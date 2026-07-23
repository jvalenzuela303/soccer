<?php
/**
 * Template de la página pública de verificación de equipos.
 *
 * Variables disponibles (inyectadas por CalibraTrack_Public):
 *   $serie_sanitizada  string      Serie buscada (ya sanitizada). Cadena vacía si no hay búsqueda.
 *   $api_data          array|null  Datos públicos del equipo desde el endpoint REST. Null si no existe.
 *   $api_error         string      Clave de error: '' | 'not_found' | 'rate_limit' | 'server_error'.
 *
 * SEGURIDAD (§9 — no negociable):
 *   - No mostrar: RUT, teléfono, correo del cliente.
 *   - No mostrar URLs directas de archivos PDF.
 *   - Solo lectura: ningún formulario de edición.
 *
 * @package CalibraTrack
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Variables con valores seguros por defecto (en caso de ser incluido directamente).
$serie_sanitizada = isset( $serie_sanitizada ) ? (string) $serie_sanitizada : '';
$api_data         = isset( $api_data ) ? $api_data : null;
$api_error        = isset( $api_error ) ? (string) $api_error : '';

// Mapeo de estado a clase CSS y etiqueta.
$estado_cfg = array(
	'vigente'    => array(
		'clase'   => 'ct-badge--vigente',
		'etiqueta' => __( 'Vigente', 'calibratrack' ),
	),
	'por_vencer' => array(
		'clase'   => 'ct-badge--por-vencer',
		'etiqueta' => __( 'Próximo a vencer', 'calibratrack' ),
	),
	'vencido'    => array(
		'clase'   => 'ct-badge--vencido',
		'etiqueta' => __( 'Vencido', 'calibratrack' ),
	),
	'sin_evento' => array(
		'clase'   => 'ct-badge--sin-evento',
		'etiqueta' => __( 'Sin registro de calibración', 'calibratrack' ),
	),
);

// Obtener el estado del equipo (viene en la respuesta del API).
$estado_vigencia = ( $api_data && isset( $api_data['estado_vigencia'] ) ) ? $api_data['estado_vigencia'] : '';
$estado_info     = isset( $estado_cfg[ $estado_vigencia ] ) ? $estado_cfg[ $estado_vigencia ] : array(
	'clase'   => 'ct-badge--sin-evento',
	'etiqueta' => __( 'Sin registro de calibración', 'calibratrack' ),
);

get_header();
?>

<div class="ct-verificar-wrapper" role="main">
	<div class="ct-verificar-container">

		<?php /* ── ENCABEZADO DEL PLUGIN ── */ ?>
		<div class="ct-verificar-header">
			<div class="ct-verificar-logo-area">
				<span class="ct-verificar-icon" aria-hidden="true">&#128269;</span>
				<h1 class="ct-verificar-title">
					<?php esc_html_e( 'Verificación de Autenticidad', 'calibratrack' ); ?>
				</h1>
			</div>
			<p class="ct-verificar-subtitle">
				<?php esc_html_e( 'Ingrese el número de serie del equipo para verificar su estado de calibración y autenticidad.', 'calibratrack' ); ?>
			</p>
		</div>

		<?php /* ── FORMULARIO DE BÚSQUEDA ── */ ?>
		<div class="ct-search-box">
			<form
				id="ct-form-busqueda"
				class="ct-search-form"
				method="get"
				action="<?php echo esc_url( home_url( '/verificar/' ) ); ?>"
				novalidate
				aria-label="<?php esc_attr_e( 'Buscar equipo por serie', 'calibratrack' ); ?>"
			>
				<div class="ct-search-input-group">
					<label for="ct-input-serie" class="ct-search-label">
						<?php esc_html_e( 'Número de serie', 'calibratrack' ); ?>
					</label>
					<div class="ct-search-row">
						<input
							type="text"
							id="ct-input-serie"
							name="serie"
							class="ct-search-input"
							value="<?php echo esc_attr( $serie_sanitizada ); ?>"
							placeholder="<?php esc_attr_e( 'Ej: GS-401-0001', 'calibratrack' ); ?>"
							autocomplete="off"
							autocorrect="off"
							autocapitalize="characters"
							aria-required="true"
							aria-describedby="ct-search-help"
						/>
						<button type="submit" class="ct-search-btn">
							<?php esc_html_e( 'Verificar', 'calibratrack' ); ?>
						</button>
					</div>
					<p id="ct-search-help" class="ct-search-help">
						<?php esc_html_e( 'El número de serie se encuentra impreso en el equipo o en el certificado de calibración.', 'calibratrack' ); ?>
					</p>
					<p id="ct-search-error" class="ct-search-error" role="alert" aria-live="polite" style="display:none;">
						<?php esc_html_e( 'Por favor ingrese un número de serie válido.', 'calibratrack' ); ?>
					</p>
				</div>
			</form>
		</div>

		<?php /* ── RESULTADOS ── */ ?>
		<?php if ( ! empty( $serie_sanitizada ) ) : ?>

			<?php /* ── ERROR: Rate limit ── */ ?>
			<?php if ( 'rate_limit' === $api_error ) : ?>
				<div class="ct-alert ct-alert--warning" role="alert">
					<span class="ct-alert-icon" aria-hidden="true">&#9888;</span>
					<div class="ct-alert-content">
						<strong><?php esc_html_e( 'Demasiadas consultas', 'calibratrack' ); ?></strong>
						<p><?php esc_html_e( 'Ha realizado demasiadas consultas en poco tiempo. Por favor espere unos minutos e intente nuevamente.', 'calibratrack' ); ?></p>
					</div>
				</div>

			<?php /* ── ERROR: Servidor / conexión ── */ ?>
			<?php elseif ( 'server_error' === $api_error ) : ?>
				<div class="ct-alert ct-alert--error" role="alert">
					<span class="ct-alert-icon" aria-hidden="true">&#10006;</span>
					<div class="ct-alert-content">
						<strong><?php esc_html_e( 'Error al consultar el sistema', 'calibratrack' ); ?></strong>
						<p><?php esc_html_e( 'No fue posible conectar con el sistema de verificación. Por favor intente más tarde.', 'calibratrack' ); ?></p>
					</div>
				</div>

			<?php /* ── NOT FOUND: Serie no existe (antifraude) ── */ ?>
			<?php elseif ( 'not_found' === $api_error || ( empty( $api_error ) && null === $api_data ) ) : ?>
				<div class="ct-alert ct-alert--not-found" role="alert">
					<span class="ct-alert-icon" aria-hidden="true">&#9888;</span>
					<div class="ct-alert-content">
						<strong>
							<?php
							printf(
								/* translators: %s: número de serie buscado */
								esc_html__( 'Serie "%s" no encontrada', 'calibratrack' ),
								esc_html( $serie_sanitizada )
							);
							?>
						</strong>
						<p><?php esc_html_e( 'El número de serie ingresado no se encuentra registrado en nuestro sistema.', 'calibratrack' ); ?></p>
						<p class="ct-not-found-detail">
							<?php esc_html_e( 'Si usted recibió un certificado con este número de serie, este documento podría no ser auténtico. Le recomendamos:', 'calibratrack' ); ?>
						</p>
						<ul class="ct-not-found-list">
							<li><?php esc_html_e( 'Verificar que el número de serie esté escrito exactamente como aparece en el equipo o en el documento.', 'calibratrack' ); ?></li>
							<li><?php esc_html_e( 'Contactar directamente a la empresa emisora del certificado para confirmar su autenticidad.', 'calibratrack' ); ?></li>
						</ul>
					</div>
				</div>

			<?php /* ── RESULTADO ENCONTRADO ── */ ?>
			<?php elseif ( is_array( $api_data ) ) : ?>

				<?php
				$historial   = ( isset( $api_data['historial'] ) && is_array( $api_data['historial'] ) ) ? $api_data['historial'] : array();
				$ultimo      = ! empty( $historial ) ? $historial[0] : null;
				$modo_token  = isset( $modo_token ) ? (bool) $modo_token : false;

				// Helper: formatea fecha YYYY-MM-DD a DD/MM/YYYY.
				$fmt_fecha = function( $raw ) {
					if ( empty( $raw ) ) { return '—'; }
					$dt = DateTime::createFromFormat( 'Y-m-d', $raw );
					return $dt ? $dt->format( 'd/m/Y' ) : $raw;
				};
				?>

				<?php if ( $modo_token ) : ?>
				<?php /* ══════════════════════════════════════════════════════════════
				       MODO TOKEN (acceso por QR o enlace del correo)
				       Experiencia centrada en la verificación de autenticidad.
				       ══════════════════════════════════════════════════════════════ */ ?>

				<?php /* Banner principal de verificación */ ?>
				<div class="ct-verificado-banner" role="status">
					<div class="ct-verificado-check">&#10003;</div>
					<div class="ct-verificado-texto">
						<strong><?php esc_html_e( 'Servicio verificado', 'calibratrack' ); ?></strong>
						<span><?php esc_html_e( 'Este servicio existe y está registrado en el sistema oficial.', 'calibratrack' ); ?></span>
					</div>
					<div class="ct-verificado-badge">
						<span class="ct-badge <?php echo esc_attr( $estado_info['clase'] ); ?>">
							<?php echo esc_html( $estado_info['etiqueta'] ); ?>
						</span>
					</div>
				</div>

				<?php /* Tarjeta de datos de verificación */ ?>
				<div class="ct-verificacion-card">

					<?php if ( ! empty( $api_data['nombre_empresa'] ) ) : ?>
					<div class="ct-verificacion-empresa">
						<span class="ct-verificacion-empresa-label"><?php esc_html_e( 'Empresa propietaria', 'calibratrack' ); ?></span>
						<span class="ct-verificacion-empresa-nombre"><?php echo esc_html( $api_data['nombre_empresa'] ); ?></span>
					</div>
					<?php endif; ?>

					<div class="ct-verificacion-grid">
						<div class="ct-verificacion-item">
							<span class="ct-vitem-label"><?php esc_html_e( 'Equipo', 'calibratrack' ); ?></span>
							<span class="ct-vitem-valor"><?php echo esc_html( $api_data['marca'] . ' ' . $api_data['modelo'] ); ?></span>
						</div>
						<div class="ct-verificacion-item">
							<span class="ct-vitem-label"><?php esc_html_e( 'Tipo', 'calibratrack' ); ?></span>
							<span class="ct-vitem-valor"><?php echo esc_html( $api_data['tipo_equipo'] ); ?></span>
						</div>
						<div class="ct-verificacion-item">
							<span class="ct-vitem-label"><?php esc_html_e( 'N° de serie', 'calibratrack' ); ?></span>
							<span class="ct-vitem-valor"><code><?php echo esc_html( $api_data['serie'] ); ?></code></span>
						</div>
						<?php if ( $ultimo ) : ?>
						<div class="ct-verificacion-item">
							<span class="ct-vitem-label"><?php esc_html_e( 'Último servicio', 'calibratrack' ); ?></span>
							<span class="ct-vitem-valor"><?php echo esc_html( $fmt_fecha( $ultimo['fecha_ejecucion'] ) ); ?></span>
						</div>
						<div class="ct-verificacion-item">
							<span class="ct-vitem-label"><?php esc_html_e( 'Tipo de servicio', 'calibratrack' ); ?></span>
							<span class="ct-vitem-valor"><?php echo esc_html( $ultimo['tipo'] ); ?></span>
						</div>
						<div class="ct-verificacion-item">
							<span class="ct-vitem-label"><?php esc_html_e( 'N° OT', 'calibratrack' ); ?></span>
							<span class="ct-vitem-valor"><?php echo esc_html( $ultimo['numero_ot'] ?: '—' ); ?></span>
						</div>
						<div class="ct-verificacion-item">
							<span class="ct-vitem-label"><?php esc_html_e( 'Técnico responsable', 'calibratrack' ); ?></span>
							<span class="ct-vitem-valor"><?php echo esc_html( $ultimo['tecnico'] ?: '—' ); ?></span>
						</div>
						<div class="ct-verificacion-item">
							<span class="ct-vitem-label"><?php esc_html_e( 'Próxima calibración', 'calibratrack' ); ?></span>
							<span class="ct-vitem-valor"><?php echo esc_html( $fmt_fecha( $ultimo['proxima_fecha_control'] ) ); ?></span>
						</div>
						<?php endif; ?>
					</div>
				</div>

				<?php /* Nota explicativa */ ?>
				<div class="ct-verificar-footer">
					<p class="ct-verificar-nota">
						<span aria-hidden="true">&#128274;</span>
						<?php esc_html_e( 'Los datos mostrados provienen directamente de la base de datos del sistema — no del documento PDF. Si coinciden con el certificado que usted tiene en mano, el documento es auténtico.', 'calibratrack' ); ?>
					</p>
				</div>

				<?php /* Historial completo (secundario) */ ?>
				<?php if ( count( $historial ) > 1 ) : ?>
				<div class="ct-historial-section" style="margin-top:28px;">
					<h3 class="ct-historial-titulo"><?php esc_html_e( 'Historial completo del equipo', 'calibratrack' ); ?></h3>
					<div class="ct-historial-tabla-wrap">
						<table class="ct-historial-tabla">
							<thead>
								<tr>
									<th scope="col"><?php esc_html_e( 'Fecha', 'calibratrack' ); ?></th>
									<th scope="col"><?php esc_html_e( 'Tipo', 'calibratrack' ); ?></th>
									<th scope="col"><?php esc_html_e( 'N° OT', 'calibratrack' ); ?></th>
									<th scope="col"><?php esc_html_e( 'Técnico', 'calibratrack' ); ?></th>
									<th scope="col"><?php esc_html_e( 'Próxima calibración', 'calibratrack' ); ?></th>
								</tr>
							</thead>
							<tbody>
								<?php foreach ( $historial as $evento ) : ?>
									<?php if ( ! is_array( $evento ) ) { continue; } ?>
									<tr>
										<td><?php echo esc_html( $fmt_fecha( $evento['fecha_ejecucion'] ) ); ?></td>
										<td><?php echo esc_html( $evento['tipo'] ); ?></td>
										<td><?php echo esc_html( $evento['numero_ot'] ?: '—' ); ?></td>
										<td><?php echo esc_html( $evento['tecnico'] ?: '—' ); ?></td>
										<td><?php echo esc_html( $fmt_fecha( $evento['proxima_fecha_control'] ) ); ?></td>
									</tr>
								<?php endforeach; ?>
							</tbody>
						</table>
					</div>
				</div>
				<?php endif; ?>

				<?php else : ?>
				<?php /* ══════════════════════════════════════════════════════════════
				       MODO BÚSQUEDA MANUAL (formulario por serie)
				       Experiencia informativa del estado del equipo.
				       ══════════════════════════════════════════════════════════════ */ ?>

				<div class="ct-equipo-card">
					<div class="ct-equipo-header">
						<div class="ct-equipo-info">
							<h2 class="ct-equipo-nombre">
								<?php echo esc_html( $api_data['marca'] . ' ' . $api_data['modelo'] ); ?>
							</h2>
							<dl class="ct-equipo-meta">
								<div class="ct-equipo-meta-item">
									<dt><?php esc_html_e( 'Tipo de equipo', 'calibratrack' ); ?></dt>
									<dd><?php echo esc_html( $api_data['tipo_equipo'] ); ?></dd>
								</div>
								<div class="ct-equipo-meta-item">
									<dt><?php esc_html_e( 'Número de serie', 'calibratrack' ); ?></dt>
									<dd><code class="ct-serie"><?php echo esc_html( $api_data['serie'] ); ?></code></dd>
								</div>
								<div class="ct-equipo-meta-item">
									<dt><?php esc_html_e( 'Marca', 'calibratrack' ); ?></dt>
									<dd><?php echo esc_html( $api_data['marca'] ); ?></dd>
								</div>
								<div class="ct-equipo-meta-item">
									<dt><?php esc_html_e( 'Modelo', 'calibratrack' ); ?></dt>
									<dd><?php echo esc_html( $api_data['modelo'] ); ?></dd>
								</div>
							</dl>
						</div>
						<div class="ct-equipo-badge-area">
							<span class="ct-badge <?php echo esc_attr( $estado_info['clase'] ); ?>" role="status">
								<?php echo esc_html( $estado_info['etiqueta'] ); ?>
							</span>
						</div>
					</div>
				</div>

				<div class="ct-historial-section">
					<h3 class="ct-historial-titulo"><?php esc_html_e( 'Historial de servicios', 'calibratrack' ); ?></h3>

					<?php if ( empty( $historial ) ) : ?>
						<p class="ct-historial-vacio"><?php esc_html_e( 'Este equipo no tiene eventos de servicio registrados.', 'calibratrack' ); ?></p>
					<?php else : ?>
						<div class="ct-historial-tabla-wrap">
							<table class="ct-historial-tabla">
								<thead>
									<tr>
										<th scope="col"><?php esc_html_e( 'Fecha', 'calibratrack' ); ?></th>
										<th scope="col"><?php esc_html_e( 'Tipo', 'calibratrack' ); ?></th>
										<th scope="col"><?php esc_html_e( 'N° OT', 'calibratrack' ); ?></th>
										<th scope="col"><?php esc_html_e( 'Técnico', 'calibratrack' ); ?></th>
										<th scope="col"><?php esc_html_e( 'Próxima calibración', 'calibratrack' ); ?></th>
										<th scope="col"><?php esc_html_e( 'Certificado y documentos', 'calibratrack' ); ?></th>
									</tr>
								</thead>
								<tbody>
									<?php foreach ( $historial as $evento ) : ?>
										<?php if ( ! is_array( $evento ) ) { continue; } ?>
										<?php
										$tiene_cert  = ! empty( $evento['tiene_certificado'] );
										$ev_id_dl    = isset( $evento['evento_id'] ) ? (int) $evento['evento_id'] : 0;
										$num_docs    = isset( $evento['num_documentos'] ) ? (int) $evento['num_documentos'] : 0;
										$serie_enc   = rawurlencode( $api_data['serie'] );
										?>
										<tr>
											<td data-label="<?php esc_attr_e( 'Fecha', 'calibratrack' ); ?>"><?php echo esc_html( $fmt_fecha( $evento['fecha_ejecucion'] ) ); ?></td>
											<td data-label="<?php esc_attr_e( 'Tipo', 'calibratrack' ); ?>"><?php echo esc_html( $evento['tipo'] ); ?></td>
											<td data-label="<?php esc_attr_e( 'N° OT', 'calibratrack' ); ?>"><?php echo esc_html( $evento['numero_ot'] ?: '—' ); ?></td>
											<td data-label="<?php esc_attr_e( 'Técnico', 'calibratrack' ); ?>"><?php echo esc_html( $evento['tecnico'] ?: '—' ); ?></td>
											<td data-label="<?php esc_attr_e( 'Próxima calibración', 'calibratrack' ); ?>"><?php echo esc_html( $fmt_fecha( $evento['proxima_fecha_control'] ) ); ?></td>
											<td data-label="<?php esc_attr_e( 'Certificado y documentos', 'calibratrack' ); ?>" class="ct-docs-cell">
												<?php if ( $tiene_cert && $ev_id_dl > 0 ) : ?>
													<a href="<?php echo esc_url( home_url( '/verificar/?serie=' . $serie_enc . '&accion=certificado&evento_id=' . $ev_id_dl ) ); ?>"
													   target="_blank" rel="noopener noreferrer" class="ct-doc-link">
														📄 <?php esc_html_e( 'Certificado', 'calibratrack' ); ?>
													</a>
												<?php elseif ( $tiene_cert ) : ?>
													<span class="ct-doc-disponible"><?php esc_html_e( 'Disponible', 'calibratrack' ); ?></span>
												<?php endif; ?>
												<?php for ( $di = 0; $di < $num_docs; $di++ ) : ?>
													<a href="<?php echo esc_url( home_url( '/verificar/?serie=' . $serie_enc . '&accion=documento&evento_id=' . $ev_id_dl . '&doc_index=' . $di ) ); ?>"
													   target="_blank" rel="noopener noreferrer" class="ct-doc-link">
														📎 <?php
														/* translators: %d: número de documento */
														printf( esc_html__( 'Doc. %d', 'calibratrack' ), $di + 1 );
														?>
													</a>
												<?php endfor; ?>
												<?php if ( ! $tiene_cert && $num_docs === 0 ) : ?>
													<span class="ct-sin-docs">—</span>
												<?php endif; ?>
											</td>
										</tr>
									<?php endforeach; ?>
								</tbody>
							</table>
						</div>
					<?php endif; ?>
				</div>

				<div class="ct-verificar-footer">
					<p class="ct-verificar-nota">
						<span aria-hidden="true">&#9989;</span>
						<?php esc_html_e( 'Este registro es la fuente de verdad del sistema. La información mostrada aquí certifica la autenticidad del servicio realizado.', 'calibratrack' ); ?>
					</p>
				</div>

				<?php endif; /* fin modo_token */ ?>

			<?php endif; /* fin resultado encontrado */ ?>

		<?php endif; /* fin if $serie_sanitizada no vacío */ ?>

	</div><!-- .ct-verificar-container -->
</div><!-- .ct-verificar-wrapper -->

<?php get_footer(); ?>
