# Eliminación Directa (Knockout) — Design Spec

## Goal

Implement the `knockout` tournament format: pure single-elimination bracket with random seeding, optional bye slots for non-power-of-2 team counts, optional 3rd-place match, and automatic next-round generation when all matches in a phase are finished.

## Context

The `knockout` value already exists in the `ds_tournaments.format` ENUM (added in DB v1.x). This feature wires up the full stack: DB migration, backend generator, REST endpoint integration, admin panel UI, and public portal JS rendering. No new tables are needed.

---

## Architecture

Two new methods on `FixtureGenerator` handle all bracket logic. The existing match-result-save endpoint auto-triggers the next-round generator inline (Option B — no hooks). The public portal reuses existing `matchCard()` and phase-title infrastructure.

---

## Global Constraints

- PHP 8.2, WordPress 7.0.2, MariaDB 10.6+
- All DB changes via `dbDelta()` / idempotent `ALTER TABLE` — never raw MySQL scripts
- Text domain: `soccertrack`
- CSS variables: `--st-green-primary`, `--st-navy`, `--st-font-body`
- Max supported team count: 16 (S ≤ 16)
- `knockout` format uses `has_third_place` column on `ds_tournaments` (already exists)
- Byes represented as matches with `away_team_id = NULL`, `status = 'finished'`, `home_score = 1`, `away_score = 0`
- No new REST routes — reuses `POST /admin/tournament/{id}/fixture` and the existing match-result endpoint

---

## Data Model

### DB migration — `ds_matches.phase` ENUM extension

Add `'octavos'` to the phase ENUM (idempotent, same pattern as the `quarterfinal` migration in v2.0.0):

```sql
-- Only runs if 'octavos' not already present
ALTER TABLE wp_ds_matches
  MODIFY COLUMN phase
    ENUM('regular','octavos','quarterfinal','semifinal','third_place','final')
    NOT NULL DEFAULT 'regular';
```

Migration version: `2.1.0`. Bump `SOCCERTRACK_DB_VERSION` constant accordingly.

### Phase map

| Team count (N) | Bracket size (S) | First phase |
|---|---|---|
| 2 | 2 | `final` |
| 3–4 | 4 | `semifinal` |
| 5–8 | 8 | `quarterfinal` |
| 9–16 | 16 | `octavos` |
| > 16 | — | error: "No soportado (máx. 16 equipos)" |

### Bye slots

- Byes = S − N (top-seeded positions in the shuffle)
- A bye slot produces a match row: `away_team_id = NULL`, `status = 'finished'`, `home_score = 1`, `away_score = 0`
- Bye matches are ignored by the auto-advance winner-collection logic (winner = `home_team_id`)

### Phase progression

```
octavos → quarterfinal → semifinal → final
                                    └→ third_place (if has_third_place = 1)
```

---

## Backend

### `FixtureGenerator::generate_knockout_initial(array $tournament, int $venue_id): array`

**Signature:**
```php
public function generate_knockout_initial(array $tournament, int $venue_id): array
// Returns: ['match_ids' => int[]] | ['match_ids' => [], 'error' => string]
```

**Steps:**

1. Fetch teams: `SELECT id FROM ds_teams WHERE tournament_id = %d ORDER BY id`
2. Validate N: if N < 2 → error `'Se necesitan al menos 2 equipos.'`; if N > 16 → error `'No soportado (máx. 16 equipos).'`
3. Validate no prior knockout matches: `SELECT COUNT(*) FROM ds_matches WHERE tournament_id = %d AND phase != 'regular'` → if > 0 → error `'El cuadro ya fue generado.'`
4. Fisher-Yates shuffle of team IDs
5. Compute S = next power of 2 ≥ N; byes = S − N
6. Determine `$first_phase` from phase map
7. For slots 0..(S/2 - 1), create match pairs:
   - Slot i pairs: `teams[i]` (home) vs `teams[S-1-i]` (away)
   - If away slot index ≥ N (beyond real teams) → bye match (away_team_id = NULL, status = 'finished', home_score = 1, away_score = 0, match_datetime = now)
   - Otherwise → scheduled match with computed datetime via `next_match_datetime()`
8. Insert all matches; call `assign_courts($ids, $venue_id)` for scheduled matches only
9. Return `['match_ids' => $ids]`

### `FixtureGenerator::generate_knockout_next_round(int $tournament_id, int $venue_id): array`

**Signature:**
```php
public function generate_knockout_next_round(int $tournament_id, int $venue_id): array
// Returns: ['match_ids' => int[]] | ['match_ids' => [], 'error' => string]
```

**Steps:**

1. Detect active phase: the most recent phase (by MAX id) that has ≥ 1 match and all matches are `finished` or `suspended`
   ```sql
   SELECT phase FROM ds_matches
   WHERE tournament_id = %d AND phase != 'regular'
   GROUP BY phase
   HAVING COUNT(*) > 0 AND SUM(status NOT IN ('finished','suspended')) = 0
   ORDER BY MIN(id) DESC
   LIMIT 1
   ```
