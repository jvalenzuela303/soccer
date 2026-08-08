# Flujos Duales de Registro de Partidos — Plan de Implementación

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Agregar al torneo un campo `registration_mode` que determina cómo se registran los resultados: **realtime** (planillero/árbitro en vivo, flujo actual) o **deferred** (coordinador ingresa todo posteriormente desde planilla física). Ambos modos producen exactamente los mismos datos en `ds_match_events`, por lo que estadísticas, posiciones y goleadores funcionan sin cambios.

**Architecture:** Una nueva columna en `ds_tournaments` (`registration_mode`) controla qué UX se presenta. En modo `deferred` se habilita la vista `/panel/carga-fecha/` (nuevo) que permite al coordinador ingresar eventos de todos los partidos de una fecha de una sola vez. La lógica REST existente (`post_match_event`, `post_match_result`) no necesita cambios — ya permite que coordinadores entren eventos. El único cambio de backend es: en modo `deferred`, el match sheet no exige que el partido esté en `in_progress` para aceptar eventos (ya está así en el endpoint REST; solo hay que actualizar la config del JS).

**Tech Stack:** PHP 8.2, WordPress 7.0.2, MariaDB 10.6, JS vanilla (match-sheet.js existente reutilizado).

## Global Constraints

- PHP 8.2, WordPress 7.0.2, MariaDB 10.6
- Migraciones vía dbDelta() + apply_index_migrations()
- Namespace: `SportsLeague\Core`, `SportsLeague\RestApi`
- Text domain: `soccertrack`
- El endpoint `POST /admin/match/{id}/event` ya NO bloquea partidos en estado `scheduled` — solo bloquea `finished`. Esto es correcto y suficiente para el modo deferred.
- Estadísticas, tabla de posiciones y goleadores: **cero cambios** — consumen `ds_match_events` independientemente del modo
- `registration_mode` se define por torneo, no globalmente

---

## Mapa de archivos

| Archivo | Acción | Qué cambia |
|---------|--------|-----------|
| `soccertrack/includes/Core/DatabaseInstaller.php` | Modificar | Agregar `registration_mode` a `ds_tournaments` |
| `soccertrack/includes/Public/TournamentPage.php` | Modificar | view_torneos (INSERT), view_torneo (pass al template), add_rewrite_rules, handle_panel dispatch, nuevo método view_carga_fecha() |
| `soccertrack/templates/panel/torneos.php` | Modificar | Selector de modo en formulario de creación |
| `soccertrack/templates/panel/torneo-detalle.php` | Modificar | Mostrar modo; en deferred: botones "Cargar acta" por jornada; en realtime: flujo actual |
| `soccertrack/templates/panel/carga-fecha.php` | **CREAR** | Vista de carga de acta para el coordinador (modo deferred) |
| `soccertrack/assets/js/match-sheet.js` | Modificar | Recibir `registrationMode` en config; en deferred no bloquear UI por estado `scheduled` |
| `soccertrack/templates/panel/partido.php` | Modificar | Pasar `registrationMode` al config de match-sheet.js |

---

### Task 1: Migración de base de datos

**Files:**
- Modify: `soccertrack/includes/Core/DatabaseInstaller.php`

**Interfaces:**
- Produce: columna `registration_mode ENUM('realtime','deferred') NOT NULL DEFAULT 'realtime'` en `ds_tournaments`

- [ ] **Step 1: Agregar columna al CREATE TABLE de ds_tournaments**

En `create_tables()`, bloque de `ds_tournaments`, agregar después de `match_time`:

```php
registration_mode ENUM('realtime','deferred') NOT NULL DEFAULT 'realtime',
```

El bloque queda (columnas relevantes):

```php
dbDelta( "CREATE TABLE {$wpdb->prefix}ds_tournaments (
    id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    name              VARCHAR(150)    NOT NULL,
    season            VARCHAR(50)     NOT NULL,
    start_date        DATE            NULL,
    end_date          DATE            NULL,
    format            ENUM('round_robin','round_robin_playoffs','group_stage','knockout') NOT NULL DEFAULT 'round_robin',
    status            ENUM('draft','active','completed') NOT NULL DEFAULT 'draft',
    bases_pdf_url     VARCHAR(255)    NULL,
    match_weekday     TINYINT(1)      NOT NULL DEFAULT 6,
    match_time        TIME            NOT NULL DEFAULT '19:00:00',
    registration_mode ENUM('realtime','deferred') NOT NULL DEFAULT 'realtime',
    created_at        DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_status (status)
) ENGINE=InnoDB {$c};" );
```

