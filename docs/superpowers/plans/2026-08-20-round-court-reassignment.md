# Round Court Reassignment Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Agregar un botón "⚙ Canchas" por ronda en el fixture del torneo que permite al coordinador seleccionar qué canchas están disponibles para esa fecha específica y reasignarlas en rotación.

**Architecture:** Sin nueva tabla de base de datos. El handler POST actualiza `ds_matches.court_id` en los partidos de la ronda afectada usando rotación circular sobre las canchas seleccionadas. La UI es un panel inline que se expande dentro de la tabla del fixture al hacer clic.

**Tech Stack:** PHP 8.2, WordPress 7.0.2, MariaDB 10.6, vanilla JS (sin dependencias externas)

## Global Constraints

- PHP 8.2 — usar sintaxis moderna: match, nullsafe operator, named args donde aplique
- WordPress Coding Standards (WPCS): sanitizar inputs con `sanitize_text_field`, `absint`, `array_map('intval', ...)`. Usar `check_admin_referer()` en todo POST
- Prefijo tablas: `$wpdb->prefix . 'ds_'`
- i18n: todo texto visible en `__()` o `esc_html__()`
- No modificar `match_datetime` — solo `court_id`
- No aplica si `$is_locked` (torneo finalizado)
- Capability requerida: `ds_manage_tournaments`

---

## Archivos modificados

| Archivo | Cambio |
|---|---|
| `soccertrack/includes/Public/TournamentPage.php` | Task 1: handler POST + notice |
| `soccertrack/templates/panel/torneo-detalle.php` | Task 2: fila separadora de ronda + panel inline |

---

## Task 1: Handler POST en TournamentPage.php

**Files:**
- Modify: `soccertrack/includes/Public/TournamentPage.php` (función `view_torneo`, bloque de handlers POST)

**Interfaces:**
- Produces: procesa `$_POST['st_reassign_round_courts']`, escribe `court_id` en `ds_matches`, redirect con `?notice=courts_reassigned&round=N`
- Consumed by: Task 2 (el template hace POST a la misma página, recibe el notice)

- [ ] **Step 1: Localizar el punto de inserción**

Abrir `soccertrack/includes/Public/TournamentPage.php`. Buscar el bloque que dice:

```php
// ── Cambiar estado de un partido ─────────────────────────────────
```

El nuevo handler se inserta **después** del bloque `st_rename_tournament` y **antes** del bloque `st_change_match_status`. El bloque `st_rename_tournament` termina en:

```php
			}
		}

		// ── Cambiar estado de un partido ─────────────────────────────────
```

- [ ] **Step 2: Insertar el handler POST**

Reemplazar:

```php
		// ── Cambiar estado de un partido ─────────────────────────────────
		if ( $_SERVER['REQUEST_METHOD'] === 'POST' && isset( $_POST['st_change_match_status'] ) ) {
```

Por:

```php
		// ── Reasignar canchas de una ronda ────────────────────────────────
		if ( $_SERVER['REQUEST_METHOD'] === 'POST' && isset( $_POST['st_reassign_round_courts'] ) ) {
			$round_number = absint( $_POST['round_number'] ?? 0 );
			check_admin_referer( 'st_reassign_round_courts_' . $id . '_' . $round_number );

			$raw_court_ids = array_map( 'absint', (array) ( $_POST['court_ids'] ?? [] ) );

			if ( empty( $raw_court_ids ) || ! $round_number ) {
				$error = __( 'Debes seleccionar al menos una cancha.', 'soccertrack' );
			} else {
				// Verificar que las canchas pertenecen al torneo (seguridad).
				$venue_ids = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
					$wpdb->prepare(
						"SELECT venue_id FROM {$wpdb->prefix}ds_tournament_venues WHERE tournament_id = %d",
						$id
					)
				);
				$venue_ids = array_map( 'intval', $venue_ids );

				$valid_court_ids = [];
				if ( ! empty( $venue_ids ) ) {
					$placeholders    = implode( ', ', array_fill( 0, count( $venue_ids ), '%d' ) );
					$valid_court_ids = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
						$wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
							"SELECT id FROM {$wpdb->prefix}ds_courts WHERE venue_id IN ({$placeholders})",
							...$venue_ids
						)
					);
					$valid_court_ids = array_map( 'intval', $valid_court_ids );
				}

				$court_ids = array_values( array_intersect( $raw_court_ids, $valid_court_ids ) );

				if ( empty( $court_ids ) ) {
					$error = __( 'Las canchas seleccionadas no pertenecen a este torneo.', 'soccertrack' );
				} else {
					// Traer partidos de la ronda ordenados por ID.
					$match_ids = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
						$wpdb->prepare(
							"SELECT id FROM {$wpdb->prefix}ds_matches
							 WHERE tournament_id = %d AND round_number = %d
							 ORDER BY id ASC",
							$id,
							$round_number
						)
					);

					// Rotación circular: match_i → court_ids[ i % count(court_ids) ].
					foreach ( $match_ids as $i => $match_id ) {
						$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
							"{$wpdb->prefix}ds_matches",
							[ 'court_id' => $court_ids[ $i % count( $court_ids ) ] ],
							[ 'id'       => (int) $match_id ],
							[ '%d' ],
							[ '%d' ]
						);
					}

					wp_safe_redirect(
						add_query_arg(
							[ 'notice' => 'courts_reassigned', 'round' => $round_number ],
							home_url( '/panel/torneo/' . $id . '/' )
						)
					);
					exit;
				}
			}
		}

		// ── Cambiar estado de un partido ─────────────────────────────────
		if ( $_SERVER['REQUEST_METHOD'] === 'POST' && isset( $_POST['st_change_match_status'] ) ) {
```

- [ ] **Step 3: Agregar el notice en el template**

Abrir `soccertrack/templates/panel/torneo-detalle.php`. Buscar el bloque:

```php
<?php if ( ( $notice ?? '' ) === 'tournament_renamed' ) : ?>
	<div class="st-alert st-alert--success">✅ <?php esc_html_e( 'Nombre del torneo actualizado.', 'soccertrack' ); ?></div>
<?php endif; ?>
```

Agregar inmediatamente después:

```php
<?php if ( ( $notice ?? '' ) === 'courts_reassigned' ) : ?>
	<div class="st-alert st-alert--success">✅ <?php
	/* translators: %d: número de fecha/ronda */
	printf( esc_html__( 'Canchas reasignadas para la fecha %d.', 'soccertrack' ), (int) ( $_GET['round'] ?? 0 ) );
	?></div>
<?php endif; ?>
```

- [ ] **Step 4: Detectar el notice en view_torneo()**

En `TournamentPage.php`, localizar donde se lee `$notice` desde la URL (busca `$_GET['notice']` o la asignación de `$notice` antes del render). Debe incluir `courts_reassigned` como valor válido.

Buscar en `view_torneo()`:

```php
$notice = sanitize_key( $_GET['notice'] ?? '' );
```

Si esa línea ya existe, no hay nada que cambiar — `courts_reassigned` ya será reconocido porque la comparación es string directa en el template.

Si no existe y `$notice` se asigna de otra forma, verificar que la variable llega al template y no se sobreescribe.

- [ ] **Step 5: Verificar manualmente (sin test automatizado)**

En el sitio local:
1. Ir a `/panel/torneo/1/`
2. Abrir DevTools → Network
3. En el fixture, identificar una ronda con partidos
4. Construir manualmente un POST a la misma URL con:
   - `st_reassign_round_courts=1`
   - `round_number=1`
   - `court_ids[]=<id_cancha_valida>`
   - `_wpnonce=<nonce_valido>`
5. Verificar en la DB que `ds_matches.court_id` cambió para los partidos de esa ronda
6. Verificar que aparece el alert verde al redirigir

- [ ] **Step 6: Commit**

```bash
git add soccertrack/includes/Public/TournamentPage.php soccertrack/templates/panel/torneo-detalle.php
git commit -m "feat(fixture): handler POST st_reassign_round_courts con validación de canchas"
```

---

## Task 2: UI — Fila separadora de ronda con botón "⚙ Canchas"

**Files:**
- Modify: `soccertrack/templates/panel/torneo-detalle.php` (sección del `<tbody>` del fixture, bloque `foreach $matches`)

