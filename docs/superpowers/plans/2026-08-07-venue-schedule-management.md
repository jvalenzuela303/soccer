# Gestión de Horarios, Recintos y Canchas — Plan de Implementación

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Permitir que cada torneo defina su día de semana y horario habitual de partidos (rango 19:00–21:00), usar esos valores al generar el fixture automáticamente, y mantener edición individual de fecha/hora/recinto/cancha ya existente.

**Architecture:** Dos nuevas columnas en `ds_tournaments` (`match_weekday` y `match_time`). El `FixtureGenerator` recibe un array del torneo en lugar de `venue_id` solo, y usa el weekday+time configurado para calcular las fechas. La edición inline de cada partido en `torneo-detalle.php` ya existe y no cambia.

**Tech Stack:** PHP 8.2, WordPress 7.0.2, MariaDB 10.6, dbDelta() para migraciones, `DateTimeImmutable` para cálculos de fecha.

## Global Constraints

- PHP 8.2, WordPress 7.0.2, MariaDB 10.6
- Migraciones siempre vía `dbDelta()` (agregar columna al CREATE TABLE) + fallback en `apply_index_migrations()` para instancias existentes
- Namespace: `SportsLeague\Core` (DatabaseInstaller, FixtureGenerator), `SportsLeague\RestApi` (AdminEndpoints)
- La edición inline de cada partido (fecha, árbitro, cancha, planillero) ya está implementada — no tocar
- `match_weekday`: TINYINT(1) donde 0=domingo, 6=sábado (convención PHP `date('w')`)
- `match_time`: TIME, hora de inicio del primer partido. Cada partido siguiente de la misma jornada se desplaza 1 hora

---

## Mapa de archivos

| Archivo | Acción | Qué cambia |
|---------|--------|-----------|
| `soccertrack/includes/Core/DatabaseInstaller.php` | Modificar | Agregar `match_weekday` y `match_time` al CREATE TABLE de `ds_tournaments` + migración en `apply_index_migrations()` |
| `soccertrack/includes/Core/FixtureGenerator.php` | Modificar | `generate()`, `generate_playoffs()`, `generate_finals()` e `insert_round()` reciben datos del torneo para calcular fechas reales |
| `soccertrack/includes/RestApi/AdminEndpoints.php` | Modificar | `post_generate_fixture()`, `post_generate_playoffs()`, `post_generate_finals()`: leer torneo de BD y pasar array completo al generator |
| `soccertrack/includes/Public/TournamentPage.php` | Modificar | `view_torneos()` → INSERT incluye match_weekday/match_time; `view_torneo()` agrega form de edición de parámetros |
| `soccertrack/templates/panel/torneos.php` | Modificar | Agregar campos de día y hora al formulario de creación de torneo |
| `soccertrack/templates/panel/torneo-detalle.php` | Modificar | Mostrar y permitir editar match_weekday/match_time del torneo |

---

### Task 1: Migración de base de datos

**Files:**
- Modify: `soccertrack/includes/Core/DatabaseInstaller.php`

**Interfaces:**
- Produce: tabla `ds_tournaments` con columnas `match_weekday TINYINT(1) NOT NULL DEFAULT 6` y `match_time TIME NOT NULL DEFAULT '19:00:00'`

- [ ] **Step 1: Agregar columnas al CREATE TABLE de ds_tournaments**

En `includes/Core/DatabaseInstaller.php`, en el bloque `dbDelta()` de `ds_tournaments`, agregar las dos columnas nuevas antes de `created_at`:

```php
// Dentro del CREATE TABLE ds_tournaments, agregar después de bases_pdf_url:
match_weekday TINYINT(1)      NOT NULL DEFAULT 6,
match_time    TIME            NOT NULL DEFAULT '19:00:00',
```

El bloque completo queda:

