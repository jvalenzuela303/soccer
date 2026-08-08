# Liberación Configurable del Fixture por Jornada

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** El coordinador configura cuántos días después de terminada una jornada se hace visible la siguiente en el portal público; por defecto se muestra todo el fixture de inmediato (0 días).

**Architecture:** Se agrega `fixture_release_days TINYINT(1) NOT NULL DEFAULT 0` a `ds_tournaments`. En el endpoint público de fixture, los partidos de la jornada N+1 solo se devuelven si la última fecha programada de la jornada N + `fixture_release_days` ≤ hoy. La jornada 1 siempre es visible. El panel de creación y detalle del torneo exponen el campo.

**Tech Stack:** PHP 8.2, WordPress 7.0.2, MariaDB 10.6, REST API WordPress, dbDelta()

## Global Constraints

- PHP 8.2, WordPress 7.0.2, MariaDB 10.6
- Prefijo de tablas: `$wpdb->prefix . 'ds_'`
- Text domain i18n: `soccertrack`
- Namespace: `SportsLeague`
- WordPress Coding Standards (WPCS)
- `$wpdb->prepare()` en todas las queries directas; `// phpcs:ignore` en directas
- `wp_die( $msg, '', [ 'response' => 403 ] )` — status en tercer argumento
- Endpoint público: `GET /wp-json/soccertrack/v1/torneo/{id}/partidos`
- No hay tests automáticos — verificación manual con SQL

---

## Lógica de visibilidad de jornadas

Un partido de la jornada **R** es visible en el portal público si:

```
R == 1                    → siempre visible
R > 1                     → visible si:
    max(match_datetime de jornada R-1) + fixture_release_days días ≤ CURDATE()
```

Equivalente en SQL:
```sql
-- Para saber si la jornada R es visible dado fixture_release_days:
SELECT MAX(match_datetime) AS last_dt
FROM wp_ds_matches
WHERE tournament_id = %d AND round_number = %d  -- round_number = R - 1
```
Si `DATE(last_dt) + fixture_release_days <= CURDATE()`, la jornada R es visible.

**Valor 0** = visible el mismo día de la última fecha de la jornada anterior (comportamiento actual).  
**Valor 1** = visible al día siguiente.  
**Valor -1** = visible un día antes de que termine la jornada anterior.

---

## Mapa de archivos

| Archivo | Acción | Qué cambia |
|---------|--------|-----------|
| `soccertrack/includes/Core/DatabaseInstaller.php` | Modificar | Agregar `fixture_release_days` al CREATE TABLE + migration |
| `soccertrack/includes/RestApi/PublicEndpoints.php` | Modificar | Filtrar partidos por jornada visible en `get_matches()` |
| `soccertrack/includes/Public/TournamentPage.php` | Modificar | Persistir `fixture_release_days` en INSERT (view_torneos) y UPDATE (view_torneo) |
| `soccertrack/templates/panel/torneos.php` | Modificar | Campo numérico en formulario de creación |
| `soccertrack/templates/panel/torneo-detalle.php` | Modificar | Campo editable en tarjeta de configuración |

---

### Task 1: Migración de base de datos

**Files:**
- Modify: `soccertrack/includes/Core/DatabaseInstaller.php`

**Interfaces:**
- Produce: columna `fixture_release_days TINYINT(1) NOT NULL DEFAULT 0` en `ds_tournaments`

- [ ] **Step 1: Agregar columna al CREATE TABLE de ds_tournaments**

En `create_tables()`, bloque de `ds_tournaments`, agregar después de `registration_mode` y antes de `created_at`:

```php
fixture_release_days TINYINT(1)      NOT NULL DEFAULT 0,
```

- [ ] **Step 2: Agregar migración idempotente**

En `apply_index_migrations()`, después de la migración de `registration_mode`:

