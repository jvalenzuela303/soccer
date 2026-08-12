# Brackets de Playoffs Configurables — Plan de Implementación

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Agregar soporte para N brackets de playoffs configurables por torneo (ej: Copa de Oro, Copa de Plata), manteniendo compatibilidad total con torneos sin brackets.

**Architecture:** Nueva tabla `wp_ds_playoff_brackets` almacena la configuración de brackets (nombre, rango de posiciones). Un campo nullable `bracket_id` en `wp_ds_matches` vincula cada partido de playoff a su bracket. `FixtureGenerator` recibe `bracket_id` explícito; `StandingsCalculator` enriquece cada fila con su bracket asignado. La API admin expone CRUD de brackets y modifica los endpoints de playoffs/finals para requerir `bracket_id` cuando el torneo tiene brackets configurados.

**Tech Stack:** PHP 8.2, WordPress 7.0.2, MariaDB 10.6+, JavaScript ES2020, dbDelta() para migraciones, WP REST API

## Global Constraints

- PHP 8.2 — usar `match`, `readonly`, `enum`, nullsafe `?->`, return types explícitos
- WordPress Coding Standards (WPCS) — todos los archivos PHP
- dbDelta() para toda migración de schema — sin scripts MySQL manuales
- Hosting compartido — sin privilegio SUPER, sin variables globales del servidor
- Prefijo de tabla: `{$wpdb->prefix}ds_` (actualmente `wp_ds_`)
- Namespace PHP: `SportsLeague\Core`, `SportsLeague\RestApi`, `SportsLeague\Public`
- i18n: text domain `soccertrack`, usar `__()` / `esc_html__()` en todo texto visible
- `bracket_id = NULL` en `ds_matches` → partido de fase regular, comportamiento actual sin cambio
- Torneos sin filas en `ds_playoff_brackets` → comportamiento actual sin cambio (compatibilidad hacia atrás)
- DB version: `SOCCERTRACK_DB_VERSION` se bumpeará de `'1.9.3'` a `'1.9.4'`

---

## Mapa de archivos

| Archivo | Acción |
|---|---|
| `soccertrack/soccertrack.php:39` | Modificar — bump `SOCCERTRACK_DB_VERSION` a `'1.9.4'` |
| `soccertrack/includes/Core/DatabaseInstaller.php` | Modificar — nueva tabla + migración de columna |
| `soccertrack/includes/Core/FixtureGenerator.php` | Modificar — 2 métodos nuevos |
| `soccertrack/includes/Core/StandingsCalculator.php` | Modificar — enriquecer resultado con bracket |
| `soccertrack/includes/RestApi/AdminEndpoints.php` | Modificar — bracket CRUD + modificar playoffs/finals |
| `soccertrack/includes/RestApi/PublicEndpoints.php` | Modificar — endpoint brackets + `bracket_id` en fixture |
| `soccertrack/assets/js/live-standings.js` | Modificar — columna Copa en standings + agrupación por bracket en fixture |

---

## Task 1: DB Schema — Nueva tabla y migración de columna

**Files:**
- Modify: `soccertrack/soccertrack.php:39`
- Modify: `soccertrack/includes/Core/DatabaseInstaller.php`

**Interfaces:**
- Produces: tabla `wp_ds_playoff_brackets(id, tournament_id, name, rank_from, rank_to, sort_order, created_at)` disponible para Tasks 2, 3, 4
- Produces: columna `wp_ds_matches.bracket_id BIGINT UNSIGNED NULL` disponible para Tasks 2, 4, 5

- [ ] **Step 1: Bump DB version**

En `soccertrack/soccertrack.php` línea 39, cambiar:

```php
define( 'SOCCERTRACK_DB_VERSION', '1.9.3' );
```

por:

```php
define( 'SOCCERTRACK_DB_VERSION', '1.9.4' );
```

- [ ] **Step 2: Agregar tabla `ds_playoff_brackets` en `create_tables()`**

En `DatabaseInstaller.php`, en el método `create_tables()`, después del bloque que crea `wp_ds_disciplinary_sanctions` (línea ~182) y antes de `update_option(...)`, agregar:

```php
// 10. Brackets de play-offs (configuración de copas por torneo).
dbDelta( "CREATE TABLE {$wpdb->prefix}ds_playoff_brackets (
    id            BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
    tournament_id BIGINT UNSIGNED  NOT NULL,
    name          VARCHAR(100)     NOT NULL,
    rank_from     TINYINT UNSIGNED NOT NULL,
    rank_to       TINYINT UNSIGNED NOT NULL,
    sort_order    TINYINT UNSIGNED NOT NULL DEFAULT 0,
    created_at    DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_tournament (tournament_id)
) ENGINE=InnoDB {$c};" );
```

- [ ] **Step 3: Agregar migración de columna `bracket_id` en `apply_index_migrations()`**

Al final del método `apply_index_migrations()`, antes del cierre `}`, agregar:

```php
// v1.9.4 — ds_matches: agregar bracket_id para play-offs con múltiples brackets.
$has_bracket_id = $wpdb->get_var( "SHOW COLUMNS FROM {$prefix}ds_matches LIKE 'bracket_id'" ); // phpcs:ignore
if ( ! $has_bracket_id ) {
    $wpdb->query( // phpcs:ignore
        "ALTER TABLE {$prefix}ds_matches
         ADD COLUMN bracket_id BIGINT UNSIGNED NULL DEFAULT NULL AFTER phase,
         ADD KEY idx_bracket (bracket_id)"
    );
}
```

- [ ] **Step 4: Verificar que la migración funciona**

Desactivar y reactivar el plugin en el WP admin (o ejecutar `DatabaseInstaller::maybe_upgrade()` directamente). Luego verificar:

```sql
SHOW CREATE TABLE wp_ds_playoff_brackets;
SHOW COLUMNS FROM wp_ds_matches LIKE 'bracket_id';
```

Resultado esperado: tabla `wp_ds_playoff_brackets` existe con 7 columnas; `ds_matches` tiene columna `bracket_id BIGINT UNSIGNED NULL`.

- [ ] **Step 5: Commit**

```bash
git add soccertrack/soccertrack.php soccertrack/includes/Core/DatabaseInstaller.php
git commit -m "feat(db): agregar tabla ds_playoff_brackets y columna bracket_id en ds_matches (v1.9.4)"
```

---

## Task 2: FixtureGenerator — Generación por bracket

