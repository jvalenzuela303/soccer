<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

$user       = wp_get_current_user();
$page_title = __( 'Inicio', 'calibratrack' );
$aviso      = isset( $_GET['guardado'] ) ? __( 'Evento registrado correctamente.', 'calibratrack' ) : '';

// ─── Parámetros de filtro ─────────────────────────────────────────────────
$buscar        = isset( $_GET['buscar'] ) ? sanitize_text_field( wp_unslash( $_GET['buscar'] ) ) : '';
$filtro_tipo   = isset( $_GET['tipo'] )   ? sanitize_key( $_GET['tipo'] )   : '';
$filtro_estado = isset( $_GET['estado'] ) ? sanitize_key( $_GET['estado'] ) : '';
$hay_filtros   = ( '' !== $buscar || '' !== $filtro_tipo || '' !== $filtro_estado );
$paged         = max( 1, isset( $_GET['pagina'] ) ? absint( $_GET['pagina'] ) : 1 );
$per_page      = 20;
$tipos_ev      = CalibraTrack_Helpers::get_tipos_evento();

if ( $hay_filtros ) {
	// ─── Modo filtrado: WP_Query paginado ─────────────────────────────────
	$query_args = array(
		'post_type'      => 'evento_servicio',
		'post_status'    => 'publish',
		'author'         => get_current_user_id(),
		'posts_per_page' => $per_page,
		'paged'          => $paged,
		'orderby'        => 'date',
		'order'          => 'DESC',
		'meta_query'     => array( 'relation' => 'AND' ),
	);

	// Filtro por tipo de evento.
	if ( '' !== $filtro_tipo ) {
		$query_args['meta_query'][] = array(
			'key'     => CalibraTrack_Meta_Keys::EVENTO_TIPO,
			'value'   => $filtro_tipo,
			'compare' => '=',
		);
	}

	// Filtro por estado (calculado desde proxima_fecha_control).
	if ( '' !== $filtro_estado ) {
		$hoy        = date( 'Y-m-d' );
		$hoy_plus30 = date( 'Y-m-d', strtotime( '+30 days' ) );
		switch ( $filtro_estado ) {
			case 'vigente':
				$query_args['meta_query'][] = array(
					'key'     => CalibraTrack_Meta_Keys::EVENTO_PROXIMA_FECHA_CONTROL,
					'value'   => $hoy_plus30,
					'compare' => '>',
					'type'    => 'DATE',
				);
				break;
			case 'por_vencer':
				$query_args['meta_query'][] = array(
					'key'     => CalibraTrack_Meta_Keys::EVENTO_PROXIMA_FECHA_CONTROL,
					'value'   => array( $hoy, $hoy_plus30 ),
					'compare' => 'BETWEEN',
					'type'    => 'DATE',
				);
				break;
			case 'vencido':
				$query_args['meta_query'][] = array(
					'key'     => CalibraTrack_Meta_Keys::EVENTO_PROXIMA_FECHA_CONTROL,
					'value'   => $hoy,
					'compare' => '<',
					'type'    => 'DATE',
				);
				break;
			case 'sin_evento':
				$query_args['meta_query'][] = array(
					'relation' => 'OR',
					array(
						'key'     => CalibraTrack_Meta_Keys::EVENTO_PROXIMA_FECHA_CONTROL,
						'compare' => 'NOT EXISTS',
					),
					array(
						'key'     => CalibraTrack_Meta_Keys::EVENTO_PROXIMA_FECHA_CONTROL,
						'value'   => '',
						'compare' => '=',
					),
				);
				break;
		}
	}

	// Búsqueda de texto: N° OT o serie del equipo asociado.
	if ( '' !== $buscar ) {
		$query_args['meta_query'][] = array(
			'relation' => 'OR',
			array(
				'key'     => CalibraTrack_Meta_Keys::EVENTO_NUMERO_OT,
				'value'   => $buscar,
				'compare' => 'LIKE',
			),
			// Búsqueda por serie: requiere buscar el equipo primero.
			array(
				'key'     => CalibraTrack_Meta_Keys::EVENTO_EQUIPO_ID,
				'value'   => array_map(
					function( $eq ) { return $eq->ID; },
					get_posts( array(
						'post_type'      => 'equipo',
						'post_status'    => 'publish',
						'posts_per_page' => 50,
						'fields'         => 'ids',
						'meta_query'     => array(
							array(
								'key'     => CalibraTrack_Meta_Keys::EQUIPO_SERIE,
								'value'   => $buscar,
								'compare' => 'LIKE',
							),
						),
					) )
				),
				'compare' => 'IN',
				'type'    => 'NUMERIC',
			),
		);
	}

	$query       = new WP_Query( $query_args );
	$eventos     = $query->posts;
	$total_pages = $query->max_num_pages;

	// URL base para paginación conservando filtros.
	$base_url = add_query_arg( array_filter( array(
		'buscar' => $buscar ?: null,
		'tipo'   => $filtro_tipo ?: null,
		'estado' => $filtro_estado ?: null,
	) ), home_url( '/tecnico/' ) );

} else {
	// ─── Modo por defecto: últimos 10 eventos ─────────────────────────────
	$eventos     = get_posts( array(
		'post_type'      => 'evento_servicio',
		'post_status'    => 'publish',
		'author'         => get_current_user_id(),
		'posts_per_page' => 10,
		'orderby'        => 'date',
		'order'          => 'DESC',
	) );
	$total_pages = 1;
	$base_url    = home_url( '/tecnico/' );
}

