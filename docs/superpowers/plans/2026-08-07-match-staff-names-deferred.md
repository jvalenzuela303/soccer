# Match Staff Names — Deferred Registration Mode

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** En modo deferred, el coordinador puede ingresar los nombres del árbitro y del planillero como texto libre por partido (sin requerir cuentas WP), directamente desde la vista de planilla del partido.

**Architecture:** Se agregan dos columnas nullable `referee_name` y `planillero_name` a `ds_matches`. En `partido.php` se muestran campos de texto editables solo cuando `$tournament['registration_mode'] === 'deferred'`, guardados por un nuevo POST handler `st_save_staff_names` en `view_partido()`. La vista `carga-fecha.php` muestra los nombres guardados en el resumen de partidos cerrados.

**Tech Stack:** PHP 8.2, WordPress 7.0.2, MariaDB 10.6, dbDelta()

## Global Constraints

- PHP 8.2, WordPress 7.0.2, MariaDB 10.6
- Prefijo de tablas: `$wpdb->prefix . 'ds_'`
- Text domain i18n: `soccertrack`
- Namespace: `SportsLeague\Core`
- WordPress Coding Standards (WPCS): escaping, sanitización, prepare() en todas las queries
- `wp_die( $msg, '', [ 'response' => 403 ] )` — status en tercer argumento
- No hay tests automáticos — verificación manual en cada tarea

---

## Mapa de archivos

| Archivo | Acción | Qué cambia |
|---------|--------|-----------|
| `soccertrack/includes/Core/DatabaseInstaller.php` | Modificar | Agregar `referee_name`, `planillero_name` al CREATE TABLE y migration idempotente |
| `soccertrack/includes/Public/TournamentPage.php` | Modificar | Nuevo POST handler `st_save_staff_names` en `view_partido()` |
| `soccertrack/templates/panel/partido.php` | Modificar | Campos de texto para nombres en modo deferred |
| `soccertrack/templates/panel/carga-fecha.php` | Modificar | Mostrar nombres en resumen de partido cerrado |

---

### Task 1: Migración de base de datos

**Files:**
- Modify: `soccertrack/includes/Core/DatabaseInstaller.php`

**Interfaces:**
- Produce: columnas `referee_name VARCHAR(120) NULL` y `planillero_name VARCHAR(120) NULL` en `ds_matches`

- [ ] **Step 1: Agregar columnas al CREATE TABLE de ds_matches**

En `create_tables()`, bloque de `ds_matches`, agregar antes de `created_at`:

```php
referee_name   VARCHAR(120) NULL,
planillero_name VARCHAR(120) NULL,
```

El bloque completo (columnas relevantes) queda:
```php
dbDelta( "CREATE TABLE {$wpdb->prefix}ds_matches (
    id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    tournament_id       BIGINT UNSIGNED NOT NULL,
    round_number        INT UNSIGNED    NOT NULL DEFAULT 0,
    home_team_id        BIGINT UNSIGNED NOT NULL DEFAULT 0,
    away_team_id        BIGINT UNSIGNED NOT NULL DEFAULT 0,
    venue_id            BIGINT UNSIGNED NOT NULL DEFAULT 0,
    court_id            BIGINT UNSIGNED NOT NULL DEFAULT 0,
    referee_user_id     BIGINT UNSIGNED NULL,
    planillero_user_id  BIGINT UNSIGNED NULL,
    referee_name        VARCHAR(120)    NULL,
    planillero_name     VARCHAR(120)    NULL,
    match_datetime      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    home_score          INT UNSIGNED    NOT NULL DEFAULT 0,
    away_score          INT UNSIGNED    NOT NULL DEFAULT 0,
    status              ENUM('scheduled','in_progress','finished','suspended') NOT NULL DEFAULT 'scheduled',
    phase               ENUM('regular','semifinal','third_place','final')      NOT NULL DEFAULT 'regular',
    created_at          DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_tournament (tournament_id),
    KEY idx_status (status)
) ENGINE=InnoDB {$c};" );
```

- [ ] **Step 2: Agregar migración idempotente**

En `apply_index_migrations()`, después de la migración de `registration_mode` (la última agregada):

```php
// v1.7.0 — ds_matches: nombres de árbitro y planillero (modo deferred).
$has_ref_name = $wpdb->get_var( "SHOW COLUMNS FROM {$prefix}ds_matches LIKE 'referee_name'" ); // phpcs:ignore
if ( ! $has_ref_name ) {
    $wpdb->query( // phpcs:ignore
        "ALTER TABLE {$prefix}ds_matches
         ADD COLUMN referee_name    VARCHAR(120) NULL AFTER planillero_user_id,
         ADD COLUMN planillero_name VARCHAR(120) NULL AFTER referee_name"
    );
}
```

- [ ] **Step 3: Verificar migración**

