<?php
/**
 * Panel de Importación Masiva — Coordinador de Liga.
 *
 * Acceso: ds_coordinador (ds_load_excel) y administrator.
 *
 * Recibe:
 *   $tournament_id int           Torneo activo seleccionado.
 *   $tournaments   array[]       Lista de torneos disponibles {id, name}.
 *   $teams         array[]       Equipos del torneo seleccionado {id, name}.
 *
 * @package SoccerTrack
 */

defined( 'ABSPATH' ) || exit;
?>

<div class="st-admin-wrap">

	<h1 class="st-page-title">
		<?php esc_html_e( 'Importación Masiva', 'soccertrack' ); ?>
	</h1>

	<p style="color:#666;margin-bottom:24px">
		<?php esc_html_e( 'Sube la planilla oficial de nómina (XLSX) para registrar un equipo y sus jugadores en un solo paso.', 'soccertrack' ); ?>
	</p>

	<?php /* ── Selector de torneo ─────────────────────────────────────── */ ?>
	<div class="st-card">
		<div class="st-card__header">
			<h2 class="st-card__title"><?php esc_html_e( 'Torneo activo', 'soccertrack' ); ?></h2>
		</div>
		<form method="get">
			<input type="hidden" name="page" value="soccertrack-import">
			<div style="display:flex;gap:12px;align-items:flex-end">
				<div class="st-form-group" style="flex:1">
					<label class="st-form-label" for="st-select-tournament">
						<?php esc_html_e( 'Selecciona un torneo', 'soccertrack' ); ?>
					</label>
					<select id="st-select-tournament" name="tournament_id" class="st-form-control">
						<option value=""><?php esc_html_e( '— Todos los torneos —', 'soccertrack' ); ?></option>
						<?php foreach ( $tournaments as $t ) : ?>
							<option
								value="<?php echo esc_attr( (string) $t['id'] ); ?>"
								<?php selected( (int) $t['id'], $tournament_id ); ?>
							>
								<?php echo esc_html( $t['name'] ); ?>
							</option>
						<?php endforeach; ?>
					</select>
				</div>
				<button type="submit" class="st-btn st-btn--secondary">
					<?php esc_html_e( 'Cambiar', 'soccertrack' ); ?>
				</button>
			</div>
		</form>
	</div>

	<?php if ( ! $tournament_id ) : ?>
		<div class="st-notice st-notice--info">
			<?php esc_html_e( 'Selecciona un torneo para habilitar la importación.', 'soccertrack' ); ?>
		</div>
	<?php else : ?>

	<?php /* ── Nómina de equipo (planilla oficial) ───────────────────────── */ ?>
	<div class="st-card">
		<div class="st-card__header">
			<h2 class="st-card__title">
				<?php esc_html_e( 'Nómina de Equipo', 'soccertrack' ); ?>
			</h2>
		</div>

		<p style="font-size:0.85rem;color:#666;margin-bottom:4px">
			<?php esc_html_e( 'Planilla oficial (XLSX) con una hoja "Nomina". Formato:', 'soccertrack' ); ?>
		</p>
		<ul style="font-size:0.82rem;color:#666;margin:0 0 16px 18px;line-height:1.7">
			<li><?php esc_html_e( 'C1–C8: datos de empresa y delegado', 'soccertrack' ); ?></li>
			<li><?php esc_html_e( 'Fila 11 en adelante: N° · Nombre · Ap.Paterno · Ap.Materno · RUT · Correo · Edad · Teléfono · Área · Cargo', 'soccertrack' ); ?></li>
		</ul>

		<div
			class="st-dropzone"
			id="st-dropzone-roster"
			data-endpoint="soccertrack/v1/admin/import/roster"
			data-extra-field="tournament_id"
			data-extra-value="<?php echo esc_attr( (string) $tournament_id ); ?>"
		>
			<div class="st-dropzone__icon" aria-hidden="true">📋</div>
			<p class="st-dropzone__text"><?php esc_html_e( 'Arrastra la planilla aquí, o haz clic para buscar', 'soccertrack' ); ?></p>
			<p class="st-dropzone__hint"><?php esc_html_e( 'XLSX · Máximo 5 MB', 'soccertrack' ); ?></p>
			<input
				type="file"
				accept=".xlsx,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet"
				aria-label="<?php esc_attr_e( 'Planilla de nómina', 'soccertrack' ); ?>"
			>
		</div>

		<div class="st-import-result" id="st-result-roster"></div>
	</div>

	<?php /* ── Logos de equipos ─────────────────────────────────────────── */ ?>
	<div class="st-card" id="st-logos-card">
		<div class="st-card__header">
			<h2 class="st-card__title">
				<?php esc_html_e( 'Logos de Equipos', 'soccertrack' ); ?>
			</h2>
		</div>

		<p style="font-size:0.85rem;color:#666;margin-bottom:16px">
			<?php esc_html_e( 'Sube una imagen por equipo (JPG, PNG o WEBP · Máximo 2 MB).', 'soccertrack' ); ?>
		</p>

		<?php if ( empty( $teams ) ) : ?>
			<p class="st-notice st-notice--info"><?php esc_html_e( 'No hay equipos en este torneo.', 'soccertrack' ); ?></p>
		<?php else : ?>
		<div class="st-logo-grid">
			<?php foreach ( $teams as $t ) : ?>
			<div class="st-logo-item">
				<?php if ( ! empty( $t['logo_url'] ) ) : ?>
					<img src="<?php echo esc_url( $t['logo_url'] ); ?>"
						 alt="<?php echo esc_attr( $t['name'] ); ?>"
						 class="st-logo-preview" id="st-logo-preview-<?php echo esc_attr( (string) $t['id'] ); ?>">
				<?php else : ?>
					<div class="st-logo-placeholder" id="st-logo-preview-<?php echo esc_attr( (string) $t['id'] ); ?>">⚽</div>
				<?php endif; ?>
				<span class="st-logo-team-name"><?php echo esc_html( $t['name'] ); ?></span>
				<div
					class="st-dropzone st-dropzone--logo"
					id="st-dropzone-logo-<?php echo esc_attr( (string) $t['id'] ); ?>"
					data-endpoint="soccertrack/v1/admin/import/team-logo"
					data-extra-field="team_id"
					data-extra-value="<?php echo esc_attr( (string) $t['id'] ); ?>"
					data-preview="st-logo-preview-<?php echo esc_attr( (string) $t['id'] ); ?>"
				>
					<span><?php esc_html_e( 'Subir logo', 'soccertrack' ); ?></span>
					<input type="file" accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp"
						   aria-label="<?php echo esc_attr( $t['name'] ); ?>">
				</div>
				<div class="st-import-result" id="st-result-logo-<?php echo esc_attr( (string) $t['id'] ); ?>" style="font-size:.8rem"></div>
			</div>
			<?php endforeach; ?>
		</div>
		<?php endif; ?>
	</div>

	<?php /* ── Importar Árbitros ─────────────────────────────────────────── */ ?>
	<div class="st-card">
		<div class="st-card__header">
			<h2 class="st-card__title">
				<?php esc_html_e( 'Importar Árbitros', 'soccertrack' ); ?>
			</h2>
		</div>

		<p style="font-size:0.85rem;color:#666;margin-bottom:12px">
			<?php esc_html_e( 'Columnas: A = Nombre · B = Apellido · C = Correo electrónico', 'soccertrack' ); ?>
			<br><em><?php esc_html_e( 'Se crea un usuario WordPress con rol Árbitro. Las contraseñas temporales se mostrarán al finalizar.', 'soccertrack' ); ?></em>
		</p>

		<div
			class="st-dropzone"
			id="st-dropzone-referees"
			data-endpoint="soccertrack/v1/admin/import/referees"
			data-extra-field=""
			data-extra-value=""
		>
			<div class="st-dropzone__icon" aria-hidden="true">🏁</div>
			<p class="st-dropzone__text"><?php esc_html_e( 'Arrastra tu archivo aquí, o haz clic para buscar', 'soccertrack' ); ?></p>
			<p class="st-dropzone__hint"><?php esc_html_e( 'CSV o XLSX · Máximo 5 MB', 'soccertrack' ); ?></p>
			<input
				type="file"
				accept=".csv,.xlsx,text/csv,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet"
				aria-label="<?php esc_attr_e( 'Archivo de árbitros', 'soccertrack' ); ?>"
			>
		</div>

		<div class="st-import-result" id="st-result-referees"></div>
	</div>

	<?php endif; ?>