**Files:**
- Modify: `soccertrack/includes/Core/FixtureGenerator.php`

**Interfaces:**
- Consumes: `StandingsCalculator::recalculate(int $tournament_id): array` — retorna array ordenado por rank con `['team_id' => int, ...]`
- Consumes: `ds_playoff_brackets` (Task 1) — columnas `id, tournament_id, rank_from, rank_to`
- Consumes: `ds_matches.bracket_id` (Task 1)
- Consumes: métodos privados existentes: `weekdays_from_tournament(array): int[]`, `duration_from_tournament(array): int`, `next_match_datetime(array, string, int, int, int): string`, `assign_courts(int[], int): void`
- Produces: `generate_bracket_playoffs(array $tournament, int $bracket_id, int $venue_id): array{match_ids: int[], error?: string}`
- Produces: `generate_bracket_finals(array $tournament, int $bracket_id, int $venue_id): array{match_ids: int[], error?: string}`

- [ ] **Step 1: Agregar `generate_bracket_playoffs()` al final de la clase, antes del cierre `}`**

```php
/**
 * Genera las semi-finales de un bracket específico de play-offs.
 *
 * Emparejamiento: primero del bracket vs último, segundo vs penúltimo.
 * Requiere que todos los partidos 'regular' del torneo estén 'finished'.
 *
 * @param  array{id:int,match_weekday:int,match_weekdays:string,match_time:string,match_duration:int} $tournament
 * @param  int $bracket_id  ID del bracket en ds_playoff_brackets.
 * @param  int $venue_id    Recinto donde se disputarán las semi-finales.
 * @return array{match_ids: int[], error?: string}
 */
public function generate_bracket_playoffs( array $tournament, int $bracket_id, int $venue_id ): array {
    global $wpdb;

    $tournament_id = (int) $tournament['id'];
    $weekdays      = $this->weekdays_from_tournament( $tournament );
    $time          = (string) ( $tournament['match_time'] ?? '19:00:00' );
    $duration      = $this->duration_from_tournament( $tournament );

    // 1. Leer y validar el bracket.
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery
    $bracket = $wpdb->get_row(
        $wpdb->prepare(
            "SELECT id, tournament_id, rank_from, rank_to
             FROM {$wpdb->prefix}ds_playoff_brackets
             WHERE id = %d",
            $bracket_id
        ),
        ARRAY_A
    );

    if ( ! $bracket || (int) $bracket['tournament_id'] !== $tournament_id ) {
        return [ 'match_ids' => [], 'error' => __( 'Bracket no encontrado o no pertenece a este torneo.', 'soccertrack' ) ];
    }

    $rank_from = (int) $bracket['rank_from'];
    $rank_to   = (int) $bracket['rank_to'];

    // 2. Verificar que todos los partidos regulares están finalizados.
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery
    $pending = (int) $wpdb->get_var(
        $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}ds_matches
             WHERE tournament_id = %d AND phase = 'regular' AND status != 'finished'",
            $tournament_id
        )
    );

    if ( $pending > 0 ) {
        return [ 'match_ids' => [], 'error' => __( 'Aún hay partidos de fase regular sin finalizar.', 'soccertrack' ) ];
    }

    // 3. Verificar que no existan ya semi-finales de este bracket.
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery
    $existing_sf = (int) $wpdb->get_var(
        $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}ds_matches
             WHERE tournament_id = %d AND bracket_id = %d AND phase = 'semifinal'",
            $tournament_id,
            $bracket_id
        )
    );

    if ( $existing_sf > 0 ) {
        return [ 'match_ids' => [], 'error' => __( 'Las semi-finales de este bracket ya fueron generadas.', 'soccertrack' ) ];
    }

    // 4. Extraer equipos del rango del bracket de la tabla de posiciones.
    $standings     = ( new StandingsCalculator() )->recalculate( $tournament_id );
    $bracket_teams = array_slice( $standings, $rank_from - 1, $rank_to - $rank_from + 1 );
    $num_teams     = count( $bracket_teams );

    if ( $num_teams < 4 ) {
        return [ 'match_ids' => [], 'error' => __( 'Se necesitan al menos 4 equipos en el rango del bracket.', 'soccertrack' ) ];
    }

    // 5. Emparejamiento: primero vs último, segundo vs penúltimo.
    $first  = (int) $bracket_teams[0]['team_id'];
    $second = (int) $bracket_teams[1]['team_id'];
    $third  = (int) $bracket_teams[ $num_teams - 2 ]['team_id'];
    $last   = (int) $bracket_teams[ $num_teams - 1 ]['team_id'];

    $dt_sf1 = $this->next_match_datetime( $weekdays, $time, 0, 0, $duration );
    $dt_sf2 = $this->next_match_datetime( $weekdays, $time, 1, 0, $duration );

    $ids = [];

    foreach ( [
        [ 'home' => $first,  'away' => $last,  'dt' => $dt_sf1 ],
        [ 'home' => $second, 'away' => $third, 'dt' => $dt_sf2 ],
    ] as $pair ) {
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
                'phase'          => 'semifinal',
                'bracket_id'     => $bracket_id,
            ],
            [ '%d', '%d', '%d', '%d', '%d', '%d', '%s', '%s', '%s', '%d' ]
        );

        if ( $wpdb->insert_id ) {
            $ids[] = (int) $wpdb->insert_id;
        }
    }

    $this->assign_courts( $ids, $venue_id );

    return [ 'match_ids' => $ids ];
}
```

- [ ] **Step 2: Agregar `generate_bracket_finals()` al final de la clase, antes del cierre `}`**