```php
dbDelta( "CREATE TABLE {$wpdb->prefix}ds_tournaments (
    id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    name          VARCHAR(150)    NOT NULL,
    season        VARCHAR(50)     NOT NULL,
    start_date    DATE            NULL,
    end_date      DATE            NULL,
    format        ENUM('round_robin','round_robin_playoffs','group_stage','knockout') NOT NULL DEFAULT 'round_robin',
    status        ENUM('draft','active','completed')           NOT NULL DEFAULT 'draft',
    bases_pdf_url VARCHAR(255)    NULL,
    match_weekday TINYINT(1)      NOT NULL DEFAULT 6,
    match_time    TIME            NOT NULL DEFAULT '19:00:00',
    created_at    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY   (id),
    KEY idx_status (status)
) ENGINE=InnoDB {$c};" );
```

- [ ] **Step 2: Agregar migración para instancias existentes**

Al final de `apply_index_migrations()`, antes de la llave de cierre, agregar:

```php
// v1.6.0 — ds_tournaments: parámetros de horario del fixture.
$has_weekday = $wpdb->get_var( "SHOW COLUMNS FROM {$prefix}ds_tournaments LIKE 'match_weekday'" ); // phpcs:ignore
if ( ! $has_weekday ) {
    $wpdb->query( // phpcs:ignore
        "ALTER TABLE {$prefix}ds_tournaments
         ADD COLUMN match_weekday TINYINT(1) NOT NULL DEFAULT 6 AFTER bases_pdf_url,
         ADD COLUMN match_time    TIME       NOT NULL DEFAULT '19:00:00' AFTER match_weekday"
    );
}
```

- [ ] **Step 3: Verificar que la migración es idempotente**

En una instalación existente, ejecutar la activación del plugin dos veces (desactivar y reactivar desde el admin de WP). No debe haber errores ni duplicación de columnas.

- [ ] **Step 4: Commit**

```bash
git add soccertrack/includes/Core/DatabaseInstaller.php
git commit -m "feat: add match_weekday and match_time to ds_tournaments"
```

---

### Task 2: Actualizar FixtureGenerator para usar horario real

**Files:**
- Modify: `soccertrack/includes/Core/FixtureGenerator.php`

**Interfaces:**
- `generate(int $tournament_id, array $team_ids, int $venue_id)` → **cambia firma** a `generate(array $tournament, array $team_ids, int $venue_id): array`
- `generate_playoffs(int $tournament_id, int $venue_id)` → `generate_playoffs(array $tournament, int $venue_id): array`
- `generate_finals(int $tournament_id, int $venue_id)` → `generate_finals(array $tournament, int $venue_id): array`
- `insert_round(int $tournament_id, int $round, array $pairs, int $venue_id, int $weekday, string $time): array` (agrega $weekday y $time)
- Produce: fechas calculadas según el día de semana y hora del torneo (no 'next saturday' hardcoded)

- [ ] **Step 1: Agregar método privado de cálculo de fecha**

Al inicio de la clase `FixtureGenerator`, agregar el método:

```php
/**
 * Calcula la fecha del próximo día de la semana dado a partir de hoy.
 *
 * @param int    $weekday  0=domingo … 6=sábado (convención date('w')).
 * @param string $time     Hora en formato 'H:i:s', ej. '19:00:00'.
 * @param int    $hour_offset Horas a sumar (para partidos del mismo día).
 * @param int    $week_offset Semanas a sumar (0=primera disponible).
 */
private function next_match_datetime(
    int    $weekday,
    string $time,
    int    $hour_offset = 0,
    int    $week_offset = 0
): string {
    $day_names = [
        0 => 'sunday', 1 => 'monday', 2 => 'tuesday', 3 => 'wednesday',
        4 => 'thursday', 5 => 'friday', 6 => 'saturday',
    ];

    $day_name = $day_names[ $weekday ] ?? 'saturday';
    $base     = new \DateTimeImmutable( "next {$day_name}" );

    if ( $week_offset > 0 ) {
        $base = $base->modify( "+{$week_offset} weeks" );
    }

    [ $h, $m, $s ] = array_map( 'intval', explode( ':', $time . ':00' ) );
    $base = $base->setTime( $h + $hour_offset, $m, $s );

    return $base->format( 'Y-m-d H:i:s' );
}
```

- [ ] **Step 2: Actualizar la firma de insert_round()**

Cambiar la firma para recibir `$weekday` y `$time`:

