# Cascade Date Shift Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Al guardar una fecha editada en el fixture, mostrar un modal que ofrece desplazar todos los partidos siguientes por el mismo delta de días.

**Architecture:** JS intercepta el submit del form `st_update_datetime`, calcula el delta (nueva fecha − original), muestra un `<dialog>` nativo de confirmación. Si el usuario acepta, agrega campos ocultos `cascade=1` y `cascade_delta_minutes=N` antes de enviar. El handler PHP existente se extiende para aplicar el desplazamiento en cascada sobre todos los `ds_matches` con `match_datetime` posterior.

**Tech Stack:** PHP 8.2, WordPress 7.0.2, MariaDB 10.6, vanilla JS (sin librerías externas), HTML `<dialog>` nativo

## Global Constraints

- PHP 8.2 — usar `\DateTime`, `\DateInterval`, nullsafe operator donde aplique
- WordPress Coding Standards: `absint()`, `sanitize_text_field()`, `check_admin_referer()`, `// phpcs:ignore WordPress.DB.DirectDatabaseQuery` en queries directas
- Prefijo tablas: `$wpdb->prefix . 'ds_'`
- i18n: todo texto visible en `__()` / `esc_html__()`
- No tocar `court_id` ni `venue_id` en la cascada — solo `match_datetime`
- El delta máximo aceptado es 525 600 minutos (1 año) — rechazar valores mayores
- El `<dialog>` debe funcionar sin JS de terceros

---

## Archivos modificados

| Archivo | Cambio |
|---|---|
| `soccertrack/templates/panel/torneo-detalle.php` | Task 1: `data-original` en inputs, `<dialog>` modal, JS interceptor, notice |
| `soccertrack/includes/Public/TournamentPage.php` | Task 2: cascada en handler `st_update_datetime` |

---

## Task 1: Frontend — `data-original`, modal `<dialog>` y JS interceptor

**Files:**
- Modify: `soccertrack/templates/panel/torneo-detalle.php`

**Interfaces:**
- Produces: form `st_update_datetime` puede llevar `cascade=1` y `cascade_delta_minutes=N` como campos ocultos adicionales
- Consumed by: Task 2 (handler PHP lee esos campos)

### Step 1: Agregar `data-original` al input datetime-local

Localizar el bloque de la celda de horario en el fixture (~línea 1388). El input actual es:

```php
<input
    type="datetime-local"
    name="match_datetime"
    class="st-input st-fixture-dt-input"
    value="<?php echo esc_attr( $dt ? substr( str_replace( ' ', 'T', $dt ), 0, 16 ) : '' ); ?>"
    style="max-width:148px;font-size:.78rem;padding:3px 5px"
>
```

Reemplazar por:

```php
<input
    type="datetime-local"
    name="match_datetime"
    class="st-input st-fixture-dt-input"
    value="<?php echo esc_attr( $dt ? substr( str_replace( ' ', 'T', $dt ), 0, 16 ) : '' ); ?>"
    data-original="<?php echo esc_attr( $dt ? substr( str_replace( ' ', 'T', $dt ), 0, 16 ) : '' ); ?>"
    style="max-width:148px;font-size:.78rem;padding:3px 5px"
>
```

### Step 2: Agregar el elemento `<dialog>` del modal

Localizar el bloque al final del template, justo antes del cierre `</div>` de la sección del fixture o antes del último `<script>`. Agregar el `<dialog>` una sola vez (fuera del `foreach`):

Buscar la línea que contiene:
```html
<script>
/* ── Reasignación de canchas por ronda ───────────────────────── */
```

Insertar **antes** de ese bloque:

```html
<?php /* ── Modal cascada de fechas ──────────────────────────────── */ ?>
<dialog id="st-cascade-modal" style="border:none;border-radius:12px;padding:0;box-shadow:0 8px 32px rgba(0,0,0,.18);max-width:420px;width:90%">
    <form method="dialog" style="padding:24px 28px">
        <p style="margin:0 0 6px;font-size:1rem;font-weight:700;color:#0E0C19">
            <?php esc_html_e( '¿Desplazar los partidos siguientes?', 'soccertrack' ); ?>
        </p>
        <p id="st-cascade-modal-desc" style="margin:0 0 20px;font-size:.88rem;color:#3C3A47;line-height:1.5"></p>
        <div style="display:flex;gap:10px;justify-content:flex-end">
            <button
                type="button"
                id="st-cascade-no"
                class="st-btn st-btn--secondary"
            ><?php esc_html_e( 'No, solo este partido', 'soccertrack' ); ?></button>
            <button
                type="button"
                id="st-cascade-yes"
                class="st-btn st-btn--primary"
            ><?php esc_html_e( 'Sí, desplazar todos', 'soccertrack' ); ?></button>
        </div>
    </form>
</dialog>
```

### Step 3: Agregar el JS interceptor

Localizar el bloque existente al final del template:
```html
<script>
/* ── Reasignación de canchas por ronda ───────────────────────── */
(function () {
```

Insertar **antes** de ese bloque el nuevo script:

```html
<script>
/* ── Cascada de fechas al editar horario de un partido ─────────── */
(function () {
    var modal       = document.getElementById('st-cascade-modal');
    var modalDesc   = document.getElementById('st-cascade-modal-desc');
    var btnYes      = document.getElementById('st-cascade-yes');
    var btnNo       = document.getElementById('st-cascade-no');
    var pendingForm = null;
    var pendingDelta = 0;

    if ( ! modal ) return;

    function deltaLabel( minutes ) {
        var days = Math.round( minutes / 1440 );
        var sign = days >= 0 ? '+' : '';
        if ( Math.abs( days ) === 7 )  return sign + days + ' <?php echo esc_js( __( 'días (siguiente semana)', 'soccertrack' ) ); ?>';
        if ( Math.abs( days ) === 1 )  return sign + days + ' <?php echo esc_js( __( 'día', 'soccertrack' ) ); ?>';
        return sign + days + ' <?php echo esc_js( __( 'días', 'soccertrack' ) ); ?>';
    }

    function countFutureMatches( originalIso ) {
        var origMs = new Date( originalIso ).getTime();
        var inputs = document.querySelectorAll('.st-fixture-dt-input[data-original]');
        var count  = 0;
        inputs.forEach( function ( inp ) {
            if ( inp.dataset.original && new Date( inp.dataset.original ).getTime() > origMs ) {
                count++;
            }
        } );
        return count;
    }

    document.addEventListener('submit', function ( e ) {
        var form = e.target;
        if ( ! form.querySelector('[name="st_update_datetime"]') ) return;

        var dtInput  = form.querySelector('[name="match_datetime"]');
        if ( ! dtInput || ! dtInput.dataset.original ) return;

        var original = dtInput.dataset.original;
        var newVal   = dtInput.value;
        if ( ! original || ! newVal ) return;

        var origMs  = new Date( original ).getTime();
        var newMs   = new Date( newVal ).getTime();
        var deltaMs = newMs - origMs;

        if ( deltaMs === 0 ) return; // sin cambio, dejar pasar

        var deltaMinutes = Math.round( deltaMs / 60000 );
        if ( Math.abs( deltaMinutes ) > 525600 ) return; // > 1 año, ignorar

        var futureCount = countFutureMatches( original );
        if ( futureCount === 0 ) return; // no hay partidos siguientes, sin modal

        e.preventDefault();
        pendingForm  = form;
        pendingDelta = deltaMinutes;

        modalDesc.textContent = '<?php echo esc_js( __( 'El partido se moverá', 'soccertrack' ) ); ?> '
            + deltaLabel( deltaMinutes )
            + '. <?php echo esc_js( __( '¿Aplicar el mismo desplazamiento a los', 'soccertrack' ) ); ?> '
            + futureCount
            + ' <?php echo esc_js( __( 'partidos programados después de esta fecha?', 'soccertrack' ) ); ?>';

        modal.showModal();
    } );

    btnYes.addEventListener('click', function () {
        modal.close();
        if ( ! pendingForm ) return;
        var c1 = document.createElement('input');
        c1.type = 'hidden'; c1.name = 'cascade'; c1.value = '1';
        var c2 = document.createElement('input');
        c2.type = 'hidden'; c2.name = 'cascade_delta_minutes'; c2.value = String( pendingDelta );
        pendingForm.appendChild( c1 );
        pendingForm.appendChild( c2 );
        pendingForm.submit();
    } );

    btnNo.addEventListener('click', function () {
        modal.close();
        if ( pendingForm ) pendingForm.submit();
    } );

    modal.addEventListener('cancel', function () {
        if ( pendingForm ) pendingForm.submit(); // Escape = No
    } );
}());
</script>
```