```sql
SHOW COLUMNS FROM wp_ds_matches LIKE 'referee_name';
SHOW COLUMNS FROM wp_ds_matches LIKE 'planillero_name';
```

Resultado esperado: dos filas con `Null = YES`, `Default = NULL`.

- [ ] **Step 4: Commit**

```bash
git add soccertrack/includes/Core/DatabaseInstaller.php
git commit -m "feat: add referee_name and planillero_name text columns to ds_matches"
```

---

### Task 2: POST handler en view_partido() y UI en partido.php

**Files:**
- Modify: `soccertrack/includes/Public/TournamentPage.php` (view_partido)
- Modify: `soccertrack/templates/panel/partido.php`

**Interfaces:**
- Consume: POST `st_save_staff_names`, `referee_name`, `planillero_name`
- Produce: nombres persistidos en BD; `$notice_staff` disponible en template

- [ ] **Step 1: Agregar POST handler en view_partido()**

En `TournamentPage.php`, método `view_partido()`, después del bloque `st_save_planillero` y antes de cargar `$referees` y `$planilleros`:

```php
// ── Guardar nombres de árbitro y planillero (modo deferred) ──────────
$notice_staff = '';
$error_staff  = '';
if ( $_SERVER['REQUEST_METHOD'] === 'POST' && isset( $_POST['st_save_staff_names'] ) ) {
    check_admin_referer( 'st_save_staff_names_' . $id );

    if ( ! current_user_can( 'ds_manage_tournaments' ) ) {
        wp_die( esc_html__( 'Sin permiso.', 'soccertrack' ), '', [ 'response' => 403 ] );
    }

    $ref_name  = sanitize_text_field( $_POST['referee_name']    ?? '' );
    $plan_name = sanitize_text_field( $_POST['planillero_name'] ?? '' );

    $wpdb->update( // phpcs:ignore
        "{$wpdb->prefix}ds_matches",
        [ 'referee_name' => $ref_name ?: null, 'planillero_name' => $plan_name ?: null ],
        [ 'id' => $id ],
        [ '%s', '%s' ],
        [ '%d' ]
    );
    $notice_staff = 'staff_saved';

    $match = $wpdb->get_row( // phpcs:ignore
        $wpdb->prepare(
            "SELECT m.*, v.name AS venue, c.court_name
             FROM {$wpdb->prefix}ds_matches m
             LEFT JOIN {$wpdb->prefix}ds_venues v ON v.id = m.venue_id
             LEFT JOIN {$wpdb->prefix}ds_courts c ON c.id = m.court_id
             WHERE m.id = %d",
            $id
        ),
        ARRAY_A
    );
}
```

También agregar `$notice_staff` y `$error_staff` al `compact()` final:
```php
self::render( 'partido', compact(
    'match', 'tournament', 'home_team', 'away_team', 'home_players', 'away_players',
    'referees', 'planilleros',
    'notice_ref', 'error_ref',
    'notice_plan', 'error_plan',
    'notice_staff', 'error_staff',
    'can_edit', 'page_title'
) );
```

- [ ] **Step 2: Agregar formulario de nombres en partido.php**

En `templates/panel/partido.php`, después del bloque de asignación de planillero (buscar el form de `st_save_planillero`) y antes del `<script>` de `$ms_config`, agregar:

```php
<?php if ( ( $tournament['registration_mode'] ?? 'realtime' ) === 'deferred' ) : ?>
<div class="st-card" style="margin-bottom:16px">
    <div class="st-card-header">
        <h2 class="st-card-title">👥 <?php esc_html_e( 'Personal del partido', 'soccertrack' ); ?></h2>
    </div>

    <?php if ( ( $notice_staff ?? '' ) === 'staff_saved' ) : ?>
        <div class="st-alert st-alert--success">✅ <?php esc_html_e( 'Nombres guardados.', 'soccertrack' ); ?></div>
    <?php endif; ?>

    <form method="post" action="" class="st-form-inline" style="gap:16px;padding:8px 0">
        <?php wp_nonce_field( 'st_save_staff_names_' . $match['id'] ); ?>
        <input type="hidden" name="st_save_staff_names" value="1">

        <div class="st-field">
            <label class="st-label"><?php esc_html_e( 'Árbitro', 'soccertrack' ); ?></label>
            <input
                type="text"
                name="referee_name"
                class="st-input"
                value="<?php echo esc_attr( (string) ( $match['referee_name'] ?? '' ) ); ?>"
                placeholder="<?php esc_attr_e( 'Nombre del árbitro', 'soccertrack' ); ?>"
                style="max-width:220px"
            >
        </div>

        <div class="st-field">
            <label class="st-label"><?php esc_html_e( 'Planillero', 'soccertrack' ); ?></label>
            <input
                type="text"
                name="planillero_name"
                class="st-input"
                value="<?php echo esc_attr( (string) ( $match['planillero_name'] ?? '' ) ); ?>"
                placeholder="<?php esc_attr_e( 'Nombre del planillero', 'soccertrack' ); ?>"
                style="max-width:220px"
            >
        </div>

        <div class="st-field" style="align-self:flex-end">
            <button type="submit" class="st-btn st-btn--secondary st-btn--sm">
                💾 <?php esc_html_e( 'Guardar', 'soccertrack' ); ?>
            </button>
        </div>
    </form>
    <p style="font-size:.8rem;color:#888;margin:4px 0 0">
        <?php esc_html_e( 'Solo visible en modo planilla física. Los nombres se usan como referencia en el acta.', 'soccertrack' ); ?>
    </p>
</div>
<?php endif; ?>
```