```php
private function insert_round(
    int    $tournament_id,
    int    $round,
    array  $pairs,
    int    $venue_id,
    int    $weekday,
    string $time
): array {
    global $wpdb;
    $ids = [];

    foreach ( $pairs as $idx => $pair ) {
        // Fecha basada en el weekday y time del torneo, desplazada por jornada y por slot.
        // Cada jornada (round) ocupa una semana. Cada partido del mismo día: +1 hora.
        $dt = $this->next_match_datetime( $weekday, $time, $idx, $round - 1 );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $wpdb->insert(
            "{$wpdb->prefix}ds_matches",
            [
                'tournament_id'  => $tournament_id,
                'round_number'   => $round,
                'home_team_id'   => $pair['home'],
                'away_team_id'   => $pair['away'],
                'venue_id'       => $venue_id,
                'court_id'       => 0,
                'match_datetime' => $dt,
                'status'         => 'scheduled',
            ],
            [ '%d', '%d', '%d', '%d', '%d', '%d', '%s', '%s' ]
        );

        if ( $wpdb->insert_id ) {
            $ids[] = (int) $wpdb->insert_id;
        }
    }

    return $ids;
}
```

- [ ] **Step 3: Actualizar generate() para aceptar array del torneo**

```php
/**
 * Genera el fixture completo de un torneo Round-Robin.
 *
 * @param  array{id:int,match_weekday:int,match_time:string} $tournament Datos del torneo.
 * @param  int[]  $team_ids IDs de equipos participantes.
 * @param  int    $venue_id ID del recinto.
 * @return int[]  IDs de los partidos creados.
 */
public function generate( array $tournament, array $team_ids, int $venue_id ): array {
    $tournament_id = (int) $tournament['id'];
    $weekday       = (int) ( $tournament['match_weekday'] ?? 6 );
    $time          = (string) ( $tournament['match_time'] ?? '19:00:00' );

    $n     = count( $team_ids );
    $teams = $team_ids;

    if ( $n % 2 !== 0 ) {
        $teams[] = null;
        $n++;
    }

    $rounds    = $n - 1;
    $match_ids = [];

    for ( $round = 1; $round <= $rounds; $round++ ) {
        $pairs = [];

        for ( $i = 0; $i < $n / 2; $i++ ) {
            $home = $teams[ $i ];
            $away = $teams[ $n - 1 - $i ];

            if ( $home === null || $away === null ) {
                continue;
            }

            if ( $round % 2 === 0 ) {
                [ $home, $away ] = [ $away, $home ];
            }

            $pairs[] = [ 'home' => $home, 'away' => $away ];
        }

        $match_ids = array_merge(
            $match_ids,
            $this->insert_round( $tournament_id, $round, $pairs, $venue_id, $weekday, $time )
        );

        $fixed = array_shift( $teams );
        $last  = array_pop( $teams );
        array_unshift( $teams, $last );
        array_unshift( $teams, $fixed );
    }

    $this->assign_courts( $match_ids, $venue_id );

    return $match_ids;
}
```

- [ ] **Step 4: Actualizar generate_playoffs() y generate_finals()**

En `generate_playoffs()`, cambiar la firma a `generate_playoffs( array $tournament, int $venue_id )` y reemplazar el hardcode de fechas:

```php
public function generate_playoffs( array $tournament, int $venue_id ): array {
    // ... validaciones existentes (usar $tournament['id'] en lugar de $tournament_id) ...
    $tournament_id = (int) $tournament['id'];
    $weekday       = (int) ( $tournament['match_weekday'] ?? 6 );
    $time          = (string) ( $tournament['match_time'] ?? '19:00:00' );

    // Reemplazar:
    // $dt_sf1 = ( new \DateTimeImmutable( 'next saturday' ) )->format( 'Y-m-d H:i:s' );
    // $dt_sf2 = ( new \DateTimeImmutable( 'next saturday' ) )->modify( '+2 hours' )->format( 'Y-m-d H:i:s' );

    // Por:
    $dt_sf1 = $this->next_match_datetime( $weekday, $time, 0 );
    $dt_sf2 = $this->next_match_datetime( $weekday, $time, 1 );

    // El resto del método queda igual, usando $tournament_id
}
```