2. If no active phase found → return `['match_ids' => []]` (nothing to do)
3. Determine next phase:
   - `octavos` → `quarterfinal`
   - `quarterfinal` → `semifinal`
   - `semifinal` → `final` (+ `third_place` if `has_third_place = 1`)
   - `final` → return `['match_ids' => []]` (tournament complete)
4. Guard: if next phase already has matches → return `['match_ids' => [], 'error' => 'La siguiente fase ya fue generada.']`
5. Collect winners from active phase:
   - `home_score > away_score` → `home_team_id`
   - `away_score > home_score` → `away_team_id`
   - `away_team_id IS NULL` → `home_team_id` (bye)
   - Draw → error `'Hay partidos empatados — el formato knockout no admite empates.'`
6. Pair winners: `winners[0]` vs `winners[1]`, `winners[2]` vs `winners[3]`, etc.
7. If next phase is `final`: also generate `third_place` match (losers of semifinal) if `has_third_place`
8. Insert matches; return `['match_ids' => $ids]`

### `AdminEndpoints::post_generate_fixture()` — knockout branch

Add branch after existing `group_stage` branch:

```php
} elseif ( 'knockout' === ( $tournament['format'] ?? '' ) ) {
    $result = $generator->generate_knockout_initial( $tournament, $venue_id );
    if ( ! empty( $result['error'] ) ) {
        return new \WP_Error( 'knockout_error', $result['error'], [ 'status' => 422 ] );
    }
    $this->invalidate_fixture_cache( $tournament_id );
    return new \WP_REST_Response( [ 'match_ids' => $result['match_ids'] ], 201 );
}
```

### Auto-advance in match-result save callback

Identify the callback that saves match results (sets `status = 'finished'`). After the DB update and before the response, add:

```php
if ( 'knockout' === ( $tournament['format'] ?? '' ) ) {
    $next = ( new FixtureGenerator() )->generate_knockout_next_round(
        $tournament_id,
        (int) ( $match['venue_id'] ?? $venue_id )
    );
    if ( ! empty( $next['match_ids'] ) ) {
        $this->invalidate_fixture_cache( $tournament_id );
    }
}
```

---

## Admin Panel UI

### `torneos.php` — tournament creation form

1. Add `<option value="knockout">🏆 Eliminación Directa</option>` to the format `<select>`
2. Update the JS show/hide IIFE so `has_third_place` checkbox shows for both `group_stage` AND `knockout`:
   ```js
   const showThirdPlace = fmt.value === 'group_stage' || fmt.value === 'knockout';
   document.getElementById('st-third-place-options').style.display = showThirdPlace ? '' : 'none';
   ```
3. The group-stage-specific options (`group_count`, `teams_advancing_per_group`) remain hidden for `knockout`

### `torneo-detalle.php` — tournament detail

Add a new card gated on `$is_knockout`:

```php
<?php if ( $is_knockout ) : ?>
<div class="st-card" id="st-knockout-card">
    <h2><?php esc_html_e( 'Eliminación Directa', 'soccertrack' ); ?></h2>
    <?php if ( ! $knockout_status['has_fixture'] ) : ?>
        <!-- Venue/date selector + "Generar Cuadro" button (same pattern as group_stage card) -->
        <p><?php esc_html_e( 'El cuadro aún no ha sido generado.', 'soccertrack' ); ?></p>
        <!-- form inputs: venue_id, match_date -->
        <button id="st-btn-knockout-generate" class="st-btn st-btn--primary">
            <?php esc_html_e( 'Generar Cuadro', 'soccertrack' ); ?>
        </button>
    <?php else : ?>
        <p><strong><?php esc_html_e( 'Fase activa:', 'soccertrack' ); ?></strong>
           <?php echo esc_html( $knockout_status['active_phase_label'] ); ?></p>
        <p><?php printf(
            esc_html__( '%d partido(s) pendiente(s) en esta fase.', 'soccertrack' ),
            $knockout_status['pending_count']
        ); ?></p>
        <?php if ( $knockout_status['is_complete'] ) : ?>
            <p class="st-notice st-notice--success">
                <?php esc_html_e( '🏆 Torneo finalizado.', 'soccertrack' ); ?>
            </p>
        <?php endif; ?>
    <?php endif; ?>
</div>
<?php endif; ?>
```

Inline JS fetch for "Generar Cuadro" button follows the same pattern as `st-btn-knockout` in the group_stage card (POST to `/fixture` endpoint).

### `TournamentPage.php` — `view_torneo()`

Add after the `$group_stage_status` block:

