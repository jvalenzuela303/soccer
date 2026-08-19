# Bracket Visual de Playoffs + Panel Swiss — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Habilitar la creación de brackets en el panel para torneos Swiss, y reemplazar la vista de lista de playoffs en el portal público por un cuadro visual tipo árbol (CSS tree).

**Architecture:** Cambio en `torneo-detalle.php` (1 línea eliminada), estilos nuevos en `tournament-page.css` (~100 líneas), y reescritura de `renderPlayoffs()` en `live-standings.js` con funciones helper para construir el árbol. Sin cambios en PHP backend ni REST API.

**Tech Stack:** PHP 8.2, vanilla JS (ES2020), CSS custom properties.

## Global Constraints

- WordPress Coding Standards (WPCS) — escapar toda salida PHP con `esc_html__()`, `esc_attr()`, etc.
- Variables CSS del plugin: `--st-green-primary: #3CBC20`, `--st-green-secondary: #6DB728`, `--st-navy: #0E0C19`, `--st-charcoal: #3C3A47`, `--st-font-body: 'Poppins'`
- JS: funciones definidas dentro del IIFE existente de `live-standings.js` — no agregar variables globales
- Match data del API usa: `m.home_team`, `m.away_team` (nombre), `m.home_score`, `m.away_score`, `m.home_logo`, `m.away_logo`, `m.home_is_ghost`, `m.away_is_ghost`, `m.status`, `m.match_datetime`, `m.bracket_name`
- i18n disponible en JS: `i18n.phase_quarterfinal`, `i18n.phase_semifinal`, `i18n.phase_third_place`, `i18n.phase_final`, `i18n.playoffs_title`, `i18n.no_playoffs`, `i18n.status_finished`, `i18n.status_live`, `i18n.status_scheduled`
- `escHtml()` ya existe en el IIFE — usarla para escapar strings en JS

---

## Archivos a modificar

| Archivo | Responsabilidad del cambio |
|---------|---------------------------|
| `soccertrack/templates/panel/torneo-detalle.php` | Eliminar condición `!== 'swiss'` (líneas 721 y 761) |
| `soccertrack/assets/css/tournament-page.css` | Agregar estilos `.st-bracket-tree`, `.st-bracket-col`, `.st-bracket-match`, `.st-bracket-team`, `.st-bracket-pair` |
| `soccertrack/assets/js/live-standings.js` | Reemplazar `renderPlayoffs()` (líneas 654-716) con versión de árbol visual + helpers `buildBracketTree()` y `bracketMatchCard()` |

---

### Task 1: Panel — Habilitar formulario de brackets para Swiss

**Files:**
- Modify: `soccertrack/templates/panel/torneo-detalle.php:721` y `761`

**Interfaces:**
- Consumes: nada
- Produces: el formulario de brackets queda visible para todos los formatos incluyendo `swiss`

- [ ] **Step 1: Eliminar la condición if/endif que oculta el form para Swiss**

En `soccertrack/templates/panel/torneo-detalle.php`, eliminar la línea 721 (el `if`) y la línea 761 (el `endif`). El bloque interior (el `<div id="st-bracket-form-wrap">` y su contenido) debe quedar intacto.

**Antes (líneas 720-761):**
```php
	<?php /* Formulario agregar/editar — oculto para Swiss (brackets pre-configurados por seeder) */ ?>
	<?php if ( ( $tournament['format'] ?? '' ) !== 'swiss' ) : ?>
	<div id="st-bracket-form-wrap" style="background:#f9f9f9;border:1px solid #e5e7eb;border-radius:8px;padding:16px">
		... (contenido del form)
	</div>
	<?php endif; /* !swiss — bracket add/edit form */ ?>
```

**Después:**
```php
	<?php /* Formulario agregar/editar */ ?>
	<div id="st-bracket-form-wrap" style="background:#f9f9f9;border:1px solid #e5e7eb;border-radius:8px;padding:16px">
		... (contenido del form — sin cambios)
	</div>
```

- [ ] **Step 2: Verificar manualmente en browser**

1. Navegar al panel de admin → Torneos → abrir un torneo con formato `swiss`
2. Confirmar que la sección "Brackets de Playoffs" muestra el formulario "Agregar bracket"
3. Crear un bracket de prueba (nombre: "Copa Oro", pos. desde: 1, pos. hasta: 8, tipo: Sembrado)
4. Confirmar que aparece en la tabla y puede editarse/eliminarse
5. Repetir con un torneo `round_robin_playoffs` — confirmar que sigue funcionando igual

