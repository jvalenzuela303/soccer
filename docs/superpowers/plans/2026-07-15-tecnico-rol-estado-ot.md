# Rol técnico restringido + estados intermedios OT — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Restringir el rol `tecnico_calibracion` a ver solo sus OTs asignadas con acceso limitado a campos (sin montos), y agregar dos estados intermedios (`en_ejecucion`, `listo_revision`) al flujo de la OT.

**Architecture:** Fuente única de verdad en `get_estados_servicio()` (Helpers). Bifurcación de servidor en `procesar_guardar_evento()` para el técnico — guarda solo los 4 campos permitidos. Ruta `/panel/evento/{id}/` redirige al admin a `/panel/ot/{id}/` y muestra vista restringida al técnico. El filtro de OTs del técnico usa `calibratrack_tecnico_responsable` meta (no `post_author`).

**Tech Stack:** PHP 7.4, WordPress 6.8.5, MariaDB 10.6. Sin librerías nuevas.

## Global Constraints

- PHP 7.4: sin `enum`, `match`, `?->`, constructor promotion, union types, argumentos nombrados
- Estándar WPCS: funciones de escape en toda salida HTML (`esc_html`, `esc_attr`, `esc_url`)
- Nonces en todos los formularios; `wp_verify_nonce()` en todos los handlers POST
- El técnico NO puede asignar estado `completado` — validado server-side, no solo en UI
- Los campos de costo NO se guardan para técnicos — ignorados server-side
- i18n: todo texto visible usa `__()` / `esc_html_e()` con text domain `calibratrack`

---

### Task 1: Foundation — `get_estados_servicio()` + CSS badge classes

**Files:**
- Modify: `calibratrack/includes/class-calibratrack-helpers.php` (después de línea 63, al cierre de `get_estados_equipo()`)
- Modify: `calibratrack/assets/css/tecnico.css` (después de línea 176)

**Interfaces:**
- Produces: `CalibraTrack_Helpers::get_estados_servicio(): array<string, array{label:string, clase:string}>` — retorna los 4 estados en orden de flujo. Todas las tareas siguientes usan este método.

- [ ] **Step 1: Add `get_estados_servicio()` to Helpers**

En `class-calibratrack-helpers.php`, añadir después del cierre de `get_estados_equipo()` (línea 63):

```php
	/**
	 * Devuelve los 4 estados posibles de una Orden de Trabajo.
	 * Fuente única de verdad — ningún otro archivo define esta lista.
	 *
	 * @return array<string, array{label: string, clase: string}>
	 */
	public static function get_estados_servicio() {
		return array(
			'en_proceso'     => array(
				'label' => __( 'En proceso', 'calibratrack' ),
				'clase' => 'ct-badge--por-vencer',
			),
			'en_ejecucion'   => array(
				'label' => __( 'En ejecución', 'calibratrack' ),
				'clase' => 'ct-badge--en-ejecucion',
			),
			'listo_revision' => array(
				'label' => __( 'Listo para revisión', 'calibratrack' ),
				'clase' => 'ct-badge--listo-revision',
			),
			'completado'     => array(
				'label' => __( 'Completado', 'calibratrack' ),
				'clase' => 'ct-badge--vigente',
			),
		);
	}
```

- [ ] **Step 2: Add CSS badge classes to tecnico.css**

En `tecnico.css`, añadir después de la línea que contiene `.ct-badge--sin-evento` (línea 176):

```css
.ct-badge--en-ejecucion   { background: #fef3c7; color: #92400e; border: 1px solid #fcd34d; }
.ct-badge--listo-revision { background: #ede9fe; color: #5b21b6; border: 1px solid #c4b5fd; }
```

- [ ] **Step 3: Verify manually**

Abrir cualquier página del panel en el browser y revisar que no haya errores PHP. Los badges nuevos no aparecerán aún (se usan en tareas siguientes).

- [ ] **Step 4: Commit**

```bash
cd /home/jvalenzuela/Desarollo/truetech
git add calibratrack/includes/class-calibratrack-helpers.php calibratrack/assets/css/tecnico.css
git commit -m "feat: add get_estados_servicio() and OT state badge CSS classes"
```

---

### Task 2: Server security — technician save path

**Files:**
- Modify: `calibratrack/includes/class-calibratrack-panel.php`
  - Función `procesar_guardar_evento()` (~línea 1024)
  - Añadir método privado `procesar_guardar_evento_tecnico()`

**Interfaces:**
- Consumes: `CalibraTrack_Meta_Keys::EVENTO_ESTADO_SERVICIO`, `EVENTO_DESCRIPCION_TRABAJO`, `EVENTO_OBSERVACIONES`, `EVENTO_EQUIPO_ID`
- Produces: `procesar_guardar_evento_tecnico(int $evento_id, array &$errors, array &$valores): bool` — guarda solo los 4 campos permitidos, retorna `true` en éxito.

- [ ] **Step 1: Add technician branch at start of `procesar_guardar_evento()`**

Localizar la función `procesar_guardar_evento()` (~línea 1024). Justo después del bloque de verificación de nonce (que termina con `return false;` en ~línea 1030), insertar:

```php
		// Técnico editando una OT existente: solo campos permitidos.
		if ( $evento_id > 0 && ! current_user_can( 'manage_options' ) ) {
			return self::procesar_guardar_evento_tecnico( $evento_id, $errors, $valores );
		}
```