</div>

<?php
// Inline JS del importador (drag-drop + fetch).
// Solo se incluye en esta página — no en el frontend.
$nonce = wp_create_nonce( 'wp_rest' );
?>
<script>
( () => {
	'use strict';

	const API   = <?php echo wp_json_encode( esc_url_raw( get_rest_url() ) ); ?>;
	const NONCE = <?php echo wp_json_encode( $nonce ); ?>;

	function escHtml( s ) {
		return String( s ).replace( /&/g,'&amp;' ).replace( /</g,'&lt;' ).replace( />/g,'&gt;' );
	}

	function showResult( resultEl, data ) {
		resultEl.classList.add( 'is-visible' );

		const errors = data.errors ?? [];

		let teamBannerHtml = '';
		if ( data.team_name ) {
			teamBannerHtml = `<p style="margin-bottom:8px;font-weight:600">
				<?php esc_html_e( 'Equipo:', 'soccertrack' ); ?> ${ escHtml( data.team_name ) }</p>`;
		}

		let passwordsHtml = '';
		if ( data.passwords && Object.keys( data.passwords ).length ) {
			const rows = Object.entries( data.passwords )
				.map( ( [ email, pwd ] ) => `<tr><td>${ escHtml( email ) }</td><td><code style="user-select:all">${ escHtml( pwd ) }</code></td></tr>` )
				.join( '' );
			passwordsHtml = `<p style="margin-top:12px;font-weight:600"><?php esc_html_e( 'Contraseñas temporales:', 'soccertrack' ); ?></p>
				<table style="border-collapse:collapse;font-size:.85rem;width:100%">
					<thead><tr><th style="text-align:left;padding:4px 8px;border-bottom:1px solid #ddd"><?php esc_html_e( 'Correo', 'soccertrack' ); ?></th>
					<th style="text-align:left;padding:4px 8px;border-bottom:1px solid #ddd"><?php esc_html_e( 'Contraseña', 'soccertrack' ); ?></th></tr></thead>
					<tbody>${ rows }</tbody>
				</table>`;
		}

		// "Actualizados" solo aparece cuando la respuesta lo incluye (import de nómina).
		const updatedBadgeHtml = data.updated !== undefined
			? `<div class="st-stat-badge st-stat-badge--updated" title="<?php esc_attr_e( 'Jugadores ya inscritos en este equipo — datos personales y área/cargo actualizados.', 'soccertrack' ); ?>">
					<span class="st-stat-badge__num">${ data.updated }</span>
					<span class="st-stat-badge__label"><?php esc_html_e( 'Actualizados', 'soccertrack' ); ?></span>
				</div>`
			: '';

		// "Omitidos" = conflictos reales (jugador ya en otro equipo del torneo).
		const skippedLabel = data.updated !== undefined
			? '<?php esc_html_e( 'Conflictos', 'soccertrack' ); ?>'
			: '<?php esc_html_e( 'Omitidos', 'soccertrack' ); ?>';

		resultEl.innerHTML = `
			${ teamBannerHtml }
			<div class="st-import-stats">
				<div class="st-stat-badge st-stat-badge--imported">
					<span class="st-stat-badge__num">${ data.imported ?? 0 }</span>
					<span class="st-stat-badge__label"><?php esc_html_e( 'Importados', 'soccertrack' ); ?></span>
				</div>
				${ updatedBadgeHtml }
				<div class="st-stat-badge st-stat-badge--skipped">
					<span class="st-stat-badge__num">${ data.skipped ?? 0 }</span>
					<span class="st-stat-badge__label">${ skippedLabel }</span>
				</div>
				<div class="st-stat-badge st-stat-badge--errors">
					<span class="st-stat-badge__num">${ errors.length }</span>
					<span class="st-stat-badge__label"><?php esc_html_e( 'Errores', 'soccertrack' ); ?></span>
				</div>
			</div>
			${ errors.length ? `<ul class="st-error-list">${ errors.map( e => `<li>${ escHtml( e ) }</li>` ).join('') }</ul>` : '' }
			${ passwordsHtml }`;
	}

	function showUploadError( resultEl, msg ) {
		resultEl.classList.add( 'is-visible' );
		resultEl.innerHTML = `<div class="st-notice st-notice--error">${ escHtml( msg ) }</div>`;
	}

	/**
	 * Reemplaza #st-logos-card con la versión actualizada de la página actual.
	 * Se llama tras un import de nómina exitoso para que aparezca el nuevo equipo.
	 */
	async function refreshLogosCard() {
		const logosCard = document.getElementById( 'st-logos-card' );
		if ( ! logosCard ) return;

		try {
			const resp = await fetch( window.location.href, { credentials: 'same-origin' } );
			if ( ! resp.ok ) return;

			const html   = await resp.text();
			const parser = new DOMParser();
			const doc    = parser.parseFromString( html, 'text/html' );
			const fresh  = doc.getElementById( 'st-logos-card' );

			if ( fresh ) {
				logosCard.replaceWith( fresh );
				// Re-inicializar los dropzones de logo del nuevo HTML.
				fresh.querySelectorAll( '.st-dropzone' ).forEach( initDropzone );
			}
		} catch ( _e ) {
			// Si falla el fetch, no bloqueamos — el usuario puede recargar manualmente.
		}
	}

	function initDropzone( dropzoneEl ) {
		const input     = dropzoneEl.querySelector( 'input[type="file"]' );
		const resultEl  = document.getElementById( 'st-result-' + dropzoneEl.id.replace( 'st-dropzone-', '' ) );
		const endpoint  = dropzoneEl.dataset.endpoint;
		const extraField = dropzoneEl.dataset.extraField;
		const extraValue = dropzoneEl.dataset.extraValue;
		const extraSelect = dropzoneEl.dataset.extraSelect
			? document.getElementById( dropzoneEl.dataset.extraSelect )
			: null;

		const isLogo = dropzoneEl.classList.contains( 'st-dropzone--logo' );

		// Para logos: hacer clickeable el preview (⚽ o img existente).
		if ( isLogo ) {
			const previewEl = document.getElementById( dropzoneEl.dataset.preview );
			if ( previewEl ) {
				previewEl.style.cursor = 'pointer';
				previewEl.title = '<?php esc_attr_e( 'Haz clic para cambiar el logo', 'soccertrack' ); ?>';
				previewEl.addEventListener( 'click', () => input?.click() );
			}
		}

		async function upload( file ) {
			const fd = new FormData();
			fd.append( 'file', file );

			const val = extraSelect ? extraSelect.value : extraValue;

			// Validación de tamaño en cliente para logos (2 MB).
			const MAX_LOGO = 2 * 1024 * 1024;
			if ( isLogo && file.size > MAX_LOGO ) {
				const mb = ( file.size / 1024 / 1024 ).toFixed( 1 );
				showUploadError( resultEl,
					`<?php esc_html_e( 'El archivo es demasiado grande', 'soccertrack' ); ?> (${ mb } MB). <?php esc_html_e( 'Máximo 2 MB. Sugerimos imágenes de 200×200 px o menos.', 'soccertrack' ); ?>`
				);
				return;
			}

			if ( ! isLogo && extraField && ! val ) {
				showUploadError( resultEl, '<?php esc_html_e( 'Selecciona el equipo destino antes de subir.', 'soccertrack' ); ?>' );
				return;
			}

			if ( val && extraField ) {
				fd.append( extraField, val );
			}

			dropzoneEl.style.opacity = '0.5';
			dropzoneEl.style.pointerEvents = 'none';

			try {
				const resp = await fetch( API + endpoint, {
					method:  'POST',
					headers: { 'X-WP-Nonce': NONCE },
					body:    fd,
				} );

				const data = await resp.json();

				if ( ! resp.ok ) {
					showUploadError( resultEl, data.message ?? 'Error HTTP ' + resp.status );
				} else if ( isLogo && data.logo_url ) {
					// Logo: actualizar preview y mantenerlo clickeable para reemplazos.
					const previewId = dropzoneEl.dataset.preview;
					const preview   = document.getElementById( previewId );
					if ( preview ) {
						const img       = document.createElement( 'img' );
						img.src         = data.logo_url;
						img.id          = previewId;
						img.className   = 'st-logo-preview';
						img.alt         = '';
						img.title       = '<?php esc_attr_e( 'Haz clic para cambiar el logo', 'soccertrack' ); ?>';
						img.style.cursor = 'pointer';
						img.addEventListener( 'click', () => input?.click() );
						preview.replaceWith( img );
					}
					resultEl.classList.add( 'is-visible' );
					resultEl.innerHTML = `<span style="color:green">✔ <?php esc_html_e( 'Logo guardado', 'soccertrack' ); ?></span>`;
				} else {
					showResult( resultEl, data );

					// Tras import de nómina exitoso, refrescar la sección de logos
					// para mostrar el equipo recién creado sin recargar la página entera.
					if ( dropzoneEl.id === 'st-dropzone-roster' && data.team_id ) {
						refreshLogosCard();
					}
				}
			} catch ( err ) {
				showUploadError( resultEl, err.message );
			} finally {
				dropzoneEl.style.opacity = '';
				dropzoneEl.style.pointerEvents = '';
			}
		}

		// Click en el input.
		input?.addEventListener( 'change', () => {
			if ( input.files?.[0] ) upload( input.files[0] );
		} );

		// Drag & drop.
		dropzoneEl.addEventListener( 'dragover', ( e ) => {
			e.preventDefault();
			dropzoneEl.classList.add( 'is-dragover' );
		} );

		dropzoneEl.addEventListener( 'dragleave', () => {
			dropzoneEl.classList.remove( 'is-dragover' );
		} );

		dropzoneEl.addEventListener( 'drop', ( e ) => {
			e.preventDefault();
			dropzoneEl.classList.remove( 'is-dragover' );
			const file = e.dataTransfer.files?.[0];
			if ( file ) upload( file );
		} );
	}

	document.querySelectorAll( '.st-dropzone' ).forEach( initDropzone );

} )();
</script>