- [ ] **Step 3: Commit**

```bash
git add soccertrack/templates/panel/torneo-detalle.php
git commit -m "feat(panel): habilitar formulario de brackets para formato Swiss"
```

---

### Task 2: CSS — Estilos del bracket tree

**Files:**
- Modify: `soccertrack/assets/css/tournament-page.css` (agregar al final del archivo, después de línea 609)

**Interfaces:**
- Consumes: nada (CSS puro, variables CSS existentes del plugin)
- Produces: clases `.st-bracket-tree`, `.st-bracket-col`, `.st-bracket-pair`, `.st-bracket-match`, `.st-bracket-team`, `.st-bracket-team--winner`, `.st-bracket-team--tbd`, `.st-bracket-score`, `.st-bracket-match-meta`, `.st-bracket-phase-label`, `.st-bracket-col-title`, `.st-bracket-col-matches`, `.st-bracket-match--third` — usadas por Task 3

- [ ] **Step 1: Agregar los estilos al final de `tournament-page.css`**

Agregar el siguiente bloque exactamente al final de `soccertrack/assets/css/tournament-page.css`:

```css
/* ── Bracket Tree (cuadro visual de playoffs) ──────────────────────── */
.st-bracket-tree {
	--bracket-gap:    32px;
	--bracket-mw:     210px;
	--bracket-mh:     76px;
	--bracket-mg:     8px;
	--bracket-pg:     24px;
	--bracket-line:   #d1d5db;
	display: flex;
	gap: 0;
	overflow-x: auto;
	padding: 16px 4px 28px;
	align-items: flex-start;
}

.st-bracket-col {
	display: flex;
	flex-direction: column;
	min-width: var(--bracket-mw);
	flex-shrink: 0;
	margin-right: var(--bracket-gap);
}

.st-bracket-col:last-child {
	margin-right: 0;
}

.st-bracket-col-title {
	font-size: .72rem;
	font-weight: 700;
	text-transform: uppercase;
	letter-spacing: .06em;
	color: var(--st-charcoal, #3C3A47);
	text-align: center;
	margin: 0 0 14px;
	opacity: .7;
}

.st-bracket-col-matches {
	display: flex;
	flex-direction: column;
}

/* Un par agrupa 2 partidos cuyos ganadores se enfrentan en la siguiente ronda */
.st-bracket-pair {
	display: flex;
	flex-direction: column;
	gap: var(--bracket-mg);
	position: relative;
	margin-bottom: var(--bracket-pg);
}

.st-bracket-pair:last-child {
	margin-bottom: 0;
}

/* Línea vertical derecha del par (conecta los dos partidos hacia la siguiente ronda) */
.st-bracket-col:not([data-round="final"]) .st-bracket-pair::after {
	content: '';
	position: absolute;
	right: calc(var(--bracket-gap) * -1 + 1px);
	top: calc(var(--bracket-mh) / 2);
	height: calc(var(--bracket-mh) + var(--bracket-mg));
	width: 1px;
	background: var(--bracket-line);
}

/* Línea horizontal derecha de cada partido */
.st-bracket-col:not([data-round="final"]) .st-bracket-match::after {
	content: '';
	position: absolute;
	right: calc(var(--bracket-gap) * -1 + 1px);
	top: 50%;
	width: calc(var(--bracket-gap) - 1px);
	height: 1px;
	background: var(--bracket-line);
}

/* Línea horizontal izquierda de entrada (SF y Final) */
.st-bracket-col[data-round="sf"] .st-bracket-match::before,
.st-bracket-col[data-round="final"] .st-bracket-match:not(.st-bracket-match--third)::before {
	content: '';
	position: absolute;
	left: calc(var(--bracket-gap) * -1 + 1px);
	top: 50%;
	width: calc(var(--bracket-gap) - 1px);
	height: 1px;
	background: var(--bracket-line);
}

.st-bracket-match {
	position: relative;
	background: #fff;
	border: 1px solid #e5e7eb;
	border-radius: 8px;
	overflow: hidden;
	min-height: var(--bracket-mh);
	min-width: var(--bracket-mw);
	display: flex;
	flex-direction: column;
}

.st-bracket-match--third {
	border-style: dashed;
	margin-top: 12px;
}

.st-bracket-phase-label {
	font-size: .62rem;
	text-transform: uppercase;
	letter-spacing: .05em;
	color: #9ca3af;
	padding: 4px 10px 0;
}

.st-bracket-team {
	display: flex;
	align-items: center;
	justify-content: space-between;
	padding: 7px 10px;
	gap: 8px;
	flex: 1;
	border-bottom: 1px solid #f3f4f6;
	transition: background .15s;
}

.st-bracket-team:last-of-type {
	border-bottom: none;
}

.st-bracket-team--winner {
	background: rgba(60, 188, 32, .08);
}

.st-bracket-team--winner .st-bracket-team-name {
	font-weight: 700;
	color: var(--st-green-primary, #3CBC20);
}

.st-bracket-team--tbd .st-bracket-team-name,
.st-bracket-team--tbd .st-bracket-score {
	opacity: .35;
	font-style: italic;
}

.st-bracket-team-name {
	font-size: .8rem;
	color: var(--st-charcoal, #3C3A47);
	font-family: var(--st-font-body, 'Poppins', sans-serif);
	white-space: nowrap;
	overflow: hidden;
	text-overflow: ellipsis;
	max-width: 148px;
}

.st-bracket-score {
	font-size: .88rem;
	font-weight: 700;
	color: var(--st-navy, #0E0C19);
	min-width: 18px;
	text-align: center;
	flex-shrink: 0;
}

.st-bracket-match-meta {
	font-size: .66rem;
	color: #9ca3af;
	padding: 3px 10px 4px;
	border-top: 1px solid #f3f4f6;
	white-space: nowrap;
}

@media (max-width: 640px) {
	.st-bracket-tree {
		--bracket-gap: 20px;
		--bracket-mw: 162px;
	}

	.st-bracket-team-name {
		max-width: 110px;
	}
}
```