- [ ] **Step 2: Update 2-state validation to 4-state in remaining flow**

En la misma función, localizar (~línea 1044):
```php
			'estado_servicio'     => in_array( $estado_raw, array( 'en_proceso', 'completado' ), true ) ? $estado_raw : 'en_proceso',
```

Reemplazar con:
```php
			'estado_servicio'     => in_array( $estado_raw, array( 'en_proceso', 'en_ejecucion', 'listo_revision', 'completado' ), true ) ? $estado_raw : 'en_proceso',
```

- [ ] **Step 3: Add private method `procesar_guardar_evento_tecnico()`**

Añadir antes del cierre de la clase (antes del último `}`), después del bloque de `procesar_guardar_evento()`:

```php
	/**
	 * Guarda únicamente los campos que el técnico puede editar:
	 * descripcion_trabajo, observaciones, estado_servicio (max listo_revision),
	 * evidencia fotográfica y documentos adjuntos (vía procesar_uploads).
	 *
	 * Los campos de costo, equipo, fechas y N° OT NO se tocan.
	 *
	 * @param int   $evento_id Post ID de la OT.
	 * @param array $errors    Por referencia.
	 * @param array $valores   Por referencia.
	 * @return bool
	 */
	private static function procesar_guardar_evento_tecnico( $evento_id, &$errors, &$valores ) {
		// Leer estado actual en BD como fallback.
		$estado_db = (string) get_post_meta( $evento_id, CalibraTrack_Meta_Keys::EVENTO_ESTADO_SERVICIO, true );
		if ( empty( $estado_db ) ) {
			$estado_db = 'en_proceso';
		}

		$estado_raw = isset( $_POST['estado_servicio'] )
			? sanitize_key( wp_unslash( $_POST['estado_servicio'] ) )
			: '';

		// Técnico solo puede asignar estos tres estados — jamás 'completado'.
		$estados_permitidos = array( 'en_proceso', 'en_ejecucion', 'listo_revision' );
		$estado_nuevo = in_array( $estado_raw, $estados_permitidos, true ) ? $estado_raw : $estado_db;

		$valores = array(
			'descripcion_trabajo' => sanitize_textarea_field( isset( $_POST['descripcion_trabajo'] ) ? wp_unslash( $_POST['descripcion_trabajo'] ) : '' ),
			'observaciones'       => sanitize_textarea_field( isset( $_POST['observaciones'] )       ? wp_unslash( $_POST['observaciones'] )       : '' ),
			'estado_servicio'     => $estado_nuevo,
		);

		update_post_meta( $evento_id, CalibraTrack_Meta_Keys::EVENTO_DESCRIPCION_TRABAJO, $valores['descripcion_trabajo'] );
		update_post_meta( $evento_id, CalibraTrack_Meta_Keys::EVENTO_OBSERVACIONES,       $valores['observaciones'] );
		update_post_meta( $evento_id, CalibraTrack_Meta_Keys::EVENTO_ESTADO_SERVICIO,     $valores['estado_servicio'] );

		self::procesar_uploads( $evento_id );

		$equipo_id = (int) get_post_meta( $evento_id, CalibraTrack_Meta_Keys::EVENTO_EQUIPO_ID, true );
		if ( $equipo_id > 0 ) {
			delete_transient( 'calibratrack_vigencia_' . $equipo_id );
		}

		return true;
	}
```

- [ ] **Step 4: Verify — no PHP errors on panel pages**

Visitar `/panel/` como admin y como técnico; verificar que no haya errores PHP en pantalla.

- [ ] **Step 5: Commit**

```bash
git add calibratrack/includes/class-calibratrack-panel.php
git commit -m "feat: split procesar_guardar_evento for technician — restricts to 4 allowed fields, blocks completado state"
```

---

### Task 3: Admin OT — `guardar_ot()` 4-state + routing in `handle_editar_evento()`

**Files:**
- Modify: `calibratrack/includes/class-calibratrack-panel.php`
  - Función `guardar_ot()` (~línea 861 y ~línea 1004)
  - Función `handle_editar_evento()` (~línea 462)

**Interfaces:**
- Consumes: `current_user_can('manage_options')`, `CalibraTrack_Meta_Keys::EVENTO_TECNICO_RESPONSABLE`
- Produces: `handle_editar_evento()` pasa `$es_tecnico = true` al template

- [ ] **Step 1: Update state validation in `guardar_ot()`**

Localizar en `guardar_ot()` (~línea 861):
```php
		$estado_valido = in_array( $estado_raw, array( 'en_proceso', 'completado' ), true )
			? $estado_raw
			: 'en_proceso';
```

Reemplazar con:
```php
		$estado_valido = in_array( $estado_raw, array( 'en_proceso', 'en_ejecucion', 'listo_revision', 'completado' ), true )
			? $estado_raw
			: 'en_proceso';
```

- [ ] **Step 2: Fix certificate email condition in `guardar_ot()`**

Localizar (~línea 1004):
```php
			if ( 'en_proceso' === $estado_anterior && class_exists( 'CalibraTrack_Mailer' ) ) {
				CalibraTrack_Mailer::send_certificado_a_cliente( $evento_id );
			}
```

