/**
 * SoccerTrack — Planilla Arbitral · JS
 *
 * Funcionalidades:
 *  - Botón + abre modal para seleccionar goleador, minuto y detalle.
 *  - Botón - decrementa marcador (solo si hay goles registrados).
 *  - Registrar tarjetas amarilla/roja con detalle del incidente.
 *  - Editar incidente (árbitro/coordinador): modal con minuto + detalle → PATCH.
 *  - Eliminar incidente: DELETE al endpoint (propio o con cap ds_edit_incidents).
 *  - Log de auditoría: muestra quién registró y quién editó cada incidente.
 *  - Carga eventos previos del partido al abrir la planilla.
 *  - Cierre de partido con confirmación (solo roles con ds_close_match).
 *
 * Config global: window.stMatchSheet
 *
 * @package SoccerTrack
 */

( () => {
	'use strict';

	/** @type {{ apiBase:string, matchId:number, nonce:string, homeScore:number, awayScore:number,
	 *           homeTeam:{id:number,name:string}, awayTeam:{id:number,name:string},
	 *           homePlayers:Array, awayPlayers:Array,
	 *           currentUserId:number, canEditIncidents:boolean, canClose:boolean,
	 *           i18n:Record<string,string> }} */
	const cfg  = window.stMatchSheet ?? {};
	const API  = ( cfg.apiBase ?? '' ).replace( /\/$/, '' );
	const MID  = Number( cfg.matchId ?? 0 );
	const i18n = cfg.i18n ?? {};

	/* ------------------------------------------------------------------ */
	/* Helpers DOM                                                          */
	/* ------------------------------------------------------------------ */

	const qs  = ( sel, ctx = document ) => ctx.querySelector( sel );
	const qsa = ( sel, ctx = document ) => [ ...ctx.querySelectorAll( sel ) ];

	function escHtml( str ) {
		return String( str )
			.replace( /&/g, '&amp;' )
			.replace( /</g, '&lt;' )
			.replace( />/g, '&gt;' )
			.replace( /"/g, '&quot;' );
	}

	function showNotice( el, msg, type = 'info' ) {
		if ( ! el ) return;
		el.innerHTML = `<div class="st-notice st-notice--${ type }" role="alert">${ escHtml( msg ) }</div>`;
	}

	function setLoading( btn, loading ) {
		if ( ! btn ) return;
		btn.disabled = loading;
		const label  = btn.dataset.label ?? btn.textContent;
		if ( loading ) {
			btn.dataset.label = label;
			btn.textContent   = i18n.saving ?? 'Guardando…';
		} else {
			btn.textContent = label;
		}
	}

	/* ------------------------------------------------------------------ */
	/* REST helpers                                                         */
	/* ------------------------------------------------------------------ */

	async function apiPost( endpoint, body ) {
		const resp = await fetch( `${ API }/${ endpoint }`, {
			method:  'POST',
			headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': cfg.nonce ?? '' },
			body:    JSON.stringify( body ),
		} );
		const data = await resp.json();
		if ( ! resp.ok ) throw new Error( data?.message ?? `HTTP ${ resp.status }` );
		return data;
	}

	async function apiPatch( endpoint, body ) {
		const resp = await fetch( `${ API }/${ endpoint }`, {
			method:  'PATCH',
			headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': cfg.nonce ?? '' },
			body:    JSON.stringify( body ),
		} );
		const data = await resp.json();
		if ( ! resp.ok ) throw new Error( data?.message ?? `HTTP ${ resp.status }` );
		return data;
	}

	async function apiFetch( endpoint ) {
		const resp = await fetch( `${ API }/${ endpoint }`, {
			headers: { 'X-WP-Nonce': cfg.nonce ?? '' },
		} );
		if ( ! resp.ok ) throw new Error( `HTTP ${ resp.status }` );
		return resp.json();
	}

	async function apiDelete( endpoint ) {
		const resp = await fetch( `${ API }/${ endpoint }`, {
			method:  'DELETE',
			headers: { 'X-WP-Nonce': cfg.nonce ?? '' },
		} );
		const data = await resp.json();
		if ( ! resp.ok ) throw new Error( data?.message ?? `HTTP ${ resp.status }` );
		return data;
	}

	/* ------------------------------------------------------------------ */
	/* Marcador                                                             */
	/* ------------------------------------------------------------------ */

	let homeScore = Number( cfg.homeScore ?? 0 );
	let awayScore = Number( cfg.awayScore ?? 0 );
	let submitted = false;

	function updateScoreDisplay() {
		for ( const id of [ '#st-score-home', '#st-score-home-ctrl' ] ) {
			const el = qs( id );
			if ( el ) el.textContent = homeScore;
		}
		for ( const id of [ '#st-score-away', '#st-score-away-ctrl' ] ) {
			const el = qs( id );
			if ( el ) el.textContent = awayScore;
		}
	}

	function incrementScore( team ) {
		if ( team === 'home' ) homeScore++;
		else awayScore++;
		updateScoreDisplay();
	}

	function decrementScore( team ) {
		if ( team === 'home' && homeScore > 0 ) homeScore--;
		else if ( team === 'away' && awayScore > 0 ) awayScore--;
		updateScoreDisplay();
	}

	/* ------------------------------------------------------------------ */
	/* Modal de gol (incluye campo de descripción)                         */
	/* ------------------------------------------------------------------ */

	/** Estado interno del modal */
	const modal = {
		el:          null,   // overlay <div>
		team:        null,   // 'home' | 'away'
		players:     [],     // array de jugadores del equipo
		selectedId:  null,   // player id seleccionado
		editEventId: null,   // event_id si es edición (null = nuevo)
		editRow:     null,   // <tr> a reemplazar en edición
		editTeam:    null,   // 'home'|'away' del evento editado
	};

	function buildModal() {
		const div = document.createElement( 'div' );
		div.className = 'st-modal-overlay';
		div.id        = 'st-goal-modal';
		div.style.display = 'none';
		div.innerHTML = `
			<div class="st-modal" role="dialog" aria-modal="true">
				<div class="st-modal__header">
					<h3 class="st-modal__title" id="st-modal-title">⚽</h3>
					<button class="st-modal__close" id="st-modal-close" aria-label="Cerrar">✕</button>
				</div>
				<div class="st-modal__body">
					<input
						type="text"
						class="st-modal__search"
						id="st-modal-search"
						placeholder="${ escHtml( i18n.search_player ?? 'Buscar jugador…' ) }"
						autocomplete="off"
					>
					<ul class="st-modal__player-list" id="st-modal-players"></ul>
					<div class="st-modal__minute-row">
						<label class="st-modal__minute-label" for="st-modal-minute">
							${ escHtml( i18n.minute_label ?? 'Minuto' ) }
						</label>
						<input
							type="number"
							id="st-modal-minute"
							class="st-modal__minute-input"
							min="1" max="120" value="45"
						>
					</div>
					<div style="margin-top:10px">
						<label style="display:block;font-size:.8rem;font-weight:600;margin-bottom:4px" for="st-modal-desc">
							${ escHtml( i18n.description_label ?? 'Detalle del incidente' ) }
							<span style="font-weight:400;color:#9ca3af"> — ${ escHtml( i18n.optional ?? 'opcional' ) }</span>
						</label>
						<textarea
							id="st-modal-desc"
							class="st-modal__minute-input"
							rows="2"
							maxlength="500"
							placeholder="${ escHtml( i18n.description_hint ?? 'Ej: plancha contra jugador 5' ) }"
							style="width:100%;resize:vertical;font-size:.82rem;padding:6px 10px"
						></textarea>
					</div>
					<div class="st-modal__notice" id="st-modal-notice"></div>
				</div>
				<div class="st-modal__footer">
					<button class="st-btn st-btn--secondary" id="st-modal-cancel">
						${ escHtml( i18n.cancel ?? 'Cancelar' ) }
					</button>
					<button class="st-btn st-btn--primary" id="st-modal-confirm">
						${ escHtml( i18n.confirm_goal ?? '⚽ Registrar Gol' ) }
					</button>
				</div>
			</div>`;
		document.body.appendChild( div );
		modal.el = div;

		qs( '#st-modal-close',   div ).addEventListener( 'click', closeModal );
		qs( '#st-modal-cancel',  div ).addEventListener( 'click', closeModal );
		div.addEventListener( 'click', e => { if ( e.target === div ) closeModal(); } );
		qs( '#st-modal-search',  div ).addEventListener( 'input', filterPlayers );
		qs( '#st-modal-confirm', div ).addEventListener( 'click', confirmGoal );
	}

	function openModal( team, preSelectedId = null, preMinute = 45, preDesc = '' ) {
		modal.team       = team;
		modal.players    = team === 'home' ? ( cfg.homePlayers ?? [] ) : ( cfg.awayPlayers ?? [] );
		modal.selectedId = preSelectedId;

		const teamName = team === 'home'
			? ( cfg.homeTeam?.name ?? 'Local' )
			: ( cfg.awayTeam?.name ?? 'Visitante' );

		const title = modal.editEventId
			? `${ i18n.goal_edit_title ?? 'Editar Gol' } — ${ teamName }`
			: `${ i18n.goal_modal_title ?? 'Registrar Gol' } — ${ teamName }`;

		qs( '#st-modal-title',   modal.el ).textContent = '⚽ ' + title;
		qs( '#st-modal-minute',  modal.el ).value       = preMinute;
		qs( '#st-modal-desc',    modal.el ).value       = preDesc;
		qs( '#st-modal-search',  modal.el ).value       = '';
		qs( '#st-modal-notice',  modal.el ).innerHTML   = '';
		const confirmBtn = qs( '#st-modal-confirm', modal.el );
		confirmBtn.disabled    = false;
		confirmBtn.textContent = modal.editEventId
			? ( i18n.save_goal    ?? '⚽ Guardar Gol' )
			: ( i18n.confirm_goal ?? '⚽ Registrar Gol' );
		delete confirmBtn.dataset.label;

		renderPlayerList( modal.players );
		modal.el.style.display = 'flex';
		qs( '#st-modal-search', modal.el ).focus();
	}

	function closeModal() {
		if ( modal.el ) modal.el.style.display = 'none';
		modal.editEventId = null;
		modal.editRow     = null;
		modal.editTeam    = null;
		modal.selectedId  = null;
	}

	function renderPlayerList( players ) {
		const ul = qs( '#st-modal-players', modal.el );
		ul.innerHTML = '';

		players.forEach( p => {
			const li = document.createElement( 'li' );
			li.className = 'st-modal__player-item' +
				( p.is_suspended ? ' is-suspended' : '' ) +
				( p.id === modal.selectedId ? ' is-selected' : '' );
			li.dataset.playerId = p.id;
			li.textContent = `${ p.dorsal } — ${ p.name }`;
			if ( p.is_suspended ) {
				li.textContent += ` ${ i18n.suspended_label ?? '[SUSP.]' }`;
			}

			li.addEventListener( 'click', () => {
				if ( p.is_suspended ) return;
				qsa( '.st-modal__player-item.is-selected', modal.el )
					.forEach( el => el.classList.remove( 'is-selected' ) );
				li.classList.add( 'is-selected' );
				modal.selectedId = p.id;
			} );

			ul.appendChild( li );
		} );
	}

	function filterPlayers() {
		const q = qs( '#st-modal-search', modal.el ).value.toLowerCase();
		const filtered = modal.players.filter(
			p => p.name.toLowerCase().includes( q ) || String( p.dorsal ).includes( q )
		);
		renderPlayerList( filtered );
	}

	async function confirmGoal() {
		const noticeEl   = qs( '#st-modal-notice',  modal.el );
		const confirmBtn = qs( '#st-modal-confirm', modal.el );
		const minute     = Number( qs( '#st-modal-minute', modal.el ).value );
		const description = qs( '#st-modal-desc', modal.el ).value.trim();

		if ( ! modal.selectedId ) {
			showNotice( noticeEl, i18n.missing_fields ?? 'Selecciona jugador y minuto.', 'warning' );
			return;
		}
		if ( ! minute || minute < 1 || minute > 120 ) {
			showNotice( noticeEl, i18n.missing_fields ?? 'Selecciona jugador y minuto.', 'warning' );
			return;
		}

		const player  = modal.players.find( p => p.id === modal.selectedId )
			?? ( cfg.homePlayers ?? [] ).concat( cfg.awayPlayers ?? [] ).find( p => p.id === modal.selectedId );
		const teamId  = player?.team_id
			?? ( modal.team === 'home' ? cfg.homeTeam?.id : cfg.awayTeam?.id );

		setLoading( confirmBtn, true );
		noticeEl.innerHTML = '';

		try {
			// Edición de gol: usamos PATCH si tenemos permisos, de lo contrario delete+create.
			if ( modal.editEventId ) {
				if ( cfg.canEditIncidents ) {
					// PATCH: preserva auditoría y no cambia created_by.
					const patched = await apiPatch(
						`soccertrack/v1/admin/match/${ MID }/event/${ modal.editEventId }`,
						{ minute, description }
					);
					// Actualizar la fila existente en lugar de reemplazarla.
					if ( modal.editRow ) {
						const descCell = modal.editRow.querySelector( '[data-cell="desc"]' );
						const auditCell = modal.editRow.querySelector( '[data-cell="audit"]' );
						const minCell = modal.editRow.querySelector( 'td:first-child' );
						if ( minCell )  minCell.textContent = `${ minute }'`;
						if ( descCell ) descCell.textContent = description;
						if ( auditCell && patched.updated_by_name ) {
							auditCell.innerHTML = buildAuditHtml(
								modal.editRow.dataset.createdByName ?? '',
								patched.updated_by_name,
								patched.updated_at
							);
						}
						modal.editRow.dataset.minute = minute;
					}
				} else {
					// Planillero editando su propio gol: delete + create.
					await apiDelete( `soccertrack/v1/admin/match/${ MID }/event/${ modal.editEventId }` );
					modal.editRow?.remove();

					const result = await apiPost(
						`soccertrack/v1/admin/match/${ MID }/event`,
						{ player_id: modal.selectedId, team_id: teamId, event_type: 'goal', minute, description }
					);
					const playerName = result.player_name || player?.name || '';
					const teamName   = modal.team === 'home'
						? ( cfg.homeTeam?.name ?? '' )
						: ( cfg.awayTeam?.name ?? '' );

					appendIncidentRow( {
						eventId:         result.event_id,
						type:            'goal',
						playerName,
						playerData:      player ?? { id: modal.selectedId, name: playerName, team_id: teamId, dorsal: 0, is_suspended: false },
						minute,
						teamName,
						team:            modal.team,
						description,
						createdBy:       result.created_by ?? cfg.currentUserId,
						createdByName:   result.created_by_name ?? '',
						updatedByName:   '',
						updatedAt:       null,
						canEdit:         result.can_edit ?? cfg.canEditIncidents,
						canDelete:       result.can_delete ?? true,
					} );
				}

				closeModal();
				return;
			}

			// Nuevo gol.
			const result = await apiPost(
				`soccertrack/v1/admin/match/${ MID }/event`,
				{ player_id: modal.selectedId, team_id: teamId, event_type: 'goal', minute, description }
			);

			const playerName = result.player_name || player?.name || '';
			const teamName   = modal.team === 'home'
				? ( cfg.homeTeam?.name ?? '' )
				: ( cfg.awayTeam?.name ?? '' );

			incrementScore( modal.team );

			appendIncidentRow( {
				eventId:       result.event_id,
				type:          'goal',
				playerName,
				playerData:    player ?? { id: modal.selectedId, name: playerName, team_id: teamId, dorsal: 0, is_suspended: false },
				minute,
				teamName,
				team:          modal.team,
				description,
				createdBy:     result.created_by ?? cfg.currentUserId,
				createdByName: result.created_by_name ?? '',
				updatedByName: '',
				updatedAt:     null,
				canEdit:       result.can_edit ?? cfg.canEditIncidents,
				canDelete:     result.can_delete ?? true,
			} );

			closeModal();
		} catch ( err ) {
			showNotice( noticeEl, `${ i18n.error_save ?? 'Error al registrar.' } ${ err.message }`, 'error' );
			setLoading( confirmBtn, false );
		}
	}

	/* ------------------------------------------------------------------ */
	/* Modal de edición de incidentes (árbitro — tarjetas y otros)        */
	/* ------------------------------------------------------------------ */

	const editModal = { el: null, eventId: null, row: null };

	function buildEditModal() {
		const div = document.createElement( 'div' );
		div.className = 'st-modal-overlay';
		div.id        = 'st-edit-incident-modal';
		div.style.display = 'none';
		div.innerHTML = `
			<div class="st-modal" role="dialog" aria-modal="true" style="max-width:420px">
				<div class="st-modal__header">
					<h3 class="st-modal__title" id="st-edit-modal-title">
						✏️ ${ escHtml( i18n.edit_incident_title ?? 'Editar Incidente' ) }
					</h3>
					<button class="st-modal__close" id="st-edit-modal-close" aria-label="Cerrar">✕</button>
				</div>
				<div class="st-modal__body">
					<div class="st-modal__minute-row">
						<label class="st-modal__minute-label" for="st-edit-minute">
							${ escHtml( i18n.minute_label ?? 'Minuto' ) }
						</label>
						<input
							type="number"
							id="st-edit-minute"
							class="st-modal__minute-input"
							min="1" max="120" value="45"
						>
					</div>
					<div style="margin-top:10px">
						<label style="display:block;font-size:.8rem;font-weight:600;margin-bottom:4px" for="st-edit-desc">
							${ escHtml( i18n.description_label ?? 'Detalle del incidente' ) }
						</label>
						<textarea
							id="st-edit-desc"
							class="st-modal__minute-input"
							rows="3"
							maxlength="500"
							placeholder="${ escHtml( i18n.description_hint ?? 'Ej: plancha contra jugador 5' ) }"
							style="width:100%;resize:vertical;font-size:.82rem;padding:6px 10px"
						></textarea>
					</div>
					<div class="st-modal__notice" id="st-edit-modal-notice"></div>
				</div>
				<div class="st-modal__footer">
					<button class="st-btn st-btn--secondary" id="st-edit-modal-cancel">
						${ escHtml( i18n.cancel ?? 'Cancelar' ) }
					</button>
					<button class="st-btn st-btn--primary" id="st-edit-modal-confirm">
						${ escHtml( i18n.save_changes ?? '💾 Guardar cambios' ) }
					</button>
				</div>
			</div>`;
		document.body.appendChild( div );
		editModal.el = div;

		qs( '#st-edit-modal-close',   div ).addEventListener( 'click', closeEditModal );
		qs( '#st-edit-modal-cancel',  div ).addEventListener( 'click', closeEditModal );
		div.addEventListener( 'click', e => { if ( e.target === div ) closeEditModal(); } );
		qs( '#st-edit-modal-confirm', div ).addEventListener( 'click', confirmEdit );
	}

	function openEditModal( eventId, minute, description, row ) {
		editModal.eventId = eventId;
		editModal.row     = row;

		qs( '#st-edit-minute',       editModal.el ).value = minute;
		qs( '#st-edit-desc',         editModal.el ).value = description ?? '';
		qs( '#st-edit-modal-notice', editModal.el ).innerHTML = '';

		const btn = qs( '#st-edit-modal-confirm', editModal.el );
		btn.disabled    = false;
		btn.textContent = i18n.save_changes ?? '💾 Guardar cambios';
		delete btn.dataset.label;

		editModal.el.style.display = 'flex';
		qs( '#st-edit-desc', editModal.el ).focus();
	}

	function closeEditModal() {
		if ( editModal.el ) editModal.el.style.display = 'none';
		editModal.eventId = null;
		editModal.row     = null;
	}

	async function confirmEdit() {
		const noticeEl  = qs( '#st-edit-modal-notice',  editModal.el );
		const confirmBtn = qs( '#st-edit-modal-confirm', editModal.el );
		const minute     = Number( qs( '#st-edit-minute', editModal.el ).value );
		const description = qs( '#st-edit-desc', editModal.el ).value.trim();

		setLoading( confirmBtn, true );
		noticeEl.innerHTML = '';

		try {
			const patched = await apiPatch(
				`soccertrack/v1/admin/match/${ MID }/event/${ editModal.eventId }`,
				{ minute, description }
			);

			// Actualizar la fila en la tabla.
			if ( editModal.row ) {
				const minCell   = editModal.row.querySelector( 'td:first-child' );
				const descCell  = editModal.row.querySelector( '[data-cell="desc"]' );
				const auditCell = editModal.row.querySelector( '[data-cell="audit"]' );

				if ( minCell )   minCell.textContent = `${ minute }'`;
				if ( descCell )  descCell.textContent = description;
				if ( auditCell && patched.updated_by_name ) {
					auditCell.innerHTML = buildAuditHtml(
						editModal.row.dataset.createdByName ?? '',
						patched.updated_by_name,
						patched.updated_at
					);
				}
				editModal.row.dataset.minute = minute;
			}

			closeEditModal();
		} catch ( err ) {
			showNotice( noticeEl, `${ i18n.error_save ?? 'Error al guardar.' } ${ err.message }`, 'error' );
			setLoading( confirmBtn, false );
		}
	}

	/* ------------------------------------------------------------------ */
	/* Controles de marcador (+ abre modal, - decrementa)                  */
	/* ------------------------------------------------------------------ */

	function setupScoreControls() {
		document.addEventListener( 'click', e => {
			if ( submitted ) return;

			const btn = e.target.closest( '[data-score-action]' );
			if ( ! btn ) return;

			const action = btn.dataset.scoreAction;
			const team   = btn.dataset.scoreTeam;

			if ( action === 'inc' ) {
				// Abrir modal para seleccionar goleador.
				openModal( team );
			} else if ( action === 'dec' ) {
				// Decremento directo (corrección rápida sin evento registrado).
				decrementScore( team );
			}
		} );
	}

	/* ------------------------------------------------------------------ */
	/* Tabla de incidentes                                                  */
	/* ------------------------------------------------------------------ */

	const EVENT_LABELS = {
		goal:        { icon: '⚽', label: 'Gol' },
		own_goal:    { icon: '⚽🔙', label: 'Gol en contra' },
		yellow_card: { icon: '🟡', label: 'Amarilla' },
		red_card:    { icon: '🔴', label: 'Roja' },
	};

	/**
	 * Genera el HTML de auditoría de un incidente.
	 *
	 * @param {string} createdByName
	 * @param {string} updatedByName
	 * @param {string|null} updatedAt
	 * @returns {string}
	 */
	function buildAuditHtml( createdByName, updatedByName, updatedAt ) {
		let html = '';
		if ( createdByName ) {
			html += `<span style="display:block;font-size:.72rem;color:#6b7280">${ escHtml( i18n.entered_by ?? 'Ingresado por' ) }: ${ escHtml( createdByName ) }</span>`;
		}
		if ( updatedByName ) {
			const when = updatedAt ? ` (${ updatedAt.substring( 0, 16 ).replace( 'T', ' ' ) })` : '';
			html += `<span style="display:block;font-size:.72rem;color:#9ca3af">${ escHtml( i18n.edited_by ?? 'Editado por' ) }: ${ escHtml( updatedByName ) }${ escHtml( when ) }</span>`;
		}
		return html;
	}

	/**
	 * Agrega una fila a la tabla de incidentes.
	 *
	 * @param {{ eventId:number, type:string, playerName:string, playerData:object,
	 *            minute:number, teamName:string, team:string,
	 *            description:string, createdBy:number, createdByName:string,
	 *            updatedByName:string, updatedAt:string|null,
	 *            canEdit:boolean, canDelete:boolean }} opts
	 * @returns {HTMLTableRowElement}
	 */
	function appendIncidentRow( {
		eventId, type, playerName, playerData,
		minute, teamName, team,
		description = '', createdBy = 0, createdByName = '',
		updatedByName = '', updatedAt = null,
		canEdit = false, canDelete = false,
	} ) {
		const tbody = qs( '#st-incidents-tbody' );
		if ( ! tbody ) return null;

		qs( '#st-incidents-empty' )?.remove();

		const { icon, label } = EVENT_LABELS[ type ] ?? { icon: '❓', label: type };
		const isGoal          = type === 'goal' || type === 'own_goal';
		const editBtn         = ( ! submitted && canEdit ) ? `
			<button class="st-btn--icon st-incident-edit" title="Editar incidente"
				data-event-id="${ eventId }"
				data-type="${ escHtml( type ) }"
				data-minute="${ minute }"
				data-description="${ escHtml( description ) }"
				data-player-id="${ playerData?.id ?? '' }"
				data-team="${ escHtml( team ?? '' ) }">
				✏️
			</button>` : '';

		const delBtn = ( ! submitted && canDelete ) ? `
			<button class="st-btn--icon st-incident-delete" title="Eliminar incidente"
				data-event-id="${ eventId }"
				data-type="${ escHtml( type ) }"
				data-team="${ escHtml( team ?? '' ) }">
				🗑
			</button>` : '';

		const actionBtns = ( editBtn || delBtn )
			? `<div class="st-incident-actions">${ editBtn }${ delBtn }</div>`
			: '';

		const tr = document.createElement( 'tr' );
		tr.dataset.eventId       = eventId;
		tr.dataset.type          = type;
		tr.dataset.createdBy     = createdBy;
		tr.dataset.createdByName = createdByName;

		tr.innerHTML = `
			<td style="text-align:center;font-weight:700">${ escHtml( String( minute ) ) }'</td>
			<td style="text-align:center;font-size:1.2rem" title="${ escHtml( label ) }">${ icon }</td>
			<td>${ escHtml( playerName ) }</td>
			<td style="font-size:.8rem;color:#666">${ escHtml( teamName ) }</td>
			<td data-cell="desc" style="font-size:.8rem;color:#374151">${ escHtml( description ) }</td>
			<td data-cell="audit">${ buildAuditHtml( createdByName, updatedByName, updatedAt ) }</td>
			<td>${ actionBtns }</td>`;

		tbody.prepend( tr );
		return tr;
	}

	/* ------------------------------------------------------------------ */
	/* Editar / eliminar incidentes                                         */
	/* ------------------------------------------------------------------ */

	function setupIncidentActions() {
		document.addEventListener( 'click', async e => {
			// Editar incidente.
			const editBtn = e.target.closest( '.st-incident-edit' );
			if ( editBtn ) {
				const eventId     = Number( editBtn.dataset.eventId );
				const type        = editBtn.dataset.type;
				const minute      = Number( editBtn.dataset.minute );
				const description = editBtn.dataset.description ?? '';
				const playerId    = Number( editBtn.dataset.playerId );
				const team        = editBtn.dataset.team;
				const row         = editBtn.closest( 'tr' );

				if ( type === 'goal' || type === 'own_goal' ) {
					// Editar gol: abrir modal con preselección de jugador.
					modal.editEventId = eventId;
					modal.editRow     = row;
					modal.editTeam    = team;
					openModal( team, playerId, minute, description );
				} else {
					// Editar tarjeta/otro: modal simple de minuto + descripción.
					openEditModal( eventId, minute, description, row );
				}
				return;
			}

			// Eliminar incidente.
			const delBtn = e.target.closest( '.st-incident-delete' );
			if ( delBtn ) {
				const type    = delBtn.dataset.type;
				const isGoal  = type === 'goal' || type === 'own_goal';
				const msg     = isGoal
					? ( i18n.confirm_delete_goal  ?? '¿Eliminar este gol?' )
					: ( i18n.confirm_delete_event ?? '¿Eliminar este incidente?' );

				if ( ! window.confirm( msg ) ) return;

				const eventId = Number( delBtn.dataset.eventId );
				const team    = delBtn.dataset.team;
				const row     = delBtn.closest( 'tr' );

				delBtn.disabled = true;

				try {
					await apiDelete( `soccertrack/v1/admin/match/${ MID }/event/${ eventId }` );
					row?.remove();
					if ( isGoal ) decrementScore( team );
				} catch ( err ) {
					alert( `${ i18n.error_save ?? 'Error.' } ${ err.message }` );
					delBtn.disabled = false;
				}
			}
		} );
	}

	/* ------------------------------------------------------------------ */
	/* Formularios de tarjetas (Amarilla / Roja)                           */
	/* ------------------------------------------------------------------ */

	function setupCardForms() {
		document.addEventListener( 'submit', async e => {
			if ( submitted ) return;

			const form = e.target.closest( '.st-incident-form[data-incident-type]' );
			if ( ! form ) return;

			e.preventDefault();

			const typeMap   = { yellow: 'yellow_card', red: 'red_card' };
			const formType  = form.dataset.incidentType;
			const eventType = typeMap[ formType ];

			// Solo manejar tarjetas aquí (goles van por el modal).
			if ( ! eventType ) return;

			const playerSelect  = qs( '[name="player_id"]', form );
			const playerId      = Number( playerSelect?.value ?? 0 );
			const teamId        = Number( playerSelect?.options[ playerSelect.selectedIndex ]?.dataset.teamId ?? 0 );
			const minute        = Number( qs( '[name="minute"]', form )?.value ?? 0 );
			const description   = ( qs( '[name="description"]', form )?.value ?? '' ).trim();
			const playerName    = playerSelect?.options[ playerSelect.selectedIndex ]?.text?.split( ' (' )[0] ?? '';
			const teamName      = playerSelect?.options[ playerSelect.selectedIndex ]?.text?.match( /\(([^)]+)\)$/ )?.[1] ?? '';

			const noticeEl  = qs( '.st-incident-notice', form );
			const submitBtn = qs( '[type="submit"]', form );

			if ( ! playerId || ! teamId || minute < 1 ) {
				showNotice( noticeEl, i18n.missing_fields ?? 'Selecciona jugador y minuto.', 'warning' );
				return;
			}

			setLoading( submitBtn, true );

			try {
				const result = await apiPost(
					`soccertrack/v1/admin/match/${ MID }/event`,
					{ player_id: playerId, team_id: teamId, event_type: eventType, minute, description }
				);

				if ( eventType === 'red_card' ) {
					showNotice(
						noticeEl,
						i18n.red_card_tribunal ?? '🔴 Tarjeta roja registrada. El Tribunal de Disciplina determinará la sanción.',
						'warning'
					);
				} else {
					noticeEl.innerHTML = '';
				}

				appendIncidentRow( {
					eventId:       result.event_id,
					type:          eventType,
					playerName:    result.player_name || playerName,
					playerData:    null,
					minute,
					teamName:      result.team_name ?? teamName,
					team:          null,
					description,
					createdBy:     result.created_by ?? cfg.currentUserId,
					createdByName: result.created_by_name ?? '',
					updatedByName: '',
					updatedAt:     null,
					canEdit:       result.can_edit ?? cfg.canEditIncidents,
					canDelete:     result.can_delete ?? true,
				} );

				form.reset();
				setLoading( submitBtn, false );
			} catch ( err ) {
				showNotice( noticeEl, `${ i18n.error_save ?? 'Error al registrar.' } ${ err.message }`, 'error' );
				setLoading( submitBtn, false );
			}
		} );
	}

	/* ------------------------------------------------------------------ */
	/* Cierre de partido                                                    */
	/* ------------------------------------------------------------------ */

	function setupResultForm() {
		const btn    = qs( '#st-submit-result' );
		const notice = qs( '#st-result-notice' );
		if ( ! btn ) return;

		// Bloquear si no hay árbitro asignado.
		if ( ! cfg.refereeUserId ) {
			btn.disabled = true;
			btn.title    = i18n.no_referee ?? '⚠️ Debes asignar un árbitro antes de cerrar el partido.';
			showNotice(
				notice,
				i18n.no_referee ?? '⚠️ Debes asignar un árbitro antes de cerrar el partido.',
				'warning'
			);
		}

		btn.addEventListener( 'click', async () => {
			if ( submitted ) return;

			if ( ! cfg.refereeUserId ) {
				showNotice(
					notice,
					i18n.no_referee ?? '⚠️ Debes asignar un árbitro antes de cerrar el partido.',
					'warning'
				);
				return;
			}

			const confirmed = window.confirm(
				`${ i18n.confirm_result ?? '¿Confirmar resultado?' } ${ homeScore } – ${ awayScore }`
			);
			if ( ! confirmed ) return;

			setLoading( btn, true );
			if ( notice ) notice.innerHTML = '';

			try {
				await apiPost(
					`soccertrack/v1/admin/match/${ MID }/result`,
					{ home_score: homeScore, away_score: awayScore }
				);

				submitted = true;

				qsa( '[data-score-action], #st-submit-result, .st-incident-form button, .st-incident-edit, .st-incident-delete' )
					.forEach( el => { el.disabled = true; } );

				showNotice( notice, i18n.result_saved ?? 'Resultado registrado correctamente.', 'success' );
			} catch ( err ) {
				showNotice( notice, `${ i18n.error_save ?? 'Error al guardar.' } ${ err.message }`, 'error' );
				setLoading( btn, false );
			}
		} );
	}

	/* ------------------------------------------------------------------ */
	/* Carga de eventos existentes                                          */
	/* ------------------------------------------------------------------ */

	async function loadExistingEvents() {
		try {
			const events = await apiFetch( `soccertrack/v1/admin/match/${ MID }/events` );
			// API devuelve ASC; prepend invierte → cronológico de más reciente arriba.
			[ ...events ].reverse().forEach( ev => {
				const name = `${ ev.first_name } ${ ev.last_name }`;

				// Determinar a qué equipo pertenece.
				const isHome = Number( ev.team_id ) === Number( cfg.homeTeam?.id );
				const team   = isHome ? 'home' : 'away';

				// Buscar datos del jugador en el config para poder editar.
				const allPlayers = ( cfg.homePlayers ?? [] ).concat( cfg.awayPlayers ?? [] );
				const playerData = allPlayers.find( p => p.id === Number( ev.player_id ) ) ?? null;

				appendIncidentRow( {
					eventId:       Number( ev.id ),
					type:          ev.event_type,
					playerName:    name,
					playerData,
					minute:        Number( ev.minute ),
					teamName:      ev.team_name ?? '',
					team,
					description:   ev.description ?? '',
					createdBy:     Number( ev.created_by ?? 0 ),
					createdByName: ev.created_by_name ?? '',
					updatedByName: ev.updated_by_name ?? '',
					updatedAt:     ev.updated_at ?? null,
					canEdit:       ev.can_edit ?? false,
					canDelete:     ev.can_delete ?? false,
				} );
			} );
		} catch {
			// No crítico: si falla solo no se pre-cargan los eventos.
		}
	}

	/* ------------------------------------------------------------------ */
	/* Timer de partido (visual, no persistido)                            */
	/* ------------------------------------------------------------------ */

	function setupMatchTimer() {
		const display = qs( '#st-match-timer' );
		if ( ! display ) return;

		let seconds  = 0;
		let interval = null;

		const startBtn = qs( '#st-timer-start' );
		const stopBtn  = qs( '#st-timer-stop' );
		const resetBtn = qs( '#st-timer-reset' );

		function tick() {
			seconds++;
			const m = String( Math.floor( seconds / 60 ) ).padStart( 2, '0' );
			const s = String( seconds % 60 ).padStart( 2, '0' );
			display.textContent = `${ m }:${ s }`;

			// Sincronizar minuto del modal con el timer.
			const minuteInput = qs( '#st-modal-minute' );
			if ( minuteInput && document.activeElement !== minuteInput ) {
				minuteInput.value = Math.max( 1, Math.floor( seconds / 60 ) );
			}
		}

		startBtn?.addEventListener( 'click', () => {
			if ( interval ) return;
			interval = setInterval( tick, 1000 );
			startBtn.disabled = true;
			if ( stopBtn ) stopBtn.disabled = false;
		} );

		stopBtn?.addEventListener( 'click', () => {
			clearInterval( interval );
			interval = null;
			if ( startBtn ) startBtn.disabled = false;
			if ( stopBtn )  stopBtn.disabled  = true;
		} );

		resetBtn?.addEventListener( 'click', () => {
			clearInterval( interval );
			interval = null;
			seconds  = 0;
			display.textContent = '00:00';
			if ( startBtn ) startBtn.disabled = false;
			if ( stopBtn )  stopBtn.disabled  = true;
		} );
	}

	/* ------------------------------------------------------------------ */
	/* Init                                                                 */
	/* ------------------------------------------------------------------ */

	function init() {
		if ( ! MID ) return;

		buildModal();
		if ( cfg.canEditIncidents ) buildEditModal();
		setupScoreControls();
		setupResultForm();
		setupCardForms();
		setupIncidentActions();
		setupMatchTimer();
		updateScoreDisplay();
		loadExistingEvents();
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}

} )();
