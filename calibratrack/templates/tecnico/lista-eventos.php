<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
$page_title  = __( 'Mis eventos', 'calibratrack' );
$paged       = max( 1, isset( $_GET['pagina'] ) ? absint( $_GET['pagina'] ) : 1 );
$per_page    = 20;
$query = new WP_Query( array(
	'post_type'      => 'evento_servicio',
	'post_status'    => 'publish',
	'author'         => get_current_user_id(),
	'posts_per_page' => $per_page,
	'paged'          => $paged,
	'orderby'        => 'date',
	'order'          => 'DESC',
	// Mostrar solo OTs (o eventos sin tipo_documento para compatibilidad con registros anteriores).
	'meta_query'     => array(
		'relation' => 'OR',
		array(
			'key'   => CalibraTrack_Meta_Keys::EVENTO_TIPO_DOCUMENTO,
			'value' => 'ot',
		),
		array(
			'key'     => CalibraTrack_Meta_Keys::EVENTO_TIPO_DOCUMENTO,
			'compare' => 'NOT EXISTS',
		),
	),
) );
$total_pages = $query->max_num_pages;
include __DIR__ . '/_partials/header.php';
?>

<div class="ct-container">
	<div class="ct-page-header">
		<h1 class="ct-page-title"><?php esc_html_e( 'Mis órdenes de trabajo', 'calibratrack' ); ?></h1>
	</div>

	<?php if ( ! $query->have_posts() ) : ?>
		<p class="ct-empty-msg"><?php esc_html_e( 'Aún no has registrado ningún evento.', 'calibratrack' ); ?></p>
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
			<?php while ( $query->have_posts() ) : $query->the_post();
				$ev_id      = get_the_ID();
				$equipo_id  = (int) get_post_meta( $ev_id, CalibraTrack_Meta_Keys::EVENTO_EQUIPO_ID, true );
				$numero_ot  = get_post_meta( $ev_id, CalibraTrack_Meta_Keys::EVENTO_NUMERO_OT, true );
				$tipo       = get_post_meta( $ev_id, CalibraTrack_Meta_Keys::EVENTO_TIPO, true );
				$proxima    = get_post_meta( $ev_id, CalibraTrack_Meta_Keys::EVENTO_PROXIMA_FECHA_CONTROL, true );
				$fecha_raw  = get_post_meta( $ev_id, CalibraTrack_Meta_Keys::EVENTO_FECHA_EJECUCION, true );
				$cert_id    = (int) get_post_meta( $ev_id, CalibraTrack_Meta_Keys::EVENTO_CERTIFICADO_PDF, true );
				$serie      = $equipo_id ? get_post_meta( $equipo_id, CalibraTrack_Meta_Keys::EQUIPO_SERIE, true ) : '—';
				$tipos      = CalibraTrack_Helpers::get_tipos_evento();
				$tipo_label = isset( $tipos[ $tipo ] ) ? $tipos[ $tipo ] : $tipo;
				$estado     = $proxima ? CalibraTrack_Helpers::calcular_estado_vigencia( $proxima ) : 'sin_evento';
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
				<td data-label="<?php esc_attr_e( 'Fecha', 'calibratrack' ); ?>"><?php echo $dt ? esc_html( $dt->format( 'd/m/Y' ) ) : '—'; ?></td>
				<td data-label="<?php esc_attr_e( 'N° OT', 'calibratrack' ); ?>"><?php echo esc_html( $numero_ot ?: '—' ); ?></td>
				<td data-label="<?php esc_attr_e( 'Equipo', 'calibratrack' ); ?>"><?php echo esc_html( $serie ); ?></td>
				<td data-label="<?php esc_attr_e( 'Tipo', 'calibratrack' ); ?>"><?php echo esc_html( $tipo_label ); ?></td>
				<td data-label="<?php esc_attr_e( 'Estado', 'calibratrack' ); ?>">
					<span class="ct-badge <?php echo esc_attr( $estado_info['clase'] ); ?>">
						<?php echo esc_html( $estado_info['label'] ); ?>
					</span>
				</td>
				<td>
					<a href="<?php echo esc_url( home_url( '/tecnico/evento/' . $ev_id . '/' ) ); ?>" class="ct-btn ct-btn--sm">
						<?php esc_html_e( 'Editar', 'calibratrack' ); ?>
					</a>
				</td>
			</tr>
			<?php endwhile; wp_reset_postdata(); ?>
			</tbody>
		</table>
	</div>

	<?php if ( $total_pages > 1 ) : ?>
	<nav class="ct-paginacion" aria-label="<?php esc_attr_e( 'Paginación', 'calibratrack' ); ?>">
		<?php for ( $p = 1; $p <= $total_pages; $p++ ) : ?>
			<a href="<?php echo esc_url( add_query_arg( 'pagina', $p ) ); ?>"
				class="ct-btn ct-btn--sm <?php echo $p === $paged ? 'ct-btn--primary' : ''; ?>">
				<?php echo (int) $p; ?>
			</a>
		<?php endfor; ?>
	</nav>
	<?php endif; ?>
	<?php endif; ?>
</div>

<?php include __DIR__ . '/_partials/footer.php'; ?>