- [ ] **Step 2: Agregar migración para instancias existentes**

En `apply_index_migrations()`, después de la migración de `match_weekday` (Task 1 del plan anterior):

```php
// v1.6.1 — ds_tournaments: modo de registro de partidos.
$has_reg_mode = $wpdb->get_var( "SHOW COLUMNS FROM {$prefix}ds_tournaments LIKE 'registration_mode'" ); // phpcs:ignore
if ( ! $has_reg_mode ) {
    $wpdb->query( // phpcs:ignore
        "ALTER TABLE {$prefix}ds_tournaments
         ADD COLUMN registration_mode ENUM('realtime','deferred') NOT NULL DEFAULT 'realtime'
         AFTER match_time"
    );
}
```

- [ ] **Step 3: Verificar migración**

```sql
SHOW COLUMNS FROM wp_ds_tournaments LIKE 'registration_mode';
```

Resultado esperado: fila con `Field='registration_mode'`, `Type='enum('realtime','deferred')'`, `Default='realtime'`.

- [ ] **Step 4: Commit**

```bash
git add soccertrack/includes/Core/DatabaseInstaller.php
git commit -m "feat: add registration_mode to ds_tournaments"
```

---

### Task 2: Formulario de creación de torneo con selector de modo

**Files:**
- Modify: `soccertrack/includes/Public/TournamentPage.php` (view_torneos INSERT)
- Modify: `soccertrack/templates/panel/torneos.php`

**Interfaces:**
- Consume: POST field `registration_mode` ('realtime' | 'deferred')
- Produce: torneo creado con modo correcto

- [ ] **Step 1: Sanitizar y persistir registration_mode en view_torneos()**

En `TournamentPage.php`, método `view_torneos()`, en el INSERT del torneo:

```php
$reg_mode = sanitize_key( $_POST['registration_mode'] ?? 'realtime' );
$reg_mode = in_array( $reg_mode, [ 'realtime', 'deferred' ], true ) ? $reg_mode : 'realtime';

$wpdb->insert(
    "{$wpdb->prefix}ds_tournaments",
    [
        'name'              => $name,
        'season'            => sanitize_text_field( $_POST['season'] ?? gmdate( 'Y' ) ),
        'start_date'        => sanitize_text_field( $_POST['start_date'] ?? '' ) ?: null,
        'end_date'          => sanitize_text_field( $_POST['end_date'] ?? '' ) ?: null,
        'format'            => sanitize_text_field( $_POST['format'] ?? 'round_robin' ),
        'status'            => 'draft',
        'match_weekday'     => $match_weekday,    // del plan de horarios
        'match_time'        => $match_time,       // del plan de horarios
        'registration_mode' => $reg_mode,
    ],
    [ '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s' ]
);
```

- [ ] **Step 2: Agregar selector al formulario en torneos.php**

En `templates/panel/torneos.php`, en el form de creación, después del selector de formato:

```php
<div class="st-field">
    <label for="st-registration-mode" class="st-label">
        <?php esc_html_e( 'Modo de registro de partidos', 'soccertrack' ); ?>
    </label>
    <select id="st-registration-mode" name="registration_mode" class="st-input">
        <option value="realtime">
            ⚡ <?php esc_html_e( 'Tiempo real (planillero en vivo)', 'soccertrack' ); ?>
        </option>
        <option value="deferred">
            📋 <?php esc_html_e( 'Planilla física (coordinador carga después)', 'soccertrack' ); ?>
        </option>
    </select>
    <span style="font-size:.78rem;color:#888;display:block;margin-top:4px">
        <?php esc_html_e( 'Puede cambiarse después desde el detalle del torneo.', 'soccertrack' ); ?>
    </span>
</div>
```

- [ ] **Step 3: Prueba manual**

