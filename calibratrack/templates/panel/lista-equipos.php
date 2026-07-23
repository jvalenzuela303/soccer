<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
$page_title = __( 'Equipos', 'calibratrack' );
$tipos      = CalibraTrack_Helpers::get_tipos_equipo();

$buscar        = isset( $_GET['buscar'] ) ? sanitize_text_field( wp_unslash( $_GET['buscar'] ) ) : '';
$filtro_tipo   = isset( $_GET['tipo'] )   ? sanitize_key( $_GET['tipo'] )   : '';
$filtro_estado = isset( $_GET['estado'] ) ? sanitize_key( $_GET['estado'] ) : '';
$paged         = max( 1, isset( $_GET['pagina'] ) ? absint( $_GET['pagina'] ) : 1 );
$per_page      = 20;

// ── 1. Query base (IDs solamente — evita traer postmeta en esta etapa) ─────
$query_args = array(
	'post_type'      => 'equipo',
	'post_status'    => 'publish',
	'posts_per_page' => -1,        // Necesitamos todos los IDs para filtrar por estado.
	'fields'         => 'ids',     // Solo IDs: más rápido, sin JOIN a postmeta.
	'orderby'        => 'title',
	'order'          => 'ASC',
	'meta_query'     => array( 'relation' => 'AND' ),
	'no_found_rows'  => true,      // Omitir COUNT(*) — paginamos manualmente.
	'update_post_term_cache' => false,
);

if ( '' !== $filtro_tipo ) {
	$query_args['meta_query'][] = array(
		'key'     => CalibraTrack_Meta_Keys::EQUIPO_TIPO,
		'value'   => $filtro_tipo,
		'compare' => '=',
	);
}

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

$todos_ids = ( new WP_Query( $query_args ) )->posts; // array de int IDs

// ── 2. Filtro por estado (1 query batch en vez de N queries) ────────────────
if ( '' !== $filtro_estado && ! empty( $todos_ids ) ) {
	$ultimos = CalibraTrack_DB::get_ultimo_evento_batch( $todos_ids );
	$ids_filtrados = array();
	foreach ( $todos_ids as $eid ) {
		$ultimo  = isset( $ultimos[ $eid ] ) ? $ultimos[ $eid ] : null;
		$proxima = $ultimo ? (string) $ultimo->proxima_fecha_control : '';
		if ( CalibraTrack_Helpers::calcular_estado_vigencia( $proxima ) === $filtro_estado ) {
			$ids_filtrados[] = $eid;
		}
	}
	$todos_ids = $ids_filtrados;
} else {
	// Pre-cargar último evento en batch para el loop de renderizado (evita N queries).
	$ultimos = CalibraTrack_DB::get_ultimo_evento_batch( $todos_ids );
}

// ── 3. Paginación manual ────────────────────────────────────────────────────
$total_items = count( $todos_ids );
$total_pages = (int) ceil( $total_items / $per_page );
$paged       = min( $paged, max( 1, $total_pages ) );
$offset      = ( $paged - 1 ) * $per_page;
$ids_pagina  = array_slice( $todos_ids, $offset, $per_page );

// ── 4. Cargar posts + cachear postmeta en 1 sola query ─────────────────────
$equipos = array();
if ( ! empty( $ids_pagina ) ) {
	$equipos = get_posts( array(
		'post_type'              => 'equipo',
		'post__in'               => $ids_pagina,
		'orderby'                => 'post__in',
		'posts_per_page'         => count( $ids_pagina ),
		'no_found_rows'          => true,
		'update_post_term_cache' => false,
	) );
	// Pre-carga TODA la postmeta de estos equipos en 1 query → get_post_meta() usa caché.
	update_postmeta_cache( $ids_pagina );

	// Pre-cargar también postmeta de clientes para evitar N queries de nombre_empresa.
	$cliente_ids = array();
	foreach ( $ids_pagina as $eid ) {
		$cid = (int) get_post_meta( $eid, CalibraTrack_Meta_Keys::EQUIPO_CLIENTE_PROPIETARIO, true );
		if ( $cid > 0 ) {
			$cliente_ids[] = $cid;
		}
	}
	if ( ! empty( $cliente_ids ) ) {
		update_postmeta_cache( array_unique( $cliente_ids ) );
	}
}

$base_url = add_query_arg( array_filter( array(
	'buscar' => $buscar ?: null,
	'tipo'   => $filtro_tipo ?: null,
	'estado' => $filtro_estado ?: null,
) ), home_url( '/panel/equipos/' ) );

$guardado_eq = isset( $_GET['guardado'] ) ? (int) $_GET['guardado'] : 0;