include __DIR__ . '/_partials/header.php';
?>

<div class="ct-container">

	<div class="ct-dashboard-hero">
		<div>
			<h1 class="ct-page-title">
				<?php
				echo esc_html( sprintf(
					/* translators: %s: nombre del técnico */
					__( 'Hola, %s', 'calibratrack' ),
					$user->display_name
				) );
				?>
			</h1>
			<p class="ct-text-muted"><?php echo esc_html( date_i18n( 'l j \d\e F \d\e Y' ) ); ?></p>
		</div>
		<a href="<?php echo esc_url( home_url( '/tecnico/nuevo-evento/' ) ); ?>" class="ct-btn ct-btn--primary ct-btn--large">
			<?php esc_html_e( '+ Registrar nuevo evento', 'calibratrack' ); ?>
		</a>
	</div>

	<?php if ( $aviso ) : ?>
		<div class="ct-alert ct-alert--success" role="alert"><?php echo esc_html( $aviso ); ?></div>
	<?php endif; ?>

	<section class="ct-section">
		<div class="ct-section-header">
			<h2 class="ct-section-title">
				<?php
				if ( $hay_filtros ) {
					esc_html_e( 'Resultados de búsqueda', 'calibratrack' );
				} else {
					esc_html_e( 'Últimos eventos', 'calibratrack' );
				}
				?>
			</h2>
			<?php if ( ! $hay_filtros ) : ?>
			<a href="<?php echo esc_url( home_url( '/tecnico/eventos/' ) ); ?>" class="ct-link">
				<?php esc_html_e( 'Ver todos →', 'calibratrack' ); ?>
			</a>
			<?php endif; ?>
		</div>

		<!-- Barra de búsqueda/filtros -->
		<form method="get" action="<?php echo esc_url( home_url( '/tecnico/' ) ); ?>" class="ct-filter-bar">
			<input
				type="text"
				name="buscar"
				value="<?php echo esc_attr( $buscar ); ?>"
				placeholder="<?php esc_attr_e( 'Buscar por N° OT o serie…', 'calibratrack' ); ?>"
				class="ct-input ct-filter-search"
			>
			<select name="tipo" class="ct-select ct-filter-select">
				<option value=""><?php esc_html_e( 'Todos los tipos', 'calibratrack' ); ?></option>
				<?php foreach ( $tipos_ev as $slug => $label ) : ?>
					<option value="<?php echo esc_attr( $slug ); ?>" <?php selected( $filtro_tipo, $slug ); ?>>
						<?php echo esc_html( $label ); ?>
					</option>
				<?php endforeach; ?>
			</select>
			<select name="estado" class="ct-select ct-filter-select">
				<option value=""><?php esc_html_e( 'Todos los estados', 'calibratrack' ); ?></option>
				<option value="vigente"    <?php selected( $filtro_estado, 'vigente' ); ?>><?php esc_html_e( 'Vigente', 'calibratrack' ); ?></option>
				<option value="por_vencer" <?php selected( $filtro_estado, 'por_vencer' ); ?>><?php esc_html_e( 'Por vencer', 'calibratrack' ); ?></option>
				<option value="vencido"    <?php selected( $filtro_estado, 'vencido' ); ?>><?php esc_html_e( 'Vencido', 'calibratrack' ); ?></option>
				<option value="sin_evento" <?php selected( $filtro_estado, 'sin_evento' ); ?>><?php esc_html_e( 'Sin fecha', 'calibratrack' ); ?></option>
			</select>
			<button type="submit" class="ct-btn ct-btn--primary"><?php esc_html_e( 'Filtrar', 'calibratrack' ); ?></button>
			<?php if ( $hay_filtros ) : ?>
				<a href="<?php echo esc_url( home_url( '/tecnico/' ) ); ?>" class="ct-btn ct-btn--secondary"><?php esc_html_e( 'Limpiar', 'calibratrack' ); ?></a>
			<?php endif; ?>
		</form>

		<?php if ( empty( $eventos ) ) : ?>
			<p class="ct-empty-msg">
				<?php
				if ( $hay_filtros ) {
					esc_html_e( 'No hay eventos que coincidan con los filtros aplicados.', 'calibratrack' );
				} else {
					esc_html_e( 'Aún no has registrado ningún evento.', 'calibratrack' );
				}
				?>
			</p>
		<?php else : ?>
		<div class="ct-table-wrap">
			<table class="ct-table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Fecha', 'calibratrack' ); ?></th>
						<th><?php esc_html_e( 'N° OT', 'calibratrack' ); ?></th>
						<th><?php esc_html_e( 'Equipo', 'calibratrack' ); ?></th>
						<th><?php esc_html_e( 'Tipo', 'calibratrack' ); ?></th>
						<th><?php esc_html_e( 'Estado', 'calibratrack' ); ?></th>
						<th></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $eventos as $ev ) :
						$equipo_id   = (int) get_post_meta( $ev->ID, CalibraTrack_Meta_Keys::EVENTO_EQUIPO_ID, true );
						$numero_ot   = get_post_meta( $ev->ID, CalibraTrack_Meta_Keys::EVENTO_NUMERO_OT, true );
						$tipo        = get_post_meta( $ev->ID, CalibraTrack_Meta_Keys::EVENTO_TIPO, true );
						$proxima     = get_post_meta( $ev->ID, CalibraTrack_Meta_Keys::EVENTO_PROXIMA_FECHA_CONTROL, true );
						$fecha_raw   = get_post_meta( $ev->ID, CalibraTrack_Meta_Keys::EVENTO_FECHA_EJECUCION, true );
						$serie       = $equipo_id ? get_post_meta( $equipo_id, CalibraTrack_Meta_Keys::EQUIPO_SERIE, true ) : '—';
						$marca       = $equipo_id ? get_post_meta( $equipo_id, CalibraTrack_Meta_Keys::EQUIPO_MARCA, true ) : '';
						$tipo_label  = isset( $tipos_ev[ $tipo ] ) ? $tipos_ev[ $tipo ] : $tipo;
						$estado      = $proxima ? CalibraTrack_Helpers::calcular_estado_vigencia( $proxima ) : 'sin_evento';
						$estados_cfg = array(
							'vigente'    => array( 'clase' => 'ct-badge--vigente',    'label' => __( 'Vigente', 'calibratrack' ) ),
							'por_vencer' => array( 'clase' => 'ct-badge--por-vencer', 'label' => __( 'Por vencer', 'calibratrack' ) ),
							'vencido'    => array( 'clase' => 'ct-badge--vencido',    'label' => __( 'Vencido', 'calibratrack' ) ),
							'sin_evento' => array( 'clase' => 'ct-badge--sin-evento', 'label' => __( 'Sin fecha', 'calibratrack' ) ),
						);
						$estado_info = isset( $estados_cfg[ $estado ] ) ? $estados_cfg[ $estado ] : $estados_cfg['sin_evento'];
						$dt          = $fecha_raw ? DateTime::createFromFormat( 'Y-m-d', $fecha_raw ) : null;
					?>
					<tr>
						<td data-label="<?php esc_attr_e( 'Fecha', 'calibratrack' ); ?>">
							<?php echo $dt ? esc_html( $dt->format( 'd/m/Y' ) ) : '—'; ?>
						</td>
						<td data-label="<?php esc_attr_e( 'N° OT', 'calibratrack' ); ?>">
							<?php echo esc_html( $numero_ot ?: '—' ); ?>
						</td>
						<td data-label="<?php esc_attr_e( 'Equipo', 'calibratrack' ); ?>">
							<?php echo esc_html( trim( $serie . ' ' . $marca ) ?: '—' ); ?>
						</td>
						<td data-label="<?php esc_attr_e( 'Tipo', 'calibratrack' ); ?>">
							<?php echo esc_html( $tipo_label ); ?>
						</td>
						<td data-label="<?php esc_attr_e( 'Estado', 'calibratrack' ); ?>">
							<span class="ct-badge <?php echo esc_attr( $estado_info['clase'] ); ?>">
								<?php echo esc_html( $estado_info['label'] ); ?>
							</span>
						</td>
						<td>
							<a href="<?php echo esc_url( home_url( '/tecnico/evento/' . $ev->ID . '/' ) ); ?>" class="ct-btn ct-btn--sm">
								<?php esc_html_e( 'Editar', 'calibratrack' ); ?>
							</a>
						</td>
					</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>

		<?php if ( $total_pages > 1 ) : ?>
		<nav class="ct-paginacion" aria-label="<?php esc_attr_e( 'Paginación', 'calibratrack' ); ?>">
			<?php for ( $p = 1; $p <= $total_pages; $p++ ) : ?>
				<a href="<?php echo esc_url( add_query_arg( 'pagina', $p, $base_url ) ); ?>"
					class="ct-btn ct-btn--sm <?php echo $p === $paged ? 'ct-btn--primary' : ''; ?>">
					<?php echo (int) $p; ?>
				</a>
			<?php endfor; ?>
		</nav>
		<?php endif; ?>
		<?php endif; ?>
	</section>

</div>

<?php include __DIR__ . '/_partials/footer.php'; ?>
