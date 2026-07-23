<?php
/**
 * Formulario de Orden de Trabajo (OT) — Panel unificado.
 *
 * Variables esperadas:
 *   $evento_id  int         0 = nueva, >0 = editar.
 *   $errors     array       Errores de validación por campo.
 *   $valores    array       Valores a pre-poblar.
 *   $equipos    WP_Post[]   Equipos para el select.
 *   $tecnicos   WP_User[]   Técnicos para el select.
 *   $tipos_ev   array       Tipos de evento slug => label.
 *   $ois        WP_Post[]   OIs para el select de vinculación.
 *   $cert_id    int         (opcional) Attachment ID del certificado PDF.
 *   $ot_pdf_id  int         (opcional) Attachment ID del PDF de OT.
 *   $oi_id_get  int         (opcional) OI pre-seleccionada desde GET.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$evento_id = isset( $evento_id ) ? (int) $evento_id : 0;
$errors    = isset( $errors ) ? $errors : array();
$valores   = isset( $valores ) ? $valores : array();
$equipos   = isset( $equipos ) ? $equipos : array();
$tecnicos  = isset( $tecnicos ) ? $tecnicos : array();
$tipos_ev  = isset( $tipos_ev ) ? $tipos_ev : array();
$ois       = isset( $ois ) ? $ois : array();
$cert_id   = isset( $cert_id ) ? (int) $cert_id : 0;
$ot_pdf_id = isset( $ot_pdf_id ) ? (int) $ot_pdf_id : 0;
$oi_id_get = isset( $oi_id_get ) ? (int) $oi_id_get : 0;

$es_nueva   = ( 0 === $evento_id );
$numero_ot  = isset( $valores['numero_ot'] ) ? $valores['numero_ot'] : '';
$page_title = $es_nueva
	? __( 'Nueva Orden de Trabajo', 'calibratrack' )
	: sprintf( __( 'Editar OT #%s', 'calibratrack' ), $numero_ot );

$v = function( $key, $default = '' ) use ( $valores ) {
	return isset( $valores[ $key ] ) ? $valores[ $key ] : $default;
};
$e = function( $key ) use ( $errors ) {
	return isset( $errors[ $key ] ) ? $errors[ $key ] : '';
};

$estado_actual = $v( 'estado_servicio', 'en_proceso' );
if ( '' === $estado_actual ) {
	$estado_actual = 'en_proceso';
}

$items_actuales = $v( 'items', array() );

$nonce_wc = wp_create_nonce( 'calibratrack_buscar_wc' );

// URL del formulario según si es nueva o edición.
$form_action = $es_nueva
	? home_url( '/panel/nueva-ot/' )
	: home_url( '/panel/ot/' . $evento_id . '/' );

include __DIR__ . '/_partials/header.php';
?>

<div class="ct-container">
	<div class="ct-page-header">
		<h1 class="ct-page-title"><?php echo esc_html( $page_title ); ?></h1>
		<a href="<?php echo esc_url( home_url( '/panel/' ) ); ?>" class="ct-link ct-no-print">
			&larr; <?php esc_html_e( 'Volver al panel', 'calibratrack' ); ?>
		</a>
	</div>

	<?php if ( isset( $_GET['guardado'] ) ) : ?>
		<div class="ct-alert ct-alert--success" role="alert">
			<?php esc_html_e( 'Orden de Trabajo guardada correctamente.', 'calibratrack' ); ?>
			<?php if ( $cert_id ) : ?>
				&mdash;
				<a href="<?php echo esc_url( wp_get_attachment_url( $cert_id ) ); ?>" target="_blank" class="ct-link">
					<?php esc_html_e( 'Ver certificado PDF', 'calibratrack' ); ?>
				</a>
			<?php endif; ?>
		</div>
	<?php endif; ?>

	<?php if ( ! empty( $errors['general'] ) ) : ?>
		<div class="ct-alert ct-alert--error" role="alert">
			<?php echo esc_html( $errors['general'] ); ?>
		</div>
	<?php endif; ?>

	<?php if ( ! $es_nueva && ( $cert_id || $ot_pdf_id ) ) : ?>
	<div class="ct-no-print" style="display:flex;gap:12px;flex-wrap:wrap;margin-bottom:20px;">
		<?php if ( $ot_pdf_id ) : ?>
			<a href="<?php echo esc_url( wp_get_attachment_url( $ot_pdf_id ) ); ?>" target="_blank" class="ct-btn ct-btn--sm">
				<?php esc_html_e( 'Ver OT PDF', 'calibratrack' ); ?>
			</a>
		<?php endif; ?>
		<?php if ( $cert_id ) : ?>
			<a href="<?php echo esc_url( wp_get_attachment_url( $cert_id ) ); ?>" target="_blank" class="ct-btn ct-btn--sm" style="background:#2563eb;border-color:#2563eb;color:#fff;">
				<?php esc_html_e( 'Ver Certificado PDF', 'calibratrack' ); ?>
			</a>
		<?php endif; ?>
	</div>
	<?php endif; ?>

	<form method="post" action="<?php echo esc_url( $form_action ); ?>" enctype="multipart/form-data" class="ct-form" novalidate>
		<?php wp_nonce_field( 'calibratrack_admin_ot' ); ?>

		<!-- OI vinculada (obligatoria) -->
		<div class="ct-field <?php echo $e( 'ingreso_relacionado_id' ) ? 'ct-field--error' : ''; ?>">
			<label for="ct-ingreso-id" class="ct-label">
				<?php esc_html_e( 'OI vinculada *', 'calibratrack' ); ?>
			</label>
			<select id="ct-ingreso-id" name="ingreso_relacionado_id" class="ct-select" required>
				<option value="0"><?php esc_html_e( '— Seleccione una OI —', 'calibratrack' ); ?></option>
				<?php foreach ( $ois as $oi ) :
					$oi_numero    = (string) get_post_meta( $oi->ID, CalibraTrack_Meta_Keys::EVENTO_NUMERO_OI, true );
					$oi_fecha     = (string) get_post_meta( $oi->ID, CalibraTrack_Meta_Keys::EVENTO_FECHA_EJECUCION, true );
					$oi_label     = $oi_numero ? $oi_numero : 'OI-' . $oi_fecha;
					$selected_val = (int) $v( 'ingreso_relacionado_id', $oi_id_get );
				?>
				<option value="<?php echo esc_attr( $oi->ID ); ?>" <?php selected( $selected_val, $oi->ID ); ?>>
					<?php echo esc_html( $oi_label ); ?>
				</option>
				<?php endforeach; ?>
			</select>
			<?php if ( $e( 'ingreso_relacionado_id' ) ) : ?>
				<p class="ct-field-error"><?php echo esc_html( $e( 'ingreso_relacionado_id' ) ); ?></p>
			<?php endif; ?>
		</div>

		<!-- Equipo -->
		<div class="ct-field <?php echo $e( 'equipo_id' ) ? 'ct-field--error' : ''; ?>">
			<label for="ct-equipo-id" class="ct-label">
				<?php esc_html_e( 'Equipo *', 'calibratrack' ); ?>
			</label>
			<select id="ct-equipo-id" name="equipo_id" class="ct-select" required>
				<option value="0"><?php esc_html_e( '— Seleccionar equipo —', 'calibratrack' ); ?></option>
				<?php foreach ( $equipos as $eq ) :
					$serie_eq  = get_post_meta( $eq->ID, CalibraTrack_Meta_Keys::EQUIPO_SERIE, true );
					$marca_eq  = get_post_meta( $eq->ID, CalibraTrack_Meta_Keys::EQUIPO_MARCA, true );
					$modelo_eq = get_post_meta( $eq->ID, CalibraTrack_Meta_Keys::EQUIPO_MODELO, true );
				?>
				<option value="<?php echo esc_attr( $eq->ID ); ?>" <?php selected( $v( 'equipo_id' ), $eq->ID ); ?>>
					<?php echo esc_html( $serie_eq . ' — ' . $marca_eq . ' ' . $modelo_eq ); ?>
				</option>
				<?php endforeach; ?>
			</select>
			<?php if ( $e( 'equipo_id' ) ) : ?>
				<span class="ct-field-error"><?php echo esc_html( $e( 'equipo_id' ) ); ?></span>
			<?php endif; ?>
		</div>

		<!-- Técnico responsable -->
		<div class="ct-field <?php echo $e( 'tecnico_id' ) ? 'ct-field--error' : ''; ?>">
			<label for="ct-tecnico-id" class="ct-label">
				<?php esc_html_e( 'Técnico responsable *', 'calibratrack' ); ?>
			</label>
			<select id="ct-tecnico-id" name="tecnico_id" class="ct-select" required>
				<option value="0"><?php esc_html_e( '— Seleccionar técnico —', 'calibratrack' ); ?></option>
				<?php foreach ( $tecnicos as $tec ) : ?>
				<option value="<?php echo esc_attr( $tec->ID ); ?>" <?php selected( $v( 'tecnico_id' ), $tec->ID ); ?>>
					<?php echo esc_html( $tec->display_name ); ?>
				</option>
				<?php endforeach; ?>
			</select>
			<?php if ( $e( 'tecnico_id' ) ) : ?>
				<span class="ct-field-error"><?php echo esc_html( $e( 'tecnico_id' ) ); ?></span>
			<?php endif; ?>
		</div>

		<!-- N° OT y Tipo de servicio -->
		<div class="ct-field-group">
			<div class="ct-field <?php echo $e( 'numero_ot' ) ? 'ct-field--error' : ''; ?>">
				<label for="ct-numero-ot" class="ct-label">
					<?php esc_html_e( 'N° de Orden de Trabajo *', 'calibratrack' ); ?>
				</label>
				<input type="text" id="ct-numero-ot" name="numero_ot" class="ct-input"
					value="<?php echo esc_attr( $v( 'numero_ot' ) ); ?>" required>
				<?php if ( $e( 'numero_ot' ) ) : ?>
					<span class="ct-field-error"><?php echo esc_html( $e( 'numero_ot' ) ); ?></span>
				<?php endif; ?>
			</div>

			<div class="ct-field <?php echo $e( 'tipo' ) ? 'ct-field--error' : ''; ?>">
				<label for="ct-tipo" class="ct-label">
					<?php esc_html_e( 'Tipo de servicio *', 'calibratrack' ); ?>
				</label>
				<select id="ct-tipo" name="tipo" class="ct-select" required>
					<option value=""><?php esc_html_e( '— Seleccionar —', 'calibratrack' ); ?></option>
					<?php foreach ( $tipos_ev as $slug => $label ) : ?>
					<option value="<?php echo esc_attr( $slug ); ?>" <?php selected( $v( 'tipo' ), $slug ); ?>>
						<?php echo esc_html( $label ); ?>
					</option>
					<?php endforeach; ?>
				</select>
				<?php if ( $e( 'tipo' ) ) : ?>
					<span class="ct-field-error"><?php echo esc_html( $e( 'tipo' ) ); ?></span>
				<?php endif; ?>
			</div>
		</div>

		<!-- Fechas -->
		<div class="ct-field-group">
			<div class="ct-field <?php echo $e( 'fecha_ejecucion' ) ? 'ct-field--error' : ''; ?>">
				<label for="ct-fecha-ejecucion" class="ct-label">
					<?php esc_html_e( 'Fecha de ejecución *', 'calibratrack' ); ?>
				</label>
				<input type="date" id="ct-fecha-ejecucion" name="fecha_ejecucion" class="ct-input"
					value="<?php echo esc_attr( $v( 'fecha_ejecucion' ) ); ?>" required>
				<?php if ( $e( 'fecha_ejecucion' ) ) : ?>
					<span class="ct-field-error"><?php echo esc_html( $e( 'fecha_ejecucion' ) ); ?></span>
				<?php endif; ?>
			</div>

			<div class="ct-field <?php echo $e( 'proxima_fecha' ) ? 'ct-field--error' : ''; ?>">
				<label for="ct-proxima-fecha" class="ct-label">
					<?php esc_html_e( 'Próxima fecha de control', 'calibratrack' ); ?>
				</label>
				<input type="date" id="ct-proxima-fecha" name="proxima_fecha" class="ct-input"
					value="<?php echo esc_attr( $v( 'proxima_fecha' ) ); ?>">
				<?php if ( $e( 'proxima_fecha' ) ) : ?>
					<span class="ct-field-error"><?php echo esc_html( $e( 'proxima_fecha' ) ); ?></span>
				<?php endif; ?>
			</div>
		</div>

		<!-- Falla reportada -->
		<div class="ct-field">
			<label for="ct-falla" class="ct-label">
				<?php esc_html_e( 'Falla reportada por el cliente', 'calibratrack' ); ?>
			</label>
			<textarea id="ct-falla" name="falla_reportada" class="ct-textarea" rows="3"><?php echo esc_textarea( $v( 'falla_reportada' ) ); ?></textarea>
		</div>

		<!-- Descripción del trabajo -->
		<div class="ct-field">
			<label for="ct-descripcion" class="ct-label">
				<?php esc_html_e( 'Descripción del trabajo / servicio realizado', 'calibratrack' ); ?>
			</label>
			<textarea id="ct-descripcion" name="descripcion_trabajo" class="ct-textarea" rows="5"><?php echo esc_textarea( $v( 'descripcion_trabajo' ) ); ?></textarea>
		</div>

		<!-- Observaciones -->
		<div class="ct-field">
			<label for="ct-observaciones" class="ct-label">
				<?php esc_html_e( 'Observaciones', 'calibratrack' ); ?>
			</label>
			<textarea id="ct-observaciones" name="observaciones" class="ct-textarea" rows="3"><?php echo esc_textarea( $v( 'observaciones' ) ); ?></textarea>
		</div>

		<!-- Ítems de costo -->
		<div class="ct-field">
			<label class="ct-label"><?php esc_html_e( 'Ítems de costo', 'calibratrack' ); ?></label>

			<div class="ct-items-wrap">
				<table class="ct-items-table" id="ct-items-tabla">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Detalle', 'calibratrack' ); ?></th>
							<th style="width:90px;"><?php esc_html_e( 'Cantidad', 'calibratrack' ); ?></th>
							<th style="width:120px;"><?php esc_html_e( 'Precio unitario', 'calibratrack' ); ?></th>
							<th style="width:100px;"><?php esc_html_e( 'Subtotal', 'calibratrack' ); ?></th>
							<th style="width:40px;"></th>
						</tr>
					</thead>
					<tbody id="ct-items-tbody">
						<?php if ( ! empty( $items_actuales ) ) :
							foreach ( $items_actuales as $idx => $item ) :
								$det = isset( $item['detalle'] ) ? $item['detalle'] : '';
								$qty = isset( $item['cantidad'] ) ? (float) $item['cantidad'] : 1;
								$prc = isset( $item['precio_unitario'] ) ? (float) $item['precio_unitario'] : 0;
								$sub = $qty * $prc;
						?>
						<tr class="ct-item-fila">
							<td>
								<input type="text"
									name="calibratrack_items[<?php echo (int) $idx; ?>][detalle]"
									class="ct-input ct-item-detalle-input"
									value="<?php echo esc_attr( $det ); ?>">
							</td>
							<td>
								<input type="number" min="0" step="0.01"
									name="calibratrack_items[<?php echo (int) $idx; ?>][cantidad]"
									class="ct-input ct-input--sm ct-item-cantidad"
									value="<?php echo esc_attr( number_format( $qty, 2, '.', '' ) ); ?>">
							</td>
							<td>
								<input type="number" min="0" step="0.01"
									name="calibratrack_items[<?php echo (int) $idx; ?>][precio_unitario]"
									class="ct-input ct-input--sm ct-item-precio"
									value="<?php echo esc_attr( number_format( $prc, 2, '.', '' ) ); ?>">
							</td>
							<td class="ct-item-subtotal" style="font-size:13px;font-weight:600;">
								<?php echo esc_html( number_format( $sub, 0, ',', '.' ) ); ?>
							</td>
							<td>
								<button type="button" class="ct-btn ct-btn--sm ct-btn--danger ct-item-eliminar" title="<?php esc_attr_e( 'Eliminar', 'calibratrack' ); ?>">
									&times;
								</button>
							</td>
						</tr>
						<?php endforeach; else : ?>
						<tr class="ct-item-fila">
							<td>
								<input type="text"
									name="calibratrack_items[0][detalle]"
									class="ct-input ct-item-detalle-input"
									placeholder="<?php esc_attr_e( 'Descripción del ítem', 'calibratrack' ); ?>">
							</td>
							<td>
								<input type="number" min="0" step="0.01"
									name="calibratrack_items[0][cantidad]"
									class="ct-input ct-input--sm ct-item-cantidad"
									value="1">
							</td>
							<td>
								<input type="number" min="0" step="0.01"
									name="calibratrack_items[0][precio_unitario]"
									class="ct-input ct-input--sm ct-item-precio"
									value="0">
							</td>
							<td class="ct-item-subtotal" style="font-size:13px;font-weight:600;">0</td>
							<td>
								<button type="button" class="ct-btn ct-btn--sm ct-btn--danger ct-item-eliminar" title="<?php esc_attr_e( 'Eliminar', 'calibratrack' ); ?>">
									&times;
								</button>
							</td>
						</tr>
						<?php endif; ?>
					</tbody>
				</table>
				<div class="ct-items-footer">
					<button type="button" id="ct-agregar-item" class="ct-btn ct-btn--sm">
						<?php esc_html_e( '+ Agregar ítem', 'calibratrack' ); ?>
					</button>
					<div class="ct-totales" id="ct-totales-preview">
						<span><?php esc_html_e( 'Subtotal:', 'calibratrack' ); ?> <strong id="ct-subtotal">$0</strong></span>
						<span><?php esc_html_e( 'IVA 19%:', 'calibratrack' ); ?> <strong id="ct-iva">$0</strong></span>
						<span style="font-size:14px;font-weight:700;"><?php esc_html_e( 'Total:', 'calibratrack' ); ?> <strong id="ct-total">$0</strong></span>
					</div>
				</div>
			</div>
			<p class="ct-field-help">
				<?php esc_html_e( 'Los totales son una vista previa. El cálculo final se realiza en el servidor al guardar.', 'calibratrack' ); ?>
			</p>
		</div>

		<!-- Estado del servicio -->
		<div class="ct-field" style="padding:16px;background:#fffbeb;border:1px solid #fcd34d;border-radius:8px;">
			<label for="ct-estado-servicio" class="ct-label" style="font-weight:700;color:#92400e;">
				<?php esc_html_e( 'Estado del servicio', 'calibratrack' ); ?>
			</label>
			<p style="font-size:13px;color:#78350f;margin:4px 0 12px;">
				<?php esc_html_e( 'Al marcar como "Completado" se generará el certificado PDF y se enviará al cliente automáticamente.', 'calibratrack' ); ?>
			</p>
			<select id="ct-estado-servicio" name="estado_servicio" class="ct-select">
				<option value="en_proceso" <?php selected( $estado_actual, 'en_proceso' ); ?>>
					<?php esc_html_e( 'En proceso', 'calibratrack' ); ?>
				</option>
				<option value="en_ejecucion" <?php selected( $estado_actual, 'en_ejecucion' ); ?>>
					<?php esc_html_e( 'En ejecución', 'calibratrack' ); ?>
				</option>
				<option value="listo_revision" <?php selected( $estado_actual, 'listo_revision' ); ?>>
					<?php esc_html_e( 'Listo para revisión', 'calibratrack' ); ?>
				</option>
				<option value="completado" <?php selected( $estado_actual, 'completado' ); ?>>
					<?php esc_html_e( 'Completado — Emitir certificado', 'calibratrack' ); ?>
				</option>
			</select>
		</div>

		<!-- Evidencia fotográfica (máx. 6) -->
		<div class="ct-field" style="margin-top:18px;">
			<label class="ct-label"><?php esc_html_e( 'Evidencia fotográfica', 'calibratrack' ); ?></label>
			<?php
			$fotos_existentes_count = 0;
			if ( $evento_id > 0 ) {
				$fotos_raw = get_post_meta( $evento_id, CalibraTrack_Meta_Keys::EVENTO_EVIDENCIA_FOTOGRAFICA, true );
				$fotos_ids = json_decode( (string) $fotos_raw, true );
				if ( is_array( $fotos_ids ) && ! empty( $fotos_ids ) ) {
					$fotos_existentes_count = count( $fotos_ids );
					echo '<p class="ct-field-help" style="margin-bottom:8px;">' . esc_html__( 'Fotos ya guardadas:', 'calibratrack' ) . '</p>';
					echo '<div style="display:flex;flex-wrap:wrap;gap:8px;margin-bottom:16px;">';
					foreach ( $fotos_ids as $fid ) {
						$thumb    = wp_get_attachment_image_src( $fid, 'medium' );
						$full_url = wp_get_attachment_url( $fid );
						if ( ! $thumb ) { continue; }
						echo '<a href="' . esc_url( $full_url ) . '" target="_blank"
							style="display:block;width:90px;height:90px;border-radius:6px;overflow:hidden;border:2px solid #e5e7eb;flex-shrink:0;">
							<img src="' . esc_url( $thumb[0] ) . '" style="width:100%;height:100%;object-fit:cover;" alt="">
						</a>';
					}
					echo '</div>';
				}
			}
			$fotos_disponibles = max( 0, 6 - $fotos_existentes_count );
			?>
			<?php if ( $fotos_disponibles > 0 ) : ?>
			<div id="ct-zona-fotos-admin"
				style="border:2px dashed #cbd5e1;border-radius:10px;padding:20px;text-align:center;cursor:pointer;background:#f8fafc;transition:border-color .2s;"
				onmouseover="this.style.borderColor='#6366f1'" onmouseout="this.style.borderColor='#cbd5e1'">
				<input type="file" id="ct-fotos-admin" name="evidencia_fotografica[]"
					accept="image/jpeg,image/png,image/webp" multiple style="display:none;"
					data-max-fotos="<?php echo (int) $fotos_disponibles; ?>">
				<div id="ct-fotos-admin-placeholder">
					<svg width="36" height="36" fill="none" stroke="#94a3b8" stroke-width="1.5" viewBox="0 0 24 24" style="margin:0 auto 8px;display:block;">
						<path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75V16.5m-13.5-9L12 3m0 0l4.5 4.5M12 3v13.5"/>
					</svg>
					<p style="margin:0;font-size:14px;color:#64748b;"><?php esc_html_e( 'Arrastra fotos aquí o', 'calibratrack' ); ?>
						<span style="color:#6366f1;font-weight:600;"><?php esc_html_e( 'haz clic para seleccionar', 'calibratrack' ); ?></span>
					</p>
					<p style="margin:6px 0 0;font-size:12px;color:#94a3b8;">
						<?php printf( esc_html__( 'JPG, PNG o WEBP · hasta %d foto(s) más · máx. 6 en total', 'calibratrack' ), (int) $fotos_disponibles ); ?>
					</p>
				</div>
				<div id="ct-fotos-admin-preview" style="display:none;flex-wrap:wrap;gap:8px;justify-content:center;"></div>
			</div>
			<p style="margin-top:8px;font-size:12px;color:#64748b;" id="ct-fotos-admin-contador"></p>
			<?php else : ?>
			<p style="font-size:13px;color:#dc2626;margin-top:4px;"><?php esc_html_e( 'Se alcanzó el límite de 6 fotos.', 'calibratrack' ); ?></p>
			<?php endif; ?>
		</div>

		<!-- Documentos adjuntos (máx. 2) -->
		<div class="ct-field">
			<label class="ct-label"><?php esc_html_e( 'Documentos adjuntos (PDF)', 'calibratrack' ); ?></label>
			<?php
			$docs_existentes_count = 0;
			if ( $evento_id > 0 ) {
				$docs_raw = get_post_meta( $evento_id, CalibraTrack_Meta_Keys::EVENTO_DOCUMENTOS_ADJUNTOS, true );
				$docs_ids = json_decode( (string) $docs_raw, true );
				if ( is_array( $docs_ids ) && ! empty( $docs_ids ) ) {
					$docs_existentes_count = count( $docs_ids );
					echo '<p class="ct-field-help" style="margin-bottom:8px;">' . esc_html__( 'Documentos ya guardados:', 'calibratrack' ) . '</p>';
					echo '<ul style="list-style:none;padding:0;margin:0 0 16px;">';
					foreach ( $docs_ids as $doc_id ) {
						$doc_url   = wp_get_attachment_url( $doc_id );
						$doc_title = get_the_title( $doc_id );
						if ( ! $doc_title ) { continue; }
						echo '<li style="display:flex;align-items:center;gap:8px;padding:6px 10px;background:#f8fafc;border:1px solid #e5e7eb;border-radius:6px;margin-bottom:6px;font-size:13px;">
							<svg width="16" height="16" fill="#ef4444" viewBox="0 0 24 24"><path d="M7 3a2 2 0 00-2 2v14a2 2 0 002 2h10a2 2 0 002-2V8l-5-5H7zm5 1l4 4h-4V4z"/></svg>
							<a href="' . esc_url( $doc_url ) . '" target="_blank" style="color:#374151;text-decoration:none;flex:1;">' . esc_html( $doc_title ) . '</a>
						</li>';
					}
					echo '</ul>';
				}
			}
			$docs_disponibles = max( 0, 2 - $docs_existentes_count );
			?>
			<?php if ( $docs_disponibles > 0 ) : ?>
			<div id="ct-slots-docs-admin" style="display:flex;flex-direction:column;gap:10px;">
				<?php for ( $slot = 1; $slot <= $docs_disponibles; $slot++ ) : ?>
				<div style="display:flex;align-items:center;gap:10px;background:#f8fafc;border:1px solid #e5e7eb;border-radius:8px;padding:10px 14px;">
					<svg width="18" height="18" fill="#94a3b8" viewBox="0 0 24 24" style="flex-shrink:0;"><path d="M7 3a2 2 0 00-2 2v14a2 2 0 002 2h10a2 2 0 002-2V8l-5-5H7zm5 1l4 4h-4V4z"/></svg>
					<input type="file" name="documentos_adjuntos[]" accept="application/pdf,.pdf"
						style="flex:1;font-size:13px;color:#374151;" onchange="ctAdminDocChange(this)">
					<span class="ct-doc-nombre" style="font-size:12px;color:#6366f1;display:none;"></span>
				</div>
				<?php endfor; ?>
			</div>
			<p style="margin-top:6px;font-size:12px;color:#94a3b8;">
				<?php printf( esc_html__( 'PDF · Puedes subir hasta %d documento(s) más · máx. 2 en total', 'calibratrack' ), (int) $docs_disponibles ); ?>
			</p>
			<?php else : ?>
			<p style="font-size:13px;color:#dc2626;margin-top:4px;"><?php esc_html_e( 'Se alcanzó el límite de 2 documentos.', 'calibratrack' ); ?></p>
			<?php endif; ?>
		</div>

		<div class="ct-form-actions">
			<button type="submit" class="ct-btn ct-btn--primary ct-btn--large">
				<?php
				if ( 'completado' === $estado_actual ) {
					esc_html_e( 'Guardar y emitir certificado', 'calibratrack' );
				} elseif ( $es_nueva ) {
					esc_html_e( 'Crear Orden de Trabajo', 'calibratrack' );
				} else {
					esc_html_e( 'Actualizar OT', 'calibratrack' );
				}
				?>
			</button>
			<a href="<?php echo esc_url( home_url( '/panel/' ) ); ?>" class="ct-btn">
				<?php esc_html_e( 'Cancelar', 'calibratrack' ); ?>
			</a>
		</div>
	</form>

</div>

<script>
(function() {
	'use strict';

	/* ── Ítems de costo ── */
	(function () {
		var tbody      = document.getElementById( 'ct-items-tbody' );
		var btnAgregar = document.getElementById( 'ct-agregar-item' );
		var itemIndex  = <?php echo (int) ( ! empty( $items_actuales ) ? count( $items_actuales ) : 1 ); ?>;

		function formatPesos( val ) {
			val = parseFloat( val ) || 0;
			return '$' + Math.round( val ).toLocaleString( 'es-CL' );
		}

		function recalcularTotales() {
			var subtotal = 0;
			var filas = tbody ? tbody.querySelectorAll( '.ct-item-fila' ) : [];
			for ( var i = 0; i < filas.length; i++ ) {
				var qty = parseFloat( filas[i].querySelector( '.ct-item-cantidad' ).value ) || 0;
				var prc = parseFloat( filas[i].querySelector( '.ct-item-precio' ).value ) || 0;
				var sub = qty * prc;
				var subEl = filas[i].querySelector( '.ct-item-subtotal' );
				if ( subEl ) { subEl.textContent = formatPesos( sub ); }
				subtotal += sub;
			}
			var iva   = subtotal * 0.19;
			var total = subtotal + iva;
			var elSub   = document.getElementById( 'ct-subtotal' );
			var elIva   = document.getElementById( 'ct-iva' );
			var elTotal = document.getElementById( 'ct-total' );
			if ( elSub )   { elSub.textContent   = formatPesos( subtotal ); }
			if ( elIva )   { elIva.textContent   = formatPesos( iva ); }
			if ( elTotal ) { elTotal.textContent = formatPesos( total ); }
		}

		function vincularEventosFila( fila ) {
			fila.querySelector( '.ct-item-cantidad' ).addEventListener( 'input', recalcularTotales );
			fila.querySelector( '.ct-item-precio' ).addEventListener( 'input', recalcularTotales );
			fila.querySelector( '.ct-item-eliminar' ).addEventListener( 'click', function () {
				fila.parentNode.removeChild( fila );
				recalcularTotales();
			} );
		}

		function agregarFilaItem( detalle, precio ) {
			detalle = detalle || '';
			precio  = precio  || 0;
			var idx = itemIndex++;
			var tr  = document.createElement( 'tr' );
			tr.className = 'ct-item-fila';
			tr.innerHTML =
				'<td><input type="text" name="calibratrack_items[' + idx + '][detalle]" class="ct-input ct-item-detalle-input" placeholder="<?php echo esc_js( __( 'Descripción del ítem', 'calibratrack' ) ); ?>" value="' + detalle.replace( /"/g, '&quot;' ) + '"></td>' +
				'<td><input type="number" min="0" step="0.01" name="calibratrack_items[' + idx + '][cantidad]" class="ct-input ct-input--sm ct-item-cantidad" value="1"></td>' +
				'<td><input type="number" min="0" step="0.01" name="calibratrack_items[' + idx + '][precio_unitario]" class="ct-input ct-input--sm ct-item-precio" value="' + precio + '"></td>' +
				'<td class="ct-item-subtotal" style="font-size:13px;font-weight:600;">' + formatPesos( precio ) + '</td>' +
				'<td><button type="button" class="ct-btn ct-btn--sm ct-btn--danger ct-item-eliminar" title="<?php echo esc_js( __( 'Eliminar', 'calibratrack' ) ); ?>">&times;</button></td>';
			tbody.appendChild( tr );
			vincularEventosFila( tr );
			recalcularTotales();
		}

		if ( tbody ) {
			tbody.querySelectorAll( '.ct-item-fila' ).forEach( vincularEventosFila );
		}
		recalcularTotales();

		if ( btnAgregar ) {
			btnAgregar.addEventListener( 'click', function () { agregarFilaItem( '', 0 ); } );
		}
	}());

	/* ── Zona de fotos admin (drag & drop) ── */
	(function () {
		var zona      = document.getElementById( 'ct-zona-fotos-admin' );
		var input     = document.getElementById( 'ct-fotos-admin' );
		var preview   = document.getElementById( 'ct-fotos-admin-preview' );
		var holder    = document.getElementById( 'ct-fotos-admin-placeholder' );
		var contador  = document.getElementById( 'ct-fotos-admin-contador' );
		if ( ! zona || ! input ) { return; }

		var maxFotos = parseInt( input.getAttribute( 'data-max-fotos' ) || '6', 10 );
		var dt = new DataTransfer();

		function renderPreviews() {
			preview.innerHTML = '';
			if ( dt.files.length === 0 ) {
				preview.style.display = 'none';
				holder.style.display  = 'block';
				contador.textContent  = '';
				return;
			}
			preview.style.display = 'flex';
			holder.style.display  = 'none';
			contador.textContent  = dt.files.length + ' foto' + ( dt.files.length !== 1 ? 's' : '' ) + ' <?php echo esc_js( __( 'seleccionada(s) para subir', 'calibratrack' ) ); ?>';
			for ( var i = 0; i < dt.files.length; i++ ) {
				(function ( file, idx ) {
					var wrap = document.createElement( 'div' );
					wrap.style.cssText = 'position:relative;width:90px;height:90px;border-radius:6px;overflow:hidden;border:2px solid #6366f1;flex-shrink:0;';
					var img = document.createElement( 'img' );
					img.style.cssText = 'width:100%;height:100%;object-fit:cover;';
					var btn = document.createElement( 'button' );
					btn.type = 'button'; btn.innerHTML = '×';
					btn.style.cssText = 'position:absolute;top:2px;right:2px;background:rgba(0,0,0,.55);color:#fff;border:none;border-radius:50%;width:22px;height:22px;font-size:14px;line-height:1;cursor:pointer;';
					btn.addEventListener( 'click', function () {
						var nd = new DataTransfer();
						for ( var j = 0; j < dt.files.length; j++ ) { if ( j !== idx ) nd.items.add( dt.files[j] ); }
						dt = nd; input.files = dt.files; renderPreviews();
					} );
					var reader = new FileReader();
					reader.onload = function (e) { img.src = e.target.result; };
					reader.readAsDataURL( file );
					wrap.appendChild( img ); wrap.appendChild( btn ); preview.appendChild( wrap );
				})( dt.files[i], i );
			}
		}

		function addFiles( list ) {
			for ( var i = 0; i < list.length; i++ ) {
				if ( dt.files.length >= maxFotos ) {
					alert( '<?php echo esc_js( __( 'Límite alcanzado: máximo 6 fotos en total.', 'calibratrack' ) ); ?>' );
					break;
				}
				dt.items.add( list[i] );
			}
			input.files = dt.files;
			renderPreviews();
		}

		zona.addEventListener( 'click', function (e) {
			if ( e.target === input || e.target.tagName === 'BUTTON' ) { return; }
			input.click();
		} );
		input.addEventListener( 'change', function () { addFiles( this.files ); this.value = ''; } );
		zona.addEventListener( 'dragover', function (e) { e.preventDefault(); this.style.borderColor = '#6366f1'; this.style.background = '#eef2ff'; } );
		zona.addEventListener( 'dragleave', function () { this.style.borderColor = '#cbd5e1'; this.style.background = '#f8fafc'; } );
		zona.addEventListener( 'drop', function (e) {
			e.preventDefault(); this.style.borderColor = '#cbd5e1'; this.style.background = '#f8fafc';
			if ( e.dataTransfer && e.dataTransfer.files.length ) { addFiles( e.dataTransfer.files ); }
		} );
	}());

	/* ── Nombre en slot de documentos admin ── */
	window.ctAdminDocChange = function ( input ) {
		var span = input.parentElement.querySelector( '.ct-doc-nombre' );
		if ( input.files.length && span ) { span.textContent = input.files[0].name; span.style.display = 'inline'; }
	};

})();
</script>

