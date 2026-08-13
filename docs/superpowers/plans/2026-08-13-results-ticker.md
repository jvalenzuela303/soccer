# Results Ticker — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Mostrar los últimos partidos finalizados del torneo como un ticker de texto con scroll vertical continuo sobre la imagen del banner publicitario en el portal público.

**Architecture:** Se agregan estilos CSS al archivo existente, un contenedor vacío `#st-results-ticker` dentro del `.st-tournament-banner` en el template PHP, y una función `injectResultsTicker()` en `live-standings.js` que se llama desde `init()` — reutiliza el endpoint `/fixture` ya cacheado, sin peticiones extra.

**Tech Stack:** Vanilla JS ES2021, CSS `@keyframes`, PHP 8.2, WordPress plugin hooks existentes.

## Global Constraints

- PHP 8.2, WordPress 7.0.2, MariaDB 10.6+ (hosting compartido).
- Sin dependencias externas — vanilla JS únicamente.
- Respetar variables CSS del plugin: `--st-green-primary`, `--st-font-body`, etc.
- i18n: textos visibles via `__()` / `esc_html_e()` en PHP; `i18n.*` en JS (ya inyectado en `window.stPublic.i18n`).
- No modificar el endpoint REST — el ticker consume `/fixture` ya existente.
- El ticker solo se activa cuando hay banner (`#st-results-ticker` solo existe si `$tournament['banner_url']` no está vacío).

---

## File Map

| Archivo | Acción | Responsabilidad |
|---------|--------|-----------------|
| `soccertrack/assets/css/tournament-page.css` | Modificar | Estilos del ticker overlay |
| `soccertrack/templates/public/tournament-page.php` | Modificar | Contenedor HTML vacío dentro del banner |
| `soccertrack/assets/js/live-standings.js` | Modificar | Lógica de fetch, filtrado e inyección del ticker |

---

## Task 1: CSS — Estilos del ticker overlay

**Files:**
- Modify: `soccertrack/assets/css/tournament-page.css` — agregar estilos al final del bloque banner (línea 429)

**Interfaces:**
- Produce: clases `.st-results-ticker`, `.st-results-ticker.is-active`, `.st-results-ticker__track`, `.st-results-ticker__item`, `.st-results-ticker__round`, `@keyframes st-ticker-scroll`

- [ ] **Step 1: Agregar `position: relative` a `.st-tournament-banner`**

En `tournament-page.css`, la regla `.st-tournament-banner` (línea ~403) actualmente tiene `overflow: hidden` pero no `position: relative`. El ticker usa `position: absolute`, necesita un ancestro posicionado.

Reemplazar:
```css
.st-tournament-banner {
	width: 100%;
	line-height: 0;
	background: #0E0C19;
	overflow: hidden;
}
```
Con:
```css
.st-tournament-banner {
	width: 100%;
	line-height: 0;
	background: #0E0C19;
	overflow: hidden;
	position: relative;
}
```

- [ ] **Step 2: Agregar estilos del ticker al final del bloque banner (después de línea 428)**

Insertar después del bloque `@media (max-width: 480px) { .st-tournament-banner__img { ... } }`:

```css
/* ── Ticker de resultados sobre el banner ───────────────────────── */
.st-results-ticker {
	display: none;
	position: absolute;
	top: 0;
	right: 0;
	width: 220px;
	height: 100%;
	overflow: hidden;
	background: rgba(0, 0, 0, 0.55);
	--st-ticker-duration: 25s;
}

.st-results-ticker.is-active {
	display: block;
}

.st-results-ticker__track {
	display: flex;
	flex-direction: column;
	animation: st-ticker-scroll var(--st-ticker-duration) linear infinite;
}

@keyframes st-ticker-scroll {
	from { transform: translateY(0); }
	to   { transform: translateY(-50%); }
}

.st-results-ticker__item {
	padding: 0.6rem 0.75rem;
	font-family: var(--st-font-body, 'Poppins', sans-serif);
	font-size: 0.72rem;
	color: #fff;
	border-bottom: 1px solid rgba(255, 255, 255, 0.1);
	white-space: nowrap;
	overflow: hidden;
	text-overflow: ellipsis;
	line-height: 1.4;
}

.st-results-ticker__round {
	color: var(--st-green-light, #7CDA24);
	font-weight: 600;
	display: block;
	font-size: 0.65rem;
	text-transform: uppercase;
	letter-spacing: 0.04em;
}

.st-results-ticker__score {
	display: block;
	margin-top: 0.15rem;
}

@media (max-width: 480px) {
	.st-results-ticker {
		display: none !important;
	}
}
```