```php
// v1.7.1 — ds_tournaments: días de retraso para liberar el fixture de la siguiente jornada.
$has_release = $wpdb->get_var( "SHOW COLUMNS FROM {$prefix}ds_tournaments LIKE 'fixture_release_days'" ); // phpcs:ignore
if ( ! $has_release ) {
    $wpdb->query( // phpcs:ignore
        "ALTER TABLE {$prefix}ds_tournaments
         ADD COLUMN fixture_release_days TINYINT(1) NOT NULL DEFAULT 0
         AFTER registration_mode"
    );
}
```

- [ ] **Step 3: Verificar migración**

```sql
SHOW COLUMNS FROM wp_ds_tournaments LIKE 'fixture_release_days';
```

Resultado esperado: `Field='fixture_release_days'`, `Type='tinyint(1)'`, `Default='0'`.

- [ ] **Step 4: Commit**

```bash
git add soccertrack/includes/Core/DatabaseInstaller.php
git commit -m "feat: add fixture_release_days to ds_tournaments"
```

---

### Task 2: Filtrar jornadas en el endpoint público de fixture

**Files:**
- Modify: `soccertrack/includes/RestApi/PublicEndpoints.php`

**Interfaces:**
- Consume: `$tournament['fixture_release_days']` (int)
- Produce: endpoint `GET /soccertrack/v1/torneo/{id}/partidos` solo devuelve partidos de jornadas visibles

- [ ] **Step 1: Leer PublicEndpoints.php**

Leer el archivo para encontrar el método que responde a `GET /torneo/{id}/partidos`. Identificar:
- Nombre del método (probablemente `get_matches()` o similar)
- Cómo carga el torneo (necesita `fixture_release_days`)
- Cómo hace la query de partidos (necesita filtrar por `round_number`)

- [ ] **Step 2: Cargar fixture_release_days en el método de partidos**

En el método de partidos, al inicio (después de validar que el torneo existe), agregar:

```php
$release_days = (int) ( $tournament['fixture_release_days'] ?? 0 );
```

Si la query de torneo no selecciona esta columna, agregar `fixture_release_days` a la SELECT. Si usa `SELECT *`, ya está disponible.

- [ ] **Step 3: Calcular jornadas visibles**

Antes de la query de partidos, calcular el número máximo de jornada visible:

```php
if ( $release_days > 0 ) {
    // La jornada N es visible si max(match_datetime de N-1) + release_days <= hoy.
    // Obtenemos por cada jornada el max(match_datetime) y filtramos.
    $visible_rounds = $wpdb->get_col( // phpcs:ignore
        $wpdb->prepare(
            "SELECT round_number
             FROM {$wpdb->prefix}ds_matches
             WHERE tournament_id = %d
               AND round_number = 1
             UNION
             SELECT m.round_number
             FROM {$wpdb->prefix}ds_matches m
             WHERE m.tournament_id = %d
               AND m.round_number > 1
               AND EXISTS (
                   SELECT 1
                   FROM {$wpdb->prefix}ds_matches prev
                   WHERE prev.tournament_id = %d
                     AND prev.round_number = m.round_number - 1
                   GROUP BY prev.tournament_id
                   HAVING DATE_ADD( MAX(prev.match_datetime), INTERVAL %d DAY ) <= CURDATE()
               )
             GROUP BY m.round_number",
            $tournament_id,
            $tournament_id,
            $tournament_id,
            $release_days
        )
    );
    $visible_rounds = array_map( 'intval', $visible_rounds ?: [] );
} else {
    $visible_rounds = null; // null = sin filtro, mostrar todo
}
```

- [ ] **Step 4: Aplicar filtro en la query de partidos**

En la query de partidos, agregar condición cuando `$visible_rounds !== null`:

```php
$round_filter = '';
if ( null !== $visible_rounds ) {
    if ( empty( $visible_rounds ) ) {
        // Ninguna jornada visible aún — devolver array vacío.
        return rest_ensure_response( [] );
    }
    $placeholders = implode( ', ', array_fill( 0, count( $visible_rounds ), '%d' ) );
    $round_filter = $wpdb->prepare( " AND m.round_number IN ( {$placeholders} )", ...$visible_rounds ); // phpcs:ignore
}

// Usar $round_filter en la cláusula WHERE de la query de partidos:
// ... WHERE m.tournament_id = %d {$round_filter} ORDER BY ...
```