```php
$is_knockout = ( $tournament['format'] ?? '' ) === 'knockout';

$knockout_matches  = array_filter( $matches, static fn( $m ) => ( $m['phase'] ?? 'regular' ) !== 'regular' );
$active_phase      = '';
$pending_count     = 0;
$is_complete       = false;

if ( $is_knockout && ! empty( $knockout_matches ) ) {
    $phase_order   = [ 'octavos', 'quarterfinal', 'semifinal', 'third_place', 'final' ];
    $phases_present = array_unique( array_column( array_values( $knockout_matches ), 'phase' ) );
    usort( $phases_present, static fn( $a, $b ) => array_search( $a, $phase_order ) <=> array_search( $b, $phase_order ) );
    $active_phase  = end( $phases_present );
    $pending_count = count( array_filter(
        $knockout_matches,
        static fn( $m ) => $m['phase'] === $active_phase && ! in_array( $m['status'], [ 'finished', 'suspended' ], true )
    ) );
    $is_complete   = $active_phase === 'final' && $pending_count === 0;
}

$phase_labels = [
    'octavos'      => __( 'Octavos de Final', 'soccertrack' ),
    'quarterfinal' => __( 'Cuartos de Final', 'soccertrack' ),
    'semifinal'    => __( 'Semifinal', 'soccertrack' ),
    'third_place'  => __( '3.er Puesto', 'soccertrack' ),
    'final'        => __( 'Final', 'soccertrack' ),
];

$knockout_status = [
    'is_knockout'        => $is_knockout,
    'has_fixture'        => $is_knockout && ! empty( $knockout_matches ),
    'active_phase_label' => $phase_labels[ $active_phase ] ?? '',
    'pending_count'      => $pending_count,
    'is_complete'        => $is_complete,
];
```

Add `'knockout_status'` to `self::render()` compact() call.

---

## Public Portal (JS)

### `tournament-page.php` — i18n strings

Add to the `stPublic.i18n` object:

```php
'phase_octavos'  => __( 'Octavos de Final', 'soccertrack' ),
```

(Other phase keys already present from group_stage feature.)

### `live-standings.js` — shared `PHASE_TITLE` constant

Extract `phaseTitle` from inside `renderPlayoffs()` to a module-level constant (before the render functions), and add `octavos`:

```js
const PHASE_TITLE = {
    octavos:      i18n.phase_octavos      ?? 'Octavos de Final',
    quarterfinal: i18n.phase_quarterfinal ?? 'Cuartos de Final',
    semifinal:    i18n.phase_semifinal    ?? 'Semi-finales',
    third_place:  i18n.phase_third_place  ?? '3.er Puesto',
    final:        i18n.phase_final        ?? 'Final',
};
```

Update `renderPlayoffs()` to use `PHASE_TITLE` instead of its local `phaseTitle` object.

### `live-standings.js` — `renderStandings()`

At the top of the function, after `FORMAT` is read:

```js
if ( FORMAT === 'knockout' ) {
    return renderKnockoutBracket( container );
}
```

Add new function `renderKnockoutBracket(container)`:
- Fetches `/fixture` endpoint
- Filters out `phase === 'regular'` matches (byes with NULL away are excluded from display if `away_team_id` is null and status is finished)
- Groups by phase using the shared `PHASE_TITLE` constant
- Renders phase sections with `matchCard()` — no round navigation
- Shows "🏆 Torneo finalizado" banner if only `final` remains and it is `finished`

### `live-standings.js` — `renderFixture()`

At the top of the format-specific branching:

```js
if ( FORMAT === 'knockout' ) {
    // All matches are elimination — render like playoffs, no round nav
    const phases = [ 'octavos', 'quarterfinal', 'semifinal', 'third_place', 'final' ];
    const knockoutMatches = matches.filter(
        m => phases.includes( m.phase ) && m.away_team_id !== null
    );
    if ( ! knockoutMatches.length ) {
        return showEmpty( container, i18n.no_fixture ?? 'El fixture aún no ha sido generado.' );
    }
    let html = '';
    for ( const phase of phases ) {
        const ms = knockoutMatches.filter( m => m.phase === phase );
        if ( ! ms.length ) continue;
        html += `<h2 class="st-section-title">${ escHtml( PHASE_TITLE[ phase ] ) }</h2>`;
        html += ms.map( matchCard ).join( '' );
    }
    container.innerHTML = html;
    return;
}
```

No Playoffs tab injection for `knockout` (all matches already shown in Fixture and Standings tabs).

---

## Files Summary

| File | Change |
|---|---|
| `soccertrack/soccertrack.php` | Bump `SOCCERTRACK_DB_VERSION` to `'2.1.0'` |
| `soccertrack/includes/Core/DatabaseInstaller.php` | Idempotent migration: add `'octavos'` to `ds_matches.phase` ENUM |
| `soccertrack/includes/Core/FixtureGenerator.php` | Add `generate_knockout_initial()` and `generate_knockout_next_round()` |
| `soccertrack/includes/RestApi/AdminEndpoints.php` | `knockout` branch in `post_generate_fixture()`; auto-advance in match-result save callback |
| `soccertrack/includes/Public/TournamentPage.php` | `$knockout_status` computation in `view_torneo()`; add to compact() |
| `soccertrack/templates/panel/torneos.php` | Add `knockout` option to format select; update show/hide JS for `has_third_place` |
| `soccertrack/templates/panel/torneo-detalle.php` | New "Eliminación Directa" card gated on `$is_knockout` |
| `soccertrack/templates/public/tournament-page.php` | Add `phase_octavos` i18n string |
| `soccertrack/assets/js/live-standings.js` | `knockout` branches in `renderStandings()` and `renderFixture()`; new `renderKnockoutBracket()` |
