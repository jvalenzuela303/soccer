<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

$user    = wp_get_current_user();
$es_admin = current_user_can( 'manage_options' );

// ─── Dashboard del administrador ─────────────────────────────────────────────
if ( $es_admin ) {
	$page_title   = __( 'Panel de Gestión', 'calibratrack' );
	$filtro       = isset( $_GET['filtro'] ) ? sanitize_key( wp_unslash( $_GET['filtro'] ) ) : '';
	$paged        = max( 1, isset( $_GET['pagina'] ) ? absint( $_GET['pagina'] ) : 1 );
	$tipos_ev     = CalibraTrack_Helpers::get_tipos_evento();

	$es_dashboard = ( '' === $filtro );
	$es_vista_oi  = ( 'oi' === $filtro );
	$es_vista_ot  = ( 'ot' === $filtro );
	$mostrar_oi   = $es_dashboard || $es_vista_oi;
	$mostrar_ot   = $es_dashboard || $es_vista_ot;

	// ── Filtros de búsqueda para la vista ?filtro=oi ─────────────────────────
	$buscar_oi      = ( $es_vista_oi && isset( $_GET['buscar'] ) )  ? sanitize_text_field( wp_unslash( $_GET['buscar'] ) ) : '';
	$filtro_tipo_oi = ( $es_vista_oi && isset( $_GET['tipo'] ) )    ? sanitize_key( wp_unslash( $_GET['tipo'] ) )          : '';
	$filtro_tec_oi  = ( $es_vista_oi && isset( $_GET['tecnico'] ) ) ? absint( $_GET['tecnico'] )                           : 0;
	$hay_filtros_oi = ( '' !== $buscar_oi || '' !== $filtro_tipo_oi || $filtro_tec_oi > 0 );

	// ── Filtros de búsqueda para la vista ?filtro=ot ─────────────────────────
	$buscar_ot        = ( $es_vista_ot && isset( $_GET['buscar'] ) )  ? sanitize_text_field( wp_unslash( $_GET['buscar'] ) ) : '';
	$filtro_tipo_ot   = ( $es_vista_ot && isset( $_GET['tipo'] ) )    ? sanitize_key( wp_unslash( $_GET['tipo'] ) )          : '';
	$filtro_tec_ot    = ( $es_vista_ot && isset( $_GET['tecnico'] ) ) ? absint( $_GET['tecnico'] )                           : 0;
	$filtro_estado_ot = ( $es_vista_ot && isset( $_GET['estado'] ) )  ? sanitize_key( wp_unslash( $_GET['estado'] ) )        : '';
	$hay_filtros_ot   = ( '' !== $buscar_ot || '' !== $filtro_tipo_ot || $filtro_tec_ot > 0 || '' !== $filtro_estado_ot );

	// ── Query de OI ──────────────────────────────────────────────────────────
	$ois       = array();
	$ois_pages = 1;
	if ( $mostrar_oi ) {
		$per_page_oi = $es_vista_oi ? 20 : 5;
		$meta_q_oi   = array(
			'relation' => 'AND',
			array(
				'key'   => CalibraTrack_Meta_Keys::EVENTO_TIPO_DOCUMENTO,
				'value' => 'ingreso',
			),
		);

		if ( $es_vista_oi ) {
			if ( '' !== $filtro_tipo_oi ) {
				$meta_q_oi[] = array(
					'key'     => CalibraTrack_Meta_Keys::EVENTO_TIPO,
					'value'   => $filtro_tipo_oi,
					'compare' => '=',
				);
			}
			if ( $filtro_tec_oi > 0 ) {
				$meta_q_oi[] = array(
					'key'     => CalibraTrack_Meta_Keys::EVENTO_TECNICO_RESPONSABLE,
					'value'   => $filtro_tec_oi,
					'compare' => '=',
					'type'    => 'NUMERIC',
				);
			}
			if ( '' !== $buscar_oi ) {
				$equipos_ids = get_posts( array(
					'post_type'      => 'equipo',
					'post_status'    => 'publish',
					'posts_per_page' => 50,
					'fields'         => 'ids',
					'meta_query'     => array(
						array(
							'key'     => CalibraTrack_Meta_Keys::EQUIPO_SERIE,
							'value'   => $buscar_oi,
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
					'key'     => CalibraTrack_Meta_Keys::EVENTO_NUMERO_OI,
					'value'   => $buscar_oi,
					'compare' => 'LIKE',
				);
				$meta_q_oi[] = $buscar_sub;
			}
		}

		$query_oi = new WP_Query( array(
			'post_type'      => CalibraTrack_CPT_EventoServicio::SLUG,
			'post_status'    => 'publish',
			'posts_per_page' => $per_page_oi,
			'paged'          => $es_vista_oi ? $paged : 1,
			'orderby'        => 'date',
			'order'          => 'DESC',
			'meta_query'     => $meta_q_oi,
		) );
		$ois       = $query_oi->posts;
		$ois_pages = $query_oi->max_num_pages;

		// Pre-cargar postmeta de OIs + equipos en batch (evita N+1 en el loop).
		if ( ! empty( $ois ) ) {
			$oi_ids_pre = wp_list_pluck( $ois, 'ID' );
			update_postmeta_cache( $oi_ids_pre );
			$equipo_ids_oi_pre = array();
			foreach ( $oi_ids_pre as $oid ) {
				$eid = (int) get_post_meta( $oid, CalibraTrack_Meta_Keys::EVENTO_EQUIPO_ID, true );
				if ( $eid > 0 ) { $equipo_ids_oi_pre[] = $eid; }
			}
			if ( ! empty( $equipo_ids_oi_pre ) ) {
				update_postmeta_cache( array_unique( $equipo_ids_oi_pre ) );
			}
		}
	}

	// ── Query de OT ──────────────────────────────────────────────────────────
	$ots       = array();
	$ots_pages = 1;
	if ( $mostrar_ot ) {
		$per_page_ot = $es_vista_ot ? 20 : 5;
		$meta_q_ot   = array(
			'relation' => 'AND',
			array(
				'key'   => CalibraTrack_Meta_Keys::EVENTO_TIPO_DOCUMENTO,
				'value' => 'ot',
			),
		);

		if ( $es_vista_ot ) {
			if ( '' !== $filtro_tipo_ot ) {
				$meta_q_ot[] = array(
					'key'     => CalibraTrack_Meta_Keys::EVENTO_TIPO,
					'value'   => $filtro_tipo_ot,
					'compare' => '=',
				);
			}
			if ( $filtro_tec_ot > 0 ) {
				$meta_q_ot[] = array(
					'key'     => CalibraTrack_Meta_Keys::EVENTO_TECNICO_RESPONSABLE,
					'value'   => $filtro_tec_ot,
					'compare' => '=',
					'type'    => 'NUMERIC',
				);
			}
			if ( '' !== $filtro_estado_ot ) {
				$meta_q_ot[] = array(
					'key'     => CalibraTrack_Meta_Keys::EVENTO_ESTADO_SERVICIO,
					'value'   => $filtro_estado_ot,
					'compare' => '=',
				);
			}
			if ( '' !== $buscar_ot ) {
				$equipos_ids_ot = get_posts( array(
					'post_type'      => 'equipo',
					'post_status'    => 'publish',
					'posts_per_page' => 50,
					'fields'         => 'ids',
					'meta_query'     => array(
						array(
							'key'     => CalibraTrack_Meta_Keys::EQUIPO_SERIE,
							'value'   => $buscar_ot,
							'compare' => 'LIKE',
						),
					),
				) );
				$buscar_sub_ot = array( 'relation' => 'OR' );
				if ( ! empty( $equipos_ids_ot ) ) {
					$buscar_sub_ot[] = array(
						'key'     => CalibraTrack_Meta_Keys::EVENTO_EQUIPO_ID,
						'value'   => $equipos_ids_ot,
						'compare' => 'IN',
						'type'    => 'NUMERIC',
					);
				}
				$buscar_sub_ot[] = array(
					'key'     => CalibraTrack_Meta_Keys::EVENTO_NUMERO_OT,
					'value'   => $buscar_ot,
					'compare' => 'LIKE',
				);
				$meta_q_ot[] = $buscar_sub_ot;
			}
		}

		$query_ot = new WP_Query( array(
			'post_type'      => CalibraTrack_CPT_EventoServicio::SLUG,
			'post_status'    => 'publish',
			'posts_per_page' => $per_page_ot,
			'paged'          => $es_vista_ot ? $paged : 1,
			'orderby'        => 'date',
			'order'          => 'DESC',
			'meta_query'     => $meta_q_ot,
		) );
		$ots       = $query_ot->posts;
		$ots_pages = $query_ot->max_num_pages;

		// Pre-cargar postmeta de OTs + equipos + OIs vinculadas en batch.
		if ( ! empty( $ots ) ) {
			$ot_ids_pre = wp_list_pluck( $ots, 'ID' );
			update_postmeta_cache( $ot_ids_pre );
			$equipo_ids_ot_pre = array();
			$oi_ids_ot_pre     = array();
			foreach ( $ot_ids_pre as $otid ) {
				$eid = (int) get_post_meta( $otid, CalibraTrack_Meta_Keys::EVENTO_EQUIPO_ID, true );
				if ( $eid > 0 ) { $equipo_ids_ot_pre[] = $eid; }
				$oid = (int) get_post_meta( $otid, CalibraTrack_Meta_Keys::EVENTO_INGRESO_RELACIONADO_ID, true );
				if ( $oid > 0 ) { $oi_ids_ot_pre[] = $oid; }
			}
			if ( ! empty( $equipo_ids_ot_pre ) ) {
				update_postmeta_cache( array_unique( $equipo_ids_ot_pre ) );
			}
			if ( ! empty( $oi_ids_ot_pre ) ) {
				update_postmeta_cache( array_unique( $oi_ids_ot_pre ) );
			}
		}
	}

	$estados_servicio_cfg = CalibraTrack_Helpers::get_estados_servicio();

	// Técnicos para los selects de filtro (solo cuando se necesitan).
	$tecnicos_select = ( $es_vista_oi || $es_vista_ot ) ? CalibraTrack_Helpers::get_tecnicos_para_select() : array();

	// ── Datos para KPIs y gráficos (solo en vista principal) ────────────────
	$kpi            = array( 'total' => 0, 'vigente' => 0, 'por_vencer' => 0, 'vencido' => 0, 'sin_evento' => 0 );
	$total_clientes = 0;
	$total_eventos  = 0;
	$tipo_count     = array( 'reparacion' => 0, 'mantencion_calibracion' => 0 );
	$meses_labels   = array();
	$meses_data     = array();

	if ( $es_dashboard ) {
		global $wpdb;

		// KPI: estado de todos los equipos.
		$equipos_ids  = get_posts( array(
			'post_type'      => 'equipo',
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'fields'         => 'ids',
		) );
		$kpi['total'] = count( $equipos_ids );
		if ( ! empty( $equipos_ids ) ) {
			$ultimos_kpi = CalibraTrack_DB::get_ultimo_evento_batch( $equipos_ids ); // 1 sola query.
			foreach ( $equipos_ids as $eq_id ) {
				$ultimo_k  = isset( $ultimos_kpi[ $eq_id ] ) ? $ultimos_kpi[ $eq_id ] : null;
				$proxima_k = $ultimo_k ? (string) $ultimo_k->proxima_fecha_control : '';
				$kpi[ CalibraTrack_Helpers::calcular_estado_vigencia( $proxima_k ) ]++;
			}
		}

		$total_clientes = (int) wp_count_posts( 'cliente' )->publish;

		// Tipo de servicio — 1 query GROUP BY en vez de cargar todos los IDs y hacer N get_post_meta().
		$tipo_rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT pm.meta_value AS tipo, COUNT(*) AS total
			 FROM {$wpdb->postmeta} pm
			 INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
			 WHERE p.post_type = %s AND p.post_status = 'publish' AND pm.meta_key = %s
			 GROUP BY pm.meta_value",
			CalibraTrack_CPT_EventoServicio::SLUG,
			CalibraTrack_Meta_Keys::EVENTO_TIPO
		) );
		// Mapeo de slugs anteriores → nuevos (compatibilidad con registros existentes).
		$tipo_slug_map = array(
			'calibracion'   => 'mantencion_calibracion',
			'mantenimiento' => 'mantencion_calibracion',
		);
		$total_eventos = 0;
		foreach ( $tipo_rows as $row ) {
			$total_eventos += (int) $row->total;
			$slug = isset( $tipo_slug_map[ $row->tipo ] ) ? $tipo_slug_map[ $row->tipo ] : $row->tipo;
			if ( isset( $tipo_count[ $slug ] ) ) {
				$tipo_count[ $slug ] += (int) $row->total;
			}
		}

		// Eventos por mes — últimos 6 meses (todos los tipos).
		$inicio_6m       = gmdate( 'Y-m-01', strtotime( '-5 months' ) );
		$eventos_mes_raw = $wpdb->get_results( $wpdb->prepare(
			"SELECT DATE_FORMAT(post_date,'%%Y-%%m') AS mes, COUNT(*) AS total
			 FROM {$wpdb->posts}
			 WHERE post_type = 'evento_servicio' AND post_status = 'publish' AND post_date >= %s
			 GROUP BY mes ORDER BY mes ASC",
			$inicio_6m
		) );
		$meses_es_chart = array( 1 => 'Ene', 2 => 'Feb', 3 => 'Mar', 4 => 'Abr', 5 => 'May', 6 => 'Jun', 7 => 'Jul', 8 => 'Ago', 9 => 'Sep', 10 => 'Oct', 11 => 'Nov', 12 => 'Dic' );
		$meses_map      = array();
		foreach ( $eventos_mes_raw as $row ) {
			$meses_map[ $row->mes ] = (int) $row->total;
		}
		for ( $i = 5; $i >= 0; $i-- ) {
			$ts             = strtotime( "-{$i} months" );
			$key            = gmdate( 'Y-m', $ts );
			$meses_labels[] = $meses_es_chart[ (int) gmdate( 'n', $ts ) ] . ' ' . gmdate( 'y', $ts );
			$meses_data[]   = isset( $meses_map[ $key ] ) ? $meses_map[ $key ] : 0;
		}
	}

	include __DIR__ . '/_partials/header.php';
	?>

	<div class="ct-container">

		<div class="ct-dashboard-hero">
			<div>
				<h1 class="ct-page-title">
					<?php
					echo esc_html( sprintf(
						/* translators: %s: nombre del administrador */
						__( 'Hola, %s', 'calibratrack' ),
						$user->display_name
					) );
					?>
				</h1>
				<p class="ct-text-muted"><?php echo esc_html( date_i18n( 'l j \d\e F \d\e Y' ) ); ?></p>
			</div>
			<?php if ( $es_dashboard ) : ?>
			<div style="display:flex;gap:10px;flex-wrap:wrap;">
				<a href="<?php echo esc_url( home_url( '/panel/nueva-oi/' ) ); ?>" class="ct-btn ct-btn--large" style="background:#2563eb;border-color:#2563eb;color:#fff;">
					<?php esc_html_e( '+ Nueva OI', 'calibratrack' ); ?>
				</a>
				<a href="<?php echo esc_url( home_url( '/panel/nueva-ot/' ) ); ?>" class="ct-btn ct-btn--large" style="background:#16a34a;border-color:#16a34a;color:#fff;">
					<?php esc_html_e( '+ Nueva OT', 'calibratrack' ); ?>
				</a>
			</div>
			<?php endif; ?>
		</div>

		<?php if ( isset( $_GET['guardado'] ) ) : ?>
			<div class="ct-alert ct-alert--success" role="alert">
				<?php esc_html_e( 'Guardado correctamente.', 'calibratrack' ); ?>
			</div>
		<?php endif; ?>

		<!-- Filtros de vista -->
		<div class="ct-filter-bar" style="margin-bottom:24px;">
			<a href="<?php echo esc_url( home_url( '/panel/' ) ); ?>"
				class="ct-btn ct-btn--sm <?php echo $es_dashboard ? 'ct-btn--primary' : ''; ?>">
				<?php esc_html_e( 'Todo', 'calibratrack' ); ?>
			</a>
			<a href="<?php echo esc_url( add_query_arg( 'filtro', 'oi', home_url( '/panel/' ) ) ); ?>"
				class="ct-btn ct-btn--sm <?php echo $es_vista_oi ? 'ct-btn--primary' : ''; ?>">
				<?php esc_html_e( 'Órdenes de Ingreso', 'calibratrack' ); ?>
			</a>
			<a href="<?php echo esc_url( add_query_arg( 'filtro', 'ot', home_url( '/panel/' ) ) ); ?>"
				class="ct-btn ct-btn--sm <?php echo $es_vista_ot ? 'ct-btn--primary' : ''; ?>">
				<?php esc_html_e( 'Órdenes de Trabajo', 'calibratrack' ); ?>
			</a>
		</div>

		<?php if ( $mostrar_oi ) : ?>
		<!-- Tabla de Órdenes de Ingreso -->
		<section class="ct-section" style="margin-bottom:28px;">
			<div class="ct-section-header">
				<h2 class="ct-section-title"><?php esc_html_e( 'Órdenes de Ingreso', 'calibratrack' ); ?></h2>
				<?php if ( $es_dashboard ) : ?>
				<a href="<?php echo esc_url( add_query_arg( 'filtro', 'oi', home_url( '/panel/' ) ) ); ?>" class="ct-link">
					<?php esc_html_e( 'Ver todas →', 'calibratrack' ); ?>
				</a>
				<?php endif; ?>
			</div>

			<?php if ( $es_vista_oi ) : ?>
			<!-- Barra de búsqueda y filtros -->
			<form method="get" action="<?php echo esc_url( home_url( '/panel/' ) ); ?>" class="ct-filter-bar" style="margin-bottom:20px;">
				<input type="hidden" name="filtro" value="oi">
				<input
					type="text"
					name="buscar"
					value="<?php echo esc_attr( $buscar_oi ); ?>"
					placeholder="<?php esc_attr_e( 'Buscar por N° OI o serie…', 'calibratrack' ); ?>"
					class="ct-input ct-filter-search"
				>
				<select name="tipo" class="ct-select ct-filter-select">
					<option value=""><?php esc_html_e( 'Todos los tipos', 'calibratrack' ); ?></option>
					<?php foreach ( $tipos_ev as $slug => $label ) : ?>
						<option value="<?php echo esc_attr( $slug ); ?>" <?php selected( $filtro_tipo_oi, $slug ); ?>>
							<?php echo esc_html( $label ); ?>
						</option>
					<?php endforeach; ?>
				</select>
				<?php if ( ! empty( $tecnicos_select ) ) : ?>
				<select name="tecnico" class="ct-select ct-filter-select">
					<option value=""><?php esc_html_e( 'Todos los técnicos', 'calibratrack' ); ?></option>
					<?php foreach ( $tecnicos_select as $tec ) : ?>
						<option value="<?php echo esc_attr( $tec->ID ); ?>" <?php selected( $filtro_tec_oi, $tec->ID ); ?>>
							<?php echo esc_html( $tec->display_name ); ?>
						</option>
					<?php endforeach; ?>
				</select>
				<?php endif; ?>
				<button type="submit" class="ct-btn ct-btn--primary"><?php esc_html_e( 'Filtrar', 'calibratrack' ); ?></button>
				<?php if ( $hay_filtros_oi ) : ?>
					<a href="<?php echo esc_url( add_query_arg( 'filtro', 'oi', home_url( '/panel/' ) ) ); ?>" class="ct-btn ct-btn--secondary">
						<?php esc_html_e( 'Limpiar', 'calibratrack' ); ?>
					</a>
				<?php endif; ?>
			</form>
			<?php endif; ?>

			<?php if ( empty( $ois ) ) : ?>
				<p class="ct-empty-msg">
					<?php
					if ( $hay_filtros_oi ) {
						esc_html_e( 'No hay órdenes de ingreso que coincidan con los filtros aplicados.', 'calibratrack' );
					} else {
						esc_html_e( 'No hay órdenes de ingreso registradas.', 'calibratrack' );
					}
					?>
				</p>
			<?php else : ?>
			<div class="ct-table-wrap">
				<table class="ct-table">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Fecha', 'calibratrack' ); ?></th>
							<th><?php esc_html_e( 'N° OI', 'calibratrack' ); ?></th>
							<th><?php esc_html_e( 'Técnico', 'calibratrack' ); ?></th>
							<th><?php esc_html_e( 'Equipo (serie)', 'calibratrack' ); ?></th>
							<th><?php esc_html_e( 'Tipo servicio', 'calibratrack' ); ?></th>
							<th></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $ois as $oi ) :
							$equipo_id   = (int) get_post_meta( $oi->ID, CalibraTrack_Meta_Keys::EVENTO_EQUIPO_ID, true );
							$tecnico_id  = (int) get_post_meta( $oi->ID, CalibraTrack_Meta_Keys::EVENTO_TECNICO_RESPONSABLE, true );
							$tipo        = (string) get_post_meta( $oi->ID, CalibraTrack_Meta_Keys::EVENTO_TIPO, true );
							$fecha_raw   = (string) get_post_meta( $oi->ID, CalibraTrack_Meta_Keys::EVENTO_FECHA_EJECUCION, true );
							$numero_oi   = (string) get_post_meta( $oi->ID, CalibraTrack_Meta_Keys::EVENTO_NUMERO_OI, true );
							$oi_label    = $numero_oi ?: 'OI-' . $fecha_raw;
							$serie       = $equipo_id ? (string) get_post_meta( $equipo_id, CalibraTrack_Meta_Keys::EQUIPO_SERIE, true ) : '—';
							$tecnico     = $tecnico_id ? get_userdata( $tecnico_id ) : null;
							$tecnico_nom = $tecnico ? $tecnico->display_name : '—';
							$tipo_label  = isset( $tipos_ev[ $tipo ] ) ? $tipos_ev[ $tipo ] : ( '' !== $tipo ? esc_html( $tipo ) : '—' );
							$dt          = $fecha_raw ? DateTime::createFromFormat( 'Y-m-d', $fecha_raw ) : null;
						?>
						<tr>
							<td data-label="<?php esc_attr_e( 'Fecha', 'calibratrack' ); ?>">
								<?php echo $dt ? esc_html( $dt->format( 'd/m/Y' ) ) : '—'; ?>
							</td>
							<td data-label="<?php esc_attr_e( 'N° OI', 'calibratrack' ); ?>">
								<?php echo esc_html( $oi_label ); ?>
							</td>
							<td data-label="<?php esc_attr_e( 'Técnico', 'calibratrack' ); ?>">
								<?php echo esc_html( $tecnico_nom ); ?>
							</td>
							<td data-label="<?php esc_attr_e( 'Equipo (serie)', 'calibratrack' ); ?>">
								<?php echo esc_html( $serie ?: '—' ); ?>
							</td>
							<td data-label="<?php esc_attr_e( 'Tipo servicio', 'calibratrack' ); ?>">
								<?php echo esc_html( $tipo_label ); ?>
							</td>
							<td style="white-space:nowrap;">
								<a href="<?php echo esc_url( home_url( '/panel/oi/' . $oi->ID . '/' ) ); ?>" class="ct-btn ct-btn--sm">
									<?php esc_html_e( 'Editar', 'calibratrack' ); ?>
								</a>
								<a href="<?php echo esc_url( add_query_arg( 'imprimir', '1', home_url( '/panel/oi/' . $oi->ID . '/' ) ) ); ?>" class="ct-btn ct-btn--sm" style="margin-left:4px;">
									<?php esc_html_e( 'Imprimir', 'calibratrack' ); ?>
								</a>
								<a href="<?php echo esc_url( add_query_arg( 'ct_oi_id', $oi->ID, home_url( '/panel/nueva-ot/' ) ) ); ?>" class="ct-btn ct-btn--sm" style="background:#16a34a;border-color:#16a34a;color:#fff;margin-left:4px;">
									<?php esc_html_e( '+ Crear OT', 'calibratrack' ); ?>
								</a>
							</td>
						</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>

			<?php if ( $es_vista_oi && $ois_pages > 1 ) : ?>
			<nav class="ct-paginacion" aria-label="<?php esc_attr_e( 'Paginación OI', 'calibratrack' ); ?>">
				<?php
				$base_pag_oi = add_query_arg( array_filter( array(
					'filtro'  => 'oi',
					'buscar'  => $buscar_oi ?: null,
					'tipo'    => $filtro_tipo_oi ?: null,
					'tecnico' => $filtro_tec_oi ?: null,
				) ), home_url( '/panel/' ) );
				for ( $p = 1; $p <= $ois_pages; $p++ ) : ?>
					<a href="<?php echo esc_url( add_query_arg( 'pagina', $p, $base_pag_oi ) ); ?>"
						class="ct-btn ct-btn--sm <?php echo $p === $paged ? 'ct-btn--primary' : ''; ?>">
						<?php echo (int) $p; ?>
					</a>
				<?php endfor; ?>
			</nav>
			<?php endif; ?>

			<?php endif; ?>
		</section>
		<?php endif; ?>

		<?php if ( $mostrar_ot ) : ?>
		<!-- Tabla de Órdenes de Trabajo -->
		<section class="ct-section">
			<div class="ct-section-header">
				<h2 class="ct-section-title"><?php esc_html_e( 'Órdenes de Trabajo', 'calibratrack' ); ?></h2>
				<?php if ( $es_dashboard ) : ?>
				<a href="<?php echo esc_url( add_query_arg( 'filtro', 'ot', home_url( '/panel/' ) ) ); ?>" class="ct-link">
					<?php esc_html_e( 'Ver todas →', 'calibratrack' ); ?>
				</a>
				<?php endif; ?>
			</div>

			<?php if ( $es_vista_ot ) : ?>
			<!-- Barra de búsqueda y filtros -->
			<form method="get" action="<?php echo esc_url( home_url( '/panel/' ) ); ?>" class="ct-filter-bar" style="margin-bottom:20px;">
				<input type="hidden" name="filtro" value="ot">
				<input
					type="text"
					name="buscar"
					value="<?php echo esc_attr( $buscar_ot ); ?>"
					placeholder="<?php esc_attr_e( 'Buscar por N° OT o serie…', 'calibratrack' ); ?>"
					class="ct-input ct-filter-search"
				>
				<select name="tipo" class="ct-select ct-filter-select">
					<option value=""><?php esc_html_e( 'Todos los tipos', 'calibratrack' ); ?></option>
					<?php foreach ( $tipos_ev as $slug => $label ) : ?>
						<option value="<?php echo esc_attr( $slug ); ?>" <?php selected( $filtro_tipo_ot, $slug ); ?>>
							<?php echo esc_html( $label ); ?>
						</option>
					<?php endforeach; ?>
				</select>
				<?php if ( ! empty( $tecnicos_select ) ) : ?>
				<select name="tecnico" class="ct-select ct-filter-select">
					<option value=""><?php esc_html_e( 'Todos los técnicos', 'calibratrack' ); ?></option>
					<?php foreach ( $tecnicos_select as $tec ) : ?>
						<option value="<?php echo esc_attr( $tec->ID ); ?>" <?php selected( $filtro_tec_ot, $tec->ID ); ?>>
							<?php echo esc_html( $tec->display_name ); ?>
						</option>
					<?php endforeach; ?>
				</select>
				<?php endif; ?>
				<select name="estado" class="ct-select ct-filter-select">
					<option value=""><?php esc_html_e( 'Todos los estados', 'calibratrack' ); ?></option>
					<?php foreach ( CalibraTrack_Helpers::get_estados_servicio() as $slug => $cfg ) : ?>
						<option value="<?php echo esc_attr( $slug ); ?>" <?php selected( $filtro_estado_ot, $slug ); ?>>
							<?php echo esc_html( $cfg['label'] ); ?>
						</option>
					<?php endforeach; ?>
				</select>
				<button type="submit" class="ct-btn ct-btn--primary"><?php esc_html_e( 'Filtrar', 'calibratrack' ); ?></button>
				<?php if ( $hay_filtros_ot ) : ?>
					<a href="<?php echo esc_url( add_query_arg( 'filtro', 'ot', home_url( '/panel/' ) ) ); ?>" class="ct-btn ct-btn--secondary">
						<?php esc_html_e( 'Limpiar', 'calibratrack' ); ?>
					</a>
				<?php endif; ?>
			</form>
			<?php endif; ?>

			<?php if ( empty( $ots ) ) : ?>
				<p class="ct-empty-msg">
					<?php
					if ( $hay_filtros_ot ) {
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
						<?php foreach ( $ots as $ot ) :
							$equipo_id   = (int) get_post_meta( $ot->ID, CalibraTrack_Meta_Keys::EVENTO_EQUIPO_ID, true );
							$tecnico_id  = (int) get_post_meta( $ot->ID, CalibraTrack_Meta_Keys::EVENTO_TECNICO_RESPONSABLE, true );
							$numero_ot   = (string) get_post_meta( $ot->ID, CalibraTrack_Meta_Keys::EVENTO_NUMERO_OT, true );
							$tipo        = (string) get_post_meta( $ot->ID, CalibraTrack_Meta_Keys::EVENTO_TIPO, true );
							$fecha_raw   = (string) get_post_meta( $ot->ID, CalibraTrack_Meta_Keys::EVENTO_FECHA_EJECUCION, true );
							$estado_srv  = (string) get_post_meta( $ot->ID, CalibraTrack_Meta_Keys::EVENTO_ESTADO_SERVICIO, true );
							$oi_id       = (int) get_post_meta( $ot->ID, CalibraTrack_Meta_Keys::EVENTO_INGRESO_RELACIONADO_ID, true );
							$serie       = $equipo_id ? (string) get_post_meta( $equipo_id, CalibraTrack_Meta_Keys::EQUIPO_SERIE, true ) : '—';
							$tecnico     = $tecnico_id ? get_userdata( $tecnico_id ) : null;
							$tecnico_nom = $tecnico ? $tecnico->display_name : '—';
							$tipo_label  = isset( $tipos_ev[ $tipo ] ) ? $tipos_ev[ $tipo ] : esc_html( $tipo );
							$dt          = $fecha_raw ? DateTime::createFromFormat( 'Y-m-d', $fecha_raw ) : null;
							$estado_srv  = '' !== $estado_srv ? $estado_srv : 'en_proceso';
							$est_cfg     = isset( $estados_servicio_cfg[ $estado_srv ] ) ? $estados_servicio_cfg[ $estado_srv ] : $estados_servicio_cfg['en_proceso'];
						?>
						<tr>
							<td data-label="<?php esc_attr_e( 'Fecha', 'calibratrack' ); ?>">
								<?php echo $dt ? esc_html( $dt->format( 'd/m/Y' ) ) : '—'; ?>
							</td>
							<td data-label="<?php esc_attr_e( 'N° OT', 'calibratrack' ); ?>">
								<?php echo esc_html( $numero_ot ?: '—' ); ?>
							</td>
							<td data-label="<?php esc_attr_e( 'Equipo', 'calibratrack' ); ?>">
								<?php echo esc_html( $serie ?: '—' ); ?>
							</td>
							<td data-label="<?php esc_attr_e( 'Técnico', 'calibratrack' ); ?>">
								<?php echo esc_html( $tecnico_nom ); ?>
							</td>
							<td data-label="<?php esc_attr_e( 'Tipo', 'calibratrack' ); ?>">
								<?php echo esc_html( $tipo_label ); ?>
							</td>
							<td data-label="<?php esc_attr_e( 'Estado', 'calibratrack' ); ?>">
								<span class="ct-badge <?php echo esc_attr( $est_cfg['clase'] ); ?>">
									<?php echo esc_html( $est_cfg['label'] ); ?>
								</span>
							</td>
							<td data-label="<?php esc_attr_e( 'OI vinculada', 'calibratrack' ); ?>">
								<?php if ( $oi_id > 0 ) :
									$oi_numero = (string) get_post_meta( $oi_id, CalibraTrack_Meta_Keys::EVENTO_NUMERO_OI, true );
									$oi_fecha  = (string) get_post_meta( $oi_id, CalibraTrack_Meta_Keys::EVENTO_FECHA_EJECUCION, true );
									$oi_txt    = $oi_numero ?: 'OI-' . $oi_fecha;
								?>
									<a href="<?php echo esc_url( home_url( '/panel/oi/' . $oi_id . '/' ) ); ?>" class="ct-link">
										<?php echo esc_html( $oi_txt ); ?>
									</a>
								<?php else : ?>
									—
								<?php endif; ?>
							</td>
							<td style="white-space:nowrap;">
								<a href="<?php echo esc_url( home_url( '/panel/ot/' . $ot->ID . '/' ) ); ?>" class="ct-btn ct-btn--sm">
									<?php esc_html_e( 'Editar', 'calibratrack' ); ?>
								</a>
								<a href="<?php echo esc_url( add_query_arg( 'imprimir', '1', home_url( '/panel/ot/' . $ot->ID . '/' ) ) ); ?>" class="ct-btn ct-btn--sm" style="margin-left:4px;">
									<?php esc_html_e( 'Imprimir', 'calibratrack' ); ?>
								</a>
							</td>
						</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>

			<?php if ( $es_vista_ot && $ots_pages > 1 ) : ?>
			<nav class="ct-paginacion" aria-label="<?php esc_attr_e( 'Paginación OT', 'calibratrack' ); ?>">
				<?php
				$base_pag_ot = add_query_arg( array_filter( array(
					'filtro'  => 'ot',
					'buscar'  => $buscar_ot ?: null,
					'tipo'    => $filtro_tipo_ot ?: null,
					'tecnico' => $filtro_tec_ot ?: null,
					'estado'  => $filtro_estado_ot ?: null,
				) ), home_url( '/panel/' ) );
				for ( $p = 1; $p <= $ots_pages; $p++ ) : ?>
					<a href="<?php echo esc_url( add_query_arg( 'pagina', $p, $base_pag_ot ) ); ?>"
						class="ct-btn ct-btn--sm <?php echo $p === $paged ? 'ct-btn--primary' : ''; ?>">
						<?php echo (int) $p; ?>
					</a>
				<?php endfor; ?>
			</nav>
			<?php endif; ?>

			<?php endif; ?>
		</section>
		<?php endif; ?>

		<?php if ( $es_dashboard ) : ?>
		<!-- ── KPIs ──────────────────────────────────────────────────────────── -->
		<section class="ct-section" style="margin-top:28px;">
			<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:14px;margin-bottom:24px;">
				<?php
				$ct_kpi_cards = array(
					array( 'label' => __( 'Equipos totales', 'calibratrack' ), 'val' => $kpi['total'],      'color' => '#00aeef' ),
					array( 'label' => __( 'Vigentes',        'calibratrack' ), 'val' => $kpi['vigente'],    'color' => '#22c55e' ),
					array( 'label' => __( 'Por vencer',      'calibratrack' ), 'val' => $kpi['por_vencer'], 'color' => '#f59e0b' ),
					array( 'label' => __( 'Vencidos',        'calibratrack' ), 'val' => $kpi['vencido'],    'color' => '#ef4444' ),
					array( 'label' => __( 'Sin calibración', 'calibratrack' ), 'val' => $kpi['sin_evento'], 'color' => '#94a3b8' ),
					array( 'label' => __( 'Clientes',        'calibratrack' ), 'val' => $total_clientes,    'color' => '#8b5cf6' ),
					array( 'label' => __( 'Eventos totales', 'calibratrack' ), 'val' => $total_eventos,     'color' => '#0ea5e9' ),
				);
				foreach ( $ct_kpi_cards as $card ) : ?>
				<div style="background:#fff;border-radius:10px;padding:16px;box-shadow:0 1px 4px rgba(0,0,0,.06);border-top:4px solid <?php echo esc_attr( $card['color'] ); ?>;">
					<div style="font-size:2rem;font-weight:800;color:#1e293b;line-height:1;margin-bottom:6px;"><?php echo (int) $card['val']; ?></div>
					<div style="font-size:.75rem;font-weight:600;color:#64748b;text-transform:uppercase;letter-spacing:.05em;"><?php echo esc_html( $card['label'] ); ?></div>
				</div>
				<?php endforeach; ?>
			</div>

			<!-- Gráficos -->
			<div style="display:grid;grid-template-columns:1fr 1.8fr 1fr;gap:20px;align-items:start;">
				<div style="background:#fff;border-radius:10px;padding:20px;box-shadow:0 1px 4px rgba(0,0,0,.06);">
					<p style="margin:0 0 14px;font-weight:600;font-size:.85rem;color:#374151;text-transform:uppercase;letter-spacing:.04em;">
						<?php esc_html_e( 'Estado de equipos', 'calibratrack' ); ?>
					</p>
					<canvas id="ct-panel-chart-estados"></canvas>
				</div>
				<div style="background:#fff;border-radius:10px;padding:20px;box-shadow:0 1px 4px rgba(0,0,0,.06);">
					<p style="margin:0 0 14px;font-weight:600;font-size:.85rem;color:#374151;text-transform:uppercase;letter-spacing:.04em;">
						<?php esc_html_e( 'Eventos de servicio — últimos 6 meses', 'calibratrack' ); ?>
					</p>
					<canvas id="ct-panel-chart-meses"></canvas>
				</div>
				<div style="background:#fff;border-radius:10px;padding:20px;box-shadow:0 1px 4px rgba(0,0,0,.06);">
					<p style="margin:0 0 14px;font-weight:600;font-size:.85rem;color:#374151;text-transform:uppercase;letter-spacing:.04em;">
						<?php esc_html_e( 'Tipo de servicio', 'calibratrack' ); ?>
					</p>
					<canvas id="ct-panel-chart-tipos"></canvas>
				</div>
			</div>
		</section>

		<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js"></script>
		<script>
		(function () {
			'use strict';
			if (typeof Chart === 'undefined') return;
			Chart.defaults.font.family = 'system-ui, -apple-system, sans-serif';
			Chart.defaults.font.size   = 12;

			var donutOpts = {
				responsive: true,
				cutout: '60%',
				plugins: {
					legend: { position: 'bottom', labels: { padding: 12, font: { size: 12 } } },
					tooltip: {
						callbacks: {
							label: function(ctx) {
								var total = ctx.dataset.data.reduce(function(a,b){return a+b;},0);
								var pct   = total > 0 ? Math.round(ctx.parsed/total*100) : 0;
								return ' '+ctx.label+': '+ctx.parsed+' ('+pct+'%)';
							}
						}
					}
				}
			};

			// Estado de equipos
			var c1 = document.getElementById('ct-panel-chart-estados');
			if (c1) {
				new Chart(c1, {
					type: 'doughnut',
					data: {
						labels: <?php echo wp_json_encode( array( __( 'Vigente', 'calibratrack' ), __( 'Por vencer', 'calibratrack' ), __( 'Vencido', 'calibratrack' ), __( 'Sin evento', 'calibratrack' ) ) ); ?>,
						datasets: [{ data: <?php echo wp_json_encode( array( $kpi['vigente'], $kpi['por_vencer'], $kpi['vencido'], $kpi['sin_evento'] ) ); ?>, backgroundColor: ['#22c55e','#f59e0b','#ef4444','#94a3b8'], borderWidth: 2, borderColor: '#fff', hoverOffset: 5 }]
					},
					options: donutOpts
				});
			}

			// Eventos por mes
			var c2 = document.getElementById('ct-panel-chart-meses');
			if (c2) {
				new Chart(c2, {
					type: 'bar',
					data: {
						labels: <?php echo wp_json_encode( $meses_labels ); ?>,
						datasets: [{
							label: '<?php echo esc_js( __( 'Eventos', 'calibratrack' ) ); ?>',
							data: <?php echo wp_json_encode( $meses_data ); ?>,
							backgroundColor: 'rgba(0,174,239,.75)',
							borderColor: 'rgba(0,174,239,1)',
							borderWidth: 1, borderRadius: 4, borderSkipped: false
						}]
					},
					options: {
						responsive: true,
						scales: {
							y: { beginAtZero: true, ticks: { stepSize: 1, precision: 0 }, grid: { color: 'rgba(0,0,0,.05)' } },
							x: { grid: { display: false } }
						},
						plugins: { legend: { display: false } }
					}
				});
			}

			// Tipo de servicio
			var c3 = document.getElementById('ct-panel-chart-tipos');
			if (c3) {
				new Chart(c3, {
					type: 'doughnut',
					data: {
						labels: <?php echo wp_json_encode( array( __( 'Reparación', 'calibratrack' ), __( 'Mantención y/o calibración', 'calibratrack' ) ) ); ?>,
						datasets: [{ data: <?php echo wp_json_encode( array( $tipo_count['reparacion'], $tipo_count['mantencion_calibracion'] ) ); ?>, backgroundColor: ['#3b82f6','#8b5cf6'], borderWidth: 2, borderColor: '#fff', hoverOffset: 5 }]
					},
					options: donutOpts
				});
			}
		})();
		</script>
		<?php endif; ?>

	</div>

	<?php include __DIR__ . '/_partials/footer.php';

} else {
	// ─── Dashboard del técnico ────────────────────────────────────────────────

	$page_title   = __( 'Inicio', 'calibratrack' );
	$aviso        = isset( $_GET['guardado'] ) ? __( 'Evento registrado correctamente.', 'calibratrack' ) : '';
	$vista        = isset( $_GET['vista'] ) ? sanitize_key( wp_unslash( $_GET['vista'] ) ) : '';
	$buscar       = isset( $_GET['buscar'] ) ? sanitize_text_field( wp_unslash( $_GET['buscar'] ) ) : '';
	$filtro_tipo  = isset( $_GET['tipo'] )   ? sanitize_key( wp_unslash( $_GET['tipo'] ) )          : '';
	$filtro_estado= isset( $_GET['estado'] ) ? sanitize_key( wp_unslash( $_GET['estado'] ) )         : '';
	$hay_filtros  = ( '' !== $buscar || '' !== $filtro_tipo || '' !== $filtro_estado );
	$paged        = max( 1, isset( $_GET['pagina'] ) ? absint( $_GET['pagina'] ) : 1 );
	$per_page     = 20;
	$tipos_ev     = CalibraTrack_Helpers::get_tipos_evento();
	$uid          = get_current_user_id();

	$es_vista_oi  = ( 'oi' === $vista );
	$es_vista_ot  = ( 'ot' === $vista );
	$es_dashboard = ( '' === $vista );
	$mostrar_oi   = $es_dashboard || $es_vista_oi;
	$mostrar_ot   = $es_dashboard || $es_vista_ot;

	// Cláusula reutilizable: 'ot' explícito O sin tipo_documento (compat. registros anteriores).
	$meta_tipo_ot = array(
		'relation' => 'OR',
		array(
			'key'     => CalibraTrack_Meta_Keys::EVENTO_TIPO_DOCUMENTO,
			'value'   => 'ot',
			'compare' => '=',
		),
		array(
			'key'     => CalibraTrack_Meta_Keys::EVENTO_TIPO_DOCUMENTO,
			'compare' => 'NOT EXISTS',
		),
	);

	// Helper para construir la búsqueda por serie/número en equipos.
	$equipos_buscar = array();
	if ( '' !== $buscar ) {
		$equipos_buscar = get_posts( array(
			'post_type'      => 'equipo',
			'post_status'    => 'publish',
			'posts_per_page' => 50,
			'fields'         => 'ids',
			'meta_query'     => array( array(
				'key'     => CalibraTrack_Meta_Keys::EQUIPO_SERIE,
				'value'   => $buscar,
				'compare' => 'LIKE',
			) ),
		) );
	}

	// ── Query OI del técnico ─────────────────────────────────────────────────
	$ois       = array();
	$ois_pages = 1;
	if ( $mostrar_oi ) {
		$mq_oi = array(
			'relation' => 'AND',
			array( 'key' => CalibraTrack_Meta_Keys::EVENTO_TIPO_DOCUMENTO, 'value' => 'ingreso' ),
			array( 'key' => CalibraTrack_Meta_Keys::EVENTO_TECNICO_RESPONSABLE, 'value' => $uid, 'compare' => '=', 'type' => 'NUMERIC' ),
		);
		if ( '' !== $filtro_tipo ) {
			$mq_oi[] = array( 'key' => CalibraTrack_Meta_Keys::EVENTO_TIPO, 'value' => $filtro_tipo, 'compare' => '=' );
		}
		if ( '' !== $buscar ) {
			$sub = array( 'relation' => 'OR',
				array( 'key' => CalibraTrack_Meta_Keys::EVENTO_NUMERO_OI, 'value' => $buscar, 'compare' => 'LIKE' ),
			);
			if ( ! empty( $equipos_buscar ) ) {
				$sub[] = array( 'key' => CalibraTrack_Meta_Keys::EVENTO_EQUIPO_ID, 'value' => $equipos_buscar, 'compare' => 'IN', 'type' => 'NUMERIC' );
			}
			$mq_oi[] = $sub;
		}
		$q_oi      = new WP_Query( array(
			'post_type'      => CalibraTrack_CPT_EventoServicio::SLUG,
			'post_status'    => 'publish',
			'posts_per_page' => $es_vista_oi ? $per_page : 5,
			'paged'          => $es_vista_oi ? $paged : 1,
			'orderby'        => 'date',
			'order'          => 'DESC',
			'meta_query'     => $mq_oi,
		) );
		$ois       = $q_oi->posts;
		$ois_pages = $q_oi->max_num_pages;

		// Pre-cargar postmeta de OIs + equipos; batch query OT vinculada por OI.
		$oi_ot_map = array(); // oi_id => array( 'ot_id' => int, 'numero_ot' => string ).
		if ( ! empty( $ois ) ) {
			$oi_ids_tec = wp_list_pluck( $ois, 'ID' );
			update_postmeta_cache( $oi_ids_tec );
			$equipo_ids_oi_tec = array();
			foreach ( $oi_ids_tec as $oid ) {
				$eid = (int) get_post_meta( $oid, CalibraTrack_Meta_Keys::EVENTO_EQUIPO_ID, true );
				if ( $eid > 0 ) { $equipo_ids_oi_tec[] = $eid; }
			}
			if ( ! empty( $equipo_ids_oi_tec ) ) {
				update_postmeta_cache( array_unique( $equipo_ids_oi_tec ) );
			}
			// Batch: OT vinculada a cada OI — reemplaza get_posts() por fila.
			// IMPORTANTE: usar '%s' con strings en vez de CAST(... AS UNSIGNED) para
			// permitir que MariaDB use el índice en meta_value. Los IDs en postmeta
			// se almacenan como strings; la comparación directa es correcta.
			global $wpdb;
			$oi_ids_str   = array_map( 'strval', array_map( 'intval', $oi_ids_tec ) );
			$placeholders = implode( ',', array_fill( 0, count( $oi_ids_str ), '%s' ) );
			$prepare_args = array_merge(
				array(
					CalibraTrack_CPT_EventoServicio::SLUG,
					CalibraTrack_Meta_Keys::EVENTO_NUMERO_OT,
					CalibraTrack_Meta_Keys::EVENTO_INGRESO_RELACIONADO_ID,
				),
				$oi_ids_str
			);
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
			$ot_rows_tec = $wpdb->get_results( $wpdb->prepare(
				"SELECT pm_link.meta_value AS oi_id, p.ID AS ot_id, pm_num.meta_value AS numero_ot
				 FROM {$wpdb->postmeta} pm_link
				 INNER JOIN {$wpdb->posts} p ON p.ID = pm_link.post_id AND p.post_type = %s AND p.post_status = 'publish'
				 LEFT JOIN {$wpdb->postmeta} pm_num ON pm_num.post_id = pm_link.post_id AND pm_num.meta_key = %s
				 WHERE pm_link.meta_key = %s AND pm_link.meta_value IN ({$placeholders})",
				$prepare_args
			) );
			foreach ( $ot_rows_tec as $row ) {
				$oi_ot_map[ (int) $row->oi_id ] = array(
					'ot_id'     => (int) $row->ot_id,
					'numero_ot' => (string) $row->numero_ot,
				);
			}
		}
	}

	// ── Query OT del técnico ─────────────────────────────────────────────────
	$ots       = array();
	$ots_pages = 1;
	if ( $mostrar_ot ) {
		$mq_ot = array(
			'relation' => 'AND',
			$meta_tipo_ot,
			array( 'key' => CalibraTrack_Meta_Keys::EVENTO_TECNICO_RESPONSABLE, 'value' => $uid, 'compare' => '=', 'type' => 'NUMERIC' ),
		);
		if ( '' !== $filtro_tipo ) {
			$mq_ot[] = array( 'key' => CalibraTrack_Meta_Keys::EVENTO_TIPO, 'value' => $filtro_tipo, 'compare' => '=' );
		}
		if ( '' !== $filtro_estado ) {
			$mq_ot[] = array( 'key' => CalibraTrack_Meta_Keys::EVENTO_ESTADO_SERVICIO, 'value' => $filtro_estado, 'compare' => '=' );
		}
		if ( '' !== $buscar ) {
			$sub_ot = array( 'relation' => 'OR',
				array( 'key' => CalibraTrack_Meta_Keys::EVENTO_NUMERO_OT, 'value' => $buscar, 'compare' => 'LIKE' ),
			);
			if ( ! empty( $equipos_buscar ) ) {
				$sub_ot[] = array( 'key' => CalibraTrack_Meta_Keys::EVENTO_EQUIPO_ID, 'value' => $equipos_buscar, 'compare' => 'IN', 'type' => 'NUMERIC' );
			}
			$mq_ot[] = $sub_ot;
		}
		$q_ot      = new WP_Query( array(
			'post_type'      => CalibraTrack_CPT_EventoServicio::SLUG,
			'post_status'    => 'publish',
			'posts_per_page' => $es_vista_ot ? $per_page : 5,
			'paged'          => $es_vista_ot ? $paged : 1,
			'orderby'        => 'date',
			'order'          => 'DESC',
			'meta_query'     => $mq_ot,
		) );
		$ots       = $q_ot->posts;
		$ots_pages = $q_ot->max_num_pages;

		// Pre-cargar postmeta de OTs + equipos + OIs vinculadas en batch.
		if ( ! empty( $ots ) ) {
			$ot_ids_tec = wp_list_pluck( $ots, 'ID' );
			update_postmeta_cache( $ot_ids_tec );
			$equipo_ids_ot_tec = array();
			$oi_ids_ot_tec     = array();
			foreach ( $ot_ids_tec as $otid ) {
				$eid = (int) get_post_meta( $otid, CalibraTrack_Meta_Keys::EVENTO_EQUIPO_ID, true );
				if ( $eid > 0 ) { $equipo_ids_ot_tec[] = $eid; }
				$oid = (int) get_post_meta( $otid, CalibraTrack_Meta_Keys::EVENTO_INGRESO_RELACIONADO_ID, true );
				if ( $oid > 0 ) { $oi_ids_ot_tec[] = $oid; }
			}
			if ( ! empty( $equipo_ids_ot_tec ) ) {
				update_postmeta_cache( array_unique( $equipo_ids_ot_tec ) );
			}
			if ( ! empty( $oi_ids_ot_tec ) ) {
				update_postmeta_cache( array_unique( $oi_ids_ot_tec ) );
			}
		}
	}

	$estados_srv_cfg = CalibraTrack_Helpers::get_estados_servicio();

	include __DIR__ . '/_partials/header.php';
	?>

	<div class="ct-container">

		<div class="ct-dashboard-hero">
			<div>
				<h1 class="ct-page-title">
					<?php echo esc_html( sprintf( __( 'Hola, %s', 'calibratrack' ), $user->display_name ) ); ?>
				</h1>
				<p class="ct-text-muted"><?php echo esc_html( date_i18n( 'l j \d\e F \d\e Y' ) ); ?></p>
			</div>
		</div>

		<?php if ( $aviso ) : ?>
			<div class="ct-alert ct-alert--success" role="alert"><?php echo esc_html( $aviso ); ?></div>
		<?php endif; ?>

		<!-- Filtros de vista -->
		<div class="ct-filter-bar" style="margin-bottom:24px;">
			<a href="<?php echo esc_url( home_url( '/panel/' ) ); ?>"
				class="ct-btn ct-btn--sm <?php echo $es_dashboard ? 'ct-btn--primary' : ''; ?>">
				<?php esc_html_e( 'Todo', 'calibratrack' ); ?>
			</a>
			<a href="<?php echo esc_url( add_query_arg( 'vista', 'oi', home_url( '/panel/' ) ) ); ?>"
				class="ct-btn ct-btn--sm <?php echo $es_vista_oi ? 'ct-btn--primary' : ''; ?>">
				<?php esc_html_e( 'Órdenes de Ingreso', 'calibratrack' ); ?>
			</a>
			<a href="<?php echo esc_url( add_query_arg( 'vista', 'ot', home_url( '/panel/' ) ) ); ?>"
				class="ct-btn ct-btn--sm <?php echo $es_vista_ot ? 'ct-btn--primary' : ''; ?>">
				<?php esc_html_e( 'Órdenes de Trabajo', 'calibratrack' ); ?>
			</a>
		</div>

		<?php /* ── SECCIÓN OI ── */ ?>
		<?php if ( $mostrar_oi ) : ?>
		<section class="ct-section" style="margin-bottom:28px;">
			<div class="ct-section-header">
				<h2 class="ct-section-title"><?php esc_html_e( 'Mis Órdenes de Ingreso', 'calibratrack' ); ?></h2>
				<?php if ( $es_dashboard ) : ?>
				<a href="<?php echo esc_url( add_query_arg( 'vista', 'oi', home_url( '/panel/' ) ) ); ?>" class="ct-link">
					<?php esc_html_e( 'Ver todas →', 'calibratrack' ); ?>
				</a>
				<?php endif; ?>
			</div>

			<?php if ( $es_vista_oi ) : ?>
			<form method="get" action="<?php echo esc_url( home_url( '/panel/' ) ); ?>" class="ct-filter-bar" style="margin-bottom:16px;">
				<input type="hidden" name="vista" value="oi">
				<input type="text" name="buscar" value="<?php echo esc_attr( $buscar ); ?>"
					placeholder="<?php esc_attr_e( 'Buscar por N° OI o serie…', 'calibratrack' ); ?>"
					class="ct-input ct-filter-search">
				<select name="tipo" class="ct-select ct-filter-select">
					<option value=""><?php esc_html_e( 'Todos los tipos', 'calibratrack' ); ?></option>
					<?php foreach ( $tipos_ev as $slug => $label ) : ?>
						<option value="<?php echo esc_attr( $slug ); ?>" <?php selected( $filtro_tipo, $slug ); ?>>
							<?php echo esc_html( $label ); ?>
						</option>
					<?php endforeach; ?>
				</select>
				<button type="submit" class="ct-btn ct-btn--primary"><?php esc_html_e( 'Filtrar', 'calibratrack' ); ?></button>
				<?php if ( $hay_filtros ) : ?>
					<a href="<?php echo esc_url( add_query_arg( 'vista', 'oi', home_url( '/panel/' ) ) ); ?>" class="ct-btn ct-btn--secondary">
						<?php esc_html_e( 'Limpiar', 'calibratrack' ); ?>
					</a>
				<?php endif; ?>
			</form>
			<?php endif; ?>

			<?php if ( empty( $ois ) ) : ?>
				<p class="ct-empty-msg"><?php esc_html_e( 'No tienes órdenes de ingreso asignadas.', 'calibratrack' ); ?></p>
			<?php else : ?>
			<div class="ct-table-wrap">
				<table class="ct-table">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Fecha recep.', 'calibratrack' ); ?></th>
							<th><?php esc_html_e( 'N° OI', 'calibratrack' ); ?></th>
							<th><?php esc_html_e( 'Equipo (serie)', 'calibratrack' ); ?></th>
							<th><?php esc_html_e( 'Tipo servicio', 'calibratrack' ); ?></th>
							<th><?php esc_html_e( 'OT vinculada', 'calibratrack' ); ?></th>
							<th></th>
						</tr>
					</thead>
					<tbody>
					<?php foreach ( $ois as $oi ) :
						$eq_id      = (int) get_post_meta( $oi->ID, CalibraTrack_Meta_Keys::EVENTO_EQUIPO_ID, true );
						$numero_oi  = (string) get_post_meta( $oi->ID, CalibraTrack_Meta_Keys::EVENTO_NUMERO_OI, true );
						$tipo       = (string) get_post_meta( $oi->ID, CalibraTrack_Meta_Keys::EVENTO_TIPO, true );
						$fecha_raw  = (string) get_post_meta( $oi->ID, CalibraTrack_Meta_Keys::EVENTO_FECHA_EJECUCION, true );
						$serie      = $eq_id ? (string) get_post_meta( $eq_id, CalibraTrack_Meta_Keys::EQUIPO_SERIE, true ) : '—';
						$tipo_label = isset( $tipos_ev[ $tipo ] ) ? $tipos_ev[ $tipo ] : ( $tipo ?: '—' );
						$dt         = $fecha_raw ? DateTime::createFromFormat( 'Y-m-d', $fecha_raw ) : null;

						// OT vinculada a esta OI — desde el batch pre-cargado (0 queries extra).
						$ot_data   = isset( $oi_ot_map[ $oi->ID ] ) ? $oi_ot_map[ $oi->ID ] : array( 'ot_id' => 0, 'numero_ot' => '' );
						$ot_id     = $ot_data['ot_id'];
						$ot_numero = $ot_data['numero_ot'];
					?>
					<tr>
						<td data-label="<?php esc_attr_e( 'Fecha recep.', 'calibratrack' ); ?>">
							<?php echo $dt ? esc_html( $dt->format( 'd/m/Y' ) ) : '—'; ?>
						</td>
						<td data-label="<?php esc_attr_e( 'N° OI', 'calibratrack' ); ?>">
							<strong><?php echo esc_html( $numero_oi ?: '—' ); ?></strong>
						</td>
						<td data-label="<?php esc_attr_e( 'Equipo (serie)', 'calibratrack' ); ?>">
							<?php echo esc_html( $serie ?: '—' ); ?>
						</td>
						<td data-label="<?php esc_attr_e( 'Tipo servicio', 'calibratrack' ); ?>">
							<?php echo esc_html( $tipo_label ); ?>
						</td>
						<td data-label="<?php esc_attr_e( 'OT vinculada', 'calibratrack' ); ?>">
							<?php if ( $ot_id ) : ?>
								<a href="<?php echo esc_url( home_url( '/panel/evento/' . $ot_id . '/' ) ); ?>" class="ct-link">
									<?php echo esc_html( $ot_numero ?: '#' . $ot_id ); ?>
								</a>
							<?php else : ?>
								<span style="color:#94a3b8;"><?php esc_html_e( 'Sin OT', 'calibratrack' ); ?></span>
							<?php endif; ?>
						</td>
						<td style="white-space:nowrap;">
							<a href="<?php echo esc_url( home_url( '/panel/oi/' . $oi->ID . '/' ) ); ?>" class="ct-btn ct-btn--sm">
								<?php esc_html_e( 'Ver', 'calibratrack' ); ?>
							</a>
							<a href="<?php echo esc_url( add_query_arg( 'imprimir', '1', home_url( '/panel/oi/' . $oi->ID . '/' ) ) ); ?>" class="ct-btn ct-btn--sm" style="margin-left:4px;">
								<?php esc_html_e( 'Imprimir', 'calibratrack' ); ?>
							</a>
						</td>
					</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			</div>
			<?php if ( $es_vista_oi && $ois_pages > 1 ) : ?>
			<nav class="ct-paginacion">
				<?php
				$base_oi = add_query_arg( array_filter( array( 'vista' => 'oi', 'buscar' => $buscar ?: null, 'tipo' => $filtro_tipo ?: null ) ), home_url( '/panel/' ) );
				for ( $p = 1; $p <= $ois_pages; $p++ ) : ?>
					<a href="<?php echo esc_url( add_query_arg( 'pagina', $p, $base_oi ) ); ?>"
						class="ct-btn ct-btn--sm <?php echo $p === $paged ? 'ct-btn--primary' : ''; ?>">
						<?php echo (int) $p; ?>
					</a>
				<?php endfor; ?>
			</nav>
			<?php endif; ?>
			<?php endif; ?>
		</section>
		<?php endif; ?>

		<?php /* ── SECCIÓN OT ── */ ?>
		<?php if ( $mostrar_ot ) : ?>
		<section class="ct-section">
			<div class="ct-section-header">
				<h2 class="ct-section-title"><?php esc_html_e( 'Mis Órdenes de Trabajo', 'calibratrack' ); ?></h2>
				<?php if ( $es_dashboard ) : ?>
				<a href="<?php echo esc_url( add_query_arg( 'vista', 'ot', home_url( '/panel/' ) ) ); ?>" class="ct-link">
					<?php esc_html_e( 'Ver todas →', 'calibratrack' ); ?>
				</a>
				<?php endif; ?>
			</div>

			<?php if ( $es_vista_ot ) : ?>
			<form method="get" action="<?php echo esc_url( home_url( '/panel/' ) ); ?>" class="ct-filter-bar" style="margin-bottom:16px;">
				<input type="hidden" name="vista" value="ot">
				<input type="text" name="buscar" value="<?php echo esc_attr( $buscar ); ?>"
					placeholder="<?php esc_attr_e( 'Buscar por N° OT o serie…', 'calibratrack' ); ?>"
					class="ct-input ct-filter-search">
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
					<?php foreach ( $estados_srv_cfg as $slug => $cfg ) : ?>
						<option value="<?php echo esc_attr( $slug ); ?>" <?php selected( $filtro_estado, $slug ); ?>>
							<?php echo esc_html( $cfg['label'] ); ?>
						</option>
					<?php endforeach; ?>
				</select>
				<button type="submit" class="ct-btn ct-btn--primary"><?php esc_html_e( 'Filtrar', 'calibratrack' ); ?></button>
				<?php if ( $hay_filtros ) : ?>
					<a href="<?php echo esc_url( add_query_arg( 'vista', 'ot', home_url( '/panel/' ) ) ); ?>" class="ct-btn ct-btn--secondary">
						<?php esc_html_e( 'Limpiar', 'calibratrack' ); ?>
					</a>
				<?php endif; ?>
			</form>
			<?php endif; ?>

			<?php if ( empty( $ots ) ) : ?>
				<p class="ct-empty-msg"><?php esc_html_e( 'No tienes órdenes de trabajo asignadas.', 'calibratrack' ); ?></p>
			<?php else : ?>
			<div class="ct-table-wrap">
				<table class="ct-table">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Fecha', 'calibratrack' ); ?></th>
							<th><?php esc_html_e( 'N° OT', 'calibratrack' ); ?></th>
							<th><?php esc_html_e( 'Equipo (serie)', 'calibratrack' ); ?></th>
							<th><?php esc_html_e( 'Tipo', 'calibratrack' ); ?></th>
							<th><?php esc_html_e( 'Estado', 'calibratrack' ); ?></th>
							<th><?php esc_html_e( 'OI vinculada', 'calibratrack' ); ?></th>
							<th></th>
						</tr>
					</thead>
					<tbody>
					<?php foreach ( $ots as $ot ) :
						$eq_id      = (int) get_post_meta( $ot->ID, CalibraTrack_Meta_Keys::EVENTO_EQUIPO_ID, true );
						$numero_ot  = (string) get_post_meta( $ot->ID, CalibraTrack_Meta_Keys::EVENTO_NUMERO_OT, true );
						$tipo       = (string) get_post_meta( $ot->ID, CalibraTrack_Meta_Keys::EVENTO_TIPO, true );
						$fecha_raw  = (string) get_post_meta( $ot->ID, CalibraTrack_Meta_Keys::EVENTO_FECHA_EJECUCION, true );
						$estado_srv = (string) get_post_meta( $ot->ID, CalibraTrack_Meta_Keys::EVENTO_ESTADO_SERVICIO, true );
						$oi_id      = (int) get_post_meta( $ot->ID, CalibraTrack_Meta_Keys::EVENTO_INGRESO_RELACIONADO_ID, true );
						$serie      = $eq_id ? (string) get_post_meta( $eq_id, CalibraTrack_Meta_Keys::EQUIPO_SERIE, true ) : '—';
						$tipo_label = isset( $tipos_ev[ $tipo ] ) ? $tipos_ev[ $tipo ] : ( $tipo ?: '—' );
						$dt         = $fecha_raw ? DateTime::createFromFormat( 'Y-m-d', $fecha_raw ) : null;
						if ( empty( $estado_srv ) ) { $estado_srv = 'en_proceso'; }
						$est_cfg    = isset( $estados_srv_cfg[ $estado_srv ] ) ? $estados_srv_cfg[ $estado_srv ] : $estados_srv_cfg['en_proceso'];
						$oi_numero  = $oi_id ? (string) get_post_meta( $oi_id, CalibraTrack_Meta_Keys::EVENTO_NUMERO_OI, true ) : '';
					?>
					<tr>
						<td data-label="<?php esc_attr_e( 'Fecha', 'calibratrack' ); ?>">
							<?php echo $dt ? esc_html( $dt->format( 'd/m/Y' ) ) : '—'; ?>
						</td>
						<td data-label="<?php esc_attr_e( 'N° OT', 'calibratrack' ); ?>">
							<strong><?php echo esc_html( $numero_ot ?: '—' ); ?></strong>
						</td>
						<td data-label="<?php esc_attr_e( 'Equipo (serie)', 'calibratrack' ); ?>">
							<?php echo esc_html( $serie ?: '—' ); ?>
						</td>
						<td data-label="<?php esc_attr_e( 'Tipo', 'calibratrack' ); ?>">
							<?php echo esc_html( $tipo_label ); ?>
						</td>
						<td data-label="<?php esc_attr_e( 'Estado', 'calibratrack' ); ?>">
							<span class="ct-badge <?php echo esc_attr( $est_cfg['clase'] ); ?>">
								<?php echo esc_html( $est_cfg['label'] ); ?>
							</span>
						</td>
						<td data-label="<?php esc_attr_e( 'OI vinculada', 'calibratrack' ); ?>">
							<?php if ( $oi_id ) : ?>
								<a href="<?php echo esc_url( home_url( '/panel/oi/' . $oi_id . '/' ) ); ?>" class="ct-link">
									<?php echo esc_html( $oi_numero ?: '#' . $oi_id ); ?>
								</a>
							<?php else : ?>
								<span style="color:#94a3b8;">—</span>
							<?php endif; ?>
						</td>
						<td>
							<a href="<?php echo esc_url( home_url( '/panel/evento/' . $ot->ID . '/' ) ); ?>" class="ct-btn ct-btn--sm">
								<?php esc_html_e( 'Editar', 'calibratrack' ); ?>
							</a>
						</td>
					</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			</div>
			<?php if ( $es_vista_ot && $ots_pages > 1 ) : ?>
			<nav class="ct-paginacion">
				<?php
				$base_ot = add_query_arg( array_filter( array( 'vista' => 'ot', 'buscar' => $buscar ?: null, 'tipo' => $filtro_tipo ?: null, 'estado' => $filtro_estado ?: null ) ), home_url( '/panel/' ) );
				for ( $p = 1; $p <= $ots_pages; $p++ ) : ?>
					<a href="<?php echo esc_url( add_query_arg( 'pagina', $p, $base_ot ) ); ?>"
						class="ct-btn ct-btn--sm <?php echo $p === $paged ? 'ct-btn--primary' : ''; ?>">
						<?php echo (int) $p; ?>
					</a>
				<?php endfor; ?>
			</nav>
			<?php endif; ?>
			<?php endif; ?>
		</section>
		<?php endif; ?>

	</div>

	<?php include __DIR__ . '/_partials/footer.php';
}