**Interfaces:**
- Consumes: `$courts_by_venue` (ya disponible en el template — array[venue_id] → array de courts con 'id' y 'court_name'), `$tournament['id']`, `$is_locked`
- Produces: una fila `<tr>` de cabecera por cada cambio de `round_number` en fase regular, con panel inline de checkboxes que hace POST al handler de Task 1

**Contexto del código actual:**

El fixture es una `<table>` con `foreach ( $matches as $m )`. Actualmente hay separadores de sección (fase/bracket) usando la variable `$prev_section`. La columna "Fecha" (primera columna) muestra el `round_number` en cada fila de partido regular.

No existe ninguna fila de cabecera de ronda — solo el número en cada celda.

- [ ] **Step 1: Agregar tracking de ronda previa**

En el bloque PHP dentro del `<tbody>`, justo después de donde se declara `$prev_section`:

```php
$prev_section    = null;
$cols            = 9; // número de columnas de la tabla
```

Agregar:

```php
$prev_section    = null;
$prev_round      = null;   // ← AGREGAR
$cols            = 9; // número de columnas de la tabla
```

- [ ] **Step 2: Calcular la lista plana de canchas del torneo**

Antes del `foreach ( $matches as $m )`, agregar:

```php
// Lista plana de todas las canchas del torneo para el panel de reasignación.
$all_tournament_courts = [];
foreach ( $courts_by_venue as $v_courts ) {
	foreach ( $v_courts as $c ) {
		$all_tournament_courts[] = $c;
	}
}
```

- [ ] **Step 3: Inyectar fila de cabecera de ronda con botón**

Dentro del `foreach ( $matches as $m )`, después del bloque que inserta la fila separadora de sección (el `if ( $section_key !== $prev_section )` que termina con `$prev_section = $section_key; endif;`) y antes del `<tr>` del partido, agregar:

```php
				<?php
				// Fila de cabecera de ronda (solo fase regular, cuando cambia el número).
				if ( $phase_cur === 'regular' && $m['round_number'] !== $prev_round ) :
					$rn = (int) $m['round_number'];
				?>
				<tr id="st-round-row-<?php echo $rn; ?>">
					<td colspan="<?php echo (int) $cols; ?>"
						style="padding:6px 14px;background:#f0f4f0;border-top:1px solid #c8d8c8;border-bottom:1px solid #c8d8c8">
						<div style="display:flex;align-items:center;gap:10px">
							<span style="font-weight:700;font-size:.82rem;color:#1a5c1a">
								<?php
								/* translators: %d: número de jornada */
								printf( esc_html__( 'Jornada %d', 'soccertrack' ), $rn );
								?>
							</span>
							<?php if ( empty( $is_locked ) && ! empty( $all_tournament_courts ) ) : ?>
							<button
								type="button"
								class="st-btn st-btn--sm st-btn--secondary st-round-courts-toggle"
								data-round="<?php echo $rn; ?>"
								style="padding:2px 8px;font-size:.78rem"
							>⚙ <?php esc_html_e( 'Canchas', 'soccertrack' ); ?></button>
							<?php endif; ?>
						</div>

						<?php if ( empty( $is_locked ) && ! empty( $all_tournament_courts ) ) : ?>
						<div
							id="st-courts-panel-<?php echo $rn; ?>"
							style="display:none;margin-top:10px;padding:12px;background:#fff;border:1px solid #c8d8c8;border-radius:6px"
						>
							<p style="margin:0 0 8px;font-size:.82rem;font-weight:600;color:#1a5c1a">
								<?php esc_html_e( 'Canchas disponibles para esta fecha:', 'soccertrack' ); ?>
							</p>
							<form method="post" style="display:flex;flex-wrap:wrap;gap:10px 20px;align-items:flex-end">
								<?php wp_nonce_field( 'st_reassign_round_courts_' . $tournament['id'] . '_' . $rn ); ?>
								<input type="hidden" name="st_reassign_round_courts" value="1">
								<input type="hidden" name="tournament_id" value="<?php echo esc_attr( (string) $tournament['id'] ); ?>">
								<input type="hidden" name="round_number" value="<?php echo esc_attr( (string) $rn ); ?>">

								<div style="display:flex;flex-wrap:wrap;gap:8px 16px;flex:1">
								<?php foreach ( $all_tournament_courts as $court ) : ?>
									<label style="display:flex;align-items:center;gap:5px;font-size:.82rem;cursor:pointer">
										<input
											type="checkbox"
											name="court_ids[]"
											value="<?php echo esc_attr( (string) $court['id'] ); ?>"
											checked
										>
										<?php echo esc_html( $court['court_name'] ); ?>
									</label>
								<?php endforeach; ?>
								</div>

								<div style="display:flex;gap:8px;flex-shrink:0">
									<button type="submit" class="st-btn st-btn--sm st-btn--primary">
										<?php esc_html_e( 'Reasignar', 'soccertrack' ); ?>
									</button>
									<button
										type="button"
										class="st-btn st-btn--sm st-btn--secondary st-round-courts-cancel"
										data-round="<?php echo $rn; ?>"
									>
										<?php esc_html_e( 'Cancelar', 'soccertrack' ); ?>
									</button>
								</div>
							</form>
						</div>
						<?php endif; ?>
					</td>
				</tr>
				<?php
					$prev_round = $m['round_number'];
				endif;
				?>
```