```php
/**
 * Genera la Final y el partido por el 3.er puesto de un bracket específico.
 *
 * Requiere que ambas semi-finales del bracket estén finalizadas.
 * En caso de empate en semi: gana el equipo local (misma regla que generate_finals()).
 *
 * @param  array{id:int,match_weekday:int,match_weekdays:string,match_time:string,match_duration:int} $tournament
 * @param  int $bracket_id
 * @param  int $venue_id
 * @return array{match_ids: int[], error?: string}
 */
public function generate_bracket_finals( array $tournament, int $bracket_id, int $venue_id ): array {
    global $wpdb;

    $tournament_id = (int) $tournament['id'];
    $weekdays      = $this->weekdays_from_tournament( $tournament );
    $time          = (string) ( $tournament['match_time'] ?? '19:00:00' );
    $duration      = $this->duration_from_tournament( $tournament );

    // 1. Leer semi-finales finalizadas del bracket.
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery
    $semis = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT id, home_team_id, away_team_id, home_score, away_score
             FROM {$wpdb->prefix}ds_matches
             WHERE tournament_id = %d AND bracket_id = %d AND phase = 'semifinal' AND status = 'finished'
             ORDER BY id ASC",
            $tournament_id,
            $bracket_id
        ),
        ARRAY_A
    );

    if ( count( $semis ) < 2 ) {
        return [ 'match_ids' => [], 'error' => __( 'Ambas semi-finales del bracket deben estar finalizadas.', 'soccertrack' ) ];
    }

    // 2. Verificar que no existan ya final/3er puesto de este bracket.
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery
    $existing = (int) $wpdb->get_var(
        $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}ds_matches
             WHERE tournament_id = %d AND bracket_id = %d AND phase IN ('final', 'third_place')",
            $tournament_id,
            $bracket_id
        )
    );

    if ( $existing > 0 ) {
        return [ 'match_ids' => [], 'error' => __( 'La final de este bracket ya fue generada.', 'soccertrack' ) ];
    }

    // 3. Determinar ganadores y perdedores (empate → gana local).
    $resolve = static function ( array $m ): array {
        $hs = (int) $m['home_score'];
        $as = (int) $m['away_score'];
        if ( $hs >= $as ) {
            return [ 'winner' => (int) $m['home_team_id'], 'loser' => (int) $m['away_team_id'] ];
        }
        return [ 'winner' => (int) $m['away_team_id'], 'loser' => (int) $m['home_team_id'] ];
    };

    $sf1 = $resolve( $semis[0] );
    $sf2 = $resolve( $semis[1] );

    $dt_3rd   = $this->next_match_datetime( $weekdays, $time, 0, 0, $duration );
    $dt_final = $this->next_match_datetime( $weekdays, $time, 1, 0, $duration );

    $ids = [];

    foreach ( [
        [ 'home' => $sf1['loser'],  'away' => $sf2['loser'],  'dt' => $dt_3rd,   'phase' => 'third_place' ],
        [ 'home' => $sf1['winner'], 'away' => $sf2['winner'], 'dt' => $dt_final,  'phase' => 'final' ],
    ] as $pair ) {
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
                'bracket_id'     => $bracket_id,
            ],
            [ '%d', '%d', '%d', '%d', '%d', '%d', '%s', '%s', '%s', '%d' ]
        );

        if ( $wpdb->insert_id ) {
            $ids[] = (int) $wpdb->insert_id;
        }
    }

    $this->assign_courts( $ids, $venue_id );

    return [ 'match_ids' => $ids ];
}
```

- [ ] **Step 3: Verificar manualmente**

Crear un bracket vía REST API (Task 4 aún no existe), o probar con una llamada directa en PHP:
```php
$gen = new \SportsLeague\Core\FixtureGenerator();
$result = $gen->generate_bracket_playoffs( ['id'=>1,'match_time'=>'19:00:00','match_weekday'=>6,'match_weekdays'=>'','match_duration'=>60], 1, 1 );
var_dump( $result );
```
Esperar array con `match_ids` o `error` descriptivo.

- [ ] **Step 4: Commit**

```bash
git add soccertrack/includes/Core/FixtureGenerator.php
git commit -m "feat(fixture): agregar generate_bracket_playoffs() y generate_bracket_finals()"
```

---

## Task 3: StandingsCalculator — Enriquecer con bracket

**Files:**
- Modify: `soccertrack/includes/Core/StandingsCalculator.php`

**Interfaces:**
- Consumes: `ds_playoff_brackets` (Task 1) — `id, name, rank_from, rank_to`
- Produces: `recalculate(int $tournament_id): array` — cada elemento ahora incluye `bracket_id: int|null` y `bracket_name: string|null`

- [ ] **Step 1: Modificar `recalculate()` — agregar enriquecimiento de bracket**

Reemplazar la línea final del método:
```php
return array_values( $table );
```

por:

```php
$sorted = array_values( $table );

// Enriquecer con bracket si el torneo tiene brackets configurados
// y la fase regular está completa.
// phpcs:ignore WordPress.DB.DirectDatabaseQuery
$brackets = $wpdb->get_results(
    $wpdb->prepare(
        "SELECT id, name, rank_from, rank_to
         FROM {$wpdb->prefix}ds_playoff_brackets
         WHERE tournament_id = %d
         ORDER BY rank_from ASC",
        $tournament_id
    ),
    ARRAY_A
);

$regular_pending = 0;
if ( ! empty( $brackets ) ) {
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery
    $regular_pending = (int) $wpdb->get_var(
        $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}ds_matches
             WHERE tournament_id = %d AND phase = 'regular' AND status != 'finished'",
            $tournament_id
        )
    );
}

foreach ( $sorted as $rank_idx => &$row ) {
    $row['bracket_id']   = null;
    $row['bracket_name'] = null;

    if ( empty( $brackets ) || $regular_pending > 0 ) {
        continue;
    }

    $rank = $rank_idx + 1; // 1-based.
    foreach ( $brackets as $bracket ) {
        if ( $rank >= (int) $bracket['rank_from'] && $rank <= (int) $bracket['rank_to'] ) {
            $row['bracket_id']   = (int) $bracket['id'];
            $row['bracket_name'] = $bracket['name'];
            break;
        }
    }
}
unset( $row );

return $sorted;
```

- [ ] **Step 2: Verificar mediante REST API pública**

```bash
curl -s "https://torneoscorporativos.cl/wp-json/soccertrack/v1/public/tournament/1/standings" | python3 -m json.tool | grep -A2 bracket
```

Esperado: cada equipo incluye `"bracket_id": null` (o un entero si hay brackets configurados y la fase regular terminó).

- [ ] **Step 3: Commit**

```bash
git add soccertrack/includes/Core/StandingsCalculator.php
git commit -m "feat(standings): enriquecer tabla de posiciones con bracket_id y bracket_name"
```

---

## Task 4: Admin REST API — CRUD de brackets + modificar playoffs/finals

**Files:**
- Modify: `soccertrack/includes/RestApi/AdminEndpoints.php`

