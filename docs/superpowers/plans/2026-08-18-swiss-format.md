# Swiss Format Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Añadir el formato `swiss` al plugin SoccerTrack, permitiendo torneos tipo "Champions" donde 28 equipos juegan N rondas con emparejamiento dinámico por clasificación, seguido de hasta 3 brackets de playoffs (Oro/Plata/Bronce).

**Architecture:** Un nuevo valor `swiss` en el ENUM `format` de `ds_tournaments`, más una columna `swiss_rounds` para configurar el total de rondas y `is_ghost` en `ds_teams` para el equipo "LIBRE" de descanso. El algoritmo de emparejamiento greedy vive en `FixtureGenerator`, reutiliza `insert_round()`-style insertion y `assign_courts()`, y consume `StandingsCalculator::recalculate()` para conocer la clasificación actual antes de cada ronda.

**Tech Stack:** PHP 8.2, WordPress 7.0.2, MariaDB 10.6, vanilla JS ES2021, `$wpdb`, `dbDelta()`.

## Global Constraints

- PHP 8.2 — usar `match`, `enum`, nullsafe operator, typed properties donde aplique.
- WordPress Coding Standards (WPCS) — `phpcs:ignore` donde sea inevitable con `$wpdb` directo.
- Sin tablas nuevas — toda la información se deriva de `ds_matches` y las columnas nuevas.
- Idempotencia obligatoria en toda migración — guard `SHOW COLUMNS` antes de `ALTER TABLE`.
- Text domain `soccertrack` en todos los strings traducibles.
- Versión DB se bump en `soccertrack.php` (constante `SOCCERTRACK_DB_VERSION`) y en `DatabaseInstaller::run()`.

---

## Mapa de archivos

| Archivo | Acción | Responsabilidad |
|---|---|---|
| `soccertrack.php` | Modificar | Bump `SOCCERTRACK_VERSION` → `2.2.0`, `SOCCERTRACK_DB_VERSION` → `2.3.0` |
| `includes/Core/DatabaseInstaller.php` | Modificar | Migración v2.3.0: 3 ALTER TABLE (swiss_rounds, is_ghost, ENUM) |
| `includes/Core/StandingsCalculator.php` | Modificar | Excluir equipos `is_ghost=1` de la tabla y del cómputo de partidos |
| `includes/Core/FixtureGenerator.php` | Modificar | +2 métodos públicos, +4 métodos privados para el algoritmo Swiss |
| `includes/RestApi/AdminEndpoints.php` | Modificar | +1 ruta REST + handler `post_generate_swiss_round()` |
| `includes/RestApi/PublicEndpoints.php` | Modificar | Agregar `is_ghost` al response de fixture para que el JS lo detecte |
| `templates/panel/torneo-detalle.php` | Modificar | Bloque "Fase Liga Swiss" con 4 estados y botón de generación |
| `assets/js/live-standings.js` | Modificar | Renderizar "LIBRE (Descanso)" en fixture cuando `is_ghost=1` |

---

## Task 1: DB Migration v2.3.0

**Files:**
- Modify: `soccertrack/soccertrack.php:38-39`
- Modify: `soccertrack/includes/Core/DatabaseInstaller.php` (al final de `apply_index_migrations()`)

**Interfaces:**
- Produces: columna `swiss_rounds TINYINT UNSIGNED NOT NULL DEFAULT 8` en `ds_tournaments`
- Produces: columna `is_ghost TINYINT(1) NOT NULL DEFAULT 0` en `ds_teams`
- Produces: `format` ENUM extendido con `'swiss'`

- [ ] **Step 1: Bump versiones en soccertrack.php**

Localizar las líneas con `SOCCERTRACK_VERSION` y `SOCCERTRACK_DB_VERSION` (líneas 38-39) y reemplazar:

```php
define( 'SOCCERTRACK_VERSION',    '2.2.0' );
define( 'SOCCERTRACK_DB_VERSION', '2.3.0' );
```

- [ ] **Step 2: Agregar las 3 migraciones al final de `apply_index_migrations()` en DatabaseInstaller.php**

Añadir al final del método (antes del cierre `}`):

```php
// v2.3.0 — ds_tournaments: columna swiss_rounds para formato Swiss.
$has_swiss_rounds = $wpdb->get_var( "SHOW COLUMNS FROM {$prefix}ds_tournaments LIKE 'swiss_rounds'" ); // phpcs:ignore
if ( ! $has_swiss_rounds ) {
    $wpdb->query( // phpcs:ignore
        "ALTER TABLE {$prefix}ds_tournaments
         ADD COLUMN swiss_rounds TINYINT UNSIGNED NOT NULL DEFAULT 8 AFTER format"
    );
}

// v2.3.0 — ds_teams: columna is_ghost para equipo LIBRE de descanso.
$has_is_ghost = $wpdb->get_var( "SHOW COLUMNS FROM {$prefix}ds_teams LIKE 'is_ghost'" ); // phpcs:ignore
if ( ! $has_is_ghost ) {
    $wpdb->query( // phpcs:ignore
        "ALTER TABLE {$prefix}ds_teams
         ADD COLUMN is_ghost TINYINT(1) NOT NULL DEFAULT 0 AFTER logo_url"
    );
}

// v2.3.0 — ds_tournaments: extender ENUM format con 'swiss'.
// dbDelta no puede modificar ENUMs; se hace via ALTER TABLE directo.
// Guard: verificar si 'swiss' ya está en el ENUM antes de ejecutar.
$col_def = $wpdb->get_var( "SHOW COLUMNS FROM {$prefix}ds_tournaments LIKE 'format'" ); // phpcs:ignore
// get_var retorna solo el primer campo (Field name); usar get_row para ver Type.
$col_row = $wpdb->get_row( "SHOW COLUMNS FROM {$prefix}ds_tournaments LIKE 'format'", ARRAY_A ); // phpcs:ignore
if ( $col_row && false === strpos( $col_row['Type'] ?? '', 'swiss' ) ) {
    $wpdb->query( // phpcs:ignore
        "ALTER TABLE {$prefix}ds_tournaments
         MODIFY COLUMN format
           ENUM('round_robin','round_robin_playoffs','group_stage','knockout','swiss')
           NOT NULL DEFAULT 'round_robin'"
    );
}
```