- [ ] **Step 4: Agregar el JS de toggle**

Al final del template, antes del cierre `</div>` de la sección de fixture (cerca de donde está el bloque `<script>` de `st-gen-fixture-btn`), agregar:

```html
<script>
(function () {
	document.addEventListener('click', function (e) {
		// Toggle abrir panel
		if ( e.target.closest('.st-round-courts-toggle') ) {
			var btn   = e.target.closest('.st-round-courts-toggle');
			var round = btn.dataset.round;
			var panel = document.getElementById('st-courts-panel-' + round);
			if ( panel ) {
				panel.style.display = panel.style.display === 'none' ? 'block' : 'none';
			}
		}
		// Cancelar — cerrar panel
		if ( e.target.closest('.st-round-courts-cancel') ) {
			var btn   = e.target.closest('.st-round-courts-cancel');
			var round = btn.dataset.round;
			var panel = document.getElementById('st-courts-panel-' + round);
			if ( panel ) panel.style.display = 'none';
		}
	});
}());
</script>
```

- [ ] **Step 5: Verificar manualmente en el navegador**

1. Ir a `http://localhost:8088/panel/torneo/1/`
2. Scroll hasta el fixture
3. Verificar que cada jornada de fase regular tiene una fila de cabecera "Jornada N" con botón "⚙ Canchas"
4. Hacer clic en "⚙ Canchas" → debe expandirse el panel con checkboxes de canchas
5. Desmarcar algunas canchas → clic "Reasignar"
6. Verificar alert verde "Canchas reasignadas para la fecha N."
7. Verificar en la DB: `SELECT id, court_id FROM wp_ds_matches WHERE tournament_id=1 AND round_number=1 ORDER BY id`
   — los `court_id` deben rotar entre las canchas seleccionadas
8. En torneo bloqueado (`status='completed'`): verificar que el botón "⚙ Canchas" no aparece

- [ ] **Step 6: Restart container y verificar**

```bash
docker restart soccertrack_wp
```

Repetir el paso 5 para confirmar que OPcache no interfiere.

- [ ] **Step 7: Commit**

```bash
git add soccertrack/templates/panel/torneo-detalle.php
git commit -m "feat(fixture): UI reasignación de canchas por ronda — botón y panel inline"
```

---

## Self-Review

**Cobertura del spec:**
- ✅ Botón "⚙ Canchas" por ronda en fase regular
- ✅ Panel inline con checkboxes de todas las canchas del torneo
- ✅ Todas marcadas por defecto
- ✅ Botón Reasignar + Cancelar
- ✅ Alert verde post-redirect con número de ronda
- ✅ Handler POST con nonce, capability check, validación de court_ids contra torneo
- ✅ Rotación circular sobre canchas seleccionadas
- ✅ Solo actualiza court_id, no match_datetime
- ✅ No aparece si torneo bloqueado (`$is_locked`)
- ✅ `$courts_by_venue` ya disponible en template — sin queries adicionales

**Casos edge cubiertos:**
- Sin canchas seleccionadas → error "Debes seleccionar al menos una cancha"
- court_ids manipulados (no pertenecen al torneo) → `array_intersect` los filtra → error
- `round_number=0` → absint() devuelve 0, falla validación `! $round_number`
