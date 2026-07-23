# Task 6 Report: UI Consistency — form-ot.php states + header nav restriction

## Status
DONE

## Changes Made

### Change 1: Added 4-state dropdown to `form-ot.php` (lines 364-377)

**File:** `calibratrack/templates/panel/form-ot.php`

**Before:**
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

**After:**
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

### Change 2: Restricted "Equipos" nav link to admin only in `header.php` (lines 29-31)

**File:** `calibratrack/templates/panel/_partials/header.php`

**Before:**
```php
<?php endif; ?>

<a href="<?php echo esc_url( home_url( '/panel/equipos/' ) ); ?>" class="ct-nav-link"><?php esc_html_e( 'Equipos', 'calibratrack' ); ?></a>
```

**After:**
```php
<?php endif; ?>

<?php if ( current_user_can( 'manage_options' ) ) : ?>
<a href="<?php echo esc_url( home_url( '/panel/equipos/' ) ); ?>" class="ct-nav-link"><?php esc_html_e( 'Equipos', 'calibratrack' ); ?></a>
<?php endif; ?>
```

## Verification

✓ **form-ot.php**: Dropdown now contains 4 options in correct order:
  1. `en_proceso` → "En proceso"
  2. `en_ejecucion` → "En ejecución"
  3. `listo_revision` → "Listo para revisión"
  4. `completado` → "Completado — Emitir certificado"

✓ **header.php**: "Equipos" link now wrapped in `if (current_user_can('manage_options'))` condition
  - Non-admin technicians will no longer see this link in the navigation
  - Admin users will continue to see it normally

## Technical Notes

- All changes follow PHP 7.4 syntax constraints (no enums, match, nullsafe operators, etc.)
- All strings use `esc_html_e()` with text domain `calibratrack` (WPCS compliant)
- Changes align with Task 4 updates to badge configurations in dashboard and event list templates