- [ ] **Step 2: Verificar visualmente en browser**

Abrir cualquier página del portal público con un torneo que tenga playoffs. El tab de Playoffs aún mostrará el render de lista (Task 3 lo cambiará), pero inspeccionar con DevTools que las clases `.st-bracket-tree`, `.st-bracket-col`, `.st-bracket-match` están definidas en el CSS sin errores de sintaxis.

- [ ] **Step 3: Commit**

```bash
git add soccertrack/assets/css/tournament-page.css
git commit -m "feat(css): estilos del cuadro visual bracket tree para playoffs"
```

---

### Task 3: JS — Reemplazar renderPlayoffs() con árbol visual

**Files:**
- Modify: `soccertrack/assets/js/live-standings.js:654-716`

**Interfaces:**
- Consumes: clases CSS de Task 2, `escHtml()` (ya existe en el IIFE), `apiFetch()` (ya existe), `showLoading()`, `showEmpty()`, `showError()` (ya existen), variables `TID`, `i18n` (ya existen en el scope)
- Produces: `renderPlayoffs( container )` — misma firma que antes. Internamente usa helpers `buildBracketTree()` y `bracketMatchCard()` definidos en el mismo scope.

- [ ] **Step 1: Reemplazar el bloque completo de `renderPlayoffs` (líneas 654–716)**

En `soccertrack/assets/js/live-standings.js`, reemplazar desde `async function renderPlayoffs( container ) {` hasta el cierre `}` de esa función (inclusive) con el siguiente bloque. Verificar que las funciones `buildBracketTree` y `bracketMatchCard` quedan al mismo nivel de indentación que `renderPlayoffs` (dentro del IIFE):

