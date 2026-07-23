<?php
/**
 * Partial: campos del formulario de evento.
 * Variables esperadas:
 *   $valores  array   Valores a pre-poblar.
 *   $errors   array   Errores de validación por campo.
 *   $equipos  array   Lista de WP_Post de equipos.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }
$valores  = isset( $valores ) ? $valores : array();
$errors   = isset( $errors ) ? $errors : array();
$equipos  = isset( $equipos ) ? $equipos : array();
$v        = function( $key, $default = '' ) use ( $valores ) {
	return isset( $valores[ $key ] ) ? $valores[ $key ] : $default;
};
$e        = function( $key ) use ( $errors ) {
	return isset( $errors[ $key ] ) ? $errors[ $key ] : '';
};
$tipos_evento = CalibraTrack_Helpers::get_tipos_evento();
?>

<?php if ( ! empty( $errors['general'] ) ) : ?>
<div class="ct-alert ct-alert--error" role="alert"><?php echo esc_html( $errors['general'] ); ?></div>
<?php endif; ?>

<?php wp_nonce_field( 'calibratrack_tecnico_evento' ); ?>

<!-- Equipo -->
<div class="ct-field <?php echo $e('equipo_id') ? 'ct-field--error' : ''; ?>">
	<label for="ct-equipo-id" class="ct-label"><?php esc_html_e( 'Equipo *', 'calibratrack' ); ?></label>
	<select id="ct-equipo-id" name="equipo_id" class="ct-select ct-select--searchable" required>
		<option value="0"><?php esc_html_e( '— Seleccionar equipo —', 'calibratrack' ); ?></option>
		<?php foreach ( $equipos as $eq ) :
			$serie_eq = get_post_meta( $eq->ID, CalibraTrack_Meta_Keys::EQUIPO_SERIE, true );
			$marca_eq = get_post_meta( $eq->ID, CalibraTrack_Meta_Keys::EQUIPO_MARCA, true );
			$modelo_eq = get_post_meta( $eq->ID, CalibraTrack_Meta_Keys::EQUIPO_MODELO, true );
		?>
		<option value="<?php echo esc_attr( $eq->ID ); ?>" <?php selected( $v('equipo_id'), $eq->ID ); ?>>
			<?php echo esc_html( $serie_eq . ' — ' . $marca_eq . ' ' . $modelo_eq ); ?>
		</option>
		<?php endforeach; ?>
	</select>
	<?php if ( $e('equipo_id') ) : ?>
		<span class="ct-field-error"><?php echo esc_html( $e('equipo_id') ); ?></span>
	<?php endif; ?>
</div>

<!-- N° OT y Tipo -->
<div class="ct-field-group">
	<div class="ct-field <?php echo $e('numero_ot') ? 'ct-field--error' : ''; ?>">
		<label for="ct-numero-ot" class="ct-label"><?php esc_html_e( 'N° de Orden de Trabajo *', 'calibratrack' ); ?></label>
		<input type="text" id="ct-numero-ot" name="numero_ot" class="ct-input"
			value="<?php echo esc_attr( $v('numero_ot') ); ?>" required>
		<?php if ( $e('numero_ot') ) : ?>
			<span class="ct-field-error"><?php echo esc_html( $e('numero_ot') ); ?></span>
		<?php endif; ?>
	</div>

	<div class="ct-field <?php echo $e('tipo') ? 'ct-field--error' : ''; ?>">
		<label for="ct-tipo" class="ct-label"><?php esc_html_e( 'Tipo de servicio *', 'calibratrack' ); ?></label>
		<select id="ct-tipo" name="tipo" class="ct-select" required>
			<option value=""><?php esc_html_e( '— Seleccionar —', 'calibratrack' ); ?></option>
			<?php foreach ( $tipos_evento as $slug => $label ) : ?>
			<option value="<?php echo esc_attr( $slug ); ?>" <?php selected( $v('tipo'), $slug ); ?>>
				<?php echo esc_html( $label ); ?>
			</option>
			<?php endforeach; ?>
		</select>
		<?php if ( $e('tipo') ) : ?>
			<span class="ct-field-error"><?php echo esc_html( $e('tipo') ); ?></span>
		<?php endif; ?>
	</div>
</div>

<!-- Fechas -->
<div class="ct-field-group">
	<div class="ct-field <?php echo $e('fecha_ejecucion') ? 'ct-field--error' : ''; ?>">
		<label for="ct-fecha-ejecucion" class="ct-label"><?php esc_html_e( 'Fecha de ejecución *', 'calibratrack' ); ?></label>
		<input type="date" id="ct-fecha-ejecucion" name="fecha_ejecucion" class="ct-input"
			value="<?php echo esc_attr( $v('fecha_ejecucion') ); ?>" required>
		<?php if ( $e('fecha_ejecucion') ) : ?>
			<span class="ct-field-error"><?php echo esc_html( $e('fecha_ejecucion') ); ?></span>
		<?php endif; ?>
	</div>

	<div class="ct-field">
		<label for="ct-proxima-fecha" class="ct-label"><?php esc_html_e( 'Próxima fecha de control', 'calibratrack' ); ?></label>
		<input type="date" id="ct-proxima-fecha" name="proxima_fecha" class="ct-input"
			value="<?php echo esc_attr( $v('proxima_fecha') ); ?>">
	</div>
</div>

<!-- Falla reportada -->
<div class="ct-field">
	<label for="ct-falla" class="ct-label"><?php esc_html_e( 'Defectos/falla reportada por el cliente', 'calibratrack' ); ?></label>
	<textarea id="ct-falla" name="falla_reportada" class="ct-textarea" rows="3"><?php echo esc_textarea( $v('falla_reportada') ); ?></textarea>
</div>

<!-- Descripción del trabajo -->
<div class="ct-field">
	<label for="ct-descripcion" class="ct-label"><?php esc_html_e( 'Servicio realizado / Descripción del trabajo', 'calibratrack' ); ?></label>
	<textarea id="ct-descripcion" name="descripcion_trabajo" class="ct-textarea" rows="5"><?php echo esc_textarea( $v('descripcion_trabajo') ); ?></textarea>
</div>

<!-- Observaciones -->
<div class="ct-field">
	<label for="ct-observaciones" class="ct-label"><?php esc_html_e( 'Observaciones', 'calibratrack' ); ?></label>
	<textarea id="ct-observaciones" name="observaciones" class="ct-textarea" rows="3"><?php echo esc_textarea( $v('observaciones') ); ?></textarea>
</div>

<!-- Evidencia fotográfica -->
<div class="ct-field">
	<label for="ct-fotos" class="ct-label"><?php esc_html_e( 'Evidencia fotográfica (imágenes)', 'calibratrack' ); ?></label>
	<input type="file" id="ct-fotos" name="evidencia_fotografica[]" class="ct-input-file"
		accept="image/jpeg,image/png,image/webp" multiple>
	<p class="ct-field-help"><?php esc_html_e( 'JPG, PNG o WEBP. Puede seleccionar varias fotos.', 'calibratrack' ); ?></p>
</div>

<!-- Documentos adjuntos -->
<?php
$docs_existentes = array();
$_eid_docs = isset( $evento_id ) ? (int) $evento_id : 0;
if ( $_eid_docs > 0 ) {
	$docs_raw = get_post_meta( $_eid_docs, CalibraTrack_Meta_Keys::EVENTO_DOCUMENTOS_ADJUNTOS, true );
	$docs_existentes = json_decode( (string) $docs_raw, true );
	if ( ! is_array( $docs_existentes ) ) { $docs_existentes = array(); }
}
?>
<div class="ct-field">
	<label for="ct-documentos" class="ct-label"><?php esc_html_e( 'Documentos adjuntos (PDF)', 'calibratrack' ); ?></label>
	<?php if ( ! empty( $docs_existentes ) ) : ?>
	<ul class="ct-docs-lista" style="margin-bottom:8px;">
		<?php foreach ( $docs_existentes as $doc_id ) : ?>
			<?php $doc_title = get_the_title( $doc_id ); ?>
			<?php if ( $doc_title ) : ?>
			<li>📄 <?php echo esc_html( $doc_title ); ?></li>
			<?php endif; ?>
		<?php endforeach; ?>
	</ul>
	<p class="ct-field-help"><?php esc_html_e( 'Documentos ya adjuntos. Los nuevos archivos se agregarán a la lista.', 'calibratrack' ); ?></p>
	<?php endif; ?>
	<input type="file" id="ct-documentos" name="documentos_adjuntos[]" class="ct-input-file"
		accept="application/pdf,.pdf" multiple>
	<p class="ct-field-help"><?php esc_html_e( 'PDF. Informes, protocolos u otros documentos que avalen el trabajo realizado. Estos documentos estarán disponibles para descarga junto al certificado.', 'calibratrack' ); ?></p>
</div>