<?php
// Incluir hilo de mensajes solo en OTs existentes (no en la creación nueva).
if ( $evento_id > 0 ) {
	$mensajes      = isset( $mensajes_ot ) ? $mensajes_ot : array();
	$es_tecnico    = false; // En este template el usuario siempre es admin.
	$es_completado = ( 'completado' === $estado_actual );
	include __DIR__ . '/_partials/mensajes.php';
}
?>
<?php if ( ! $es_nueva && isset( $_GET['imprimir'] ) && '1' === $_GET['imprimir'] ) : ?>
<script>window.addEventListener('load', function(){ window.print(); });</script>
<?php endif; ?>

<?php if ( ! $es_nueva ) :
	// ── Preparar datos para la vista de impresión ──────────────────────────

	// OI vinculada
	$print_oi_label  = '';
	$oi_id_vinculada = (int) $v( 'ingreso_relacionado_id' );
	foreach ( $ois as $oi_p ) {
		if ( (int) $oi_p->ID === $oi_id_vinculada ) {
			$oi_num_p       = (string) get_post_meta( $oi_p->ID, CalibraTrack_Meta_Keys::EVENTO_NUMERO_OI, true );
			$print_oi_label = $oi_num_p ? $oi_num_p : 'OI-' . $oi_p->ID;
			break;
		}
	}

	// Equipo
	$print_equipo_ot = '';
	foreach ( $equipos as $eq ) {
		if ( (int) $eq->ID === (int) $v( 'equipo_id' ) ) {
			$s              = (string) get_post_meta( $eq->ID, CalibraTrack_Meta_Keys::EQUIPO_SERIE,  true );
			$m              = (string) get_post_meta( $eq->ID, CalibraTrack_Meta_Keys::EQUIPO_MARCA,  true );
			$mo             = (string) get_post_meta( $eq->ID, CalibraTrack_Meta_Keys::EQUIPO_MODELO, true );
			$print_equipo_ot = trim( $s . ' — ' . $m . ' ' . $mo );
			break;
		}
	}

	// Técnico
	$print_tecnico_ot = '';
	foreach ( $tecnicos as $tec ) {
		if ( (int) $tec->ID === (int) $v( 'tecnico_id' ) ) {
			$print_tecnico_ot = $tec->display_name;
			break;
		}
	}

	// Tipo
	$print_tipo_ot = isset( $tipos_ev[ $v( 'tipo' ) ] ) ? $tipos_ev[ $v( 'tipo' ) ] : $v( 'tipo' );

	// Estado
	$estados_labels = array(
		'en_proceso'     => __( 'En proceso',           'calibratrack' ),
		'en_ejecucion'   => __( 'En ejecución',         'calibratrack' ),
		'listo_revision' => __( 'Listo para revisión',  'calibratrack' ),
		'completado'     => __( 'Completado',            'calibratrack' ),
	);
	$print_estado_ot = isset( $estados_labels[ $estado_actual ] ) ? $estados_labels[ $estado_actual ] : $estado_actual;

	// Fechas
	$print_fecha_ej = $v( 'fecha_ejecucion' );
	if ( $print_fecha_ej ) {
		$dt = date_create( $print_fecha_ej );
		if ( $dt ) {
			$print_fecha_ej = date_format( $dt, 'd/m/Y' );
		}
	}
	$print_fecha_prox = $v( 'proxima_fecha' );
	if ( $print_fecha_prox ) {
		$dt = date_create( $print_fecha_prox );
		if ( $dt ) {
			$print_fecha_prox = date_format( $dt, 'd/m/Y' );
		}
	}

	// Totales
	$print_subtotal = 0;
	foreach ( $items_actuales as $item ) {
		$qty             = isset( $item['cantidad'] )        ? (float) $item['cantidad']        : 0;
		$prc             = isset( $item['precio_unitario'] ) ? (float) $item['precio_unitario'] : 0;
		$print_subtotal += $qty * $prc;
	}
	$print_iva   = $print_subtotal * 0.19;
	$print_total = $print_subtotal + $print_iva;

	$print_fecha_impresion = date_i18n( 'd/m/Y', current_time( 'timestamp' ) );