**Nota:** La query de partidos existente ya tiene un `WHERE m.tournament_id = %d`. Concatenar `$round_filter` después de esa condición.

- [ ] **Step 5: Prueba manual**

Configurar un torneo con `fixture_release_days = 1`:

```sql
UPDATE wp_ds_tournaments SET fixture_release_days = 1 WHERE id = 1;
```

1. Con jornada 1 no terminada → `GET /wp-json/soccertrack/v1/torneo/1/partidos` debe devolver solo jornada 1.
2. Con jornada 1 terminada ayer → debe devolver jornadas 1 y 2.
3. Con `fixture_release_days = 0` → debe devolver todas las jornadas sin importar estado.

```sql
-- Simular jornada 1 terminada antes de ayer:
UPDATE wp_ds_matches
SET match_datetime = DATE_SUB(NOW(), INTERVAL 2 DAY), status = 'finished'
WHERE tournament_id = 1 AND round_number = 1;
```

- [ ] **Step 6: Commit**

```bash
git add soccertrack/includes/RestApi/PublicEndpoints.php
git commit -m "feat: filter fixture by visible rounds based on fixture_release_days"
```

---

### Task 3: Formulario de creación y edición de fixture_release_days

**Files:**
- Modify: `soccertrack/includes/Public/TournamentPage.php` (view_torneos INSERT, view_torneo POST handler)
- Modify: `soccertrack/templates/panel/torneos.php`
- Modify: `soccertrack/templates/panel/torneo-detalle.php`

**Interfaces:**
- Consume: POST `fixture_release_days` (int, rango -7 a 30)
- Produce: campo guardado en BD; tarjeta editable en torneo-detalle

- [ ] **Step 1: Persistir en INSERT de view_torneos()**

En `TournamentPage.php`, método `view_torneos()`, en el bloque INSERT del torneo, agregar:

```php
$release_days = max( -7, min( 30, (int) ( $_POST['fixture_release_days'] ?? 0 ) ) );
```

Y en el `$wpdb->insert()`:
```php
// Agregar al array de datos:
'fixture_release_days' => $release_days,
// Agregar al array de formats:
'%d',
```

- [ ] **Step 2: Agregar campo en torneos.php**

En `templates/panel/torneos.php`, en el formulario de creación, después del selector `registration_mode`:

```php
<div class="st-field">
    <label for="st-release-days" class="st-label">
        <?php esc_html_e( 'Días para liberar siguiente jornada', 'soccertrack' ); ?>
    </label>
    <input
        type="number"
        id="st-release-days"
        name="fixture_release_days"
        class="st-input"
        value="0"
        min="-7"
        max="30"
        style="max-width:100px"
    >
    <span style="font-size:.78rem;color:#888;display:block;margin-top:4px">
        <?php esc_html_e( '0 = visible de inmediato. 1 = al día siguiente de terminada la jornada anterior.', 'soccertrack' ); ?>
    </span>
</div>
```

- [ ] **Step 3: Agregar POST handler en view_torneo()**

En `TournamentPage.php`, método `view_torneo()`, después del bloque `st_update_reg_mode`:

```php
// ── Actualizar días de liberación del fixture ─────────────────────
if ( $_SERVER['REQUEST_METHOD'] === 'POST' && isset( $_POST['st_update_release_days'] ) ) {
    check_admin_referer( 'st_update_release_days_' . $id );

    if ( ! current_user_can( 'ds_manage_tournaments' ) ) {
        wp_die( esc_html__( 'Sin permiso.', 'soccertrack' ), '', [ 'response' => 403 ] );
    }

    $release_days = max( -7, min( 30, (int) ( $_POST['fixture_release_days'] ?? 0 ) ) );

    $wpdb->update( // phpcs:ignore
        "{$wpdb->prefix}ds_tournaments",
        [ 'fixture_release_days' => $release_days ],
        [ 'id' => $id ],
        [ '%d' ],
        [ '%d' ]
    );
    $notice = 'release_days_updated';

    $tournament = $wpdb->get_row(
        $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}ds_tournaments WHERE id = %d", $id ),
        ARRAY_A
    );
}
```