En `generate_finals()`, misma actualización de firma y fechas:

```php
public function generate_finals( array $tournament, int $venue_id ): array {
    $tournament_id = (int) $tournament['id'];
    $weekday       = (int) ( $tournament['match_weekday'] ?? 6 );
    $time          = (string) ( $tournament['match_time'] ?? '19:00:00' );

    // Reemplazar:
    // $dt_3rd   = ( new \DateTimeImmutable( 'next saturday' ) )->format(...);
    // $dt_final = ( new \DateTimeImmutable( 'next saturday' ) )->modify('+2 hours')->format(...);

    // Por:
    $dt_3rd   = $this->next_match_datetime( $weekday, $time, 0 );
    $dt_final = $this->next_match_datetime( $weekday, $time, 1 );

    // El resto igual, usando $tournament_id
}
```

- [ ] **Step 5: Verificar que no quedan referencias a 'next saturday' hardcoded**

```bash
grep -n "next saturday\|next_saturday" soccertrack/includes/Core/FixtureGenerator.php
```

Resultado esperado: **sin output**.

- [ ] **Step 6: Commit**

```bash
git add soccertrack/includes/Core/FixtureGenerator.php
git commit -m "feat: use tournament match_weekday and match_time in fixture generation"
```

---

### Task 3: Actualizar AdminEndpoints para pasar torneo al generator

**Files:**
- Modify: `soccertrack/includes/RestApi/AdminEndpoints.php`

**Interfaces:**
- Consume: `FixtureGenerator::generate(array $tournament, ...)`, `generate_playoffs(array $tournament, ...)`, `generate_finals(array $tournament, ...)`
- Produce: endpoints que leen torneo completo de BD antes de llamar al generator

- [ ] **Step 1: Actualizar post_generate_fixture()**

Buscar el callback `post_generate_fixture` en `AdminEndpoints.php`. Reemplazar la construcción del generator para que lea el torneo primero:

```php
public static function post_generate_fixture( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
    global $wpdb;

    $tournament_id = (int) $request['id'];
    $venue_id      = (int) $request['venue_id'];

    // Leer torneo completo (necesitamos match_weekday y match_time).
    $tournament = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $wpdb->prepare(
            "SELECT id, match_weekday, match_time FROM {$wpdb->prefix}ds_tournaments WHERE id = %d",
            $tournament_id
        ),
        ARRAY_A
    );

    if ( ! $tournament ) {
        return new \WP_Error( 'not_found', __( 'Torneo no encontrado.', 'soccertrack' ), [ 'status' => 404 ] );
    }

    $team_ids = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}ds_teams WHERE tournament_id = %d ORDER BY id ASC",
            $tournament_id
        )
    );

    if ( count( $team_ids ) < 2 ) {
        return new \WP_Error( 'not_enough_teams', __( 'Se necesitan al menos 2 equipos.', 'soccertrack' ), [ 'status' => 422 ] );
    }

    $match_ids = ( new FixtureGenerator() )->generate( $tournament, array_map( 'intval', $team_ids ), $venue_id );

    return rest_ensure_response( [ 'match_ids' => $match_ids, 'total' => count( $match_ids ) ] );
}
```

- [ ] **Step 2: Actualizar post_generate_playoffs()**

Mismo patrón — leer torneo completo antes de llamar al generator:

```php
// Reemplazar la llamada:
// ( new FixtureGenerator() )->generate_playoffs( $tournament_id, $venue_id )
// Por:
$tournament = $wpdb->get_row( /* SELECT id, match_weekday, match_time ... */, ARRAY_A );
( new FixtureGenerator() )->generate_playoffs( $tournament, $venue_id );
```

- [ ] **Step 3: Actualizar post_generate_finals() si existe**

Mismo patrón que playoffs.

- [ ] **Step 4: Verificar que los tres métodos del endpoint compilan**

```bash
wp --path=/var/www/html eval "echo 'OK';" 2>&1
```

Resultado esperado: `OK` (sin errores PHP fatales).

- [ ] **Step 5: Commit**