### Step 4: Agregar notice `datetime_cascade` y `datetime_updated` en el template

Localizar el bloque de notices al inicio del template. Actualmente existe:
```php
<?php if ( ( $notice ?? '' ) === 'courts_reassigned' ) : ?>
```

Agregar después de ese bloque:

```php
<?php if ( ( $notice ?? '' ) === 'datetime_updated' ) : ?>
    <div class="st-alert st-alert--success">✅ <?php esc_html_e( 'Horario del partido actualizado.', 'soccertrack' ); ?></div>
<?php endif; ?>
<?php if ( ( $notice ?? '' ) === 'datetime_cascade' ) : ?>
    <div class="st-alert st-alert--success">✅ <?php
    /* translators: %d: cantidad de partidos desplazados */
    printf( esc_html__( 'Horario actualizado. Se desplazaron también %d partidos siguientes.', 'soccertrack' ), absint( $_GET['cascade_count'] ?? 0 ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
    ?></div>
<?php endif; ?>
```

### Step 5: Verificar manualmente

1. Ir a `http://localhost:8088/panel/torneo/1/`
2. En el fixture, hacer clic en el input de horario de un partido de la jornada 1 y cambiar la fecha (+7 días)
3. Clic en ✔ — debe aparecer el `<dialog>` con texto descriptivo del delta y cantidad de partidos
4. Clic "No, solo este partido" — el form se envía normalmente, sin `cascade`
5. Repetir y esta vez clic "Sí" — verificar que los campos `cascade=1` y `cascade_delta_minutes` aparecen en el Network tab
6. Presionar Escape con el modal abierto — debe comportarse como "No" (el form se envía sin cascada)
7. Editar un partido de la última jornada (sin partidos siguientes) — el modal NO debe aparecer

### Step 6: Commit

```bash
git add soccertrack/templates/panel/torneo-detalle.php
git commit -m "feat(fixture): modal cascada de fechas — data-original, dialog, JS interceptor"
```

---

## Task 2: Backend — cascada en handler `st_update_datetime`

**Files:**
- Modify: `soccertrack/includes/Public/TournamentPage.php`

**Interfaces:**
- Consumes: `$_POST['cascade']` (string '1'), `$_POST['cascade_delta_minutes']` (int, puede ser negativo)
- Produces: actualiza `match_datetime` en todos los `ds_matches` del torneo con datetime > old_datetime; redirect con `?notice=datetime_cascade&cascade_count=N`

### Step 1: Localizar el punto de inserción en el handler

Abrir `soccertrack/includes/Public/TournamentPage.php`. Localizar este bloque (aproximadamente línea 1048):

```php
					if ( ! $error ) {
						$wpdb->update( // phpcs:ignore
							"{$wpdb->prefix}ds_matches",
							[ 'match_datetime' => $new_datetime ],
							[ 'id' => $match_id ],
							[ '%s' ],
							[ '%d' ]
						);
						$notice = 'datetime_updated';
					}
```

### Step 2: Extender el bloque `if ( ! $error )` con la cascada

Reemplazar el bloque completo `if ( ! $error ) { ... }` por:

```php
					if ( ! $error ) {
						// Guardar el datetime original ANTES de actualizar (necesario para la cascada).
						$old_datetime = $existing_match['match_datetime'] ?? null;

						$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
							"{$wpdb->prefix}ds_matches",
							[ 'match_datetime' => $new_datetime ],
							[ 'id' => $match_id ],
							[ '%s' ],
							[ '%d' ]
						);

						// ── Cascada de desplazamiento ──────────────────────────────
						$cascade       = ( '1' === ( $_POST['cascade'] ?? '' ) );
						$delta_minutes = (int) ( $_POST['cascade_delta_minutes'] ?? 0 );

						if ( $cascade && $delta_minutes !== 0 && abs( $delta_minutes ) <= 525600 && $old_datetime ) {
							$future_matches = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
								$wpdb->prepare(
									"SELECT id, match_datetime FROM {$wpdb->prefix}ds_matches
									 WHERE tournament_id = %d AND match_datetime > %s
									 ORDER BY match_datetime ASC",
									$id,
									$old_datetime
								),
								ARRAY_A
							);

							$cascade_count = 0;
							foreach ( $future_matches as $fm ) {
								$fm_dt     = new \DateTime( $fm['match_datetime'] );
								$interval  = new \DateInterval( 'PT' . abs( $delta_minutes ) . 'M' );
								if ( $delta_minutes > 0 ) {
									$fm_dt->add( $interval );
								} else {
									$fm_dt->sub( $interval );
								}
								$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
									"{$wpdb->prefix}ds_matches",
									[ 'match_datetime' => $fm_dt->format( 'Y-m-d H:i:s' ) ],
									[ 'id' => (int) $fm['id'] ],
									[ '%s' ],
									[ '%d' ]
								);
								$cascade_count++;
							}

							wp_safe_redirect(
								add_query_arg(
									[ 'notice' => 'datetime_cascade', 'cascade_count' => $cascade_count ],
									home_url( '/panel/torneo/' . $id . '/' )
								)
							);
							exit;
						}

						$notice = 'datetime_updated';
					}
```

> **Nota sobre `existing_match`:** el campo `match_datetime` no está en el SELECT actual. Hay que agregarlo. El SELECT actual es:
> ```php
> "SELECT id, court_id, status FROM {$wpdb->prefix}ds_matches WHERE id = %d AND tournament_id = %d"
> ```
> Cambiarlo a:
> ```php
> "SELECT id, court_id, status, match_datetime FROM {$wpdb->prefix}ds_matches WHERE id = %d AND tournament_id = %d"
> ```

### Step 3: Verificar manualmente en el navegador

1. Ir a `http://localhost:8088/panel/torneo/1/`
2. Cambiar la fecha de un partido de la jornada 3 (+7 días), clic ✔
3. En el modal: clic "Sí, desplazar todos"
4. Verificar alert verde: "Horario actualizado. Se desplazaron también N partidos siguientes."
5. Verificar en la DB:
   ```sql
   SELECT id, round_number, match_datetime 
   FROM wp_ds_matches 
   WHERE tournament_id = 1 
   ORDER BY round_number, match_datetime;
   ```
   — Las jornadas 4 en adelante deben tener `match_datetime` desplazado exactamente `delta_minutes` minutos

6. Probar con "No, solo este partido" — solo ese partido cambia, el resto queda igual
7. Probar con Escape en el modal — mismo efecto que "No"

### Step 4: Restart container y commit

```bash
docker restart soccertrack_wp
```

```bash
git add soccertrack/includes/Public/TournamentPage.php
git commit -m "feat(fixture): cascada de desplazamiento de fechas en handler st_update_datetime"
```

---

## Self-Review

**Cobertura del spec:**
- ✅ `data-original` en cada input de datetime-local
- ✅ JS calcula delta en minutos (puede ser negativo)
- ✅ Modal no aparece si delta = 0
- ✅ Modal no aparece si no hay partidos posteriores (`countFutureMatches === 0`)
- ✅ Modal muestra delta en texto legible (días con signo)
- ✅ "Sí" → cascada; "No" / Escape → solo este partido
- ✅ Backend valida `abs(delta) <= 525600` (1 año)
- ✅ Backend desplaza todos los `match_datetime > old_datetime` del torneo (regular + playoffs)
- ✅ Redirect con `notice=datetime_cascade&cascade_count=N`
- ✅ Notices `datetime_updated` y `datetime_cascade` en template
- ✅ No modifica `court_id` ni `venue_id`
- ✅ `existing_match` SELECT extendido para incluir `match_datetime`