Reemplazar con (enviar el email al pasar a completado desde CUALQUIER estado anterior):
```php
			if ( 'completado' !== $estado_anterior && class_exists( 'CalibraTrack_Mailer' ) ) {
				CalibraTrack_Mailer::send_certificado_a_cliente( $evento_id );
			}
```

- [ ] **Step 3: Rewrite auth block in `handle_editar_evento()`**

Localizar (~línea 462):
```php
		// Verificar que el evento pertenece al técnico actual o el usuario es admin.
		$autor_id = (int) get_post_field( 'post_author', $evento_id );
		if ( $autor_id !== get_current_user_id() && ! current_user_can( 'edit_others_eventos_servicio' ) ) {
			wp_redirect( home_url( '/panel/eventos/' ) );
			exit;
		}
```

Reemplazar con:
```php
		// Admin → redirigir a vista completa de OT.
		if ( current_user_can( 'manage_options' ) ) {
			wp_redirect( home_url( '/panel/ot/' . $evento_id . '/' ) );
			exit;
		}

		// Técnico: verificar que la OT le esté asignada por meta o sea el autor.
		$tecnico_asignado = (int) get_post_meta( $evento_id, CalibraTrack_Meta_Keys::EVENTO_TECNICO_RESPONSABLE, true );
		$autor_id         = (int) get_post_field( 'post_author', $evento_id );
		$user_id          = get_current_user_id();
		if ( $tecnico_asignado !== $user_id && $autor_id !== $user_id ) {
			wp_redirect( home_url( '/panel/' ) );
			exit;
		}
```

- [ ] **Step 4: Pass `$es_tecnico = true` to template in `handle_editar_evento()`**

Localizar la llamada a `load_template` (~línea 487):
```php
		self::load_template( 'evento-detalle', array(
			'evento_id'    => $evento_id,
			'errors'       => $errors,
			'valores'      => $valores,
			'equipos'      => CalibraTrack_Tecnico::get_equipos_para_select(),
			'es_completado' => $es_completado,
		) );
```

Reemplazar con:
```php
		self::load_template( 'evento-detalle', array(
			'evento_id'     => $evento_id,
			'errors'        => $errors,
			'valores'       => $valores,
			'equipos'       => CalibraTrack_Tecnico::get_equipos_para_select(),
			'es_completado' => $es_completado,
			'es_tecnico'    => true,
		) );
```

- [ ] **Step 5: Verify routing**

Como admin, visitar `/panel/evento/123/` (con un ID real de OT) — debe redirigir a `/panel/ot/123/`. Como técnico asignado a esa OT, debe mostrar el formulario (tarea 5 completa esto).

- [ ] **Step 6: Commit**

```bash
git add calibratrack/includes/class-calibratrack-panel.php
git commit -m "feat: guardar_ot 4-state validation, handle_editar_evento redirects admin and uses tecnico_responsable meta for auth"
```

---

### Task 4: Fix technician OT list — `author` → `calibratrack_tecnico_responsable`

**Files:**
- Modify: `calibratrack/templates/panel/dashboard.php` — sección técnico (else block, ~línea 745)
- Modify: `calibratrack/templates/panel/lista-eventos.php` (~línea 116)

**Interfaces:**
- Consumes: `CalibraTrack_Meta_Keys::EVENTO_TECNICO_RESPONSABLE`, `EVENTO_TIPO_DOCUMENTO`, `EVENTO_ESTADO_SERVICIO`
- Consumes: `CalibraTrack_Helpers::get_estados_servicio()`

- [ ] **Step 1: Replace `author` filter in dashboard.php — `$hay_filtros` branch**

En la sección del técnico (~línea 759), localizar:
```php
		$query_args = array(
			'post_type'      => 'evento_servicio',
			'post_status'    => 'publish',
			'author'         => get_current_user_id(),
			'posts_per_page' => $per_page,
			'paged'          => $paged,
			'orderby'        => 'date',
			'order'          => 'DESC',
			'meta_query'     => array( 'relation' => 'AND' ),
		);
```

Reemplazar con:
```php
		$query_args = array(
			'post_type'      => 'evento_servicio',
			'post_status'    => 'publish',
			'posts_per_page' => $per_page,
			'paged'          => $paged,
			'orderby'        => 'date',
			'order'          => 'DESC',
			'meta_query'     => array(
				'relation' => 'AND',
				array(
					'key'   => CalibraTrack_Meta_Keys::EVENTO_TIPO_DOCUMENTO,
					'value' => 'ot',
				),
				array(
					'key'     => CalibraTrack_Meta_Keys::EVENTO_TECNICO_RESPONSABLE,
					'value'   => get_current_user_id(),
					'compare' => '=',
					'type'    => 'NUMERIC',
				),
			),
		);
```

- [ ] **Step 2: Replace `author` filter in dashboard.php — no-filters branch**

Localizar (~línea 866):
```php
		$eventos = get_posts( array(
			'post_type'      => 'evento_servicio',
			'post_status'    => 'publish',
			'author'         => get_current_user_id(),
			'posts_per_page' => 10,
			'orderby'        => 'date',
			'order'          => 'DESC',
		) );
```