1. Crear torneo con modo "Planilla física".
2. Verificar en BD: `SELECT registration_mode FROM wp_ds_tournaments ORDER BY id DESC LIMIT 1;` → `deferred`.

- [ ] **Step 4: Commit**

```bash
git add soccertrack/includes/Public/TournamentPage.php \
        soccertrack/templates/panel/torneos.php
git commit -m "feat: add registration_mode selector to tournament creation"
```

---

### Task 3: Editar y mostrar el modo en torneo-detalle

**Files:**
- Modify: `soccertrack/includes/Public/TournamentPage.php` (view_torneo)
- Modify: `soccertrack/templates/panel/torneo-detalle.php`

**Interfaces:**
- Consume: POST `st_update_reg_mode`, `registration_mode`
- Produce: modo persistido en BD; template muestra el modo actual; en deferred → botones "Cargar acta" por jornada

- [ ] **Step 1: Manejar POST st_update_reg_mode en view_torneo()**

En `TournamentPage.php`, método `view_torneo()`, agregar bloque POST:

```php
// ── Actualizar modo de registro ───────────────────────────────────
if ( $_SERVER['REQUEST_METHOD'] === 'POST' && isset( $_POST['st_update_reg_mode'] ) ) {
    check_admin_referer( 'st_update_reg_mode_' . $id );

    $reg_mode = sanitize_key( $_POST['registration_mode'] ?? 'realtime' );
    $reg_mode = in_array( $reg_mode, [ 'realtime', 'deferred' ], true ) ? $reg_mode : 'realtime';

    $wpdb->update( // phpcs:ignore
        "{$wpdb->prefix}ds_tournaments",
        [ 'registration_mode' => $reg_mode ],
        [ 'id' => $id ],
        [ '%s' ],
        [ '%d' ]
    );
    $notice = 'reg_mode_updated';

    $tournament = $wpdb->get_row(
        $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}ds_tournaments WHERE id = %d", $id ),
        ARRAY_A
    );
}
```

- [ ] **Step 2: Agregar alerta en torneo-detalle.php**

```php
<?php if ( ( $notice ?? '' ) === 'reg_mode_updated' ) : ?>
    <div class="st-alert st-alert--success">✅ <?php esc_html_e( 'Modo de registro actualizado.', 'soccertrack' ); ?></div>
<?php endif; ?>
```

- [ ] **Step 3: Agregar tarjeta de modo de registro en torneo-detalle.php**

Agregar junto a la tarjeta de horario (Task 5 del plan anterior):

```php
<?php /* ── Modo de registro de partidos ─────────────────────────────── */ ?>
<div class="st-card" style="margin-bottom:20px">
    <div class="st-card-header">
        <h2 class="st-card-title">
            <?php echo $tournament['registration_mode'] === 'deferred' ? '📋' : '⚡'; ?>
            <?php esc_html_e( 'Modo de registro', 'soccertrack' ); ?>
        </h2>
    </div>
    <form method="post" action="" class="st-form-inline" style="align-items:flex-end;gap:16px">
        <?php wp_nonce_field( 'st_update_reg_mode_' . $tournament['id'] ); ?>
        <input type="hidden" name="st_update_reg_mode" value="1">

        <div class="st-field">
            <label class="st-label"><?php esc_html_e( 'Modo actual', 'soccertrack' ); ?></label>
            <select name="registration_mode" class="st-input">
                <option value="realtime" <?php selected( $tournament['registration_mode'] ?? 'realtime', 'realtime' ); ?>>
                    ⚡ <?php esc_html_e( 'Tiempo real (planillero)', 'soccertrack' ); ?>
                </option>
                <option value="deferred" <?php selected( $tournament['registration_mode'] ?? 'realtime', 'deferred' ); ?>>
                    📋 <?php esc_html_e( 'Planilla física (coordinador)', 'soccertrack' ); ?>
                </option>
            </select>
        </div>
        <div class="st-field" style="align-self:flex-end">
            <button type="submit" class="st-btn st-btn--secondary st-btn--sm">
                💾 <?php esc_html_e( 'Guardar', 'soccertrack' ); ?>
            </button>
        </div>
    </form>
</div>
```

- [ ] **Step 4: Modificar la sección de Fixture en torneo-detalle.php según el modo**