- [ ] **Step 4: Agregar tarjeta en torneo-detalle.php**

En `templates/panel/torneo-detalle.php`, en la sección de alertas, agregar:

```php
<?php if ( ( $notice ?? '' ) === 'release_days_updated' ) : ?>
    <div class="st-alert st-alert--success">✅ <?php esc_html_e( 'Liberación de fixture actualizada.', 'soccertrack' ); ?></div>
<?php endif; ?>
```

Y agregar tarjeta junto a las otras de configuración (schedule, registration_mode):

```php
<?php /* ── Liberación del fixture ──────────────────────────────────────── */ ?>
<div class="st-card" style="margin-bottom:20px">
    <div class="st-card-header">
        <h2 class="st-card-title">📅 <?php esc_html_e( 'Liberación del fixture', 'soccertrack' ); ?></h2>
    </div>
    <form method="post" action="" class="st-form-inline" style="align-items:flex-end;gap:16px">
        <?php wp_nonce_field( 'st_update_release_days_' . $tournament['id'] ); ?>
        <input type="hidden" name="st_update_release_days" value="1">

        <div class="st-field">
            <label class="st-label"><?php esc_html_e( 'Días tras última fecha', 'soccertrack' ); ?></label>
            <input
                type="number"
                name="fixture_release_days"
                class="st-input"
                value="<?php echo esc_attr( (string) (int) ( $tournament['fixture_release_days'] ?? 0 ) ); ?>"
                min="-7"
                max="30"
                style="max-width:100px"
            >
        </div>

        <div class="st-field" style="align-self:flex-end">
            <button type="submit" class="st-btn st-btn--secondary st-btn--sm">
                💾 <?php esc_html_e( 'Guardar', 'soccertrack' ); ?>
            </button>
        </div>
    </form>
    <p style="margin:8px 0 0;font-size:.8rem;color:#888">
        <?php esc_html_e( '0 = todas las jornadas visibles de inmediato. 1 = la siguiente jornada se publica al día siguiente de terminada la anterior.', 'soccertrack' ); ?>
    </p>
</div>
```

- [ ] **Step 5: Prueba manual**

1. Crear torneo con `fixture_release_days = 1`.
2. Verificar en BD: `SELECT fixture_release_days FROM wp_ds_tournaments ORDER BY id DESC LIMIT 1;` → `1`.
3. Desde el detalle del torneo, cambiar a `2`, guardar → alerta de éxito.
4. Verificar que el endpoint de partidos aplica el filtro según lo probado en Task 2.

- [ ] **Step 6: Commit**

```bash
git add soccertrack/includes/Public/TournamentPage.php \
        soccertrack/templates/panel/torneos.php \
        soccertrack/templates/panel/torneo-detalle.php
git commit -m "feat: add fixture_release_days to tournament forms and detail view"
```

---

## Self-Review

**Spec coverage:**
- ✅ Configurable por torneo → `fixture_release_days` en `ds_tournaments`
- ✅ Valor por defecto 0 → comportamiento actual (todo visible inmediatamente)
- ✅ Ejemplo "al día siguiente" → `fixture_release_days = 1`
- ✅ Lógica: jornada N+1 visible si last(jornada N) + días ≤ hoy → query SQL con `DATE_ADD`
- ✅ Jornada 1 siempre visible → UNION con `round_number = 1`
- ✅ Editable desde creación y desde detalle del torneo
- ✅ Solo afecta endpoint público → panel del coordinador ve todo el fixture siempre

**Posible omisión:** El endpoint de "tabla de posiciones" no necesita cambios (las posiciones se calculan de partidos terminados, no de partidos futuros). El endpoint de fixture del panel admin tampoco debe filtrarse (coordinador necesita ver todo).

**Sin placeholders:** Todo el código está completo.
