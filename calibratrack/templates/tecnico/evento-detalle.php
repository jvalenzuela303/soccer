<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
$page_title   = __( 'Editar evento', 'calibratrack' );
$evento_id    = isset( $evento_id ) ? (int) $evento_id : 0;
$valores      = isset( $valores ) ? $valores : array();
$errors       = isset( $errors ) ? $errors : array();
$equipos      = isset( $equipos ) ? $equipos : array();
$es_completado = ! empty( $es_completado );
$actualizado  = isset( $_GET['actualizado'] ) ? true : false;
$cert_id      = $evento_id ? (int) get_post_meta( $evento_id, CalibraTrack_Meta_Keys::EVENTO_CERTIFICADO_PDF, true ) : 0;
$ot_id        = $evento_id ? (int) get_post_meta( $evento_id, CalibraTrack_Meta_Keys::EVENTO_ORDEN_TRABAJO_PDF, true ) : 0;
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
		<a href="<?php echo esc_url( home_url( '/tecnico/eventos/' ) ); ?>" class="ct-link">← <?php esc_html_e( 'Volver a mis eventos', 'calibratrack' ); ?></a>
	</div>

	<?php if ( $actualizado ) : ?>
		<div class="ct-alert ct-alert--success" role="alert">
			<?php esc_html_e( 'Evento actualizado correctamente.', 'calibratrack' ); ?>
			<?php if ( $cert_id ) : ?>
				— <a href="<?php echo esc_url( home_url( '/tecnico/evento/' . $evento_id . '/certificado/' ) ); ?>" target="_blank" class="ct-link">
					<?php esc_html_e( 'Ver certificado PDF', 'calibratrack' ); ?>
				</a>
			<?php endif; ?>
		</div>
	<?php endif; ?>

	<?php if ( $es_completado ) : ?>
		<!-- Aviso de OT finalizada -->
		<div class="ct-alert ct-alert--info" role="alert" style="background:#f0f9ff;border-left:4px solid #0ea5e9;color:#0369a1;padding:14px 18px;border-radius:6px;margin-bottom:16px;">
			<strong><?php esc_html_e( 'Orden de Trabajo finalizada', 'calibratrack' ); ?></strong> —
			<?php esc_html_e( 'Esta OT ya fue completada y su certificado emitido. Los datos no pueden ser modificados.', 'calibratrack' ); ?>
		</div>

		<!-- Documentos disponibles -->
		<div style="display:flex;gap:12px;flex-wrap:wrap;margin-bottom:24px;">
			<?php if ( $ot_id ) : ?>
				<a href="<?php echo esc_url( wp_get_attachment_url( $ot_id ) ); ?>" target="_blank" class="ct-btn ct-btn--secondary">
					📄 <?php esc_html_e( 'Ver Orden de Trabajo PDF', 'calibratrack' ); ?>
				</a>
			<?php endif; ?>
			<?php if ( $cert_id ) : ?>
				<a href="<?php echo esc_url( home_url( '/tecnico/evento/' . $evento_id . '/certificado/' ) ); ?>" target="_blank" class="ct-btn ct-btn--primary">
					🏅 <?php esc_html_e( 'Ver Certificado PDF', 'calibratrack' ); ?>
				</a>
			<?php endif; ?>
		</div>

		<!-- Resumen de datos (solo lectura) -->
		<div style="background:#f9fafb;border:1px solid #e5e7eb;border-radius:8px;padding:20px;font-size:14px;">
			<h2 style="font-size:15px;font-weight:700;margin:0 0 14px;color:#111827;"><?php esc_html_e( 'Datos del evento', 'calibratrack' ); ?></h2>
			<?php
			$v = function( $key, $default = '—' ) use ( $valores ) {
				$val = isset( $valores[ $key ] ) ? $valores[ $key ] : '';
				return '' !== $val ? $val : $default;
			};
			$tipos_map = CalibraTrack_Helpers::get_tipos_evento();
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

	<?php else : ?>
		<!-- Formulario de edición normal -->
		<?php if ( $cert_id ) : ?>
			<div class="ct-cert-disponible">
				<span>📄 <?php esc_html_e( 'Certificado PDF disponible', 'calibratrack' ); ?></span>
				<a href="<?php echo esc_url( home_url( '/tecnico/evento/' . $evento_id . '/certificado/' ) ); ?>" target="_blank" class="ct-btn ct-btn--sm">
					<?php esc_html_e( 'Descargar / Ver PDF', 'calibratrack' ); ?>
				</a>
			</div>
		<?php endif; ?>

		<form method="post" action="" enctype="multipart/form-data" class="ct-form" novalidate>
			<?php include __DIR__ . '/_partials/form-evento-fields.php'; ?>

			<!-- Estado del servicio -->
			<?php
			$estado_actual_form = isset( $valores['estado_servicio'] ) ? $valores['estado_servicio'] : 'en_proceso';
			if ( '' === $estado_actual_form ) { $estado_actual_form = 'en_proceso'; }
			?>
			<div class="ct-field" style="margin-top:20px;padding:16px;background:#fffbeb;border:1px solid #fcd34d;border-radius:8px;">
				<label for="ct-estado-servicio" class="ct-label" style="font-weight:700;color:#92400e;">
					<?php esc_html_e( '¿Finalizar este servicio?', 'calibratrack' ); ?>
				</label>
				<p style="font-size:13px;color:#78350f;margin:4px 0 12px;">
					<?php esc_html_e( 'Al marcar como "Completado" se generará el certificado PDF y se enviará al cliente. Esta acción no puede deshacerse.', 'calibratrack' ); ?>
				</p>
				<select id="ct-estado-servicio" name="estado_servicio" class="ct-select">
					<option value="en_proceso" <?php selected( $estado_actual_form, 'en_proceso' ); ?>>
						<?php esc_html_e( 'En proceso', 'calibratrack' ); ?>
					</option>
					<option value="completado" <?php selected( $estado_actual_form, 'completado' ); ?>>
						<?php esc_html_e( 'Completado — Emitir certificado', 'calibratrack' ); ?>
					</option>
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
</div>

<?php include __DIR__ . '/_partials/footer.php'; ?>