En la tabla de fixture de `torneo-detalle.php`, en la columna "Planillero" (actualmente muestra el select de asignación de planillero):

```php
<td>
<?php if ( ( $tournament['registration_mode'] ?? 'realtime' ) === 'realtime' ) : ?>
    <?php /* Asignación de planillero — flujo existente */ ?>
    <!-- ... código actual del select de planillero ... -->
<?php else : ?>
    <span style="font-size:.78rem;color:#888">—</span>
<?php endif; ?>
</td>
```

Y en la misma fila, en la columna "Planilla" (botón de acceso al partido), para modo deferred agregar referencia a la vista de carga masiva por round. En el encabezado de cada jornada (grupo de partidos del mismo `round_number`), agregar:

```php
<?php /* Agregar header de jornada con botón "Cargar acta" en modo deferred */ ?>
<?php if ( $prev_round !== (int) $m['round_number'] && ( $tournament['registration_mode'] ?? 'realtime' ) === 'deferred' ) : ?>
    <?php $prev_round = (int) $m['round_number']; ?>
    <tr>
        <td colspan="10" style="background:#f0f7ff;padding:6px 12px">
            <strong><?php printf( esc_html__( 'Jornada %d', 'soccertrack' ), (int) $m['round_number'] ); ?></strong>
            <a href="<?php echo esc_url( home_url( '/panel/carga-fecha/?tournament_id=' . $tournament['id'] . '&round=' . (int) $m['round_number'] ) ); ?>"
               class="st-btn st-btn--sm st-btn--primary" style="margin-left:12px">
                📋 <?php esc_html_e( 'Cargar acta de esta jornada', 'soccertrack' ); ?>
            </a>
        </td>
    </tr>
<?php endif; ?>
```

Para que el `$prev_round` funcione, inicializarlo antes del foreach:

```php
<?php $prev_round = -1; ?>
<?php foreach ( $matches as $m ) : ?>
```

- [ ] **Step 5: Prueba manual**

1. Abrir torneo en modo deferred.
2. Verificar que la columna Planillero muestra "—".
3. Verificar que cada jornada muestra el botón "Cargar acta".

- [ ] **Step 6: Commit**

```bash
git add soccertrack/includes/Public/TournamentPage.php \
        soccertrack/templates/panel/torneo-detalle.php
git commit -m "feat: show registration_mode in tournament detail, deferred UI adjustments"
```

---

### Task 4: Nueva ruta y vista view_carga_fecha()

**Files:**
- Modify: `soccertrack/includes/Public/TournamentPage.php` (add_rewrite_rules, handle_panel, nuevo método)
- Create: `soccertrack/templates/panel/carga-fecha.php`

**Interfaces:**
- URL: `/panel/carga-fecha/?tournament_id={id}&round={n}`
- Require: `ds_manage_tournaments` capability
- Consume: GET `tournament_id`, `round`
- Produce: template con todos los partidos de la jornada, config de match-sheet.js por partido

- [ ] **Step 1: Registrar la rewrite rule**

En `TournamentPage::add_rewrite_rules()`:

```php
// Agregar junto a las otras reglas del panel:
add_rewrite_rule( '^panel/carga-fecha/?$', "index.php?{$qv}=1&{$qvv}=carga-fecha", 'top' );
```

- [ ] **Step 2: Agregar al dispatch de handle_panel()**

En el `match ( $vista )`:

```php
'carga-fecha'   => self::view_carga_fecha(),
```

- [ ] **Step 3: Implementar view_carga_fecha()**

Agregar método privado en `TournamentPage.php`:

```php
private static function view_carga_fecha(): void {
    global $wpdb;

    if ( ! current_user_can( 'ds_manage_tournaments' ) ) {
        wp_die( esc_html__( 'Sin permiso.', 'soccertrack' ), 403 );
    }

    $tournament_id = absint( $_GET['tournament_id'] ?? 0 );
    $round         = absint( $_GET['round'] ?? 0 );

    if ( ! $tournament_id || ! $round ) {
        wp_safe_redirect( home_url( '/panel/torneos/' ) );
        exit;
    }

    $tournament = $wpdb->get_row( // phpcs:ignore
        $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}ds_tournaments WHERE id = %d", $tournament_id ),
        ARRAY_A
    );

    if ( ! $tournament || ( $tournament['registration_mode'] ?? 'realtime' ) !== 'deferred' ) {
        wp_die( esc_html__( 'Esta vista solo está disponible para torneos en modo planilla física.', 'soccertrack' ), 403 );
    }

    // Cargar todos los partidos de la jornada.
    $matches = $wpdb->get_results( // phpcs:ignore
        $wpdb->prepare(
            "SELECT m.*,
                    th.name AS home_team_name,
                    ta.name AS away_team_name,
                    v.name  AS venue_name,
                    c.court_name
             FROM {$wpdb->prefix}ds_matches m
             JOIN {$wpdb->prefix}ds_teams th ON th.id = m.home_team_id
             JOIN {$wpdb->prefix}ds_teams ta ON ta.id = m.away_team_id
             LEFT JOIN {$wpdb->prefix}ds_venues v ON v.id = m.venue_id
             LEFT JOIN {$wpdb->prefix}ds_courts c ON c.id = m.court_id
             WHERE m.tournament_id = %d AND m.round_number = %d
             ORDER BY m.match_datetime ASC",
            $tournament_id,
            $round
        ),
        ARRAY_A
    ) ?: [];

    // Para cada partido, cargar plantillas de jugadores.
    $matches_data = [];
    foreach ( $matches as $match ) {
        $home_players = $wpdb->get_results( // phpcs:ignore
            $wpdb->prepare(
                "SELECT p.id, p.first_name, p.last_name, tp.dorsal, tp.is_suspended
                 FROM {$wpdb->prefix}ds_team_players tp
                 JOIN {$wpdb->prefix}ds_players p ON p.id = tp.player_id
                 WHERE tp.team_id = %d ORDER BY tp.dorsal ASC",
                (int) $match['home_team_id']
            ),
            ARRAY_A
        ) ?: [];

        $away_players = $wpdb->get_results( // phpcs:ignore
            $wpdb->prepare(
                "SELECT p.id, p.first_name, p.last_name, tp.dorsal, tp.is_suspended
                 FROM {$wpdb->prefix}ds_team_players tp
                 JOIN {$wpdb->prefix}ds_players p ON p.id = tp.player_id
                 WHERE tp.team_id = %d ORDER BY tp.dorsal ASC",
                (int) $match['away_team_id']
            ),
            ARRAY_A
        ) ?: [];

        $matches_data[] = [
            'match'        => $match,
            'home_players' => $home_players,
            'away_players' => $away_players,
        ];
    }

    $page_title = sprintf( __( 'Carga de Acta — Jornada %d', 'soccertrack' ), $round );
    self::render( 'carga-fecha', compact( 'tournament', 'round', 'matches_data', 'page_title' ) );
}
```

- [ ] **Step 4: Crear templates/panel/carga-fecha.php**

