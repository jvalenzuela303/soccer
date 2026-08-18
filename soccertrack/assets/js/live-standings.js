/**
 * SoccerTrack — Portal Público · Pestañas reactivas
 *
 * Maneja la navegación por pestañas y carga asíncrona de datos
 * desde la REST API pública de SoccerTrack.
 *
 * Sin dependencias externas (vanilla JS ES2021).
 * Localización: stPublic.apiBase, stPublic.tournamentId, stPublic.basesUrl
 *
 * @package SoccerTrack
 */

( () => {
	'use strict';

	/** @type {{ apiBase: string, tournamentId: number, basesUrl: string, i18n: Record<string,string> }} */
	const cfg = window.stPublic ?? {};

	const API    = cfg.apiBase?.replace( /\/$/, '' ) ?? '';
	const TID    = Number( cfg.tournamentId ?? 0 );
	const FORMAT = cfg.tournamentFormat ?? '';
	const i18n   = cfg.i18n ?? {};

	/* Títulos de fase compartidos entre renderPlayoffs, renderKnockoutBracket y renderFixture. */
	const PHASE_TITLE = {
		octavos:      i18n.phase_octavos      ?? 'Octavos de Final',
		quarterfinal: i18n.phase_quarterfinal ?? 'Cuartos de Final',
		semifinal:    i18n.phase_semifinal    ?? 'Semi-finales',
		third_place:  i18n.phase_third_place  ?? '3.er Puesto',
		final:        i18n.phase_final        ?? 'Final',
	};

	// Cache de respuestas por tab para evitar refetches.
	/** @type {Map<string, any>} */
	const cache = new Map();

	/** Retorna el nombre a mostrar para un equipo; 'LIBRE (Descanso)' si es fantasma. */
	function teamDisplay( name, isGhost ) {
		return parseInt( isGhost, 10 ) === 1 ? ( i18n.bye_team ?? 'LIBRE (Descanso)' ) : escHtml( name ?? '—' );
	}

	/* ------------------------------------------------------------------ */
	/* Helpers de UI                                                        */
	/* ------------------------------------------------------------------ */

	const el  = ( id ) => document.getElementById( id );
	const qs  = ( sel, ctx = document ) => ctx.querySelector( sel );
	const qsa = ( sel, ctx = document ) => [ ...ctx.querySelectorAll( sel ) ];

	function showLoading( container ) {
		container.innerHTML = `
			<div class="st-loading" role="status" aria-live="polite">
				<span class="st-spinner" aria-hidden="true"></span>
				<span>${ i18n.loading ?? 'Cargando…' }</span>
			</div>`;
	}

	function showError( container, msg ) {
		container.innerHTML = `<p class="st-error-msg" role="alert">${ escHtml( msg ) }</p>`;
	}

	function showEmpty( container, msg ) {
		container.innerHTML = `<p class="st-empty-msg">${ escHtml( msg ) }</p>`;
	}

	function escHtml( str ) {
		return String( str )
			.replace( /&/g, '&amp;' )
			.replace( /</g, '&lt;' )
			.replace( />/g, '&gt;' )
			.replace( /"/g, '&quot;' );
	}

	function fmt( val ) {
		return val ?? '—';
	}

	/**
	 * Formatea "2026-08-08 10:00:00" → "Sáb 08/08 10:00"
	 * @param {string} dt
	 */
	function fmtDatetime( dt ) {
		if ( ! dt ) return '';
		try {
			const d = new Date( dt.replace( ' ', 'T' ) );
			const days = [ 'Dom', 'Lun', 'Mar', 'Mié', 'Jue', 'Vie', 'Sáb' ];
			const day  = days[ d.getDay() ];
			const dd   = String( d.getDate() ).padStart( 2, '0' );
			const mm   = String( d.getMonth() + 1 ).padStart( 2, '0' );
			const hh   = String( d.getHours() ).padStart( 2, '0' );
			const min  = String( d.getMinutes() ).padStart( 2, '0' );
			return `${ day } ${ dd }/${ mm } ${ hh }:${ min }`;
		} catch {
			return dt;
		}
	}

	function teamInitials( name ) {
		if ( ! name ) return '?';
		// Palabras ignoradas al calcular iniciales
		const SKIP = new Set( [ 'de', 'del', 'la', 'las', 'el', 'los', 'y', 'e', 'fc', 'cf', 'sc', 'ac', 'united', 'pro', 'club' ] );
		const words = name.trim().split( /\s+/ ).filter( w => ! SKIP.has( w.toLowerCase() ) );
		if ( words.length === 0 ) return name[0].toUpperCase();
		if ( words.length === 1 ) return words[0].slice( 0, 2 ).toUpperCase();
		return ( words[0][0] + words[1][0] ).toUpperCase();
	}

	// Paleta de colores para avatares — se asigna por hash del nombre
	const AVATAR_COLORS = [
		'#3CBC20', '#0E6BC5', '#E63946', '#F4A261', '#6A4C93',
		'#1D7874', '#E76F51', '#264653', '#2A9D8F', '#8338EC',
	];

	function avatarColor( name ) {
		let h = 0;
		for ( let i = 0; i < ( name?.length ?? 0 ); i++ ) {
			h = ( h * 31 + name.charCodeAt( i ) ) >>> 0;
		}
		return AVATAR_COLORS[ h % AVATAR_COLORS.length ];
	}

	function logoOrPlaceholder( url, name, classes = 'st-team-logo' ) {
		if ( url ) {
			return `<img src="${ escHtml( url ) }" alt="${ escHtml( name ) }" class="${ classes }" loading="lazy">`;
		}
		const initials = teamInitials( name );
		const bg = avatarColor( name );
		return `<span class="st-team-avatar" style="background:${ bg }" aria-label="${ escHtml( name ) }">${ escHtml( initials ) }</span>`;
	}

	/* ------------------------------------------------------------------ */
	/* Fetch helper                                                         */
	/* ------------------------------------------------------------------ */

	async function apiFetch( endpoint ) {
		const url = `${ API }/${ endpoint }`;

		if ( cache.has( url ) ) {
			return cache.get( url );
		}

		const resp = await fetch( url, {
			method:  'GET',
			headers: { 'Accept': 'application/json' },
		} );

		if ( ! resp.ok ) {
			throw new Error( `HTTP ${ resp.status } — ${ resp.statusText }` );
		}

		const data = await resp.json();
		cache.set( url, data );
		return data;
	}

	/* ------------------------------------------------------------------ */
	/* Renderizadores de pestañas                                          */
	/* ------------------------------------------------------------------ */

	async function renderStandings( container ) {
		showLoading( container );

		try {
			if ( FORMAT === 'knockout' ) {
				return renderKnockoutBracket( container );
			}

			if ( FORMAT === 'group_stage' ) {
				await renderGroupStandings( container );
			} else {
				await renderSingleStandings( container );
			}
		} catch ( err ) {
			showError( container, `${ i18n.error_load ?? 'Error al cargar posiciones.' } (${ err.message })` );
		}
	}

	async function renderGroupStandings( container ) {
		const groups = await apiFetch( `soccertrack/v1/public/tournament/${ TID }/groups` );

		if ( ! groups.length ) {
			return showEmpty( container, i18n.no_standings ?? 'Aún no hay partidos jugados.' );
		}

		let html = `<h2 class="st-section-title">${ i18n.standings_title ?? 'Tabla de Posiciones' }</h2>`;

		for ( const group of groups ) {
			const rows = group.standings ?? [];
			// Detect advancing count from first group where all teams have played.
			// (The server handles the actual logic; we just mark the top rows visually.)
			// The spec says "las primeras teams_advancing_per_group filas" — since we don't know
			// that value client-side, mark top-2 by default (safe for 2-groups × 2 advancing).
			// For precise marking, the /groups response includes it implicitly via ordering.

			const trs = rows.map( ( r, idx ) => {
				const pos  = idx + 1;
				const zone = pos <= 2 ? 'playoff' : ''; // top-2 as qualifying zone visual
				const formBubbles = ( r.form ?? [] ).map( result => {
					const cls = result === 'W' ? 'w' : result === 'D' ? 'd' : 'l';
					const lbl = result === 'W' ? 'V' : result === 'D' ? 'E' : 'D';
					return `<span class="st-form-bubble st-form-bubble--${ cls }">${ lbl }</span>`;
				} ).join( '' );
				return `
				<tr${ zone ? ` data-zone="${ zone }"` : '' }>
					<td class="st-rank">${ pos }</td>
					<td>${ escHtml( r.name ) }</td>
					<td>${ r.pj }</td>
					<td>${ r.pg }</td>
					<td>${ r.pe }</td>
					<td>${ r.pp }</td>
					<td>${ r.gf }</td>
					<td>${ r.gc }</td>
					<td>${ r.dg >= 0 ? '+' : '' }${ r.dg }</td>
					<td class="st-pts">${ r.pts }</td>
					<td class="st-form">${ formBubbles }</td>
				</tr>`;
			} ).join( '' );

			html += `
			<h3 class="st-subsection-title" style="margin-top:1.5rem;color:#0E0C19">${ escHtml( i18n.group_label ?? 'Grupo' ) } ${ escHtml( group.label ) }</h3>
			<div class="st-table-wrap" style="margin-bottom:1rem">
				<table class="st-table st-standings-table" aria-label="Grupo ${ escHtml( group.label ) }">
					<thead>
						<tr>
							<th>#</th>
							<th>${ i18n.team ?? 'Equipo' }</th>
							<th title="${ i18n.played ?? 'Jugados' }">PJ</th>
							<th title="${ i18n.won ?? 'Ganados' }">PG</th>
							<th title="${ i18n.drawn ?? 'Empatados' }">PE</th>
							<th title="${ i18n.lost ?? 'Perdidos' }">PP</th>
							<th title="${ i18n.gf ?? 'Goles a Favor' }">GF</th>
							<th title="${ i18n.gc ?? 'Goles en Contra' }">GC</th>
							<th title="${ i18n.dg ?? 'Diferencia de Goles' }">DG</th>
							<th title="${ i18n.pts ?? 'Puntos' }">PTS</th>
							<th title="${ escHtml( i18n.form ?? 'Últimos 5 partidos' ) }">Forma</th>
						</tr>
					</thead>
					<tbody>${ trs }</tbody>
				</table>
			</div>`;
		}

		container.innerHTML = html;
	}

	async function renderSingleStandings( container ) {
		const rows = await apiFetch( `soccertrack/v1/public/tournament/${ TID }/standings` );

		if ( ! rows.length ) {
			return showEmpty( container, i18n.no_standings ?? 'Aún no hay partidos jugados.' );
		}

		const total = rows.length;
		// Zona playoff: top 4 (o top 2 si hay ≤ 6 equipos).
		const playoffCut = total <= 6 ? 2 : 4;

		// ── Leader cards ────────────────────────────────────────────────────
		const activeRows = rows.filter( r => r.pj > 0 );
		let leaderCardsHtml = '';

		if ( activeRows.length ) {
			const bestAttack  = activeRows.reduce( ( a, b ) => b.gf > a.gf ? b : a );
			const bestDefense = activeRows.reduce( ( a, b ) => b.gc < a.gc ? b : a );
			const bestStreak  = activeRows.reduce( ( a, b ) => ( b.win_streak ?? 0 ) > ( a.win_streak ?? 0 ) ? b : a );

			leaderCardsHtml = `
			<div class="st-leader-cards">
				<div class="st-leader-card">
					<span class="st-leader-card__icon">⚔️</span>
					<span class="st-leader-card__title">${ escHtml( i18n.best_attack ?? 'Mejor Ataque' ) }</span>
					<span class="st-leader-card__team">${ escHtml( bestAttack.name ) }</span>
					<span class="st-leader-card__value">${ bestAttack.gf } ${ escHtml( i18n.goals_for_label ?? 'goles' ) }</span>
				</div>
				<div class="st-leader-card">
					<span class="st-leader-card__icon">🛡</span>
					<span class="st-leader-card__title">${ escHtml( i18n.best_defense ?? 'Mejor Defensa' ) }</span>
					<span class="st-leader-card__team">${ escHtml( bestDefense.name ) }</span>
					<span class="st-leader-card__value">${ bestDefense.gc } ${ escHtml( i18n.goals_against_label ?? 'en contra' ) }</span>
				</div>
				<div class="st-leader-card">
					<span class="st-leader-card__icon">🔥</span>
					<span class="st-leader-card__title">${ escHtml( i18n.current_streak ?? 'Racha Actual' ) }</span>
					<span class="st-leader-card__team">${ escHtml( bestStreak.name ) }</span>
					<span class="st-leader-card__value">${ bestStreak.win_streak ?? 0 } ${ escHtml( i18n.win_streak_label ?? 'victorias' ) }</span>
				</div>
			</div>`;
		}

		// ── Rows ────────────────────────────────────────────────────────────
		// Detectar si algún equipo tiene bracket_name.
		const hasBrackets = rows.some( r => r.bracket_name );

		const trs = rows.map( ( r, idx ) => {
			const pos  = idx + 1;
			const zone = pos <= playoffCut ? 'playoff' : ( pos > total - 2 && total > 4 ? 'danger' : '' );
			const rankStyle = pos === 1 ? ' style="color:#f39c12"' : pos === 2 ? ' style="color:#95a5a6"' : pos === 3 ? ' style="color:#cd7f32"' : '';

			const winRate = r.pj > 0 ? Math.round( r.pg / r.pj * 100 ) : '—';

			const formBubbles = ( r.form ?? [] ).map( result => {
				const cls = result === 'W' ? 'w' : result === 'D' ? 'd' : 'l';
				const lbl = result === 'W' ? 'V' : result === 'D' ? 'E' : 'D';
				return `<span class="st-form-bubble st-form-bubble--${ cls }">${ lbl }</span>`;
			} ).join( '' );

			const bracketCell = hasBrackets
				? `<td class="st-bracket-cell">${ r.bracket_name ? escHtml( r.bracket_name ) : '—' }</td>`
				: '';

			return `
			<tr${ zone ? ` data-zone="${ zone }"` : '' }>
				<td class="st-rank"${ rankStyle }>${ pos }</td>
				<td style="display:flex;align-items:center;gap:8px">${ logoOrPlaceholder( r.logo_url, r.name ) }<span>${ escHtml( r.name ) }</span></td>
				<td>${ r.pj }</td>
				<td>${ r.pg }</td>
				<td>${ r.pe }</td>
				<td>${ r.pp }</td>
				<td>${ r.gf }</td>
				<td>${ r.gc }</td>
				<td>${ r.dg >= 0 ? '+' : '' }${ r.dg }</td>
				<td class="st-pts">${ r.pts }</td>
				<td class="st-winrate">${ winRate }${ r.pj > 0 ? '%' : '' }</td>
				<td class="st-form">${ formBubbles }</td>
				${ bracketCell }
			</tr>`;
		} ).join( '' );

		// ── Zone legend ─────────────────────────────────────────────────────
		const zoneLegendHtml = rows.length >= 4
			? `
			<div class="st-zone-legend">
				<span class="st-zone-dot st-zone-dot--playoff"></span> ${ escHtml( i18n.zone_playoff ?? 'Zona playoff' ) }
				<span class="st-zone-dot st-zone-dot--danger"></span> ${ escHtml( i18n.zone_danger ?? 'Zona peligro' ) }
			</div>`
			: '';

		// ── Bar charts (estadísticas) — usando datos de standings ya disponibles ──
		let chartsHtml = '';
		if ( activeRows.length ) {
			const buildChart = ( chartRows, modifier ) => {
				const max = Math.max( ...chartRows.map( r => r.val ), 1 );
				return `<div class="st-chart">${ chartRows.map( r => {
					const pct = Math.round( r.val / max * 100 );
					return `
					<div class="st-bar-row">
						<span class="st-bar-label" title="${ escHtml( r.name ) }">${ escHtml( r.name ) }</span>
						<div class="st-bar-track">
							<div class="st-bar-fill st-bar-fill--${ modifier }" style="width:${ pct }%"></div>
						</div>
						<span class="st-bar-value">${ r.val }</span>
					</div>`;
				} ).join( '' ) }</div>`;
			};

			const byGF  = [ ...activeRows ].sort( ( a, b ) => b.gf  - a.gf  ).map( r => ( { name: r.name, val: r.gf  } ) );
			const byGC  = [ ...activeRows ].sort( ( a, b ) => a.gc  - b.gc  ).map( r => ( { name: r.name, val: r.gc  } ) );
			const byPG  = [ ...activeRows ].sort( ( a, b ) => b.pg  - a.pg  ).map( r => ( { name: r.name, val: r.pg  } ) );
			const byPTS = [ ...activeRows ].sort( ( a, b ) => b.pts - a.pts ).map( r => ( { name: r.name, val: r.pts } ) );

			chartsHtml = `
			<h3 class="st-subsection-title" style="margin-top:2rem">${ escHtml( i18n.records_charts_title ?? 'Comparativa de equipos' ) }</h3>
			<div class="st-charts-grid">
				<div class="st-chart-wrap">
					<h4 class="st-chart-title">⚽ ${ escHtml( i18n.goals_scored ?? 'Goles Anotados' ) }</h4>
					${ buildChart( byGF, 'attack' ) }
				</div>
				<div class="st-chart-wrap">
					<h4 class="st-chart-title">🛡 ${ escHtml( i18n.goals_conceded ?? 'Goles Concedidos' ) } <small class="st-chart-hint">${ escHtml( i18n.lower_is_better ?? 'menos es mejor' ) }</small></h4>
					${ buildChart( byGC, 'defense' ) }
				</div>
				<div class="st-chart-wrap">
					<h4 class="st-chart-title">🏆 ${ escHtml( i18n.wins ?? 'Victorias' ) }</h4>
					${ buildChart( byPG, 'wins' ) }
				</div>
				<div class="st-chart-wrap">
					<h4 class="st-chart-title">📊 ${ escHtml( i18n.pts ?? 'Puntos' ) }</h4>
					${ buildChart( byPTS, 'pts' ) }
				</div>
			</div>`;
		}

		const bracketHeader = hasBrackets
			? `<th title="Copa / Bracket">Copa</th>`
			: '';

		container.innerHTML = `
			<h2 class="st-section-title">${ i18n.standings_title ?? 'Tabla de Posiciones' }</h2>
			${ leaderCardsHtml }
			<div class="st-table-wrap">
				<table class="st-table st-standings-table" aria-label="${ i18n.standings_title ?? 'Tabla de Posiciones' }">
					<thead>
						<tr>
							<th>#</th>
							<th>${ i18n.team ?? 'Equipo' }</th>
							<th title="${ i18n.played ?? 'Jugados' }">PJ</th>
							<th title="${ i18n.won ?? 'Ganados' }">PG</th>
							<th title="${ i18n.drawn ?? 'Empatados' }">PE</th>
							<th title="${ i18n.lost ?? 'Perdidos' }">PP</th>
							<th title="${ i18n.gf ?? 'Goles a Favor' }">GF</th>
							<th title="${ i18n.gc ?? 'Goles en Contra' }">GC</th>
							<th title="${ i18n.dg ?? 'Diferencia de Goles' }">DG</th>
							<th title="${ i18n.pts ?? 'Puntos' }">PTS</th>
							<th title="% victorias">%</th>
							<th title="${ escHtml( i18n.form ?? 'Últimos 5 partidos' ) }">Forma</th>
							${ bracketHeader }
						</tr>
					</thead>
					<tbody>${ trs }</tbody>
				</table>
			</div>
			${ zoneLegendHtml }
			<div class="st-form-legend">
				<span class="st-form-bubble st-form-bubble--w">V</span> ${ escHtml( i18n.form_win ?? 'Victoria' ) }
				<span class="st-form-bubble st-form-bubble--d">E</span> ${ escHtml( i18n.form_draw ?? 'Empate' ) }
				<span class="st-form-bubble st-form-bubble--l">D</span> ${ escHtml( i18n.form_loss ?? 'Derrota' ) }
			</div>
			${ chartsHtml }`;
	}

	/** Helpers de tarjetas de partido compartidos entre renderFixture y renderPlayoffs. */
	const statusLabel = ( s ) => {
		const map = { finished: i18n.status_finished ?? 'Finalizado', scheduled: i18n.status_scheduled ?? 'Programado', in_progress: i18n.status_live ?? 'En curso' };
		const cls = { finished: 'finished', scheduled: 'scheduled', in_progress: 'live' };
		return `<span class="st-badge st-badge--${ cls[ s ] ?? 'scheduled' }">${ map[ s ] ?? escHtml( s ) }</span>`;
	};

	const matchCard = ( m ) => {
		const hasScore = m.status === 'finished' || m.status === 'in_progress';
		return `
		<div class="st-match-card">
			<div class="st-match-team">
				${ logoOrPlaceholder( m.home_logo, m.home_team ) }
				<span>${ teamDisplay( m.home_team, m.home_is_ghost ) }</span>
			</div>
			<div class="st-match-center">
				<div class="st-match-score">
					<span>${ hasScore ? m.home_score : '—' }</span>
					<span class="st-match-score-sep">:</span>
					<span>${ hasScore ? m.away_score : '—' }</span>
				</div>
				<div class="st-match-meta">
					${ statusLabel( m.status ) }<br>
					${ fmtDatetime( m.match_datetime ) }<br>
					${ m.venue ? escHtml( m.venue ) : '' }${ m.court_name ? ' · ' + escHtml( m.court_name ) : '' }
				</div>
			</div>
			<div class="st-match-team st-match-team--away">
				${ logoOrPlaceholder( m.away_logo, m.away_team ) }
				<span>${ teamDisplay( m.away_team, m.away_is_ghost ) }</span>
			</div>
		</div>`;
	};

	async function renderFixture( container ) {
		showLoading( container );

		try {
			const matches = await apiFetch( `soccertrack/v1/public/tournament/${ TID }/fixture` );

			if ( ! matches.length ) {
				return showEmpty( container, i18n.no_fixture ?? 'El fixture aún no ha sido generado.' );
			}

			if ( FORMAT === 'knockout' ) {
				// Todos los partidos son de eliminación directa — sin navegación por jornadas.
				const phases = [ 'octavos', 'quarterfinal', 'semifinal', 'third_place', 'final' ];
				const knockoutMatches = matches.filter(
					m => phases.includes( m.phase ) && m.away_team_id !== null
				);
				if ( ! knockoutMatches.length ) {
					return showEmpty( container, i18n.no_fixture ?? 'El fixture aún no ha sido generado.' );
				}
				let html = '';
				for ( const phase of phases ) {
					const ms = knockoutMatches.filter( m => m.phase === phase );
					if ( ! ms.length ) continue;
					html += `<h2 class="st-section-title">${ escHtml( PHASE_TITLE[ phase ] ) }</h2>`;
					html += ms.map( matchCard ).join( '' );
				}
				container.innerHTML = html;
				return;
			}

			// Separar partidos regulares de eliminatoria.
			const PLAYOFF_PHASES = [ 'quarterfinal', 'semifinal', 'third_place', 'final' ];
			const regularMatches  = matches.filter( m => ! PLAYOFF_PHASES.includes( m.phase ) );
			const playoffMatches  = matches.filter( m =>   PLAYOFF_PHASES.includes( m.phase ) );
			const isGroupStage    = FORMAT === 'group_stage';

			let html = `<h2 class="st-section-title">${ i18n.fixture_title ?? 'Fixture' }</h2>`;

			if ( isGroupStage && regularMatches.some( m => m.group_label ) ) {
				// Agrupar por group_label, luego por round dentro de cada grupo.
				/** @type {Map<string, typeof matches>} */
				const groupMap = new Map();
				for ( const m of regularMatches ) {
					const lbl = m.group_label ?? 'Sin Grupo';
					if ( ! groupMap.has( lbl ) ) groupMap.set( lbl, [] );
					groupMap.get( lbl ).push( m );
				}

				const sortedGroups = [ ...groupMap.entries() ].sort( ( a, b ) => a[0].localeCompare( b[0] ) );

				for ( const [ lbl, gMatches ] of sortedGroups ) {
					html += `<h2 class="st-section-title" style="margin-top:1.5rem">${ escHtml( i18n.group_label ?? 'Grupo' ) } ${ escHtml( lbl ) }</h2>`;

					const gRounds = new Map();
					for ( const m of gMatches ) {
						const r = Number( m.round_number );
						if ( ! gRounds.has( r ) ) gRounds.set( r, [] );
						gRounds.get( r ).push( m );
					}

					for ( const [ r, ms ] of [ ...gRounds.entries() ].sort( ( a, b ) => a[0] - b[0] ) ) {
						html += `<h3 class="st-round-header" style="font-size:.85rem;margin:8px 0 4px">${ i18n.round ?? 'Fecha' } ${ r }</h3>`;
						html += ms.map( matchCard ).join( '' );
					}
				}

				container.innerHTML = html;

				if ( playoffMatches.length ) {
					injectPlayoffsTab( playoffMatches );
				}

			} else {
				// Render clásico por jornadas (round-robin, etc.).
				/** @type {Map<number, typeof matches>} */
				const rounds = new Map();
				for ( const m of regularMatches ) {
					const r = Number( m.round_number );
					if ( ! rounds.has( r ) ) rounds.set( r, [] );
					rounds.get( r ).push( m );
				}

				const sortedRounds = [ ...rounds.entries() ].sort( ( a, b ) => a[0] - b[0] );

				const activeRound = sortedRounds.find(
					( [ , ms ] ) => ms.some( m => m.status !== 'finished' )
				)?.[0] ?? sortedRounds.at( -1 )?.[0] ?? 1;

				const renderRound = ( roundNum ) => {
					const ms = rounds.get( roundNum ) ?? [];
					return ms.map( matchCard ).join( '' );
				};

				if ( sortedRounds.length ) {
					const roundNums = sortedRounds.map( ( [ r ] ) => r );
					const btnHtml = roundNums.map( r => {
						const isFinished = rounds.get( r ).every( m => m.status === 'finished' );
						const isFuture   = rounds.get( r ).every( m => m.status === 'scheduled' ) && r > activeRound;
						const label      = isFuture ? `${ r } 🔒` : String( r );
						return `<button class="st-round-btn${ r === activeRound ? ' st-round-btn--active' : '' }${ isFuture ? ' st-round-btn--locked' : '' }${ isFinished ? ' st-round-btn--done' : '' }"
							data-round="${ r }" aria-label="Fecha ${ r }"${ isFuture ? ' disabled title="Jornada no disponible aún"' : '' }>${ label }</button>`;
					} ).join( '' );

					html += `
						<div class="st-round-nav" role="group" aria-label="Navegación por jornadas">
							${ btnHtml }
						</div>
						<div class="st-fixture-current" id="st-fixture-matches">
							<h3 class="st-round-header">${ i18n.round ?? 'Fecha' } ${ activeRound }</h3>
							${ renderRound( activeRound ) }
						</div>`;
				}

				container.innerHTML = html;

				// Navegación entre jornadas.
				const roundNav = container.querySelector( '.st-round-nav' );
				if ( roundNav ) {
					roundNav.addEventListener( 'click', ( e ) => {
						const btn = e.target.closest( '.st-round-btn' );
						if ( ! btn || btn.disabled ) return;

						const r = Number( btn.dataset.round );
						container.querySelectorAll( '.st-round-btn' ).forEach( b => b.classList.toggle( 'st-round-btn--active', b === btn ) );
						const panel = container.querySelector( '#st-fixture-matches' );
						panel.innerHTML = `<h3 class="st-round-header">${ i18n.round ?? 'Fecha' } ${ r }</h3>${ renderRound( r ) }`;
					} );
				}

				// Para Swiss: inyectar tab de playoffs si hay partidos de copa.
				if ( playoffMatches.length ) {
					injectPlayoffsTab( playoffMatches );
				}
			}

		} catch ( err ) {
			showError( container, `${ i18n.error_load ?? 'Error al cargar el fixture.' } (${ err.message })` );
		}
	}

	async function renderPlayoffs( container ) {
		showLoading( container );

		try {
			// La respuesta ya estará en caché si el fixture tab se cargó primero.
			const matches = await apiFetch( `soccertrack/v1/public/tournament/${ TID }/fixture` );
			const playoffPhases  = [ 'quarterfinal', 'semifinal', 'third_place', 'final' ];
			const playoffMatches = matches.filter( m => playoffPhases.includes( m.phase ) );

			if ( ! playoffMatches.length ) {
				return showEmpty( container, i18n.no_playoffs ?? 'Los play-offs aún no han comenzado.' );
			}

			const phaseTitle = PHASE_TITLE;

			// Agrupar por bracket_name (null → bracket genérico).
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

				const octMatches   = bracket.matches.filter( m => m.phase === 'octavos' );
				const qfMatches    = bracket.matches.filter( m => m.phase === 'quarterfinal' );
				const sfMatches    = bracket.matches.filter( m => m.phase === 'semifinal' );
				const thirdMatches = bracket.matches.filter( m => m.phase === 'third_place' );
				const finalMatches = bracket.matches.filter( m => m.phase === 'final' );

				for ( const [ phase, group ] of [
					[ 'octavos',     octMatches   ],
					[ 'quarterfinal', qfMatches    ],
					[ 'semifinal',    sfMatches    ],
					[ 'third_place',  thirdMatches ],
					[ 'final',        finalMatches ],
				] ) {
					if ( ! group.length ) continue;
					const countClass = `st-bracket-matches--${ group.length }`;
					html += `
					<div class="st-bracket-round">
						<h3 class="st-bracket-round-title">${ escHtml( phaseTitle[ phase ] ) }</h3>
						<div class="st-bracket-matches ${ countClass }">
							${ group.map( matchCard ).join( '' ) }
						</div>
					</div>`;
				}
			}

			container.innerHTML = html;

		} catch ( err ) {
			showError( container, `${ i18n.error_load ?? 'Error al cargar los play-offs.' } (${ err.message })` );
		}
	}

	/**
	 * Renderiza el cuadro de eliminación directa en el contenedor dado.
	 * Usado en el tab "Posiciones" cuando el formato es knockout.
	 *
	 * @param {HTMLElement} container
	 */
	async function renderKnockoutBracket( container ) {
		showLoading( container );

		try {
			const matches         = await apiFetch( `soccertrack/v1/public/tournament/${ TID }/fixture` );
			const phases          = [ 'octavos', 'quarterfinal', 'semifinal', 'third_place', 'final' ];
			// Excluir byes (away_team_id === null en la respuesta REST significa away_team_id IS NULL en DB).
			const knockoutMatches = matches.filter(
				m => phases.includes( m.phase ) && m.away_team_id !== null
			);

			if ( ! knockoutMatches.length ) {
				return showEmpty( container, i18n.no_fixture ?? 'El fixture aún no ha sido generado.' );
			}

			let html = `<h2 class="st-section-title">${ escHtml( i18n.playoffs_title ?? 'Eliminación Directa' ) }</h2>`;

			for ( const phase of phases ) {
				const ms = knockoutMatches.filter( m => m.phase === phase );
				if ( ! ms.length ) continue;
				html += `<h3 class="st-bracket-round-title" style="font-size:.9rem;margin:1rem 0 .4rem">${ escHtml( PHASE_TITLE[ phase ] ?? phase ) }</h3>`;
				html += ms.map( matchCard ).join( '' );
			}

			// Banner de torneo finalizado.
			const finalMatch = knockoutMatches.find( m => m.phase === 'final' );
			if ( finalMatch && finalMatch.status === 'finished' ) {
				html += `<p style="color:var(--st-green-primary);font-weight:700;margin-top:1rem">🏆 ${ escHtml( i18n.tournament_complete ?? 'Torneo finalizado.' ) }</p>`;
			}

			container.innerHTML = html;

		} catch ( err ) {
			showError( container, `${ i18n.error_load ?? 'Error al cargar.' } (${ err.message })` );
		}
	}

	/**
	 * Inyecta dinámicamente la pestaña "Playoffs" en el nav y su panel en el DOM
	 * cuando un torneo de fase de grupos detecta partidos de eliminación directa.
	 * Idempotente: sale silenciosamente si la pestaña ya existe.
	 *
	 * @param {Array} playoffMatches - Array de partidos de playoffs (ya filtrado).
	 */
	function injectPlayoffsTab( playoffMatches ) {
		if ( qs( '#st-tab-playoffs' ) ) return; // ya existe

		const nav = qs( '.st-tabs-nav' );
		if ( ! nav ) return;

		// --- Crear botón de pestaña ---
		const li  = document.createElement( 'li' );
		li.setAttribute( 'role', 'presentation' );
		const btn = document.createElement( 'button' );
		btn.className   = 'st-tab-btn';
		btn.setAttribute( 'role', 'tab' );
		btn.dataset.tab = 'playoffs';
		btn.id          = 'st-tab-playoffs';
		btn.setAttribute( 'aria-selected', 'false' );
		btn.setAttribute( 'aria-controls', 'st-panel-playoffs' );
		btn.setAttribute( 'tabindex', '-1' );
		btn.textContent = `🏆 ${ i18n.playoffs_title ?? 'Playoffs' }`;
		li.appendChild( btn );
		nav.appendChild( li );

		// --- Crear panel e insertarlo antes del panel de equipos ---
		const panel = document.createElement( 'section' );
		panel.id        = 'st-panel-playoffs';
		panel.className = 'st-tab-panel';
		panel.setAttribute( 'role', 'tabpanel' );
		panel.setAttribute( 'aria-labelledby', 'st-tab-playoffs' );
		panel.setAttribute( 'aria-hidden', 'true' );

		const teamsPanel = qs( '#st-panel-teams' );
		if ( teamsPanel ) {
			teamsPanel.before( panel );
		} else {
			nav.parentElement?.appendChild( panel );
		}

		// Registrar renderer si por algún motivo no estuviera aún disponible.
		if ( ! RENDERERS.playoffs ) {
			RENDERERS.playoffs = renderPlayoffs;
		}
	}

	async function renderTeams( container ) {
		showLoading( container );

		try {
			const teams = await apiFetch( `soccertrack/v1/public/tournament/${ TID }/teams` );

			if ( ! teams.length ) {
				return showEmpty( container, i18n.no_teams ?? 'No hay equipos inscritos.' );
			}

			const cards = teams.map( t => `
				<div class="st-team-card">
					${ logoOrPlaceholder( t.logo_url, t.name, 'st-team-card__logo' ) }
					<div class="st-team-card__name">${ escHtml( t.name ) }</div>
				</div>` ).join( '' );

			container.innerHTML = `
				<h2 class="st-section-title">${ i18n.teams_title ?? 'Equipos' }</h2>
				<div class="st-teams-grid">${ cards }</div>`;
		} catch ( err ) {
			showError( container, `${ i18n.error_load ?? 'Error al cargar equipos.' } (${ err.message })` );
		}
	}

	async function renderScorers( container ) {
		showLoading( container );

		try {
			const [ rows, standings ] = await Promise.all( [
				apiFetch( `soccertrack/v1/public/tournament/${ TID }/scorers` ),
				apiFetch( `soccertrack/v1/public/tournament/${ TID }/standings` ),
			] );

			if ( ! rows.length ) {
				return showEmpty( container, i18n.no_scorers ?? 'Aún no hay goles registrados.' );
			}

			// Build a map of team_name → pj from standings.
			/** @type {Record<string, number>} */
			const teamPjMap = {};
			for ( const s of ( standings ?? [] ) ) {
				teamPjMap[ s.name ] = Number( s.pj ?? 0 );
			}

			const trs = rows.map( ( r, idx ) => {
				const goals   = Number( r.goals   ?? 0 );
				const yellows = Number( r.yellows ?? 0 );
				const reds    = Number( r.reds    ?? 0 );
				const pj      = teamPjMap[ r.team_name ] ?? 0;
				const medalClass = idx === 0 ? ' st-scorer--gold' : idx === 1 ? ' st-scorer--silver' : idx === 2 ? ' st-scorer--bronze' : '';
				return `
				<tr class="${ medalClass.trim() }">
					<td class="st-rank" style="width:36px">${ idx + 1 }</td>
					<td><strong>${ escHtml( r.first_name ) } ${ escHtml( r.last_name ) }</strong></td>
					<td style="font-size:.8rem;color:#888">${ escHtml( r.team_name ) }</td>
					<td style="text-align:center;font-weight:700;color:var(--st-green-primary)">${ goals > 0 ? '⚽ ' + goals : '—' }</td>
					<td style="text-align:center">${ yellows > 0 ? '🟡 ' + yellows : '—' }</td>
					<td style="text-align:center">${ reds > 0 ? '🔴 ' + reds : '—' }</td>
					<td style="text-align:center;font-size:.85rem;color:#666">${ pj > 0 ? ( goals / pj ).toFixed( 1 ) : '—' }</td>
				</tr>`;
			} ).join( '' );

			container.innerHTML = `
				<h2 class="st-section-title">${ i18n.scorers_title ?? 'Goleadores y Estadísticas' }</h2>
				<div class="st-table-wrap">
					<table class="st-table" aria-label="${ i18n.scorers_title ?? 'Goleadores' }">
						<thead>
							<tr>
								<th>#</th>
								<th>${ i18n.player ?? 'Jugador' }</th>
								<th>${ i18n.team ?? 'Equipo' }</th>
								<th style="text-align:center" title="Goles">⚽</th>
								<th style="text-align:center" title="Tarjetas amarillas">🟡</th>
								<th style="text-align:center" title="Tarjetas rojas">🔴</th>
								<th style="text-align:center" title="Goles por partido">G/PJ</th>
							</tr>
						</thead>
						<tbody>${ trs }</tbody>
					</table>
				</div>`;
		} catch ( err ) {
			showError( container, `${ i18n.error_load ?? 'Error al cargar.' } (${ err.message })` );
		}
	}

	function renderBases( container ) {
		const url = cfg.basesUrl ?? '';

		if ( ! url ) {
			return showEmpty( container, i18n.no_bases ?? 'Las bases del torneo no están disponibles aún.' );
		}

		container.innerHTML = `
			<h2 class="st-section-title">${ i18n.bases_title ?? 'Bases del Torneo' }</h2>
			<div class="st-bases-wrap">
				<p class="st-bases-desc">${ escHtml( i18n.bases_desc ?? 'Descarga el reglamento oficial del torneo en formato PDF.' ) }</p>
				<a href="${ escHtml( url ) }" class="st-btn st-btn--primary st-btn--lg" download target="_blank" rel="noopener noreferrer">
					📄 ${ escHtml( i18n.bases_download ?? 'Descargar Bases en PDF' ) }
				</a>
				<div class="st-bases-preview">
					<iframe
						src="${ escHtml( url ) }#toolbar=0&navpanes=0"
						title="${ escHtml( i18n.bases_title ?? 'Bases del Torneo' ) }"
						class="st-bases-iframe"
						loading="lazy"
					></iframe>
				</div>
			</div>`;
	}

	async function renderTribunal( container ) {
		showLoading( container );

		try {
			const data = await apiFetch( `soccertrack/v1/public/tournament/${ TID }/tribunal` );

			// Soporte para respuesta antigua (array plano) y nueva (objeto con secciones).
			const isLegacy       = Array.isArray( data );
			const lastRound      = isLegacy ? null : ( data.last_round ?? null );
			const pendingReview  = isLegacy ? []   : ( data.pending_review ?? [] );
			const sanctions      = isLegacy ? data  : ( data.sanctions ?? [] );

			const hasContent = pendingReview.length || sanctions.length;
			if ( ! hasContent ) {
				return showEmpty( container, i18n.no_sanctions ?? 'No hay sanciones registradas.' );
			}

			// ── Sección 1: Casos última fecha ─────────────────────────────────
			let pendingHtml = '';
			if ( pendingReview.length ) {
				const roundLabel = lastRound
					? `${ i18n.round ?? 'Fecha' } ${ lastRound }`
					: ( i18n.last_round ?? 'Última Fecha' );

				const cardIcon = ( type ) => type === 'red_card' ? '🟥' : '🟨';
				const cardLabel = ( type ) => type === 'red_card'
					? ( i18n.event_red_card    ?? 'Tarjeta Roja' )
					: ( i18n.event_yellow_card ?? 'Tarjeta Amarilla' );

				const pendingRows = pendingReview.map( p => `
					<tr>
						<td>${ cardIcon( p.event_type ) } <span class="st-card-label">${ escHtml( cardLabel( p.event_type ) ) }</span></td>
						<td><strong>${ escHtml( p.first_name ) } ${ escHtml( p.last_name ) }</strong></td>
						<td>${ escHtml( p.team_name ?? '—' ) }</td>
						<td class="st-pending-status">
							<span class="st-badge st-badge--pending">${ escHtml( i18n.pending_decision ?? 'Pendiente resolución' ) }</span>
						</td>
					</tr>` ).join( '' );

				pendingHtml = `
					<div class="st-tribunal-section st-tribunal-section--pending">
						<h3 class="st-subsection-title">
							${ escHtml( i18n.pending_review_title ?? 'Casos a Resolver' ) }
							<span class="st-round-badge">${ escHtml( roundLabel ) }</span>
						</h3>
						<p class="st-tribunal-notice">${ escHtml( i18n.pending_review_notice ?? 'El tribunal revisará estos incidentes y determinará la sanción correspondiente.' ) }</p>
						<div class="st-table-wrap">
							<table class="st-table st-tribunal-pending-table" aria-label="${ escHtml( i18n.pending_review_title ?? 'Casos a Resolver' ) }">
								<thead>
									<tr>
										<th>${ escHtml( i18n.incident ?? 'Incidente' ) }</th>
										<th>${ escHtml( i18n.player   ?? 'Jugador' ) }</th>
										<th>${ escHtml( i18n.team     ?? 'Equipo' ) }</th>
										<th>${ escHtml( i18n.status   ?? 'Estado' ) }</th>
									</tr>
								</thead>
								<tbody>${ pendingRows }</tbody>
							</table>
						</div>
					</div>`;
			}

			// ── Sección 2: Sanciones resueltas ────────────────────────────────
			let sanctionsHtml = '';
			if ( sanctions.length ) {
				const sanctionRows = sanctions.map( s => {
					const cls   = s.status === 'active' ? 'st-sanction-status--active' : 'st-sanction-status--served';
					const label = s.status === 'active'
						? ( i18n.status_active ?? 'Activa' )
						: ( i18n.status_served ?? 'Cumplida' );

					return `
					<tr>
						<td><strong>${ escHtml( s.first_name ) } ${ escHtml( s.last_name ) }</strong></td>
						<td style="font-size:.85rem;color:#888">${ escHtml( s.team_name ?? '—' ) }</td>
						<td>${ escHtml( s.reason ) }</td>
						<td style="text-align:center">${ s.ban_days_or_matches }</td>
						<td style="text-align:center">${ s.remaining_matches }</td>
						<td><span class="${ cls }">${ escHtml( label ) }</span></td>
					</tr>`;
				} ).join( '' );

				sanctionsHtml = `
					<div class="st-tribunal-section st-tribunal-section--sanctions">
						<h3 class="st-subsection-title">${ escHtml( i18n.sanctions_title ?? 'Sanciones Resueltas' ) }</h3>
						<div class="st-table-wrap">
							<table class="st-table" aria-label="${ escHtml( i18n.sanctions_title ?? 'Sanciones' ) }">
								<thead>
									<tr>
										<th>${ escHtml( i18n.player    ?? 'Jugador' ) }</th>
										<th>${ escHtml( i18n.team      ?? 'Equipo' ) }</th>
										<th>${ escHtml( i18n.reason    ?? 'Motivo' ) }</th>
										<th>${ escHtml( i18n.ban       ?? 'Fechas' ) }</th>
										<th>${ escHtml( i18n.remaining ?? 'Restantes' ) }</th>
										<th>${ escHtml( i18n.status    ?? 'Estado' ) }</th>
									</tr>
								</thead>
								<tbody>${ sanctionRows }</tbody>
							</table>
						</div>
					</div>`;
			}

			container.innerHTML = `
				<h2 class="st-section-title">${ escHtml( i18n.tribunal_title ?? 'Tribunal Disciplinario' ) }</h2>
				${ pendingHtml }
				${ sanctionsHtml }`;

		} catch ( err ) {
			showError( container, `${ i18n.error_load ?? 'Error al cargar el tribunal.' } (${ err.message })` );
		}
	}

	async function renderStats( container ) {
		showLoading( container );
		try {
			const [ data, standings ] = await Promise.all( [
				apiFetch( `soccertrack/v1/public/tournament/${ TID }/stats` ),
				apiFetch( `soccertrack/v1/public/tournament/${ TID }/standings` ),
			] );
			const { records, top_scorers } = data;

			const hasRecords = records && records.best_attack;

			// ── Block 1: Records cards ──────────────────────────────────────
			let recordsHtml = '';
			if ( hasRecords ) {
				const cards = [
					{ icon: '⚔️', title: i18n.best_attack ?? 'Mejor Ataque',             value: `${ escHtml( records.best_attack.team ) } · ${ records.best_attack.gf } ${ escHtml( i18n.goals_for_label ?? 'goles' ) }` },
					{ icon: '🛡',  title: i18n.best_defense ?? 'Mejor Defensa',            value: `${ escHtml( records.best_defense.team ) } · ${ records.best_defense.gc } ${ escHtml( i18n.goals_against_label ?? 'en contra' ) }` },
					{ icon: '🧤', title: i18n.most_clean_sheets ?? 'Arco Menos Vencido',  value: `${ escHtml( records.most_clean_sheets.team ) } · ${ records.most_clean_sheets.count } ${ escHtml( i18n.clean_sheets_label ?? 'porterías a 0' ) }` },
					{ icon: '🔥', title: i18n.longest_streak ?? 'Racha Más Larga',        value: `${ escHtml( records.longest_streak.team ) } · ${ records.longest_streak.wins } ${ escHtml( i18n.win_streak_label ?? 'victorias seguidas' ) }` },
				].map( c => `
					<div class="st-record-card">
						<span class="st-record-card__icon">${ c.icon }</span>
						<span class="st-record-card__title">${ escHtml( c.title ) }</span>
						<span class="st-record-card__value">${ c.value }</span>
					</div>` ).join( '' );

				recordsHtml = `
					<h3 class="st-subsection-title">${ escHtml( i18n.records_title ?? 'Records del Torneo' ) }</h3>
					<div class="st-records-grid">${ cards }</div>`;
			}

			// ── Block 2: Charts por equipo (reemplaza podio) ────────────────
			let chartsHtml = '';
			if ( standings && standings.length ) {
				const buildChart = ( title, rows, modifier ) => {
					const max = Math.max( ...rows.map( r => r.val ), 1 );
					const bars = rows.map( r => {
						const pct = Math.round( r.val / max * 100 );
						return `
						<div class="st-bar-row">
							<span class="st-bar-label" title="${ escHtml( r.name ) }">${ escHtml( r.name ) }</span>
							<div class="st-bar-track">
								<div class="st-bar-fill st-bar-fill--${ modifier }" style="width:${ pct }%"></div>
							</div>
							<span class="st-bar-value">${ r.val }</span>
						</div>`;
					} ).join( '' );
					return `<div class="st-chart">${ bars }</div>`;
				};

				const byGF  = [ ...standings ].sort( ( a, b ) => b.gf  - a.gf  ).map( r => ( { name: r.name, val: r.gf  } ) );
				const byGC  = [ ...standings ].sort( ( a, b ) => a.gc  - b.gc  ).map( r => ( { name: r.name, val: r.gc  } ) );
				const byPG  = [ ...standings ].sort( ( a, b ) => b.pg  - a.pg  ).map( r => ( { name: r.name, val: r.pg  } ) );
				const byPTS = [ ...standings ].sort( ( a, b ) => b.pts - a.pts ).map( r => ( { name: r.name, val: r.pts } ) );

				chartsHtml = `
					<h3 class="st-subsection-title">${ escHtml( i18n.records_charts_title ?? 'Records del Torneo' ) }</h3>
					<div class="st-charts-grid">
						<div class="st-chart-wrap">
							<h4 class="st-chart-title">⚽ ${ escHtml( i18n.goals_scored ?? 'Goles Anotados' ) }</h4>
							${ buildChart( 'gf', byGF, 'attack' ) }
						</div>
						<div class="st-chart-wrap">
							<h4 class="st-chart-title">🛡 ${ escHtml( i18n.goals_conceded ?? 'Goles Concedidos' ) } <small class="st-chart-hint">${ escHtml( i18n.lower_is_better ?? 'menos es mejor' ) }</small></h4>
							${ buildChart( 'gc', byGC, 'defense' ) }
						</div>
						<div class="st-chart-wrap">
							<h4 class="st-chart-title">🏆 ${ escHtml( i18n.wins ?? 'Victorias' ) }</h4>
							${ buildChart( 'pg', byPG, 'wins' ) }
						</div>
						<div class="st-chart-wrap">
							<h4 class="st-chart-title">📊 ${ escHtml( i18n.pts ?? 'Puntos' ) }</h4>
							${ buildChart( 'pts', byPTS, 'pts' ) }
						</div>
					</div>`;
			}

			// ── Block 3: Full scorer table ──────────────────────────────────
			let scorerTableHtml = '';
			if ( top_scorers && top_scorers.length ) {
				const trs = top_scorers.map( s => `
					<tr>
						<td class="st-rank">${ s.rank }</td>
						<td><strong>${ escHtml( s.first_name ) } ${ escHtml( s.last_name ) }</strong></td>
						<td style="font-size:.8rem;color:#888">${ escHtml( s.team_name ) }</td>
						<td style="text-align:center;font-weight:700;color:var(--st-green-primary)">⚽ ${ s.goals }</td>
						<td style="text-align:center;font-size:.85rem">${ s.goals_per_match }</td>
					</tr>` ).join( '' );

				scorerTableHtml = `
					<div class="st-table-wrap" style="margin-top:2rem">
						<table class="st-table" aria-label="${ escHtml( i18n.scorers_title ?? 'Goleadores' ) }">
							<thead><tr>
								<th>#</th>
								<th>${ escHtml( i18n.player ?? 'Jugador' ) }</th>
								<th>${ escHtml( i18n.team ?? 'Equipo' ) }</th>
								<th style="text-align:center">⚽</th>
								<th style="text-align:center">${ escHtml( i18n.gpm_label ?? 'G/PJ' ) }</th>
							</tr></thead>
							<tbody>${ trs }</tbody>
						</table>
					</div>`;
			}

			if ( ! hasRecords && ! ( standings && standings.length ) && ( ! top_scorers || ! top_scorers.length ) ) {
				return showEmpty( container, i18n.no_stats ?? 'Aún no hay partidos finalizados.' );
			}

			container.innerHTML = `
				<h2 class="st-section-title">${ escHtml( i18n.stats_title ?? 'Estadísticas del Torneo' ) }</h2>
				${ recordsHtml }
				${ chartsHtml }
				${ scorerTableHtml }`;

		} catch ( err ) {
			showError( container, `${ i18n.error_load ?? 'Error al cargar.' } (${ err.message })` );
		}
	}

	/* ------------------------------------------------------------------ */
	/* Router de pestañas                                                  */
	/* ------------------------------------------------------------------ */

	const RENDERERS = {
		standings: renderStandings,
		fixture:   renderFixture,
		playoffs:  renderPlayoffs,
		teams:     renderTeams,
		scorers:   renderScorers,
		tribunal:  renderTribunal,
		bases:     renderBases,
		stats:     renderStandings, // alias: ?tab=stats redirige a posiciones (tab unificado)
	};

	function activateTab( tabId ) {
		const buttons = qsa( '.st-tab-btn' );
		const panels  = qsa( '.st-tab-panel' );

		for ( const btn of buttons ) {
			const active = btn.dataset.tab === tabId;
			btn.setAttribute( 'aria-selected', String( active ) );
			btn.setAttribute( 'tabindex', active ? '0' : '-1' );
		}

		for ( const panel of panels ) {
			const active = panel.id === `st-panel-${ tabId }`;
			panel.setAttribute( 'aria-hidden', String( ! active ) );

			if ( active ) {
				const renderer = RENDERERS[ tabId ];

				// Solo renderizar si el panel está vacío o muestra el spinner.
				if ( renderer && ( ! panel.dataset.loaded ) ) {
					panel.dataset.loaded = '1';
					renderer( panel );
				}
			}
		}

		// Persistir en URL sin recargar.
		try {
			const url = new URL( window.location.href );
			url.searchParams.set( 'tab', tabId );
			window.history.replaceState( null, '', url.toString() );
		} catch { /* ignorar */ }
	}

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

		const allFinished = ( Array.isArray( matches ) ? matches : [] )
			.filter( ( m ) => m.status === 'finished' );

		if ( allFinished.length === 0 ) return; // Sin partidos finalizados.

		// Para round_robin_playoffs: mostrar playoffs si ya comenzaron, si no solo fase regular.
		let pool;
		if ( FORMAT === 'round_robin_playoffs' ) {
			const playoffFinished = allFinished.filter( ( m ) => m.phase !== 'regular' );
			pool = playoffFinished.length > 0 ? playoffFinished : allFinished.filter( ( m ) => m.phase === 'regular' );
		} else {
			pool = allFinished;
		}

		const finished = pool
			.sort( ( a, b ) => b.round_number - a.round_number )
			.slice( 0, 10 );

		// Etiqueta de fase para cada ítem.
		const phaseLabel = ( m ) => {
			if ( m.phase === 'regular' || ! m.phase ) return `${ escHtml( i18n.round ?? 'Jornada' ) } ${ Number( m.round_number ) }`;
			const labels = {
				semifinal:    i18n.phase_semifinal    ?? 'Semifinal',
				final:        i18n.phase_final        ?? 'Final',
				third_place:  i18n.phase_third_place  ?? '3.er Puesto',
				quarterfinal: i18n.phase_quarterfinal ?? 'Cuartos',
				octavos:      i18n.phase_octavos      ?? 'Octavos',
			};
			return escHtml( labels[ m.phase ] ?? m.phase );
		};

		const items = finished.map( ( m ) => `
			<div class="st-results-ticker__item">
				<span class="st-results-ticker__round">${ phaseLabel( m ) }</span>
				<span class="st-results-ticker__score">${ escHtml( m.home_team ) } ${ Number( m.home_score ) } – ${ Number( m.away_score ) } ${ escHtml( m.away_team ) }</span>
			</div>` ).join( '' );

		// Duplicar para loop seamless (el @keyframes va de 0 a -50%).
		const track = ticker.querySelector( '.st-results-ticker__track' );
		track.innerHTML = items + items;
		ticker.classList.add( 'is-active' );
	}

	/* ------------------------------------------------------------------ */
	/* Init                                                                 */
	/* ------------------------------------------------------------------ */

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

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}

	/* ------------------------------------------------------------------ */
	/* Protección cosmética — disuasión básica                             */
	/* No es seguridad real: curl/proxies/otro-navegador igual acceden.    */
	/* Sí evita que usuarios casuales usen el menú contextual o F12.       */
	/* ------------------------------------------------------------------ */

	document.addEventListener( 'contextmenu', ( e ) => e.preventDefault() );

	document.addEventListener( 'keydown', ( e ) => {
		// F12
		if ( e.key === 'F12' ) { e.preventDefault(); return; }
		// Ctrl+Shift+I / Ctrl+Shift+J / Ctrl+Shift+C (DevTools)
		if ( e.ctrlKey && e.shiftKey && [ 'I', 'J', 'C' ].includes( e.key.toUpperCase() ) ) {
			e.preventDefault(); return;
		}
		// Ctrl+U (ver fuente)
		if ( e.ctrlKey && e.key.toUpperCase() === 'U' ) { e.preventDefault(); }
	} );

} )();