Reemplazar con:
```php
		$eventos = get_posts( array(
			'post_type'      => 'evento_servicio',
			'post_status'    => 'publish',
			'posts_per_page' => 10,
			'orderby'        => 'date',
			'order'          => 'DESC',
			'meta_query'     => array(
				'relation' => 'AND',
				array(
					'key'   => CalibraTrack_Meta_Keys::EVENTO_TIPO_DOCUMENTO,
					'value' => 'ot',
				),
				array(
					'key'     => CalibraTrack_Meta_Keys::EVENTO_TECNICO_RESPONSABLE,
					'value'   => get_current_user_id(),
					'compare' => '=',
					'type'    => 'NUMERIC',
				),
			),
		) );
```

- [ ] **Step 3: Update tech filter query logic — replace vigencia states with OT service state**

En dashboard.php sección técnico, localizar el bloque `if ( '' !== $filtro_estado )` (~línea 778) que tiene un `switch` con casos `vigente`, `por_vencer`, `vencido`, `sin_evento`. Reemplazar todo ese bloque con:

```php
		if ( '' !== $filtro_estado ) {
			$query_args['meta_query'][] = array(
				'key'     => CalibraTrack_Meta_Keys::EVENTO_ESTADO_SERVICIO,
				'value'   => $filtro_estado,
				'compare' => '=',
			);
		}
```

- [ ] **Step 4: Update tech filter dropdown — replace vigencia options with OT service states**

En dashboard.php sección técnico, localizar el `<select name="estado">` (~línea 936):
```php
				<select name="estado" class="ct-select ct-filter-select">
					<option value=""><?php esc_html_e( 'Todos los estados', 'calibratrack' ); ?></option>
					<option value="vigente"    ...>
					<option value="por_vencer" ...>
					<option value="vencido"    ...>
					<option value="sin_evento" ...>
				</select>
```

Reemplazar con:
```php
				<select name="estado" class="ct-select ct-filter-select">
					<option value=""><?php esc_html_e( 'Todos los estados', 'calibratrack' ); ?></option>
					<?php foreach ( CalibraTrack_Helpers::get_estados_servicio() as $slug => $cfg ) : ?>
						<option value="<?php echo esc_attr( $slug ); ?>" <?php selected( $filtro_estado, $slug ); ?>>
							<?php echo esc_html( $cfg['label'] ); ?>
						</option>
					<?php endforeach; ?>
				</select>
```

- [ ] **Step 5: Update tech row badge — replace vigencia with OT service state**

En dashboard.php sección técnico, en el `foreach` de la tabla (~línea 982):
```php
							$estado      = $proxima ? CalibraTrack_Helpers::calcular_estado_vigencia( $proxima ) : 'sin_evento';
							$estados_cfg = array(
								'vigente'    => array( 'clase' => 'ct-badge--vigente',    'label' => __( 'Vigente', 'calibratrack' ) ),
								'por_vencer' => array( 'clase' => 'ct-badge--por-vencer', 'label' => __( 'Por vencer', 'calibratrack' ) ),
								'vencido'    => array( 'clase' => 'ct-badge--vencido',    'label' => __( 'Vencido', 'calibratrack' ) ),
								'sin_evento' => array( 'clase' => 'ct-badge--sin-evento', 'label' => __( 'Sin fecha', 'calibratrack' ) ),
							);
							$estado_info = isset( $estados_cfg[ $estado ] ) ? $estados_cfg[ $estado ] : $estados_cfg['sin_evento'];
```

Reemplazar con:
```php
							$estado_srv     = (string) get_post_meta( $ev->ID, CalibraTrack_Meta_Keys::EVENTO_ESTADO_SERVICIO, true );
							if ( empty( $estado_srv ) ) { $estado_srv = 'en_proceso'; }
							$estados_srv_cfg = CalibraTrack_Helpers::get_estados_servicio();
							$estado_info    = isset( $estados_srv_cfg[ $estado_srv ] ) ? $estados_srv_cfg[ $estado_srv ] : $estados_srv_cfg['en_proceso'];
```

- [ ] **Step 6: Fix `author` filter in lista-eventos.php**

En `lista-eventos.php` (~línea 116):
```php
	// El técnico solo ve sus propios eventos; el admin ve todos.
	if ( ! $es_admin ) {
		$query_args['author'] = get_current_user_id();
	}
```

Reemplazar con:
```php
	// El técnico solo ve OTs donde es el responsable asignado.
	if ( ! $es_admin ) {
		$query_args['meta_query'][] = array(
			'key'     => CalibraTrack_Meta_Keys::EVENTO_TECNICO_RESPONSABLE,
			'value'   => get_current_user_id(),
			'compare' => '=',
			'type'    => 'NUMERIC',
		);
	}
```

- [ ] **Step 7: Verify as technician**

Iniciar sesión como técnico. `/panel/` debe mostrar solo las OTs donde ese usuario está en el meta `calibratrack_tecnico_responsable`. Verificar que el filtro de estado funciona con los 4 valores nuevos.

- [ ] **Step 8: Commit**

```bash
git add calibratrack/templates/panel/dashboard.php calibratrack/templates/panel/lista-eventos.php
git commit -m "fix: OT list for technician uses calibratrack_tecnico_responsable meta instead of post_author; filter by OT service state"
```

---

### Task 5: Redesign `evento-detalle.php` — restricted tech view

**Files:**
- Modify: `calibratrack/templates/panel/evento-detalle.php`

**Interfaces:**
- Consumes: `$es_tecnico` (bool, pasado desde `handle_editar_evento()`)
- Consumes: `$valores` (array con `descripcion_trabajo`, `observaciones`, `estado_servicio`)
- Consumes: `CalibraTrack_Helpers::get_estados_servicio()`, `CalibraTrack_Meta_Keys::*`