**Interfaces:**
- Consumes: `FixtureGenerator::generate_bracket_playoffs(array, int, int): array` (Task 2)
- Consumes: `FixtureGenerator::generate_bracket_finals(array, int, int): array` (Task 2)
- Consumes: `ds_playoff_brackets` (Task 1)
- Produces: `POST   /admin/tournament/{id}/brackets` — crea bracket, retorna `{id, name, rank_from, rank_to, sort_order}`
- Produces: `GET    /admin/tournament/{id}/brackets` — lista brackets del torneo
- Produces: `PATCH  /admin/tournament/{id}/brackets/{bid}` — edita bracket (bloqueado si tiene partidos)
- Produces: `DELETE /admin/tournament/{id}/brackets/{bid}` — elimina bracket (bloqueado si tiene partidos)
- Modifies: `POST /admin/tournament/{id}/playoffs` — acepta `bracket_id` opcional; si el torneo tiene brackets, `bracket_id` es requerido
- Modifies: `POST /admin/tournament/{id}/finals` — mismo criterio

- [ ] **Step 1: Agregar registro de rutas de brackets en `register_routes()`**

Justo antes del cierre `}` de `register_routes()` (línea ~335), agregar:

```php
self::register_bracket_routes();
```

- [ ] **Step 2: Agregar método privado `register_bracket_routes()`**

Agregar como método estático privado, justo antes de `post_match_result()`:

```php
private static function register_bracket_routes(): void {
    $tid_arg = [
        'validate_callback' => static fn( mixed $v ): bool => is_numeric( $v ) && (int) $v > 0,
        'sanitize_callback' => 'absint',
    ];
    $bid_arg = [
        'validate_callback' => static fn( mixed $v ): bool => is_numeric( $v ) && (int) $v > 0,
        'sanitize_callback' => 'absint',
    ];

    // POST + GET /admin/tournament/{id}/brackets
    register_rest_route(
        self::NAMESPACE,
        '/admin/tournament/(?P<id>\d+)/brackets',
        [
            [
                'methods'             => \WP_REST_Server::CREATABLE,
                'callback'            => [ self::class, 'post_bracket' ],
                'permission_callback' => static fn() => current_user_can( 'ds_generate_fixture' ),
                'args'                => [
                    'id'         => $tid_arg,
                    'name'       => [
                        'required'          => true,
                        'validate_callback' => static fn( mixed $v ): bool => is_string( $v ) && strlen( trim( $v ) ) > 0,
                        'sanitize_callback' => 'sanitize_text_field',
                    ],
                    'rank_from'  => [
                        'required'          => true,
                        'validate_callback' => static fn( mixed $v ): bool => is_numeric( $v ) && (int) $v >= 1,
                        'sanitize_callback' => 'absint',
                    ],
                    'rank_to'    => [
                        'required'          => true,
                        'validate_callback' => static fn( mixed $v ): bool => is_numeric( $v ) && (int) $v >= 1,
                        'sanitize_callback' => 'absint',
                    ],
                    'sort_order' => [
                        'required'          => false,
                        'default'           => 0,
                        'validate_callback' => static fn( mixed $v ): bool => is_numeric( $v ) && (int) $v >= 0,
                        'sanitize_callback' => 'absint',
                    ],
                ],
            ],
            [
                'methods'             => \WP_REST_Server::READABLE,
                'callback'            => [ self::class, 'get_brackets' ],
                'permission_callback' => static fn() => current_user_can( 'ds_generate_fixture' ),
                'args'                => [ 'id' => $tid_arg ],
            ],
        ]
    );

    // PATCH + DELETE /admin/tournament/{id}/brackets/{bid}
    register_rest_route(
        self::NAMESPACE,
        '/admin/tournament/(?P<id>\d+)/brackets/(?P<bid>\d+)',
        [
            [
                'methods'             => 'PATCH',
                'callback'            => [ self::class, 'patch_bracket' ],
                'permission_callback' => static fn() => current_user_can( 'ds_generate_fixture' ),
                'args'                => [
                    'id'         => $tid_arg,
                    'bid'        => $bid_arg,
                    'name'       => [
                        'required'          => false,
                        'validate_callback' => static fn( mixed $v ): bool => is_string( $v ) && strlen( trim( $v ) ) > 0,
                        'sanitize_callback' => 'sanitize_text_field',
                    ],
                    'rank_from'  => [
                        'required'          => false,
                        'validate_callback' => static fn( mixed $v ): bool => is_numeric( $v ) && (int) $v >= 1,
                        'sanitize_callback' => 'absint',
                    ],
                    'rank_to'    => [
                        'required'          => false,
                        'validate_callback' => static fn( mixed $v ): bool => is_numeric( $v ) && (int) $v >= 1,
                        'sanitize_callback' => 'absint',
                    ],
                    'sort_order' => [
                        'required'          => false,
                        'validate_callback' => static fn( mixed $v ): bool => is_numeric( $v ) && (int) $v >= 0,
                        'sanitize_callback' => 'absint',
                    ],
                ],
            ],
            [
                'methods'             => \WP_REST_Server::DELETABLE,
                'callback'            => [ self::class, 'delete_bracket' ],
                'permission_callback' => static fn() => current_user_can( 'ds_generate_fixture' ),
                'args'                => [
                    'id'  => $tid_arg,
                    'bid' => $bid_arg,
                ],
            ],
        ]
    );
}
```

- [ ] **Step 3: Agregar helper privado `brackets_overlap()`**

Agregar como método privado estático antes de `post_bracket()`:

```php
/**
 * Verifica si el rango [from, to] se solapa con brackets existentes del torneo.
 * Excluye opcionalmente un bracket (para ediciones).
 *
 * @param  int      $tournament_id
 * @param  int      $rank_from
 * @param  int      $rank_to
 * @param  int|null $exclude_id  ID del bracket a excluir (PATCH).
 * @return bool  true si hay solapamiento.
 */
private static function brackets_overlap( int $tournament_id, int $rank_from, int $rank_to, ?int $exclude_id = null ): bool {
    global $wpdb;

    $exclude_sql = $exclude_id ? $wpdb->prepare( ' AND id != %d', $exclude_id ) : '';

    // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
    $count = (int) $wpdb->get_var(
        $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}ds_playoff_brackets
             WHERE tournament_id = %d
               AND rank_from <= %d
               AND rank_to   >= %d" . $exclude_sql, // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            $tournament_id,
            $rank_to,
            $rank_from
        )
    );

    return $count > 0;
}
```

- [ ] **Step 4: Agregar helper privado `bracket_is_locked()`**