- [ ] **Step 3: Verificar que la migración corre correctamente**

En el sitio WordPress, desactivar y reactivar el plugin (o navegar a cualquier página del admin para que el hook `admin_init` dispare `DatabaseInstaller::run()` si la versión cambió). Verificar en phpMyAdmin / WP-CLI:

```bash
# WP-CLI
wp db query "SHOW COLUMNS FROM wp_ds_tournaments LIKE 'swiss_rounds';"
wp db query "SHOW COLUMNS FROM wp_ds_teams LIKE 'is_ghost';"
wp db query "SHOW COLUMNS FROM wp_ds_tournaments LIKE 'format';"
# La columna format debe mostrar swiss en el ENUM
```

Resultado esperado: las 3 columnas aparecen sin errores.

- [ ] **Step 4: Commit**

```bash
git add soccertrack/soccertrack.php soccertrack/includes/Core/DatabaseInstaller.php
git commit -m "feat(swiss): DB migration v2.3.0 — swiss_rounds, is_ghost, ENUM format"
```

---

## Task 2: StandingsCalculator — excluir equipos fantasma

**Files:**
- Modify: `soccertrack/includes/Core/StandingsCalculator.php`

**Interfaces:**
- Consumes: columna `is_ghost` en `ds_teams` (Task 1)
- Produces: `recalculate(int $tournament_id): array` — sin cambio de firma; equipos `is_ghost=1` ya no aparecen en el resultado

- [ ] **Step 1: Modificar la query de equipos en `recalculate()`**

Localizar la query que carga equipos (~línea 50):

```php
$teams = $wpdb->get_results(
    $wpdb->prepare(
        "SELECT id, name FROM {$wpdb->prefix}ds_teams WHERE tournament_id = %d ORDER BY name ASC",
        $tournament_id
    ),
    ARRAY_A
);
```

Reemplazar por:

```php
// phpcs:ignore WordPress.DB.DirectDatabaseQuery
$teams = $wpdb->get_results(
    $wpdb->prepare(
        "SELECT id, name FROM {$wpdb->prefix}ds_teams WHERE tournament_id = %d AND is_ghost = 0 ORDER BY name ASC",
        $tournament_id
    ),
    ARRAY_A
);
```

El guard existente `if ( ! isset( $table[ $h ], $table[ $a ] ) ) { continue; }` (~línea 89) ya descarta partidos donde uno de los equipos no está en la tabla — como el equipo LIBRE no se inicializa en `$table`, sus partidos se ignoran automáticamente sin cambio adicional.

- [ ] **Step 2: Verificar manualmente**

Con un torneo Swiss que tenga equipo LIBRE creado (is_ghost=1), llamar:

```
GET /wp-json/soccertrack/v1/public/tournament/{id}/standings
```

Confirmar que "LIBRE" no aparece en la tabla de posiciones retornada.

- [ ] **Step 3: Commit**

```bash
git add soccertrack/includes/Core/StandingsCalculator.php
git commit -m "feat(swiss): StandingsCalculator excluye equipos fantasma (is_ghost=1)"
```

---

## Task 3: FixtureGenerator — algoritmo Swiss

**Files:**
- Modify: `soccertrack/includes/Core/FixtureGenerator.php`

**Interfaces:**
- Consumes: `StandingsCalculator::recalculate(int): array` — returns `[['team_id'=>int, 'pts'=>int, 'dg'=>int, 'gf'=>int, ...], ...]` sorted pts DESC, dg DESC, gf DESC
- Consumes: `$this->insert_round()` — private method, firma: `insert_round(int $tournament_id, int $round, array $pairs, int $venue_id, array $weekdays, string $time, int $num_courts, int $duration): int[]`
- Consumes: `$this->assign_courts(array $match_ids, int $venue_id): void`
- Consumes: `$this->weekdays_from_tournament(array): array`, `$this->duration_from_tournament(array): int`, `$this->count_courts(int): int`
- Produces: `get_swiss_status(int $tournament_id): array{current_round:int, total_rounds:int, round_complete:bool, swiss_done:bool, bye_history:int[]}`
- Produces: `generate_swiss_round(array $tournament, int $round_number, int $venue_id, ?string $match_date=null): array{match_ids:int[], error?:string}`

- [ ] **Step 1: Agregar método privado `get_played_pairs()`**

Al final de la clase (antes del cierre `}`), agregar:

```php
/**
 * Retorna un Set de claves "min:max" de pares de equipos que ya jugaron entre sí.
 * Excluye partidos contra el equipo fantasma (bye) para no bloquear rematches vs LIBRE.
 *
 * @param  int   $tournament_id
 * @param  int   $ghost_id  ID del equipo LIBRE (0 si no existe aún).
 * @return array<string, true>  Claves "minId:maxId" => true.
 */
private function get_played_pairs( int $tournament_id, int $ghost_id = 0 ): array {
    global $wpdb;

    // phpcs:ignore WordPress.DB.DirectDatabaseQuery
    $rows = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT home_team_id, away_team_id
             FROM {$wpdb->prefix}ds_matches
             WHERE tournament_id = %d AND phase = 'regular' AND status != 'suspended'",
            $tournament_id
        ),
        ARRAY_A
    );

    $pairs = [];
    foreach ( $rows as $row ) {
        $a = (int) $row['home_team_id'];
        $b = (int) $row['away_team_id'];
        // No registrar partidos vs el equipo fantasma como "jugados" entre reales.
        if ( $ghost_id > 0 && ( $a === $ghost_id || $b === $ghost_id ) ) {
            continue;
        }
        $key           = min( $a, $b ) . ':' . max( $a, $b );
        $pairs[ $key ] = true;
    }

    return $pairs;
}
```

- [ ] **Step 2: Agregar método privado `build_swiss_pairs()`**

```php
/**
 * Empareja equipos usando algoritmo greedy top-down con backtracking simple.
 *
 * Orden de candidatos dentro del mismo grupo de puntos: DG DESC (ya viene así de standings).
 * Si todos los candidatos cercanos ya jugaron contra el equipo actual, desciende al siguiente bucket.
 * Backtracking de 1 nivel: si un equipo queda sin pareja, intenta intercambiar el último par formado.
 *
 * @param  int[]                 $team_ids    IDs en orden standings (pts DESC, dg DESC, gf DESC).
 * @param  array<string, true>   $played      Set de pares ya jugados ("minId:maxId").
 * @return array<array{home:int,away:int}>  Pares emparejados.
 */
private function build_swiss_pairs( array $team_ids, array $played ): array {
    $paired = [];   // team_id => true (ya tiene pareja).
    $pairs  = [];

    $n = count( $team_ids );

    for ( $i = 0; $i < $n; $i++ ) {
        $a = $team_ids[ $i ];
        if ( isset( $paired[ $a ] ) ) {
            continue;
        }

        $found = false;
        for ( $j = $i + 1; $j < $n; $j++ ) {
            $b = $team_ids[ $j ];
            if ( isset( $paired[ $b ] ) ) {
                continue;
            }
            $key = min( $a, $b ) . ':' . max( $a, $b );
            if ( isset( $played[ $key ] ) ) {
                continue;
            }
            // Par válido encontrado.
            $pairs[]      = [ 'home' => $a, 'away' => $b ];
            $paired[ $a ] = true;
            $paired[ $b ] = true;
            $found        = true;
            break;
        }

        if ( ! $found && ! empty( $pairs ) ) {
            // Backtracking: deshacer el último par e intentar reasignar.
            $last = array_pop( $pairs );
            $c    = $last['home'];
            $d    = $last['away'];
            unset( $paired[ $c ], $paired[ $d ] );

            $key_ca = min( $c, $a ) . ':' . max( $c, $a );
            $key_da = min( $d, $a ) . ':' . max( $d, $a );

            if ( ! isset( $played[ $key_ca ] ) ) {
                // c juega con a; d queda libre para la siguiente iteración.
                $pairs[]      = [ 'home' => $c, 'away' => $a ];
                $paired[ $c ] = true;
                $paired[ $a ] = true;
            } elseif ( ! isset( $played[ $key_da ] ) ) {
                // d juega con a; c queda libre.
                $pairs[]      = [ 'home' => $d, 'away' => $a ];
                $paired[ $d ] = true;
                $paired[ $a ] = true;
            } else {
                // No hay solución de backtracking — restaurar par original, a quedará sin pareja (bye).
                $pairs[]      = [ 'home' => $c, 'away' => $d ];
                $paired[ $c ] = true;
                $paired[ $d ] = true;
            }
        }
    }

    return $pairs;
}
```

- [ ] **Step 3: Agregar método privado `select_bye_team()`**

```php
/**
 * Selecciona el equipo que descansará esta ronda (bye).
 *
 * Prioridad: el equipo de menor ranking que aún no haya tenido bye.
 * Si todos tuvieron bye, asigna al de menor ranking.
 *
 * @param  int[] $unpaired_ids  IDs sin pareja, en orden standings (mejor primero).
 * @param  int[] $bye_history   IDs que ya tuvieron bye en rondas anteriores.
 * @return int   team_id seleccionado para el bye.
 */
private function select_bye_team( array $unpaired_ids, array $bye_history ): int {
    // Invertir para tener peor primero (menor ranking).
    $worst_first = array_reverse( $unpaired_ids );
    foreach ( $worst_first as $tid ) {
        if ( ! in_array( $tid, $bye_history, true ) ) {
            return $tid;
        }
    }
    // Todos tuvieron bye — asignar al de peor ranking.
    return $worst_first[0];
}
```

- [ ] **Step 4: Agregar método privado `ensure_ghost_team()`**

