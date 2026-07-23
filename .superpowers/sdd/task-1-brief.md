# Task 1: Foundation — `get_estados_servicio()` + CSS badge classes

## Context
CalibraTrack WordPress plugin (PHP 7.4, WP 6.8.5). Adding support for 4 OT service states and two new badge CSS classes. This is the foundation task — all subsequent tasks consume `CalibraTrack_Helpers::get_estados_servicio()`.

## Files to Modify
- `calibratrack/includes/class-calibratrack-helpers.php` — add method after `get_estados_equipo()` (line 63)
- `calibratrack/assets/css/tecnico.css` — add 2 new badge classes after `.ct-badge--sin-evento` (line 176)

## Global Constraints
- PHP 7.4: no `enum`, `match`, `?->`, constructor promotion, union types, named args
- WPCS: `esc_html()`, `esc_attr()` on all HTML output
- i18n: all visible text uses `__()` / `esc_html_e()` with text domain `calibratrack`

## What to Implement

### Step 1: Add `get_estados_servicio()` to class-calibratrack-helpers.php

In `class-calibratrack-helpers.php`, add after the closing brace of `get_estados_equipo()` (after line 63):

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

### Step 2: Add CSS badge classes to tecnico.css

Find the `.ct-badge--sin-evento` rule (around line 176) and add after it:

```css
.ct-badge--en-ejecucion   { background: #fef3c7; color: #92400e; border: 1px solid #fcd34d; }
.ct-badge--listo-revision { background: #ede9fe; color: #5b21b6; border: 1px solid #c4b5fd; }
```

## Interfaces Produced
`CalibraTrack_Helpers::get_estados_servicio(): array` — returns 4 states in flow order, each with `label` (translated string) and `clase` (CSS class string). All subsequent tasks use this method.

## Verification
Check that the PHP file has no syntax errors (look at it carefully — no need for a running server). The CSS classes won't visually appear until later tasks use them.