- [ ] **Step 1: Add `$es_tecnico` variable at top of template**

Después de la línea `$es_completado = ! empty( $es_completado );` (~línea 8), añadir:
```php
$es_tecnico    = ! empty( $es_tecnico );
```

- [ ] **Step 2: Replace the `else` block (non-completado view)**

El bloque `<?php else : ?>` (~línea 80) hasta `<?php endif; ?>` (~línea 127) debe reemplazarse con el siguiente código completo:

```php
<?php else : ?>

	<?php if ( $cert_id ) : ?>
		<div class="ct-cert-disponible">
			<span><?php esc_html_e( 'Certificado PDF disponible', 'calibratrack' ); ?></span>
			<a href="<?php echo esc_url( home_url( '/panel/evento/' . $evento_id . '/certificado/' ) ); ?>" target="_blank" class="ct-btn ct-btn--sm">
				<?php esc_html_e( 'Descargar / Ver PDF', 'calibratrack' ); ?>
			</a>
		</div>
	<?php endif; ?>

	<?php if ( $es_tecnico ) : ?>
		<?php
		// Datos de la OT para la tarjeta informativa (solo lectura).
		$ot_equipo_id  = (int) get_post_meta( $evento_id, CalibraTrack_Meta_Keys::EVENTO_EQUIPO_ID, true );
		$ot_numero_ot  = (string) get_post_meta( $evento_id, CalibraTrack_Meta_Keys::EVENTO_NUMERO_OT, true );
		$ot_tipo       = (string) get_post_meta( $evento_id, CalibraTrack_Meta_Keys::EVENTO_TIPO, true );
		$ot_fecha      = (string) get_post_meta( $evento_id, CalibraTrack_Meta_Keys::EVENTO_FECHA_EJECUCION, true );
		$ot_proxima    = (string) get_post_meta( $evento_id, CalibraTrack_Meta_Keys::EVENTO_PROXIMA_FECHA_CONTROL, true );
		$ot_falla      = (string) get_post_meta( $evento_id, CalibraTrack_Meta_Keys::EVENTO_FALLA_REPORTADA, true );
		$ot_oi_id      = (int) get_post_meta( $evento_id, CalibraTrack_Meta_Keys::EVENTO_INGRESO_RELACIONADO_ID, true );
		$ot_serie      = $ot_equipo_id ? (string) get_post_meta( $ot_equipo_id, CalibraTrack_Meta_Keys::EQUIPO_SERIE, true ) : '—';
		$ot_marca      = $ot_equipo_id ? (string) get_post_meta( $ot_equipo_id, CalibraTrack_Meta_Keys::EQUIPO_MARCA, true ) : '';
		$ot_modelo     = $ot_equipo_id ? (string) get_post_meta( $ot_equipo_id, CalibraTrack_Meta_Keys::EQUIPO_MODELO, true ) : '';
		$ot_oi_numero  = $ot_oi_id ? (string) get_post_meta( $ot_oi_id, CalibraTrack_Meta_Keys::EVENTO_NUMERO_OI, true ) : '';
		$tipos_map     = CalibraTrack_Helpers::get_tipos_evento();
		$ot_tipo_label = isset( $tipos_map[ $ot_tipo ] ) ? $tipos_map[ $ot_tipo ] : $ot_tipo;
		$estados_srv   = CalibraTrack_Helpers::get_estados_servicio();
		$ot_estado_raw = (string) get_post_meta( $evento_id, CalibraTrack_Meta_Keys::EVENTO_ESTADO_SERVICIO, true );
		if ( empty( $ot_estado_raw ) ) { $ot_estado_raw = 'en_proceso'; }
		$ot_estado_cfg = isset( $estados_srv[ $ot_estado_raw ] ) ? $estados_srv[ $ot_estado_raw ] : $estados_srv['en_proceso'];
		$v_edit = function( $key, $default = '' ) use ( $valores ) {
			return isset( $valores[ $key ] ) ? $valores[ $key ] : $default;
		};
		$estado_actual_form = $v_edit( 'estado_servicio', $ot_estado_raw );
		if ( empty( $estado_actual_form ) ) { $estado_actual_form = 'en_proceso'; }
		?>

		<!-- Tarjeta informativa (solo lectura) -->
		<div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px;padding:20px;margin-bottom:24px;">
			<h2 style="font-size:15px;font-weight:700;margin:0 0 14px;color:#1e293b;"><?php esc_html_e( 'Información de la OT', 'calibratrack' ); ?></h2>
			<table style="width:100%;border-collapse:collapse;font-size:14px;">
				<tr>
					<td style="padding:6px 10px;font-weight:600;width:180px;color:#374151;"><?php esc_html_e( 'N° OT', 'calibratrack' ); ?></td>
					<td style="padding:6px 10px;"><?php echo esc_html( $ot_numero_ot ?: '—' ); ?></td>
				</tr>
				<?php if ( $ot_oi_numero ) : ?>
				<tr style="background:#f3f4f6;">
					<td style="padding:6px 10px;font-weight:600;color:#374151;"><?php esc_html_e( 'OI vinculada', 'calibratrack' ); ?></td>
					<td style="padding:6px 10px;"><?php echo esc_html( $ot_oi_numero ); ?></td>
				</tr>
				<?php endif; ?>
				<tr>
					<td style="padding:6px 10px;font-weight:600;color:#374151;"><?php esc_html_e( 'Equipo', 'calibratrack' ); ?></td>
					<td style="padding:6px 10px;"><?php echo esc_html( trim( $ot_serie . ' — ' . $ot_marca . ' ' . $ot_modelo ) ); ?></td>
				</tr>
				<tr style="background:#f3f4f6;">
					<td style="padding:6px 10px;font-weight:600;color:#374151;"><?php esc_html_e( 'Tipo de servicio', 'calibratrack' ); ?></td>
					<td style="padding:6px 10px;"><?php echo esc_html( $ot_tipo_label ); ?></td>
				</tr>
				<tr>
					<td style="padding:6px 10px;font-weight:600;color:#374151;"><?php esc_html_e( 'Fecha ejecución', 'calibratrack' ); ?></td>
					<td style="padding:6px 10px;"><?php echo esc_html( $ot_fecha ?: '—' ); ?></td>
				</tr>
				<tr style="background:#f3f4f6;">
					<td style="padding:6px 10px;font-weight:600;color:#374151;"><?php esc_html_e( 'Próx. control', 'calibratrack' ); ?></td>
					<td style="padding:6px 10px;"><?php echo esc_html( $ot_proxima ?: '—' ); ?></td>
				</tr>
				<?php if ( $ot_falla ) : ?>
				<tr>
					<td style="padding:6px 10px;font-weight:600;color:#374151;"><?php esc_html_e( 'Falla reportada', 'calibratrack' ); ?></td>
					<td style="padding:6px 10px;white-space:pre-wrap;"><?php echo esc_html( $ot_falla ); ?></td>
				</tr>
				<?php endif; ?>
				<tr style="background:#f3f4f6;">
					<td style="padding:6px 10px;font-weight:600;color:#374151;"><?php esc_html_e( 'Estado actual', 'calibratrack' ); ?></td>
					<td style="padding:6px 10px;">
						<span class="ct-badge <?php echo esc_attr( $ot_estado_cfg['clase'] ); ?>">
							<?php echo esc_html( $ot_estado_cfg['label'] ); ?>
						</span>
					</td>
				</tr>
			</table>
		</div>

		<!-- Formulario técnico: solo campos editables -->
		<?php if ( ! empty( $errors['general'] ) ) : ?>
			<div class="ct-alert ct-alert--error" role="alert"><?php echo esc_html( $errors['general'] ); ?></div>
		<?php endif; ?>

		<form method="post" action="" enctype="multipart/form-data" class="ct-form" novalidate>
			<?php wp_nonce_field( 'calibratrack_tecnico_evento' ); ?>

			<div class="ct-field">
				<label for="ct-descripcion" class="ct-label"><?php esc_html_e( 'Descripción del trabajo / servicio realizado', 'calibratrack' ); ?></label>
				<textarea id="ct-descripcion" name="descripcion_trabajo" class="ct-textarea" rows="5"><?php echo esc_textarea( $v_edit( 'descripcion_trabajo' ) ); ?></textarea>
			</div>

			<div class="ct-field">
				<label for="ct-observaciones" class="ct-label"><?php esc_html_e( 'Observaciones', 'calibratrack' ); ?></label>
				<textarea id="ct-observaciones" name="observaciones" class="ct-textarea" rows="3"><?php echo esc_textarea( $v_edit( 'observaciones' ) ); ?></textarea>
			</div>

			<!-- Evidencia fotográfica -->
			<div class="ct-field">
				<label for="ct-fotos" class="ct-label"><?php esc_html_e( 'Evidencia fotográfica', 'calibratrack' ); ?></label>
				<?php
				$fotos_raw = get_post_meta( $evento_id, CalibraTrack_Meta_Keys::EVENTO_EVIDENCIA_FOTOGRAFICA, true );
				$fotos_ids = json_decode( (string) $fotos_raw, true );
				if ( is_array( $fotos_ids ) && ! empty( $fotos_ids ) ) {
					echo '<div style="display:flex;flex-wrap:wrap;gap:8px;margin-bottom:8px;">';
					foreach ( $fotos_ids as $fid ) {
						$thumb = wp_get_attachment_image_src( $fid, 'thumbnail' );
						if ( $thumb ) {
							echo '<img src="' . esc_url( $thumb[0] ) . '" style="width:80px;height:80px;object-fit:cover;border-radius:4px;border:1px solid #e5e7eb;">';
						}
					}
					echo '</div>';
					echo '<p class="ct-field-help">' . esc_html__( 'Fotos ya adjuntas. Los nuevos archivos se agregarán.', 'calibratrack' ) . '</p>';
				}
				?>
				<input type="file" id="ct-fotos" name="evidencia_fotografica[]" class="ct-input-file"
					accept="image/jpeg,image/png,image/webp" multiple>
				<p class="ct-field-help"><?php esc_html_e( 'JPG, PNG o WEBP. Puede seleccionar varias fotos.', 'calibratrack' ); ?></p>
			</div>

			<!-- Documentos adjuntos -->
			<div class="ct-field">
				<label for="ct-documentos" class="ct-label"><?php esc_html_e( 'Documentos adjuntos (PDF)', 'calibratrack' ); ?></label>
				<?php
				$docs_raw = get_post_meta( $evento_id, CalibraTrack_Meta_Keys::EVENTO_DOCUMENTOS_ADJUNTOS, true );
				$docs_ids = json_decode( (string) $docs_raw, true );
				if ( is_array( $docs_ids ) && ! empty( $docs_ids ) ) {
					echo '<ul class="ct-docs-lista" style="margin-bottom:8px;list-style:none;padding:0;">';
					foreach ( $docs_ids as $doc_id ) {
						$doc_title = get_the_title( $doc_id );
						if ( $doc_title ) {
							echo '<li style="font-size:13px;padding:3px 0;">' . esc_html( $doc_title ) . '</li>';
						}
					}
					echo '</ul>';
					echo '<p class="ct-field-help">' . esc_html__( 'Documentos ya adjuntos. Los nuevos archivos se agregarán.', 'calibratrack' ) . '</p>';
				}
				?>
				<input type="file" id="ct-documentos" name="documentos_adjuntos[]" class="ct-input-file"
					accept="application/pdf,.pdf" multiple>
				<p class="ct-field-help"><?php esc_html_e( 'PDF. Protocolos, informes u otros documentos.', 'calibratrack' ); ?></p>
			</div>

			<!-- Estado del servicio -->
			<div class="ct-field" style="padding:16px;background:#f0fdf4;border:1px solid #86efac;border-radius:8px;">
				<label for="ct-estado-servicio" class="ct-label" style="font-weight:700;color:#14532d;">
					<?php esc_html_e( 'Estado del servicio', 'calibratrack' ); ?>
				</label>
				<p style="font-size:13px;color:#166534;margin:4px 0 12px;">
					<?php esc_html_e( 'Actualiza el estado para informar al administrador sobre el avance de la OT.', 'calibratrack' ); ?>
				</p>
				<select id="ct-estado-servicio" name="estado_servicio" class="ct-select">
					<option value="en_proceso" <?php selected( $estado_actual_form, 'en_proceso' ); ?>>
						<?php esc_html_e( 'En proceso', 'calibratrack' ); ?>
					</option>
					<option value="en_ejecucion" <?php selected( $estado_actual_form, 'en_ejecucion' ); ?>>
						<?php esc_html_e( 'En ejecución', 'calibratrack' ); ?>
					</option>
					<option value="listo_revision" <?php selected( $estado_actual_form, 'listo_revision' ); ?>>
						<?php esc_html_e( 'Listo para revisión', 'calibratrack' ); ?>
					</option>
				</select>
			</div>

			<div class="ct-form-actions">
				<button type="submit" class="ct-btn ct-btn--primary ct-btn--large">
					<?php esc_html_e( 'Guardar cambios', 'calibratrack' ); ?>
				</button>
				<a href="<?php echo esc_url( home_url( '/panel/' ) ); ?>" class="ct-btn">
					<?php esc_html_e( 'Cancelar', 'calibratrack' ); ?>
				</a>
			</div>
		</form>

	<?php else : // Fallback admin (redirigido normalmente a /panel/ot/{id}/) ?>

		<form method="post" action="" enctype="multipart/form-data" class="ct-form" novalidate>
			<?php include __DIR__ . '/_partials/form-evento-fields.php'; ?>

			<?php
			$estado_actual_form = isset( $valores['estado_servicio'] ) ? $valores['estado_servicio'] : 'en_proceso';
			if ( '' === $estado_actual_form ) { $estado_actual_form = 'en_proceso'; }
			?>
			<div class="ct-field" style="margin-top:20px;padding:16px;background:#fffbeb;border:1px solid #fcd34d;border-radius:8px;">
				<label for="ct-estado-servicio" class="ct-label" style="font-weight:700;color:#92400e;">
					<?php esc_html_e( '¿Finalizar este servicio?', 'calibratrack' ); ?>
				</label>
				<select id="ct-estado-servicio" name="estado_servicio" class="ct-select">
					<?php foreach ( CalibraTrack_Helpers::get_estados_servicio() as $slug => $cfg ) : ?>
					<option value="<?php echo esc_attr( $slug ); ?>" <?php selected( $estado_actual_form, $slug ); ?>>
						<?php echo esc_html( $cfg['label'] ); ?>
					</option>
					<?php endforeach; ?>
				</select>
			</div>
			<div class="ct-form-actions">
				<button type="submit" class="ct-btn ct-btn--primary ct-btn--large">
					<?php
					if ( 'completado' === $estado_actual_form ) {
						esc_html_e( 'Guardar y emitir certificado', 'calibratrack' );
					} else {
						esc_html_e( 'Actualizar evento', 'calibratrack' );
					}
					?>
				</button>
			</div>
		</form>

	<?php endif; ?>

<?php endif; ?>
```