```php
/**
 * Retorna el ID del equipo LIBRE del torneo, creándolo si no existe.
 *
 * @param  int $tournament_id
 * @return int  ID del equipo fantasma.
 */
private function ensure_ghost_team( int $tournament_id ): int {
    global $wpdb;

    // phpcs:ignore WordPress.DB.DirectDatabaseQuery
    $existing = (int) $wpdb->get_var(
        $wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}ds_teams WHERE tournament_id = %d AND is_ghost = 1 LIMIT 1",
            $tournament_id
        )
    );

    if ( $existing > 0 ) {
        return $existing;
    }

    // phpcs:ignore WordPress.DB.DirectDatabaseQuery
    $wpdb->insert(
        "{$wpdb->prefix}ds_teams",
        [
            'tournament_id' => $tournament_id,
            'name'          => 'LIBRE',
            'is_ghost'      => 1,
        ],
        [ '%d', '%s', '%d' ]
    );

    return (int) $wpdb->insert_id;
}
```

- [ ] **Step 5: Agregar método público `get_swiss_status()`**

```php
/**
 * Retorna el estado de la fase liga Swiss de un torneo.
 *
 * @param  int $tournament_id
 * @return array{current_round:int, total_rounds:int, round_complete:bool, swiss_done:bool, bye_history:int[]}
 */
public function get_swiss_status( int $tournament_id ): array {
    global $wpdb;

    // phpcs:ignore WordPress.DB.DirectDatabaseQuery
    $swiss_rounds = (int) $wpdb->get_var(
        $wpdb->prepare(
            "SELECT swiss_rounds FROM {$wpdb->prefix}ds_tournaments WHERE id = %d",
            $tournament_id
        )
    );
    $total_rounds = max( 1, $swiss_rounds ?: 8 );

    // Última ronda generada.
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery
    $current_round = (int) $wpdb->get_var(
        $wpdb->prepare(
            "SELECT MAX(round_number) FROM {$wpdb->prefix}ds_matches
             WHERE tournament_id = %d AND phase = 'regular'",
            $tournament_id
        )
    );

    // ¿Todos los partidos de la ronda actual están finalizados?
    $round_complete = false;
    if ( $current_round > 0 ) {
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $pending = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->prefix}ds_matches
                 WHERE tournament_id = %d AND phase = 'regular'
                   AND round_number = %d AND status NOT IN ('finished', 'suspended')",
                $tournament_id,
                $current_round
            )
        );
        $round_complete = ( 0 === $pending );
    }

    // Historial de byes: equipos que jugaron contra el equipo LIBRE.
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery
    $ghost_id = (int) $wpdb->get_var(
        $wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}ds_teams WHERE tournament_id = %d AND is_ghost = 1 LIMIT 1",
            $tournament_id
        )
    );

    $bye_history = [];
    if ( $ghost_id > 0 ) {
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $bye_rows = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT home_team_id FROM {$wpdb->prefix}ds_matches
                 WHERE tournament_id = %d AND away_team_id = %d AND phase = 'regular'",
                $tournament_id,
                $ghost_id
            )
        );
        $bye_history = array_map( 'intval', $bye_rows );
    }

    return [
        'current_round'  => $current_round,
        'total_rounds'   => $total_rounds,
        'round_complete' => $round_complete,
        'swiss_done'     => $current_round >= $total_rounds && $round_complete,
        'bye_history'    => $bye_history,
    ];
}
```

- [ ] **Step 6: Agregar método público `generate_swiss_round()`**