```php
<?php
/**
 * Carga de acta para el coordinador — modo planilla física.
 *
 * Variables: $tournament, $round, $matches_data, $page_title
 * $matches_data: array de {match, home_players, away_players}
 */
defined( 'ABSPATH' ) || exit;
?>

<div class="st-page-header">
    <a href="<?php echo esc_url( home_url( '/panel/torneo/' . $tournament['id'] . '/' ) ); ?>" class="st-back-link">
        ← <?php echo esc_html( $tournament['name'] ); ?>
    </a>
    <h1 class="st-page-title">
        📋 <?php printf( esc_html__( 'Carga de Acta — Jornada %d', 'soccertrack' ), $round ); ?>
    </h1>
</div>

<div class="st-alert" style="background:#fff8e1;border-left:4px solid #f9a825;margin-bottom:20px">
    <?php esc_html_e( 'Ingresa los resultados y eventos de cada partido según la planilla física. Una vez cerrado un partido no se pueden agregar más eventos.', 'soccertrack' ); ?>
</div>

<?php if ( empty( $matches_data ) ) : ?>
    <p class="st-empty-msg"><?php esc_html_e( 'No hay partidos en esta jornada.', 'soccertrack' ); ?></p>
<?php else : ?>

<?php foreach ( $matches_data as $idx => $item ) :
    $match        = $item['match'];
    $home_players = $item['home_players'];
    $away_players = $item['away_players'];
    $match_id     = (int) $match['id'];
    $is_finished  = $match['status'] === 'finished';
    $config_id    = 'ms_config_' . $match_id;

    $map_player = static function ( array $p, int $team_id ): array {
        return [
            'id'           => (int) $p['id'],
            'name'         => $p['first_name'] . ' ' . $p['last_name'],
            'dorsal'       => (int) $p['dorsal'],
            'is_suspended' => (bool) $p['is_suspended'],
            'team_id'      => $team_id,
        ];
    };
?>
<div class="st-card" style="margin-bottom:24px;<?php echo $is_finished ? 'opacity:.8' : ''; ?>">
    <div class="st-card-header">
        <h2 class="st-card-title">
            <?php echo esc_html( $match['home_team_name'] . ' vs ' . $match['away_team_name'] ); ?>
            <?php if ( $is_finished ) : ?>
                <span class="st-badge st-badge--success" style="margin-left:8px">
                    ✅ <?php esc_html_e( 'Cerrado', 'soccertrack' ); ?>
                    — <?php echo esc_html( $match['home_score'] . ' - ' . $match['away_score'] ); ?>
                </span>
            <?php endif; ?>
        </h2>
        <?php if ( ! $is_finished ) : ?>
        <a href="<?php echo esc_url( home_url( '/panel/partido/' . $match_id . '/' ) ); ?>"
           class="st-btn st-btn--primary st-btn--sm">
            ✏️ <?php esc_html_e( 'Abrir planilla completa', 'soccertrack' ); ?>
        </a>
        <?php endif; ?>
    </div>

    <?php if ( ! $is_finished ) : ?>
    <div style="padding:12px 0 0;font-size:.85rem;color:#555">
        <?php if ( $match['venue_name'] ) : ?>
            🏟 <?php echo esc_html( $match['venue_name'] ); ?>
            <?php if ( $match['court_name'] ) : ?>
                — <?php echo esc_html( $match['court_name'] ); ?>
            <?php endif; ?>
        <?php endif; ?>
        <?php if ( $match['match_datetime'] ) : ?>
            · 🕐 <?php echo esc_html( date_i18n( 'd/m/Y H:i', strtotime( $match['match_datetime'] ) ) ); ?>
        <?php endif; ?>
    </div>
    <p style="margin:8px 0 0;font-size:.82rem;color:#888">
        <?php esc_html_e( 'Usa "Abrir planilla completa" para ingresar goles, tarjetas y cerrar el partido.', 'soccertrack' ); ?>
    </p>
    <?php else : ?>
    <?php /* Resumen de eventos del partido cerrado */ ?>
    <?php
    global $wpdb;
    $events = $wpdb->get_results( // phpcs:ignore
        $wpdb->prepare(
            "SELECT e.event_type, e.minute, p.first_name, p.last_name, t.name AS team_name
             FROM {$wpdb->prefix}ds_match_events e
             JOIN {$wpdb->prefix}ds_players p ON p.id = e.player_id
             JOIN {$wpdb->prefix}ds_teams t ON t.id = e.team_id
             WHERE e.match_id = %d ORDER BY e.minute ASC",
            $match_id
        ),
        ARRAY_A
    ) ?: [];
    ?>
    <?php if ( ! empty( $events ) ) : ?>
    <div class="st-table-wrap" style="margin-top:8px">
        <table class="st-table st-table--compact">
            <thead><tr>
                <th><?php esc_html_e( 'Min.', 'soccertrack' ); ?></th>
                <th><?php esc_html_e( 'Tipo', 'soccertrack' ); ?></th>
                <th><?php esc_html_e( 'Jugador', 'soccertrack' ); ?></th>
                <th><?php esc_html_e( 'Equipo', 'soccertrack' ); ?></th>
            </tr></thead>
            <tbody>
            <?php foreach ( $events as $ev ) : ?>
            <tr>
                <td><?php echo esc_html( (string) $ev['minute'] ); ?>'</td>
                <td>
                    <?php
                    $type_icons = [
                        'goal'        => '⚽',
                        'own_goal'    => '⚽🔴',
                        'yellow_card' => '🟨',
                        'red_card'    => '🟥',
                    ];
                    echo esc_html( $type_icons[ $ev['event_type'] ] ?? $ev['event_type'] );
                    ?>
                </td>
                <td><?php echo esc_html( $ev['first_name'] . ' ' . $ev['last_name'] ); ?></td>
                <td><?php echo esc_html( $ev['team_name'] ); ?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php else : ?>
        <p style="font-size:.82rem;color:#888;margin-top:8px"><?php esc_html_e( 'Sin eventos registrados.', 'soccertrack' ); ?></p>
    <?php endif; ?>
    <?php endif; ?>
</div>
<?php endforeach; ?>

<?php endif; ?>
```

