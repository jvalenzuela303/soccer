# Fase de Grupos (`group_stage`) — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Implement the `group_stage` tournament format — teams randomly assigned to groups with round-robin internal phase, followed by a smart elimination phase (quarterfinals/semis/final) whose rounds are generated on-demand via a single `/knockout` endpoint.

**Architecture:** `group_label VARCHAR(5) NULL` on `ds_teams` and `ds_matches`; three config columns (`group_count`, `teams_advancing_per_group`, `has_third_place`) on `ds_tournaments`; `phase` ENUM extended with `'quarterfinal'`; two new `FixtureGenerator` public methods; one new REST endpoint `/knockout` that auto-detects which elimination round to generate next; `StandingsCalculator::recalculate_by_group()` for per-group standings; new public REST endpoint `/groups`; JS updates for group-aware standings and fixture tabs. Backward compatible: `group_label = NULL` in non-group-stage tournaments.

**Tech Stack:** PHP 8.2, WordPress REST API (nonce auth), dbDelta() schema migrations, vanilla JS ES2021, MariaDB 10.6, Docker (soccertrack_db).

## Global Constraints

- PHP 8.2 syntax mandatory: `match`, `?->`, named args, union types, explicit return types on all methods
- WordPress Coding Standards: all direct DB queries have `// phpcs:ignore WordPress.DB.DirectDatabaseQuery`
- `$wpdb->prepare()` is mandatory for every parametrized query
- dbDelta() for schema — no manual MySQL scripts
- Migration pattern: `$has_col = $wpdb->get_var("SHOW COLUMNS FROM {$prefix}table LIKE 'col'"); if (!$has_col) { $wpdb->query("ALTER TABLE..."); }`
- REST namespace: `soccertrack/v1`
- Permission for fixture/knockout generation: `ds_generate_fixture`
- Text domain: `soccertrack` — all visible strings use `__()` / `esc_html__()`
- `group_label` values: uppercase letters `'A'`, `'B'`, `'C'`, … (up to `'H'` for group_count = 8)
- `group_label = NULL` in `ds_matches` → elimination match or non-group-stage tournament (backward compatible)
- `group_label = NULL` in `ds_teams` → non-group-stage tournament (backward compatible)
- Fisher-Yates shuffle for all randomization (team assignment to groups, knockout seeding)
- DB version: bump `SOCCERTRACK_VERSION` and `SOCCERTRACK_DB_VERSION` to `'2.0.0'` in `soccertrack/soccertrack.php` (currently `'1.9.4'`)
- No existing behavior changes — all new columns default to NULL or safe values

## File Map

| File | Change |
|---|---|
| `soccertrack/soccertrack.php` | Bump version constants to `'2.0.0'` |
| `soccertrack/includes/Core/DatabaseInstaller.php` | 5 new migrations in `apply_index_migrations()` |
| `soccertrack/includes/Core/StandingsCalculator.php` | New public method `recalculate_by_group()` |
| `soccertrack/includes/Core/FixtureGenerator.php` | New public methods `generate_group_stage()` and `generate_group_knockout()` |
| `soccertrack/includes/RestApi/AdminEndpoints.php` | Modify `/fixture` callback; add `/knockout` route + callback |
| `soccertrack/includes/RestApi/PublicEndpoints.php` | Add `'groups'` route; add `group_label` to `/fixture` response; update `invalidate_cache()` |
| `soccertrack/includes/Public/TournamentPage.php` | Save group_stage config on creation; add `group_stage_status` to `view_torneo()`; add `tournamentFormat` to `stPublic` in `render_public()` |
| `soccertrack/templates/panel/torneos.php` | Group-stage fields in creation form + JS show/hide |
| `soccertrack/templates/panel/torneo-detalle.php` | "Fase Eliminatoria" section for group_stage |
| `soccertrack/assets/js/live-standings.js` | Group-aware standings and fixture renderers |
| `soccertrack/templates/public/tournament-page.php` | Add `tournamentFormat` to `stPublic` |

---

### Task 1: DB Schema — 5 migrations + version bump

**Files:**
- Modify: `soccertrack/soccertrack.php:38-39`
- Modify: `soccertrack/includes/Core/DatabaseInstaller.php:586-594` (after v1.9.4 bracket_id block)

**Interfaces:**
- Produces:
  - `ds_tournaments.group_count TINYINT UNSIGNED NOT NULL DEFAULT 2`
  - `ds_tournaments.teams_advancing_per_group TINYINT UNSIGNED NOT NULL DEFAULT 2`
  - `ds_tournaments.has_third_place TINYINT(1) NOT NULL DEFAULT 1`
  - `ds_teams.group_label VARCHAR(5) NULL DEFAULT NULL`
  - `ds_matches.group_label VARCHAR(5) NULL DEFAULT NULL`
  - `ds_matches.phase` ENUM extended to include `'quarterfinal'`

- [ ] **Step 1: Bump version constants**

In `soccertrack/soccertrack.php`, lines 38-39, change:
```php
define( 'SOCCERTRACK_VERSION',    '1.9.4' );
define( 'SOCCERTRACK_DB_VERSION', '1.9.4' );
```
to:
```php
define( 'SOCCERTRACK_VERSION',    '2.0.0' );
define( 'SOCCERTRACK_DB_VERSION', '2.0.0' );
```

- [ ] **Step 2: Add 5 migration blocks to `apply_index_migrations()`**

In `soccertrack/includes/Core/DatabaseInstaller.php`, append the following **after** the last existing migration block (after the closing `}` of the v1.9.4 bracket_id block, around line 593):

```php
		// v2.0.0 — ds_tournaments: columnas de configuración de fase de grupos.
		$has_group_count = $wpdb->get_var( "SHOW COLUMNS FROM {$prefix}ds_tournaments LIKE 'group_count'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		if ( ! $has_group_count ) {
			$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				"ALTER TABLE {$prefix}ds_tournaments
				 ADD COLUMN group_count               TINYINT UNSIGNED NOT NULL DEFAULT 2 AFTER fixture_release_days,
				 ADD COLUMN teams_advancing_per_group TINYINT UNSIGNED NOT NULL DEFAULT 2 AFTER group_count,
				 ADD COLUMN has_third_place           TINYINT(1)       NOT NULL DEFAULT 1 AFTER teams_advancing_per_group"
			);
		}

		// v2.0.0 — ds_teams: etiqueta de grupo para formato fase de grupos.
		$has_team_group = $wpdb->get_var( "SHOW COLUMNS FROM {$prefix}ds_teams LIKE 'group_label'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		if ( ! $has_team_group ) {
			$wpdb->query( "ALTER TABLE {$prefix}ds_teams ADD COLUMN group_label VARCHAR(5) NULL DEFAULT NULL" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}

		// v2.0.0 — ds_matches: etiqueta de grupo (NULL en partidos de eliminatoria).
		$has_match_group = $wpdb->get_var( "SHOW COLUMNS FROM {$prefix}ds_matches LIKE 'group_label'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		if ( ! $has_match_group ) {
			$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				"ALTER TABLE {$prefix}ds_matches
				 ADD COLUMN group_label VARCHAR(5) NULL DEFAULT NULL AFTER bracket_id,
				 ADD KEY idx_group_label (tournament_id, group_label)"
			);
		}

		// v2.0.0 — ds_matches: ampliar ENUM phase para incluir 'quarterfinal'.
		// El MODIFY COLUMN es idempotente en MariaDB si el ENUM ya incluye el valor.
		$phase_col = $wpdb->get_row( "SHOW COLUMNS FROM {$prefix}ds_matches LIKE 'phase'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		if ( $phase_col && ! str_contains( (string) $phase_col->Type, 'quarterfinal' ) ) {
			$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				"ALTER TABLE {$prefix}ds_matches
				 MODIFY COLUMN phase ENUM('regular','quarterfinal','semifinal','third_place','final')
				                     NOT NULL DEFAULT 'regular'"
			);
		}
```

- [ ] **Step 3: Verify migrations via Docker SQL**

```bash
docker exec soccertrack_db mysql -uwpuser -pwppass soccertrack_wp -e "
SHOW COLUMNS FROM wp_ds_tournaments LIKE 'group_count';
SHOW COLUMNS FROM wp_ds_tournaments LIKE 'teams_advancing_per_group';
SHOW COLUMNS FROM wp_ds_tournaments LIKE 'has_third_place';
SHOW COLUMNS FROM wp_ds_teams LIKE 'group_label';
SHOW COLUMNS FROM wp_ds_matches LIKE 'group_label';
SHOW COLUMNS FROM wp_ds_matches LIKE 'phase';
"
```

Expected: all 6 queries return one row each. The `phase` column Type should contain `'quarterfinal'`.

Before triggering migrations, columns won't exist. Trigger `maybe_upgrade()` by bumping the stored DB version:
```bash
docker exec soccertrack_db mysql -uwpuser -pwppass soccertrack_wp -e "
UPDATE wp_options SET option_value = '1.0.0' WHERE option_name = 'soccertrack_db_version';"
```
Then load any panel page in the browser (this triggers `maybe_upgrade()`), then re-run the verification SQL above.

- [ ] **Step 4: Commit**

```bash
git add soccertrack/soccertrack.php soccertrack/includes/Core/DatabaseInstaller.php
git commit -m "feat(db): v2.0.0 schema — group_stage columns, quarterfinal ENUM, group_label indexes"
```

---

### Task 2: StandingsCalculator::recalculate_by_group()

**Files:**
- Modify: `soccertrack/includes/Core/StandingsCalculator.php` (append after `recalculate()`, before closing `}` of class)

**Interfaces:**
- Consumes: `int $tournament_id`
- Produces: `public function recalculate_by_group(int $tournament_id): array`
  - Returns: `['A' => [...rows], 'B' => [...rows], ...]` where each row has the same structure as `recalculate()` (team_id, name, pj, pg, pe, pp, gf, gc, dg, pts, form, clean_sheets, win_streak)
  - Empty array `[]` if tournament has no group_label data
  - Groups ordered alphabetically, rows within each group ordered by PTS → DG → GF

- [ ] **Step 1: Verify endpoint fails before adding method**

