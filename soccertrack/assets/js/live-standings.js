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

	const API   = cfg.apiBase?.replace( /\/$/, '' ) ?? '';
	const TID   = Number( cfg.tournamentId ?? 0 );
	const i18n  = cfg.i18n ?? {};

	// Cache de respuestas por tab para evitar refetches.
	/** @type {Map<string, any>} */
	const cache = new Map();

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

	function logoOrPlaceholder( url, name, classes = 'st-team-logo' ) {
		if ( url ) {
			return `<img src="${ escHtml( url ) }" alt="${ escHtml( name ) }" class="${ classes }" loading="lazy">`;
		}
		const initial = ( name?.[0] ?? '?' ).toUpperCase();
		return `<span class="st-team-card__logo--placeholder" aria-hidden="true">${ escHtml( initial ) }</span>`;
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
			const trs = rows.map( ( r, idx ) => {
				const pos  = idx + 1;
				const zone = pos <= playoffCut ? 'playoff' : ( pos > total - 2 && total > 4 ? 'danger' : '' );
				const rankStyle = pos === 1 ? ' style="color:#f39c12"' : pos === 2 ? ' style="color:#95a5a6"' : pos === 3 ? ' style="color:#cd7f32"' : '';

				const winRate = r.pj > 0 ? Math.round( r.pg / r.pj * 100 ) : '—';

				const formBubbles = ( r.form ?? [] ).map( result => {
					const cls = result === 'W' ? 'w' : result === 'D' ? 'd' : 'l';
					const lbl = result === 'W' ? 'W' : result === 'D' ? 'D' : 'L';
					return `<span class="st-form-bubble st-form-bubble--${ cls }">${ lbl }</span>`;
				} ).join( '' );

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
							</tr>
						</thead>
						<tbody>${ trs }</tbody>
					</table>
				</div>
				${ zoneLegendHtml }`;
		} catch ( err ) {
			showError( container, `${ i18n.error_load ?? 'Error al cargar posiciones.' } (${ err.message })` );
		}
	}

	async function renderFixture( container ) {
		showLoading( container );

		try {
			const matches = await apiFetch( `soccertrack/v1/public/tournament/${ TID }/fixture` );

			if ( ! matches.length ) {
				return showEmpty( container, i18n.no_fixture ?? 'El fixture aún no ha sido generado.' );
			}

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
						<span>${ escHtml( m.home_team ) }</span>
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
						<span>${ escHtml( m.away_team ) }</span>
					</div>
				</div>`;
			};

			// Separar partidos regulares de play-offs.
			const PLAYOFF_PHASES = [ 'semifinal', 'third_place', 'final' ];
			const regularMatches  = matches.filter( m => ! PLAYOFF_PHASES.includes( m.phase ) );
			const playoffMatches  = matches.filter( m => PLAYOFF_PHASES.includes( m.phase ) );

			// ── Jornadas regulares ──────────────────────────────────────────────
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

			let html = `<h2 class="st-section-title">${ i18n.fixture_title ?? 'Fixture' }</h2>`;

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

			// ── Bracket de play-offs ────────────────────────────────────────────
			if ( playoffMatches.length ) {
				const sfMatches    = playoffMatches.filter( m => m.phase === 'semifinal' );
				const thirdMatches = playoffMatches.filter( m => m.phase === 'third_place' );
				const finalMatches = playoffMatches.filter( m => m.phase === 'final' );

				const phaseTitle = {
					semifinal:   i18n.phase_semifinal   ?? 'Semi-finales',
					third_place: i18n.phase_third_place ?? '3.er Puesto',
					final:       i18n.phase_final       ?? 'Final',
				};

				html += `<div class="st-playoffs-bracket">
					<h2 class="st-section-title">${ i18n.playoffs_title ?? 'Play-offs' }</h2>`;

				for ( const [ phase, group ] of [ [ 'semifinal', sfMatches ], [ 'third_place', thirdMatches ], [ 'final', finalMatches ] ] ) {
					if ( ! group.length ) continue;
					html += `
					<div class="st-bracket-round">
						<h3 class="st-bracket-round-title">${ phaseTitle[ phase ] }</h3>
						<div class="st-bracket-matches">
							${ group.map( matchCard ).join( '' ) }
						</div>
					</div>`;
				}

				html += `</div>`;
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

		} catch ( err ) {
			showError( container, `${ i18n.error_load ?? 'Error al cargar el fixture.' } (${ err.message })` );
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
					${ t.logo_url
						? `<img src="${ escHtml( t.logo_url ) }" alt="${ escHtml( t.name ) }" class="st-team-card__logo" loading="lazy">`
						: `<div class="st-team-card__logo--placeholder" aria-hidden="true">${ escHtml( t.name[0]?.toUpperCase() ?? '?' ) }</div>` }
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
			const sanctions = await apiFetch( `soccertrack/v1/public/tournament/${ TID }/tribunal` );

			if ( ! sanctions.length ) {
				return showEmpty( container, i18n.no_sanctions ?? 'No hay sanciones registradas.' );
			}

			const trs = sanctions.map( s => {
				const cls  = s.status === 'active' ? 'st-sanction-status--active' : 'st-sanction-status--served';
				const label = s.status === 'active'
					? ( i18n.status_active ?? 'Activa' )
					: ( i18n.status_served ?? 'Cumplida' );

				return `
					<tr>
						<td>${ escHtml( s.first_name ) } ${ escHtml( s.last_name ) }</td>
						<td>${ escHtml( s.reason ) }</td>
						<td style="text-align:center">${ s.ban_days_or_matches }</td>
						<td style="text-align:center">${ s.remaining_matches }</td>
						<td><span class="${ cls }">${ escHtml( label ) }</span></td>
					</tr>`;
			} ).join( '' );

			container.innerHTML = `
				<h2 class="st-section-title">${ i18n.tribunal_title ?? 'Tribunal Disciplinario' }</h2>
				<div class="st-table-wrap">
					<table class="st-table" aria-label="${ i18n.tribunal_title ?? 'Tribunal Disciplinario' }">
						<thead>
							<tr>
								<th>${ i18n.player ?? 'Jugador' }</th>
								<th>${ i18n.reason ?? 'Motivo' }</th>
								<th>${ i18n.ban ?? 'Fechas' }</th>
								<th>${ i18n.remaining ?? 'Restantes' }</th>
								<th>${ i18n.status ?? 'Estado' }</th>
							</tr>
						</thead>
						<tbody>${ trs }</tbody>
					</table>
				</div>`;
		} catch ( err ) {
			showError( container, `${ i18n.error_load ?? 'Error al cargar el tribunal.' } (${ err.message })` );
		}
	}

	async function renderStats( container ) {
		showLoading( container );
		try {
			const data = await apiFetch( `soccertrack/v1/public/tournament/${ TID }/stats` );
			const { records, top_scorers } = data;

			const hasRecords = records && records.best_attack;

			// ── Block 1: Records ────────────────────────────────────────────
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

			// ── Block 2: Podium (top 3) ─────────────────────────────────────
			let podiumHtml = '';
			if ( top_scorers && top_scorers.length ) {
				const medals  = [ '🥇', '🥈', '🥉' ];
				const top3    = top_scorers.slice( 0, 3 );
				// Podium visual order: 2nd (left), 1st (center), 3rd (right)
				const order   = top3.length >= 2 ? [ top3[1], top3[0], top3[2] ] : [ top3[0] ];
				const heights = [ '160px', '200px', '120px' ];
				const rankIdx = top3.length >= 2 ? [ 1, 0, 2 ] : [ 0 ];

				const podiumItems = order.map( ( s, i ) => {
					if ( ! s ) return '';
					const ri    = rankIdx[ i ];
					const medal = medals[ ri ] ?? '';
					return `
					<div class="st-podium-item st-podium-item--${ ri + 1 }" style="--podium-height:${ heights[i] }">
						<div class="st-podium-medal">${ medal }</div>
						<div class="st-podium-avatar">${ escHtml( ( s.first_name?.[0] ?? '?' ).toUpperCase() ) }</div>
						<div class="st-podium-name">${ escHtml( s.first_name ) } ${ escHtml( s.last_name ) }</div>
						<div class="st-podium-team">${ escHtml( s.team_name ) }</div>
						<div class="st-podium-goals">${ s.goals } ⚽</div>
						<div class="st-podium-gpm">${ s.goals_per_match } ${ escHtml( i18n.gpm_label ?? 'G/PJ' ) }</div>
					</div>`;
				} ).join( '' );

				podiumHtml = `
					<h3 class="st-subsection-title">${ escHtml( i18n.podium_title ?? 'Podio de Goleadores' ) }</h3>
					<div class="st-podium">${ podiumItems }</div>`;
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

			if ( ! hasRecords && ( ! top_scorers || ! top_scorers.length ) ) {
				return showEmpty( container, i18n.no_stats ?? 'Aún no hay partidos finalizados.' );
			}

			container.innerHTML = `
				<h2 class="st-section-title">${ escHtml( i18n.stats_title ?? 'Estadísticas del Torneo' ) }</h2>
				${ recordsHtml }
				${ podiumHtml }
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
		teams:     renderTeams,
		scorers:   renderScorers,
		tribunal:  renderTribunal,
		bases:     renderBases,
		stats:     renderStats,
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