- [ ] **Step 5: Registrar rewrite y flushar**

```bash
wp rewrite flush --hard
```

- [ ] **Step 6: Commit**

```bash
git add soccertrack/includes/Public/TournamentPage.php \
        soccertrack/templates/panel/carga-fecha.php
git commit -m "feat: add /panel/carga-fecha/ view for deferred registration mode"
```

---

### Task 5: Adaptar match-sheet.js para modo deferred

**Files:**
- Modify: `soccertrack/templates/panel/partido.php`
- Modify: `soccertrack/assets/js/match-sheet.js`

**Interfaces:**
- Consume: `window.msConfig.registrationMode` ('realtime' | 'deferred')
- Produce: en modo deferred, el botón "Abrir partido" y entrada de eventos se muestran incluso si status es 'scheduled' (no solo 'in_progress')

**Contexto:** El endpoint REST `post_match_event` ya permite entrar eventos en partidos con status 'scheduled' o 'in_progress' (solo bloquea 'finished'). El único cambio necesario es en la UI del JS, que puede tener guardias de estado.

- [ ] **Step 1: Pasar registrationMode al config en partido.php**

En `templates/panel/partido.php`, en el objeto `$ms_config`:

```php
$ms_config = [
    // ... campos existentes ...
    'matchStatus'      => (string) ( $match['status'] ?? 'scheduled' ),
    'registrationMode' => (string) ( $tournament['registration_mode'] ?? 'realtime' ),
    // ...
];
```

Para que `$tournament` esté disponible en partido.php, el método `view_partido()` en TournamentPage.php debe cargarlo y pasarlo. Buscar `view_partido()` y agregar:

```php
$tournament = $wpdb->get_row( // phpcs:ignore
    $wpdb->prepare( "SELECT registration_mode FROM {$wpdb->prefix}ds_tournaments WHERE id = %d", (int) $match['tournament_id'] ),
    ARRAY_A
);
```

Y pasarlo en `self::render()`:

```php
self::render( 'partido', compact( 'match', 'tournament', /* resto... */ ) );
```

- [ ] **Step 2: Ajustar match-sheet.js para no bloquear en deferred+scheduled**

En `assets/js/match-sheet.js`, buscar cualquier condición que revise `matchStatus === 'in_progress'` antes de permitir entrada de eventos. Reemplazar la condición para que en modo deferred no bloquee:

```js
// ANTES (ejemplo genérico — buscar el patrón real):
if ( msConfig.matchStatus !== 'in_progress' ) {
    showError( i18n.match_not_started );
    return;
}

// DESPUÉS:
if ( msConfig.registrationMode !== 'deferred' && msConfig.matchStatus !== 'in_progress' ) {
    showError( i18n.match_not_started );
    return;
}
```

Si `match-sheet.js` no tiene esa guardia (porque el endpoint REST ya es permisivo), este step es no-op y puede marcarse directamente como completado.

- [ ] **Step 3: En modo deferred, permitir cerrar el partido desde 'scheduled'**

En `match-sheet.js`, buscar la lógica que muestra/oculta el botón de cerrar partido (`canClose`). Si hay una guardia que require `in_progress`, eliminarla para modo deferred:

```js
// ANTES:
const showCloseButton = msConfig.canClose && msConfig.matchStatus === 'in_progress';

// DESPUÉS:
const showCloseButton = msConfig.canClose
    && ( msConfig.matchStatus === 'in_progress'
         || msConfig.registrationMode === 'deferred' );
```