```bash
git add soccertrack/includes/RestApi/AdminEndpoints.php
git commit -m "feat: pass full tournament data to FixtureGenerator endpoints"
```

---

### Task 4: Formulario de creación de torneo con día/hora

**Files:**
- Modify: `soccertrack/includes/Public/TournamentPage.php` (view_torneos INSERT)
- Modify: `soccertrack/templates/panel/torneos.php`

**Interfaces:**
- Consume: POST fields `match_weekday` (int 0–6) y `match_time` (string H:i)
- Produce: torneo creado con weekday y time correctos

- [ ] **Step 1: Actualizar view_torneos() para persistir los nuevos campos**

En `TournamentPage.php`, método `view_torneos()`, en el bloque de creación (~línea 387):

```php
// ANTES:
$wpdb->insert(
    "{$wpdb->prefix}ds_tournaments",
    [
        'name'       => $name,
        'season'     => sanitize_text_field( $_POST['season'] ?? gmdate( 'Y' ) ),
        'start_date' => sanitize_text_field( $_POST['start_date'] ?? '' ) ?: null,
        'end_date'   => sanitize_text_field( $_POST['end_date'] ?? '' ) ?: null,
        'format'     => sanitize_text_field( $_POST['format'] ?? 'round_robin' ),
        'status'     => 'draft',
    ],
    [ '%s', '%s', '%s', '%s', '%s', '%s' ]
);

// DESPUÉS:
$raw_weekday = absint( $_POST['match_weekday'] ?? 6 );
$match_weekday = ( $raw_weekday >= 0 && $raw_weekday <= 6 ) ? $raw_weekday : 6;
$raw_time    = sanitize_text_field( $_POST['match_time'] ?? '19:00' );
$match_time  = preg_match( '/^\d{1,2}:\d{2}$/', $raw_time ) ? $raw_time . ':00' : '19:00:00';

$wpdb->insert(
    "{$wpdb->prefix}ds_tournaments",
    [
        'name'          => $name,
        'season'        => sanitize_text_field( $_POST['season'] ?? gmdate( 'Y' ) ),
        'start_date'    => sanitize_text_field( $_POST['start_date'] ?? '' ) ?: null,
        'end_date'      => sanitize_text_field( $_POST['end_date'] ?? '' ) ?: null,
        'format'        => sanitize_text_field( $_POST['format'] ?? 'round_robin' ),
        'status'        => 'draft',
        'match_weekday' => $match_weekday,
        'match_time'    => $match_time,
    ],
    [ '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s' ]
);
```

- [ ] **Step 2: Agregar campos al formulario en torneos.php**

En `templates/panel/torneos.php`, dentro del form de creación de torneo, agregar después del campo `format`:

```php
<div class="st-field">
    <label for="st-match-weekday" class="st-label">
        <?php esc_html_e( 'Día de partidos', 'soccertrack' ); ?>
    </label>
    <select id="st-match-weekday" name="match_weekday" class="st-input">
        <option value="1"><?php esc_html_e( 'Lunes', 'soccertrack' ); ?></option>
        <option value="2"><?php esc_html_e( 'Martes', 'soccertrack' ); ?></option>
        <option value="3"><?php esc_html_e( 'Miércoles', 'soccertrack' ); ?></option>
        <option value="4"><?php esc_html_e( 'Jueves', 'soccertrack' ); ?></option>
        <option value="5"><?php esc_html_e( 'Viernes', 'soccertrack' ); ?></option>
        <option value="6" selected><?php esc_html_e( 'Sábado', 'soccertrack' ); ?></option>
        <option value="0"><?php esc_html_e( 'Domingo', 'soccertrack' ); ?></option>
    </select>
</div>

<div class="st-field">
    <label for="st-match-time" class="st-label">
        <?php esc_html_e( 'Hora de inicio (primer partido)', 'soccertrack' ); ?>
    </label>
    <input
        type="time"
        id="st-match-time"
        name="match_time"
        class="st-input"
        value="19:00"
        min="07:00"
        max="23:00"
        style="max-width:120px"
    >
    <span style="font-size:.8rem;color:#888;margin-left:6px">
        <?php esc_html_e( 'Los siguientes partidos del día se asignan +1 hora c/u', 'soccertrack' ); ?>
    </span>
</div>
```