- [ ] **Step 3: Verify tech view**

Iniciar sesión como técnico, abrir una OT asignada. Debe mostrar:
- Tarjeta de info (solo lectura) con datos del equipo, N° OT, falla, etc.
- Formulario con descripción, observaciones, fotos, PDFs, selector de estado (3 opciones, sin "Completado").
- Sin campos de montos.

Guardar cambios y verificar que el estado se actualiza en la BD (`wp_postmeta` con key `calibratrack_estado_servicio`).

- [ ] **Step 4: Commit**

```bash
git add calibratrack/templates/panel/evento-detalle.php
git commit -m "feat: evento-detalle.php shows restricted tech view (read-only info card + 4 editable fields, no pricing)"
```

---

### Task 6: UI consistency — admin dropdown, badges, header nav

**Files:**
- Modify: `calibratrack/templates/panel/form-ot.php` (~línea 364)
- Modify: `calibratrack/templates/panel/dashboard.php` (~línea 188, admin section)
- Modify: `calibratrack/templates/panel/lista-eventos.php` (~línea 135)
- Modify: `calibratrack/templates/panel/_partials/header.php` (~línea 29)

**Interfaces:**
- Consumes: `CalibraTrack_Helpers::get_estados_servicio()`

- [ ] **Step 1: Add new states to admin OT dropdown in `form-ot.php`**