```php
/**
 * Un bracket está bloqueado si ya tiene partidos generados.
 */
private static function bracket_is_locked( int $bracket_id ): bool {
    global $wpdb;
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery
    return (bool) (int) $wpdb->get_var(
        $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}ds_matches WHERE bracket_id = %d",
            $bracket_id
        )
    );
}
```

- [ ] **Step 5: Agregar `post_bracket()`, `get_brackets()`, `patch_bracket()`, `delete_bracket()`**

```php
public static function post_bracket( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
    global $wpdb;

    $tid        = (int) $request['id'];
    $name       = (string) $request['name'];
    $rank_from  = (int) $request['rank_from'];
    $rank_to    = (int) $request['rank_to'];
    $sort_order = (int) ( $request['sort_order'] ?? 0 );

    if ( $rank_from >= $rank_to ) {
        return new \WP_Error( 'invalid_range', __( 'rank_from debe ser menor que rank_to.', 'soccertrack' ), [ 'status' => 422 ] );
    }

    if ( self::brackets_overlap( $tid, $rank_from, $rank_to ) ) {
        return new \WP_Error( 'bracket_overlap', __( 'El rango se solapa con un bracket existente.', 'soccertrack' ), [ 'status' => 409 ] );
    }

    // phpcs:ignore WordPress.DB.DirectDatabaseQuery
    $wpdb->insert(
        "{$wpdb->prefix}ds_playoff_brackets",
        [
            'tournament_id' => $tid,
            'name'          => $name,
            'rank_from'     => $rank_from,
            'rank_to'       => $rank_to,
            'sort_order'    => $sort_order,
        ],
        [ '%d', '%s', '%d', '%d', '%d' ]
    );

    $bracket_id = (int) $wpdb->insert_id;
    if ( ! $bracket_id ) {
        return new \WP_Error( 'db_error', __( 'Error al crear el bracket.', 'soccertrack' ), [ 'status' => 500 ] );
    }

    return rest_ensure_response( [
        'id'         => $bracket_id,
        'name'       => $name,
        'rank_from'  => $rank_from,
        'rank_to'    => $rank_to,
        'sort_order' => $sort_order,
    ] );
}

public static function get_brackets( \WP_REST_Request $request ): \WP_REST_Response {
    global $wpdb;

    $tid = (int) $request['id'];

    // phpcs:ignore WordPress.DB.DirectDatabaseQuery
    $rows = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT id, name, rank_from, rank_to, sort_order
             FROM {$wpdb->prefix}ds_playoff_brackets
             WHERE tournament_id = %d
             ORDER BY sort_order ASC, rank_from ASC",
            $tid
        ),
        ARRAY_A
    ) ?: [];

    return rest_ensure_response(
        array_map( static fn( array $r ): array => [
            'id'         => (int) $r['id'],
            'name'       => $r['name'],
            'rank_from'  => (int) $r['rank_from'],
            'rank_to'    => (int) $r['rank_to'],
            'sort_order' => (int) $r['sort_order'],
            'locked'     => self::bracket_is_locked( (int) $r['id'] ),
        ], $rows )
    );
}

public static function patch_bracket( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
    global $wpdb;

    $tid = (int) $request['id'];
    $bid = (int) $request['bid'];

    // phpcs:ignore WordPress.DB.DirectDatabaseQuery
    $bracket = $wpdb->get_row(
        $wpdb->prepare(
            "SELECT id, tournament_id, name, rank_from, rank_to, sort_order
             FROM {$wpdb->prefix}ds_playoff_brackets
             WHERE id = %d AND tournament_id = %d",
            $bid,
            $tid
        ),
        ARRAY_A
    );

    if ( ! $bracket ) {
        return new \WP_Error( 'bracket_not_found', __( 'Bracket no encontrado.', 'soccertrack' ), [ 'status' => 404 ] );
    }

    if ( self::bracket_is_locked( $bid ) ) {
        return new \WP_Error( 'bracket_locked', __( 'El bracket ya tiene partidos generados y no puede ser editado.', 'soccertrack' ), [ 'status' => 409 ] );
    }

    $name       = isset( $request['name'] )       ? (string) $request['name']       : $bracket['name'];
    $rank_from  = isset( $request['rank_from'] )  ? (int) $request['rank_from']     : (int) $bracket['rank_from'];
    $rank_to    = isset( $request['rank_to'] )    ? (int) $request['rank_to']       : (int) $bracket['rank_to'];
    $sort_order = isset( $request['sort_order'] ) ? (int) $request['sort_order']    : (int) $bracket['sort_order'];

    if ( $rank_from >= $rank_to ) {
        return new \WP_Error( 'invalid_range', __( 'rank_from debe ser menor que rank_to.', 'soccertrack' ), [ 'status' => 422 ] );
    }

    if ( self::brackets_overlap( $tid, $rank_from, $rank_to, $bid ) ) {
        return new \WP_Error( 'bracket_overlap', __( 'El rango se solapa con un bracket existente.', 'soccertrack' ), [ 'status' => 409 ] );
    }

    // phpcs:ignore WordPress.DB.DirectDatabaseQuery
    $wpdb->update(
        "{$wpdb->prefix}ds_playoff_brackets",
        [
            'name'       => $name,
            'rank_from'  => $rank_from,
            'rank_to'    => $rank_to,
            'sort_order' => $sort_order,
        ],
        [ 'id' => $bid ],
        [ '%s', '%d', '%d', '%d' ],
        [ '%d' ]
    );

    return rest_ensure_response( [
        'id'         => $bid,
        'name'       => $name,
        'rank_from'  => $rank_from,
        'rank_to'    => $rank_to,
        'sort_order' => $sort_order,
    ] );
}

public static function delete_bracket( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
    global $wpdb;

    $tid = (int) $request['id'];
    $bid = (int) $request['bid'];

    // phpcs:ignore WordPress.DB.DirectDatabaseQuery
    $exists = (int) $wpdb->get_var(
        $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}ds_playoff_brackets WHERE id = %d AND tournament_id = %d",
            $bid,
            $tid
        )
    );

    if ( ! $exists ) {
        return new \WP_Error( 'bracket_not_found', __( 'Bracket no encontrado.', 'soccertrack' ), [ 'status' => 404 ] );
    }

    if ( self::bracket_is_locked( $bid ) ) {
        return new \WP_Error( 'bracket_locked', __( 'El bracket ya tiene partidos generados y no puede ser eliminado.', 'soccertrack' ), [ 'status' => 409 ] );
    }

    // phpcs:ignore WordPress.DB.DirectDatabaseQuery
    $wpdb->delete( "{$wpdb->prefix}ds_playoff_brackets", [ 'id' => $bid ], [ '%d' ] );

    return rest_ensure_response( [ 'deleted' => true, 'id' => $bid ] );
}
```