- [ ] **Step 3: Verificar visualmente en el navegador**

Abrir cualquier torneo con banner en el portal público (ej. `http://localhost/torneo/1`).  
En este paso el ticker todavía no es visible (no existe el HTML ni el JS) — verificar que `.st-tournament-banner` tenga `position: relative` con DevTools (Inspect → Computed → position).

- [ ] **Step 4: Commit**

```bash
git add soccertrack/assets/css/tournament-page.css
git commit -m "feat(css): add results ticker overlay styles for banner"
```

---

## Task 2: HTML — Contenedor del ticker en el template PHP

**Files:**
- Modify: `soccertrack/templates/public/tournament-page.php:20-32` — agregar `#st-results-ticker` dentro del banner

**Interfaces:**
- Consume: bloque PHP existente `if ( ! empty( $tournament['banner_url'] ) )`
- Produce: elemento `#st-results-ticker` con hijo `.st-results-ticker__track` en el DOM cuando hay banner

- [ ] **Step 1: Agregar el contenedor del ticker dentro del banner**

En `tournament-page.php`, el bloque actual es:
```php
<?php if ( ! empty( $tournament['banner_url'] ) ) : ?>
<div class="st-tournament-banner">
	<img
		src="<?php echo esc_url( $tournament['banner_url'] ); ?>"
		alt="<?php echo esc_attr( sprintf(
			/* translators: %s: tournament name */
			__( 'Banner de %s', 'soccertrack' ),
			$tournament['name']
		) ); ?>"
		class="st-tournament-banner__img"
	>
</div>
<?php endif; ?>
```

Reemplazarlo con:
```php
<?php if ( ! empty( $tournament['banner_url'] ) ) : ?>
<div class="st-tournament-banner">
	<img
		src="<?php echo esc_url( $tournament['banner_url'] ); ?>"
		alt="<?php echo esc_attr( sprintf(
			/* translators: %s: tournament name */
			__( 'Banner de %s', 'soccertrack' ),
			$tournament['name']
		) ); ?>"
		class="st-tournament-banner__img"
	>
	<div id="st-results-ticker" class="st-results-ticker" aria-hidden="true">
		<div class="st-results-ticker__track"></div>
	</div>
</div>
<?php endif; ?>
```

- [ ] **Step 2: Verificar que el elemento existe en el DOM**

Abrir el portal de un torneo con banner en el navegador.  
En DevTools → Elements buscar `#st-results-ticker`. Debe existir, estar oculto (`display: none`) y estar dentro de `.st-tournament-banner`.  
En un torneo **sin** banner, `#st-results-ticker` no debe existir en el DOM.

- [ ] **Step 3: Commit**

```bash
git add soccertrack/templates/public/tournament-page.php
git commit -m "feat(portal): add results ticker container inside banner"
```

---

## Task 3: JS — Función `injectResultsTicker` y llamada desde `init()`

**Files:**
- Modify: `soccertrack/assets/js/live-standings.js` — agregar función antes de `init()` y llamarla dentro de `init()`

**Interfaces:**
- Consume:
  - `apiFetch(endpoint)` — función existente en el IIFE, línea ~105
  - `escHtml(str)` — función existente, línea ~61
  - `i18n` — objeto existente (línea 22), usa clave `i18n.round` (fallback `'Jornada'`)
  - `TID` — constante existente (línea 20)
  - `#st-results-ticker` — elemento DOM del Task 2
  - Endpoint: `soccertrack/v1/public/tournament/${TID}/fixture` — devuelve array de partidos con campos: `status` (string), `home_score` (number), `away_score` (number), `home_team` (string), `away_team` (string), `round_number` (number)
- Produce: ticker visible en `.st-results-ticker.is-active` con ítems duplicados para loop seamless

- [ ] **Step 1: Agregar `injectResultsTicker()` antes de la función `init()`**

En `live-standings.js`, localizar el bloque `/* ── Init ──` (línea ~1104). Insertar la siguiente función **justo antes** de ese bloque:

```js
	/* ------------------------------------------------------------------ */
	/* Ticker de resultados sobre el banner                                */
	/* ------------------------------------------------------------------ */

	async function injectResultsTicker() {
		const ticker = document.getElementById( 'st-results-ticker' );
		if ( ! ticker ) return; // No hay banner configurado.

		let matches;
		try {
			matches = await apiFetch( `soccertrack/v1/public/tournament/${ TID }/fixture` );
		} catch {
			return; // Falló el fetch — ticker permanece oculto.
		}

		const finished = ( Array.isArray( matches ) ? matches : [] )
			.filter( ( m ) => m.status === 'finished' )
			.sort( ( a, b ) => b.round_number - a.round_number )
			.slice( 0, 10 );

		if ( finished.length === 0 ) return; // Sin partidos finalizados.

		const items = finished.map( ( m ) => `
			<div class="st-results-ticker__item">
				<span class="st-results-ticker__round">${ escHtml( i18n.round ?? 'Jornada' ) } ${ m.round_number }</span>
				<span class="st-results-ticker__score">${ escHtml( m.home_team ) } ${ m.home_score } – ${ m.away_score } ${ escHtml( m.away_team ) }</span>
			</div>` ).join( '' );

		// Duplicar para loop seamless (el @keyframes va de 0 a -50%).
		const track = ticker.querySelector( '.st-results-ticker__track' );
		track.innerHTML = items + items;
		ticker.classList.add( 'is-active' );
	}
```

- [ ] **Step 2: Llamar `injectResultsTicker()` desde `init()`**

Dentro de la función `init()` (línea ~1108), al final — después de la línea `if ( initialId ) activateTab( initialId );`:

```js
		// Ticker de resultados sobre el banner (fire-and-forget).
		injectResultsTicker();
```

El resultado final de `init()` debe verse así:
```js
	function init() {
		const nav = qs( '.st-tabs-nav' );

		if ( ! nav || ! TID ) return;

		// Delegar click en la lista de pestañas.
		nav.addEventListener( 'click', ( e ) => {
			const btn = e.target.closest( '.st-tab-btn' );
			if ( ! btn ) return;
			activateTab( btn.dataset.tab );
			btn.focus();
		} );

		// Soporte teclado (←/→ entre pestañas).
		nav.addEventListener( 'keydown', ( e ) => {
			const buttons  = qsa( '.st-tab-btn', nav );
			const current  = document.activeElement;
			const idx      = buttons.indexOf( current );

			if ( idx === -1 ) return;

			let next = idx;
			if ( e.key === 'ArrowRight' ) next = ( idx + 1 ) % buttons.length;
			if ( e.key === 'ArrowLeft'  ) next = ( idx - 1 + buttons.length ) % buttons.length;
			if ( next === idx ) return;

			e.preventDefault();
			activateTab( buttons[ next ].dataset.tab );
			buttons[ next ].focus();
		} );

		// Tab inicial: desde URL o el primero disponible.
		const urlTab    = new URLSearchParams( window.location.search ).get( 'tab' );
		const firstBtn  = qs( '.st-tab-btn', nav );
		const initialId = ( urlTab && RENDERERS[ urlTab ] ) ? urlTab : firstBtn?.dataset.tab;

		if ( initialId ) activateTab( initialId );

		// Ticker de resultados sobre el banner (fire-and-forget).
		injectResultsTicker();
	}
```

- [ ] **Step 3: Verificar en el navegador**

1. Abrir el portal de un torneo **con banner** y al menos **1 partido finalizado**.
2. Esperar ~1 segundo hasta que el JS haga el fetch.
3. El ticker debe aparecer en el lado derecho del banner con ítems subiendo continuamente.
4. Cada ítem debe mostrar: línea verde con `Jornada N`, línea blanca con `Equipo A X – Y Equipo B`.
5. Abrir el portal de un torneo **sin banner** → no debe haber ticker.
6. Abrir el portal de un torneo con banner pero **sin partidos finalizados** → no debe haber ticker.

- [ ] **Step 4: Commit**

```bash
git add soccertrack/assets/js/live-standings.js
git commit -m "feat(portal): inject results ticker over tournament banner from fixture data"
```

---

## Self-Review

**Spec coverage:**
- ✅ Scroll continuo y automático → `@keyframes st-ticker-scroll` + `animation: ... infinite`
- ✅ Formato `Jornada N · Local X – Y Visitante` → `.st-results-ticker__round` + `.st-results-ticker__score`
- ✅ Solo partidos finalizados → `.filter(m => m.status === 'finished')`
- ✅ Solo cuando hay banner → `#st-results-ticker` solo existe en el DOM si `banner_url` no está vacío (Task 2)
- ✅ Sin partidos finalizados → ticker permanece oculto
- ✅ Sin request extra → `apiFetch` usa el mismo cache que la pestaña Fixture
- ✅ Responsive: oculto en < 480px → `@media (max-width: 480px) { display: none !important }`
- ✅ Nombres largos truncados → `text-overflow: ellipsis`

**Placeholder scan:** Sin TBDs. Todos los pasos tienen código completo.

**Type consistency:** `injectResultsTicker()` usa `apiFetch`, `escHtml`, `i18n`, `TID` — todos definidos en el IIFE del mismo archivo. La función se llama sin argumentos desde `init()`.
