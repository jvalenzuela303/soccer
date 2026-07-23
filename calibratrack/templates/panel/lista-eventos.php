<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

$es_admin    = current_user_can( 'manage_options' );
$page_title  = $es_admin ? __( 'Órdenes de Trabajo', 'calibratrack' ) : __( 'Mis órdenes de trabajo', 'calibratrack' );
$paged       = max( 1, isset( $_GET['pagina'] ) ? absint( $_GET['pagina'] ) : 1 );
$per_page    = 20;
$tipos_ev    = CalibraTrack_Helpers::get_tipos_evento();

// ── Filtros de búsqueda ──────────────────────────────────────────────────────
$buscar        = isset( $_GET['buscar'] )  ? sanitize_text_field( wp_unslash( $_GET['buscar'] ) )  : '';
$filtro_tipo   = isset( $_GET['tipo'] )    ? sanitize_key( wp_unslash( $_GET['tipo'] ) )            : '';
$filtro_tec    = isset( $_GET['tecnico'] ) ? absint( $_GET['tecnico'] )                             : 0;
$filtro_estado = isset( $_GET['estado'] )  ? sanitize_key( wp_unslash( $_GET['estado'] ) )          : '';
$hay_filtros   = ( '' !== $buscar || '' !== $filtro_tipo || $filtro_tec > 0 || '' !== $filtro_estado );

// ── Query ────────────────────────────────────────────────────────────────────
$meta_q = array(
	'relation' => 'AND',
	array(
		'key'   => CalibraTrack_Meta_Keys::EVENTO_TIPO_DOCUMENTO,
		'value' => 'ot',
	),
);

if ( '' !== $filtro_tipo ) {
	$meta_q[] = array(
		'key'     => CalibraTrack_Meta_Keys::EVENTO_TIPO,
		'value'   => $filtro_tipo,
		'compare' => '=',
	);
}

if ( $filtro_tec > 0 ) {
	$meta_q[] = array(
		'key'     => CalibraTrack_Meta_Keys::EVENTO_TECNICO_RESPONSABLE,
		'value'   => $filtro_tec,
		'compare' => '=',
		'type'    => 'NUMERIC',
	);
}

if ( '' !== $filtro_estado ) {
	$hoy        = gmdate( 'Y-m-d' );
	$hoy_plus30 = gmdate( 'Y-m-d', strtotime( '+30 days' ) );
	switch ( $filtro_estado ) {
		case 'vigente':
			$meta_q[] = array(
				'key'     => CalibraTrack_Meta_Keys::EVENTO_PROXIMA_FECHA_CONTROL,
				'value'   => $hoy_plus30,
				'compare' => '>',
				'type'    => 'DATE',
			);
			break;
		case 'por_vencer':
			$meta_q[] = array(
				'key'     => CalibraTrack_Meta_Keys::EVENTO_PROXIMA_FECHA_CONTROL,
				'value'   => array( $hoy, $hoy_plus30 ),
				'compare' => 'BETWEEN',
				'type'    => 'DATE',
			);
			break;
		case 'vencido':
			$meta_q[] = array(
				'key'     => CalibraTrack_Meta_Keys::EVENTO_PROXIMA_FECHA_CONTROL,
				'value'   => $hoy,
				'compare' => '<',
				'type'    => 'DATE',
			);
			break;
	}
}

if ( '' !== $buscar ) {
	$equipos_ids = get_posts( array(
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
	) );
	$buscar_sub = array( 'relation' => 'OR' );
	if ( ! empty( $equipos_ids ) ) {
		$buscar_sub[] = array(
			'key'     => CalibraTrack_Meta_Keys::EVENTO_EQUIPO_ID,
			'value'   => $equipos_ids,
			'compare' => 'IN',
			'type'    => 'NUMERIC',
		);
	}
	$buscar_sub[] = array(
		'key'     => CalibraTrack_Meta_Keys::EVENTO_NUMERO_OT,
		'value'   => $buscar,
		'compare' => 'LIKE',
	);
	$meta_q[] = $buscar_sub;
}

$query_args = array(
	'post_type'      => CalibraTrack_CPT_EventoServicio::SLUG,
	'post_status'    => 'publish',
	'posts_per_page' => $per_page,
	'paged'          => $paged,
	'orderby'        => 'date',
	'order'          => 'DESC',
	'meta_query'     => $meta_q,
);

// El técnico solo ve OTs donde es el responsable asignado.
if ( ! $es_admin ) {
	$query_args['meta_query'][] = array(
		'key'     => CalibraTrack_Meta_Keys::EVENTO_TECNICO_RESPONSABLE,
		'value'   => get_current_user_id(),
		'compare' => '=',
		'type'    => 'NUMERIC',
	);
}

$query       = new WP_Query( $query_args );
$eventos     = $query->posts;
$total_pages = $query->max_num_pages;