```javascript
	async function renderPlayoffs( container ) {
		showLoading( container );

		try {
			const matches       = await apiFetch( `soccertrack/v1/public/tournament/${ TID }/fixture` );
			const playoffPhases = [ 'quarterfinal', 'semifinal', 'third_place', 'final' ];
			const playoffMatches = matches.filter( m => playoffPhases.includes( m.phase ) );

			if ( ! playoffMatches.length ) {
				return showEmpty( container, i18n.no_playoffs ?? 'Los play-offs aún no han comenzado.' );
			}

			// Agrupar por bracket_name (null → bracket genérico '__generic__').
			const bracketMap = new Map();
			for ( const m of playoffMatches ) {
				const key = m.bracket_name ?? '__generic__';
				if ( ! bracketMap.has( key ) ) {
					bracketMap.set( key, { name: m.bracket_name ?? ( i18n.playoffs_title ?? 'Play-offs' ), matches: [] } );
				}
				bracketMap.get( key ).matches.push( m );
			}

			let html = `<h2 class="st-section-title">🏆 ${ escHtml( i18n.playoffs_title ?? 'Play-offs' ) }</h2>`;

			for ( const [ , bracket ] of bracketMap ) {
				if ( bracketMap.size > 1 ) {
					html += `<h3 class="st-subsection-title">${ escHtml( bracket.name ) }</h3>`;
				}

				const qfMatches    = bracket.matches.filter( m => m.phase === 'quarterfinal' );
				const sfMatches    = bracket.matches.filter( m => m.phase === 'semifinal' );
				const thirdMatches = bracket.matches.filter( m => m.phase === 'third_place' );
				const finalMatches = bracket.matches.filter( m => m.phase === 'final' );

				html += buildBracketTree( { qfMatches, sfMatches, thirdMatches, finalMatches } );
			}

			container.innerHTML = html;

		} catch ( err ) {
			showError( container, `${ i18n.error_load ?? 'Error al cargar los play-offs.' } (${ err.message })` );
		}
	}

	/**
	 * Construye el HTML del cuadro bracket para un bracket.
	 * Adaptativo: 3 columnas (QF+SF+Final) si hay cuartos, 2 columnas (SF+Final) si no.
	 *
	 * @param {{ qfMatches: Array, sfMatches: Array, thirdMatches: Array, finalMatches: Array }} opts
	 * @returns {string} HTML del árbol bracket.
	 */
	function buildBracketTree( { qfMatches, sfMatches, thirdMatches, finalMatches } ) {
		const hasQF      = qfMatches.length >= 4;
		const treeClass  = hasQF ? 'st-bracket-tree--8' : 'st-bracket-tree--4';
		let cols = '';

		// Columna Cuartos de Final (solo si existen).
		if ( hasQF ) {
			// Par 1: QF[0] + QF[1]  → ganadores van a SF[0]
			// Par 2: QF[2] + QF[3]  → ganadores van a SF[1]
			cols += `
			<div class="st-bracket-col" data-round="qf">
				<h4 class="st-bracket-col-title">${ escHtml( i18n.phase_quarterfinal ?? 'Cuartos de Final' ) }</h4>
				<div class="st-bracket-col-matches">
					<div class="st-bracket-pair">
						${ bracketMatchCard( qfMatches[ 0 ] ) }
						${ bracketMatchCard( qfMatches[ 1 ] ) }
					</div>
					<div class="st-bracket-pair">
						${ bracketMatchCard( qfMatches[ 2 ] ) }
						${ bracketMatchCard( qfMatches[ 3 ] ) }
					</div>
				</div>
			</div>`;
		}

		// Columna Semifinales.
		if ( sfMatches.length ) {
			cols += `
			<div class="st-bracket-col" data-round="sf">
				<h4 class="st-bracket-col-title">${ escHtml( i18n.phase_semifinal ?? 'Semifinal' ) }</h4>
				<div class="st-bracket-col-matches">
					<div class="st-bracket-pair">
						${ sfMatches.map( m => bracketMatchCard( m ) ).join( '' ) }
					</div>
				</div>
			</div>`;
		}

		// Columna Final + 3.er Puesto.
		if ( finalMatches.length || thirdMatches.length ) {
			cols += `
			<div class="st-bracket-col" data-round="final">
				<h4 class="st-bracket-col-title">${ escHtml( i18n.phase_final ?? 'Final' ) }</h4>
				<div class="st-bracket-col-matches">
					<div class="st-bracket-pair">
						${ finalMatches.map( m => bracketMatchCard( m ) ).join( '' ) }
						${ thirdMatches.map( m => bracketMatchCard( m, true ) ).join( '' ) }
					</div>
				</div>
			</div>`;
		}

		return `<div class="st-bracket-tree ${ treeClass }">${ cols }</div>`;
	}

	/**
	 * Renderiza la tarjeta de un partido dentro del bracket tree.
	 *
	 * @param {Object}  m        Partido del API (puede ser undefined → muestra TBD).
	 * @param {boolean} isThird  true si es el partido por 3.er puesto.
	 * @returns {string} HTML de la tarjeta.
	 */
	function bracketMatchCard( m, isThird = false ) {
		if ( ! m ) {
			return `
			<div class="st-bracket-match">
				<div class="st-bracket-team st-bracket-team--tbd">
					<span class="st-bracket-team-name">?</span>
					<span class="st-bracket-score">-</span>
				</div>
				<div class="st-bracket-team st-bracket-team--tbd">
					<span class="st-bracket-team-name">?</span>
					<span class="st-bracket-score">-</span>
				</div>
			</div>`;
		}

		const isDone     = m.status === 'finished';
		const homeScore  = m.home_score ?? null;
		const awayScore  = m.away_score ?? null;
		const homeWins   = isDone && homeScore !== null && awayScore !== null && homeScore > awayScore;
		const awayWins   = isDone && homeScore !== null && awayScore !== null && awayScore > homeScore;
		const homeTbd    = ! m.home_team;
		const awayTbd    = ! m.away_team;

		const dateStr = m.match_datetime
			? new Date( m.match_datetime ).toLocaleDateString( 'es-CL', { day: 'numeric', month: 'short' } )
			: '';
		const statusStr = isDone
			? ( i18n.status_finished ?? 'Finalizado' )
			: ( m.status === 'in_progress'
				? ( i18n.status_live ?? 'En curso' )
				: ( dateStr || ( i18n.status_scheduled ?? 'Programado' ) ) );

		const thirdLabel = isThird
			? `<span class="st-bracket-phase-label">${ escHtml( i18n.phase_third_place ?? '3.er Puesto' ) }</span>`
			: '';

		return `
		<div class="st-bracket-match${ isThird ? ' st-bracket-match--third' : '' }">
			${ thirdLabel }
			<div class="st-bracket-team${ homeTbd ? ' st-bracket-team--tbd' : '' }${ homeWins ? ' st-bracket-team--winner' : '' }">
				<span class="st-bracket-team-name">${ homeTbd ? '?' : escHtml( m.home_team ) }</span>
				<span class="st-bracket-score">${ homeScore !== null ? homeScore : '-' }</span>
			</div>
			<div class="st-bracket-team${ awayTbd ? ' st-bracket-team--tbd' : '' }${ awayWins ? ' st-bracket-team--winner' : '' }">
				<span class="st-bracket-team-name">${ awayTbd ? '?' : escHtml( m.away_team ) }</span>
				<span class="st-bracket-score">${ awayScore !== null ? awayScore : '-' }</span>
			</div>
			<div class="st-bracket-match-meta">${ escHtml( statusStr ) }</div>
		</div>`;
	}
```