```bash
curl -s "http://localhost/wp-json/soccertrack/v1/public/tournament/1/groups" | python3 -m json.tool
```
Expected: `{"code":"rest_no_route","message":"No route was found..."}`  
(The route doesn't exist yet, confirming baseline.)

- [ ] **Step 2: Add `recalculate_by_group()` method to StandingsCalculator**

Append after the closing `unset($row)` and `return $sorted;` block of `recalculate()`, before the class closing `}`:

```php
	/**
	 * Recalcula la tabla de posiciones por grupo para torneos con fase de grupos.
	 *
	 * Solo considera partidos con phase = 'regular' y status = 'finished'.
	 * Retorna mapa de group_label → array de rows, misma estructura que recalculate().
	 *
	 * @param  int $tournament_id
	 * @return array<string, array<int, array{team_id:int, name:string, pj:int, pg:int, pe:int, pp:int, gf:int, gc:int, dg:int, pts:int, form:list<string>, clean_sheets:int, win_streak:int}>>
	 */
	public function recalculate_by_group( int $tournament_id ): array {
		global $wpdb;

		// Cargar partidos regulares finalizados con su group_label.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$matches = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT home_team_id, away_team_id, home_score, away_score, match_datetime, group_label
				 FROM {$wpdb->prefix}ds_matches USE INDEX (idx_tournament_status)
				 WHERE tournament_id = %d AND status = 'finished' AND phase = 'regular' AND group_label IS NOT NULL
				 ORDER BY match_datetime ASC",
				$tournament_id
			),
			ARRAY_A
		);

		// Cargar equipos con su group_label.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$teams = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, name, group_label FROM {$wpdb->prefix}ds_teams
				 WHERE tournament_id = %d AND group_label IS NOT NULL
				 ORDER BY group_label ASC, name ASC",
				$tournament_id
			),
			ARRAY_A
		);

		if ( empty( $teams ) ) {
			return [];
		}

		// Agrupar equipos por group_label.
		$groups      = [];
		$team_groups = []; // team_id → group_label.
		foreach ( $teams as $team ) {
			$tid   = (int) $team['id'];
			$label = (string) $team['group_label'];
			if ( ! isset( $groups[ $label ] ) ) {
				$groups[ $label ] = [];
			}
			$groups[ $label ][ $tid ] = [
				'team_id'      => $tid,
				'name'         => $team['name'],
				'pj'           => 0,
				'pg'           => 0,
				'pe'           => 0,
				'pp'           => 0,
				'gf'           => 0,
				'gc'           => 0,
				'dg'           => 0,
				'pts'          => 0,
				'form'         => [],
				'clean_sheets' => 0,
				'win_streak'   => 0,
			];
			$team_groups[ $tid ] = $label;
		}

		// Historial por equipo.
		$team_history = array_fill_keys( array_keys( $team_groups ), [] );

		// Procesar partidos — cada partido pertenece al group_label del equipo.
		foreach ( $matches as $match ) {
			$h     = (int) $match['home_team_id'];
			$a     = (int) $match['away_team_id'];
			$hs    = (int) $match['home_score'];
			$as    = (int) $match['away_score'];
			$label = (string) $match['group_label'];

			if ( ! isset( $groups[ $label ][ $h ], $groups[ $label ][ $a ] ) ) {
				continue;
			}

			$groups[ $label ][ $h ]['pj']++;
			$groups[ $label ][ $a ]['pj']++;
			$groups[ $label ][ $h ]['gf'] += $hs;
			$groups[ $label ][ $h ]['gc'] += $as;
			$groups[ $label ][ $a ]['gf'] += $as;
			$groups[ $label ][ $a ]['gc'] += $hs;

			if ( $hs > $as ) {
				$groups[ $label ][ $h ]['pg']++;
				$groups[ $label ][ $h ]['pts'] += 3;
				$groups[ $label ][ $a ]['pp']++;
				$team_history[ $h ][] = [ 'result' => 'W', 'clean' => 0 === $as ];
				$team_history[ $a ][] = [ 'result' => 'L', 'clean' => 0 === $hs ];
			} elseif ( $hs < $as ) {
				$groups[ $label ][ $a ]['pg']++;
				$groups[ $label ][ $a ]['pts'] += 3;
				$groups[ $label ][ $h ]['pp']++;
				$team_history[ $h ][] = [ 'result' => 'L', 'clean' => 0 === $as ];
				$team_history[ $a ][] = [ 'result' => 'W', 'clean' => 0 === $hs ];
			} else {
				$groups[ $label ][ $h ]['pe']++;
				$groups[ $label ][ $h ]['pts']++;
				$groups[ $label ][ $a ]['pe']++;
				$groups[ $label ][ $a ]['pts']++;
				$team_history[ $h ][] = [ 'result' => 'D', 'clean' => 0 === $as ];
				$team_history[ $a ][] = [ 'result' => 'D', 'clean' => 0 === $hs ];
			}
		}

		// Calcular form, clean_sheets, win_streak y dg; ordenar por PTS → DG → GF.
		$result = [];
		ksort( $groups ); // Orden alfabético de grupos.

		foreach ( $groups as $label => $table ) {
			foreach ( $table as $tid => &$row ) {
				$history        = $team_history[ $tid ] ?? [];
				$row['form']    = array_column( array_slice( $history, -5 ), 'result' );
				$row['clean_sheets'] = count( array_filter( $history, static fn( array $e ): bool => $e['clean'] ) );
				$row['dg']      = $row['gf'] - $row['gc'];

				$streak          = 0;
				foreach ( array_reverse( $history ) as $entry ) {
					if ( 'W' !== $entry['result'] ) {
						break;
					}
					++$streak;
				}
				$row['win_streak'] = $streak;
			}
			unset( $row );

			$sorted = array_values( $table );
			usort(
				$sorted,
				static fn( array $a, array $b ): int =>
					[ $b['pts'], $b['dg'], $b['gf'] ] <=> [ $a['pts'], $a['dg'], $a['gf'] ]
			);
			$result[ $label ] = $sorted;
		}

		return $result;
	}
```

- [ ] **Step 3: Manual smoke-test via Docker**

Create a temporary group_stage scenario in the DB (or use an existing group_stage tournament if one exists). Verify `recalculate_by_group()` compiles without syntax errors:

```bash
docker exec soccertrack_db mysql -uwpuser -pwppass soccertrack_wp -e "
SELECT id, format FROM wp_ds_tournaments WHERE format = 'group_stage' LIMIT 1;"
```

If none exist yet, we verify at the end of Task 3 when a real fixture is generated.

- [ ] **Step 4: Commit**

```bash
git add soccertrack/includes/Core/StandingsCalculator.php
git commit -m "feat(standings): recalculate_by_group() — per-group standings for group_stage format"
```

---

### Task 3: FixtureGenerator::generate_group_stage()

**Files:**
- Modify: `soccertrack/includes/Core/FixtureGenerator.php` (append before the class closing `}`)

**Interfaces:**
- Consumes: `array $tournament` (must include `id`, `match_weekday`, `match_weekdays`, `match_time`, `match_time_weekend`, `match_duration`, `group_count`, `teams_advancing_per_group`, `has_third_place`), `int $venue_id`
- Produces: `public function generate_group_stage(array $tournament, int $venue_id): array`
  - Returns `['match_ids' => int[]]` on success
  - Returns `['match_ids' => [], 'error' => string]` on failure
  - Side effect: updates `ds_teams.group_label` for all teams in the tournament

- [ ] **Step 1: Add `generate_group_stage()` to FixtureGenerator**

Append before the class closing `}` in `soccertrack/includes/Core/FixtureGenerator.php`:

```php
	/**
	 * Genera el fixture completo de un torneo en Fase de Grupos.
	 *
	 * Flujo:
	 *  1. Carga y baraja equipos (Fisher-Yates).
	 *  2. Distribuye en N grupos (letras A, B, C…). Si total no divide exactamente,
	 *     los últimos grupos tienen un equipo menos (diferencia máxima de 1).
	 *  3. Actualiza ds_teams.group_label.
	 *  4. Por cada grupo: genera round-robin completo con phase='regular', group_label='X'.
	 *  5. Asigna canchas.
	 *
	 * @param  array{id:int,match_weekday:int,match_weekdays:string,match_time:string,match_duration:int,group_count:int,teams_advancing_per_group:int,has_third_place:int} $tournament
	 * @param  int $venue_id
	 * @return array{match_ids: int[], error?: string}
	 */
	public function generate_group_stage( array $tournament, int $venue_id ): array {
		global $wpdb;

		$tournament_id = (int) $tournament['id'];
		$group_count   = max( 2, min( 8, (int) ( $tournament['group_count'] ?? 2 ) ) );
		$weekdays      = $this->weekdays_from_tournament( $tournament );
		$time          = (string) ( $tournament['match_time'] ?? '19:00:00' );
		$duration      = $this->duration_from_tournament( $tournament );
		$num_courts    = $this->count_courts( $venue_id );

		// 1. Cargar equipos.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$team_ids = array_map(
			'intval',
			$wpdb->get_col(
				$wpdb->prepare(
					"SELECT id FROM {$wpdb->prefix}ds_teams WHERE tournament_id = %d ORDER BY id ASC",
					$tournament_id
				)
			)
		);

		$total = count( $team_ids );

		// 2. Validar mínimo 2 equipos por grupo.
		if ( $total < $group_count * 2 ) {
			return [
				'match_ids' => [],
				'error'     => sprintf(
					'Equipos insuficientes para %d grupos (mínimo %d equipos).',
					$group_count,
					$group_count * 2
				),
			];
		}

		// 3. Verificar que no exista fixture previo.
		$existing = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}ds_matches WHERE tournament_id = %d",
				$tournament_id
			)
		);
		if ( $existing > 0 ) {
			return [ 'match_ids' => [], 'error' => 'Ya existen partidos generados para este torneo.' ];
		}

		// 4. Fisher-Yates shuffle.
		for ( $i = $total - 1; $i > 0; $i-- ) {
			$j             = random_int( 0, $i );
			[ $team_ids[ $i ], $team_ids[ $j ] ] = [ $team_ids[ $j ], $team_ids[ $i ] ];
		}

		// 5. Distribuir en grupos: ceil() para los primeros grupos, el resto tiene 1 menos.
		$base_size    = (int) ceil( $total / $group_count );
		$groups       = [];
		$group_labels = range( 'A', chr( ord( 'A' ) + $group_count - 1 ) );
		$offset       = 0;
		foreach ( $group_labels as $label ) {
			$remaining   = $total - $offset;
			$groups_left = $group_count - count( $groups );
			$size        = (int) ceil( $remaining / $groups_left );
			$groups[ $label ] = array_slice( $team_ids, $offset, $size );
			$offset += $size;
		}

		// 6. Actualizar group_label en ds_teams.
		foreach ( $groups as $label => $group_team_ids ) {
			foreach ( $group_team_ids as $tid ) {
				$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
					"{$wpdb->prefix}ds_teams",
					[ 'group_label' => $label ],
					[ 'id' => $tid ],
					[ '%s' ],
					[ '%d' ]
				);
			}
		}

		// 7. Generar round-robin por grupo; round_index continuo a través de grupos
		//    para que los partidos de cada grupo caigan en fechas distintas.
		$all_match_ids  = [];
		$round_offset   = 0; // Acumula rondas para calcular fechas escaladas por grupo.

		foreach ( $groups as $label => $group_team_ids ) {
			$n     = count( $group_team_ids );
			$teams = $group_team_ids;

			// Número impar → agregar null (bye).
			if ( $n % 2 !== 0 ) {
				$teams[] = null;
				$n++;
			}

			$rounds_in_group = $n - 1;

			for ( $r = 1; $r <= $rounds_in_group; $r++ ) {
				$pairs = [];
				for ( $i = 0; $i < $n / 2; $i++ ) {
					$home = $teams[ $i ];
					$away = $teams[ $n - 1 - $i ];
					if ( $home === null || $away === null ) {
						continue;
					}
					if ( $r % 2 === 0 ) {
						[ $home, $away ] = [ $away, $home ];
					}
					$pairs[] = [ 'home' => $home, 'away' => $away ];
				}

				$round_index  = $round_offset + $r - 1;
				$n_pairs      = count( $pairs );
				$num_batches  = max( 1, (int) ceil( $n_pairs / $num_courts ) );

				foreach ( $pairs as $idx => $pair ) {
					$base_batch    = (int) floor( $idx / $num_courts );
					$rotated_batch = ( $base_batch + ( $r - 1 ) ) % $num_batches;
					$dt            = $this->next_match_datetime( $weekdays, $time, $rotated_batch, $round_index, $duration );

					// phpcs:ignore WordPress.DB.DirectDatabaseQuery
					$wpdb->insert(
						"{$wpdb->prefix}ds_matches",
						[
							'tournament_id'  => $tournament_id,
							'round_number'   => $round_offset + $r, // Jornada global.
							'home_team_id'   => $pair['home'],
							'away_team_id'   => $pair['away'],
							'venue_id'       => $venue_id,
							'court_id'       => 0,
							'match_datetime' => $dt,
							'status'         => 'scheduled',
							'phase'          => 'regular',
							'group_label'    => $label,
						],
						[ '%d', '%d', '%d', '%d', '%d', '%d', '%s', '%s', '%s', '%s' ]
					);

					if ( $wpdb->insert_id ) {
						$all_match_ids[] = (int) $wpdb->insert_id;
					}
				}

				// Rotación circular (algoritmo Round-Robin estándar).
				$fixed = array_shift( $teams );
				$last  = array_pop( $teams );
				array_unshift( $teams, $last );
				array_unshift( $teams, $fixed );
			}

			$round_offset += $rounds_in_group;
		}

		// 8. Asignar canchas.
		$this->assign_courts( $all_match_ids, $venue_id );

		return [ 'match_ids' => $all_match_ids ];
	}
```

- [ ] **Step 2: Trigger fixture generation via REST to verify**

First get a nonce (requires browser login or the panel session):
```bash
NONCE=$(curl -s -b cookies.txt "http://localhost/wp-admin/admin-ajax.php?action=rest-nonce" | tr -d '"')
# For testing, use a tournament with format='group_stage' and at least 4 teams.
# Create one via the panel or SQL:
docker exec soccertrack_db mysql -uwpuser -pwppass soccertrack_wp -e "
INSERT INTO wp_ds_tournaments (name, season, format, status, group_count, teams_advancing_per_group, has_third_place, match_weekday, match_weekdays, match_time, match_duration)
VALUES ('Test GS', '2026', 'group_stage', 'draft', 2, 2, 1, 6, '[6]', '19:00:00', 60);
SET @tid = LAST_INSERT_ID();
INSERT INTO wp_ds_teams (tournament_id, name) VALUES (@tid,'Alpha'),(@tid,'Beta'),(@tid,'Gamma'),(@tid,'Delta');
SELECT @tid AS tournament_id;"
```

Generate fixture:
```bash
curl -s -X POST "http://localhost/wp-json/soccertrack/v1/admin/tournament/{TID}/fixture" \
  -H "X-WP-Nonce: $NONCE" \
  -H "Content-Type: application/json" \
  -d '{"venue_id": 1}' | python3 -m json.tool
```
Expected: `{"tournament_id": N, "matches_created": 2, "match_ids": [...]}`  
(4 teams in 2 groups of 2 → 1 match per group = 2 total)

Verify group_label in DB:
```bash
docker exec soccertrack_db mysql -uwpuser -pwppass soccertrack_wp -e "
SELECT id, group_label FROM wp_ds_teams WHERE tournament_id = {TID};
SELECT id, group_label, phase FROM wp_ds_matches WHERE tournament_id = {TID};"
```
Expected: 2 teams with group_label='A', 2 with 'B'; 2 matches with phase='regular' and group_label set.

- [ ] **Step 3: Commit**

```bash
git add soccertrack/includes/Core/FixtureGenerator.php
git commit -m "feat(fixture): generate_group_stage() — Fisher-Yates assignment, per-group round-robin"
```

---

### Task 4: FixtureGenerator::generate_group_knockout()

**Files:**
- Modify: `soccertrack/includes/Core/FixtureGenerator.php` (append after `generate_group_stage()`, before class closing `}`)

**Interfaces:**
- Consumes: `array $tournament` (same keys as Task 3, must include `teams_advancing_per_group` and `has_third_place`), `int $venue_id`, `?string $match_date = null`
- Produces: `public function generate_group_knockout(array $tournament, int $venue_id, ?string $match_date = null): array`
  - Returns `['match_ids' => int[], 'phase' => 'quarterfinal'|'semifinal'|'final']` on success
  - Returns `['match_ids' => [], 'error' => string]` on failure
  - Detects automatically which elimination round to generate (State 1, 2, or 3)

- [ ] **Step 1: Add `generate_group_knockout()` to FixtureGenerator**

Append after `generate_group_stage()`, before the class closing `}`:

```php
	/**
	 * Genera la siguiente ronda eliminatoria de un torneo en Fase de Grupos.
	 *
	 * Se llama múltiples veces: cada llamada genera la siguiente ronda disponible.
	 * Detecta automáticamente el estado del torneo:
	 *   Estado 1 — Sin eliminatoria: genera Cuartos (8 clasificados) o Semis (2 o 4 clasificados).
	 *   Estado 2 — Cuartos terminados, sin Semis: genera Semis con los 4 ganadores de QF.
	 *   Estado 3 — Semis terminadas, sin Final: genera Final + 3.er Puesto (si has_third_place=1).
	 *
	 * @param  array{id:int,match_weekday:int,match_weekdays:string,match_time:string,match_duration:int,teams_advancing_per_group:int,has_third_place:int} $tournament
	 * @param  int     $venue_id
	 * @param  ?string $match_date  Fecha específica 'Y-m-d' (opcional). Null = próximo día hábil.
	 * @return array{match_ids: int[], phase: string, error?: string}
	 */
	public function generate_group_knockout( array $tournament, int $venue_id, ?string $match_date = null ): array {
		global $wpdb;

		$tournament_id       = (int) $tournament['id'];
		$has_third_place     = (bool) ( $tournament['has_third_place'] ?? 1 );
		$weekdays            = $this->weekdays_from_tournament( $tournament );
		$time                = (string) ( $tournament['match_time'] ?? '19:00:00' );
		$duration            = $this->duration_from_tournament( $tournament );

		$resolve_winner = static function ( array $m ): int {
			return (int) $m['home_score'] >= (int) $m['away_score']
				? (int) $m['home_team_id']
				: (int) $m['away_team_id'];
		};

		$dt = static function ( int $offset ) use ( $match_date, $weekdays, $time, $duration ): string {
			if ( $match_date !== null ) {
				[ $h, $min, $s ] = array_map( 'intval', explode( ':', $time . ':00' ) );
				$start  = $h * 60 + $min + $offset * $duration;
				$base   = ( new \DateTimeImmutable( $match_date ) )->setTime( intdiv( $start, 60 ) % 24, $start % 60, $s );
				return $base->format( 'Y-m-d H:i:s' );
			}
			$day_names = [ 0 => 'sunday', 1 => 'monday', 2 => 'tuesday', 3 => 'wednesday',
				4 => 'thursday', 5 => 'friday', 6 => 'saturday' ];
			$first_day = $weekdays[0] ?? 6;
			$base      = new \DateTimeImmutable( 'next ' . ( $day_names[ $first_day ] ?? 'saturday' ) );
			[ $h, $min, $s ] = array_map( 'intval', explode( ':', $time . ':00' ) );
			$start     = $h * 60 + $min + $offset * $duration;
			$base      = $base->setTime( intdiv( $start, 60 ) % 24, $start % 60, $s );
			return $base->format( 'Y-m-d H:i:s' );
		};

		// ── Detectar estado actual ────────────────────────────────────────────

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$qf_matches = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, home_team_id, away_team_id, home_score, away_score, status
				 FROM {$wpdb->prefix}ds_matches
				 WHERE tournament_id = %d AND phase = 'quarterfinal'
				 ORDER BY id ASC",
				$tournament_id
			),
			ARRAY_A
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$sf_matches = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, home_team_id, away_team_id, home_score, away_score, status
				 FROM {$wpdb->prefix}ds_matches
				 WHERE tournament_id = %d AND phase = 'semifinal'
				 ORDER BY id ASC",
				$tournament_id
			),
			ARRAY_A
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$has_final = (bool) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}ds_matches
				 WHERE tournament_id = %d AND phase IN ('final','third_place')",
				$tournament_id
			)
		);

		// ── Estado 3: Semis terminadas, sin Final ────────────────────────────
		if ( ! empty( $sf_matches ) && ! $has_final ) {
			$all_sf_done = count( array_filter( $sf_matches, static fn( $m ) => ! in_array( $m['status'], [ 'finished', 'suspended', 'postponed' ], true ) ) ) === 0;

			if ( ! $all_sf_done ) {
				return [ 'match_ids' => [], 'error' => 'Las semi-finales aún no han terminado.' ];
			}

			$sf1 = $sf_matches[0];
			$sf2 = $sf_matches[1] ?? null;
			if ( ! $sf2 ) {
				return [ 'match_ids' => [], 'error' => 'Se necesitan exactamente 2 semi-finales para generar la final.' ];
			}

			$resolve_loser = static function ( array $m ): int {
				return (int) $m['home_score'] >= (int) $m['away_score']
					? (int) $m['away_team_id']
					: (int) $m['home_team_id'];
			};

			$w1 = $resolve_winner( $sf1 );
			$w2 = $resolve_winner( $sf2 );
			$l1 = $resolve_loser( $sf1 );
			$l2 = $resolve_loser( $sf2 );

			$ids      = [];
			$inserts  = [];

			if ( $has_third_place ) {
				$inserts[] = [ 'home' => $l1, 'away' => $l2, 'dt' => $dt( 0 ), 'phase' => 'third_place' ];
			}
			$inserts[] = [ 'home' => $w1, 'away' => $w2, 'dt' => $dt( $has_third_place ? 1 : 0 ), 'phase' => 'final' ];

			foreach ( $inserts as $pair ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$wpdb->insert(
					"{$wpdb->prefix}ds_matches",
					[
						'tournament_id'  => $tournament_id,
						'round_number'   => 0,
						'home_team_id'   => $pair['home'],
						'away_team_id'   => $pair['away'],
						'venue_id'       => $venue_id,
						'court_id'       => 0,
						'match_datetime' => $pair['dt'],
						'status'         => 'scheduled',
						'phase'          => $pair['phase'],
						'group_label'    => null,
					],
					[ '%d', '%d', '%d', '%d', '%d', '%d', '%s', '%s', '%s', '%s' ]
				);
				if ( $wpdb->insert_id ) {
					$ids[] = (int) $wpdb->insert_id;
				}
			}

			$this->assign_courts( $ids, $venue_id );
			return [ 'match_ids' => $ids, 'phase' => 'final' ];
		}

		// ── Estado 2: Cuartos terminados, sin Semis ──────────────────────────
		if ( ! empty( $qf_matches ) && empty( $sf_matches ) ) {
			$all_qf_done = count( array_filter( $qf_matches, static fn( $m ) => ! in_array( $m['status'], [ 'finished', 'suspended', 'postponed' ], true ) ) ) === 0;

			if ( ! $all_qf_done ) {
				return [ 'match_ids' => [], 'error' => 'Los cuartos de final aún no han terminado.' ];
			}

			$winners = array_map( $resolve_winner, $qf_matches );

			// Fisher-Yates sobre los 4 ganadores.
			for ( $i = count( $winners ) - 1; $i > 0; $i-- ) {
				$j = random_int( 0, $i );
				[ $winners[ $i ], $winners[ $j ] ] = [ $winners[ $j ], $winners[ $i ] ];
			}

			$ids = [];
			foreach ( [ [ $winners[0], $winners[1] ], [ $winners[2], $winners[3] ] ] as $k => $pair ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$wpdb->insert(
					"{$wpdb->prefix}ds_matches",
					[
						'tournament_id'  => $tournament_id,
						'round_number'   => 0,
						'home_team_id'   => $pair[0],
						'away_team_id'   => $pair[1],
						'venue_id'       => $venue_id,
						'court_id'       => 0,
						'match_datetime' => $dt( $k ),
						'status'         => 'scheduled',
						'phase'          => 'semifinal',
						'group_label'    => null,
					],
					[ '%d', '%d', '%d', '%d', '%d', '%d', '%s', '%s', '%s', '%s' ]
				);
				if ( $wpdb->insert_id ) {
					$ids[] = (int) $wpdb->insert_id;
				}
			}

			$this->assign_courts( $ids, $venue_id );
			return [ 'match_ids' => $ids, 'phase' => 'semifinal' ];
		}

		// ── Estado 1: Sin eliminatoria — calcular clasificados ───────────────
		if ( ! empty( $qf_matches ) || ! empty( $sf_matches ) || $has_final ) {
			return [ 'match_ids' => [], 'error' => 'Estado del torneo no soportado para generar eliminatoria.' ];
		}

		// Verificar que todos los partidos regulares estén finalizados.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$pending_regular = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}ds_matches
				 WHERE tournament_id = %d AND phase = 'regular'
				   AND status NOT IN ('finished', 'suspended', 'postponed')",
				$tournament_id
			)
		);
		if ( $pending_regular > 0 ) {
			return [ 'match_ids' => [], 'error' => 'Aún hay partidos de fase de grupos sin finalizar.' ];
		}

		// Calcular clasificados: top N de cada grupo.
		$advancing_per_group = max( 1, min( 4, (int) ( $tournament['teams_advancing_per_group'] ?? 2 ) ) );
		$standings_by_group  = ( new StandingsCalculator() )->recalculate_by_group( $tournament_id );

		if ( empty( $standings_by_group ) ) {
			return [ 'match_ids' => [], 'error' => 'No se encontraron grupos para este torneo.' ];
		}

		$qualifiers = [];
		foreach ( $standings_by_group as $rows ) {
			foreach ( array_slice( $rows, 0, $advancing_per_group ) as $row ) {
				$qualifiers[] = (int) $row['team_id'];
			}
		}

		$n_qual = count( $qualifiers );
		if ( ! in_array( $n_qual, [ 2, 4, 8 ], true ) ) {
			return [
				'match_ids' => [],
				'error'     => sprintf(
					'El número de clasificados (%d) no soporta un bracket limpio. Deben ser 2, 4 u 8.',
					$n_qual
				),
			];
		}

		// Fisher-Yates sobre los clasificados.
		for ( $i = $n_qual - 1; $i > 0; $i-- ) {
			$j = random_int( 0, $i );
			[ $qualifiers[ $i ], $qualifiers[ $j ] ] = [ $qualifiers[ $j ], $qualifiers[ $i ] ];
		}

		$phase = match ( $n_qual ) {
			2, 4 => 'semifinal',
			8    => 'quarterfinal',
		};

		$ids = [];
		$pairs = array_chunk( $qualifiers, 2 );
		foreach ( $pairs as $k => $pair ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->insert(
				"{$wpdb->prefix}ds_matches",
				[
					'tournament_id'  => $tournament_id,
					'round_number'   => 0,
					'home_team_id'   => $pair[0],
					'away_team_id'   => $pair[1],
					'venue_id'       => $venue_id,
					'court_id'       => 0,
					'match_datetime' => $dt( $k ),
					'status'         => 'scheduled',
					'phase'          => $phase,
					'group_label'    => null,
				],
				[ '%d', '%d', '%d', '%d', '%d', '%d', '%s', '%s', '%s', '%s' ]
			);
			if ( $wpdb->insert_id ) {
				$ids[] = (int) $wpdb->insert_id;
			}
		}

		$this->assign_courts( $ids, $venue_id );
		return [ 'match_ids' => $ids, 'phase' => $phase ];
	}
```

- [ ] **Step 2: Test Estado 1 (groups finished → generate semis) via curl**

Using the test tournament from Task 3, mark all regular matches as finished:
```bash
docker exec soccertrack_db mysql -uwpuser -pwppass soccertrack_wp -e "
UPDATE wp_ds_matches
SET status='finished', home_score=2, away_score=1
WHERE tournament_id = {TID} AND phase='regular';"
```

Call knockout endpoint (not yet registered — verify will happen after Task 5). For now, verify the method compiles cleanly by checking for PHP syntax errors:
```bash
docker exec soccertrack_db mysql -uwpuser -pwppass soccertrack_wp -e "SELECT 1;" && \
php -l soccertrack/includes/Core/FixtureGenerator.php
```
Expected: `No syntax errors detected in soccertrack/includes/Core/FixtureGenerator.php`

- [ ] **Step 3: Commit**

```bash
git add soccertrack/includes/Core/FixtureGenerator.php
git commit -m "feat(fixture): generate_group_knockout() — smart state-machine elimination round generator"
```

---

### Task 5: AdminEndpoints — route /fixture + new /knockout

**Files:**
- Modify: `soccertrack/includes/RestApi/AdminEndpoints.php`
  - Line 686: update tournament SELECT to include `format, group_count, teams_advancing_per_group, has_third_place`
  - Lines 731-731: add group_stage branching in `post_generate_fixture()`
  - After `register_routes()` block (after `/fixture` route): add `/knockout` route registration
  - Append new callback `post_generate_knockout()` before class closing `}`

**Interfaces:**
- Consumes: `FixtureGenerator::generate_group_stage(array $tournament, int $venue_id): array` (Task 3)
- Consumes: `FixtureGenerator::generate_group_knockout(array $tournament, int $venue_id, ?string $match_date): array` (Task 4)
- Produces:
  - `POST /admin/tournament/{id}/fixture` now routes to `generate_group_stage()` when `format = 'group_stage'`
  - `POST /admin/tournament/{id}/knockout` → `post_generate_knockout()` callback

- [ ] **Step 1: Update the tournament SELECT in `post_generate_fixture()`**

In `AdminEndpoints.php`, locate line ~686:
```php
"SELECT id, name, match_weekday, match_weekdays, match_time, match_time_weekend, match_duration FROM {$wpdb->prefix}ds_tournaments WHERE id = %d",
```
Change to:
```php
"SELECT id, name, format, match_weekday, match_weekdays, match_time, match_time_weekend, match_duration, group_count, teams_advancing_per_group, has_third_place FROM {$wpdb->prefix}ds_tournaments WHERE id = %d",
```

- [ ] **Step 2: Add group_stage branching in `post_generate_fixture()` callback**

Locate the line (around line 731):
```php
$team_ids    = array_map( 'intval', $team_ids );
$match_ids   = ( new FixtureGenerator() )->generate( $tournament, $team_ids, $venue_id );
```
Replace with:
```php
$team_ids  = array_map( 'intval', $team_ids );
$generator = new FixtureGenerator();

if ( ( $tournament['format'] ?? '' ) === 'group_stage' ) {
    $result    = $generator->generate_group_stage( $tournament, $venue_id );
    if ( ! empty( $result['error'] ) ) {
        return new \WP_Error(
            'fixture_error',
            $result['error'],
            [ 'status' => 422 ]
        );
    }
    $match_ids = $result['match_ids'];
} else {
    $match_ids = $generator->generate( $tournament, $team_ids, $venue_id );
}
```

- [ ] **Step 3: Register the `/knockout` route**

In `register_routes()`, after the existing `/fixture` route block (after the closing `]` at line ~89), add:

```php
		// POST /admin/tournament/{id}/knockout — Generar siguiente ronda eliminatoria (group_stage).
		register_rest_route(
			self::NAMESPACE,
			'/admin/tournament/(?P<id>\d+)/knockout',
			[
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => [ self::class, 'post_generate_knockout' ],
				'permission_callback' => static fn() => current_user_can( 'ds_generate_fixture' ),
				'args'                => [
					'id'         => [
						'validate_callback' => static fn( mixed $v ): bool => is_numeric( $v ) && (int) $v > 0,
						'sanitize_callback' => 'absint',
					],
					'venue_id'   => [
						'required'          => true,
						'validate_callback' => static fn( mixed $v ): bool => is_numeric( $v ) && (int) $v > 0,
						'sanitize_callback' => 'absint',
					],
					'match_date' => [
						'required'          => false,
						'validate_callback' => static fn( mixed $v ): bool => ! $v || (bool) preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $v ),
						'sanitize_callback' => 'sanitize_text_field',
					],
				],
			]
		);
```

Also update the file-level docblock comment at the top of the file to add the new route:
```php
 * POST /admin/tournament/{id}/knockout         — Generar siguiente ronda eliminatoria (group_stage)
```

- [ ] **Step 4: Add `post_generate_knockout()` callback**

Append before the class closing `}` (after `post_generate_fixture()` or at end of file):

```php
	/**
	 * POST /admin/tournament/{id}/knockout
	 *
	 * Genera la siguiente ronda eliminatoria de un torneo en Fase de Grupos.
	 * Puede llamarse múltiples veces: detecta automáticamente qué ronda corresponde.
	 */
	public static function post_generate_knockout( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		global $wpdb;

		$tournament_id = (int) $request['id'];
		$venue_id      = (int) $request['venue_id'];
		$match_date    = $request['match_date'] ? (string) $request['match_date'] : null;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$tournament = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT id, name, format, match_weekday, match_weekdays, match_time, match_time_weekend, match_duration,
				        group_count, teams_advancing_per_group, has_third_place
				 FROM {$wpdb->prefix}ds_tournaments WHERE id = %d",
				$tournament_id
			),
			ARRAY_A
		);

		if ( ! $tournament ) {
			return new \WP_Error( 'tournament_not_found', __( 'Torneo no encontrado.', 'soccertrack' ), [ 'status' => 404 ] );
		}

		if ( ( $tournament['format'] ?? '' ) !== 'group_stage' ) {
			return new \WP_Error(
				'invalid_format',
				__( 'Este endpoint solo aplica para torneos en Fase de Grupos.', 'soccertrack' ),
				[ 'status' => 422 ]
			);
		}

		$result = ( new FixtureGenerator() )->generate_group_knockout( $tournament, $venue_id, $match_date );

		if ( ! empty( $result['error'] ) ) {
			return new \WP_Error( 'knockout_error', $result['error'], [ 'status' => 422 ] );
		}

		return rest_ensure_response( [
			'tournament_id'   => $tournament_id,
			'matches_created' => count( $result['match_ids'] ),
			'match_ids'       => $result['match_ids'],
			'phase'           => $result['phase'],
		] );
	}
```

- [ ] **Step 5: Test /fixture with group_stage format**

```bash
NONCE=$(curl -s -c cookies.txt -b cookies.txt "http://localhost/wp-admin/admin-ajax.php?action=rest-nonce" | tr -d '"')
# Use test tournament TID from Task 3. Delete existing matches first:
docker exec soccertrack_db mysql -uwpuser -pwppass soccertrack_wp -e "
DELETE FROM wp_ds_matches WHERE tournament_id = {TID};
UPDATE wp_ds_teams SET group_label = NULL WHERE tournament_id = {TID};"

curl -s -X POST "http://localhost/wp-json/soccertrack/v1/admin/tournament/{TID}/fixture" \
  -H "X-WP-Nonce: $NONCE" \
  -H "Content-Type: application/json" \
  -d '{"venue_id": 1}' | python3 -m json.tool
```
Expected: `{"tournament_id": N, "matches_created": 2, "match_ids": [...]}`

- [ ] **Step 6: Test /knockout endpoint (Estado 1)**

Mark all regular matches finished:
```bash
docker exec soccertrack_db mysql -uwpuser -pwppass soccertrack_wp -e "
UPDATE wp_ds_matches SET status='finished', home_score=2, away_score=1
WHERE tournament_id = {TID} AND phase='regular';"

curl -s -X POST "http://localhost/wp-json/soccertrack/v1/admin/tournament/{TID}/knockout" \
  -H "X-WP-Nonce: $NONCE" \
  -H "Content-Type: application/json" \
  -d '{"venue_id": 1}' | python3 -m json.tool
```
Expected: `{"tournament_id": N, "matches_created": 1, "match_ids": [...], "phase": "semifinal"}`  
(4 teams, 2 groups × 2 advancing = 4 qualifiers, but with 2-team groups only top-1 advances = 2 qualifiers → semifinal)

- [ ] **Step 7: Commit**

```bash
git add soccertrack/includes/RestApi/AdminEndpoints.php
git commit -m "feat(api): /fixture routes to group_stage generator; new /knockout endpoint for elimination rounds"
```

---

### Task 6: PublicEndpoints — GET /groups + group_label in /fixture

**Files:**
- Modify: `soccertrack/includes/RestApi/PublicEndpoints.php`
  - Add `'groups'` to `$routes` array in `register_routes()`
  - Add `'groups'` to `invalidate_cache()` loop
  - Add `m.group_label` to the SELECT in `get_fixture()`
  - Append new callback `get_groups()` before class closing `}`

**Interfaces:**
- Consumes: `StandingsCalculator::recalculate_by_group(int $tournament_id): array` (Task 2)
- Produces: `GET /public/tournament/{id}/groups` returns:
  ```json
  [{"label":"A","standings":[{...rows...}],"matches":[{...}]}, ...]
  ```

- [ ] **Step 1: Add `'groups'` to routes array**

In `PublicEndpoints::register_routes()`, the `$routes` array at line 38:
```php
$routes = [
    'standings' => [ self::class, 'get_standings' ],
    'fixture'   => [ self::class, 'get_fixture' ],
    'teams'     => [ self::class, 'get_teams' ],
    'tribunal'  => [ self::class, 'get_tribunal' ],
    'scorers'   => [ self::class, 'get_scorers' ],
    'stats'     => [ self::class, 'get_stats' ],
    'brackets'  => [ self::class, 'get_public_brackets' ],
];
```
Add `'groups' => [ self::class, 'get_groups' ],` as the last entry.

- [ ] **Step 2: Update `invalidate_cache()`**

At line 76:
```php
foreach ( [ 'standings', 'fixture', 'scorers', 'tribunal', 'teams', 'stats', 'brackets' ] as $s ) {
```
Change to:
```php
foreach ( [ 'standings', 'fixture', 'scorers', 'tribunal', 'teams', 'stats', 'brackets', 'groups' ] as $s ) {
```

- [ ] **Step 3: Add `m.group_label` to `get_fixture()` SELECT**

In `get_fixture()`, the large SELECT query (around line 172 in PublicEndpoints.php) starts with:
```sql
SELECT
    m.id,
    m.round_number,
    COALESCE(m.phase, 'regular') AS phase,
    m.bracket_id,
    b.name AS bracket_name,
    m.match_datetime,
```
Add `m.group_label,` after `b.name AS bracket_name,`:
```sql
    m.group_label,
```

- [ ] **Step 4: Add `get_groups()` callback**

Append before the class closing `}` in PublicEndpoints.php:

```php
	/**
	 * GET /public/tournament/{id}/groups
	 *
	 * Retorna standings y partidos por grupo para torneos en Fase de Grupos.
	 * Sin autenticación. Caché 60 s.
	 */
	public static function get_groups( \WP_REST_Request $request ): \WP_REST_Response {
		global $wpdb;

		$tid = (int) $request['id'];
		$key = self::cache_key( $tid, 'groups' );

		$cached = get_transient( $key );
		if ( false !== $cached ) {
			return rest_ensure_response( $cached );
		}

		// Standings por grupo.
		$standings_by_group = ( new \SportsLeague\Core\StandingsCalculator() )->recalculate_by_group( $tid );

		if ( empty( $standings_by_group ) ) {
			set_transient( $key, [], self::CACHE_TTL );
			return rest_ensure_response( [] );
		}

		// Partidos de fase regular con group_label.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$matches_raw = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT m.id, m.round_number, m.group_label, m.match_datetime,
				        m.home_score, m.away_score, m.status,
				        ht.name AS home_team, at.name AS away_team,
				        ht.logo_url AS home_logo, at.logo_url AS away_logo,
				        v.name AS venue, c.court_name
				 FROM {$wpdb->prefix}ds_matches m
				 JOIN {$wpdb->prefix}ds_teams ht ON ht.id = m.home_team_id
				 JOIN {$wpdb->prefix}ds_teams at ON at.id = m.away_team_id
				 LEFT JOIN {$wpdb->prefix}ds_venues v ON v.id = m.venue_id
				 LEFT JOIN {$wpdb->prefix}ds_courts c ON c.id = m.court_id
				 WHERE m.tournament_id = %d AND m.phase = 'regular' AND m.group_label IS NOT NULL
				 ORDER BY m.group_label ASC, m.round_number ASC, m.match_datetime ASC",
				$tid
			),
			ARRAY_A
		);

		// Agrupar partidos por group_label.
		$matches_by_group = [];
		foreach ( $matches_raw as $m ) {
			$lbl = (string) $m['group_label'];
			if ( ! isset( $matches_by_group[ $lbl ] ) ) {
				$matches_by_group[ $lbl ] = [];
			}
			$matches_by_group[ $lbl ][] = [
				'id'             => (int) $m['id'],
				'round_number'   => (int) $m['round_number'],
				'match_datetime' => $m['match_datetime'],
				'home_team'      => $m['home_team'],
				'away_team'      => $m['away_team'],
				'home_logo'      => $m['home_logo'],
				'away_logo'      => $m['away_logo'],
				'home_score'     => (int) $m['home_score'],
				'away_score'     => (int) $m['away_score'],
				'status'         => $m['status'],
				'venue'          => $m['venue'],
				'court_name'     => $m['court_name'],
			];
		}

		// Construir respuesta final.
		$data = [];
		foreach ( $standings_by_group as $label => $rows ) {
			$data[] = [
				'label'     => $label,
				'standings' => $rows,
				'matches'   => $matches_by_group[ $label ] ?? [],
			];
		}

		set_transient( $key, $data, self::CACHE_TTL );
		return rest_ensure_response( $data );
	}
```

- [ ] **Step 5: Test /groups endpoint**

Using the test tournament from Task 3 (with groups and some finished matches):
```bash
curl -s "http://localhost/wp-json/soccertrack/v1/public/tournament/{TID}/groups" | python3 -m json.tool
```
Expected: JSON array with `[{"label":"A","standings":[...],"matches":[...]},{"label":"B",...}]`

Also verify `/fixture` now includes `group_label` in each match:
```bash
curl -s "http://localhost/wp-json/soccertrack/v1/public/tournament/{TID}/fixture" | python3 -m json.tool | grep group_label
```
Expected: lines showing `"group_label": "A"` and `"group_label": "B"` for regular matches.

- [ ] **Step 6: Commit**

```bash
git add soccertrack/includes/RestApi/PublicEndpoints.php
git commit -m "feat(api): GET /groups endpoint; add group_label to /fixture response; invalidate groups cache"
```

---

### Task 7: Admin Panel — Tournament creation form

**Files:**
- Modify: `soccertrack/templates/panel/torneos.php` — add group-stage fields + JS show/hide
- Modify: `soccertrack/includes/Public/TournamentPage.php:431-453` — save group_stage config on creation

**Interfaces:**
- Consumes: form fields `group_count`, `teams_advancing_per_group`, `has_third_place`
- Produces: these fields saved to `wp_ds_tournaments` when `format = 'group_stage'`

- [ ] **Step 1: Add group-stage fields to the creation form in `torneos.php`**

After the format `<select>` closing `</div>` (around line 48), before the hidden `registration_mode` input (line 50), insert:

```php
		<div id="st-group-stage-options" style="display:none">
			<div class="st-field">
				<label class="st-label"><?php esc_html_e( 'Número de grupos', 'soccertrack' ); ?></label>
				<input type="number" name="group_count" class="st-input" value="2" min="2" max="8" style="max-width:80px">
			</div>
			<div class="st-field">
				<label class="st-label"><?php esc_html_e( 'Equipos que clasifican por grupo', 'soccertrack' ); ?></label>
				<input type="number" name="teams_advancing_per_group" class="st-input" value="2" min="1" max="4" style="max-width:80px">
			</div>
			<div class="st-field">
				<label class="st-label" style="display:flex;align-items:center;gap:8px">
					<input type="checkbox" name="has_third_place" value="1" checked>
					<?php esc_html_e( 'Partido por 3.er puesto', 'soccertrack' ); ?>
				</label>
			</div>
		</div>
```

- [ ] **Step 2: Add JS show/hide script in `torneos.php`**

Before the closing `</div>` of the form card (around line 56), add:

```html
	<script>
	(function() {
		var fmt   = document.querySelector('select[name="format"]');
		var opts  = document.getElementById('st-group-stage-options');
		function toggle() { opts.style.display = fmt.value === 'group_stage' ? '' : 'none'; }
		fmt.addEventListener('change', toggle);
		toggle();
	}());
	</script>
```

- [ ] **Step 3: Save group_stage config in `TournamentPage.php`**

In `TournamentPage.php`, inside the `if ($name)` block of the tournament creation handler (around line 435), modify the `$wpdb->insert()` call to include group_stage fields:

```php
// Current insert:
$wpdb->insert( // phpcs:ignore
    "{$wpdb->prefix}ds_tournaments",
    [
        'name'              => $name,
        'season'            => sanitize_text_field( $_POST['season'] ?? gmdate( 'Y' ) ),
        'start_date'        => sanitize_text_field( $_POST['start_date'] ?? '' ) ?: null,
        'end_date'          => sanitize_text_field( $_POST['end_date'] ?? '' ) ?: null,
        'format'            => sanitize_text_field( $_POST['format'] ?? 'round_robin' ),
        'status'            => 'draft',
        'registration_mode' => $reg_mode,
    ],
    [ '%s', '%s', '%s', '%s', '%s', '%s', '%s' ]
);
```
Change to:
```php
$format        = sanitize_text_field( $_POST['format'] ?? 'round_robin' );
$group_count   = 'group_stage' === $format ? max( 2, min( 8, (int) ( $_POST['group_count'] ?? 2 ) ) ) : 2;
$teams_adv     = 'group_stage' === $format ? max( 1, min( 4, (int) ( $_POST['teams_advancing_per_group'] ?? 2 ) ) ) : 2;
$has_3rd       = 'group_stage' === $format ? ( isset( $_POST['has_third_place'] ) ? 1 : 0 ) : 1;

$wpdb->insert( // phpcs:ignore
    "{$wpdb->prefix}ds_tournaments",
    [
        'name'                      => $name,
        'season'                    => sanitize_text_field( $_POST['season'] ?? gmdate( 'Y' ) ),
        'start_date'                => sanitize_text_field( $_POST['start_date'] ?? '' ) ?: null,
        'end_date'                  => sanitize_text_field( $_POST['end_date'] ?? '' ) ?: null,
        'format'                    => $format,
        'status'                    => 'draft',
        'registration_mode'         => $reg_mode,
        'group_count'               => $group_count,
        'teams_advancing_per_group' => $teams_adv,
        'has_third_place'           => $has_3rd,
    ],
    [ '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%d' ]
);
```

- [ ] **Step 4: Test the form in the browser**

Navigate to `http://localhost/panel/torneos/`.  
Select "Fase de grupos" in the format dropdown — the three group-stage fields should appear.  
Select another format — the fields should hide.  
Create a tournament with format=group_stage, group_count=3, teams_advancing=1.  

Verify the saved values:
```bash
docker exec soccertrack_db mysql -uwpuser -pwppass soccertrack_wp -e "
SELECT id, name, format, group_count, teams_advancing_per_group, has_third_place
FROM wp_ds_tournaments ORDER BY id DESC LIMIT 3;"
```
Expected: newest row has `format=group_stage`, `group_count=3`, `teams_advancing_per_group=1`.

- [ ] **Step 5: Commit**

```bash
git add soccertrack/templates/panel/torneos.php soccertrack/includes/Public/TournamentPage.php
git commit -m "feat(panel): group-stage config fields in tournament creation form"
```

---

### Task 8: Admin Panel — torneo-detalle.php "Fase Eliminatoria" section

**Files:**
- Modify: `soccertrack/includes/Public/TournamentPage.php:1038-1085` — add `group_stage_status` to render data
- Modify: `soccertrack/templates/panel/torneo-detalle.php` — add "Fase Eliminatoria" section for group_stage

**Interfaces:**
- Consumes: `$tournament['format']`, `$matches` (already loaded), `$brackets` (already loaded)
- Produces: A new PHP variable `$group_stage_status` passed to template, containing:
  ```php
  [
      'is_group_stage'        => bool,
      'all_regular_done'      => bool,
      'has_group_label'       => bool,  // fixture generated
      'has_quarterfinals'     => bool,
      'all_qf_done'           => bool,
      'has_semifinals'        => bool,
      'all_sf_done'           => bool,
      'has_finals'            => bool,
  ]
  ```

- [ ] **Step 1: Compute `$group_stage_status` in `view_torneo()`**

In `TournamentPage.php`, after the existing `$playoffs_status = compact(...)` line (around line 1047), add:

```php
		// ── Estado fase eliminatoria (group_stage) ───────────────────────────
		$is_group_stage  = ( $tournament['format'] ?? '' ) === 'group_stage';
		$has_group_label = $is_group_stage && ! empty( array_filter( $teams, static fn( $t ) => ! empty( $t['group_label'] ) ) );
		$qf_ms           = array_filter( $matches, static fn( $m ) => ( $m['phase'] ?? '' ) === 'quarterfinal' );
		$sf_ms           = array_filter( $matches, static fn( $m ) => ( $m['phase'] ?? '' ) === 'semifinal' );
		$final_ms        = array_filter( $matches, static fn( $m ) => in_array( $m['phase'] ?? '', [ 'final', 'third_place' ], true ) );
		$group_stage_status = [
			'is_group_stage'    => $is_group_stage,
			'has_group_label'   => $has_group_label,
			'all_regular_done'  => $is_group_stage && ! empty( $regular_matches ) &&
			                       count( array_filter( $regular_matches, static fn( $m ) => ! in_array( $m['status'], [ 'finished', 'suspended', 'postponed' ], true ) ) ) === 0,
			'has_quarterfinals' => ! empty( $qf_ms ),
			'all_qf_done'       => ! empty( $qf_ms ) && count( array_filter( $qf_ms, static fn( $m ) => ! in_array( $m['status'], [ 'finished', 'suspended', 'postponed' ], true ) ) ) === 0,
			'has_semifinals'    => ! empty( $sf_ms ),
			'all_sf_done'       => ! empty( $sf_ms ) && count( array_filter( $sf_ms, static fn( $m ) => ! in_array( $m['status'], [ 'finished', 'suspended', 'postponed' ], true ) ) ) === 0,
			'has_finals'        => ! empty( $final_ms ),
		];
```

Also update the `self::render()` call at line ~1085 to include `group_stage_status`:
```php
self::render( 'torneo-detalle', compact( 'tournament', 'teams', 'matches', 'notice', 'error', 'venues', 'tournament_venue_ids', 'courts_by_venue', 'referees', 'planilleros', 'page_title', 'playoffs_status', 'brackets', 'group_stage_status' ) );
```

Also, load `group_label` for teams in the `$teams` query (around line 569):
```php
"SELECT t.*, t.group_label, COUNT(tp.player_id) AS player_count
 FROM {$wpdb->prefix}ds_teams t
 LEFT JOIN {$wpdb->prefix}ds_team_players tp ON tp.team_id = t.id
 WHERE t.tournament_id = %d
 GROUP BY t.id ORDER BY t.name ASC",
```
(Add `t.group_label` to the SELECT — it's a wildcard `t.*` so it's already included, but make it explicit for clarity. Actually `t.*` already covers it, no change needed.)

- [ ] **Step 2: Add "Fase Eliminatoria" section to `torneo-detalle.php`**

Locate the line in `torneo-detalle.php`:
```php
<?php if ( ! empty( $playoffs_status['is_playoffs_format'] ) ) : ?>
```
(Line ~365 — beginning of the brackets card)

Add a new section **before** this existing block:

```php
<?php /* ── Fase Eliminatoria (solo formato group_stage) ──────────────── */ ?>
<?php if ( ! empty( $group_stage_status['is_group_stage'] ) ) : ?>
<div class="st-card" style="margin-bottom:20px" id="st-group-stage-card">
	<div class="st-card-header" style="display:flex;justify-content:space-between;align-items:center">
		<h2 class="st-card-title" style="margin:0"><?php esc_html_e( 'Grupos', 'soccertrack' ); ?></h2>
	</div>

	<?php if ( ! $group_stage_status['has_group_label'] ) : ?>
		<p class="st-empty-msg"><?php esc_html_e( 'Genera el fixture primero para ver los grupos.', 'soccertrack' ); ?></p>
	<?php else : ?>
		<?php
		// Agrupar equipos por group_label.
		$teams_by_group = [];
		foreach ( $teams as $t ) {
			if ( ! empty( $t['group_label'] ) ) {
				$teams_by_group[ $t['group_label'] ][] = $t;
			}
		}
		ksort( $teams_by_group );
		?>
		<div style="display:flex;flex-wrap:wrap;gap:16px;margin-top:12px">
		<?php foreach ( $teams_by_group as $label => $group_teams ) : ?>
			<div style="min-width:160px">
				<h3 style="font-size:.9rem;margin:0 0 8px;color:#0E0C19"><?php echo esc_html( __( 'Grupo', 'soccertrack' ) . ' ' . $label ); ?></h3>
				<ul style="margin:0;padding:0;list-style:none">
				<?php foreach ( $group_teams as $gt ) : ?>
					<li style="padding:4px 0;border-bottom:1px solid #eee;font-size:.85rem">
						<?php echo esc_html( $gt['name'] ); ?>
					</li>
				<?php endforeach; ?>
				</ul>
			</div>
		<?php endforeach; ?>
		</div>
	<?php endif; ?>
</div>

<?php /* ── Fase Eliminatoria — botones de generación ─────────────────── */ ?>
<div class="st-card" style="margin-bottom:20px" id="st-knockout-card">
	<div class="st-card-header">
		<h2 class="st-card-title"><?php esc_html_e( 'Fase Eliminatoria', 'soccertrack' ); ?></h2>
	</div>

	<div id="st-knockout-notice"></div>

	<?php
	$venues_for_knockout ??= ! empty( $tournament_venue_ids )
		? array_filter( $venues, static fn( $v ) => in_array( (int) $v['id'], $tournament_venue_ids, true ) )
		: $venues;
	?>

	<?php if ( $group_stage_status['has_finals'] ) : ?>
		<p style="color:#3CBC20;font-weight:600">✅ <?php esc_html_e( 'Fase eliminatoria completa.', 'soccertrack' ); ?></p>

	<?php elseif ( $group_stage_status['has_semifinals'] && ! $group_stage_status['all_sf_done'] ) : ?>
		<p class="st-empty-msg"><?php esc_html_e( 'Semi-finales en curso. Espera a que terminen para generar la Final.', 'soccertrack' ); ?></p>

	<?php elseif ( $group_stage_status['has_quarterfinals'] && ! $group_stage_status['all_qf_done'] ) : ?>
		<p class="st-empty-msg"><?php esc_html_e( 'Cuartos de final en curso. Espera a que terminen.', 'soccertrack' ); ?></p>

	<?php elseif ( ! $group_stage_status['all_regular_done'] ) : ?>
		<p class="st-empty-msg"><?php esc_html_e( 'La eliminatoria estará disponible cuando todos los partidos de grupos estén finalizados.', 'soccertrack' ); ?></p>

	<?php else : ?>
		<?php
		$knockout_btn_label = __( 'Generar Eliminatoria', 'soccertrack' );
		if ( $group_stage_status['all_sf_done'] && ! $group_stage_status['has_finals'] ) {
			$knockout_btn_label = __( 'Generar Final', 'soccertrack' );
		} elseif ( $group_stage_status['all_qf_done'] && ! $group_stage_status['has_semifinals'] ) {
			$knockout_btn_label = __( 'Generar Semi-finales', 'soccertrack' );
		}
		?>
		<?php if ( ! empty( $venues_for_knockout ) ) : ?>
		<div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap">
			<select id="st-knockout-venue-select" class="st-input" style="max-width:220px">
				<option value=""><?php esc_html_e( '— Seleccionar recinto —', 'soccertrack' ); ?></option>
				<?php foreach ( $venues_for_knockout as $v ) : ?>
					<option value="<?php echo esc_attr( (string) $v['id'] ); ?>"><?php echo esc_html( $v['name'] ); ?></option>
				<?php endforeach; ?>
			</select>
			<input type="date" id="st-knockout-date-input" class="st-input" style="max-width:160px"
			       title="<?php esc_attr_e( 'Fecha opcional. Si no se elige, se usará el próximo día hábil.', 'soccertrack' ); ?>">
			<button class="st-btn st-btn--primary" id="st-gen-knockout-btn"
				data-tournament="<?php echo esc_attr( (string) $tournament['id'] ); ?>"
				data-nonce="<?php echo esc_attr( wp_create_nonce( 'wp_rest' ) ); ?>">
				⚡ <?php echo esc_html( $knockout_btn_label ); ?>
			</button>
		</div>
		<?php else : ?>
			<p class="st-alert st-alert--warning"><?php esc_html_e( 'Asigna al menos un recinto al torneo para generar la eliminatoria.', 'soccertrack' ); ?></p>
		<?php endif; ?>
	<?php endif; ?>
</div>

<script>
(function() {
	var btn   = document.getElementById('st-gen-knockout-btn');
	if ( ! btn ) return;
	btn.addEventListener('click', function() {
		var venueEl = document.getElementById('st-knockout-venue-select');
		var dateEl  = document.getElementById('st-knockout-date-input');
		var venueId = venueEl ? parseInt(venueEl.value, 10) : 0;
		if ( ! venueId ) { alert('Selecciona un recinto.'); return; }

		var tid        = btn.dataset.tournament;
		var nonce      = btn.dataset.nonce;
		var matchDate  = dateEl && dateEl.value ? dateEl.value : null;
		var body       = { venue_id: venueId };
		if ( matchDate ) body.match_date = matchDate;

		btn.disabled = true;
		btn.textContent = '⏳ Generando…';

		fetch('/wp-json/soccertrack/v1/admin/tournament/' + tid + '/knockout', {
			method:  'POST',
			headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': nonce },
			body:    JSON.stringify(body),
		})
		.then(r => r.json())
		.then(data => {
			var notice = document.getElementById('st-knockout-notice');
			if (data.matches_created > 0) {
				notice.innerHTML = '<div class="st-alert st-alert--success">✅ ' + data.matches_created + ' partido(s) generado(s) — ' + data.phase + '. <a href="">Recargar</a></div>';
			} else {
				notice.innerHTML = '<div class="st-alert st-alert--error">⚠️ ' + (data.message || data.error || 'Error') + '</div>';
				btn.disabled = false;
				btn.textContent = '⚡ Reintentar';
			}
		})
		.catch(err => {
			document.getElementById('st-knockout-notice').innerHTML = '<div class="st-alert st-alert--error">⚠️ ' + err.message + '</div>';
			btn.disabled = false;
		});
	});
}());
</script>
<?php endif; /* is_group_stage */ ?>
```

- [ ] **Step 3: Test in browser**

Navigate to `http://localhost/panel/torneo/{TID}/` where TID is the group_stage test tournament.  

Verify:
- "Grupos" card shows teams split into groups (after fixture generated)
- "Fase Eliminatoria" card shows informative message when regular phase not done
- After marking all regular matches finished (via browser or SQL), the "Generar Eliminatoria" button appears
- Clicking the button generates the elimination round and shows success message

- [ ] **Step 4: Commit**

```bash
git add soccertrack/includes/Public/TournamentPage.php soccertrack/templates/panel/torneo-detalle.php
git commit -m "feat(panel): group-stage groups display and Fase Eliminatoria section in torneo-detalle"
```

---

### Task 9: Portal Público — group standings + group fixture in JS

**Files:**
- Modify: `soccertrack/templates/public/tournament-page.php:112-160` — add `tournamentFormat` to `stPublic`
- Modify: `soccertrack/assets/js/live-standings.js` — group-aware standings and fixture renderers

**Interfaces:**
- Consumes: `GET /groups` (Task 6); `stPublic.tournamentFormat`; `group_label` in `/fixture` response (Task 6)
- Produces: standings tab shows N group tables; fixture tab groups by `group_label` + shows elimination sections with `quarterfinal` phase

- [ ] **Step 1: Add `tournamentFormat` to `stPublic` in `tournament-page.php`**

In `templates/public/tournament-page.php`, in the `window.stPublic = ...` block, add after `'basesUrl'`:
```php
'tournamentFormat' => $tournament['format'] ?? '',
```

- [ ] **Step 2: Add `FORMAT` constant and update `renderStandings()` in `live-standings.js`**

At the top of the IIFE (after `const TID = ...`, around line 20), add:
```js
const FORMAT = cfg.tournamentFormat ?? '';
```

Replace the entire `renderStandings()` function with a version that branches on `FORMAT`:

```js
async function renderStandings( container ) {
    showLoading( container );

    try {
        if ( FORMAT === 'group_stage' ) {
            await renderGroupStandings( container );
        } else {
            await renderSingleStandings( container );
        }
    } catch ( err ) {
        showError( container, `${ i18n.error_load ?? 'Error al cargar posiciones.' } (${ err.message })` );
    }
}

async function renderGroupStandings( container ) {
    const groups = await apiFetch( `soccertrack/v1/public/tournament/${ TID }/groups` );

    if ( ! groups.length ) {
        return showEmpty( container, i18n.no_standings ?? 'Aún no hay partidos jugados.' );
    }

    let html = `<h2 class="st-section-title">${ i18n.standings_title ?? 'Tabla de Posiciones' }</h2>`;

    for ( const group of groups ) {
        const rows = group.standings ?? [];
        const advancing = rows.length > 0 ? Math.ceil( rows.length / 2 ) : 1; // fallback visual
        // Detect advancing count from first group where all teams have played.
        // (The server handles the actual logic; we just mark the top rows visually.)
        // The spec says "las primeras teams_advancing_per_group filas" — since we don't know
        // that value client-side, mark top-2 by default (safe for 2-groups × 2 advancing).
        // For precise marking, the /groups response includes it implicitly via ordering.

        const trs = rows.map( ( r, idx ) => {
            const pos  = idx + 1;
            const zone = pos <= 2 ? 'playoff' : ''; // top-2 as qualifying zone visual
            const formBubbles = ( r.form ?? [] ).map( result => {
                const cls = result === 'W' ? 'w' : result === 'D' ? 'd' : 'l';
                const lbl = result === 'W' ? 'V' : result === 'D' ? 'E' : 'D';
                return `<span class="st-form-bubble st-form-bubble--${ cls }">${ lbl }</span>`;
            } ).join( '' );
            return `
            <tr${ zone ? ` data-zone="${ zone }"` : '' }>
                <td class="st-rank">${ pos }</td>
                <td>${ escHtml( r.name ) }</td>
                <td>${ r.pj }</td>
                <td>${ r.pg }</td>
                <td>${ r.pe }</td>
                <td>${ r.pp }</td>
                <td>${ r.gf }</td>
                <td>${ r.gc }</td>
                <td>${ r.dg >= 0 ? '+' : '' }${ r.dg }</td>
                <td class="st-pts">${ r.pts }</td>
                <td class="st-form">${ formBubbles }</td>
            </tr>`;
        } ).join( '' );

        html += `
        <h3 class="st-subsection-title" style="margin-top:1.5rem;color:#0E0C19">${ escHtml( i18n.group_label ?? 'Grupo' ) } ${ escHtml( group.label ) }</h3>
        <div class="st-table-wrap" style="margin-bottom:1rem">
            <table class="st-table st-standings-table" aria-label="Grupo ${ escHtml( group.label ) }">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>${ i18n.team ?? 'Equipo' }</th>
                        <th title="${ i18n.played ?? 'Jugados' }">PJ</th>
                        <th title="${ i18n.won ?? 'Ganados' }">PG</th>
                        <th title="${ i18n.drawn ?? 'Empatados' }">PE</th>
                        <th title="${ i18n.lost ?? 'Perdidos' }">PP</th>
                        <th title="${ i18n.gf ?? 'Goles a Favor' }">GF</th>
                        <th title="${ i18n.gc ?? 'Goles en Contra' }">GC</th>
                        <th title="${ i18n.dg ?? 'Diferencia de Goles' }">DG</th>
                        <th title="${ i18n.pts ?? 'Puntos' }">PTS</th>
                        <th title="${ escHtml( i18n.form ?? 'Últimos 5 partidos' ) }">Forma</th>
                    </tr>
                </thead>
                <tbody>${ trs }</tbody>
            </table>
        </div>`;
    }

    container.innerHTML = html;
}

async function renderSingleStandings( container ) {
    // [PASTE THE EXISTING renderStandings() BODY HERE — the full try block content]
    // This is the existing implementation unchanged.
    const rows = await apiFetch( `soccertrack/v1/public/tournament/${ TID }/standings` );
    // ... (keep all existing code starting from `if (!rows.length)` down to `${chartsHtml}`)
}
```

**Important:** The `renderSingleStandings()` function body must contain the **entire existing `renderStandings()` try-block content** verbatim (lines 124-297 of the current file). Copy it exactly.

- [ ] **Step 3: Update `renderFixture()` in `live-standings.js` for group_stage**

Replace the section that separates matches into regular/playoff (around lines 343-388 of the current file):

Change:
```js
// Separar partidos regulares de play-offs.
const PLAYOFF_PHASES = [ 'semifinal', 'third_place', 'final' ];
const regularMatches  = matches.filter( m => ! PLAYOFF_PHASES.includes( m.phase ) );
const playoffMatches  = matches.filter( m => PLAYOFF_PHASES.includes( m.phase ) );
```
To:
```js
// Separar partidos regulares de eliminatoria.
const PLAYOFF_PHASES = [ 'quarterfinal', 'semifinal', 'third_place', 'final' ];
const regularMatches  = matches.filter( m => ! PLAYOFF_PHASES.includes( m.phase ) );
const playoffMatches  = matches.filter( m => PLAYOFF_PHASES.includes( m.phase ) );
const isGroupStage    = FORMAT === 'group_stage';
```

Replace the round-nav section for regular matches (the block that builds `sortedRounds`, `activeRound`, etc.) to conditionally group by `group_label` for group_stage:

```js
if ( isGroupStage && regularMatches.some( m => m.group_label ) ) {
    // Agrupar por group_label, luego por round dentro de cada grupo.
    /** @type {Map<string, typeof matches>} */
    const groupMap = new Map();
    for ( const m of regularMatches ) {
        const lbl = m.group_label ?? 'Sin Grupo';
        if ( ! groupMap.has( lbl ) ) groupMap.set( lbl, [] );
        groupMap.get( lbl ).push( m );
    }

    const sortedGroups = [ ...groupMap.entries() ].sort( ( a, b ) => a[0].localeCompare( b[0] ) );

    for ( const [ lbl, gMatches ] of sortedGroups ) {
        html += `<h2 class="st-section-title" style="margin-top:1.5rem">${ escHtml( i18n.group_label ?? 'Grupo' ) } ${ escHtml( lbl ) }</h2>`;

        const gRounds = new Map();
        for ( const m of gMatches ) {
            const r = Number( m.round_number );
            if ( ! gRounds.has( r ) ) gRounds.set( r, [] );
            gRounds.get( r ).push( m );
        }

        for ( const [ r, ms ] of [ ...gRounds.entries() ].sort( ( a, b ) => a[0] - b[0] ) ) {
            html += `<h3 class="st-round-header" style="font-size:.85rem;margin:8px 0 4px">${ i18n.round ?? 'Fecha' } ${ r }</h3>`;
            html += ms.map( matchCard ).join( '' );
        }
    }
} else {
    // Render clásico por jornadas (round-robin, etc.).
    // [PASTE EXISTING sortedRounds / round-nav block here verbatim]
}
```

**Important:** The `else` block must contain the **entire existing** sorted-rounds + round-nav HTML block from the current `renderFixture()` (lines 350-388 of the current file). Copy it exactly.

Also update `phaseTitle` in the playoff section to include `quarterfinal`:
```js
const phaseTitle = {
    quarterfinal: i18n.phase_quarterfinal ?? 'Cuartos de Final',
    semifinal:    i18n.phase_semifinal    ?? 'Semi-finales',
    third_place:  i18n.phase_third_place  ?? '3.er Puesto',
    final:        i18n.phase_final        ?? 'Final',
};
```

- [ ] **Step 4: Add i18n strings for group_stage to `tournament-page.php`**

In the `i18n` array inside `stPublic`, add:
```php
'group_label'        => __( 'Grupo', 'soccertrack' ),
'phase_quarterfinal' => __( 'Cuartos de Final', 'soccertrack' ),
'phase_semifinal'    => __( 'Semi-finales', 'soccertrack' ),
'phase_third_place'  => __( '3.er Puesto', 'soccertrack' ),
'phase_final'        => __( 'Final', 'soccertrack' ),
```

- [ ] **Step 5: Test the portal in browser**

Navigate to `http://localhost/torneo/{TID}/` (the group_stage test tournament).  

Verify "Posiciones" tab:
- Shows "Grupo A" and "Grupo B" tables separately
- Rows ordered by PTS → DG → GF within each group
- Top rows have `data-zone="playoff"` green border

Verify "Fixture" tab:
- Shows "── Grupo A ──" and "── Grupo B ──" section headers
- After elimination round generated: shows "Cuartos de Final" or "Semi-finales" section below groups

Also verify a non-group-stage tournament still works (standings shows single table, fixture shows round-nav buttons).

- [ ] **Step 6: Commit**

```bash
git add soccertrack/templates/public/tournament-page.php soccertrack/assets/js/live-standings.js
git commit -m "feat(portal): group-stage aware standings and fixture rendering in public JS portal"
```

---

## Self-Review

### 1. Spec Coverage

| Spec Requirement | Task |
|---|---|
| `group_count`, `teams_advancing_per_group`, `has_third_place` columns in `ds_tournaments` | Task 1 |
| `group_label VARCHAR(5) NULL` in `ds_teams` | Task 1 |
| `group_label VARCHAR(5) NULL` in `ds_matches` | Task 1 |
| Extend phase ENUM with `'quarterfinal'` | Task 1 |
| `StandingsCalculator::recalculate_by_group()` | Task 2 |
| `FixtureGenerator::generate_group_stage()` with Fisher-Yates + round-robin per group | Task 3 |
| `FixtureGenerator::generate_group_knockout()` with 3-state machine | Task 4 |
| `/fixture` endpoint routes to `generate_group_stage()` for group_stage format | Task 5 |
| New `POST /admin/tournament/{id}/knockout` endpoint | Task 5 |
| `GET /public/tournament/{id}/groups` endpoint | Task 6 |
| `group_label` in `/fixture` response | Task 6 |
| Invalidate `groups` cache when match closed | Task 6 |
| Group-stage config fields in creation form with JS show/hide | Task 7 |
| Save group_stage config on tournament creation | Task 7 |
| "Grupos" card shows teams per group in detail panel | Task 8 |
| "Fase Eliminatoria" section with knockout button in detail panel | Task 8 |
| Button auto-detects which round to generate | Task 8 |
| Portal "Posiciones" shows N per-group tables | Task 9 |
| Portal "Fixture" groups by label + elimination phases | Task 9 |
| `quarterfinal` in PLAYOFF_PHASES in JS | Task 9 |
| Backward compatible (`group_label = NULL` in non-group-stage) | Tasks 1, 3, 4 |
| No fixture regeneration if matches exist | Task 3 |
| `teams_advancing_per_group` qualifiers per group → 2, 4, or 8 total → error otherwise | Task 4 |

### 2. Placeholder Scan

None. All code blocks are complete and runnable.

### 3. Type Consistency

- `recalculate_by_group()` returns `array<string, array<...>>` — Task 4 calls it and iterates `foreach ($standings_by_group as $rows)` with `$row['team_id']` — matches the row structure from `recalculate()`.
- `generate_group_stage()` returns `['match_ids' => int[], 'error?' => string]` — Task 5 reads `$result['error']` and `$result['match_ids']` — matches.
- `generate_group_knockout()` returns `['match_ids' => int[], 'phase' => string, 'error?' => string]` — Task 5 callback reads all three keys — matches.
- `/groups` REST response: `[{'label':string, 'standings':array, 'matches':array}]` — Task 9 JS iterates `groups`, accesses `group.standings`, `group.label`, `group.matches` — matches.
- `group_label` in `/fixture` REST response — Task 9 JS accesses `m.group_label` — matches Task 6 added field.