- [ ] **Step 3: Prueba manual**

1. Ir a un torneo en modo deferred.
2. Abrir `/panel/partido/{id}/`.
3. Verificar que aparece la tarjeta "Personal del partido".
4. Ingresar "Juan Árbitro" y "Pedro Planillero", guardar.
5. Verificar en BD:
```sql
SELECT referee_name, planillero_name FROM wp_ds_matches WHERE id = {id};
```
Resultado esperado: `referee_name='Juan Árbitro'`, `planillero_name='Pedro Planillero'`.
6. Recargar la página → los campos deben mostrar los valores guardados.
7. Verificar que en un torneo en modo realtime la tarjeta NO aparece.

- [ ] **Step 4: Commit**

```bash
git add soccertrack/includes/Public/TournamentPage.php \
        soccertrack/templates/panel/partido.php
git commit -m "feat: add staff name form to partido view in deferred mode"
```

---

### Task 3: Mostrar nombres en carga-fecha.php

**Files:**
- Modify: `soccertrack/templates/panel/carga-fecha.php`

**Interfaces:**
- Consume: `$match['referee_name']`, `$match['planillero_name']` (disponibles en el array de `$matches` que pasa `view_carga_fecha()`)
- Produce: tabla de eventos del partido cerrado muestra árbitro y planillero al pie

- [ ] **Step 1: Agregar pie de árbitro/planillero en el resumen del partido cerrado**

En `carga-fecha.php`, en el bloque de partido cerrado (dentro de `<?php else : ?>` que sigue a `<?php if ( ! $is_finished ) : ?>`), justo después de la tabla de eventos (o debajo del "Sin eventos registrados"), agregar:

```php
<?php if ( $match['referee_name'] || $match['planillero_name'] ) : ?>
<p style="font-size:.78rem;color:#666;margin-top:8px">
    <?php if ( $match['referee_name'] ) : ?>
        ⚖️ <?php echo esc_html( $match['referee_name'] ); ?>
        <?php if ( $match['planillero_name'] ) : ?>
            &nbsp;·&nbsp;
        <?php endif; ?>
    <?php endif; ?>
    <?php if ( $match['planillero_name'] ) : ?>
        📋 <?php echo esc_html( $match['planillero_name'] ); ?>
    <?php endif; ?>
</p>
<?php endif; ?>
```

- [ ] **Step 2: Actualizar la query de matches en view_carga_fecha()**

La query en `view_carga_fecha()` ya hace `m.*` por lo que `referee_name` y `planillero_name` ya estarán disponibles automáticamente una vez que existan en la BD (no requiere cambio en la query).

Verificar leyendo el método que la query es `SELECT m.*, ...` — si usa columnas explícitas, agregar `m.referee_name, m.planillero_name`.

- [ ] **Step 3: Prueba manual**

1. En un torneo deferred, ir a `/panel/carga-fecha/?tournament_id={id}&round=1`.
2. Para un partido cerrado con nombres guardados, verificar que aparece "⚖️ Juan Árbitro · 📋 Pedro Planillero" debajo de la tabla de eventos.
3. Para un partido cerrado sin nombres, verificar que el bloque no aparece.

- [ ] **Step 4: Commit**

```bash
git add soccertrack/templates/panel/carga-fecha.php
git commit -m "feat: show referee and planillero names in carga-fecha closed match summary"
```

---

## Self-Review

**Spec coverage:**
- ✅ Nombres como texto libre (no WP user) → columnas VARCHAR nullable
- ✅ Solo coordinador puede editar → `current_user_can('ds_manage_tournaments')`
- ✅ Solo visible en modo deferred → guard `registration_mode === 'deferred'` en partido.php
- ✅ Persistidos en BD → `$wpdb->update()` con `%s`/`%s` format
- ✅ Visibles en acta post-cierre → carga-fecha.php pie del partido cerrado
- ✅ No afecta flujo realtime → tarjeta condicionada al modo, `referee_user_id`/`planillero_user_id` intactos

**Sin placeholders:** Todo el código está completo.