- [ ] **Step 6: Modificar `post_generate_playoffs()` para soportar `bracket_id`**

Localizar el registro de la ruta `/admin/tournament/{id}/playoffs` (línea ~312-322). Agregar `bracket_id` a los args:

```php
'args' => [
    'id'         => $tid_arg,
    'venue_id'   => $venue_arg,
    'bracket_id' => [
        'required'          => false,
        'validate_callback' => static fn( mixed $v ): bool => is_numeric( $v ) && (int) $v > 0,
        'sanitize_callback' => 'absint',
    ],
],
```

Luego en el método `post_generate_playoffs()`, reemplazar el cuerpo desde la llamada a `generate_playoffs()`:

```php
// Verificar si el torneo tiene brackets configurados.
// phpcs:ignore WordPress.DB.DirectDatabaseQuery
$has_brackets = (bool) (int) $wpdb->get_var(
    $wpdb->prepare(
        "SELECT COUNT(*) FROM {$wpdb->prefix}ds_playoff_brackets WHERE tournament_id = %d",
        $tid
    )
);

$bracket_id = isset( $request['bracket_id'] ) ? (int) $request['bracket_id'] : 0;

if ( $has_brackets ) {
    if ( ! $bracket_id ) {
        return new \WP_Error(
            'bracket_id_required',
            __( 'Este torneo tiene brackets configurados. Debes especificar bracket_id.', 'soccertrack' ),
            [ 'status' => 400 ]
        );
    }
    $result = ( new FixtureGenerator() )->generate_bracket_playoffs( $tournament, $bracket_id, $venue_id );
} else {
    $result = ( new FixtureGenerator() )->generate_playoffs( $tournament, $venue_id );
}
```

- [ ] **Step 7: Modificar `post_generate_finals()` con el mismo patrón**

Agregar `bracket_id` al arg de la ruta (línea ~327-334):

```php
'args' => [
    'id'         => $tid_arg,
    'venue_id'   => $venue_arg,
    'bracket_id' => [
        'required'          => false,
        'validate_callback' => static fn( mixed $v ): bool => is_numeric( $v ) && (int) $v > 0,
        'sanitize_callback' => 'absint',
    ],
],
```

En `post_generate_finals()`, reemplazar la llamada a `generate_finals()`:

```php
// phpcs:ignore WordPress.DB.DirectDatabaseQuery
$has_brackets = (bool) (int) $wpdb->get_var(
    $wpdb->prepare(
        "SELECT COUNT(*) FROM {$wpdb->prefix}ds_playoff_brackets WHERE tournament_id = %d",
        $tid
    )
);

$bracket_id = isset( $request['bracket_id'] ) ? (int) $request['bracket_id'] : 0;

if ( $has_brackets ) {
    if ( ! $bracket_id ) {
        return new \WP_Error(
            'bracket_id_required',
            __( 'Este torneo tiene brackets configurados. Debes especificar bracket_id.', 'soccertrack' ),
            [ 'status' => 400 ]
        );
    }
    $result = ( new FixtureGenerator() )->generate_bracket_finals( $tournament, $bracket_id, $venue_id );
} else {
    $result = ( new FixtureGenerator() )->generate_finals( $tournament, $venue_id );
}
```

- [ ] **Step 8: Verificar CRUD mediante curl**

```bash
# Crear bracket Copa de Oro (requiere cookie/nonce de WP admin)
curl -X POST "https://torneoscorporativos.cl/wp-json/soccertrack/v1/admin/tournament/1/brackets" \
  -H "X-WP-Nonce: TU_NONCE" \
  -H "Content-Type: application/json" \
  -d '{"name":"Copa de Oro","rank_from":1,"rank_to":4,"sort_order":0}'

# Listar brackets
curl -s "https://torneoscorporativos.cl/wp-json/soccertrack/v1/admin/tournament/1/brackets" \
  -H "X-WP-Nonce: TU_NONCE"
```

Resultado esperado: `{"id":1,"name":"Copa de Oro","rank_from":1,"rank_to":4,"sort_order":0}` y listado con `"locked":false`.

- [ ] **Step 9: Commit**

```bash
git add soccertrack/includes/RestApi/AdminEndpoints.php
git commit -m "feat(api): CRUD de brackets y soporte bracket_id en playoffs/finals"
```

---

## Task 5: API Pública + Frontend — Fixture con brackets y columna Copa

**Files:**
- Modify: `soccertrack/includes/RestApi/PublicEndpoints.php`
- Modify: `soccertrack/assets/js/live-standings.js`

**Interfaces:**
- Consumes: `ds_playoff_brackets` (Task 1)
- Consumes: `ds_matches.bracket_id` (Task 1)
- Consumes: `StandingsCalculator::recalculate()` con `bracket_id`/`bracket_name` (Task 3)
- Produces: `GET /public/tournament/{id}/brackets` — estructura pública de brackets con equipos y partidos
- Produces: `GET /public/tournament/{id}/fixture` — ahora incluye `bracket_id` y `bracket_name` en partidos de playoff
- Produces: tabla de posiciones con columna "Copa" en el frontend cuando `bracket_name` está presente
- Produces: fixture agrupa partidos de playoff por bracket

- [ ] **Step 1: Agregar `bracket_id` y `bracket_name` a la query del fixture**

En `PublicEndpoints::get_fixture()`, la query SELECT (línea ~168) ya hace LEFT JOIN con `ds_courts`. Agregar al SELECT y FROM:

Reemplazar el inicio del SELECT:

```php
"SELECT
    m.id,
    m.round_number,
    COALESCE(m.phase, 'regular') AS phase,
    m.match_datetime,
    m.home_score,
    m.away_score,
    m.status,
    ht.name     AS home_team,
    ht.logo_url AS home_logo,
    at.name     AS away_team,
    at.logo_url AS away_logo,
    v.name      AS venue,
    c.court_name
 FROM {$wpdb->prefix}ds_matches m USE INDEX (idx_fixture_order)
 JOIN {$wpdb->prefix}ds_teams   ht ON ht.id = m.home_team_id
 JOIN {$wpdb->prefix}ds_teams   at ON at.id = m.away_team_id
 JOIN {$wpdb->prefix}ds_venues  v  ON v.id  = m.venue_id
 LEFT JOIN {$wpdb->prefix}ds_courts c ON c.id = m.court_id
 WHERE m.tournament_id = %d{$round_filter}
 ORDER BY m.round_number ASC, m.match_datetime ASC",
```

