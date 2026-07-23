<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
$page_title = __( 'Equipos', 'calibratrack' );
$tipos      = CalibraTrack_Helpers::get_tipos_equipo();

// ─── Parámetros de filtro ─────────────────────────────────────────────────
$buscar       = isset( $_GET['buscar'] ) ? sanitize_text_field( wp_unslash( $_GET['buscar'] ) ) : '';
$filtro_tipo  = isset( $_GET['tipo'] )   ? sanitize_key( $_GET['tipo'] )   : '';
$filtro_estado = isset( $_GET['estado'] ) ? sanitize_key( $_GET['estado'] ) : '';
$paged        = max( 1, isset( $_GET['pagina'] ) ? absint( $_GET['pagina'] ) : 1 );
$per_page     = 20;

// ─── Construcción de WP_Query ─────────────────────────────────────────────
$query_args = array(
	'post_type'      => 'equipo',
	'post_status'    => 'publish',
	'posts_per_page' => $filtro_estado ? -1 : $per_page, // si filtra por estado, cargamos todos y paginamos en PHP
	'paged'          => $filtro_estado ? 1 : $paged,
	'orderby'        => 'title',
	'order'          => 'ASC',
	'meta_query'     => array( 'relation' => 'AND' ),
);

// Filtro por tipo de equipo.
if ( '' !== $filtro_tipo ) {
	$query_args['meta_query'][] = array(
		'key'     => CalibraTrack_Meta_Keys::EQUIPO_TIPO,
		'value'   => $filtro_tipo,
		'compare' => '=',
	);
}

// Búsqueda de texto: serie, marca, modelo.
if ( '' !== $buscar ) {
	$query_args['meta_query'][] = array(
		'relation' => 'OR',
		array(
			'key'     => CalibraTrack_Meta_Keys::EQUIPO_SERIE,
			'value'   => $buscar,
			'compare' => 'LIKE',
		),
		array(
			'key'     => CalibraTrack_Meta_Keys::EQUIPO_MARCA,
			'value'   => $buscar,
			'compare' => 'LIKE',
		),
		array(
			'key'     => CalibraTrack_Meta_Keys::EQUIPO_MODELO,
			'value'   => $buscar,
			'compare' => 'LIKE',
		),
	);
}

$query   = new WP_Query( $query_args );
$equipos = $query->posts;

// ─── Filtro por estado (PHP, requiere calcular vigencia por cada equipo) ───
if ( '' !== $filtro_estado && ! empty( $equipos ) ) {
	$equipos_filtrados = array();
	foreach ( $equipos as $eq ) {
		$ultimo_ev = CalibraTrack_DB::get_ultimo_evento( $eq->ID );
		$proxima   = $ultimo_ev ? (string) $ultimo_ev->proxima_fecha_control : '';
		$estado    = CalibraTrack_Helpers::calcular_estado_vigencia( $proxima );
		if ( $estado === $filtro_estado ) {
			$equipos_filtrados[] = $eq;
		}
	}
	$equipos         = $equipos_filtrados;
	$total_items     = count( $equipos );
	$total_pages     = (int) ceil( $total_items / $per_page );
	$offset          = ( $paged - 1 ) * $per_page;
	$equipos         = array_slice( $equipos, $offset, $per_page );
} else {
	$total_pages = $query->max_num_pages;
}

// URL base para los links de paginación (conservando filtros activos).
$base_url = add_query_arg( array_filter( array(
	'buscar' => $buscar ?: null,
	'tipo'   => $filtro_tipo ?: null,
	'estado' => $filtro_estado ?: null,
) ), home_url( '/tecnico/equipos/' ) );

include __DIR__ . '/_partials/header.php';
?>