Localizar (~línea 364):
```php
			<select id="ct-estado-servicio" name="estado_servicio" class="ct-select">
				<option value="en_proceso" <?php selected( $estado_actual, 'en_proceso' ); ?>>
					<?php esc_html_e( 'En proceso', 'calibratrack' ); ?>
				</option>
				<option value="completado" <?php selected( $estado_actual, 'completado' ); ?>>
					<?php esc_html_e( 'Completado — Emitir certificado', 'calibratrack' ); ?>
				</option>
			</select>
```

Reemplazar con:
```php
			<select id="ct-estado-servicio" name="estado_servicio" class="ct-select">
				<option value="en_proceso" <?php selected( $estado_actual, 'en_proceso' ); ?>>
					<?php esc_html_e( 'En proceso', 'calibratrack' ); ?>
				</option>
				<option value="en_ejecucion" <?php selected( $estado_actual, 'en_ejecucion' ); ?>>
					<?php esc_html_e( 'En ejecución', 'calibratrack' ); ?>
				</option>
				<option value="listo_revision" <?php selected( $estado_actual, 'listo_revision' ); ?>>
					<?php esc_html_e( 'Listo para revisión', 'calibratrack' ); ?>
				</option>
				<option value="completado" <?php selected( $estado_actual, 'completado' ); ?>>
					<?php esc_html_e( 'Completado — Emitir certificado', 'calibratrack' ); ?>
				</option>
			</select>
```