- [ ] **Step 3: Prueba manual**

1. Ir a `/panel/torneos/`.
2. Crear un torneo nuevo, seleccionar "Miércoles" y hora "19:30".
3. Verificar en la BD que el torneo tiene `match_weekday=3` y `match_time='19:30:00'`.

```sql
SELECT id, name, match_weekday, match_time FROM wp_ds_tournaments ORDER BY id DESC LIMIT 1;
```

- [ ] **Step 4: Commit**

```bash
git add soccertrack/includes/Public/TournamentPage.php \
        soccertrack/templates/panel/torneos.php
git commit -m "feat: add match_weekday and match_time to tournament creation form"
```

---

### Task 5: Editar horario del torneo desde torneo-detalle

**Files:**
- Modify: `soccertrack/includes/Public/TournamentPage.php` (view_torneo)
- Modify: `soccertrack/templates/panel/torneo-detalle.php`

**Interfaces:**
- Consume: POST `st_update_schedule`, `match_weekday`, `match_time`
- Produce: torneo actualizado en BD y feedback visual en la vista

- [ ] **Step 1: Manejar POST en view_torneo()**

En `TournamentPage.php`, método `view_torneo()`, después de los bloques POST existentes, agregar:

```php
// ── Actualizar parámetros de horario del torneo ──────────────────
if ( $_SERVER['REQUEST_METHOD'] === 'POST' && isset( $_POST['st_update_schedule'] ) ) {
    check_admin_referer( 'st_update_schedule_' . $id );

    $raw_weekday   = absint( $_POST['match_weekday'] ?? 6 );
    $match_weekday = ( $raw_weekday >= 0 && $raw_weekday <= 6 ) ? $raw_weekday : 6;
    $raw_time      = sanitize_text_field( $_POST['match_time'] ?? '19:00' );
    $match_time    = preg_match( '/^\d{1,2}:\d{2}$/', $raw_time ) ? $raw_time . ':00' : '19:00:00';

    $wpdb->update( // phpcs:ignore
        "{$wpdb->prefix}ds_tournaments",
        [ 'match_weekday' => $match_weekday, 'match_time' => $match_time ],
        [ 'id' => $id ],
        [ '%d', '%s' ],
        [ '%d' ]
    );
    $notice = 'schedule_updated';

    // Refrescar datos del torneo.
    $tournament = $wpdb->get_row(
        $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}ds_tournaments WHERE id = %d", $id ),
        ARRAY_A
    );
}
```

- [ ] **Step 2: Agregar alerta de 'schedule_updated' en la plantilla**

En `templates/panel/torneo-detalle.php`, junto a las otras alertas de `$notice`:

```php
<?php if ( ( $notice ?? '' ) === 'schedule_updated' ) : ?>
    <div class="st-alert st-alert--success">✅ <?php esc_html_e( 'Horario del torneo actualizado.', 'soccertrack' ); ?></div>
<?php endif; ?>
```

- [ ] **Step 3: Agregar tarjeta de configuración de horario en torneo-detalle.php**

En `templates/panel/torneo-detalle.php`, agregar una tarjeta nueva (por ejemplo antes del fixture):