por:

```php
"SELECT
    m.id,
    m.round_number,
    COALESCE(m.phase, 'regular') AS phase,
    m.bracket_id,
    b.name                      AS bracket_name,
    m.match_datetime,
    m.home_score,
    m.away_score,
    m.status,
    ht.name     AS home_team,
    ht.logo_url AS home_logo,
    at.name     AS away_team,
    at.logo_url AS away_logo,
    v.name      AS venue,
    c.court_name
 FROM {$wpdb->prefix}ds_matches m USE INDEX (idx_fixture_order)
 JOIN {$wpdb->prefix}ds_teams   ht ON ht.id = m.home_team_id
 JOIN {$wpdb->prefix}ds_teams   at ON at.id = m.away_team_id
 JOIN {$wpdb->prefix}ds_venues  v  ON v.id  = m.venue_id
 LEFT JOIN {$wpdb->prefix}ds_courts          c ON c.id = m.court_id
 LEFT JOIN {$wpdb->prefix}ds_playoff_brackets b ON b.id = m.bracket_id
 WHERE m.tournament_id = %d{$round_filter}
 ORDER BY m.round_number ASC, m.match_datetime ASC",
```

- [ ] **Step 2: Agregar endpoint público `brackets` en `register_routes()`**

Agregar `'brackets'` al array `$routes`:

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

- [ ] **Step 3: Agregar `'brackets'` al `invalidate_cache()`**

```php
public static function invalidate_cache( int $tournament_id ): void {
    foreach ( [ 'standings', 'fixture', 'scorers', 'tribunal', 'teams', 'stats', 'brackets' ] as $s ) {
        delete_transient( self::cache_key( $tournament_id, $s ) );
    }
}
```

- [ ] **Step 4: Implementar `get_public_brackets()`**

Agregar el método al final de la clase:

```php
/**
 * GET /public/tournament/{id}/brackets
 *
 * Retorna la estructura de brackets del torneo con equipos clasificados
 * (si la fase regular terminó) y los partidos de playoff ya generados.
 * Sin autenticación.
 */
public static function get_public_brackets( \WP_REST_Request $request ): \WP_REST_Response {
    global $wpdb;

    $tid = (int) $request['id'];
    $key = self::cache_key( $tid, 'brackets' );

    $cached = get_transient( $key );
    if ( false !== $cached ) {
        return rest_ensure_response( $cached );
    }

    // Cargar brackets del torneo.
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery
    $brackets = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT id, name, rank_from, rank_to, sort_order
             FROM {$wpdb->prefix}ds_playoff_brackets
             WHERE tournament_id = %d
             ORDER BY sort_order ASC, rank_from ASC",
            $tid
        ),
        ARRAY_A
    ) ?: [];

    if ( empty( $brackets ) ) {
        set_transient( $key, [], self::CACHE_TTL );
        return rest_ensure_response( [] );
    }

    // Determinar si la fase regular está completa.
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery
    $regular_pending = (int) $wpdb->get_var(
        $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}ds_matches
             WHERE tournament_id = %d AND phase = 'regular' AND status != 'finished'",
            $tid
        )
    );

    $regular_complete = 0 === $regular_pending;

    // Standings para asignar equipos a brackets (solo si fase regular completa).
    $standings = $regular_complete
        ? ( new \SportsLeague\Core\StandingsCalculator() )->recalculate( $tid )
        : [];

    // Cargar partidos de playoff agrupados por bracket.
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery
    $playoff_matches = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT m.id, m.bracket_id, m.phase, m.status,
                    m.home_score, m.away_score, m.match_datetime,
                    ht.name AS home_team, at.name AS away_team
             FROM {$wpdb->prefix}ds_matches m
             JOIN {$wpdb->prefix}ds_teams ht ON ht.id = m.home_team_id
             JOIN {$wpdb->prefix}ds_teams at ON at.id = m.away_team_id
             WHERE m.tournament_id = %d AND m.bracket_id IS NOT NULL
             ORDER BY m.bracket_id ASC, m.match_datetime ASC",
            $tid
        ),
        ARRAY_A
    ) ?: [];

    // Indexar partidos por bracket_id → phase.
    $matches_by_bracket = [];
    foreach ( $playoff_matches as $m ) {
        $bid   = (int) $m['bracket_id'];
        $phase = $m['phase'];
        if ( ! isset( $matches_by_bracket[ $bid ] ) ) {
            $matches_by_bracket[ $bid ] = [];
        }
        $matches_by_bracket[ $bid ][ $phase ][] = $m;
    }

    // Construir respuesta.
    $result = [];
    foreach ( $brackets as $bracket ) {
        $bid       = (int) $bracket['id'];
        $rank_from = (int) $bracket['rank_from'];
        $rank_to   = (int) $bracket['rank_to'];

        // Equipos del rango (solo si fase regular completa).
        $teams = [];
        if ( $regular_complete ) {
            foreach ( $standings as $rank_idx => $row ) {
                $rank = $rank_idx + 1;
                if ( $rank >= $rank_from && $rank <= $rank_to ) {
                    $teams[] = [
                        'rank'      => $rank,
                        'team_id'   => (int) $row['team_id'],
                        'team_name' => $row['name'],
                        'pts'       => (int) $row['pts'],
                    ];
                }
            }
        }

        $result[] = [
            'id'         => $bid,
            'name'       => $bracket['name'],
            'rank_from'  => $rank_from,
            'rank_to'    => $rank_to,
            'sort_order' => (int) $bracket['sort_order'],
            'teams'      => $teams,
            'matches'    => $matches_by_bracket[ $bid ] ?? [],
        ];
    }

    set_transient( $key, $result, self::CACHE_TTL );
    return rest_ensure_response( $result );
}
```

- [ ] **Step 5: Modificar `live-standings.js` — columna Copa en standings**

En la función `renderStandings()`, localizar el bloque `<thead>` (línea ~256-271). Reemplazar la generación de la tabla completa para incluir la columna Copa condicionalmente.

