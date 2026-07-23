# Task 6: UI Consistency — form-ot.php states + header nav restriction

## Context
CalibraTrack WordPress plugin. Final UI cleanup:
1. The admin OT form (`form-ot.php`) still only has 2 state options — add the 2 intermediate ones
2. The nav header (`header.php`) shows "Equipos" to everyone — restrict it to admin only

Note: The dashboard.php admin badge config (`$estados_servicio_cfg`) was already updated in Task 4.
Note: The `lista-eventos.php` badge config was already updated in Task 4.

## Files to Modify
- `calibratrack/templates/panel/form-ot.php`
- `calibratrack/templates/panel/_partials/header.php`

## Global Constraints
- PHP 7.4: no `enum`, `match`, `?->`, constructor promotion, union types, named args
- WPCS: escape all HTML output
- i18n: `esc_html_e()` with text domain `calibratrack`

## Change 1: Add new states to `form-ot.php` dropdown (lines 364-371)

Current code at lines 364-371:
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

Replace with:
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

## Change 2: Restrict "Equipos" nav link in `header.php` (line 29)

The header.php currently has admin-only links wrapped in `if (current_user_can('manage_options'))` (lines 22-27), then line 29 has the Equipos link outside that condition.

Current code at line 29:
```php
			<a href="<?php echo esc_url( home_url( '/panel/equipos/' ) ); ?>" class="ct-nav-link"><?php esc_html_e( 'Equipos', 'calibratrack' ); ?></a>
```

Replace with:
```php
			<?php if ( current_user_can( 'manage_options' ) ) : ?>
			<a href="<?php echo esc_url( home_url( '/panel/equipos/' ) ); ?>" class="ct-nav-link"><?php esc_html_e( 'Equipos', 'calibratrack' ); ?></a>
			<?php endif; ?>
```

## Verification
Read back the modified sections:
1. `form-ot.php`: select has 4 options — `en_proceso`, `en_ejecucion`, `listo_revision`, `completado` (in that order)
2. `header.php`: The Equipos link is now inside `if (current_user_can('manage_options'))` — a technician will not see it in the nav
