# Task 5: Redesign `evento-detalle.php` — Restricted Technician View

## Context
CalibraTrack WordPress plugin. The `evento-detalle.php` template (131 lines) currently shows a full form for editing event data. When `$es_tecnico = true` (passed from `handle_editar_evento()` since Task 3), it must show a restricted view: a read-only info card + limited form (4 fields + 3-state selector, no pricing).

## File to Modify
`calibratrack/templates/panel/evento-detalle.php`

The current file has this structure:
- Lines 1-12: PHP var setup + header include
- Lines 15-37: container open, page header, success alert
- Lines 38-79: `$es_completado` block (finalized OT — unchanged)
- Lines 80-127: `else` block (editable form — this is what we change)
- Lines 128-131: container close + footer include

## Global Constraints
- PHP 7.4: no `enum`, `match`, `?->`, constructor promotion, union types, named args
- WPCS: `esc_html()`, `esc_attr()`, `esc_url()`, `esc_textarea()` on all output
- i18n: `__()` / `esc_html_e()` with text domain `calibratrack`
- Technician CANNOT see cost items, subtotal, IVA, total
- Technician selector shows only: `en_proceso`, `en_ejecucion`, `listo_revision` (NOT `completado`)

## What to Implement

### Change 1: Add `$es_tecnico` variable (after line 8)

After the line `$es_completado = ! empty( $es_completado );` (line 8), add:
```php
$es_tecnico    = ! empty( $es_tecnico );
```

### Change 2: Replace the entire `else` block (lines 80-127)

Replace lines 80-127 (from `<?php else : ?>` through `<?php endif; ?>`) with the following complete block:

```php
	<?php else : ?>

		<?php if ( $cert_id ) : ?>
			<div class="ct-cert-disponible">
				<span><?php esc_html_e( 'Certificado PDF disponible', 'calibratrack' ); ?></span>
				<a href="<?php echo esc_url( home_url( '/panel/evento/' . $evento_id . '/certificado/' ) ); ?>" target="_blank" class="ct-btn ct-btn--sm">
					<?php esc_html_e( 'Descargar / Ver PDF', 'calibratrack' ); ?>
				</a>
			</div>
		<?php endif; ?>

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
					<label for="ct-fotos" class="ct-label"><?php esc_html_e( 'Evidencia fotográfica', 'calibratrack' ); ?></label>
					<?php
					$fotos_raw = get_post_meta( $evento_id, CalibraTrack_Meta_Keys::EVENTO_EVIDENCIA_FOTOGRAFICA, true );
					$fotos_ids = json_decode( (string) $fotos_raw, true );
					if ( is_array( $fotos_ids ) && ! empty( $fotos_ids ) ) {
						echo '<div style="display:flex;flex-wrap:wrap;gap:8px;margin-bottom:8px;">';
						foreach ( $fotos_ids as $fid ) {
							$thumb = wp_get_attachment_image_src( $fid, 'thumbnail' );
							if ( $thumb ) {
								echo '<img src="' . esc_url( $thumb[0] ) . '" style="width:80px;height:80px;object-fit:cover;border-radius:4px;border:1px solid #e5e7eb;">';
							}
						}
						echo '</div>';
						echo '<p class="ct-field-help">' . esc_html__( 'Fotos ya adjuntas. Los nuevos archivos se agregarán.', 'calibratrack' ) . '</p>';
					}
					?>
					<input type="file" id="ct-fotos" name="evidencia_fotografica[]" class="ct-input-file"
						accept="image/jpeg,image/png,image/webp" multiple>
					<p class="ct-field-help"><?php esc_html_e( 'JPG, PNG o WEBP. Puede seleccionar varias fotos.', 'calibratrack' ); ?></p>
				</div>

				<!-- Documentos adjuntos -->
				<div class="ct-field">
					<label for="ct-documentos" class="ct-label"><?php esc_html_e( 'Documentos adjuntos (PDF)', 'calibratrack' ); ?></label>
					<?php
					$docs_raw = get_post_meta( $evento_id, CalibraTrack_Meta_Keys::EVENTO_DOCUMENTOS_ADJUNTOS, true );
					$docs_ids = json_decode( (string) $docs_raw, true );
					if ( is_array( $docs_ids ) && ! empty( $docs_ids ) ) {
						echo '<ul class="ct-docs-lista" style="margin-bottom:8px;list-style:none;padding:0;">';
						foreach ( $docs_ids as $doc_id ) {
							$doc_title = get_the_title( $doc_id );
							if ( $doc_title ) {
								echo '<li style="font-size:13px;padding:3px 0;">' . esc_html( $doc_title ) . '</li>';
							}
						}
						echo '</ul>';
						echo '<p class="ct-field-help">' . esc_html__( 'Documentos ya adjuntos. Los nuevos archivos se agregarán.', 'calibratrack' ) . '</p>';
					}
					?>
					<input type="file" id="ct-documentos" name="documentos_adjuntos[]" class="ct-input-file"
						accept="application/pdf,.pdf" multiple>
					<p class="ct-field-help"><?php esc_html_e( 'PDF. Protocolos, informes u otros documentos.', 'calibratrack' ); ?></p>
				</div>

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
```

## Verification
Read back the file and confirm:
1. Line 9: `$es_tecnico = ! empty( $es_tecnico );` is present
2. When `$es_tecnico` is true: info card table is present (N° OT, Equipo, Tipo de servicio, etc.), form has `descripcion_trabajo`, `observaciones`, file inputs, and state selector with only 3 options (no `completado`)
3. When `$es_tecnico` is false (admin fallback): shows `form-evento-fields.php` include + state dropdown looping over `get_estados_servicio()`
4. No cost items fields anywhere in the tech form
5. The nonce used in the tech form is `calibratrack_tecnico_evento` (matches what `procesar_guardar_evento()` verifies)
