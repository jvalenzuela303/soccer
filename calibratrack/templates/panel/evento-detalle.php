<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
$page_title    = __( 'Editar evento', 'calibratrack' );
$evento_id     = isset( $evento_id ) ? (int) $evento_id : 0;
$valores       = isset( $valores ) ? $valores : array();
$errors        = isset( $errors ) ? $errors : array();
$equipos       = isset( $equipos ) ? $equipos : array();
$es_completado = ! empty( $es_completado );
$es_tecnico    = ! empty( $es_tecnico );
$actualizado   = isset( $_GET['actualizado'] ) ? true : false;
$cert_id       = $evento_id ? (int) get_post_meta( $evento_id, CalibraTrack_Meta_Keys::EVENTO_CERTIFICADO_PDF, true ) : 0;
$ot_id         = $evento_id ? (int) get_post_meta( $evento_id, CalibraTrack_Meta_Keys::EVENTO_ORDEN_TRABAJO_PDF, true ) : 0;
include __DIR__ . '/_partials/header.php';
?>

<div class="ct-container">
	<div class="ct-page-header">
		<h1 class="ct-page-title">
			<?php if ( $es_completado ) : ?>
				<?php esc_html_e( 'Orden de Trabajo — Finalizada', 'calibratrack' ); ?>
			<?php else : ?>
				<?php esc_html_e( 'Editar evento', 'calibratrack' ); ?>
			<?php endif; ?>
		</h1>
		<a href="<?php echo esc_url( home_url( '/panel/eventos/' ) ); ?>" class="ct-link">&larr; <?php esc_html_e( 'Volver a mis eventos', 'calibratrack' ); ?></a>
	</div>

	<?php if ( $actualizado ) : ?>
		<div class="ct-alert ct-alert--success" role="alert">
			<?php esc_html_e( 'Evento actualizado correctamente.', 'calibratrack' ); ?>
			<?php if ( $cert_id && ! $es_tecnico ) : ?>
				&mdash; <a href="<?php echo esc_url( home_url( '/panel/evento/' . $evento_id . '/certificado/' ) ); ?>" target="_blank" class="ct-link">
					<?php esc_html_e( 'Ver certificado PDF', 'calibratrack' ); ?>
				</a>
			<?php endif; ?>
		</div>
	<?php endif; ?>

	<?php if ( $es_completado ) : ?>
		<div class="ct-alert ct-alert--info" role="alert" style="background:#f0f9ff;border-left:4px solid #0ea5e9;color:#0369a1;padding:14px 18px;border-radius:6px;margin-bottom:16px;">
			<strong><?php esc_html_e( 'Orden de Trabajo finalizada', 'calibratrack' ); ?></strong> —
			<?php esc_html_e( 'Esta OT ya fue completada y su certificado emitido. Los datos no pueden ser modificados.', 'calibratrack' ); ?>
		</div>

		<div style="display:flex;gap:12px;flex-wrap:wrap;margin-bottom:24px;">
			<?php if ( $ot_id ) : ?>
				<a href="<?php echo esc_url( wp_get_attachment_url( $ot_id ) ); ?>" target="_blank" class="ct-btn ct-btn--secondary">
					<?php esc_html_e( 'Ver Orden de Trabajo PDF', 'calibratrack' ); ?>
				</a>
			<?php endif; ?>
			<?php if ( $cert_id && ! $es_tecnico ) : ?>
				<a href="<?php echo esc_url( home_url( '/panel/evento/' . $evento_id . '/certificado/' ) ); ?>" target="_blank" class="ct-btn ct-btn--primary">
					<?php esc_html_e( 'Ver Certificado PDF', 'calibratrack' ); ?>
				</a>
			<?php endif; ?>
		</div>

		<div style="background:#f9fafb;border:1px solid #e5e7eb;border-radius:8px;padding:20px;font-size:14px;">
			<h2 style="font-size:15px;font-weight:700;margin:0 0 14px;color:#111827;"><?php esc_html_e( 'Datos del evento', 'calibratrack' ); ?></h2>
			<?php
			$v = function( $key, $default = '—' ) use ( $valores ) {
				$val = isset( $valores[ $key ] ) ? $valores[ $key ] : '';
				return '' !== $val ? $val : $default;
			};
			$tipos_map  = CalibraTrack_Helpers::get_tipos_evento();
			$tipo_label = isset( $tipos_map[ $v('tipo', '') ] ) ? $tipos_map[ $v('tipo', '') ] : $v('tipo');
			?>
			<table style="width:100%;border-collapse:collapse;font-size:14px;">
				<tr><td style="padding:6px 10px;font-weight:600;width:180px;color:#374151;"><?php esc_html_e( 'N° OT', 'calibratrack' ); ?></td><td style="padding:6px 10px;"><?php echo esc_html( $v('numero_ot') ); ?></td></tr>
				<tr style="background:#f3f4f6;"><td style="padding:6px 10px;font-weight:600;color:#374151;"><?php esc_html_e( 'Tipo', 'calibratrack' ); ?></td><td style="padding:6px 10px;"><?php echo esc_html( $tipo_label ); ?></td></tr>
				<tr><td style="padding:6px 10px;font-weight:600;color:#374151;"><?php esc_html_e( 'Fecha ejecución', 'calibratrack' ); ?></td><td style="padding:6px 10px;"><?php echo esc_html( $v('fecha_ejecucion') ); ?></td></tr>
				<tr style="background:#f3f4f6;"><td style="padding:6px 10px;font-weight:600;color:#374151;"><?php esc_html_e( 'Próx. control', 'calibratrack' ); ?></td><td style="padding:6px 10px;"><?php echo esc_html( $v('proxima_fecha') ); ?></td></tr>
				<tr><td style="padding:6px 10px;font-weight:600;color:#374151;"><?php esc_html_e( 'Falla reportada', 'calibratrack' ); ?></td><td style="padding:6px 10px;white-space:pre-wrap;"><?php echo esc_html( $v('falla_reportada') ); ?></td></tr>
				<tr style="background:#f3f4f6;"><td style="padding:6px 10px;font-weight:600;color:#374151;"><?php esc_html_e( 'Trabajo realizado', 'calibratrack' ); ?></td><td style="padding:6px 10px;white-space:pre-wrap;"><?php echo esc_html( $v('descripcion_trabajo') ); ?></td></tr>
				<?php if ( $v('observaciones', '') !== '—' ) : ?>
				<tr><td style="padding:6px 10px;font-weight:600;color:#374151;"><?php esc_html_e( 'Observaciones', 'calibratrack' ); ?></td><td style="padding:6px 10px;white-space:pre-wrap;"><?php echo esc_html( $v('observaciones') ); ?></td></tr>
				<?php endif; ?>
			</table>
		</div>

		<?php
		// Hilo de mensajes para OT finalizada (solo lectura).
		// Se muestra tanto al técnico como al admin. El partial mostrará aviso
		// de "OT finalizada" en lugar del formulario de envío.
		if ( $evento_id > 0 && class_exists( 'CalibraTrack_Mensajes' ) ) {
			$mensajes      = isset( $mensajes_ot ) ? $mensajes_ot : array();
			// $es_tecnico y $es_completado ya están definidas en el ámbito del template.
			include __DIR__ . '/_partials/mensajes.php';
		}
		?>

	<?php else : ?>

		<?php if ( $es_tecnico ) : ?>
			<?php
			// Datos de la OT para la tarjeta informativa (solo lectura).
			$ot_equipo_id  = (int) get_post_meta( $evento_id, CalibraTrack_Meta_Keys::EVENTO_EQUIPO_ID, true );
			$ot_numero_ot  = (string) get_post_meta( $evento_id, CalibraTrack_Meta_Keys::EVENTO_NUMERO_OT, true );
			$ot_tipo       = (string) get_post_meta( $evento_id, CalibraTrack_Meta_Keys::EVENTO_TIPO, true );
			$ot_fecha      = (string) get_post_meta( $evento_id, CalibraTrack_Meta_Keys::EVENTO_FECHA_EJECUCION, true );
			$ot_proxima    = (string) get_post_meta( $evento_id, CalibraTrack_Meta_Keys::EVENTO_PROXIMA_FECHA_CONTROL, true );
			$ot_falla      = (string) get_post_meta( $evento_id, CalibraTrack_Meta_Keys::EVENTO_FALLA_REPORTADA, true );
			$ot_oi_id      = (int) get_post_meta( $evento_id, CalibraTrack_Meta_Keys::EVENTO_INGRESO_RELACIONADO_ID, true );
			$ot_serie      = $ot_equipo_id ? (string) get_post_meta( $ot_equipo_id, CalibraTrack_Meta_Keys::EQUIPO_SERIE, true ) : '—';
			$ot_marca      = $ot_equipo_id ? (string) get_post_meta( $ot_equipo_id, CalibraTrack_Meta_Keys::EQUIPO_MARCA, true ) : '';
			$ot_modelo     = $ot_equipo_id ? (string) get_post_meta( $ot_equipo_id, CalibraTrack_Meta_Keys::EQUIPO_MODELO, true ) : '';
			$ot_oi_numero  = $ot_oi_id ? (string) get_post_meta( $ot_oi_id, CalibraTrack_Meta_Keys::EVENTO_NUMERO_OI, true ) : '';
			$tipos_map     = CalibraTrack_Helpers::get_tipos_evento();
			$ot_tipo_label = isset( $tipos_map[ $ot_tipo ] ) ? $tipos_map[ $ot_tipo ] : $ot_tipo;
			$estados_srv   = CalibraTrack_Helpers::get_estados_servicio();
			$ot_estado_raw = (string) get_post_meta( $evento_id, CalibraTrack_Meta_Keys::EVENTO_ESTADO_SERVICIO, true );
			if ( empty( $ot_estado_raw ) ) { $ot_estado_raw = 'en_proceso'; }
			$ot_estado_cfg = isset( $estados_srv[ $ot_estado_raw ] ) ? $estados_srv[ $ot_estado_raw ] : $estados_srv['en_proceso'];
			$v_edit = function( $key, $default = '' ) use ( $valores ) {
				return isset( $valores[ $key ] ) ? $valores[ $key ] : $default;
			};
			$estado_actual_form = $v_edit( 'estado_servicio', $ot_estado_raw );
			if ( empty( $estado_actual_form ) ) { $estado_actual_form = 'en_proceso'; }
			?>

			<!-- Tarjeta informativa (solo lectura) -->
			<div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px;padding:20px;margin-bottom:24px;">
				<h2 style="font-size:15px;font-weight:700;margin:0 0 14px;color:#1e293b;"><?php esc_html_e( 'Información de la OT', 'calibratrack' ); ?></h2>
				<table style="width:100%;border-collapse:collapse;font-size:14px;">
					<tr>
						<td style="padding:6px 10px;font-weight:600;width:180px;color:#374151;"><?php esc_html_e( 'N° OT', 'calibratrack' ); ?></td>
						<td style="padding:6px 10px;"><?php echo esc_html( $ot_numero_ot ?: '—' ); ?></td>
					</tr>
					<?php if ( $ot_oi_numero ) : ?>
					<tr style="background:#f3f4f6;">
						<td style="padding:6px 10px;font-weight:600;color:#374151;"><?php esc_html_e( 'OI vinculada', 'calibratrack' ); ?></td>
						<td style="padding:6px 10px;"><?php echo esc_html( $ot_oi_numero ); ?></td>
					</tr>
					<?php endif; ?>
					<tr>
						<td style="padding:6px 10px;font-weight:600;color:#374151;"><?php esc_html_e( 'Equipo', 'calibratrack' ); ?></td>
						<td style="padding:6px 10px;"><?php echo esc_html( trim( $ot_serie . ' — ' . $ot_marca . ' ' . $ot_modelo ) ); ?></td>
					</tr>
					<tr style="background:#f3f4f6;">
						<td style="padding:6px 10px;font-weight:600;color:#374151;"><?php esc_html_e( 'Tipo de servicio', 'calibratrack' ); ?></td>
						<td style="padding:6px 10px;"><?php echo esc_html( $ot_tipo_label ); ?></td>
					</tr>
					<tr>
						<td style="padding:6px 10px;font-weight:600;color:#374151;"><?php esc_html_e( 'Fecha ejecución', 'calibratrack' ); ?></td>
						<td style="padding:6px 10px;"><?php echo esc_html( $ot_fecha ?: '—' ); ?></td>
					</tr>
					<tr style="background:#f3f4f6;">
						<td style="padding:6px 10px;font-weight:600;color:#374151;"><?php esc_html_e( 'Próx. control', 'calibratrack' ); ?></td>
						<td style="padding:6px 10px;"><?php echo esc_html( $ot_proxima ?: '—' ); ?></td>
					</tr>
					<?php if ( $ot_falla ) : ?>
					<tr>
						<td style="padding:6px 10px;font-weight:600;color:#374151;"><?php esc_html_e( 'Falla reportada', 'calibratrack' ); ?></td>
						<td style="padding:6px 10px;white-space:pre-wrap;"><?php echo esc_html( $ot_falla ); ?></td>
					</tr>
					<?php endif; ?>
					<tr style="background:#f3f4f6;">
						<td style="padding:6px 10px;font-weight:600;color:#374151;"><?php esc_html_e( 'Estado actual', 'calibratrack' ); ?></td>
						<td style="padding:6px 10px;">
							<span class="ct-badge <?php echo esc_attr( $ot_estado_cfg['clase'] ); ?>">
								<?php echo esc_html( $ot_estado_cfg['label'] ); ?>
							</span>
						</td>
					</tr>
				</table>
			</div>

			<!-- Formulario técnico: solo campos editables -->
			<?php if ( ! empty( $errors['general'] ) ) : ?>
				<div class="ct-alert ct-alert--error" role="alert"><?php echo esc_html( $errors['general'] ); ?></div>
			<?php endif; ?>

			<form method="post" action="" enctype="multipart/form-data" class="ct-form" novalidate>
				<?php wp_nonce_field( 'calibratrack_tecnico_evento' ); ?>

				<div class="ct-field">
					<label for="ct-descripcion" class="ct-label"><?php esc_html_e( 'Descripción del trabajo / servicio realizado', 'calibratrack' ); ?></label>
					<textarea id="ct-descripcion" name="descripcion_trabajo" class="ct-textarea" rows="5"><?php echo esc_textarea( $v_edit( 'descripcion_trabajo' ) ); ?></textarea>
				</div>

				<div class="ct-field">
					<label for="ct-observaciones" class="ct-label"><?php esc_html_e( 'Observaciones', 'calibratrack' ); ?></label>
					<textarea id="ct-observaciones" name="observaciones" class="ct-textarea" rows="3"><?php echo esc_textarea( $v_edit( 'observaciones' ) ); ?></textarea>
				</div>

				<!-- Evidencia fotográfica -->
				<div class="ct-field">
					<label class="ct-label"><?php esc_html_e( 'Evidencia fotográfica', 'calibratrack' ); ?></label>

					<?php
					$fotos_raw = get_post_meta( $evento_id, CalibraTrack_Meta_Keys::EVENTO_EVIDENCIA_FOTOGRAFICA, true );
					$fotos_ids = json_decode( (string) $fotos_raw, true );
					if ( ! is_array( $fotos_ids ) ) { $fotos_ids = array(); }
					?>

					<?php if ( ! empty( $fotos_ids ) ) : ?>
					<p class="ct-field-help" style="margin-bottom:8px;"><?php esc_html_e( 'Fotos ya guardadas:', 'calibratrack' ); ?></p>
					<div style="display:flex;flex-wrap:wrap;gap:8px;margin-bottom:16px;">
						<?php foreach ( $fotos_ids as $fid ) :
							$thumb    = wp_get_attachment_image_src( $fid, 'medium' );
							$full_url = wp_get_attachment_url( $fid );
							if ( ! $thumb ) { continue; }
						?>
						<a href="<?php echo esc_url( $full_url ); ?>" target="_blank"
							style="display:block;width:90px;height:90px;border-radius:6px;overflow:hidden;border:2px solid #e5e7eb;flex-shrink:0;">
							<img src="<?php echo esc_url( $thumb[0] ); ?>"
								style="width:100%;height:100%;object-fit:cover;"
								alt="<?php esc_attr_e( 'Foto adjunta', 'calibratrack' ); ?>">
						</a>
						<?php endforeach; ?>
					</div>
					<?php endif; ?>

					<!-- Zona de carga de nuevas fotos (máx. 6 en total) -->
					<?php $fotos_disponibles_tec = max( 0, 6 - count( $fotos_ids ) ); ?>
					<?php if ( $fotos_disponibles_tec > 0 ) : ?>
					<div id="ct-zona-fotos"
						style="border:2px dashed #cbd5e1;border-radius:10px;padding:20px;text-align:center;cursor:pointer;background:#f8fafc;transition:border-color .2s;"
						onmouseover="this.style.borderColor='#6366f1'"
						onmouseout="this.style.borderColor='#cbd5e1'">
						<input type="file" id="ct-input-fotos" name="evidencia_fotografica[]"
							accept="image/jpeg,image/png,image/webp" multiple
							style="display:none;"
							data-max-fotos="<?php echo (int) $fotos_disponibles_tec; ?>">
						<div id="ct-fotos-placeholder">
							<svg width="36" height="36" fill="none" stroke="#94a3b8" stroke-width="1.5" viewBox="0 0 24 24" style="margin:0 auto 8px;display:block;">
								<path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75V16.5m-13.5-9L12 3m0 0l4.5 4.5M12 3v13.5"/>
							</svg>
							<p style="margin:0;font-size:14px;color:#64748b;"><?php esc_html_e( 'Arrastra fotos aquí o', 'calibratrack' ); ?>
								<span style="color:#6366f1;font-weight:600;"><?php esc_html_e( 'haz clic para seleccionar', 'calibratrack' ); ?></span>
							</p>
							<p style="margin:6px 0 0;font-size:12px;color:#94a3b8;">
								<?php
								printf(
									esc_html__( 'JPG, PNG o WEBP · Puedes subir hasta %d foto(s) más (máx. 6 en total)', 'calibratrack' ),
									(int) $fotos_disponibles_tec
								);
								?>
							</p>
						</div>
						<div id="ct-fotos-preview" style="display:none;flex-wrap:wrap;gap:8px;justify-content:center;"></div>
					</div>
					<p style="margin-top:8px;font-size:12px;color:#64748b;" id="ct-fotos-contador"></p>
					<?php else : ?>
					<p style="font-size:13px;color:#dc2626;margin-top:4px;"><?php esc_html_e( 'Se alcanzó el límite de 6 fotos.', 'calibratrack' ); ?></p>
					<?php endif; ?>
				</div>

				<!-- Documentos adjuntos -->
				<div class="ct-field">
					<label class="ct-label"><?php esc_html_e( 'Documentos adjuntos (PDF)', 'calibratrack' ); ?></label>

					<?php
					$docs_raw = get_post_meta( $evento_id, CalibraTrack_Meta_Keys::EVENTO_DOCUMENTOS_ADJUNTOS, true );
					$docs_ids = json_decode( (string) $docs_raw, true );
					if ( ! is_array( $docs_ids ) ) { $docs_ids = array(); }
					?>

					<?php if ( ! empty( $docs_ids ) ) : ?>
					<p class="ct-field-help" style="margin-bottom:8px;"><?php esc_html_e( 'Documentos ya guardados:', 'calibratrack' ); ?></p>
					<ul style="list-style:none;padding:0;margin:0 0 16px;">
						<?php foreach ( $docs_ids as $doc_id ) :
							$doc_url   = wp_get_attachment_url( $doc_id );
							$doc_title = get_the_title( $doc_id );
							if ( ! $doc_title ) { continue; }
						?>
						<li style="display:flex;align-items:center;gap:8px;padding:6px 10px;background:#f8fafc;border:1px solid #e5e7eb;border-radius:6px;margin-bottom:6px;font-size:13px;">
							<svg width="16" height="16" fill="#ef4444" viewBox="0 0 24 24"><path d="M7 3a2 2 0 00-2 2v14a2 2 0 002 2h10a2 2 0 002-2V8l-5-5H7zm5 1l4 4h-4V4z"/></svg>
							<a href="<?php echo esc_url( $doc_url ); ?>" target="_blank" style="color:#374151;text-decoration:none;flex:1;">
								<?php echo esc_html( $doc_title ); ?>
							</a>
						</li>
						<?php endforeach; ?>
					</ul>
					<?php endif; ?>

					<?php $docs_disponibles_tec = max( 0, 2 - count( $docs_ids ) ); ?>
					<?php if ( $docs_disponibles_tec > 0 ) : ?>
					<div id="ct-slots-docs" style="display:flex;flex-direction:column;gap:10px;">
						<?php for ( $slot = 1; $slot <= $docs_disponibles_tec; $slot++ ) : ?>
						<div class="ct-doc-slot" style="display:flex;align-items:center;gap:10px;background:#f8fafc;border:1px solid #e5e7eb;border-radius:8px;padding:10px 14px;">
							<svg width="18" height="18" fill="#94a3b8" viewBox="0 0 24 24" style="flex-shrink:0;"><path d="M7 3a2 2 0 00-2 2v14a2 2 0 002 2h10a2 2 0 002-2V8l-5-5H7zm5 1l4 4h-4V4z"/></svg>
							<input type="file" name="documentos_adjuntos[]"
								accept="application/pdf,.pdf"
								style="flex:1;font-size:13px;color:#374151;"
								onchange="ctDocSlotChange(this)">
							<span class="ct-doc-nombre" style="font-size:12px;color:#6366f1;display:none;"></span>
						</div>
						<?php endfor; ?>
					</div>

					<p style="margin-top:6px;font-size:12px;color:#94a3b8;"><?php esc_html_e( 'PDF · hasta 10 MB por archivo · máx. 2 documentos en total', 'calibratrack' ); ?></p>
					<?php else : ?>
					<p style="font-size:13px;color:#dc2626;margin-top:4px;"><?php esc_html_e( 'Se alcanzó el límite de 2 documentos.', 'calibratrack' ); ?></p>
					<?php endif; ?>
				</div>

				<script>
				(function () {
					'use strict';

					/* ── Zona de fotos ── */
					var zona       = document.getElementById('ct-zona-fotos');
					var inputFotos = document.getElementById('ct-input-fotos');
					var preview    = document.getElementById('ct-fotos-preview');
					var placeholder= document.getElementById('ct-fotos-placeholder');
					var contador   = document.getElementById('ct-fotos-contador');
					var dt         = new DataTransfer();   // FileList mutable

					function renderPreviews() {
						preview.innerHTML = '';
						var files = dt.files;
						if ( files.length === 0 ) {
							preview.style.display    = 'none';
							placeholder.style.display = 'block';
							contador.textContent      = '';
							return;
						}
						preview.style.display    = 'flex';
						placeholder.style.display = 'none';
						contador.textContent      = files.length + ' foto' + (files.length !== 1 ? 's' : '') + ' <?php echo esc_js( __( 'seleccionada(s) para subir', 'calibratrack' ) ); ?>';

						for (var i = 0; i < files.length; i++) {
							(function (file, idx) {
								var wrap = document.createElement('div');
								wrap.style.cssText = 'position:relative;width:90px;height:90px;border-radius:6px;overflow:hidden;border:2px solid #6366f1;flex-shrink:0;';

								var img  = document.createElement('img');
								img.style.cssText = 'width:100%;height:100%;object-fit:cover;';

								var btn  = document.createElement('button');
								btn.type = 'button';
								btn.innerHTML = '×';
								btn.title = '<?php echo esc_js( __( 'Quitar', 'calibratrack' ) ); ?>';
								btn.style.cssText = 'position:absolute;top:2px;right:2px;background:rgba(0,0,0,.55);color:#fff;border:none;border-radius:50%;width:22px;height:22px;font-size:14px;line-height:1;cursor:pointer;';
								btn.addEventListener('click', function () {
									var newDt = new DataTransfer();
									var all   = dt.files;
									for (var j = 0; j < all.length; j++) {
										if (j !== idx) { newDt.items.add(all[j]); }
									}
									dt = newDt;
									inputFotos.files = dt.files;
									renderPreviews();
								});

								var reader = new FileReader();
								reader.onload = function (e) { img.src = e.target.result; };
								reader.readAsDataURL(file);

								wrap.appendChild(img);
								wrap.appendChild(btn);
								preview.appendChild(wrap);
							})(files[i], i);
						}
					}

					var maxFotos = inputFotos ? parseInt( inputFotos.getAttribute('data-max-fotos') || '6', 10 ) : 6;

					function addFiles(fileList) {
						for (var i = 0; i < fileList.length; i++) {
							if ( dt.files.length >= maxFotos ) {
								alert( '<?php echo esc_js( __( 'Límite alcanzado: máximo 6 fotos en total.', 'calibratrack' ) ); ?>' );
								break;
							}
							dt.items.add(fileList[i]);
						}
						inputFotos.files = dt.files;
						renderPreviews();
					}

					// Click en la zona → abrir selector.
					zona.addEventListener('click', function (e) {
						if (e.target === inputFotos || e.target.tagName === 'BUTTON') { return; }
						inputFotos.click();
					});

					// Cambio en el input.
					inputFotos.addEventListener('change', function () {
						addFiles(this.files);
						// Reset para poder volver a seleccionar los mismos archivos.
						this.value = '';
					});

					// Drag & drop.
					zona.addEventListener('dragover', function (e) {
						e.preventDefault();
						this.style.borderColor = '#6366f1';
						this.style.background  = '#eef2ff';
					});
					zona.addEventListener('dragleave', function () {
						this.style.borderColor = '#cbd5e1';
						this.style.background  = '#f8fafc';
					});
					zona.addEventListener('drop', function (e) {
						e.preventDefault();
						this.style.borderColor = '#cbd5e1';
						this.style.background  = '#f8fafc';
						if (e.dataTransfer && e.dataTransfer.files.length) {
							addFiles(e.dataTransfer.files);
						}
					});

					/* ── Slots de documentos ── */
					window.ctDocSlotChange = function (input) {
						var span = input.parentElement.querySelector('.ct-doc-nombre');
						if (input.files.length && span) {
							span.textContent = input.files[0].name;
							span.style.display = 'inline';
						}
					};

					var addDocBtn  = document.getElementById('ct-add-doc-slot');
					var slotsWrap  = document.getElementById('ct-slots-docs');
					var docCount   = 2;

					if (addDocBtn && slotsWrap) {
						addDocBtn.addEventListener('click', function () {
							docCount++;
							var div = document.createElement('div');
							div.className = 'ct-doc-slot';
							div.style.cssText = 'display:flex;align-items:center;gap:10px;background:#f8fafc;border:1px solid #e5e7eb;border-radius:8px;padding:10px 14px;';
							div.innerHTML =
								'<svg width="18" height="18" fill="#94a3b8" viewBox="0 0 24 24" style="flex-shrink:0;"><path d="M7 3a2 2 0 00-2 2v14a2 2 0 002 2h10a2 2 0 002-2V8l-5-5H7zm5 1l4 4h-4V4z"/></svg>' +
								'<input type="file" name="documentos_adjuntos[]" accept="application/pdf,.pdf" style="flex:1;font-size:13px;color:#374151;" onchange="ctDocSlotChange(this)">' +
								'<span class="ct-doc-nombre" style="font-size:12px;color:#6366f1;display:none;"></span>';
							slotsWrap.appendChild(div);
						});
					}

				}());
				</script>

				<!-- Estado del servicio -->
				<div class="ct-field" style="padding:16px;background:#f0fdf4;border:1px solid #86efac;border-radius:8px;">
					<label for="ct-estado-servicio" class="ct-label" style="font-weight:700;color:#14532d;">
						<?php esc_html_e( 'Estado del servicio', 'calibratrack' ); ?>
					</label>
					<p style="font-size:13px;color:#166534;margin:4px 0 12px;">
						<?php esc_html_e( 'Actualiza el estado para informar al administrador sobre el avance de la OT.', 'calibratrack' ); ?>
					</p>
					<select id="ct-estado-servicio" name="estado_servicio" class="ct-select">
						<option value="en_proceso" <?php selected( $estado_actual_form, 'en_proceso' ); ?>>
							<?php esc_html_e( 'En proceso', 'calibratrack' ); ?>
						</option>
						<option value="en_ejecucion" <?php selected( $estado_actual_form, 'en_ejecucion' ); ?>>
							<?php esc_html_e( 'En ejecución', 'calibratrack' ); ?>
						</option>
						<option value="listo_revision" <?php selected( $estado_actual_form, 'listo_revision' ); ?>>
							<?php esc_html_e( 'Listo para revisión', 'calibratrack' ); ?>
						</option>
					</select>
				</div>

				<div class="ct-form-actions">
					<button type="submit" class="ct-btn ct-btn--primary ct-btn--large">
						<?php esc_html_e( 'Guardar cambios', 'calibratrack' ); ?>
					</button>
					<a href="<?php echo esc_url( home_url( '/panel/' ) ); ?>" class="ct-btn">
						<?php esc_html_e( 'Cancelar', 'calibratrack' ); ?>
					</a>
				</div>
			</form>

		<?php
		// Hilo de mensajes para el técnico (también visible cuando la OT está completada,
		// pero el formulario de envío se deshabilitará dentro del partial).
		if ( $evento_id > 0 && class_exists( 'CalibraTrack_Mensajes' ) ) {
			$mensajes      = isset( $mensajes_ot ) ? $mensajes_ot : array();
			// $es_tecnico ya está definida arriba como true en este bloque.
			// $es_completado ya está definida en el ámbito del template.
			include __DIR__ . '/_partials/mensajes.php';
		}
		?>

		<?php else : // Fallback admin (redirigido normalmente a /panel/ot/{id}/) ?>

			<form method="post" action="" enctype="multipart/form-data" class="ct-form" novalidate>
				<?php include __DIR__ . '/_partials/form-evento-fields.php'; ?>

				<?php
				$estado_actual_form = isset( $valores['estado_servicio'] ) ? $valores['estado_servicio'] : 'en_proceso';
				if ( '' === $estado_actual_form ) { $estado_actual_form = 'en_proceso'; }
				?>
				<div class="ct-field" style="margin-top:20px;padding:16px;background:#fffbeb;border:1px solid #fcd34d;border-radius:8px;">
					<label for="ct-estado-servicio" class="ct-label" style="font-weight:700;color:#92400e;">
						<?php esc_html_e( '¿Finalizar este servicio?', 'calibratrack' ); ?>
					</label>
					<select id="ct-estado-servicio" name="estado_servicio" class="ct-select">
						<?php foreach ( CalibraTrack_Helpers::get_estados_servicio() as $slug => $cfg ) : ?>
						<option value="<?php echo esc_attr( $slug ); ?>" <?php selected( $estado_actual_form, $slug ); ?>>
							<?php echo esc_html( $cfg['label'] ); ?>
						</option>
						<?php endforeach; ?>
					</select>
				</div>
				<div class="ct-form-actions">
					<button type="submit" class="ct-btn ct-btn--primary ct-btn--large">
						<?php
						if ( 'completado' === $estado_actual_form ) {
							esc_html_e( 'Guardar y emitir certificado', 'calibratrack' );
						} else {
							esc_html_e( 'Actualizar evento', 'calibratrack' );
						}
						?>
					</button>
				</div>
			</form>

		<?php endif; ?>

	<?php endif; ?>
</div>

<?php include __DIR__ . '/_partials/footer.php'; ?>