- [ ] **Step 2: Update admin OT badge config in `dashboard.php`**

En la sección admin de dashboard.php, localizar (~línea 188):
```php
	$estados_servicio_cfg = array(
		'en_proceso' => array( 'clase' => 'ct-badge--por-vencer', 'label' => __( 'En proceso', 'calibratrack' ) ),
		'completado' => array( 'clase' => 'ct-badge--vigente',    'label' => __( 'Completado', 'calibratrack' ) ),
	);
```

Reemplazar con:
```php
	$estados_servicio_cfg = CalibraTrack_Helpers::get_estados_servicio();
```

- [ ] **Step 3: Update OT badge config in `lista-eventos.php`**

En lista-eventos.php, localizar (~línea 135):
```php
$estados_servicio_cfg = array(
	'en_proceso' => array( 'clase' => 'ct-badge--por-vencer', 'label' => __( 'En proceso', 'calibratrack' ) ),
	'completado' => array( 'clase' => 'ct-badge--vigente',    'label' => __( 'Completado', 'calibratrack' ) ),
);
```

Reemplazar con:
```php
$estados_servicio_cfg = CalibraTrack_Helpers::get_estados_servicio();
```

- [ ] **Step 4: Restrict Equipos link in header.php to admin only**

En `header.php`, localizar (~línea 29):
```php
			<a href="<?php echo esc_url( home_url( '/panel/equipos/' ) ); ?>" class="ct-nav-link"><?php esc_html_e( 'Equipos', 'calibratrack' ); ?></a>
```

Reemplazar con:
```php
			<?php if ( current_user_can( 'manage_options' ) ) : ?>
			<a href="<?php echo esc_url( home_url( '/panel/equipos/' ) ); ?>" class="ct-nav-link"><?php esc_html_e( 'Equipos', 'calibratrack' ); ?></a>
			<?php endif; ?>
```

- [ ] **Step 5: Final end-to-end verification**

**Como técnico:**
1. `/panel/` — ver solo sus OTs (filtradas por `calibratrack_tecnico_responsable`)
2. Menú muestra: Inicio, Mi perfil, Salir (NO Equipos, NO Nueva OI, NO Nueva OT, NO OI, NO OT)
3. Abrir una OT → tarjeta info + formulario limitado sin montos
4. Cambiar estado a "En ejecución" → guardar → verificar badge actualizado en lista
5. Cambiar estado a "Listo para revisión" → guardar → verificar
6. Intentar enviar POST manual con `estado_servicio=completado` → servidor mantiene estado anterior

**Como admin:**
1. `/panel/?filtro=ot` — ver todas las OTs con badge de 4 estados
2. Abrir `/panel/ot/{id}/` → dropdown muestra los 4 estados
3. Abrir `/panel/evento/{id}/` → redirige a `/panel/ot/{id}/`
4. Cambiar estado a "Completado" → certificado generado normalmente

- [ ] **Step 6: Commit**

```bash
git add calibratrack/templates/panel/form-ot.php \
        calibratrack/templates/panel/dashboard.php \
        calibratrack/templates/panel/lista-eventos.php \
        calibratrack/templates/panel/_partials/header.php
git commit -m "feat: 4-state badges in all OT views, admin dropdown updated, header nav restricted for technician"
```