<div class="ct-container">
	<div class="ct-page-header">
		<h1 class="ct-page-title"><?php esc_html_e( 'Equipos registrados', 'calibratrack' ); ?></h1>
	</div>

	<!-- Barra de filtros -->
	<form method="get" action="<?php echo esc_url( home_url( '/tecnico/equipos/' ) ); ?>" class="ct-filter-bar">
		<input
			type="text"
			name="buscar"
			value="<?php echo esc_attr( $buscar ); ?>"
			placeholder="<?php esc_attr_e( 'Buscar por serie, marca o modelo…', 'calibratrack' ); ?>"
			class="ct-input ct-filter-search"
		>
		<select name="tipo" class="ct-select ct-filter-select">
			<option value=""><?php esc_html_e( 'Todos los tipos', 'calibratrack' ); ?></option>
			<?php foreach ( $tipos as $slug => $label ) : ?>
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
			<option value="sin_evento" <?php selected( $filtro_estado, 'sin_evento' ); ?>><?php esc_html_e( 'Sin calibración', 'calibratrack' ); ?></option>
		</select>
		<button type="submit" class="ct-btn ct-btn--primary"><?php esc_html_e( 'Filtrar', 'calibratrack' ); ?></button>
		<?php if ( $buscar || $filtro_tipo || $filtro_estado ) : ?>
			<a href="<?php echo esc_url( home_url( '/tecnico/equipos/' ) ); ?>" class="ct-btn ct-btn--secondary"><?php esc_html_e( 'Limpiar', 'calibratrack' ); ?></a>
		<?php endif; ?>
	</form>

	<?php if ( empty( $equipos ) ) : ?>
		<p class="ct-empty-msg">
			<?php
			if ( $buscar || $filtro_tipo || $filtro_estado ) {
				esc_html_e( 'No hay equipos que coincidan con los filtros aplicados.', 'calibratrack' );
			} else {
				esc_html_e( 'No hay equipos registrados en el sistema.', 'calibratrack' );
			}
			?>
		</p>
	<?php else : ?>
	<div class="ct-table-wrap">
		<table class="ct-table">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Serie', 'calibratrack' ); ?></th>
					<th><?php esc_html_e( 'Marca / Modelo', 'calibratrack' ); ?></th>
					<th><?php esc_html_e( 'Tipo', 'calibratrack' ); ?></th>
					<th><?php esc_html_e( 'Cliente', 'calibratrack' ); ?></th>
					<th><?php esc_html_e( 'Estado', 'calibratrack' ); ?></th>
					<th></th>
				</tr>
			</thead>
			<tbody>
			<?php foreach ( $equipos as $eq ) :
				$serie      = get_post_meta( $eq->ID, CalibraTrack_Meta_Keys::EQUIPO_SERIE, true );
				$marca      = get_post_meta( $eq->ID, CalibraTrack_Meta_Keys::EQUIPO_MARCA, true );
				$modelo     = get_post_meta( $eq->ID, CalibraTrack_Meta_Keys::EQUIPO_MODELO, true );
				$tipo_slug  = get_post_meta( $eq->ID, CalibraTrack_Meta_Keys::EQUIPO_TIPO, true );
				$tipo_label = isset( $tipos[ $tipo_slug ] ) ? $tipos[ $tipo_slug ] : $tipo_slug;
				$cliente_id = (int) get_post_meta( $eq->ID, CalibraTrack_Meta_Keys::EQUIPO_CLIENTE_PROPIETARIO, true );
				$cliente    = $cliente_id ? get_post_meta( $cliente_id, CalibraTrack_Meta_Keys::CLIENTE_NOMBRE_EMPRESA, true ) : '—';
				$ultimo_ev  = CalibraTrack_DB::get_ultimo_evento( $eq->ID );
				$proxima    = $ultimo_ev ? (string) $ultimo_ev->proxima_fecha_control : '';
				$estado     = CalibraTrack_Helpers::calcular_estado_vigencia( $proxima );
				$estados_cfg = array(
					'vigente'    => array( 'clase' => 'ct-badge--vigente',    'label' => __( 'Vigente', 'calibratrack' ) ),
					'por_vencer' => array( 'clase' => 'ct-badge--por-vencer', 'label' => __( 'Por vencer', 'calibratrack' ) ),
					'vencido'    => array( 'clase' => 'ct-badge--vencido',    'label' => __( 'Vencido', 'calibratrack' ) ),
					'sin_evento' => array( 'clase' => 'ct-badge--sin-evento', 'label' => __( 'Sin calibración', 'calibratrack' ) ),
				);
				$estado_info = isset( $estados_cfg[ $estado ] ) ? $estados_cfg[ $estado ] : $estados_cfg['sin_evento'];
			?>
			<tr>
				<td data-label="<?php esc_attr_e( 'Serie', 'calibratrack' ); ?>"><code><?php echo esc_html( $serie ); ?></code></td>
				<td data-label="<?php esc_attr_e( 'Marca / Modelo', 'calibratrack' ); ?>"><?php echo esc_html( $marca . ' ' . $modelo ); ?></td>
				<td data-label="<?php esc_attr_e( 'Tipo', 'calibratrack' ); ?>"><?php echo esc_html( $tipo_label ); ?></td>
				<td data-label="<?php esc_attr_e( 'Cliente', 'calibratrack' ); ?>"><?php echo esc_html( $cliente ); ?></td>
				<td data-label="<?php esc_attr_e( 'Estado', 'calibratrack' ); ?>">
					<span class="ct-badge <?php echo esc_attr( $estado_info['clase'] ); ?>">
						<?php echo esc_html( $estado_info['label'] ); ?>
					</span>
				</td>
				<td>
					<a href="<?php echo esc_url( home_url( '/verificar/' . rawurlencode( $serie ) . '/' ) ); ?>" target="_blank" class="ct-btn ct-btn--sm">
						<?php esc_html_e( 'Ver verificación', 'calibratrack' ); ?>
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
</div>

<?php include __DIR__ . '/_partials/footer.php'; ?>
