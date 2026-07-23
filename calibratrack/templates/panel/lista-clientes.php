<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
$page_title = __( 'Clientes', 'calibratrack' );

$buscar = isset( $_GET['buscar'] ) ? sanitize_text_field( wp_unslash( $_GET['buscar'] ) ) : '';
$paged  = max( 1, isset( $_GET['pagina'] ) ? absint( $_GET['pagina'] ) : 1 );
$per_page = 20;

// Query clientes
$query_args = array(
	'post_type'              => 'cliente',
	'post_status'            => 'publish',
	'posts_per_page'         => -1,
	'fields'                 => 'ids',
	'orderby'                => 'title',
	'order'                  => 'ASC',
	'no_found_rows'          => true,
	'update_post_term_cache' => false,
);

if ( '' !== $buscar ) {
	$query_args['meta_query'] = array(
		'relation' => 'OR',
		array(
			'key'     => CalibraTrack_Meta_Keys::CLIENTE_NOMBRE_EMPRESA,
			'value'   => $buscar,
			'compare' => 'LIKE',
		),
		array(
			'key'     => CalibraTrack_Meta_Keys::CLIENTE_RUT,
			'value'   => $buscar,
			'compare' => 'LIKE',
		),
	);
}

$todos_ids   = ( new WP_Query( $query_args ) )->posts;
$total_items = count( $todos_ids );
$total_pages = (int) ceil( $total_items / $per_page );
$paged       = min( $paged, max( 1, $total_pages ) );
$offset      = ( $paged - 1 ) * $per_page;
$ids_pagina  = array_slice( $todos_ids, $offset, $per_page );

$clientes = array();
if ( ! empty( $ids_pagina ) ) {
	$clientes = get_posts( array(
		'post_type'              => 'cliente',
		'post__in'               => $ids_pagina,
		'orderby'                => 'post__in',
		'posts_per_page'         => count( $ids_pagina ),
		'no_found_rows'          => true,
		'update_post_term_cache' => false,
	) );
	update_postmeta_cache( $ids_pagina );
}

$base_url = add_query_arg( array_filter( array(
	'buscar' => $buscar ?: null,
) ), home_url( '/panel/clientes/' ) );

$guardado = isset( $_GET['guardado'] ) ? (int) $_GET['guardado'] : 0;

include __DIR__ . '/_partials/header.php';
?>

<div class="ct-container">
	<?php if ( 1 === $guardado ) : ?>
		<div style="background:#d1fae5;border:1px solid #6ee7b7;border-radius:6px;padding:12px 16px;margin-bottom:16px;color:#065f46;">
			<?php esc_html_e( 'Cliente creado correctamente.', 'calibratrack' ); ?>
		</div>
	<?php elseif ( 2 === $guardado ) : ?>
		<div style="background:#d1fae5;border:1px solid #6ee7b7;border-radius:6px;padding:12px 16px;margin-bottom:16px;color:#065f46;">
			<?php esc_html_e( 'Cliente actualizado correctamente.', 'calibratrack' ); ?>
		</div>
	<?php endif; ?>

	<div class="ct-page-header" style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px;">
		<h1 class="ct-page-title"><?php esc_html_e( 'Clientes registrados', 'calibratrack' ); ?></h1>
		<a href="<?php echo esc_url( home_url( '/panel/cliente/nuevo/' ) ); ?>" class="ct-btn ct-btn--primary">
			<?php esc_html_e( '+ Nuevo Cliente', 'calibratrack' ); ?>
		</a>
	</div>

	<form method="get" action="<?php echo esc_url( home_url( '/panel/clientes/' ) ); ?>" class="ct-filter-bar">
		<input
			type="text"
			name="buscar"
			value="<?php echo esc_attr( $buscar ); ?>"
			placeholder="<?php esc_attr_e( 'Buscar por nombre o RUT…', 'calibratrack' ); ?>"
			class="ct-input ct-filter-search"
		>
		<button type="submit" class="ct-btn ct-btn--primary"><?php esc_html_e( 'Buscar', 'calibratrack' ); ?></button>
		<?php if ( $buscar ) : ?>
			<a href="<?php echo esc_url( home_url( '/panel/clientes/' ) ); ?>" class="ct-btn ct-btn--secondary"><?php esc_html_e( 'Limpiar', 'calibratrack' ); ?></a>
		<?php endif; ?>
	</form>

	<?php if ( empty( $clientes ) ) : ?>
		<p class="ct-empty-msg">
			<?php
			if ( $buscar ) {
				esc_html_e( 'No hay clientes que coincidan con la búsqueda.', 'calibratrack' );
			} else {
				esc_html_e( 'No hay clientes registrados en el sistema.', 'calibratrack' );
			}
			?>
		</p>
	<?php else : ?>
	<div class="ct-table-wrap">
		<table class="ct-table">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Empresa', 'calibratrack' ); ?></th>
					<th><?php esc_html_e( 'RUT', 'calibratrack' ); ?></th>
					<th><?php esc_html_e( 'Contacto', 'calibratrack' ); ?></th>
					<th><?php esc_html_e( 'Teléfono', 'calibratrack' ); ?></th>
					<th><?php esc_html_e( 'Correo', 'calibratrack' ); ?></th>
					<th></th>
				</tr>
			</thead>
			<tbody>
			<?php foreach ( $clientes as $cl ) : ?>
			<tr>
				<td data-label="<?php esc_attr_e( 'Empresa', 'calibratrack' ); ?>">
					<strong><?php echo esc_html( get_post_meta( $cl->ID, CalibraTrack_Meta_Keys::CLIENTE_NOMBRE_EMPRESA, true ) ?: get_the_title( $cl->ID ) ); ?></strong>
				</td>
				<td data-label="<?php esc_attr_e( 'RUT', 'calibratrack' ); ?>">
					<code><?php echo esc_html( get_post_meta( $cl->ID, CalibraTrack_Meta_Keys::CLIENTE_RUT, true ) ?: '—' ); ?></code>
				</td>
				<td data-label="<?php esc_attr_e( 'Contacto', 'calibratrack' ); ?>">
					<?php echo esc_html( get_post_meta( $cl->ID, CalibraTrack_Meta_Keys::CLIENTE_CONTACTO_NOMBRE, true ) ?: '—' ); ?>
				</td>
				<td data-label="<?php esc_attr_e( 'Teléfono', 'calibratrack' ); ?>">
					<?php echo esc_html( get_post_meta( $cl->ID, CalibraTrack_Meta_Keys::CLIENTE_TELEFONO, true ) ?: '—' ); ?>
				</td>
				<td data-label="<?php esc_attr_e( 'Correo', 'calibratrack' ); ?>">
					<?php
					$correo = get_post_meta( $cl->ID, CalibraTrack_Meta_Keys::CLIENTE_CORREO, true );
					if ( $correo ) {
						echo '<a href="mailto:' . esc_attr( $correo ) . '" style="color:inherit;">' . esc_html( $correo ) . '</a>';
					} else {
						echo '—';
					}
					?>
				</td>
				<td style="white-space:nowrap;">
					<a href="<?php echo esc_url( home_url( '/panel/cliente/' . $cl->ID . '/' ) ); ?>" class="ct-btn ct-btn--sm">
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
</div>

<?php include __DIR__ . '/_partials/footer.php'; ?>
