# Task 2 Report: Server Security — Technician Save Path

## Changes Made

### Change 1: Technician branch at start of `procesar_guardar_evento()`
- **File:** `calibratrack/includes/class-calibratrack-panel.php`
- **Inserted after:** line 1030 (the `return false;` closing the nonce check block)
- **Now at lines:** 1032–1035
- Code inserts a check: if `$evento_id > 0` (editing an existing OT) AND the user is NOT an admin (`manage_options`), delegate immediately to `procesar_guardar_evento_tecnico()` and return its result.

### Change 2: 4-state validation at (original) line 1044
- **File:** `calibratrack/includes/class-calibratrack-panel.php`
- **Now at line:** 1049
- `'estado_servicio'` validation array expanded from `array( 'en_proceso', 'completado' )` to `array( 'en_proceso', 'en_ejecucion', 'listo_revision', 'completado' )`.

### Change 3: New private method `procesar_guardar_evento_tecnico()`
- **File:** `calibratrack/includes/class-calibratrack-panel.php`
- **Inserted after:** the closing `}` of `procesar_uploads()` (was line 1266, now line 1271)
- **Method spans lines:** 1273–1318
- Method reads the current state from the database as a fallback, only accepts `en_proceso`, `en_ejecucion`, or `listo_revision` (never `completado`), saves only `descripcion_trabajo`, `observaciones`, and `estado_servicio`, calls `self::procesar_uploads()`, and invalidates the vigencia transient for the associated equipo.

## Self-Review Results

1. **Tech branch placement:** Confirmed at lines 1032–1035, immediately after the `return false;` on line 1030. Correct.
2. **4-state array:** Confirmed at line 1049 — includes `en_ejecucion` and `listo_revision`. Correct.
3. **`procesar_guardar_evento_tecnico()` method:** Confirmed at lines 1285–1318. `$estados_permitidos` array on line 1297 contains exactly `en_proceso`, `en_ejecucion`, `listo_revision` — `completado` is absent. Correct.
4. **PHP 7.4 compatibility:** No `match`, `enum`, `?->`, union types, named args, constructor promotion, or readonly properties used in any of the three changes. All `$_POST` reads use `isset()` checks with `wp_unslash()` and appropriate `sanitize_*` functions. Correct.
5. **No nonce re-verification in the new method:** Confirmed. The nonce is verified in the parent `procesar_guardar_evento()` before the branch is taken.
6. **Cost items not accessible to technician:** Confirmed. The technician branch returns before the `$valores` array is built with `items`, and before the `CalibraTrack_DB::save_items_costo()` / `calibratrack_subtotal/iva/total` block. Cost data is fully excluded.

## Concerns

None. All three changes match the brief verbatim, are PHP 7.4 compatible, and enforce the security constraint that a technician cannot assign `completado` state or touch cost fields.