include __DIR__ . '/_partials/header.php';
?>

<div class="ct-container">
	<?php if ( 1 === $guardado_eq ) : ?>
		<div style="background:#d1fae5;border:1px solid #6ee7b7;border-radius:6px;padding:12px 16px;margin-bottom:16px;color:#065f46;">
			<?php esc_html_e( 'Equipo creado correctamente.', 'calibratrack' ); ?>
		</div>
	<?php elseif ( 2 === $guardado_eq ) : ?>
		<div style="background:#d1fae5;border:1px solid #6ee7b7;border-radius:6px;padding:12px 16px;margin-bottom:16px;color:#065f46;">
			<?php esc_html_e( 'Equipo actualizado correctamente.', 'calibratrack' ); ?>
		</div>
	<?php endif; ?>

	<div class="ct-page-header" style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px;">
		<h1 class="ct-page-title"><?php esc_html_e( 'Equipos registrados', 'calibratrack' ); ?></h1>
		<?php if ( current_user_can( 'manage_options' ) ) : ?>
		<a href="<?php echo esc_url( home_url( '/panel/equipo/nuevo/' ) ); ?>" class="ct-btn ct-btn--primary">
			<?php esc_html_e( '+ Nuevo Equipo', 'calibratrack' ); ?>
		</a>
		<?php endif; ?>
	</div>

	<form method="get" action="<?php echo esc_url( home_url( '/panel/equipos/' ) ); ?>" class="ct-filter-bar">
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
			<a href="<?php echo esc_url( home_url( '/panel/equipos/' ) ); ?>" class="ct-btn ct-btn--secondary"><?php esc_html_e( 'Limpiar', 'calibratrack' ); ?></a>
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
			<?php
			$estados_cfg = array(
				'vigente'    => array( 'clase' => 'ct-badge--vigente',    'label' => __( 'Vigente', 'calibratrack' ) ),
				'por_vencer' => array( 'clase' => 'ct-badge--por-vencer', 'label' => __( 'Por vencer', 'calibratrack' ) ),
				'vencido'    => array( 'clase' => 'ct-badge--vencido',    'label' => __( 'Vencido', 'calibratrack' ) ),
				'sin_evento' => array( 'clase' => 'ct-badge--sin-evento', 'label' => __( 'Sin calibración', 'calibratrack' ) ),
			);
			foreach ( $equipos as $eq ) :
				// Todos los get_post_meta() usan caché cargado arriba (0 queries extra).
				$serie      = get_post_meta( $eq->ID, CalibraTrack_Meta_Keys::EQUIPO_SERIE, true );
				$marca      = get_post_meta( $eq->ID, CalibraTrack_Meta_Keys::EQUIPO_MARCA, true );
				$modelo     = get_post_meta( $eq->ID, CalibraTrack_Meta_Keys::EQUIPO_MODELO, true );
				$tipo_slug  = get_post_meta( $eq->ID, CalibraTrack_Meta_Keys::EQUIPO_TIPO, true );
				$tipo_label = isset( $tipos[ $tipo_slug ] ) ? $tipos[ $tipo_slug ] : $tipo_slug;
				$cliente_id = (int) get_post_meta( $eq->ID, CalibraTrack_Meta_Keys::EQUIPO_CLIENTE_PROPIETARIO, true );
				$cliente    = $cliente_id ? get_post_meta( $cliente_id, CalibraTrack_Meta_Keys::CLIENTE_NOMBRE_EMPRESA, true ) : '—';
				if ( empty( $cliente ) ) { $cliente = '—'; }

				// Último evento desde el batch pre-cargado (0 queries extra).
				$ultimo  = isset( $ultimos[ $eq->ID ] ) ? $ultimos[ $eq->ID ] : null;
				$proxima = $ultimo ? (string) $ultimo->proxima_fecha_control : '';
				$estado  = CalibraTrack_Helpers::calcular_estado_vigencia( $proxima );

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
				<td style="white-space:nowrap;">
					<a href="<?php echo esc_url( home_url( '/verificar/' . rawurlencode( $serie ) . '/' ) ); ?>" target="_blank" class="ct-btn ct-btn--sm">
						<?php esc_html_e( 'Ver', 'calibratrack' ); ?>
					</a>
					<?php if ( current_user_can( 'manage_options' ) ) : ?>
					<a href="<?php echo esc_url( home_url( '/panel/equipo/' . $eq->ID . '/' ) ); ?>" class="ct-btn ct-btn--sm" style="margin-left:4px;">
						<?php esc_html_e( 'Editar', 'calibratrack' ); ?>
					</a>
					<?php endif; ?>
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