?>
<style>
@media screen { .ct-print-view { display:none; } }
@media print {
	header.ct-panel-header,
	.ct-panel-footer,
	.ct-page-header,
	.ct-no-print,
	.ct-form,
	.ct-form-actions,
	[id^="ct-modal-"],
	#ct-modal-backdrop,
	.ct-mensajes-hilo,
	script { display:none !important; }
	.ct-container { box-shadow:none !important; padding:0 !important; max-width:100% !important; }
	.ct-print-view { display:block !important; }
	body { background:#fff !important; }
}
</style>
<div class="ct-print-view" style="font-family:Arial,Helvetica,sans-serif;color:#1e293b;max-width:780px;margin:0 auto;padding:20px;">
	<!-- Encabezado -->
	<div style="display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:18px;padding-bottom:14px;border-bottom:2px solid #1e3a5f;">
		<div>
			<div style="font-size:22px;font-weight:800;color:#1e3a5f;letter-spacing:.5px;">TrueTech</div>
			<div style="font-size:11px;color:#64748b;margin-top:2px;"><?php esc_html_e( 'Servicio Técnico — Instrumentos de Fibra Óptica', 'calibratrack' ); ?></div>
		</div>
		<div style="text-align:right;">
			<div style="font-size:16px;font-weight:700;color:#1e3a5f;"><?php esc_html_e( 'ORDEN DE TRABAJO', 'calibratrack' ); ?></div>
			<div style="font-size:20px;font-weight:800;color:#16a34a;margin-top:2px;"><?php echo esc_html( $v( 'numero_ot' ) ); ?></div>
			<?php if ( $print_oi_label ) : ?>
			<div style="font-size:11px;color:#64748b;margin-top:3px;">
				<?php
				/* translators: %s: número de OI */
				printf( esc_html__( 'OI: %s', 'calibratrack' ), esc_html( $print_oi_label ) );
				?>
			</div>
			<?php endif; ?>
			<div style="font-size:10px;color:#94a3b8;margin-top:4px;">
				<?php
				/* translators: %s: fecha de impresión */
				printf( esc_html__( 'Impreso: %s', 'calibratrack' ), esc_html( $print_fecha_impresion ) );
				?>
			</div>
		</div>
	</div>

	<!-- Datos principales -->
	<table style="width:100%;border-collapse:collapse;font-size:13px;margin-bottom:20px;">
		<tbody>
			<tr style="border-bottom:1px solid #e5e7eb;">
				<td style="padding:8px 12px;width:35%;font-weight:600;color:#374151;background:#f8fafc;"><?php esc_html_e( 'Equipo', 'calibratrack' ); ?></td>
				<td style="padding:8px 12px;"><?php echo esc_html( $print_equipo_ot ); ?></td>
			</tr>
			<tr style="border-bottom:1px solid #e5e7eb;">
				<td style="padding:8px 12px;font-weight:600;color:#374151;background:#f8fafc;"><?php esc_html_e( 'Técnico responsable', 'calibratrack' ); ?></td>
				<td style="padding:8px 12px;"><?php echo esc_html( $print_tecnico_ot ); ?></td>
			</tr>
			<tr style="border-bottom:1px solid #e5e7eb;">
				<td style="padding:8px 12px;font-weight:600;color:#374151;background:#f8fafc;"><?php esc_html_e( 'Tipo de servicio', 'calibratrack' ); ?></td>
				<td style="padding:8px 12px;"><?php echo esc_html( $print_tipo_ot ); ?></td>
			</tr>
			<tr style="border-bottom:1px solid #e5e7eb;">
				<td style="padding:8px 12px;font-weight:600;color:#374151;background:#f8fafc;"><?php esc_html_e( 'Fecha de ejecución', 'calibratrack' ); ?></td>
				<td style="padding:8px 12px;"><?php echo esc_html( $print_fecha_ej ); ?></td>
			</tr>
			<?php if ( $print_fecha_prox ) : ?>
			<tr style="border-bottom:1px solid #e5e7eb;">
				<td style="padding:8px 12px;font-weight:600;color:#374151;background:#f8fafc;"><?php esc_html_e( 'Próxima fecha de control', 'calibratrack' ); ?></td>
				<td style="padding:8px 12px;"><?php echo esc_html( $print_fecha_prox ); ?></td>
			</tr>
			<?php endif; ?>
			<tr>
				<td style="padding:8px 12px;font-weight:600;color:#374151;background:#f8fafc;"><?php esc_html_e( 'Estado', 'calibratrack' ); ?></td>
				<td style="padding:8px 12px;"><?php echo esc_html( $print_estado_ot ); ?></td>
			</tr>
		</tbody>
	</table>

	<!-- Campos de texto -->
	<?php foreach ( array(
		'falla_reportada'     => __( 'Falla reportada por el cliente', 'calibratrack' ),
		'descripcion_trabajo' => __( 'Descripción del trabajo / servicio realizado', 'calibratrack' ),
		'observaciones'       => __( 'Observaciones', 'calibratrack' ),
	) as $campo => $etiqueta ) :
		$val = $v( $campo );
		if ( ! $val ) { continue; }
	?>
	<div style="margin-bottom:16px;">
		<div style="font-weight:700;font-size:11px;text-transform:uppercase;color:#374151;letter-spacing:.5px;margin-bottom:5px;padding-bottom:3px;border-bottom:1px solid #e5e7eb;">
			<?php echo esc_html( $etiqueta ); ?>
		</div>
		<div style="font-size:13px;line-height:1.6;white-space:pre-wrap;"><?php echo esc_html( $val ); ?></div>
	</div>
	<?php endforeach; ?>

	<!-- Ítems de costo -->
	<?php if ( ! empty( $items_actuales ) ) : ?>
	<div style="margin-bottom:20px;">
		<div style="font-weight:700;font-size:11px;text-transform:uppercase;color:#374151;letter-spacing:.5px;margin-bottom:8px;padding-bottom:3px;border-bottom:1px solid #e5e7eb;">
			<?php esc_html_e( 'Ítems de costo', 'calibratrack' ); ?>
		</div>
		<table style="width:100%;border-collapse:collapse;font-size:13px;">
			<thead>
				<tr style="background:#1e3a5f;color:#fff;">
					<th style="padding:8px 10px;text-align:left;font-weight:600;"><?php esc_html_e( 'Detalle', 'calibratrack' ); ?></th>
					<th style="padding:8px 10px;text-align:center;font-weight:600;width:80px;"><?php esc_html_e( 'Cant.', 'calibratrack' ); ?></th>
					<th style="padding:8px 10px;text-align:right;font-weight:600;width:130px;"><?php esc_html_e( 'Precio unit.', 'calibratrack' ); ?></th>
					<th style="padding:8px 10px;text-align:right;font-weight:600;width:120px;"><?php esc_html_e( 'Subtotal', 'calibratrack' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $items_actuales as $item ) :
					$det  = isset( $item['detalle'] )         ? $item['detalle']                    : '';
					$qty  = isset( $item['cantidad'] )        ? (float) $item['cantidad']            : 0;
					$prc  = isset( $item['precio_unitario'] ) ? (float) $item['precio_unitario']     : 0;
					$sub  = $qty * $prc;
				?>
				<tr style="border-bottom:1px solid #e5e7eb;">
					<td style="padding:7px 10px;"><?php echo esc_html( $det ); ?></td>
					<td style="padding:7px 10px;text-align:center;"><?php echo esc_html( number_format( $qty, 2, ',', '.' ) ); ?></td>
					<td style="padding:7px 10px;text-align:right;">$<?php echo esc_html( number_format( $prc, 0, ',', '.' ) ); ?></td>
					<td style="padding:7px 10px;text-align:right;">$<?php echo esc_html( number_format( $sub, 0, ',', '.' ) ); ?></td>
				</tr>
				<?php endforeach; ?>
			</tbody>
			<tfoot>
				<tr>
					<td colspan="3" style="padding:7px 10px;text-align:right;font-weight:600;color:#374151;"><?php esc_html_e( 'Subtotal', 'calibratrack' ); ?></td>
					<td style="padding:7px 10px;text-align:right;font-weight:600;">$<?php echo esc_html( number_format( $print_subtotal, 0, ',', '.' ) ); ?></td>
				</tr>
				<tr>
					<td colspan="3" style="padding:7px 10px;text-align:right;font-weight:600;color:#374151;"><?php esc_html_e( 'IVA 19%', 'calibratrack' ); ?></td>
					<td style="padding:7px 10px;text-align:right;font-weight:600;">$<?php echo esc_html( number_format( $print_iva, 0, ',', '.' ) ); ?></td>
				</tr>
				<tr style="background:#f0fdf4;">
					<td colspan="3" style="padding:9px 10px;text-align:right;font-weight:800;font-size:14px;color:#15803d;"><?php esc_html_e( 'TOTAL', 'calibratrack' ); ?></td>
					<td style="padding:9px 10px;text-align:right;font-weight:800;font-size:14px;color:#15803d;">$<?php echo esc_html( number_format( $print_total, 0, ',', '.' ) ); ?></td>
				</tr>
			</tfoot>
		</table>
	</div>
	<?php endif; ?>

	<!-- Pie de página -->
	<div style="margin-top:30px;padding-top:10px;border-top:1px solid #e5e7eb;font-size:10px;color:#94a3b8;text-align:center;">
		&copy; <?php echo esc_html( date_i18n( 'Y' ) ); ?> TrueTech SpA
	</div>
</div>
<?php endif; ?>

<?php include __DIR__ . '/_partials/footer.php'; ?>