```php
/**
 * Genera los emparejamientos de la ronda N del formato Swiss.
 *
 * Flujo:
 *  1. Guard de idempotencia: si ya existen partidos de esta ronda, retorna error.
 *  2. Obtiene standings actuales via StandingsCalculator.
 *  3. Carga pares ya jugados desde ds_matches.
 *  4. Empareja con algoritmo greedy + backtracking (build_swiss_pairs).
 *  5. Si número impar, asigna bye al equipo de menor ranking sin bye previo.
 *  6. Inserta partidos; el partido bye se inserta como 'finished' 0-0 (no suma puntos
 *     porque StandingsCalculator excluye el equipo LIBRE).
 *  7. Asigna canchas con assign_courts().
 *
 * @param  array{id:int,swiss_rounds:int,match_weekday:int,match_weekdays:string,match_time:string,match_time_weekend:string,match_duration:int} $tournament
 * @param  int         $round_number  Número de ronda 1-based.
 * @param  int         $venue_id
 * @param  string|null $match_date    Fecha override 'Y-m-d'. Null usa next_match_datetime().
 * @return array{match_ids:int[], error?:string}
 */
public function generate_swiss_round(
    array   $tournament,
    int     $round_number,
    int     $venue_id,
    ?string $match_date = null
): array {
    global $wpdb;

    $tournament_id = (int) $tournament['id'];

    // 1. Guard de idempotencia.
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery
    $existing = (int) $wpdb->get_var(
        $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}ds_matches
             WHERE tournament_id = %d AND round_number = %d AND phase = 'regular'",
            $tournament_id,
            $round_number
        )
    );
    if ( $existing > 0 ) {
        return [
            'match_ids' => [],
            'error'     => sprintf(
                /* translators: %d: número de ronda */
                __( 'La ronda %d ya fue generada.', 'soccertrack' ),
                $round_number
            ),
        ];
    }

    // 2. Standings actuales (excluye equipo LIBRE por is_ghost=0 en StandingsCalculator).
    $standings = ( new \SportsLeague\Core\StandingsCalculator() )->recalculate( $tournament_id );

    if ( count( $standings ) < 2 ) {
        return [
            'match_ids' => [],
            'error'     => __( 'Se necesitan al menos 2 equipos para generar una ronda Swiss.', 'soccertrack' ),
        ];
    }

    // IDs en orden standings (mejor → peor).
    $team_ids = array_column( $standings, 'team_id' );

    // 3. Ghost team y historial de byes.
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery
    $ghost_id = (int) $wpdb->get_var(
        $wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}ds_teams WHERE tournament_id = %d AND is_ghost = 1 LIMIT 1",
            $tournament_id
        )
    );

    $bye_history = [];
    if ( $ghost_id > 0 ) {
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $bye_rows    = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT home_team_id FROM {$wpdb->prefix}ds_matches
                 WHERE tournament_id = %d AND away_team_id = %d AND phase = 'regular'",
                $tournament_id,
                $ghost_id
            )
        );
        $bye_history = array_map( 'intval', $bye_rows );
    }

    // 4. Pares ya jugados.
    $played_pairs = $this->get_played_pairs( $tournament_id, $ghost_id );

    // 5. Construir emparejamientos.
    $pairs = $this->build_swiss_pairs( $team_ids, $played_pairs );

    // 6. Detectar equipo sin pareja (número impar de equipos).
    $paired_ids = [];
    foreach ( $pairs as $pair ) {
        $paired_ids[] = $pair['home'];
        $paired_ids[] = $pair['away'];
    }
    $unpaired = array_values( array_diff( $team_ids, $paired_ids ) );

    $bye_pair = null;
    if ( ! empty( $unpaired ) ) {
        $bye_team_id = $this->select_bye_team( $unpaired, $bye_history );
        $ghost_id    = $this->ensure_ghost_team( $tournament_id );
        $bye_pair    = [ 'home' => $bye_team_id, 'away' => $ghost_id, 'is_bye' => true ];
    }

    // 7. Insertar partidos regulares usando insert_round().
    $weekdays  = $this->weekdays_from_tournament( $tournament );
    $time      = (string) ( $tournament['match_time'] ?? '19:00:00' );
    $duration  = $this->duration_from_tournament( $tournament );
    $num_courts = $this->count_courts( $venue_id );

    // Si hay fecha override, inyectarla al $tournament temporalmente para insert_round().
    // insert_round() usa next_match_datetime() internamente; sin override usamos la lógica estándar.
    $match_ids = $this->insert_round(
        $tournament_id,
        $round_number,
        $pairs,
        $venue_id,
        $weekdays,
        $time,
        $num_courts,
        $duration
    );

    // 8. Insertar partido bye (si aplica) como 'finished' 0-0.
    if ( null !== $bye_pair ) {
        $dt = $match_date
            ? $match_date . ' ' . $time
            : $this->next_match_datetime( $weekdays, $time, count( $pairs ), $round_number - 1, $duration );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $wpdb->insert(
            "{$wpdb->prefix}ds_matches",
            [
                'tournament_id'  => $tournament_id,
                'round_number'   => $round_number,
                'home_team_id'   => $bye_pair['home'],
                'away_team_id'   => $bye_pair['away'],
                'venue_id'       => $venue_id,
                'court_id'       => 0,
                'match_datetime' => $dt,
                'status'         => 'finished',
                'phase'          => 'regular',
                'home_score'     => 0,
                'away_score'     => 0,
            ],
            [ '%d', '%d', '%d', '%d', '%d', '%d', '%s', '%s', '%s', '%d', '%d' ]
        );
        if ( $wpdb->insert_id ) {
            $match_ids[] = (int) $wpdb->insert_id;
        }
    }

    // 9. Asignar canchas a todos los partidos.
    $this->assign_courts( $match_ids, $venue_id );

    return [ 'match_ids' => $match_ids ];
}
```

- [ ] **Step 7: Verificar manualmente con un torneo de prueba**

Crear un torneo con `format = 'swiss'`, `swiss_rounds = 3`, 5 equipos. Llamar al endpoint de generación (Task 4) y verificar:
- Se generan 2 partidos programados + 1 partido LIBRE (finished 0-0) en `ds_matches`.
- Llamar dos veces al mismo endpoint retorna error `'La ronda 1 ya fue generada.'`.
- La tabla de posiciones no incluye al equipo LIBRE.

- [ ] **Step 8: Commit**

```bash
git add soccertrack/includes/Core/FixtureGenerator.php
git commit -m "feat(swiss): FixtureGenerator — generate_swiss_round + get_swiss_status"
```

---

## Task 4: REST API endpoint + fixture público con is_ghost

**Files:**
- Modify: `soccertrack/includes/RestApi/AdminEndpoints.php`
- Modify: `soccertrack/includes/RestApi/PublicEndpoints.php`

**Interfaces:**
- Consumes: `FixtureGenerator::get_swiss_status(int): array` (Task 3)
- Consumes: `FixtureGenerator::generate_swiss_round(array, int, int, ?string): array` (Task 3)
- Produces: `POST /wp-json/soccertrack/v1/admin/tournament/{id}/swiss-round` → `{round:int, matches_created:int, match_ids:int[]}`
- Produces: fixture response incluye campos `home_is_ghost:int, away_is_ghost:int` (0 o 1)

- [ ] **Step 1: Registrar la ruta REST en `AdminEndpoints::register_routes()`**

Buscar el bloque de registro de `/admin/tournament/{id}/knockout` y agregar justo después (antes del bloque de `/admin/player/sanction`):