// ── Pre-carga de postmeta en batch (evita N+1 queries en el loop) ────────────
if ( ! empty( $eventos ) ) {
	$ev_ids = wp_list_pluck( $eventos, 'ID' );
	update_postmeta_cache( $ev_ids ); // 1 query: toda la meta de eventos.

	// Recolectar IDs de equipos y OIs para pre-cargarlos también.
	$equipo_ids_ev = array();
	$oi_ids_ev     = array();
	foreach ( $ev_ids as $ev_id ) {
		$eid = (int) get_post_meta( $ev_id, CalibraTrack_Meta_Keys::EVENTO_EQUIPO_ID, true );
		if ( $eid > 0 ) { $equipo_ids_ev[] = $eid; }
		$oid = (int) get_post_meta( $ev_id, CalibraTrack_Meta_Keys::EVENTO_INGRESO_RELACIONADO_ID, true );
		if ( $oid > 0 ) { $oi_ids_ev[] = $oid; }
	}
	if ( ! empty( $equipo_ids_ev ) ) {
		update_postmeta_cache( array_unique( $equipo_ids_ev ) ); // 1 query: series de equipos.
	}
	if ( ! empty( $oi_ids_ev ) ) {
		update_postmeta_cache( array_unique( $oi_ids_ev ) ); // 1 query: meta de OIs vinculadas.
	}
}

// URL base para paginación (preserva filtros activos).
$base_url = add_query_arg( array_filter( array(
	'buscar'  => $buscar ?: null,
	'tipo'    => $filtro_tipo ?: null,
	'tecnico' => $filtro_tec ?: null,
	'estado'  => $filtro_estado ?: null,
) ), home_url( '/panel/eventos/' ) );

// Técnicos para el select de filtro (solo admin).
$tecnicos_select = $es_admin ? CalibraTrack_Helpers::get_tecnicos_para_select() : array();

$estados_servicio_cfg = CalibraTrack_Helpers::get_estados_servicio();

include __DIR__ . '/_partials/header.php';
?>