```php
<?php /* ── Configuración de horario del torneo ─────────────────────── */ ?>
<div class="st-card" style="margin-bottom:20px">
    <div class="st-card-header">
        <h2 class="st-card-title">🕖 <?php esc_html_e( 'Horario habitual de partidos', 'soccertrack' ); ?></h2>
    </div>
    <form method="post" action="" class="st-form-inline" style="align-items:flex-end;gap:16px;padding:0 0 4px">
        <?php wp_nonce_field( 'st_update_schedule_' . $tournament['id'] ); ?>
        <input type="hidden" name="st_update_schedule" value="1">

        <?php
        $day_labels = [
            0 => __( 'Domingo', 'soccertrack' ),
            1 => __( 'Lunes', 'soccertrack' ),
            2 => __( 'Martes', 'soccertrack' ),
            3 => __( 'Miércoles', 'soccertrack' ),
            4 => __( 'Jueves', 'soccertrack' ),
            5 => __( 'Viernes', 'soccertrack' ),
            6 => __( 'Sábado', 'soccertrack' ),
        ];
        ?>

        <div class="st-field">
            <label class="st-label"><?php esc_html_e( 'Día', 'soccertrack' ); ?></label>
            <select name="match_weekday" class="st-input">
                <?php foreach ( $day_labels as $val => $label ) : ?>
                    <option value="<?php echo esc_attr( (string) $val ); ?>" <?php selected( (int) ( $tournament['match_weekday'] ?? 6 ), $val ); ?>>
                        <?php echo esc_html( $label ); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="st-field">
            <label class="st-label"><?php esc_html_e( 'Hora inicio', 'soccertrack' ); ?></label>
            <input
                type="time"
                name="match_time"
                class="st-input"
                value="<?php echo esc_attr( substr( (string) ( $tournament['match_time'] ?? '19:00:00' ), 0, 5 ) ); ?>"
                style="max-width:120px"
            >
        </div>

        <div class="st-field" style="align-self:flex-end">
            <button type="submit" class="st-btn st-btn--secondary st-btn--sm">
                💾 <?php esc_html_e( 'Guardar', 'soccertrack' ); ?>
            </button>
        </div>
    </form>
    <p style="margin:8px 0 0;font-size:.8rem;color:#888">
        <?php esc_html_e( 'Este horario se usa al generar el fixture. Los partidos ya generados conservan su fecha individual.', 'soccertrack' ); ?>
    </p>
</div>
```

- [ ] **Step 4: Prueba manual**

1. Abrir un torneo existente en `/panel/torneo/{id}/`.
2. Cambiar el día a "Jueves" y la hora a "20:00".
3. Guardar → debe mostrar alerta de éxito.
4. Recargar la página → los selects deben mostrar los valores guardados.

- [ ] **Step 5: Commit**

```bash
git add soccertrack/includes/Public/TournamentPage.php \
        soccertrack/templates/panel/torneo-detalle.php
git commit -m "feat: add schedule editing (weekday+time) to tournament detail view"
```

---

### Task 6: Prueba de integración — generar fixture con horario

- [ ] **Step 1: Crear torneo con día "Miércoles 20:00"**

1. `/panel/torneos/` → crear torneo con match_weekday=3, match_time=20:00.
2. Importar al menos 4 equipos.

- [ ] **Step 2: Generar fixture**

En `/panel/torneo/{id}/`, seleccionar recinto y clic "Generar fixture".

- [ ] **Step 3: Verificar fechas en BD**

```sql
SELECT round_number, match_datetime, DAYOFWEEK(match_datetime) AS dow
FROM wp_ds_matches
WHERE tournament_id = {id}
ORDER BY round_number, match_datetime;
```

`DAYOFWEEK` devuelve 1=domingo, 4=miércoles, 7=sábado.  
Resultado esperado: todos los partidos con `dow = 4` (miércoles) y hora 20:00, 21:00 según slot.

- [ ] **Step 4: Commit si hay ajustes**

```bash
git add -p
git commit -m "fix: schedule integration adjustments"
```

---

## Self-Review

**Spec coverage:**
- ✅ Horario 19:00–21:00 → match_time configurable, partidos del mismo día +1h por slot
- ✅ Asignación automática en generación de fixture → FixtureGenerator usa weekday+time
- ✅ Editable posteriormente → formulario en torneo-detalle.php (los partidos existentes conservan su datetime individual, que ya es editable inline)
- ✅ Recintos y canchas → ya gestionados; assign_courts() no cambia
- ✅ Orden de partidos → round_number y match_datetime ya existentes, ya editables

**Nota sobre 'next weekday':** Si hoy es el mismo día configurado, `DateTimeImmutable('next wednesday')` devuelve la semana siguiente. Para la primera jornada esto es correcto (el coordinador genera el fixture antes del inicio). Si se quiere "este mismo miércoles si no ha pasado", se puede agregar lógica de fallback — pero queda fuera del scope actual.