- [ ] **Step 4: Prueba manual del flujo deferred**

1. Torneo en modo deferred.
2. Ir a `/panel/torneo/{id}/` → jornada con partidos scheduled.
3. Clic "Cargar acta de esta jornada" → debe abrir `/panel/carga-fecha/`.
4. En la vista de carga de acta, clic "Abrir planilla completa" en un partido.
5. En la planilla del partido (estado: scheduled):
   - Agregar un gol → debe guardarse sin error.
   - Agregar una tarjeta → debe guardarse.
   - Cerrar el partido → debe cambiar a 'finished'.
6. Volver a carga-fecha → el partido aparece cerrado con el resumen de eventos.
7. Verificar tabla de posiciones en el portal público: debe reflejar el resultado.

- [ ] **Step 5: Commit**

```bash
git add soccertrack/templates/panel/partido.php \
        soccertrack/assets/js/match-sheet.js
git commit -m "feat: pass registrationMode to match-sheet.js, allow deferred event entry on scheduled matches"
```

---

### Task 6: Prueba de integración end-to-end

- [ ] **Step 1: Verificar flujo realtime no afectado**

1. Torneo en modo realtime.
2. Ir a `/panel/torneo/{id}/` → no debe aparecer botón "Cargar acta".
3. Planillero asignado a un partido → puede ingresar eventos normalmente.
4. Árbitro cierra el partido → tabla de posiciones se actualiza.

- [ ] **Step 2: Verificar flujo deferred completo**

1. Torneo en modo deferred.
2. Crear fixture y dejar jornada 1 sin jugar.
3. Ir a carga de acta jornada 1.
4. Abrir 2 partidos, ingresar eventos distintos y cerrar.
5. Verificar posiciones en el portal público → deben estar correctas.
6. Verificar goleadores en el portal público → deben aparecer los jugadores con goles.
7. Verificar Tribunal → tarjetas rojas deben aparecer en "Incidentes" si se filtró por esa fecha.

- [ ] **Step 3: Verificar que deferred no aparece en torneos realtime**

Torneo realtime: la vista `/panel/carga-fecha/?tournament_id={id}&round=1` debe devolver error 403 ("solo disponible para torneos en modo planilla física").

- [ ] **Step 4: Commit final**

```bash
git add -p
git commit -m "test: verify dual registration flows integration"
```

---

## Self-Review

**Spec coverage:**

| Requisito | Implementado en |
|-----------|----------------|
| Selección de flujo por torneo | Task 2 (creación) + Task 3 (edición) |
| Flujo 1 realtime sin cambios | Verificado — cero cambios al flujo actual |
| Flujo 2 deferred: coordinador ingresa resultado final | Task 4 `carga-fecha.php` → "Abrir planilla completa" |
| Flujo 2: goles con jugador y minuto | Task 5 — mismo match-sheet.js |
| Flujo 2: tarjetas con jugador y minuto | Task 5 — mismo match-sheet.js |
| Flujo 2: equipo correspondiente | Incluido en eventos (team_id requerido) |
| Flujo 2: carga de fecha completa | Task 4 — vista agrupa todos los partidos de la jornada |
| Estructura de datos común | ✅ `ds_match_events` no cambia |
| Estadísticas y posiciones → ambos modos | ✅ `StandingsCalculator` consume `ds_match_events` sin saber el modo |
| Historial y goleadores → ambos modos | ✅ `PublicEndpoints` no diferencia por modo |

**Nota sobre planillero en modo deferred:** En modo deferred, el coordinador ingresa los datos directamente sin pasar por el planillero. El campo `planillero_user_id` del partido quedará NULL. El endpoint `post_match_event` asigna `created_by` al `planillero_user_id` si existe, o al usuario actual (el coordinador) si no. Esto es correcto: la auditoría registrará el coordinador como quien ingresó cada evento.

**Fuera del scope de este plan:**
- Importación masiva de resultados desde CSV (si se necesita un flujo aún más rápido para muchos torneos)
- Notificación automática al coordinador cuando una fecha está lista para cargar
- Validación de que todos los partidos de una jornada están cerrados antes de avanzar a la siguiente