<div class="ct-container">
	<div class="ct-section-header" style="margin-bottom:20px;">
		<h1 class="ct-page-title"><?php echo esc_html( $page_title ); ?></h1>
	</div>

	<!-- Barra de búsqueda y filtros -->
	<form method="get" action="<?php echo esc_url( home_url( '/panel/eventos/' ) ); ?>" class="ct-filter-bar" style="margin-bottom:20px;">
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
		<?php if ( $es_admin && ! empty( $tecnicos_select ) ) : ?>
		<select name="tecnico" class="ct-select ct-filter-select">
			<option value=""><?php esc_html_e( 'Todos los técnicos', 'calibratrack' ); ?></option>
			<?php foreach ( $tecnicos_select as $tec ) : ?>
				<option value="<?php echo esc_attr( $tec->ID ); ?>" <?php selected( $filtro_tec, $tec->ID ); ?>>
					<?php echo esc_html( $tec->display_name ); ?>
				</option>
			<?php endforeach; ?>
		</select>
		<?php endif; ?>
		<select name="estado" class="ct-select ct-filter-select">
			<option value=""><?php esc_html_e( 'Todos los estados', 'calibratrack' ); ?></option>
			<option value="vigente"    <?php selected( $filtro_estado, 'vigente' ); ?>><?php esc_html_e( 'Vigente', 'calibratrack' ); ?></option>
			<option value="por_vencer" <?php selected( $filtro_estado, 'por_vencer' ); ?>><?php esc_html_e( 'Por vencer', 'calibratrack' ); ?></option>
			<option value="vencido"    <?php selected( $filtro_estado, 'vencido' ); ?>><?php esc_html_e( 'Vencido', 'calibratrack' ); ?></option>
		</select>
		<button type="submit" class="ct-btn ct-btn--primary"><?php esc_html_e( 'Filtrar', 'calibratrack' ); ?></button>
		<?php if ( $hay_filtros ) : ?>
			<a href="<?php echo esc_url( home_url( '/panel/eventos/' ) ); ?>" class="ct-btn ct-btn--secondary">
				<?php esc_html_e( 'Limpiar', 'calibratrack' ); ?>
			</a>
		<?php endif; ?>
	</form>

	<?php if ( empty( $eventos ) ) : ?>
		<p class="ct-empty-msg">
			<?php
			if ( $hay_filtros ) {
				esc_html_e( 'No hay órdenes de trabajo que coincidan con los filtros aplicados.', 'calibratrack' );
			} else {
				esc_html_e( 'No hay órdenes de trabajo registradas.', 'calibratrack' );
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
					<th><?php esc_html_e( 'Técnico', 'calibratrack' ); ?></th>
					<th><?php esc_html_e( 'Tipo', 'calibratrack' ); ?></th>
					<th><?php esc_html_e( 'Estado', 'calibratrack' ); ?></th>
					<th><?php esc_html_e( 'OI vinculada', 'calibratrack' ); ?></th>
					<th></th>
				</tr>
			</thead>
			<tbody>
			<?php foreach ( $eventos as $ev ) :
				$equipo_id   = (int) get_post_meta( $ev->ID, CalibraTrack_Meta_Keys::EVENTO_EQUIPO_ID, true );
				$tecnico_id  = (int) get_post_meta( $ev->ID, CalibraTrack_Meta_Keys::EVENTO_TECNICO_RESPONSABLE, true );
				$numero_ot   = (string) get_post_meta( $ev->ID, CalibraTrack_Meta_Keys::EVENTO_NUMERO_OT, true );
				$tipo        = (string) get_post_meta( $ev->ID, CalibraTrack_Meta_Keys::EVENTO_TIPO, true );
				$proxima     = (string) get_post_meta( $ev->ID, CalibraTrack_Meta_Keys::EVENTO_PROXIMA_FECHA_CONTROL, true );
				$fecha_raw   = (string) get_post_meta( $ev->ID, CalibraTrack_Meta_Keys::EVENTO_FECHA_EJECUCION, true );
				$estado_srv  = (string) get_post_meta( $ev->ID, CalibraTrack_Meta_Keys::EVENTO_ESTADO_SERVICIO, true );
				$oi_id       = (int) get_post_meta( $ev->ID, CalibraTrack_Meta_Keys::EVENTO_INGRESO_RELACIONADO_ID, true );
				$serie       = $equipo_id ? (string) get_post_meta( $equipo_id, CalibraTrack_Meta_Keys::EQUIPO_SERIE, true ) : '—';
				$tecnico     = $tecnico_id ? get_userdata( $tecnico_id ) : null;
				$tecnico_nom = $tecnico ? $tecnico->display_name : '—';
				$tipo_label  = isset( $tipos_ev[ $tipo ] ) ? $tipos_ev[ $tipo ] : $tipo;
				$estado_srv  = '' !== $estado_srv ? $estado_srv : 'en_proceso';
				$est_cfg     = isset( $estados_servicio_cfg[ $estado_srv ] ) ? $estados_servicio_cfg[ $estado_srv ] : $estados_servicio_cfg['en_proceso'];
				$dt          = $fecha_raw ? DateTime::createFromFormat( 'Y-m-d', $fecha_raw ) : null;

				// OI vinculada.
				$oi_txt = '—';
				if ( $oi_id > 0 ) {
					$oi_numero = (string) get_post_meta( $oi_id, CalibraTrack_Meta_Keys::EVENTO_NUMERO_OI, true );
					$oi_fecha  = (string) get_post_meta( $oi_id, CalibraTrack_Meta_Keys::EVENTO_FECHA_EJECUCION, true );
					$oi_txt    = $oi_numero ?: 'OI-' . $oi_fecha;
				}

				// URL de edición según rol.
				$edit_url = $es_admin
					? home_url( '/panel/ot/' . $ev->ID . '/' )
					: home_url( '/panel/evento/' . $ev->ID . '/' );
			?>
			<tr>
				<td data-label="<?php esc_attr_e( 'Fecha', 'calibratrack' ); ?>"><?php echo $dt ? esc_html( $dt->format( 'd/m/Y' ) ) : '—'; ?></td>
				<td data-label="<?php esc_attr_e( 'N° OT', 'calibratrack' ); ?>"><?php echo esc_html( $numero_ot ?: '—' ); ?></td>
				<td data-label="<?php esc_attr_e( 'Equipo', 'calibratrack' ); ?>"><?php echo esc_html( $serie ?: '—' ); ?></td>
				<td data-label="<?php esc_attr_e( 'Técnico', 'calibratrack' ); ?>"><?php echo esc_html( $tecnico_nom ); ?></td>
				<td data-label="<?php esc_attr_e( 'Tipo', 'calibratrack' ); ?>"><?php echo esc_html( $tipo_label ); ?></td>
				<td data-label="<?php esc_attr_e( 'Estado', 'calibratrack' ); ?>">
					<span class="ct-badge <?php echo esc_attr( $est_cfg['clase'] ); ?>">
						<?php echo esc_html( $est_cfg['label'] ); ?>
					</span>
				</td>
				<td data-label="<?php esc_attr_e( 'OI vinculada', 'calibratrack' ); ?>">
					<?php if ( $oi_id > 0 ) : ?>
						<a href="<?php echo esc_url( home_url( '/panel/oi/' . $oi_id . '/' ) ); ?>" class="ct-link">
							<?php echo esc_html( $oi_txt ); ?>
						</a>
					<?php else : ?>
						—
					<?php endif; ?>
				</td>
				<td>
					<a href="<?php echo esc_url( $edit_url ); ?>" class="ct-btn ct-btn--sm">
						<?php esc_html_e( 'Editar', 'calibratrack' ); ?>
					</a>
				</td>
			</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
	</div>

	<?php if ( $total_pages > 1 ) : ?>
	<nav class="ct-paginacion" aria-label="<?php esc_attr_e( 'Paginación OT', 'calibratrack' ); ?>">
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