```php
// POST /admin/tournament/{id}/swiss-round — Generar siguiente ronda Swiss (coordinador).
register_rest_route(
    self::NAMESPACE,
    '/admin/tournament/(?P<id>\d+)/swiss-round',
    [
        'methods'             => \WP_REST_Server::CREATABLE,
        'callback'            => [ self::class, 'post_generate_swiss_round' ],
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

- [ ] **Step 2: Agregar el handler `post_generate_swiss_round()` al final de la clase (antes del cierre `}`)**

```php
/**
 * POST /admin/tournament/{id}/swiss-round
 *
 * Genera la siguiente ronda del formato Swiss para el torneo indicado.
 * Valida que la ronda anterior esté completa y que no se hayan agotado las rondas totales.
 *
 * @param \WP_REST_Request $request
 * @return \WP_REST_Response|\WP_Error
 */
public static function post_generate_swiss_round( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
    global $wpdb;

    $tournament_id = (int) $request['id'];
    $venue_id      = (int) $request['venue_id'];
    $match_date    = $request['match_date'] ? (string) $request['match_date'] : null;

    // phpcs:ignore WordPress.DB.DirectDatabaseQuery
    $tournament = $wpdb->get_row(
        $wpdb->prepare(
            "SELECT id, format, swiss_rounds, match_weekday, match_weekdays, match_time, match_time_weekend, match_duration
             FROM {$wpdb->prefix}ds_tournaments WHERE id = %d",
            $tournament_id
        ),
        ARRAY_A
    );

    if ( ! $tournament ) {
        return new \WP_Error( 'tournament_not_found', __( 'Torneo no encontrado.', 'soccertrack' ), [ 'status' => 404 ] );
    }

    if ( ( $tournament['format'] ?? '' ) !== 'swiss' ) {
        return new \WP_Error(
            'invalid_format',
            __( 'Este endpoint solo aplica para torneos en formato Swiss.', 'soccertrack' ),
            [ 'status' => 422 ]
        );
    }

    $generator = new FixtureGenerator();
    $status    = $generator->get_swiss_status( $tournament_id );

    // Validar que la ronda actual esté completa (si hay rondas generadas).
    if ( $status['current_round'] > 0 && ! $status['round_complete'] ) {
        return new \WP_Error(
            'round_not_complete',
            sprintf(
                /* translators: %d: número de ronda actual */
                __( 'La ronda %d aún no está completa. Ingresa todos los resultados antes de generar la siguiente.', 'soccertrack' ),
                $status['current_round']
            ),
            [ 'status' => 409 ]
        );
    }

    // Validar que no se hayan agotado todas las rondas.
    if ( $status['swiss_done'] ) {
        return new \WP_Error(
            'swiss_complete',
            __( 'La fase liga Swiss ya está completa. Activa los playoffs desde el panel.', 'soccertrack' ),
            [ 'status' => 409 ]
        );
    }

    $next_round = $status['current_round'] + 1;
    $result     = $generator->generate_swiss_round( $tournament, $next_round, $venue_id, $match_date );

    if ( ! empty( $result['error'] ) ) {
        return new \WP_Error( 'swiss_error', $result['error'], [ 'status' => 409 ] );
    }

    PublicEndpoints::invalidate_cache( $tournament_id );

    return rest_ensure_response( [
        'round'           => $next_round,
        'matches_created' => count( $result['match_ids'] ),
        'match_ids'       => $result['match_ids'],
    ] );
}
```

- [ ] **Step 3: Agregar `is_ghost` al response del fixture en `PublicEndpoints`**

Localizar la query del fixture (~línea 184) que hace JOIN con `ds_teams ht` y `ds_teams at`. Agregar los campos `is_ghost` al SELECT:

```php
// Antes (fragmento):
"    ht.name     AS home_team,
     ht.logo_url AS home_logo,
     at.name     AS away_team,
     at.logo_url AS away_logo,"

// Después:
"    ht.name     AS home_team,
     ht.logo_url AS home_logo,
     ht.is_ghost AS home_is_ghost,
     at.name     AS away_team,
     at.logo_url AS away_logo,
     at.is_ghost AS away_is_ghost,"
```

- [ ] **Step 4: Verificar el endpoint**

```bash
# Con WP nonce o Basic Auth de coordinador:
curl -X POST \
  "http://localhost:8088/wp-json/soccertrack/v1/admin/tournament/99/swiss-round" \
  -H "Content-Type: application/json" \
  -H "X-WP-Nonce: <nonce>" \
  -d '{"venue_id": 1}'
```

Respuesta esperada:
```json
{ "round": 1, "matches_created": 14, "match_ids": [101, 102, ...] }
```

Verificar el fixture público:
```
GET /wp-json/soccertrack/v1/public/tournament/99/fixture
```
Confirmar que los partidos incluyen `home_is_ghost` y `away_is_ghost` (0 o 1).

- [ ] **Step 5: Actualizar el docblock de `register_routes()` con la nueva ruta**

Al inicio de `AdminEndpoints.php`, agregar la línea al listado:
```php
 * POST /admin/tournament/{id}/swiss-round  — Generar siguiente ronda Swiss (coordinador)
