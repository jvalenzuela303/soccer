# Task 3: Admin OT — `guardar_ot()` 4-state + `handle_editar_evento()` routing

## Context
CalibraTrack WordPress plugin. Two changes needed in `class-calibratrack-panel.php`:
1. `guardar_ot()` (line 849) — the admin OT save handler — still has 2-state validation and a broken email condition
2. `handle_editar_evento()` (line 452) — the technician view router — still uses `post_author` for auth and doesn't redirect admins

## File to Modify
`calibratrack/includes/class-calibratrack-panel.php`

## Global Constraints
- PHP 7.4: no `enum`, `match`, `?->`, constructor promotion, union types, named args
- WPCS: `esc_html()` on HTML output, `wp_verify_nonce()` already present — don't touch it

## What to Implement

### Change 1: Update state validation in `guardar_ot()` (line 861-863)

Current code at lines 861-863:
```php
		$estado_valido = in_array( $estado_raw, array( 'en_proceso', 'completado' ), true )
			? $estado_raw
			: 'en_proceso';
```

Replace with:
```php
		$estado_valido = in_array( $estado_raw, array( 'en_proceso', 'en_ejecucion', 'listo_revision', 'completado' ), true )
			? $estado_raw
			: 'en_proceso';
```

### Change 2: Fix certificate email condition in `guardar_ot()` (line 1004)

Current code at line 1004:
```php
			if ( 'en_proceso' === $estado_anterior && class_exists( 'CalibraTrack_Mailer' ) ) {
```

Replace with:
```php
			if ( 'completado' !== $estado_anterior && class_exists( 'CalibraTrack_Mailer' ) ) {
```

**Why:** The old condition only sent the certificate email if transitioning FROM `en_proceso`. Now that there are intermediate states (`en_ejecucion`, `listo_revision`), the email should fire whenever transitioning TO `completado` from ANY state other than `completado` itself.

Also fix the same condition at line 1156 (inside `procesar_guardar_evento()` which is the older handler still used for new-OT creation path):
```php
			if ( 'en_proceso' === $estado_anterior && class_exists( 'CalibraTrack_Mailer' ) ) {
```
Replace with:
```php
			if ( 'completado' !== $estado_anterior && class_exists( 'CalibraTrack_Mailer' ) ) {
```

### Change 3: Rewrite auth block in `handle_editar_evento()` (lines 462-467)

Current code at lines 462-467:
```php
		// Verificar que el evento pertenece al técnico actual o el usuario es admin.
		$autor_id = (int) get_post_field( 'post_author', $evento_id );
		if ( $autor_id !== get_current_user_id() && ! current_user_can( 'edit_others_eventos_servicio' ) ) {
			wp_redirect( home_url( '/panel/eventos/' ) );
			exit;
		}
```

Replace with:
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

### Change 4: Pass `$es_tecnico = true` to template in `handle_editar_evento()` (lines 487-493)

Current code at lines 487-493:
```php
		self::load_template( 'evento-detalle', array(
			'evento_id'    => $evento_id,
			'errors'       => $errors,
			'valores'      => $valores,
			'equipos'      => CalibraTrack_Tecnico::get_equipos_para_select(),
			'es_completado' => $es_completado,
		) );
```

Replace with:
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

## Verification
Read back the modified sections and confirm:
1. `guardar_ot()` lines 861-863: 4-state array with `en_ejecucion` and `listo_revision`
2. Line ~1004 in `guardar_ot()`: condition is now `'completado' !== $estado_anterior`
3. Line ~1156 in `procesar_guardar_evento()`: same fix applied
4. `handle_editar_evento()` lines 462+: admin redirect block first, then meta-based auth check
5. `load_template()` call includes `'es_tecnico' => true`