Reemplazar desde `container.innerHTML = \`` (línea ~251) hasta el cierre de la tabla (`</table>`) — modificar solo el bloque de la tabla:

```javascript
// Detectar si algún equipo tiene bracket_name.
const hasBrackets = rows.some( r => r.bracket_name );

const trs = rows.map( ( r, i ) => {
    const winPct = r.pj > 0 ? Math.round( ( r.pg / r.pj ) * 100 ) : 0;
    const form   = ( r.form ?? [] ).map( f => {
        const cls = { W: 'w', D: 'd', L: 'l' }[ f ] ?? 'd';
        const lbl = { W: 'V', D: 'E', L: 'D' }[ f ] ?? f;
        return `<span class="st-form-bubble st-form-bubble--${cls}">${lbl}</span>`;
    } ).join( '' );
    const bracketCell = hasBrackets
        ? `<td class="st-bracket-cell">${ r.bracket_name ? escHtml( r.bracket_name ) : '—' }</td>`
        : '';
    return `<tr>
        <td>${ i + 1 }</td>
        <td class="st-team-name">${ logoOrPlaceholder( r.logo_url ?? null, r.name ) } ${ escHtml( r.name ) }</td>
        <td>${ r.pj }</td><td>${ r.pg }</td><td>${ r.pe }</td><td>${ r.pp }</td>
        <td>${ r.gf }</td><td>${ r.gc }</td><td>${ r.dg }</td><td>${ r.pts }</td>
        <td>${ winPct }%</td>
        <td>${ form || '—' }</td>
        ${ bracketCell }
    </tr>`;
} ).join( '' );
```

Y en el `<thead>`, agregar la columna Copa al final si hay brackets:

```javascript
const bracketHeader = hasBrackets
    ? `<th title="Copa / Bracket">Copa</th>`
    : '';
```

Agregar `${ bracketHeader }` al final del `<tr>` del thead.

**Nota:** La lógica de construcción de `trs` ya existe en el código. Localiza el `map` existente de filas (empieza en la línea que define `trs`) y reemplázalo con la versión de arriba que incluye `bracketCell`.

- [ ] **Step 6: Modificar `live-standings.js` — agrupar playoffs por bracket en fixture**

En `renderFixture()`, localizar el bloque `// ── Bracket de play-offs` (línea ~377). Reemplazar desde `if ( playoffMatches.length )` hasta el cierre `html += \`</div>\`` con:

```javascript
// ── Bracket de play-offs — agrupado por bracket ──────────────────────
if ( playoffMatches.length ) {
    const phaseTitle = {
        semifinal:   i18n.phase_semifinal   ?? 'Semi-finales',
        third_place: i18n.phase_third_place ?? '3.er Puesto',
        final:       i18n.phase_final       ?? 'Final',
    };

    // Agrupar por bracket_name (null → 'Play-offs' genérico para torneos sin brackets).
    const bracketMap = new Map();
    for ( const m of playoffMatches ) {
        const key = m.bracket_name ?? '__generic__';
        if ( ! bracketMap.has( key ) ) bracketMap.set( key, { name: m.bracket_name ?? ( i18n.playoffs_title ?? 'Play-offs' ), matches: [] } );
        bracketMap.get( key ).matches.push( m );
    }

    html += `<div class="st-playoffs-bracket">`;

    for ( const [ , bracket ] of bracketMap ) {
        html += `<h2 class="st-section-title">${ escHtml( bracket.name ) }</h2>`;

        const sfMatches    = bracket.matches.filter( m => m.phase === 'semifinal' );
        const thirdMatches = bracket.matches.filter( m => m.phase === 'third_place' );
        const finalMatches = bracket.matches.filter( m => m.phase === 'final' );

        for ( const [ phase, group ] of [ [ 'semifinal', sfMatches ], [ 'third_place', thirdMatches ], [ 'final', finalMatches ] ] ) {
            if ( ! group.length ) continue;
            html += `
            <div class="st-bracket-round">
                <h3 class="st-bracket-round-title">${ phaseTitle[ phase ] }</h3>
                <div class="st-bracket-matches">
                    ${ group.map( matchCard ).join( '' ) }
                </div>
            </div>`;
        }
    }

    html += `</div>`;
}
```

- [ ] **Step 7: Verificar en el portal público**

1. Crear brackets para un torneo de prueba via API
2. Cargar `https://torneoscorporativos.cl/torneo/1/`
3. Tab "Posiciones": verificar que aparece columna "Copa" con el nombre del bracket cuando la fase regular está completa
4. Tab "Fixture": verificar que playoffs aparecen agrupados bajo "Copa de Oro" y "Copa de Plata"
5. Torneo sin brackets configurados: verificar que ambas pestañas se comportan igual que antes

- [ ] **Step 8: Commit**

```bash
git add soccertrack/includes/RestApi/PublicEndpoints.php soccertrack/assets/js/live-standings.js
git commit -m "feat(public): endpoint brackets, bracket_id en fixture y agrupación visual por copa"
```

---

## Self-Review

### Cobertura del spec

| Requisito del spec | Task que lo cubre |
|---|---|
| Nueva tabla `ds_playoff_brackets` | Task 1 ✓ |
| Columna `bracket_id` en `ds_matches` | Task 1 ✓ |
| Bracket bloqueado si tiene partidos | Task 4 (`bracket_is_locked()`) ✓ |
| No solapamiento de rangos | Task 4 (`brackets_overlap()`) ✓ |
| `generate_bracket_playoffs()` | Task 2 ✓ |
| `generate_bracket_finals()` | Task 2 ✓ |
| Compatibilidad hacia atrás (sin brackets) | Task 4 (check `has_brackets`) ✓ |
| `bracket_id` / `bracket_name` en standings | Task 3 ✓ |
| Solo cuando fase regular completa | Task 3 ✓ |
| Endpoint público `brackets` | Task 5 ✓ |
| `bracket_id` en fixture query | Task 5 ✓ |
| Columna Copa en standings frontend | Task 5 ✓ |
| Agrupación por bracket en fixture frontend | Task 5 ✓ |
| Invalidación de caché `brackets` | Task 5 ✓ |
| Nombres libres (VARCHAR) | Task 1, Task 4 ✓ |
| Configuración al crear torneo (editable hasta tener partidos) | Task 4 (lock logic) ✓ |
| DB version bump | Task 1 ✓ |

Todos los requisitos cubiertos.