```

- [ ] **Step 6: Commit**

```bash
git add soccertrack/includes/RestApi/AdminEndpoints.php soccertrack/includes/RestApi/PublicEndpoints.php
git commit -m "feat(swiss): REST endpoint swiss-round + is_ghost en fixture público"
```

---

## Task 5: UI — Panel admin + portal público

**Files:**
- Modify: `soccertrack/templates/panel/torneo-detalle.php`
- Modify: `soccertrack/assets/js/live-standings.js`

**Interfaces:**
- Consumes: `FixtureGenerator::get_swiss_status()` — disponible como variable PHP `$swiss_status` en el template (el controlador del panel ya lo inyectará; ver instrucción abajo)
- Consumes: `home_is_ghost`, `away_is_ghost` en objetos de partido del fixture API (Task 4)
- Produces: bloque HTML "Fase Liga Swiss" con 4 estados en el panel admin
- Produces: renderizado "LIBRE (Descanso)" en el fixture público para partidos bye

### Sub-task 5a: Panel admin

- [ ] **Step 1: Inyectar `$swiss_status` en el controlador del panel**

Buscar en `AdminController.php` la función que renderiza la vista `torneo-detalle.php` (busca `torneo-detalle` en el archivo). Localizar donde se hace `include` o `load_template` de esa vista, y agregar antes de esa línea:

```php
// Swiss status (solo para formato swiss).
$swiss_status = [];
if ( ( $tournament['format'] ?? '' ) === 'swiss' ) {
    $swiss_status = ( new \SportsLeague\Core\FixtureGenerator() )->get_swiss_status( (int) $tournament['id'] );
}
```

- [ ] **Step 2: Agregar el bloque Swiss al template**

En `torneo-detalle.php`, buscar el bloque que muestra el fixture generado (cerca de donde se muestran los partidos por ronda). Agregar ANTES de ese bloque:

```php
<?php if ( ( $tournament['format'] ?? '' ) === 'swiss' && ! empty( $swiss_status ) ) : ?>
<div class="st-card" style="margin-bottom:24px">
    <h2 class="st-card-title"><?php esc_html_e( 'Fase Liga Swiss', 'soccertrack' ); ?></h2>

    <?php if ( $swiss_status['swiss_done'] ) : ?>
        <div class="st-alert st-alert--info" style="background:#dbeafe;border-color:#3b82f6;color:#1e3a5f">
            ✅ <?php
            echo esc_html(
                sprintf(
                    /* translators: %d: total de rondas */
                    __( 'Fase liga completa (%d/%d rondas). Configura los brackets de playoffs.', 'soccertrack' ),
                    $swiss_status['total_rounds'],
                    $swiss_status['total_rounds']
                )
            );
            ?>
        </div>

    <?php elseif ( $swiss_status['current_round'] > 0 && $swiss_status['round_complete'] ) : ?>
        <div class="st-alert st-alert--success">
            ✅ <?php
            echo esc_html(
                sprintf(
                    /* translators: %1$d: ronda completada, %2$d: total de rondas */
                    __( 'Ronda %1$d de %2$d completada — todos los resultados ingresados.', 'soccertrack' ),
                    $swiss_status['current_round'],
                    $swiss_status['total_rounds']
                )
            );
            ?>
        </div>
        <?php if ( empty( $is_locked ) ) : ?>
        <button
            class="st-btn st-btn--primary js-swiss-next-round"
            data-tournament-id="<?php echo esc_attr( (string) $tournament['id'] ); ?>"
            data-next-round="<?php echo esc_attr( (string) ( $swiss_status['current_round'] + 1 ) ); ?>"
            data-total-rounds="<?php echo esc_attr( (string) $swiss_status['total_rounds'] ); ?>"
            style="margin-top:12px"
        >
            <?php
            echo esc_html(
                sprintf(
                    /* translators: %1$d: número de siguiente ronda, %2$d: total */
                    __( 'Generar Ronda %1$d de %2$d', 'soccertrack' ),
                    $swiss_status['current_round'] + 1,
                    $swiss_status['total_rounds']
                )
            );
            ?>
        </button>
        <?php endif; ?>

    <?php elseif ( 0 === $swiss_status['current_round'] ) : ?>
        <p class="st-muted"><?php esc_html_e( 'Aún no se ha generado ninguna ronda. Usa el botón de fixture para generar la Ronda 1.', 'soccertrack' ); ?></p>

    <?php else : ?>
        <div class="st-alert" style="background:#fef9c3;border-color:#f59e0b;color:#92400e">
            ⏳ <?php
            echo esc_html(
                sprintf(
                    /* translators: %1$d: ronda en curso, %2$d: total de rondas */
                    __( 'Ronda %1$d de %2$d en curso. Ingresa los resultados para habilitar la siguiente ronda.', 'soccertrack' ),
                    $swiss_status['current_round'],
                    $swiss_status['total_rounds']
                )
            );
            ?>
        </div>
    <?php endif; ?>
</div>

