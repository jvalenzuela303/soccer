# Task 2: Server Security — Technician Save Path

## Context
CalibraTrack WordPress plugin. The `procesar_guardar_evento()` function in `class-calibratrack-panel.php` currently processes POST for BOTH admins and technicians, saving all fields including cost items. We need to split it: if a technician is editing an existing OT, use a new restricted handler that only saves 4 allowed fields and never allows the `completado` state.

## File to Modify
`calibratrack/includes/class-calibratrack-panel.php`
- Function `procesar_guardar_evento()` starts at line 1024
- The nonce check block is lines 1027-1030 (ends with `return false;`)
- The 2-state validation is at line 1044

## Global Constraints
- PHP 7.4: no `enum`, `match`, `?->`, constructor promotion, union types, named args
- WPCS: `esc_html()` on HTML output; `sanitize_*` on all `$_POST` reads
- The technician MUST NOT be able to assign `completado` state — validated server-side
- Cost items (`calibratrack_items`, subtotal, iva, total) MUST be ignored for technicians

## What to Implement

### Change 1: Add technician branch at start of `procesar_guardar_evento()`

After the closing `return false;` of the nonce check block (after line 1030), insert:

```php
		// Técnico editando una OT existente: solo campos permitidos.
		if ( $evento_id > 0 && ! current_user_can( 'manage_options' ) ) {
			return self::procesar_guardar_evento_tecnico( $evento_id, $errors, $valores );
		}
```

### Change 2: Update 2-state validation to 4-state at line 1044

Current code at line 1044:
```php
			'estado_servicio'     => in_array( $estado_raw, array( 'en_proceso', 'completado' ), true ) ? $estado_raw : 'en_proceso',
```

Replace with:
```php
			'estado_servicio'     => in_array( $estado_raw, array( 'en_proceso', 'en_ejecucion', 'listo_revision', 'completado' ), true ) ? $estado_raw : 'en_proceso',
```

### Change 3: Add private method `procesar_guardar_evento_tecnico()`

Find the end of the `procesar_guardar_evento()` function and add this new method immediately after it (before the closing `}` of the class):

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

## Notes
- `self::procesar_uploads()` already exists in the class — don't create it, just call it
- `CalibraTrack_Meta_Keys::EVENTO_DESCRIPCION_TRABAJO`, `EVENTO_OBSERVACIONES`, `EVENTO_ESTADO_SERVICIO`, `EVENTO_EQUIPO_ID` are constants in the class — use them exactly as shown
- No nonce re-verification needed in `procesar_guardar_evento_tecnico()` because the technician branch is only entered after the nonce has already been verified in the parent function

## Verification
Read the modified file back and check:
1. The tech branch appears right after the nonce check in `procesar_guardar_evento()`
2. The 4-state array includes `en_ejecucion` and `listo_revision`
3. `procesar_guardar_evento_tecnico()` method exists with the exact `$estados_permitidos` array that excludes `completado`
4. No PHP 8+ syntax anywhere in the changes