- [ ] **Step 2: Verificar en browser — bracket de 8 equipos**

1. Abrir el portal público de un torneo Swiss (o cualquier torneo con `round_robin_playoffs`) que tenga cuartos de final generados (4 partidos `phase=quarterfinal`)
2. Hacer click en el tab "Playoffs"
3. Confirmar: se ven 3 columnas (Cuartos de Final · Semifinal · Final)
4. Confirmar: cada tarjeta muestra los nombres de equipo y marcador (o "-" si no hay marcador)
5. En cuartos sin jugar: equipos en gris/itálica con "?"
6. En cuartos terminados: ganador resaltado en verde y negrita
7. El partido por 3.er puesto aparece con borde punteado y etiqueta "3.er Puesto"

- [ ] **Step 3: Verificar en browser — bracket de 4 equipos (sin cuartos)**

1. Abrir el portal de un torneo con playoffs pero solo semis generadas (sin cuartos)
2. Tab Playoffs → confirmar: se ven solo 2 columnas (Semifinal · Final)
3. Las líneas conectoras unen correctamente las semis hacia la final

- [ ] **Step 4: Verificar en browser — múltiples brackets**

1. Abrir el torneo Swiss demo (si existe, ID referenciado en el seeder) o un torneo con Copa Oro/Plata/Bronce configuradas
2. Confirmar: cada bracket tiene su propio árbol con `<h3>` de título
3. Los árboles se apilan verticalmente

- [ ] **Step 5: Verificar responsive en móvil**

1. En DevTools (Chrome/Firefox) activar vista móvil < 640px
2. Tab Playoffs → confirmar: el cuadro hace scroll horizontal sin romper el layout
3. Las tarjetas mantienen ancho mínimo legible (≥ 162px)

- [ ] **Step 6: Commit**

```bash
git add soccertrack/assets/js/live-standings.js
git commit -m "feat(js): cuadro visual bracket tree en tab Playoffs — árbol adaptativo 4/8 equipos"
```

---

## Criterios de aceptación

- [ ] Formulario de brackets visible en torneos `swiss` en el panel
- [ ] Tab Playoffs muestra árbol visual (no lista) cuando hay datos de playoffs
- [ ] Bracket de 8: 3 columnas QF → SF → Final
- [ ] Bracket de 4: 2 columnas SF → Final
- [ ] Celdas vacías muestran "?" en gris/itálica
- [ ] Ganador de cada partido resaltado en verde y negrita
- [ ] 3.er Puesto con borde punteado y etiqueta propia
- [ ] Múltiples brackets: cada uno con su árbol y título
- [ ] Scroll horizontal en móvil sin romper layout