<script>
( function () {
    'use strict';
    document.querySelectorAll( '.js-swiss-next-round' ).forEach( function ( btn ) {
        btn.addEventListener( 'click', function () {
            const tid        = btn.dataset.tournamentId;
            const nextRound  = btn.dataset.nextRound;
            const totalRounds = btn.dataset.totalRounds;

            if ( ! confirm(
                '¿Generar ronda ' + nextRound + ' de ' + totalRounds + '? ' +
                'Los emparejamientos se calcularán según la tabla actual.'
            ) ) {
                return;
            }

            btn.disabled    = true;
            btn.textContent = 'Generando…';

            const nonce = window.stAdmin?.nonce ?? '';
            fetch(
                window.stAdmin?.apiBase + 'soccertrack/v1/admin/tournament/' + tid + '/swiss-round',
                {
                    method:  'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-WP-Nonce':   nonce,
                    },
                    body: JSON.stringify( {
                        venue_id: parseInt( window.stAdmin?.venueId ?? 1, 10 ),
                    } ),
                }
            )
                .then( function ( r ) { return r.json(); } )
                .then( function ( data ) {
                    if ( data.code ) {
                        alert( 'Error: ' + ( data.message ?? data.code ) );
                        btn.disabled    = false;
                        btn.textContent = 'Reintentar';
                    } else {
                        window.location.reload();
                    }
                } )
                .catch( function ( err ) {
                    alert( 'Error de red: ' + err.message );
                    btn.disabled    = false;
                    btn.textContent = 'Reintentar';
                } );
        } );
    } );
}() );
</script>
<?php endif; ?>
```

- [ ] **Step 3: Verificar el panel admin con 4 estados**

Probar manualmente los 4 estados:
1. Torneo Swiss sin rondas → muestra mensaje "Aún no se ha generado ninguna ronda".
2. Ronda 1 generada con partidos pendientes → muestra banner amarillo "Ronda 1 en curso".
3. Todos los resultados de ronda 1 ingresados → muestra banner verde + botón "Generar Ronda 2".
4. Todas las rondas completas → muestra banner azul "Fase liga completa".

### Sub-task 5b: Portal público — renderizar LIBRE

- [ ] **Step 4: Modificar el renderer de partidos en `live-standings.js`**

Buscar la función `renderRound` o la sección donde se construye el HTML de cada partido en `renderFixture` (~línea 537). Localizar donde se usan `m.home_team` y `m.away_team` para el nombre del equipo.

Agregar un helper al inicio del bloque del fixture (después de la declaración de `const cache`):

```js
/** Retorna el nombre a mostrar para un equipo; 'LIBRE (Descanso)' si es fantasma. */
function teamDisplay( name, isGhost ) {
    return isGhost ? ( i18n.bye_team ?? 'LIBRE (Descanso)' ) : escHtml( name ?? '—' );
}
```

Luego buscar cada lugar donde se imprime el nombre del equipo en el fixture (busca `m.home_team` en el archivo) y reemplazar:

```js
// Antes:
escHtml( m.home_team )
// Después:
teamDisplay( m.home_team, m.home_is_ghost )

// Antes:
escHtml( m.away_team )
// Después:
teamDisplay( m.away_team, m.away_is_ghost )
```

- [ ] **Step 5: Agregar `bye_team` a las cadenas i18n en el PHP que localiza el script**

Buscar en `TournamentPage.php` o `public/class-soccertrack-public.php` donde se hace `wp_localize_script` para `stPublic`. Agregar a la clave `i18n`:

```php
'bye_team' => __( 'LIBRE (Descanso)', 'soccertrack' ),
```

- [ ] **Step 6: Verificar en el portal público**

Navegar a `/torneo/{id}/` de un torneo Swiss con número impar de equipos. En la pestaña Fixture verificar que el partido de descanso muestra "LIBRE (Descanso)" en lugar del nombre del equipo fantasma.

- [ ] **Step 7: Commit**

```bash
git add soccertrack/templates/panel/torneo-detalle.php \
        soccertrack/assets/js/live-standings.js \
        soccertrack/includes/Public/TournamentPage.php \
        soccertrack/includes/Admin/AdminController.php
git commit -m "feat(swiss): UI panel Swiss 4-estados + renderizado LIBRE en fixture público"
```

---

## Self-Review

### Cobertura del spec

| Requisito spec | Task que lo implementa |
|---|---|
| ENUM `swiss` en `ds_tournaments.format` | Task 1 |
| Columna `swiss_rounds` configurable por torneo | Task 1 |
| Columna `is_ghost` en `ds_teams` | Task 1 |
| StandingsCalculator excluye equipos fantasma | Task 2 |
| `get_swiss_status()` con 5 campos | Task 3 Step 5 |
| Algoritmo greedy + backtracking | Task 3 Steps 1-2 |
| Bye: equipo de menor ranking sin bye previo | Task 3 Steps 3-4 |
| `generate_swiss_round()` con guard idempotencia | Task 3 Step 6 |
| Partido bye insertado como `finished` 0-0 | Task 3 Step 6 |
| `assign_courts()` aplicado a todos los partidos | Task 3 Step 6 |
| Endpoint `POST /swiss-round` con validaciones | Task 4 Steps 1-2 |
| `is_ghost` en response del fixture público | Task 4 Step 3 |
| Panel: 4 estados del bloque Swiss | Task 5 Step 2 |
| Botón "Generar Ronda N+1" con confirmación JS | Task 5 Step 2 |
| Portal: "LIBRE (Descanso)" en fixture | Task 5 Steps 4-5 |
| Reutiliza brackets existentes para playoffs | No requiere código nuevo |

### Consistencia de tipos

- `get_swiss_status()` retorna `bye_history: int[]` — `generate_swiss_round()` lo consume vía llamada interna a la misma lógica de derivación (no lo recibe como argumento, lo recomputa). Consistente.
- `build_swiss_pairs()` recibe `array $team_ids` (int[]) y `array $played` (string→true). Retorna `array<array{home:int,away:int}>`. Consumido correctamente por `insert_round()` que espera `array<array{home:int,away:int}>`.
- El endpoint REST retorna `{round:int, matches_created:int, match_ids:int[]}`. Referenciado correctamente en el JS del panel.
