<?php
/**
 * Bootstrap del módulo de administración de CalibraTrack.
 *
 * Implementa:
 *  - save_post_equipo:            guardado con validación de serie única y RUT.
 *  - save_post_cliente:           guardado con validación de RUT.
 *  - save_post_evento_servicio:   guardado con ítems de costo, invalidación de transient.
 *  - Filtro user_has_cap:         técnico solo puede editar sus propios eventos.
 *
 * SEGURIDAD:
 *  - Todos los formularios verifican nonce antes de procesar.
 *  - Todos los valores se sanitizan antes de guardar.
 *  - Los cálculos de totales se hacen en servidor (nunca se confía en valores del navegador).
 *  - El técnico solo puede guardar eventos de los que es autor.
 *
 * @package CalibraTrack
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class CalibraTrack_Admin
 */
class CalibraTrack_Admin {

	/**
	 * Nombre del nonce para el formulario de equipo.
	 */
	const NONCE_EQUIPO = 'calibratrack_save_equipo';

	/**
	 * Nombre del nonce para el formulario de cliente.
	 */
	const NONCE_CLIENTE = 'calibratrack_save_cliente';

	/**
	 * Nombre del nonce para el formulario de evento de servicio.
	 */
	const NONCE_EVENTO = 'calibratrack_save_evento';

	/**
	 * Inicializa los hooks del módulo admin.
	 * Se llama desde calibratrack_init() solo cuando is_admin() es true.
	 *
	 * @return void
	 */
	public static function init() {
		// §9 — Renombrar archivos PDF y fotografías al nombre no adivinable (UUID)
		// en el momento de la subida a la Media Library, para que la URL de WordPress
		// uploads/ no sea predecible ni indexable.
		// Se aplica solo a PDFs y a imágenes subidas en las páginas de los CPTs del plugin.
		add_filter( 'wp_handle_upload_prefilter', array( __CLASS__, 'hash_uploaded_filename' ) );

		// Guardado de metaboxes.
		add_action( 'save_post_equipo',           array( __CLASS__, 'save_equipo_meta' ), 10, 3 );
		add_action( 'save_post_cliente',          array( __CLASS__, 'save_cliente_meta' ), 10, 3 );
		add_action( 'save_post_evento_servicio',  array( __CLASS__, 'save_evento_meta' ), 10, 3 );

		// Limpieza de ítems de costo al borrar un evento de forma definitiva.
		add_action( 'before_delete_post', array( __CLASS__, 'on_before_delete_post' ) );

		// Restricción de capabilities: técnico solo edita sus propios eventos.
		add_filter( 'user_has_cap', array( __CLASS__, 'filter_tecnico_solo_propios_eventos' ), 10, 4 );

		// Menú de administración.
		add_action( 'admin_menu', array( __CLASS__, 'register_admin_menu' ) );

		// Redirigir "→ Panel de Administración" antes de que WP emita HTML.
		// Debe estar en admin_init, no en el callback del submenú, para evitar
		// "headers already sent" (el callback se invoca después del <head>).
		add_action( 'admin_init', array( __CLASS__, 'maybe_redirect_to_admin_panel' ) );

		// Bloquear acceso directo a pantallas de evento_servicio en wp-admin.
		// Los eventos se gestionan exclusivamente desde /tecnico/.
		add_action( 'load-edit.php', array( __CLASS__, 'redirect_evento_admin_screens' ) );
		add_action( 'load-post.php', array( __CLASS__, 'redirect_evento_admin_screens' ) );
		add_action( 'load-post-new.php', array( __CLASS__, 'redirect_evento_admin_screens' ) );

		// Metaboxes.
		add_action( 'add_meta_boxes', array( __CLASS__, 'register_metaboxes' ) );

		// Columnas personalizadas en lista de equipos.
		add_filter( 'manage_equipo_posts_columns',        array( __CLASS__, 'equipo_columns' ) );
		add_action( 'manage_equipo_posts_custom_column',  array( __CLASS__, 'equipo_column_content' ), 10, 2 );

		// Aviso de errores de validación en el admin.
		add_action( 'admin_notices', array( __CLASS__, 'show_admin_notices' ) );

		// Assets del admin (solo en páginas del plugin).
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_admin_assets' ) );

		// Campos de perfil del técnico (cargo y firma) en wp-admin.
		add_action( 'show_user_profile',        array( __CLASS__, 'render_tecnico_profile_fields' ) );
		add_action( 'edit_user_profile',        array( __CLASS__, 'render_tecnico_profile_fields' ) );
		add_action( 'personal_options_update',  array( __CLASS__, 'save_tecnico_profile_fields' ) );
		add_action( 'edit_user_profile_update', array( __CLASS__, 'save_tecnico_profile_fields' ) );

		// El formulario de perfil de wp-admin no tiene enctype multipart por defecto.
		// Sin este filtro la subida de la firma nunca llegaría a $_FILES.
		add_filter( 'user_edit_form_tag', array( __CLASS__, 'add_enctype_to_profile_form' ) );

		// Página de configuración del plugin.
		add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );

		// AJAX: búsqueda de productos WooCommerce para el selector de ítems de costo.
		add_action( 'wp_ajax_calibratrack_buscar_productos_wc', array( __CLASS__, 'ajax_buscar_productos_wc' ) );

		// AJAX: búsqueda de productos WooCommerce para importar datos al crear un equipo.
		add_action( 'wp_ajax_calibratrack_buscar_equipo_wc', array( __CLASS__, 'ajax_buscar_equipo_wc' ) );
		add_action( 'wp_ajax_calibratrack_guardar_pago_ot', array( __CLASS__, 'ajax_guardar_pago_ot' ) );

		// Filtrar lista de evento_servicio por tipo de documento (OI / OT) si viene el parámetro ct_tipo.
		add_action( 'pre_get_posts', array( __CLASS__, 'filtrar_eventos_por_tipo' ) );

		// Agregar columna "Tipo" en la lista de evento_servicio.
		add_filter( 'manage_evento_servicio_posts_columns',       array( __CLASS__, 'evento_servicio_columns' ) );
		add_action( 'manage_evento_servicio_posts_custom_column', array( __CLASS__, 'evento_servicio_column_content' ), 10, 2 );
	}

	/**
	 * Encola CSS y JS del admin SOLO en páginas de los CPTs del plugin.
	 *
	 * @param string $hook Slug de la página actual del admin.
	 * @return void
	 */
	public static function enqueue_admin_assets( $hook ) {
		$screen = get_current_screen();
		if ( ! $screen ) {
			return;
		}

		$cpts_validos = array(
			CalibraTrack_CPT_Equipo::SLUG,
			CalibraTrack_CPT_Cliente::SLUG,
			CalibraTrack_CPT_EventoServicio::SLUG,
		);

		// Solo en páginas de edición o listado de los CPTs del plugin.
		$hooks_validos = array( 'post.php', 'post-new.php', 'edit.php' );
		if ( ! in_array( $hook, $hooks_validos, true ) || ! in_array( $screen->post_type, $cpts_validos, true ) ) {
			// También incluir la página de dashboard del plugin.
			if ( 'toplevel_page_calibratrack' !== $hook ) {
				return;
			}
		}

		// Chart.js solo en el dashboard de CalibraTrack.
		// Se carga en el <head> (false) para que esté disponible cuando el inline
		// script de inicialización de gráficos corre dentro del HTML de la página.
		if ( 'toplevel_page_calibratrack' === $hook ) {
			wp_enqueue_script(
				'chartjs',
				'https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js',
				array(),
				'4.4.4',
				false
			);
		}

		// CSS del admin.
		wp_enqueue_style(
			'calibratrack-admin',
			CALIBRATRACK_PLUGIN_URL . 'assets/css/admin.css',
			array(),
			CALIBRATRACK_VERSION
		);

		// JS del admin. Depende de jquery y wp-mediaelement para wp.media.
		wp_enqueue_script(
			'calibratrack-admin',
			CALIBRATRACK_PLUGIN_URL . 'assets/js/admin.js',
			array( 'jquery', 'media-upload', 'thickbox' ),
			CALIBRATRACK_VERSION,
			true
		);

		// Cargar la Media Library de WP.
		wp_enqueue_media();

		// Pasar variables PHP→JS.
		global $post;
		$qr_img_url    = '';
		$equipo_serie  = '';
		$equipo_marca  = '';
		$equipo_modelo = '';

		if ( $post && CalibraTrack_CPT_Equipo::SLUG === $post->post_type ) {
			$qr_att_id  = (int) get_post_meta( $post->ID, CalibraTrack_Meta_Keys::EQUIPO_CODIGO_QR, true );
			$qr_img_url = $qr_att_id ? (string) wp_get_attachment_url( $qr_att_id ) : '';
			$equipo_serie  = (string) get_post_meta( $post->ID, CalibraTrack_Meta_Keys::EQUIPO_SERIE, true );
			$equipo_marca  = (string) get_post_meta( $post->ID, CalibraTrack_Meta_Keys::EQUIPO_MARCA, true );
			$equipo_modelo = (string) get_post_meta( $post->ID, CalibraTrack_Meta_Keys::EQUIPO_MODELO, true );
		}

		wp_localize_script(
			'calibratrack-admin',
			'calibratrackAdmin',
			array(
				'qrImgUrl'     => esc_url( $qr_img_url ),
				'equipoSerie'  => esc_js( $equipo_serie ),
				'equipoMarca'  => esc_js( $equipo_marca ),
				'equipoModelo' => esc_js( $equipo_modelo ),
				'i18n'         => array(
					'seleccionar_fotos' => __( 'Seleccionar fotografías', 'calibratrack' ),
					'seleccionar_cert'  => __( 'Seleccionar certificado PDF', 'calibratrack' ),
					'seleccionar_ot'    => __( 'Seleccionar OT PDF', 'calibratrack' ),
					'sin_archivo'       => __( 'Sin archivo seleccionado', 'calibratrack' ),
					'agregar_item'      => __( 'Agregar ítem', 'calibratrack' ),
					'quitar'            => __( 'Quitar', 'calibratrack' ),
				),
			)
		);
	}

	// ─── MENÚ Y METABOXES (estructura) ──────────────────────────────────────────

	/**
	 * Registra el menú principal CalibraTrack y sus submenús.
	 *
	 * @return void
	 */
	public static function register_admin_menu() {
		add_menu_page(
			__( 'CalibraTrack', 'calibratrack' ),
			__( 'CalibraTrack', 'calibratrack' ),
			'read',
			'calibratrack',
			array( __CLASS__, 'render_dashboard_page' ),
			'dashicons-networking',
			25
		);

		add_submenu_page(
			'calibratrack',
			__( 'Dashboard', 'calibratrack' ),
			__( 'Dashboard', 'calibratrack' ),
			'read',
			'calibratrack',
			array( __CLASS__, 'render_dashboard_page' )
		);

		// ── Panel de Administración (OI y OT se gestionan en /panel/) ──────────
		// No se registran submenús de wp-admin para OI/OT: el panel frontend
		// es la única interfaz de creación y edición de órdenes.
		add_submenu_page(
			'calibratrack',
			__( 'Panel de Administración', 'calibratrack' ),
			__( '→ Panel de Administración', 'calibratrack' ),
			'manage_options',
			'calibratrack-admin-panel',
			array( __CLASS__, 'redirect_to_admin_panel' )
		);

		add_submenu_page(
			'calibratrack',
			__( 'Liquidación Técnicos', 'calibratrack' ),
			__( 'Liquidación Técnicos', 'calibratrack' ),
			'manage_options',
			'calibratrack-liquidacion',
			array( __CLASS__, 'render_liquidacion_page' )
		);

		add_submenu_page(
			'calibratrack',
			__( 'Configuración', 'calibratrack' ),
			__( 'Configuración', 'calibratrack' ),
			'manage_options',
			'calibratrack-settings',
			array( __CLASS__, 'render_settings_page' )
		);
	}

	/**
	 * Redirige las pantallas de lista/edición de evento_servicio en wp-admin
	 * hacia el panel de técnico (/tecnico/). Los eventos se gestionan
	 * exclusivamente desde el frontend técnico, no desde wp-admin.
	 *
	 * Hook: load-edit.php, load-post.php, load-post-new.php
	 *
	 * @return void
	 */
	/**
	 * Redirige a /tecnico/eventos/ si un técnico intenta acceder a las pantallas
	 * de evento_servicio en wp-admin. Los administradores tienen acceso completo.
	 *
	 * @return void
	 */
	public static function redirect_evento_admin_screens() {
		$post_type = isset( $_GET['post_type'] ) ? sanitize_key( $_GET['post_type'] ) : '';

		// Para load-post.php y load-post-new.php, detectar por el post ID.
		if ( empty( $post_type ) && isset( $_GET['post'] ) ) {
			$post_type = (string) get_post_type( absint( $_GET['post'] ) );
		}

		if ( 'evento_servicio' !== $post_type ) {
			return;
		}

		// Administradores: redirigir al panel frontend /administrar/.
		if ( current_user_can( 'manage_options' ) ) {
			// Si es post-new.php, ir a nueva OI por defecto.
			$preset = isset( $_GET['ct_tipo_preset'] ) ? sanitize_key( $_GET['ct_tipo_preset'] ) : '';
			if ( 'ot' === $preset ) {
				wp_redirect( home_url( '/administrar/nueva-ot/' ) );
			} elseif ( isset( $_GET['post'] ) && absint( $_GET['post'] ) > 0 ) {
				// Edición de un evento existente: determinar si es OI u OT.
				$evento_id  = absint( $_GET['post'] );
				$tipo_doc   = get_post_meta( $evento_id, 'calibratrack_tipo_documento', true );
				if ( 'ot' === $tipo_doc ) {
					wp_redirect( home_url( '/administrar/ot/' . $evento_id . '/' ) );
				} else {
					wp_redirect( home_url( '/administrar/oi/' . $evento_id . '/' ) );
				}
			} else {
				wp_redirect( home_url( '/administrar/nueva-oi/' ) );
			}
			exit;
		}

		// Técnicos y otros roles: redirigir al panel técnico.
		wp_redirect( home_url( '/tecnico/eventos/' ) );
		exit;
	}

	/**
	 * Redirige al panel de administración frontend si se detecta la página
	 * del submenú "→ Panel de Administración" ANTES de que WordPress emita HTML.
	 *
	 * Hook: admin_init (corre antes de cualquier output).
	 *
	 * @return void
	 */
	public static function maybe_redirect_to_admin_panel() {
		if ( ! isset( $_GET['page'] ) || 'calibratrack-admin-panel' !== $_GET['page'] ) {
			return;
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		wp_redirect( home_url( '/panel/' ) );
		exit;
	}

	/**
	 * Fallback: si por alguna razón admin_init no redirigió (no debería ocurrir),
	 * mostrar un enlace manual en lugar de una página en blanco.
	 *
	 * @return void
	 */
	public static function redirect_to_admin_panel() {
		$url = esc_url( home_url( '/panel/' ) );
		echo '<div class="wrap"><p>';
		printf(
			/* translators: %s: URL del panel */
			esc_html__( 'Redirigiendo… Si no ocurre automáticamente, %s.', 'calibratrack' ),
			'<a href="' . $url . '">' . esc_html__( 'haz clic aquí', 'calibratrack' ) . '</a>'
		);
		echo '</p></div>';
		echo '<script>window.location.href=' . wp_json_encode( home_url( '/panel/' ) ) . ';</script>';
	}

	/**
	 * Renderiza la página de dashboard del plugin.
	 *
	 * @return void
	 */
	public static function render_dashboard_page() {
		if ( ! current_user_can( 'read' ) ) {
			wp_die( esc_html__( 'No tiene permisos para acceder a esta página.', 'calibratrack' ) );
		}

		// ── Datos KPI: estado de todos los equipos ────────────────────────────
		$equipos_ids = get_posts( array(
			'post_type'      => 'equipo',
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'fields'         => 'ids',
		) );

		$kpi = array(
			'total'      => count( $equipos_ids ),
			'vigente'    => 0,
			'por_vencer' => 0,
			'vencido'    => 0,
			'sin_evento' => 0,
		);
		foreach ( $equipos_ids as $eq_id ) {
			$ultimo  = CalibraTrack_DB::get_ultimo_evento( $eq_id );
			$proxima = $ultimo ? (string) $ultimo->proxima_fecha_control : '';
			$kpi[ CalibraTrack_Helpers::calcular_estado_vigencia( $proxima ) ]++;
		}

		// ── Eventos por mes: últimos 6 meses, todos los técnicos ─────────────
		global $wpdb;
		$inicio_6m = gmdate( 'Y-m-01', strtotime( '-5 months' ) );
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery
		$eventos_mes_raw = $wpdb->get_results( $wpdb->prepare(
			"SELECT DATE_FORMAT(post_date, '%%Y-%%m') AS mes, COUNT(*) AS total
			 FROM {$wpdb->posts}
			 WHERE post_type   = 'evento_servicio'
			   AND post_status = 'publish'
			   AND post_date  >= %s
			 GROUP BY mes
			 ORDER BY mes ASC",
			$inicio_6m
		) );
		// phpcs:enable
		$meses_es  = array( 1 => 'Ene', 2 => 'Feb', 3 => 'Mar', 4 => 'Abr', 5 => 'May', 6 => 'Jun',
		                    7 => 'Jul', 8 => 'Ago', 9 => 'Sep', 10 => 'Oct', 11 => 'Nov', 12 => 'Dic' );
		$meses_map = array();
		foreach ( $eventos_mes_raw as $row ) {
			$meses_map[ $row->mes ] = (int) $row->total;
		}
		$meses_labels = array();
		$meses_data   = array();
		for ( $i = 5; $i >= 0; $i-- ) {
			$ts             = strtotime( "-$i months" );
			$key            = gmdate( 'Y-m', $ts );
			$meses_labels[] = $meses_es[ (int) gmdate( 'n', $ts ) ] . ' ' . gmdate( 'y', $ts );
			$meses_data[]   = isset( $meses_map[ $key ] ) ? $meses_map[ $key ] : 0;
		}

		// ── Tipo de servicio: todos los eventos ───────────────────────────────
		$tipo_ids = get_posts( array(
			'post_type'      => 'evento_servicio',
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'fields'         => 'ids',
		) );
		// Mapeo de slugs anteriores → nuevos (compatibilidad con registros existentes).
		$tipo_slug_map = array(
			'calibracion'   => 'mantencion_calibracion',
			'mantenimiento' => 'mantencion_calibracion',
		);
		$tipo_count = array( 'reparacion' => 0, 'mantencion_calibracion' => 0 );
		foreach ( $tipo_ids as $ev_id ) {
			$t = (string) get_post_meta( $ev_id, CalibraTrack_Meta_Keys::EVENTO_TIPO, true );
			$t = isset( $tipo_slug_map[ $t ] ) ? $tipo_slug_map[ $t ] : $t;
			if ( isset( $tipo_count[ $t ] ) ) {
				$tipo_count[ $t ]++;
			}
		}

		// ── Total clientes ────────────────────────────────────────────────────
		$total_clientes = (int) wp_count_posts( 'cliente' )->publish;

		// ── Estadísticas OI / OT ──────────────────────────────────────────────
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$total_oi = (int) $wpdb->get_var(
			"SELECT COUNT(DISTINCT p.ID)
			 FROM {$wpdb->posts} p
			 INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID
			 WHERE p.post_type   = 'evento_servicio'
			   AND p.post_status = 'publish'
			   AND pm.meta_key   = 'calibratrack_tipo_documento'
			   AND pm.meta_value = 'ingreso'"
		);

		$total_ot = (int) $wpdb->get_var(
			"SELECT COUNT(DISTINCT p.ID)
			 FROM {$wpdb->posts} p
			 INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID
			 WHERE p.post_type   = 'evento_servicio'
			   AND p.post_status = 'publish'
			   AND pm.meta_key   = 'calibratrack_tipo_documento'
			   AND pm.meta_value = 'ot'"
		);

		// OT agrupadas por estado.
		$ot_por_estado_raw = $wpdb->get_results(
			"SELECT pm_est.meta_value AS estado, COUNT(DISTINCT p.ID) AS total
			 FROM {$wpdb->posts} p
			 INNER JOIN {$wpdb->postmeta} pm_tipo
			         ON pm_tipo.post_id   = p.ID
			        AND pm_tipo.meta_key  = 'calibratrack_tipo_documento'
			        AND pm_tipo.meta_value = 'ot'
			 LEFT JOIN {$wpdb->postmeta} pm_est
			        ON pm_est.post_id  = p.ID
			       AND pm_est.meta_key = 'calibratrack_estado_servicio'
			 WHERE p.post_type   = 'evento_servicio'
			   AND p.post_status = 'publish'
			 GROUP BY pm_est.meta_value"
		);
		$ot_estados = array(
			'en_proceso'     => 0,
			'en_ejecucion'   => 0,
			'listo_revision' => 0,
			'completado'     => 0,
		);
		foreach ( $ot_por_estado_raw as $row ) {
			$k = isset( $row->estado ) ? (string) $row->estado : '';
			if ( isset( $ot_estados[ $k ] ) ) {
				$ot_estados[ $k ] = (int) $row->total;
			}
		}

		// OI sin OT vinculada.
		$oi_sin_ot = (int) $wpdb->get_var(
			"SELECT COUNT(DISTINCT p.ID)
			 FROM {$wpdb->posts} p
			 INNER JOIN {$wpdb->postmeta} pm_tipo
			         ON pm_tipo.post_id    = p.ID
			        AND pm_tipo.meta_key   = 'calibratrack_tipo_documento'
			        AND pm_tipo.meta_value = 'ingreso'
			 WHERE p.post_type   = 'evento_servicio'
			   AND p.post_status = 'publish'
			   AND NOT EXISTS (
			       SELECT 1
			       FROM {$wpdb->postmeta} pm2
			       INNER JOIN {$wpdb->posts} p2 ON p2.ID = pm2.post_id AND p2.post_status = 'publish'
			       WHERE pm2.meta_key           = 'calibratrack_ingreso_relacionado_id'
			         AND CAST(pm2.meta_value AS UNSIGNED) = p.ID
			   )"
		);

		// Total facturado: suma del campo total de todas las OT.
		$total_facturado = (float) $wpdb->get_var(
			"SELECT COALESCE(SUM(pm_tot.meta_value), 0)
			 FROM {$wpdb->posts} p
			 INNER JOIN {$wpdb->postmeta} pm_tipo
			         ON pm_tipo.post_id    = p.ID
			        AND pm_tipo.meta_key   = 'calibratrack_tipo_documento'
			        AND pm_tipo.meta_value = 'ot'
			 INNER JOIN {$wpdb->postmeta} pm_tot
			         ON pm_tot.post_id  = p.ID
			        AND pm_tot.meta_key = 'calibratrack_total'
			 WHERE p.post_type   = 'evento_servicio'
			   AND p.post_status = 'publish'"
		);

		// OI y OT por mes — últimos 6 meses (reutiliza $inicio_6m definido antes).
		$oi_mes_raw = $wpdb->get_results( $wpdb->prepare(
			"SELECT DATE_FORMAT(p.post_date, '%%Y-%%m') AS mes, COUNT(*) AS total
			 FROM {$wpdb->posts} p
			 INNER JOIN {$wpdb->postmeta} pm
			         ON pm.post_id    = p.ID
			        AND pm.meta_key   = 'calibratrack_tipo_documento'
			        AND pm.meta_value = 'ingreso'
			 WHERE p.post_type   = 'evento_servicio'
			   AND p.post_status = 'publish'
			   AND p.post_date  >= %s
			 GROUP BY mes
			 ORDER BY mes ASC",
			$inicio_6m
		) );
		$ot_mes_raw = $wpdb->get_results( $wpdb->prepare(
			"SELECT DATE_FORMAT(p.post_date, '%%Y-%%m') AS mes, COUNT(*) AS total
			 FROM {$wpdb->posts} p
			 INNER JOIN {$wpdb->postmeta} pm
			         ON pm.post_id    = p.ID
			        AND pm.meta_key   = 'calibratrack_tipo_documento'
			        AND pm.meta_value = 'ot'
			 WHERE p.post_type   = 'evento_servicio'
			   AND p.post_status = 'publish'
			   AND p.post_date  >= %s
			 GROUP BY mes
			 ORDER BY mes ASC",
			$inicio_6m
		) );
		// phpcs:enable
		$oi_mes_map = array();
		foreach ( $oi_mes_raw as $row ) {
			$oi_mes_map[ $row->mes ] = (int) $row->total;
		}
		$ot_mes_map = array();
		foreach ( $ot_mes_raw as $row ) {
			$ot_mes_map[ $row->mes ] = (int) $row->total;
		}
		$oi_meses_data = array();
		$ot_meses_data = array();
		for ( $i = 5; $i >= 0; $i-- ) {
			$ts              = strtotime( "-$i months" );
			$key             = gmdate( 'Y-m', $ts );
			$oi_meses_data[] = isset( $oi_mes_map[ $key ] ) ? $oi_mes_map[ $key ] : 0;
			$ot_meses_data[] = isset( $ot_mes_map[ $key ] ) ? $ot_mes_map[ $key ] : 0;
		}

		// Últimas 5 OT (para tabla resumen).
		$ultimas_ot = get_posts( array(
			'post_type'      => 'evento_servicio',
			'post_status'    => 'publish',
			'posts_per_page' => 5,
			'orderby'        => 'date',
			'order'          => 'DESC',
			'meta_query'     => array(
				array(
					'key'   => 'calibratrack_tipo_documento',
					'value' => 'ot',
				),
			),
		) );

		$url_equipos      = admin_url( 'edit.php?post_type=equipo' );
		$url_clientes     = admin_url( 'edit.php?post_type=cliente' );
		$url_eventos      = admin_url( 'edit.php?post_type=evento_servicio' );
		$url_nuevo_equipo = admin_url( 'post-new.php?post_type=equipo' );
		$url_config       = admin_url( 'admin.php?page=calibratrack-settings' );
		?>
		<div class="wrap">
			<h1 style="display:flex;align-items:center;gap:10px;">
				<span class="dashicons dashicons-networking" style="font-size:30px;width:30px;height:30px;color:#00AEEF;"></span>
				<?php esc_html_e( 'CalibraTrack', 'calibratrack' ); ?>
			</h1>
			<p style="color:#666;margin-bottom:24px;"><?php esc_html_e( 'Sistema de trazabilidad y verificación pública de calibraciones.', 'calibratrack' ); ?></p>

			<?php /* ── KPI CARDS ── */ ?>
			<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:16px;margin-bottom:28px;">
				<?php
				$cards = array(
					array( 'label' => __( 'Equipos totales', 'calibratrack' ), 'val' => $kpi['total'],        'color' => '#00AEEF', 'icon' => 'dashicons-networking',     'url' => $url_equipos ),
					array( 'label' => __( 'Vigentes',        'calibratrack' ), 'val' => $kpi['vigente'],      'color' => '#22c55e', 'icon' => 'dashicons-yes-alt',        'url' => $url_equipos ),
					array( 'label' => __( 'Por vencer',      'calibratrack' ), 'val' => $kpi['por_vencer'],   'color' => '#f59e0b', 'icon' => 'dashicons-warning',        'url' => $url_equipos ),
					array( 'label' => __( 'Vencidos',        'calibratrack' ), 'val' => $kpi['vencido'],      'color' => '#ef4444', 'icon' => 'dashicons-dismiss',        'url' => $url_equipos ),
					array( 'label' => __( 'Sin calibración', 'calibratrack' ), 'val' => $kpi['sin_evento'],   'color' => '#94a3b8', 'icon' => 'dashicons-clock',          'url' => $url_equipos ),
					array( 'label' => __( 'Clientes',        'calibratrack' ), 'val' => $total_clientes,      'color' => '#8b5cf6', 'icon' => 'dashicons-businessperson',  'url' => $url_clientes ),
					array( 'label' => __( 'Eventos totales', 'calibratrack' ), 'val' => count( $tipo_ids ),   'color' => '#0ea5e9', 'icon' => 'dashicons-list-view',      'url' => $url_eventos ),
				);
				foreach ( $cards as $card ) : ?>
				<a href="<?php echo esc_url( $card['url'] ); ?>" style="text-decoration:none;">
					<div style="background:#fff;border-radius:8px;padding:18px 16px;box-shadow:0 1px 4px rgba(0,0,0,.08);border-top:4px solid <?php echo esc_attr( $card['color'] ); ?>;display:flex;flex-direction:column;gap:8px;transition:box-shadow .15s;" onmouseover="this.style.boxShadow='0 4px 12px rgba(0,0,0,.15)'" onmouseout="this.style.boxShadow='0 1px 4px rgba(0,0,0,.08)'">
						<span class="dashicons <?php echo esc_attr( $card['icon'] ); ?>" style="color:<?php echo esc_attr( $card['color'] ); ?>;font-size:22px;width:22px;height:22px;"></span>
						<span style="font-size:32px;font-weight:800;color:#1e293b;line-height:1;"><?php echo (int) $card['val']; ?></span>
						<span style="font-size:12px;font-weight:600;color:#64748b;text-transform:uppercase;letter-spacing:.05em;"><?php echo esc_html( $card['label'] ); ?></span>
					</div>
				</a>
				<?php endforeach; ?>
			</div>

			<?php /* ── KPI OI / OT ── */ ?>
			<h2 style="font-size:15px;font-weight:700;color:#374151;margin:24px 0 12px;"><?php esc_html_e( 'Órdenes de Ingreso y Trabajo', 'calibratrack' ); ?></h2>
			<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:16px;margin-bottom:28px;">
				<?php
				$url_ois          = home_url( '/panel/nueva-oi/' );
				$url_ots          = home_url( '/panel/nueva-ot/' );
				$url_panel        = home_url( '/panel/' );
				$url_eventos_oi   = admin_url( 'edit.php?post_type=evento_servicio&ct_tipo=ingreso' );
				$url_eventos_ot   = admin_url( 'edit.php?post_type=evento_servicio&ct_tipo=ot' );
				$kpi_oi_ot = array(
					array( 'label' => __( 'OI creadas',        'calibratrack' ), 'val' => $total_oi,                          'color' => '#6366f1', 'icon' => 'dashicons-clipboard',     'url' => $url_eventos_oi ),
					array( 'label' => __( 'OT creadas',        'calibratrack' ), 'val' => $total_ot,                          'color' => '#0ea5e9', 'icon' => 'dashicons-hammer',         'url' => $url_eventos_ot ),
					array( 'label' => __( 'OT completadas',    'calibratrack' ), 'val' => $ot_estados['completado'],           'color' => '#22c55e', 'icon' => 'dashicons-yes-alt',        'url' => $url_eventos_ot ),
					array( 'label' => __( 'En ejecución',      'calibratrack' ), 'val' => $ot_estados['en_ejecucion'],        'color' => '#f59e0b', 'icon' => 'dashicons-admin-tools',    'url' => $url_eventos_ot ),
					array( 'label' => __( 'Lista p/ revisión', 'calibratrack' ), 'val' => $ot_estados['listo_revision'],      'color' => '#8b5cf6', 'icon' => 'dashicons-visibility',     'url' => $url_eventos_ot ),
					array( 'label' => __( 'OI sin OT',         'calibratrack' ), 'val' => $oi_sin_ot,                         'color' => '#ef4444', 'icon' => 'dashicons-warning',        'url' => $url_eventos_oi ),
					array( 'label' => __( 'Total facturado',   'calibratrack' ), 'val' => '$' . number_format( $total_facturado, 0, ',', '.' ), 'color' => '#10b981', 'icon' => 'dashicons-money-alt', 'url' => $url_eventos_ot ),
				);
				foreach ( $kpi_oi_ot as $card ) : ?>
				<a href="<?php echo esc_url( $card['url'] ); ?>" style="text-decoration:none;">
					<div style="background:#fff;border-radius:8px;padding:18px 16px;box-shadow:0 1px 4px rgba(0,0,0,.08);border-top:4px solid <?php echo esc_attr( $card['color'] ); ?>;display:flex;flex-direction:column;gap:8px;transition:box-shadow .15s;" onmouseover="this.style.boxShadow='0 4px 12px rgba(0,0,0,.15)'" onmouseout="this.style.boxShadow='0 1px 4px rgba(0,0,0,.08)'">
						<span class="dashicons <?php echo esc_attr( $card['icon'] ); ?>" style="color:<?php echo esc_attr( $card['color'] ); ?>;font-size:22px;width:22px;height:22px;"></span>
						<span style="font-size:<?php echo is_numeric( $card['val'] ) ? '32px' : '22px'; ?>;font-weight:800;color:#1e293b;line-height:1;"><?php echo esc_html( $card['val'] ); ?></span>
						<span style="font-size:12px;font-weight:600;color:#64748b;text-transform:uppercase;letter-spacing:.05em;"><?php echo esc_html( $card['label'] ); ?></span>
					</div>
				</a>
				<?php endforeach; ?>
			</div>

			<?php /* ── GRÁFICOS ── */ ?>
			<div style="display:grid;grid-template-columns:1fr 1.8fr 1fr;gap:20px;margin-bottom:28px;align-items:start;">

				<div style="background:#fff;border-radius:8px;padding:20px;box-shadow:0 1px 4px rgba(0,0,0,.08);">
					<h3 style="margin:0 0 16px;font-size:14px;font-weight:700;color:#374151;"><?php esc_html_e( 'Estado de equipos', 'calibratrack' ); ?></h3>
					<div style="max-width:240px;margin:0 auto;">
						<canvas id="ct-adm-chart-estados"></canvas>
					</div>
				</div>

				<div style="background:#fff;border-radius:8px;padding:20px;box-shadow:0 1px 4px rgba(0,0,0,.08);">
					<h3 style="margin:0 0 16px;font-size:14px;font-weight:700;color:#374151;"><?php esc_html_e( 'Eventos de servicio — últimos 6 meses', 'calibratrack' ); ?></h3>
					<canvas id="ct-adm-chart-meses"></canvas>
				</div>

				<div style="background:#fff;border-radius:8px;padding:20px;box-shadow:0 1px 4px rgba(0,0,0,.08);">
					<h3 style="margin:0 0 16px;font-size:14px;font-weight:700;color:#374151;"><?php esc_html_e( 'Tipo de servicio', 'calibratrack' ); ?></h3>
					<div style="max-width:240px;margin:0 auto;">
						<canvas id="ct-adm-chart-tipos"></canvas>
					</div>
				</div>

			</div>

			<?php /* ── GRÁFICOS OI / OT ── */ ?>
			<div style="display:grid;grid-template-columns:1fr 2fr;gap:20px;margin-bottom:28px;align-items:start;">

				<div style="background:#fff;border-radius:8px;padding:20px;box-shadow:0 1px 4px rgba(0,0,0,.08);">
					<h3 style="margin:0 0 16px;font-size:14px;font-weight:700;color:#374151;"><?php esc_html_e( 'Estado de OT', 'calibratrack' ); ?></h3>
					<div style="max-width:260px;margin:0 auto;">
						<canvas id="ct-adm-chart-ot-estados"></canvas>
					</div>
				</div>

				<div style="background:#fff;border-radius:8px;padding:20px;box-shadow:0 1px 4px rgba(0,0,0,.08);">
					<h3 style="margin:0 0 16px;font-size:14px;font-weight:700;color:#374151;"><?php esc_html_e( 'OI y OT — últimos 6 meses', 'calibratrack' ); ?></h3>
					<canvas id="ct-adm-chart-oi-ot-mes"></canvas>
				</div>

			</div>

			<?php /* ── ÚLTIMAS OT ── */ ?>
			<?php if ( ! empty( $ultimas_ot ) ) : ?>
			<div style="background:#fff;border-radius:8px;padding:20px;box-shadow:0 1px 4px rgba(0,0,0,.08);margin-bottom:28px;">
				<h3 style="margin:0 0 16px;font-size:14px;font-weight:700;color:#374151;"><?php esc_html_e( 'Últimas órdenes de trabajo', 'calibratrack' ); ?></h3>
				<table style="width:100%;border-collapse:collapse;font-size:13px;">
					<thead>
						<tr style="border-bottom:2px solid #e2e8f0;">
							<th style="text-align:left;padding:6px 10px;color:#64748b;font-weight:600;"><?php esc_html_e( 'N° OT', 'calibratrack' ); ?></th>
							<th style="text-align:left;padding:6px 10px;color:#64748b;font-weight:600;"><?php esc_html_e( 'Equipo', 'calibratrack' ); ?></th>
							<th style="text-align:left;padding:6px 10px;color:#64748b;font-weight:600;"><?php esc_html_e( 'Tipo', 'calibratrack' ); ?></th>
							<th style="text-align:left;padding:6px 10px;color:#64748b;font-weight:600;"><?php esc_html_e( 'Estado', 'calibratrack' ); ?></th>
							<th style="text-align:left;padding:6px 10px;color:#64748b;font-weight:600;"><?php esc_html_e( 'Fecha', 'calibratrack' ); ?></th>
							<th style="text-align:right;padding:6px 10px;color:#64748b;font-weight:600;"><?php esc_html_e( 'Total', 'calibratrack' ); ?></th>
						</tr>
					</thead>
					<tbody>
					<?php
					$estado_labels = array(
						'en_proceso'     => array( 'label' => __( 'En proceso',        'calibratrack' ), 'color' => '#94a3b8' ),
						'en_ejecucion'   => array( 'label' => __( 'En ejecución',      'calibratrack' ), 'color' => '#f59e0b' ),
						'listo_revision' => array( 'label' => __( 'Lista p/ revisión', 'calibratrack' ), 'color' => '#8b5cf6' ),
						'completado'     => array( 'label' => __( 'Completado',        'calibratrack' ), 'color' => '#22c55e' ),
					);
					$tipos_ev_labels = CalibraTrack_Helpers::get_tipos_evento();
					foreach ( $ultimas_ot as $idx => $ot_post ) :
						$ot_id      = $ot_post->ID;
						$numero_ot  = (string) get_post_meta( $ot_id, 'calibratrack_numero_ot', true );
						$equipo_id  = (int) get_post_meta( $ot_id, 'calibratrack_equipo_id', true );
						$equipo     = $equipo_id ? get_the_title( $equipo_id ) : '—';
						$tipo       = (string) get_post_meta( $ot_id, 'calibratrack_tipo_evento', true );
						$tipo_label = isset( $tipos_ev_labels[ $tipo ] ) ? $tipos_ev_labels[ $tipo ] : $tipo;
						$estado     = (string) get_post_meta( $ot_id, 'calibratrack_estado_servicio', true );
						$est_info   = isset( $estado_labels[ $estado ] ) ? $estado_labels[ $estado ] : array( 'label' => $estado, 'color' => '#94a3b8' );
						$total_ot_v = (float) get_post_meta( $ot_id, 'calibratrack_total', true );
						$fecha_raw  = (string) get_post_meta( $ot_id, 'calibratrack_fecha_ejecucion', true );
						$fecha      = $fecha_raw ? gmdate( 'd/m/Y', strtotime( $fecha_raw ) ) : '—';
						$url_ot     = home_url( '/panel/ot/' . $ot_id . '/' );
						$row_bg     = ( 0 === $idx % 2 ) ? '#f8fafc' : '#fff';
					?>
					<tr style="border-bottom:1px solid #f1f5f9;background:<?php echo esc_attr( $row_bg ); ?>;">
						<td style="padding:8px 10px;font-weight:600;">
							<a href="<?php echo esc_url( $url_ot ); ?>" style="color:#0ea5e9;text-decoration:none;">
								<?php echo esc_html( $numero_ot ? $numero_ot : '#' . $ot_id ); ?>
							</a>
						</td>
						<td style="padding:8px 10px;color:#374151;"><?php echo esc_html( $equipo ); ?></td>
						<td style="padding:8px 10px;color:#374151;"><?php echo esc_html( $tipo_label ); ?></td>
						<td style="padding:8px 10px;">
							<span style="display:inline-block;padding:2px 8px;border-radius:12px;background:<?php echo esc_attr( $est_info['color'] ); ?>22;color:<?php echo esc_attr( $est_info['color'] ); ?>;font-size:11px;font-weight:700;border:1px solid <?php echo esc_attr( $est_info['color'] ); ?>55;">
								<?php echo esc_html( $est_info['label'] ); ?>
							</span>
						</td>
						<td style="padding:8px 10px;color:#64748b;"><?php echo esc_html( $fecha ); ?></td>
						<td style="padding:8px 10px;text-align:right;color:#10b981;font-weight:700;">
							<?php echo $total_ot_v > 0 ? esc_html( '$' . number_format( $total_ot_v, 0, ',', '.' ) ) : '<span style="color:#94a3b8;">—</span>'; ?>
						</td>
					</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
				<div style="margin-top:12px;text-align:right;">
					<a href="<?php echo esc_url( home_url( '/panel/' ) ); ?>" style="font-size:13px;color:#0ea5e9;">
						<?php esc_html_e( 'Ver todas las órdenes →', 'calibratrack' ); ?>
					</a>
				</div>
			</div>
			<?php endif; ?>

			<?php /* ── ACCESO RÁPIDO ── */ ?>
			<div style="display:flex;gap:12px;flex-wrap:wrap;">
				<a href="<?php echo esc_url( home_url( '/panel/nueva-oi/' ) ); ?>"
				   class="button button-primary" style="padding:10px 18px;">
					<?php esc_html_e( '+ Nueva OI', 'calibratrack' ); ?>
				</a>
				<a href="<?php echo esc_url( home_url( '/panel/nueva-ot/' ) ); ?>"
				   class="button button-primary" style="padding:10px 18px;background:#16a34a;border-color:#16a34a;">
					<?php esc_html_e( '+ Nueva OT', 'calibratrack' ); ?>
				</a>
				<a href="<?php echo esc_url( $url_config ); ?>"
				   class="button button-secondary" style="padding:10px 18px;">
					<?php esc_html_e( 'Configuración', 'calibratrack' ); ?>
				</a>
			</div>
		</div>

		<?php /* ── Chart.js data + init (inline, solo en esta página) ── */ ?>
		<script>
		(function () {
			'use strict';
			if (typeof Chart === 'undefined') return;

			var estados = {
				labels: [
					'<?php echo esc_js( __( 'Vigente', 'calibratrack' ) ); ?>',
					'<?php echo esc_js( __( 'Por vencer', 'calibratrack' ) ); ?>',
					'<?php echo esc_js( __( 'Vencido', 'calibratrack' ) ); ?>',
					'<?php echo esc_js( __( 'Sin evento', 'calibratrack' ) ); ?>'
				],
				data:   [<?php echo (int)$kpi['vigente'].','.(int)$kpi['por_vencer'].','.(int)$kpi['vencido'].','.(int)$kpi['sin_evento']; ?>],
				colors: ['#22c55e', '#f59e0b', '#ef4444', '#94a3b8']
			};

			var meses = {
				labels: <?php echo wp_json_encode( $meses_labels ); ?>,
				data:   <?php echo wp_json_encode( $meses_data ); ?>
			};

			var tipos = {
				labels: [
					'<?php echo esc_js( __( 'Reparación', 'calibratrack' ) ); ?>',
					'<?php echo esc_js( __( 'Mantención y/o calibración', 'calibratrack' ) ); ?>'
				],
				data:   [<?php echo (int)$tipo_count['reparacion'].','.(int)$tipo_count['mantencion_calibracion']; ?>],
				colors: ['#3b82f6', '#8b5cf6']
			};

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

			// Estado de equipos (donut)
			var c1 = document.getElementById('ct-adm-chart-estados');
			if (c1) {
				new Chart(c1, {
					type: 'doughnut',
					data: { labels: estados.labels, datasets: [{ data: estados.data, backgroundColor: estados.colors, borderWidth: 2, borderColor: '#fff', hoverOffset: 5 }] },
					options: donutOpts
				});
			}

			// Eventos por mes (barras)
			var c2 = document.getElementById('ct-adm-chart-meses');
			if (c2) {
				new Chart(c2, {
					type: 'bar',
					data: {
						labels: meses.labels,
						datasets: [{
							label: '<?php echo esc_js( __( 'Eventos', 'calibratrack' ) ); ?>',
							data:  meses.data,
							backgroundColor: 'rgba(0,174,239,0.75)',
							borderColor:     'rgba(0,174,239,1)',
							borderWidth: 1, borderRadius: 4, borderSkipped: false
						}]
					},
					options: {
						responsive: true,
						scales: {
							y: { beginAtZero: true, ticks: { stepSize: 1, precision: 0 }, grid: { color: 'rgba(0,0,0,0.05)' } },
							x: { grid: { display: false } }
						},
						plugins: {
							legend: { display: false },
							tooltip: { callbacks: { label: function(ctx){ return ' '+ctx.parsed.y+' evento'+(ctx.parsed.y!==1?'s':''); } } }
						}
					}
				});
			}

			// Tipo de servicio (donut)
			var c3 = document.getElementById('ct-adm-chart-tipos');
			if (c3) {
				new Chart(c3, {
					type: 'doughnut',
					data: { labels: tipos.labels, datasets: [{ data: tipos.data, backgroundColor: tipos.colors, borderWidth: 2, borderColor: '#fff', hoverOffset: 5 }] },
					options: donutOpts
				});
			}

			// Estado de OT (donut)
			var otEstados = {
				labels: [
					'<?php echo esc_js( __( 'En proceso',        'calibratrack' ) ); ?>',
					'<?php echo esc_js( __( 'En ejecución',      'calibratrack' ) ); ?>',
					'<?php echo esc_js( __( 'Lista p/ revisión', 'calibratrack' ) ); ?>',
					'<?php echo esc_js( __( 'Completada',        'calibratrack' ) ); ?>'
				],
				data:   [<?php echo (int) $ot_estados['en_proceso'] . ',' . (int) $ot_estados['en_ejecucion'] . ',' . (int) $ot_estados['listo_revision'] . ',' . (int) $ot_estados['completado']; ?>],
				colors: ['#94a3b8', '#f59e0b', '#8b5cf6', '#22c55e']
			};
			var c4 = document.getElementById('ct-adm-chart-ot-estados');
			if (c4) {
				new Chart(c4, {
					type: 'doughnut',
					data: { labels: otEstados.labels, datasets: [{ data: otEstados.data, backgroundColor: otEstados.colors, borderWidth: 2, borderColor: '#fff', hoverOffset: 5 }] },
					options: donutOpts
				});
			}

			// OI y OT por mes — stacked bar
			var oiOtMes = {
				labels: <?php echo wp_json_encode( $meses_labels ); ?>,
				oi:     <?php echo wp_json_encode( $oi_meses_data ); ?>,
				ot:     <?php echo wp_json_encode( $ot_meses_data ); ?>
			};
			var c5 = document.getElementById('ct-adm-chart-oi-ot-mes');
			if (c5) {
				new Chart(c5, {
					type: 'bar',
					data: {
						labels: oiOtMes.labels,
						datasets: [
							{
								label: '<?php echo esc_js( __( 'OI', 'calibratrack' ) ); ?>',
								data:  oiOtMes.oi,
								backgroundColor: 'rgba(99,102,241,0.75)',
								borderColor:     'rgba(99,102,241,1)',
								borderWidth: 1, borderRadius: 4, borderSkipped: false,
								stack: 'ordenes'
							},
							{
								label: '<?php echo esc_js( __( 'OT', 'calibratrack' ) ); ?>',
								data:  oiOtMes.ot,
								backgroundColor: 'rgba(14,165,233,0.75)',
								borderColor:     'rgba(14,165,233,1)',
								borderWidth: 1, borderRadius: 4, borderSkipped: false,
								stack: 'ordenes'
							}
						]
					},
					options: {
						responsive: true,
						scales: {
							y: { beginAtZero: true, stacked: true, ticks: { stepSize: 1, precision: 0 }, grid: { color: 'rgba(0,0,0,0.05)' } },
							x: { stacked: true, grid: { display: false } }
						},
						plugins: {
							legend: { position: 'bottom', labels: { padding: 12, font: { size: 12 } } },
							tooltip: { callbacks: { label: function(ctx){ return ' '+ctx.dataset.label+': '+ctx.parsed.y; } } }
						}
					}
				});
			}
		})();
		</script>
		<?php
	}

	/**
	 * Registra los metaboxes para los tres CPTs.
	 *
	 * @return void
	 */
	public static function register_metaboxes() {
		// Equipo.
		add_meta_box(
			'calibratrack_equipo_datos',
			__( 'Datos del equipo', 'calibratrack' ),
			array( __CLASS__, 'render_metabox_equipo' ),
			CalibraTrack_CPT_Equipo::SLUG,
			'normal',
			'high'
		);

		// Cliente.
		add_meta_box(
			'calibratrack_cliente_datos',
			__( 'Datos del cliente', 'calibratrack' ),
			array( __CLASS__, 'render_metabox_cliente' ),
			CalibraTrack_CPT_Cliente::SLUG,
			'normal',
			'high'
		);

		// Evento de servicio.
		add_meta_box(
			'calibratrack_evento_datos',
			__( 'Datos del evento de servicio', 'calibratrack' ),
			array( __CLASS__, 'render_metabox_evento' ),
			CalibraTrack_CPT_EventoServicio::SLUG,
			'normal',
			'high'
		);

		// Tipo de documento: OI o OT (sidebar, alta prioridad).
		add_meta_box(
			'calibratrack_tipo_documento',
			__( 'Tipo de documento', 'calibratrack' ),
			array( __CLASS__, 'render_metabox_tipo_documento' ),
			CalibraTrack_CPT_EventoServicio::SLUG,
			'side',
			'high'
		);

		// OI relacionada (sidebar).
		add_meta_box(
			'calibratrack_ingreso_relacionado',
			__( 'Orden de Ingreso relacionada', 'calibratrack' ),
			array( __CLASS__, 'render_metabox_ingreso_relacionado' ),
			CalibraTrack_CPT_EventoServicio::SLUG,
			'side',
			'default'
		);

		// OTs asociadas a esta OI (sidebar) — solo relevante en OIs.
		add_meta_box(
			'calibratrack_ots_asociadas',
			__( 'Órdenes de Trabajo asociadas', 'calibratrack' ),
			array( __CLASS__, 'render_metabox_ots_asociadas' ),
			CalibraTrack_CPT_EventoServicio::SLUG,
			'side',
			'default'
		);
	}

	/**
	 * Renderiza el metabox del equipo.
	 * El HTML completo lo implementará el agente admin-ui-agent.
	 * Este método incluye el nonce necesario para el guardado.
	 *
	 * @param WP_Post $post Post actual.
	 * @return void
	 */
	public static function render_metabox_equipo( $post ) {
		wp_nonce_field( self::NONCE_EQUIPO . '_' . $post->ID, self::NONCE_EQUIPO );

		$serie           = esc_attr( (string) get_post_meta( $post->ID, CalibraTrack_Meta_Keys::EQUIPO_SERIE, true ) );
		$marca           = esc_attr( (string) get_post_meta( $post->ID, CalibraTrack_Meta_Keys::EQUIPO_MARCA, true ) );
		$modelo          = esc_attr( (string) get_post_meta( $post->ID, CalibraTrack_Meta_Keys::EQUIPO_MODELO, true ) );
		$tipo_equipo     = (string) get_post_meta( $post->ID, CalibraTrack_Meta_Keys::EQUIPO_TIPO, true );
		$cliente_prop    = (int) get_post_meta( $post->ID, CalibraTrack_Meta_Keys::EQUIPO_CLIENTE_PROPIETARIO, true );
		$fecha_ingreso   = esc_attr( (string) get_post_meta( $post->ID, CalibraTrack_Meta_Keys::EQUIPO_FECHA_INGRESO, true ) );
		$tipos_equipo    = CalibraTrack_Helpers::get_tipos_equipo();

		// QR del equipo.
		$qr_att_id  = (int) get_post_meta( $post->ID, CalibraTrack_Meta_Keys::EQUIPO_CODIGO_QR, true );
		$qr_img_url = $qr_att_id ? wp_get_attachment_url( $qr_att_id ) : '';

		// Clientes disponibles para el select.
		$clientes = get_posts( array(
			'post_type'      => CalibraTrack_CPT_Cliente::SLUG,
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'orderby'        => 'title',
			'order'          => 'ASC',
			'fields'         => 'ids',
		) );

		?>

		<div id="ct-wc-equipo-buscador" style="background:#f0f6fc;border:1px solid #c3d4e3;border-radius:4px;padding:12px 14px;margin-bottom:16px;">
			<p style="margin:0 0 8px;font-weight:600;font-size:13px;">
				<?php esc_html_e( 'Importar datos desde WooCommerce (opcional)', 'calibratrack' ); ?>
			</p>
			<p style="margin:0 0 10px;font-size:12px;color:#555;">
				<?php esc_html_e( 'Busca un producto WooCommerce para pre-llenar marca, modelo y tipo. Puedes editar los valores luego.', 'calibratrack' ); ?>
			</p>
			<div style="display:flex;gap:8px;align-items:flex-start;flex-wrap:wrap;">
				<input type="text" id="ct-wc-eq-term" placeholder="<?php esc_attr_e( 'Nombre o SKU del producto…', 'calibratrack' ); ?>"
					style="flex:1;min-width:200px;padding:6px 8px;border:1px solid #8c9;border-radius:3px;" />
				<button type="button" id="ct-wc-eq-buscar" class="button">
					<?php esc_html_e( 'Buscar', 'calibratrack' ); ?>
				</button>
			</div>
			<div id="ct-wc-eq-resultados" style="display:none;margin-top:10px;">
				<select id="ct-wc-eq-select" size="5" style="width:100%;border:1px solid #ccd;border-radius:3px;padding:4px;">
				</select>
				<button type="button" id="ct-wc-eq-usar" class="button button-primary" style="margin-top:8px;" disabled>
					<?php esc_html_e( 'Usar este producto', 'calibratrack' ); ?>
				</button>
				<span id="ct-wc-eq-msg" style="display:none;margin-left:10px;font-size:12px;color:#176b00;font-weight:600;">
					<?php esc_html_e( '¡Datos importados!', 'calibratrack' ); ?>
				</span>
			</div>
			<div id="ct-wc-eq-spinner" style="display:none;margin-top:8px;font-size:12px;color:#555;">
				<?php esc_html_e( 'Buscando…', 'calibratrack' ); ?>
			</div>
		</div>
		<script>
		(function() {
			var nonce = '<?php echo esc_js( wp_create_nonce( 'calibratrack_buscar_equipo_wc' ) ); ?>';
			var ajaxUrl = '<?php echo esc_js( admin_url( 'admin-ajax.php' ) ); ?>';
			var productosData = {};

			document.getElementById('ct-wc-eq-buscar').addEventListener('click', function() {
				var term = document.getElementById('ct-wc-eq-term').value.trim();
				var spinner = document.getElementById('ct-wc-eq-spinner');
				var resultados = document.getElementById('ct-wc-eq-resultados');
				var select = document.getElementById('ct-wc-eq-select');
				var btnUsar = document.getElementById('ct-wc-eq-usar');
				var msg = document.getElementById('ct-wc-eq-msg');

				spinner.style.display = 'block';
				resultados.style.display = 'none';
				msg.style.display = 'none';
				btnUsar.disabled = true;
				productosData = {};

				var url = ajaxUrl + '?action=calibratrack_buscar_equipo_wc&nonce=' + encodeURIComponent(nonce) + '&term=' + encodeURIComponent(term);
				fetch(url)
					.then(function(r) { return r.json(); })
					.then(function(data) {
						spinner.style.display = 'none';
						select.innerHTML = '';
						if (!data.success || !data.data || data.data.length === 0) {
							var opt = document.createElement('option');
							opt.value = '';
							opt.textContent = '<?php echo esc_js( __( 'Sin resultados', 'calibratrack' ) ); ?>';
							select.appendChild(opt);
							resultados.style.display = 'block';
							return;
						}
						data.data.forEach(function(p) {
							productosData[p.id] = p;
							var opt = document.createElement('option');
							opt.value = p.id;
							var label = p.name;
							if (p.sku) { label += ' [SKU: ' + p.sku + ']'; }
							if (p.marca) { label += ' — ' + p.marca; }
							opt.textContent = label;
							select.appendChild(opt);
						});
						resultados.style.display = 'block';
					})
					.catch(function() {
						spinner.style.display = 'none';
					});
			});

			document.getElementById('ct-wc-eq-select').addEventListener('change', function() {
				document.getElementById('ct-wc-eq-usar').disabled = !this.value || !productosData[this.value];
				document.getElementById('ct-wc-eq-msg').style.display = 'none';
			});

			document.getElementById('ct-wc-eq-usar').addEventListener('click', function() {
				var select = document.getElementById('ct-wc-eq-select');
				var p = productosData[select.value];
				if (!p) { return; }

				if (p.marca) {
					document.getElementById('calibratrack_marca').value = p.marca;
				}
				if (p.modelo) {
					document.getElementById('calibratrack_modelo').value = p.modelo;
				}
				if (p.tipo_sugerido) {
					var tipoSelect = document.getElementById('calibratrack_tipo_equipo');
					if (tipoSelect) { tipoSelect.value = p.tipo_sugerido; }
				}
				// Título del post (nombre del producto como referencia)
				if (p.name) {
					var tituloInput = document.getElementById('title');
					if (tituloInput && !tituloInput.value) {
						tituloInput.value = p.name;
					}
				}

				document.getElementById('ct-wc-eq-msg').style.display = 'inline';
				this.disabled = true;
			});

			// Buscar al presionar Enter en el campo de texto.
			document.getElementById('ct-wc-eq-term').addEventListener('keydown', function(e) {
				if (e.key === 'Enter' || e.keyCode === 13) {
					e.preventDefault();
					document.getElementById('ct-wc-eq-buscar').click();
				}
			});
		}());
		</script>
		</div>

		<table class="form-table">
			<tr>
				<th><label for="calibratrack_serie"><?php esc_html_e( 'Número de serie *', 'calibratrack' ); ?></label></th>
				<td>
					<input type="text" id="calibratrack_serie" name="calibratrack_serie" value="<?php echo esc_attr( $serie ); ?>" class="regular-text" required />
					<p class="description"><?php esc_html_e( 'Identificador único del equipo. No puede repetirse.', 'calibratrack' ); ?></p>
				</td>
			</tr>
			<tr>
				<th><label for="calibratrack_marca"><?php esc_html_e( 'Marca *', 'calibratrack' ); ?></label></th>
				<td><input type="text" id="calibratrack_marca" name="calibratrack_marca" value="<?php echo esc_attr( $marca ); ?>" class="regular-text" required placeholder="<?php esc_attr_e( 'Ej: Grandway, EXFO, Fluke Networks', 'calibratrack' ); ?>" /></td>
			</tr>
			<tr>
				<th><label for="calibratrack_modelo"><?php esc_html_e( 'Modelo *', 'calibratrack' ); ?></label></th>
				<td><input type="text" id="calibratrack_modelo" name="calibratrack_modelo" value="<?php echo esc_attr( $modelo ); ?>" class="regular-text" required placeholder="<?php esc_attr_e( 'Ej: GS-401', 'calibratrack' ); ?>" /></td>
			</tr>
			<tr>
				<th><label for="calibratrack_tipo_equipo"><?php esc_html_e( 'Tipo de equipo', 'calibratrack' ); ?></label></th>
				<td>
					<select id="calibratrack_tipo_equipo" name="calibratrack_tipo_equipo" class="ct-select-with-search">
						<option value=""><?php esc_html_e( '-- Seleccionar --', 'calibratrack' ); ?></option>
						<?php foreach ( $tipos_equipo as $slug => $label ) : ?>
							<option value="<?php echo esc_attr( $slug ); ?>" <?php selected( $tipo_equipo, $slug ); ?>><?php echo esc_html( $label ); ?></option>
						<?php endforeach; ?>
					</select>
				</td>
			</tr>
			<tr>
				<th><label for="calibratrack_cliente_propietario"><?php esc_html_e( 'Cliente propietario', 'calibratrack' ); ?></label></th>
				<td>
					<select id="calibratrack_cliente_propietario" name="calibratrack_cliente_propietario" class="ct-select-with-search">
						<option value="0"><?php esc_html_e( '-- Sin cliente asignado --', 'calibratrack' ); ?></option>
						<?php foreach ( $clientes as $cliente_id ) : ?>
							<?php
							$nombre_cliente = (string) get_post_meta( $cliente_id, CalibraTrack_Meta_Keys::CLIENTE_NOMBRE_EMPRESA, true );
							if ( empty( $nombre_cliente ) ) {
								$nombre_cliente = get_the_title( $cliente_id );
							}
							?>
							<option value="<?php echo (int) $cliente_id; ?>" <?php selected( $cliente_prop, $cliente_id ); ?>>
								<?php echo esc_html( $nombre_cliente ); ?>
							</option>
						<?php endforeach; ?>
					</select>
				</td>
			</tr>
			<tr>
				<th><label for="calibratrack_fecha_ingreso_sistema"><?php esc_html_e( 'Fecha de ingreso al sistema', 'calibratrack' ); ?></label></th>
				<td><input type="date" id="calibratrack_fecha_ingreso_sistema" name="calibratrack_fecha_ingreso_sistema" value="<?php echo esc_attr( $fecha_ingreso ); ?>" /></td>
			</tr>
		</table>

		<?php if ( $qr_img_url ) : ?>
			<p class="ct-admin-section-title"><?php esc_html_e( 'Código QR', 'calibratrack' ); ?></p>
			<div class="ct-qr-preview">
				<img src="<?php echo esc_url( $qr_img_url ); ?>" alt="<?php esc_attr_e( 'Código QR del equipo', 'calibratrack' ); ?>" />
			</div>
			<button type="button" id="ct-btn-imprimir-qr" class="ct-qr-print-btn">
				<span aria-hidden="true">&#128438;</span>
				<?php esc_html_e( 'Imprimir etiqueta QR', 'calibratrack' ); ?>
			</button>
		<?php elseif ( $post->ID ) : ?>
			<p class="description"><?php esc_html_e( 'El código QR se generará automáticamente al guardar el equipo.', 'calibratrack' ); ?></p>
		<?php endif; ?>
		<?php
	}

	/**
	 * Renderiza el metabox del cliente.
	 *
	 * @param WP_Post $post Post actual.
	 * @return void
	 */
	public static function render_metabox_cliente( $post ) {
		wp_nonce_field( self::NONCE_CLIENTE . '_' . $post->ID, self::NONCE_CLIENTE );

		$nombre_empresa  = esc_attr( (string) get_post_meta( $post->ID, CalibraTrack_Meta_Keys::CLIENTE_NOMBRE_EMPRESA, true ) );
		$rut             = esc_attr( (string) get_post_meta( $post->ID, CalibraTrack_Meta_Keys::CLIENTE_RUT, true ) );
		$contacto_nombre = esc_attr( (string) get_post_meta( $post->ID, CalibraTrack_Meta_Keys::CLIENTE_CONTACTO_NOMBRE, true ) );
		$telefono        = esc_attr( (string) get_post_meta( $post->ID, CalibraTrack_Meta_Keys::CLIENTE_TELEFONO, true ) );
		$correo          = esc_attr( (string) get_post_meta( $post->ID, CalibraTrack_Meta_Keys::CLIENTE_CORREO, true ) );
		$direccion       = esc_attr( (string) get_post_meta( $post->ID, CalibraTrack_Meta_Keys::CLIENTE_DIRECCION, true ) );

		?>
		<table class="form-table">
			<tr>
				<th><label for="calibratrack_nombre_empresa"><?php esc_html_e( 'Nombre de empresa *', 'calibratrack' ); ?></label></th>
				<td><input type="text" id="calibratrack_nombre_empresa" name="calibratrack_nombre_empresa" value="<?php echo esc_attr( $nombre_empresa ); ?>" class="regular-text" required /></td>
			</tr>
			<tr>
				<th><label for="calibratrack_rut"><?php esc_html_e( 'RUT *', 'calibratrack' ); ?></label></th>
				<td>
					<input type="text" id="calibratrack_rut" name="calibratrack_rut" value="<?php echo esc_attr( $rut ); ?>" class="regular-text" placeholder="12.345.678-9" required />
					<p class="description"><?php esc_html_e( 'Formato: 12.345.678-9 o 12345678-9', 'calibratrack' ); ?></p>
				</td>
			</tr>
			<tr>
				<th><label for="calibratrack_contacto_nombre"><?php esc_html_e( 'Contacto', 'calibratrack' ); ?></label></th>
				<td><input type="text" id="calibratrack_contacto_nombre" name="calibratrack_contacto_nombre" value="<?php echo esc_attr( $contacto_nombre ); ?>" class="regular-text" /></td>
			</tr>
			<tr>
				<th><label for="calibratrack_telefono"><?php esc_html_e( 'Teléfono', 'calibratrack' ); ?></label></th>
				<td><input type="text" id="calibratrack_telefono" name="calibratrack_telefono" value="<?php echo esc_attr( $telefono ); ?>" class="regular-text" /></td>
			</tr>
			<tr>
				<th><label for="calibratrack_correo"><?php esc_html_e( 'Correo electrónico', 'calibratrack' ); ?></label></th>
				<td><input type="email" id="calibratrack_correo" name="calibratrack_correo" value="<?php echo esc_attr( $correo ); ?>" class="regular-text" /></td>
			</tr>
			<tr>
				<th><label for="calibratrack_direccion"><?php esc_html_e( 'Dirección', 'calibratrack' ); ?></label></th>
				<td><input type="text" id="calibratrack_direccion" name="calibratrack_direccion" value="<?php echo esc_attr( $direccion ); ?>" class="regular-text" /></td>
			</tr>
		</table>
		<?php
	}

	/**
	 * Renderiza el metabox del evento de servicio.
	 *
	 * @param WP_Post $post Post actual.
	 * @return void
	 */
	public static function render_metabox_evento( $post ) {
		wp_nonce_field( self::NONCE_EVENTO . '_' . $post->ID, self::NONCE_EVENTO );

		$equipo_id        = (int) get_post_meta( $post->ID, CalibraTrack_Meta_Keys::EVENTO_EQUIPO_ID, true );
		$numero_ot        = esc_attr( (string) get_post_meta( $post->ID, CalibraTrack_Meta_Keys::EVENTO_NUMERO_OT, true ) );
		$tipo_evento      = (string) get_post_meta( $post->ID, CalibraTrack_Meta_Keys::EVENTO_TIPO, true );
		$fecha_ejecucion  = esc_attr( (string) get_post_meta( $post->ID, CalibraTrack_Meta_Keys::EVENTO_FECHA_EJECUCION, true ) );
		$proxima_fecha    = esc_attr( (string) get_post_meta( $post->ID, CalibraTrack_Meta_Keys::EVENTO_PROXIMA_FECHA_CONTROL, true ) );
		$tecnico_id       = (int) get_post_meta( $post->ID, CalibraTrack_Meta_Keys::EVENTO_TECNICO_RESPONSABLE, true );
		$falla            = esc_textarea( (string) get_post_meta( $post->ID, CalibraTrack_Meta_Keys::EVENTO_FALLA_REPORTADA, true ) );
		$descripcion      = esc_textarea( (string) get_post_meta( $post->ID, CalibraTrack_Meta_Keys::EVENTO_DESCRIPCION_TRABAJO, true ) );
		$observaciones    = esc_textarea( (string) get_post_meta( $post->ID, CalibraTrack_Meta_Keys::EVENTO_OBSERVACIONES, true ) );
		$garantia         = (int) get_post_meta( $post->ID, CalibraTrack_Meta_Keys::EVENTO_GARANTIA, true );
		$dias_garantia    = (int) get_post_meta( $post->ID, CalibraTrack_Meta_Keys::EVENTO_DIAS_GARANTIA, true );
		$cert_pdf_id      = (int) get_post_meta( $post->ID, CalibraTrack_Meta_Keys::EVENTO_CERTIFICADO_PDF, true );
		$estado_servicio  = (string) get_post_meta( $post->ID, CalibraTrack_Meta_Keys::EVENTO_ESTADO_SERVICIO, true );
		if ( empty( $estado_servicio ) ) {
			$estado_servicio = 'en_proceso';
		}

		// Pre-llenar desde la OI cuando se crea una OT nueva vía "Crear OT desde OI".
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$ct_oi_id = isset( $_GET['ct_oi_id'] ) ? (int) $_GET['ct_oi_id'] : 0;
		if ( 'auto-draft' === $post->post_status && $ct_oi_id > 0 ) {
			$oi_post = get_post( $ct_oi_id );
			if ( $oi_post && 'evento_servicio' === $oi_post->post_type ) {
				if ( ! $equipo_id ) {
					$equipo_id = (int) get_post_meta( $ct_oi_id, CalibraTrack_Meta_Keys::EVENTO_EQUIPO_ID, true );
				}
				if ( ! $tecnico_id ) {
					$tecnico_id = (int) get_post_meta( $ct_oi_id, CalibraTrack_Meta_Keys::EVENTO_TECNICO_RESPONSABLE, true );
				}
				if ( empty( $tipo_evento ) ) {
					$tipo_evento = (string) get_post_meta( $ct_oi_id, CalibraTrack_Meta_Keys::EVENTO_TIPO, true );
				}
				if ( empty( $falla ) ) {
					$falla = esc_textarea( (string) get_post_meta( $ct_oi_id, CalibraTrack_Meta_Keys::EVENTO_FALLA_REPORTADA, true ) );
				}
			}
		}
		$tipos_evento     = CalibraTrack_Helpers::get_tipos_evento();

		// Evidencia fotográfica (JSON array de attachment IDs).
		$evidencia_raw   = (string) get_post_meta( $post->ID, CalibraTrack_Meta_Keys::EVENTO_EVIDENCIA_FOTOGRAFICA, true );
		$evidencia_ids   = array();
		if ( ! empty( $evidencia_raw ) ) {
			$decoded = json_decode( $evidencia_raw, true );
			$evidencia_ids = is_array( $decoded ) ? array_filter( array_map( 'absint', $decoded ) ) : array();
		}

		// Ítems de costo desde la tabla custom.
		$items_costo      = CalibraTrack_DB::get_items_costo( $post->ID );

		// Totales guardados (solo lectura — calculados por servidor al último guardado).
		$subtotal_guardado = (float) get_post_meta( $post->ID, 'calibratrack_subtotal', true );
		$iva_guardado      = (float) get_post_meta( $post->ID, 'calibratrack_iva', true );
		$total_guardado    = (float) get_post_meta( $post->ID, 'calibratrack_total', true );

		// Lista de equipos para select.
		$equipos = get_posts( array(
			'post_type'      => CalibraTrack_CPT_Equipo::SLUG,
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'orderby'        => 'title',
			'order'          => 'ASC',
			'fields'         => 'ids',
		) );

		// Lista de técnicos (usuarios con rol tecnico_calibracion o administrador).
		$tecnicos = get_users( array(
			'role__in' => array( 'administrator', 'tecnico_calibracion' ),
			'orderby'  => 'display_name',
			'order'    => 'ASC',
			'fields'   => array( 'ID', 'display_name' ),
		) );

		// cert_nombre: para mostrar el nombre del certificado generado en el panel.
		$cert_nombre = $cert_pdf_id ? get_the_title( $cert_pdf_id ) : '';

		?>
		<p class="ct-admin-section-title"><?php esc_html_e( 'Datos principales', 'calibratrack' ); ?></p>
		<table class="form-table">
			<tr>
				<th><label for="calibratrack_equipo_id"><?php esc_html_e( 'Equipo *', 'calibratrack' ); ?></label></th>
				<td>
					<select id="calibratrack_equipo_id" name="calibratrack_equipo_id" class="ct-select-with-search" required>
						<option value="0"><?php esc_html_e( '-- Seleccionar equipo --', 'calibratrack' ); ?></option>
						<?php foreach ( $equipos as $eq_id ) : ?>
							<?php
							$eq_serie = (string) get_post_meta( $eq_id, CalibraTrack_Meta_Keys::EQUIPO_SERIE, true );
							$eq_marca = (string) get_post_meta( $eq_id, CalibraTrack_Meta_Keys::EQUIPO_MARCA, true );
							$eq_mod   = (string) get_post_meta( $eq_id, CalibraTrack_Meta_Keys::EQUIPO_MODELO, true );
							$eq_label = trim( $eq_serie . ' — ' . $eq_marca . ' ' . $eq_mod );
							?>
							<option value="<?php echo (int) $eq_id; ?>" <?php selected( $equipo_id, $eq_id ); ?>>
								<?php echo esc_html( $eq_label ); ?>
							</option>
						<?php endforeach; ?>
					</select>
				</td>
			</tr>
			<tr>
				<th><label for="calibratrack_numero_ot"><?php esc_html_e( 'N° Orden de trabajo *', 'calibratrack' ); ?></label></th>
				<td><input type="text" id="calibratrack_numero_ot" name="calibratrack_numero_ot" value="<?php echo esc_attr( $numero_ot ); ?>" class="regular-text" required placeholder="<?php esc_attr_e( 'Ej: OT96', 'calibratrack' ); ?>" /></td>
			</tr>
			<tr>
				<th><label for="calibratrack_tipo_evento"><?php esc_html_e( 'Tipo de evento *', 'calibratrack' ); ?></label></th>
				<td>
					<select id="calibratrack_tipo_evento" name="calibratrack_tipo_evento" required>
						<option value=""><?php esc_html_e( '-- Seleccionar --', 'calibratrack' ); ?></option>
						<?php foreach ( $tipos_evento as $slug => $label ) : ?>
							<option value="<?php echo esc_attr( $slug ); ?>" <?php selected( $tipo_evento, $slug ); ?>><?php echo esc_html( $label ); ?></option>
						<?php endforeach; ?>
					</select>
				</td>
			</tr>
			<tr>
				<th><label for="calibratrack_fecha_ejecucion"><?php esc_html_e( 'Fecha de ejecución *', 'calibratrack' ); ?></label></th>
				<td><input type="date" id="calibratrack_fecha_ejecucion" name="calibratrack_fecha_ejecucion" value="<?php echo esc_attr( $fecha_ejecucion ); ?>" required /></td>
			</tr>
			<tr>
				<th><label for="calibratrack_proxima_fecha_control"><?php esc_html_e( 'Próxima fecha de control *', 'calibratrack' ); ?></label></th>
				<td><input type="date" id="calibratrack_proxima_fecha_control" name="calibratrack_proxima_fecha_control" value="<?php echo esc_attr( $proxima_fecha ); ?>" required /></td>
			</tr>
			<tr>
				<th><label for="calibratrack_tecnico_responsable"><?php esc_html_e( 'Técnico responsable', 'calibratrack' ); ?></label></th>
				<td>
					<select id="calibratrack_tecnico_responsable" name="calibratrack_tecnico_responsable" class="ct-select-with-search">
						<option value="0"><?php esc_html_e( '-- Seleccionar técnico --', 'calibratrack' ); ?></option>
						<?php foreach ( $tecnicos as $tecnico ) : ?>
							<option value="<?php echo (int) $tecnico->ID; ?>" <?php selected( $tecnico_id, $tecnico->ID ); ?>>
								<?php echo esc_html( $tecnico->display_name ); ?>
							</option>
						<?php endforeach; ?>
					</select>
				</td>
			</tr>
		</table>

		<p class="ct-admin-section-title"><?php esc_html_e( 'Descripción del servicio', 'calibratrack' ); ?></p>
		<table class="form-table">
			<tr>
				<th><label for="calibratrack_falla_reportada"><?php esc_html_e( 'Falla reportada por el cliente', 'calibratrack' ); ?></label></th>
				<td><textarea id="calibratrack_falla_reportada" name="calibratrack_falla_reportada" rows="3" class="large-text"><?php echo esc_textarea( $falla ); ?></textarea></td>
			</tr>
			<tr>
				<th><label for="calibratrack_descripcion_trabajo"><?php esc_html_e( 'Descripción del trabajo realizado', 'calibratrack' ); ?></label></th>
				<td><textarea id="calibratrack_descripcion_trabajo" name="calibratrack_descripcion_trabajo" rows="4" class="large-text"><?php echo esc_textarea( $descripcion ); ?></textarea></td>
			</tr>
			<tr>
				<th><label for="calibratrack_observaciones"><?php esc_html_e( 'Observaciones adicionales', 'calibratrack' ); ?></label></th>
				<td><textarea id="calibratrack_observaciones" name="calibratrack_observaciones" rows="3" class="large-text"><?php echo esc_textarea( $observaciones ); ?></textarea></td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'Garantía', 'calibratrack' ); ?></th>
				<td>
					<label>
						<input type="checkbox" name="calibratrack_garantia" value="1" <?php checked( $garantia, 1 ); ?> />
						<?php esc_html_e( 'El servicio tiene garantía', 'calibratrack' ); ?>
					</label>
					<div class="ct-garantia-dias-row<?php echo $garantia ? ' visible' : ''; ?>">
						<label for="calibratrack_dias_garantia">
							<?php esc_html_e( 'Días de garantía:', 'calibratrack' ); ?>
						</label>
						<input
							type="number"
							id="calibratrack_dias_garantia"
							name="calibratrack_dias_garantia"
							value="<?php echo absint( $dias_garantia ); ?>"
							class="small-text"
							min="1"
						/>
					</div>
				</td>
			</tr>
			<tr>
				<th><label for="calibratrack_estado_servicio"><?php esc_html_e( 'Estado del servicio', 'calibratrack' ); ?></label></th>
				<td>
					<select id="calibratrack_estado_servicio" name="calibratrack_estado_servicio">
						<option value="en_proceso" <?php selected( $estado_servicio, 'en_proceso' ); ?>><?php esc_html_e( 'En proceso', 'calibratrack' ); ?></option>
						<option value="completado" <?php selected( $estado_servicio, 'completado' ); ?>><?php esc_html_e( 'Completado', 'calibratrack' ); ?></option>
					</select>
					<p class="description">
						<?php esc_html_e( 'Al marcar como "Completado" se generará el certificado PDF y se enviará al cliente por correo electrónico.', 'calibratrack' ); ?>
					</p>
				</td>
			</tr>
		</table>

		<?php /* ── Ítems de costo ── */ ?>
		<p class="ct-admin-section-title"><?php esc_html_e( 'Ítems de costo', 'calibratrack' ); ?></p>
		<p class="description"><?php esc_html_e( 'Los totales mostrados son una vista previa. El servidor los recalcula al guardar.', 'calibratrack' ); ?></p>

		<?php if ( function_exists( 'wc_get_products' ) ) : ?>
		<div id="ct-wc-buscador" style="margin-bottom:10px;display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
			<input
				type="text"
				id="ct-wc-term"
				placeholder="<?php esc_attr_e( 'Buscar producto WooCommerce…', 'calibratrack' ); ?>"
				class="regular-text"
				style="min-width:260px;"
			/>
			<select id="ct-wc-select" style="min-width:260px;display:none;">
				<option value=""><?php esc_html_e( '— Seleccionar producto —', 'calibratrack' ); ?></option>
			</select>
			<button type="button" id="ct-wc-btn-agregar" class="button button-secondary" disabled>
				<?php esc_html_e( 'Agregar producto', 'calibratrack' ); ?>
			</button>
			<span id="ct-wc-status" style="color:#666;font-size:12px;"></span>
		</div>
		<script>
		(function(){
			var nonce   = '<?php echo esc_js( wp_create_nonce( 'calibratrack_buscar_wc' ) ); ?>';
			var ajaxUrl = '<?php echo esc_js( admin_url( 'admin-ajax.php' ) ); ?>';
			var term    = document.getElementById('ct-wc-term');
			var select  = document.getElementById('ct-wc-select');
			var btnAdd  = document.getElementById('ct-wc-btn-agregar');
			var status  = document.getElementById('ct-wc-status');
			var timer   = null;

			term.addEventListener('input', function(){
				clearTimeout(timer);
				var val = term.value.trim();
				if ( val.length < 2 ) {
					select.style.display = 'none';
					btnAdd.disabled = true;
					return;
				}
				status.textContent = '<?php esc_html_e( 'Buscando…', 'calibratrack' ); ?>';
				timer = setTimeout(function(){
					fetch(ajaxUrl + '?action=calibratrack_buscar_productos_wc&nonce=' + encodeURIComponent(nonce) + '&term=' + encodeURIComponent(val))
						.then(function(r){ return r.json(); })
						.then(function(res){
							status.textContent = '';
							select.innerHTML = '<option value=""><?php echo esc_js( __( '— Seleccionar —', 'calibratrack' ) ); ?></option>';
							if ( res.success && res.data.length ) {
								res.data.forEach(function(p){
									var opt = document.createElement('option');
									opt.value = JSON.stringify({name: p.name, price: p.price});
									opt.textContent = p.name + ' ($' + p.price.toLocaleString('es-CL') + ')';
									select.appendChild(opt);
								});
								select.style.display = '';
							} else {
								status.textContent = '<?php echo esc_js( __( 'Sin resultados.', 'calibratrack' ) ); ?>';
								select.style.display = 'none';
							}
							btnAdd.disabled = true;
						});
				}, 350);
			});

			select.addEventListener('change', function(){
				btnAdd.disabled = ! select.value;
			});

			btnAdd.addEventListener('click', function(){
				if ( ! select.value ) { return; }
				var data   = JSON.parse(select.value);
				var tbody  = document.querySelector('#calibratrack-items-costo tbody');
				var filas  = tbody.querySelectorAll('tr');
				var idx    = filas.length;
				var tr     = document.createElement('tr');
				tr.innerHTML = '<td class="ct-item-detalle"><input type="text" name="calibratrack_items[' + idx + '][detalle]" value="' + data.name.replace(/"/g,'&quot;') + '" class="large-text ct-item-detalle-input"></td>'
					+ '<td class="ct-item-cantidad"><input type="number" name="calibratrack_items[' + idx + '][cantidad]" value="1" step="0.01" min="0" class="small-text ct-item-cantidad-input"></td>'
					+ '<td class="ct-item-precio"><input type="number" name="calibratrack_items[' + idx + '][precio_unitario]" value="' + data.price + '" step="0.01" min="0" class="small-text ct-item-precio-input"></td>'
					+ '<td class="ct-item-subtotal"><span class="ct-item-subtotal-preview">$0</span></td>'
					+ '<td class="ct-item-acciones"><button type="button" class="ct-btn-quitar-item" aria-label="<?php echo esc_js( __( 'Quitar ítem', 'calibratrack' ) ); ?>">&times;</button></td>';
				tbody.appendChild(tr);
				// Disparar recálculo de totales si existe la función.
				if ( typeof calibratrackRecalcularTotales === 'function' ) {
					calibratrackRecalcularTotales();
				}
				// Limpiar selector.
				select.value = '';
				select.style.display = 'none';
				btnAdd.disabled = true;
				term.value = '';
			});
		})();
		</script>
		<?php endif; ?>

		<table class="widefat" id="calibratrack-items-costo">
			<thead>
				<tr>
					<th class="ct-item-detalle"><?php esc_html_e( 'Detalle', 'calibratrack' ); ?></th>
					<th class="ct-item-cantidad"><?php esc_html_e( 'Cantidad', 'calibratrack' ); ?></th>
					<th class="ct-item-precio"><?php esc_html_e( 'Precio unitario ($)', 'calibratrack' ); ?></th>
					<th class="ct-item-subtotal"><?php esc_html_e( 'Subtotal', 'calibratrack' ); ?></th>
					<th class="ct-item-acciones"></th>
				</tr>
			</thead>
			<tbody>
				<?php $items_render = ! empty( $items_costo ) ? $items_costo : array( (object) array( 'detalle' => '', 'cantidad' => '1', 'precio_unitario' => '0' ) ); ?>
				<?php foreach ( $items_render as $idx => $item ) : ?>
				<tr>
					<td class="ct-item-detalle">
						<input
							type="text"
							name="calibratrack_items[<?php echo (int) $idx; ?>][detalle]"
							value="<?php echo esc_attr( $item->detalle ); ?>"
							class="large-text ct-item-detalle-input"
						/>
					</td>
					<td class="ct-item-cantidad">
						<input
							type="number"
							name="calibratrack_items[<?php echo (int) $idx; ?>][cantidad]"
							value="<?php echo esc_attr( $item->cantidad ); ?>"
							step="0.01"
							min="0"
							class="small-text ct-item-cantidad-input"
						/>
					</td>
					<td class="ct-item-precio">
						<input
							type="number"
							name="calibratrack_items[<?php echo (int) $idx; ?>][precio_unitario]"
							value="<?php echo esc_attr( $item->precio_unitario ); ?>"
							step="0.01"
							min="0"
							class="small-text ct-item-precio-input"
						/>
					</td>
					<td class="ct-item-subtotal">
						<span class="ct-item-subtotal-preview">$0</span>
					</td>
					<td class="ct-item-acciones">
						<button type="button" class="ct-btn-quitar-item" aria-label="<?php esc_attr_e( 'Quitar ítem', 'calibratrack' ); ?>">&times;</button>
					</td>
				</tr>
				<?php endforeach; ?>
			</tbody>
			<tfoot>
				<tr>
					<td colspan="3" class="ct-totales-label"><?php esc_html_e( 'Subtotal (preview)', 'calibratrack' ); ?></td>
					<td id="ct-preview-subtotal">$0</td>
					<td></td>
				</tr>
				<tr>
					<td colspan="3" class="ct-totales-label"><?php esc_html_e( 'IVA 19% (preview)', 'calibratrack' ); ?></td>
					<td id="ct-preview-iva">$0</td>
					<td></td>
				</tr>
				<tr>
					<td colspan="3" class="ct-totales-label ct-total-final"><?php esc_html_e( 'TOTAL (preview)', 'calibratrack' ); ?></td>
					<td id="ct-preview-total" class="ct-total-final">$0</td>
					<td></td>
				</tr>
			</tfoot>
		</table>

		<button type="button" id="ct-btn-agregar-item" class="button button-secondary">
			+ <?php esc_html_e( 'Agregar ítem', 'calibratrack' ); ?>
		</button>

		<?php if ( $post->ID ) : ?>
		<div class="ct-totales-row">
			<div class="ct-totales-item">
				<span class="ct-totales-item-label"><?php esc_html_e( 'Subtotal guardado', 'calibratrack' ); ?></span>
				<span class="ct-totales-item-valor">$<?php echo number_format( $subtotal_guardado, 0, ',', '.' ); ?></span>
			</div>
			<div class="ct-totales-item">
				<span class="ct-totales-item-label"><?php esc_html_e( 'IVA 19%', 'calibratrack' ); ?></span>
				<span class="ct-totales-item-valor">$<?php echo number_format( $iva_guardado, 0, ',', '.' ); ?></span>
			</div>
			<div class="ct-totales-item">
				<span class="ct-totales-item-label"><?php esc_html_e( 'Total guardado', 'calibratrack' ); ?></span>
				<span class="ct-totales-item-valor">$<?php echo number_format( $total_guardado, 0, ',', '.' ); ?></span>
			</div>
			<p class="description" style="width:100%;margin:8px 0 0;"><?php esc_html_e( 'Valores calculados por el servidor en el último guardado. Solo lectura.', 'calibratrack' ); ?></p>
		</div>
		<?php endif; ?>

		<?php /* ── Evidencia fotográfica ── */ ?>
		<p class="ct-admin-section-title"><?php esc_html_e( 'Evidencia fotográfica', 'calibratrack' ); ?></p>
		<div class="ct-galeria-wrapper">
			<div id="ct-galeria-preview" class="ct-galeria-preview">
				<?php foreach ( $evidencia_ids as $att_id ) : ?>
					<?php
					$thumb = wp_get_attachment_image_src( $att_id, 'thumbnail' );
					if ( $thumb ) :
					?>
						<div class="ct-galeria-thumb">
							<img src="<?php echo esc_url( $thumb[0] ); ?>" alt="" />
							<button type="button" class="ct-thumb-remove" data-id="<?php echo (int) $att_id; ?>" aria-label="<?php esc_attr_e( 'Quitar foto', 'calibratrack' ); ?>">&times;</button>
						</div>
					<?php endif; ?>
				<?php endforeach; ?>
			</div>
			<input
				type="hidden"
				id="calibratrack_evidencia_fotografica"
				name="calibratrack_evidencia_fotografica"
				value="<?php echo esc_attr( $evidencia_raw ); ?>"
			/>
			<button type="button" id="ct-btn-galeria" class="button button-secondary">
				<?php esc_html_e( 'Seleccionar fotografías', 'calibratrack' ); ?>
			</button>
			<p class="description"><?php esc_html_e( 'Seleccione una o más imágenes desde la Biblioteca de medios.', 'calibratrack' ); ?></p>
		</div>

		<?php /* ── Documentos adjuntos por el técnico ── */ ?>
		<p class="ct-admin-section-title"><?php esc_html_e( 'Documentos adjuntos por el técnico', 'calibratrack' ); ?></p>
		<?php
		$docs_adj_raw = get_post_meta( $post->ID, CalibraTrack_Meta_Keys::EVENTO_DOCUMENTOS_ADJUNTOS, true );
		$docs_adj_ids = json_decode( (string) $docs_adj_raw, true );
		if ( ! is_array( $docs_adj_ids ) ) { $docs_adj_ids = array(); }
		$docs_adj_ids = array_filter( array_map( 'intval', $docs_adj_ids ) );
		?>
		<?php if ( ! empty( $docs_adj_ids ) ) : ?>
		<table class="form-table">
			<?php foreach ( $docs_adj_ids as $doc_id ) : ?>
			<tr>
				<th><?php echo esc_html( get_the_title( $doc_id ) ); ?></th>
				<td>
					<a href="<?php echo esc_url( wp_get_attachment_url( $doc_id ) ); ?>" target="_blank" rel="noopener">
						<?php esc_html_e( 'Descargar', 'calibratrack' ); ?>
					</a>
				</td>
			</tr>
			<?php endforeach; ?>
		</table>
		<?php else : ?>
		<p class="description"><?php esc_html_e( 'El técnico no ha adjuntado documentos en este evento.', 'calibratrack' ); ?></p>
		<?php endif; ?>

		<?php /* ── Documentos generados automáticamente (solo lectura) ── */ ?>
		<p class="ct-admin-section-title"><?php esc_html_e( 'Documentos', 'calibratrack' ); ?></p>
		<?php
		$ot_pdf_id = (int) get_post_meta( $post->ID, CalibraTrack_Meta_Keys::EVENTO_ORDEN_TRABAJO_PDF, true );
		?>
		<table class="form-table">
			<tr>
				<th><?php esc_html_e( 'Orden de Trabajo PDF', 'calibratrack' ); ?></th>
				<td>
					<?php if ( $ot_pdf_id ) : ?>
						<span class="dashicons dashicons-yes-alt" style="color:#46b450;vertical-align:middle;"></span>
						<strong><?php esc_html_e( 'Generado automáticamente', 'calibratrack' ); ?></strong>
						&nbsp;—&nbsp;
						<a href="<?php echo esc_url( wp_get_attachment_url( $ot_pdf_id ) ); ?>" target="_blank" rel="noopener">
							<?php echo esc_html( get_the_title( $ot_pdf_id ) ); ?>
						</a>
					<?php else : ?>
						<span class="dashicons dashicons-clock" style="color:#999;vertical-align:middle;"></span>
						<em style="color:#666;"><?php esc_html_e( 'Se generará automáticamente al guardar el evento por primera vez.', 'calibratrack' ); ?></em>
					<?php endif; ?>
					<p class="description"><?php esc_html_e( 'La OT se genera al guardar y se envía al cliente por correo electrónico.', 'calibratrack' ); ?></p>
				</td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'Certificado PDF', 'calibratrack' ); ?></th>
				<td>
					<?php if ( $cert_pdf_id ) : ?>
						<span class="dashicons dashicons-yes-alt" style="color:#46b450;vertical-align:middle;"></span>
						<strong><?php esc_html_e( 'Generado automáticamente', 'calibratrack' ); ?></strong>
						&nbsp;—&nbsp;
						<a href="<?php echo esc_url( wp_get_attachment_url( $cert_pdf_id ) ); ?>" target="_blank" rel="noopener">
							<?php echo esc_html( get_the_title( $cert_pdf_id ) ); ?>
						</a>
					<?php else : ?>
						<span class="dashicons dashicons-clock" style="color:#999;vertical-align:middle;"></span>
						<em style="color:#666;"><?php esc_html_e( 'Se generará al marcar el estado como "Completado".', 'calibratrack' ); ?></em>
					<?php endif; ?>
					<p class="description"><?php esc_html_e( 'El certificado se genera cuando el servicio es completado y se envía al cliente con el QR de verificación.', 'calibratrack' ); ?></p>
				</td>
			</tr>
		</table>
		<?php
	}

	// ─── RENOMBRAMIENTO DE ARCHIVOS (§9) ────────────────────────────────────────

	/**
	 * Renombra el archivo a subir con un nombre no adivinable (UUID v4 + extensión original).
	 *
	 * §9 — Los nombres de archivo originales (ej. "certificado-juan.pdf", "foto-equipo.jpg")
	 * son adivinables y podrían ser indexados por motores de búsqueda o enumerados por un
	 * atacante que conozca la ruta base de uploads/ del sitio.
	 *
	 * Este filtro reemplaza el nombre del archivo con un UUID v4 sin guiones antes de que
	 * WP llame a move_uploaded_file(), preservando únicamente la extensión original del
	 * archivo para que el servidor pueda servir el tipo MIME correcto.
	 *
	 * El filtro es global para TODAS las subidas mientras el admin del plugin está activo.
	 * Esto no afecta a subidas de temas ni de otros plugins porque el hook
	 * wp_handle_upload_prefilter es estándar de WP y aplica a toda la Media Library.
	 * Si se requiere limitar solo a los CPTs del plugin, se puede agregar una comprobación
	 * de get_current_screen() o del Referer, pero en el contexto del MVP esto es aceptable.
	 *
	 * NOTA: La extensión se extrae del nombre original (no del tipo MIME) porque en este
	 * punto aún no se ha determinado el MIME real. La validación de MIME la realiza WP
	 * en wp_handle_upload() con wp_check_filetype_and_ext(), que rechaza archivos cuya
	 * extensión no coincide con el contenido. No se duplica esa lógica aquí.
	 *
	 * @param array $file Array con claves: name, type, tmp_name, error, size.
	 * @return array El mismo array con 'name' reemplazado por UUID + extensión.
	 */
	public static function hash_uploaded_filename( $file ) {
		if ( empty( $file['name'] ) ) {
			return $file;
		}

		// Extraer la extensión del nombre original y convertirla a minúsculas.
		$extension = strtolower( pathinfo( $file['name'], PATHINFO_EXTENSION ) );

		// Solo renombrar extensiones permitidas en el plugin (PDF e imágenes comunes).
		// Extensiones no incluidas en esta lista se dejan como están (WP las rechazará
		// si no están en la lista de tipos permitidos de la Media Library).
		$extensiones_validas = array( 'pdf', 'jpg', 'jpeg', 'png', 'webp', 'gif' );

		if ( ! in_array( $extension, $extensiones_validas, true ) ) {
			return $file;
		}

		// Generar nombre no adivinable: UUID v4 sin guiones + extensión.
		$uuid = CalibraTrack_Helpers::generar_token_archivo();

		$file['name'] = $uuid . '.' . $extension;

		return $file;
	}

	// ─── METABOXES TIPO DOCUMENTO OI/OT ─────────────────────────────────────────

	/**
	 * Renderiza el metabox para seleccionar el tipo de documento: OI o OT.
	 *
	 * @param WP_Post $post Post actual.
	 * @return void
	 */
	public static function render_metabox_tipo_documento( $post ) {
		wp_nonce_field( 'calibratrack_tipo_doc_' . $post->ID, 'calibratrack_tipo_doc_nonce' );
		$tipo = (string) get_post_meta( $post->ID, CalibraTrack_Meta_Keys::EVENTO_TIPO_DOCUMENTO, true );
		if ( empty( $tipo ) ) {
			// Para posts nuevos, pre-seleccionar según el parámetro del link del menú.
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$preset = isset( $_GET['ct_tipo_preset'] ) ? sanitize_key( wp_unslash( $_GET['ct_tipo_preset'] ) ) : '';
			$tipo   = in_array( $preset, array( 'ingreso', 'ot' ), true ) ? $preset : 'ot';
		}
		?>
		<p>
			<label>
				<input type="radio" name="calibratrack_tipo_documento" value="ingreso"
					<?php checked( $tipo, 'ingreso' ); ?>>
				<?php esc_html_e( 'Orden de Ingreso (OI) — notifica al técnico', 'calibratrack' ); ?>
			</label>
		</p>
		<p>
			<label>
				<input type="radio" name="calibratrack_tipo_documento" value="ot"
					<?php checked( $tipo, 'ot' ); ?>>
				<?php esc_html_e( 'Orden de Trabajo (OT) — notifica al cliente', 'calibratrack' ); ?>
			</label>
		</p>
		<p style="margin-top:8px;font-size:11px;color:#666;">
			<?php esc_html_e( 'OI: aviso automático al técnico. OT: aviso automático al cliente con montos.', 'calibratrack' ); ?>
		</p>
		<?php
	}

	/**
	 * Renderiza el metabox para vincular una OT a su Orden de Ingreso.
	 *
	 * @param WP_Post $post Post actual.
	 * @return void
	 */
	public static function render_metabox_ingreso_relacionado( $post ) {
		$ingreso_id = (int) get_post_meta( $post->ID, CalibraTrack_Meta_Keys::EVENTO_INGRESO_RELACIONADO_ID, true );

		// Pre-seleccionar la OI cuando la OT se crea desde "Crear OT desde OI".
		if ( 'auto-draft' === $post->post_status && ! $ingreso_id ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$ingreso_id = isset( $_GET['ct_oi_id'] ) ? (int) $_GET['ct_oi_id'] : 0;
		}

		// Obtener todas las OIs existentes para el select.
		$ois = get_posts( array(
			'post_type'      => CalibraTrack_CPT_EventoServicio::SLUG,
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'orderby'        => 'date',
			'order'          => 'DESC',
			'meta_query'     => array(
				array(
					'key'   => CalibraTrack_Meta_Keys::EVENTO_TIPO_DOCUMENTO,
					'value' => 'ingreso',
				),
			),
		) );
		?>
		<p>
			<select name="calibratrack_ingreso_relacionado_id" style="width:100%;">
				<option value="0"><?php esc_html_e( '— Sin OI vinculada —', 'calibratrack' ); ?></option>
				<?php foreach ( $ois as $oi ) :
					$numero_oi = (string) get_post_meta( $oi->ID, CalibraTrack_Meta_Keys::EVENTO_NUMERO_OT, true );
					$serie_oi  = '';
					$eq_id     = (int) get_post_meta( $oi->ID, CalibraTrack_Meta_Keys::EVENTO_EQUIPO_ID, true );
					if ( $eq_id ) {
						$serie_oi = (string) get_post_meta( $eq_id, CalibraTrack_Meta_Keys::EQUIPO_SERIE, true );
					}
				?>
				<option value="<?php echo esc_attr( $oi->ID ); ?>"
					<?php selected( $ingreso_id, $oi->ID ); ?>>
					<?php echo esc_html( $numero_oi . ( $serie_oi ? ' — ' . $serie_oi : '' ) . ' (#' . $oi->ID . ')' ); ?>
				</option>
				<?php endforeach; ?>
			</select>
		</p>
		<p style="font-size:11px;color:#666;">
			<?php esc_html_e( 'Vincula esta OT a la Orden de Ingreso correspondiente.', 'calibratrack' ); ?>
		</p>
		<?php
	}

	/**
	 * Metabox lateral que muestra las OTs vinculadas a esta OI.
	 * Si el documento es una OT, muestra un aviso indicando que es OT, no OI.
	 *
	 * @param WP_Post $post Post actual.
	 * @return void
	 */
	public static function render_metabox_ots_asociadas( $post ) {
		$tipo_doc = (string) get_post_meta( $post->ID, CalibraTrack_Meta_Keys::EVENTO_TIPO_DOCUMENTO, true );

		// Si es una OT (o post nuevo que será OT), este metabox no aplica.
		if ( 'ingreso' !== $tipo_doc && 'auto-draft' !== $post->post_status ) {
			echo '<p style="font-size:12px;color:#999;">' . esc_html__( 'Solo aplica a Órdenes de Ingreso.', 'calibratrack' ) . '</p>';
			return;
		}

		if ( 'auto-draft' === $post->post_status ) {
			echo '<p style="font-size:12px;color:#999;">' . esc_html__( 'Disponible después de guardar.', 'calibratrack' ) . '</p>';
			return;
		}

		// Buscar OTs que tengan esta OI como ingreso relacionado.
		$ots = get_posts( array(
			'post_type'      => CalibraTrack_CPT_EventoServicio::SLUG,
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'orderby'        => 'date',
			'order'          => 'DESC',
			'meta_query'     => array(
				array(
					'key'   => CalibraTrack_Meta_Keys::EVENTO_INGRESO_RELACIONADO_ID,
					'value' => $post->ID,
					'type'  => 'NUMERIC',
				),
			),
		) );

		if ( empty( $ots ) ) {
			$nueva_ot_url = admin_url( 'post-new.php?post_type=evento_servicio&ct_tipo_preset=ot&ct_oi_id=' . $post->ID );
			echo '<p style="font-size:12px;color:#666;">' . esc_html__( 'No hay OTs asociadas aún.', 'calibratrack' ) . '</p>';
			echo '<a href="' . esc_url( $nueva_ot_url ) . '" class="button button-primary button-small">';
			echo '+ ' . esc_html__( 'Crear OT desde esta OI', 'calibratrack' );
			echo '</a>';
			return;
		}

		echo '<ul style="margin:0;padding:0;list-style:none;">';
		foreach ( $ots as $ot ) {
			$ot_num   = (string) get_post_meta( $ot->ID, CalibraTrack_Meta_Keys::EVENTO_NUMERO_OT, true );
			$ot_url   = get_edit_post_link( $ot->ID );
			$ot_label = $ot_num ? $ot_num : '#' . $ot->ID;
			echo '<li style="margin-bottom:6px;">';
			echo '<a href="' . esc_url( $ot_url ) . '" style="font-size:12px;">📋 ' . esc_html( $ot_label ) . '</a>';
			echo '</li>';
		}
		echo '</ul>';

		$nueva_ot_url = admin_url( 'post-new.php?post_type=evento_servicio&ct_tipo_preset=ot&ct_oi_id=' . $post->ID );
		echo '<p style="margin-top:8px;">';
		echo '<a href="' . esc_url( $nueva_ot_url ) . '" class="button button-small">';
		echo '+ ' . esc_html__( 'Nueva OT desde esta OI', 'calibratrack' );
		echo '</a>';
		echo '</p>';
	}

	// ─── GUARDADO DE EQUIPO ──────────────────────────────────────────────────────

	/**
	 * Hook save_post_equipo: guarda los metafields del equipo.
	 *
	 * Validaciones:
	 *   - Nonce obligatorio.
	 *   - Solo administradores pueden guardar equipos (técnico no puede).
	 *   - Serie, marca y modelo son campos obligatorios.
	 *   - Serie debe ser única en todo el sistema.
	 *
	 * @param int     $post_id Post ID del equipo.
	 * @param WP_Post $post    Objeto post.
	 * @param bool    $update  True si es actualización, false si es creación.
	 * @return void
	 */
	public static function save_equipo_meta( $post_id, $post, $update ) {
		// Ignorar autoguardado de WP.
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		// Ignorar revisiones.
		if ( wp_is_post_revision( $post_id ) ) {
			return;
		}

		// Verificar nonce.
		$nonce_field = self::NONCE_EQUIPO;
		if ( ! isset( $_POST[ $nonce_field ] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST[ $nonce_field ] ) ), $nonce_field . '_' . $post_id ) ) {
			return;
		}

		// Verificar capabilities: solo usuarios con edit_equipos o superior.
		if ( ! current_user_can( 'edit_equipos' ) && ! current_user_can( 'manage_options' ) ) {
			return;
		}

		// ── Sanitizar valores del formulario ──────────────────────────────────────
		$serie         = isset( $_POST[ CalibraTrack_Meta_Keys::EQUIPO_SERIE ] ) ? sanitize_text_field( wp_unslash( $_POST[ CalibraTrack_Meta_Keys::EQUIPO_SERIE ] ) ) : '';
		$marca         = isset( $_POST[ CalibraTrack_Meta_Keys::EQUIPO_MARCA ] ) ? sanitize_text_field( wp_unslash( $_POST[ CalibraTrack_Meta_Keys::EQUIPO_MARCA ] ) ) : '';
		$modelo        = isset( $_POST[ CalibraTrack_Meta_Keys::EQUIPO_MODELO ] ) ? sanitize_text_field( wp_unslash( $_POST[ CalibraTrack_Meta_Keys::EQUIPO_MODELO ] ) ) : '';
		$tipo_equipo   = isset( $_POST[ CalibraTrack_Meta_Keys::EQUIPO_TIPO ] ) ? sanitize_key( wp_unslash( $_POST[ CalibraTrack_Meta_Keys::EQUIPO_TIPO ] ) ) : '';
		$cliente_prop  = isset( $_POST[ CalibraTrack_Meta_Keys::EQUIPO_CLIENTE_PROPIETARIO ] ) ? absint( $_POST[ CalibraTrack_Meta_Keys::EQUIPO_CLIENTE_PROPIETARIO ] ) : 0;
		$fecha_ingreso = isset( $_POST[ CalibraTrack_Meta_Keys::EQUIPO_FECHA_INGRESO ] ) ? sanitize_text_field( wp_unslash( $_POST[ CalibraTrack_Meta_Keys::EQUIPO_FECHA_INGRESO ] ) ) : '';

		// ── Validaciones de negocio ───────────────────────────────────────────────
		$errores = array();

		// Campos obligatorios.
		if ( empty( $serie ) ) {
			$errores[] = __( 'El número de serie es obligatorio.', 'calibratrack' );
		}
		if ( empty( $marca ) ) {
			$errores[] = __( 'La marca es obligatoria.', 'calibratrack' );
		}
		if ( empty( $modelo ) ) {
			$errores[] = __( 'El modelo es obligatorio.', 'calibratrack' );
		}

		// Tipo de equipo válido (si se proporcionó).
		if ( ! empty( $tipo_equipo ) && ! array_key_exists( $tipo_equipo, CalibraTrack_Helpers::get_tipos_equipo() ) ) {
			$errores[] = __( 'El tipo de equipo seleccionado no es válido.', 'calibratrack' );
			$tipo_equipo = '';
		}

		// Serie única: verificar que no exista otro equipo con la misma serie.
		if ( ! empty( $serie ) ) {
			$equipo_existente = self::buscar_equipo_por_serie( $serie, $post_id );
			if ( $equipo_existente ) {
				$errores[] = sprintf(
					/* translators: %s: número de serie */
					__( 'El número de serie "%s" ya está registrado para otro equipo.', 'calibratrack' ),
					esc_html( $serie )
				);
			}
		}

		// Si hay errores, guardar el mensaje y salir sin guardar los meta.
		if ( ! empty( $errores ) ) {
			self::set_admin_notice( 'error', implode( ' | ', $errores ), $post_id );
			return;
		}

		// ── Leer valores previos ANTES de guardar (necesario para detectar cambio de serie) ──
		$serie_anterior  = (string) get_post_meta( $post_id, CalibraTrack_Meta_Keys::EQUIPO_SERIE, true );
		$qr_existente_id = (int) get_post_meta( $post_id, CalibraTrack_Meta_Keys::EQUIPO_CODIGO_QR, true );

		// ── Guardar meta fields ───────────────────────────────────────────────────
		update_post_meta( $post_id, CalibraTrack_Meta_Keys::EQUIPO_SERIE, $serie );
		update_post_meta( $post_id, CalibraTrack_Meta_Keys::EQUIPO_MARCA, $marca );
		update_post_meta( $post_id, CalibraTrack_Meta_Keys::EQUIPO_MODELO, $modelo );
		update_post_meta( $post_id, CalibraTrack_Meta_Keys::EQUIPO_TIPO, $tipo_equipo );
		update_post_meta( $post_id, CalibraTrack_Meta_Keys::EQUIPO_CLIENTE_PROPIETARIO, $cliente_prop );
		update_post_meta( $post_id, CalibraTrack_Meta_Keys::EQUIPO_FECHA_INGRESO, $fecha_ingreso );

		// Invalidar el transient de vigencia de este equipo (por si el cliente cambió).
		delete_transient( 'calibratrack_vigencia_' . $post_id );

		// ── Generar o regenerar el código QR del equipo (CA-1) ───────────────────
		// Condiciones para (re)generar:
		//   1. No existe QR aún ($qr_existente_id === 0): primer guardado del equipo.
		//   2. La serie cambió respecto al valor anterior: la URL del QR cambiaría,
		//      así que el QR anterior quedaría apuntando a una URL incorrecta.
		// Si ninguna condición se cumple, no se regenera — evita trabajo innecesario
		// cuando el administrador solo actualiza marca, modelo, cliente o fecha.
		$serie_cambio = ( $serie !== $serie_anterior );
		$sin_qr       = ( 0 === $qr_existente_id );

		if ( $serie_cambio || $sin_qr ) {
			if ( class_exists( 'CalibraTrack_QR' ) ) {
				$nuevo_qr_id = CalibraTrack_QR::generate_for_equipo( $serie, $post_id );
				if ( false === $nuevo_qr_id ) {
					self::set_admin_notice(
						'error',
						__( 'Los datos del equipo se guardaron, pero no fue posible generar el código QR. Verifique que las dependencias de Composer estén instaladas (ejecute composer install en el directorio del plugin).', 'calibratrack' ),
						$post_id
					);
				}
			}
		}
	}

	/**
	 * Busca un equipo con una serie dada, excluyendo opcionalmente un post ID.
	 * Se usa para validar unicidad de serie al crear/actualizar equipos.
	 *
	 * @param string $serie      Número de serie a buscar.
	 * @param int    $exclude_id Post ID a excluir (el propio post en caso de actualización).
	 * @return WP_Post|null Post del equipo encontrado, o null.
	 */
	private static function buscar_equipo_por_serie( $serie, $exclude_id = 0 ) {
		$args = array(
			'post_type'      => CalibraTrack_CPT_Equipo::SLUG,
			'post_status'    => array( 'publish', 'draft', 'pending', 'private' ),
			'posts_per_page' => 1,
			'no_found_rows'  => true,
			'fields'         => 'ids',
			'meta_query'     => array(
				array(
					'key'     => CalibraTrack_Meta_Keys::EQUIPO_SERIE,
					'value'   => $serie,
					'compare' => '=',
				),
			),
		);

		if ( $exclude_id > 0 ) {
			$args['post__not_in'] = array( $exclude_id );
		}

		$query = new WP_Query( $args );

		if ( $query->have_posts() ) {
			return get_post( $query->posts[0] );
		}

		return null;
	}

	// ─── GUARDADO DE CLIENTE ─────────────────────────────────────────────────────

	/**
	 * Hook save_post_cliente: guarda los metafields del cliente.
	 *
	 * Validaciones:
	 *   - Nonce obligatorio.
	 *   - Solo administradores pueden guardar clientes.
	 *   - nombre_empresa y rut son obligatorios.
	 *   - RUT debe ser válido (formato y dígito verificador).
	 *
	 * @param int     $post_id Post ID del cliente.
	 * @param WP_Post $post    Objeto post.
	 * @param bool    $update  True si es actualización.
	 * @return void
	 */
	public static function save_cliente_meta( $post_id, $post, $update ) {
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		if ( wp_is_post_revision( $post_id ) ) {
			return;
		}

		$nonce_field = self::NONCE_CLIENTE;
		if ( ! isset( $_POST[ $nonce_field ] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST[ $nonce_field ] ) ), $nonce_field . '_' . $post_id ) ) {
			return;
		}

		// Solo admins pueden crear/editar clientes.
		if ( ! current_user_can( 'edit_clientes' ) && ! current_user_can( 'manage_options' ) ) {
			return;
		}

		// ── Sanitizar valores ─────────────────────────────────────────────────────
		$nombre_empresa  = isset( $_POST[ CalibraTrack_Meta_Keys::CLIENTE_NOMBRE_EMPRESA ] ) ? sanitize_text_field( wp_unslash( $_POST[ CalibraTrack_Meta_Keys::CLIENTE_NOMBRE_EMPRESA ] ) ) : '';
		$rut             = isset( $_POST[ CalibraTrack_Meta_Keys::CLIENTE_RUT ] ) ? sanitize_text_field( wp_unslash( $_POST[ CalibraTrack_Meta_Keys::CLIENTE_RUT ] ) ) : '';
		$contacto_nombre = isset( $_POST[ CalibraTrack_Meta_Keys::CLIENTE_CONTACTO_NOMBRE ] ) ? sanitize_text_field( wp_unslash( $_POST[ CalibraTrack_Meta_Keys::CLIENTE_CONTACTO_NOMBRE ] ) ) : '';
		$telefono        = isset( $_POST[ CalibraTrack_Meta_Keys::CLIENTE_TELEFONO ] ) ? sanitize_text_field( wp_unslash( $_POST[ CalibraTrack_Meta_Keys::CLIENTE_TELEFONO ] ) ) : '';
		$correo          = isset( $_POST[ CalibraTrack_Meta_Keys::CLIENTE_CORREO ] ) ? sanitize_email( wp_unslash( $_POST[ CalibraTrack_Meta_Keys::CLIENTE_CORREO ] ) ) : '';
		$direccion       = isset( $_POST[ CalibraTrack_Meta_Keys::CLIENTE_DIRECCION ] ) ? sanitize_text_field( wp_unslash( $_POST[ CalibraTrack_Meta_Keys::CLIENTE_DIRECCION ] ) ) : '';

		// ── Validaciones de negocio ───────────────────────────────────────────────
		$errores = array();

		if ( empty( $nombre_empresa ) ) {
			$errores[] = __( 'El nombre de la empresa es obligatorio.', 'calibratrack' );
		}

		if ( empty( $rut ) ) {
			$errores[] = __( 'El RUT es obligatorio.', 'calibratrack' );
		} elseif ( ! CalibraTrack_Helpers::validar_rut( $rut ) ) {
			$errores[] = __( 'El RUT ingresado no es válido (verifique el formato y el dígito verificador).', 'calibratrack' );
		}

		if ( ! empty( $errores ) ) {
			self::set_admin_notice( 'error', implode( ' | ', $errores ), $post_id );
			return;
		}

		// ── Guardar meta fields ───────────────────────────────────────────────────
		update_post_meta( $post_id, CalibraTrack_Meta_Keys::CLIENTE_NOMBRE_EMPRESA, $nombre_empresa );
		update_post_meta( $post_id, CalibraTrack_Meta_Keys::CLIENTE_RUT, $rut );
		update_post_meta( $post_id, CalibraTrack_Meta_Keys::CLIENTE_CONTACTO_NOMBRE, $contacto_nombre );
		update_post_meta( $post_id, CalibraTrack_Meta_Keys::CLIENTE_TELEFONO, $telefono );
		update_post_meta( $post_id, CalibraTrack_Meta_Keys::CLIENTE_CORREO, $correo );
		update_post_meta( $post_id, CalibraTrack_Meta_Keys::CLIENTE_DIRECCION, $direccion );
	}

	// ─── GUARDADO DE EVENTO DE SERVICIO ─────────────────────────────────────────

	/**
	 * Hook save_post_evento_servicio: guarda los metafields del evento.
	 *
	 * Lógica de negocio:
	 *   - Verifica nonce y capabilities (técnico solo guarda sus propios eventos).
	 *   - Valida campos obligatorios y coherencia de fechas.
	 *   - Guarda todos los meta fields via constantes de CalibraTrack_Meta_Keys.
	 *   - Llama CalibraTrack_DB::save_items_costo() para el repetidor.
	 *   - Calcula totales en servidor (subtotal, IVA, total) — nunca confiar en el navegador.
	 *   - Invalida el transient de vigencia del equipo relacionado (D-17).
	 *
	 * @param int     $post_id Post ID del evento.
	 * @param WP_Post $post    Objeto post.
	 * @param bool    $update  True si es actualización.
	 * @return void
	 */
	public static function save_evento_meta( $post_id, $post, $update ) {
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		if ( wp_is_post_revision( $post_id ) ) {
			return;
		}

		$nonce_field = self::NONCE_EVENTO;
		if ( ! isset( $_POST[ $nonce_field ] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST[ $nonce_field ] ) ), $nonce_field . '_' . $post_id ) ) {
			return;
		}

		// Verificar capabilities: el usuario debe poder editar eventos de servicio.
		// La restricción de "solo propios" se aplica via el filtro user_has_cap.
		if ( ! current_user_can( 'edit_evento_servicio', $post_id ) ) {
			return;
		}

		// Verificación adicional explícita: técnico no puede guardar eventos ajenos.
		// Doble verificación de seguridad — el filtro user_has_cap ya debería haberlo bloqueado.
		if ( ! current_user_can( 'manage_options' ) ) {
			$current_user_id = get_current_user_id();
			if ( (int) $post->post_author !== $current_user_id ) {
				self::set_admin_notice(
					'error',
					__( 'No tiene permisos para editar este evento de servicio.', 'calibratrack' ),
					$post_id
				);
				return;
			}
		}

		// ── Sanitizar valores del formulario ──────────────────────────────────────
		$equipo_id       = isset( $_POST[ CalibraTrack_Meta_Keys::EVENTO_EQUIPO_ID ] ) ? absint( $_POST[ CalibraTrack_Meta_Keys::EVENTO_EQUIPO_ID ] ) : 0;
		$numero_ot       = isset( $_POST[ CalibraTrack_Meta_Keys::EVENTO_NUMERO_OT ] ) ? sanitize_text_field( wp_unslash( $_POST[ CalibraTrack_Meta_Keys::EVENTO_NUMERO_OT ] ) ) : '';
		$tipo_evento     = isset( $_POST[ CalibraTrack_Meta_Keys::EVENTO_TIPO ] ) ? sanitize_key( wp_unslash( $_POST[ CalibraTrack_Meta_Keys::EVENTO_TIPO ] ) ) : '';
		$fecha_ejecucion = isset( $_POST[ CalibraTrack_Meta_Keys::EVENTO_FECHA_EJECUCION ] ) ? sanitize_text_field( wp_unslash( $_POST[ CalibraTrack_Meta_Keys::EVENTO_FECHA_EJECUCION ] ) ) : '';
		$proxima_fecha   = isset( $_POST[ CalibraTrack_Meta_Keys::EVENTO_PROXIMA_FECHA_CONTROL ] ) ? sanitize_text_field( wp_unslash( $_POST[ CalibraTrack_Meta_Keys::EVENTO_PROXIMA_FECHA_CONTROL ] ) ) : '';
		$tecnico_id      = isset( $_POST[ CalibraTrack_Meta_Keys::EVENTO_TECNICO_RESPONSABLE ] ) ? absint( $_POST[ CalibraTrack_Meta_Keys::EVENTO_TECNICO_RESPONSABLE ] ) : 0;
		$falla           = isset( $_POST[ CalibraTrack_Meta_Keys::EVENTO_FALLA_REPORTADA ] ) ? sanitize_textarea_field( wp_unslash( $_POST[ CalibraTrack_Meta_Keys::EVENTO_FALLA_REPORTADA ] ) ) : '';
		$descripcion     = isset( $_POST[ CalibraTrack_Meta_Keys::EVENTO_DESCRIPCION_TRABAJO ] ) ? sanitize_textarea_field( wp_unslash( $_POST[ CalibraTrack_Meta_Keys::EVENTO_DESCRIPCION_TRABAJO ] ) ) : '';
		$observaciones   = isset( $_POST[ CalibraTrack_Meta_Keys::EVENTO_OBSERVACIONES ] ) ? sanitize_textarea_field( wp_unslash( $_POST[ CalibraTrack_Meta_Keys::EVENTO_OBSERVACIONES ] ) ) : '';
		$garantia        = isset( $_POST[ CalibraTrack_Meta_Keys::EVENTO_GARANTIA ] ) ? 1 : 0;
		$dias_garantia   = isset( $_POST[ CalibraTrack_Meta_Keys::EVENTO_DIAS_GARANTIA ] ) ? absint( $_POST[ CalibraTrack_Meta_Keys::EVENTO_DIAS_GARANTIA ] ) : 0;
		// Estado del servicio: solo valores válidos.
		$estado_raw      = isset( $_POST[ CalibraTrack_Meta_Keys::EVENTO_ESTADO_SERVICIO ] ) ? sanitize_key( wp_unslash( $_POST[ CalibraTrack_Meta_Keys::EVENTO_ESTADO_SERVICIO ] ) ) : 'en_proceso';
		$estado_servicio = in_array( $estado_raw, array( 'en_proceso', 'completado' ), true ) ? $estado_raw : 'en_proceso';
		// Estado anterior (para detectar transición a completado).
		$estado_anterior = (string) get_post_meta( $post_id, CalibraTrack_Meta_Keys::EVENTO_ESTADO_SERVICIO, true );
		if ( empty( $estado_anterior ) ) {
			$estado_anterior = 'en_proceso';
		}
		// cert_pdf_id y ot_pdf_id NO se leen del POST — son gestionados internamente
		// por el sistema (generación automática). Leer el valor actual desde la BD.
		$cert_pdf_id     = (int) get_post_meta( $post_id, CalibraTrack_Meta_Keys::EVENTO_CERTIFICADO_PDF, true );

		// Evidencia fotográfica: JSON array de attachment IDs.
		$evidencia_raw   = isset( $_POST[ CalibraTrack_Meta_Keys::EVENTO_EVIDENCIA_FOTOGRAFICA ] )
			? sanitize_text_field( wp_unslash( $_POST[ CalibraTrack_Meta_Keys::EVENTO_EVIDENCIA_FOTOGRAFICA ] ) )
			: '';
		// Validar que sea JSON array de enteros positivos.
		$evidencia_ids   = array();
		if ( ! empty( $evidencia_raw ) ) {
			$decoded = json_decode( $evidencia_raw, true );
			if ( is_array( $decoded ) ) {
				$evidencia_ids = array_values( array_filter( array_map( 'absint', $decoded ) ) );
			}
		}
		$evidencia_json  = wp_json_encode( $evidencia_ids );

		// Ítems de costo: el navegador envía el array, pero el cálculo de totales
		// se hace en servidor — nunca confiamos en valores calculados por el cliente.
		$items_raw = isset( $_POST['calibratrack_items'] ) && is_array( $_POST['calibratrack_items'] )
			? $_POST['calibratrack_items']   // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			: array();

		// ── Validaciones de negocio ───────────────────────────────────────────────
		$errores = array();

		if ( $equipo_id <= 0 ) {
			$errores[] = __( 'Debe seleccionar un equipo válido.', 'calibratrack' );
		}

		if ( empty( $numero_ot ) ) {
			$errores[] = __( 'El número de orden de trabajo es obligatorio.', 'calibratrack' );
		}

		if ( empty( $fecha_ejecucion ) ) {
			$errores[] = __( 'La fecha de ejecución es obligatoria.', 'calibratrack' );
		}

		if ( empty( $proxima_fecha ) ) {
			$errores[] = __( 'La próxima fecha de control es obligatoria.', 'calibratrack' );
		}

		if ( empty( $tipo_evento ) ) {
			$errores[] = __( 'El tipo de evento es obligatorio.', 'calibratrack' );
		} elseif ( ! array_key_exists( $tipo_evento, CalibraTrack_Helpers::get_tipos_evento() ) ) {
			$errores[] = __( 'El tipo de evento seleccionado no es válido.', 'calibratrack' );
			$tipo_evento = '';
		}

		// Validar coherencia de fechas: proxima_fecha no puede ser anterior a fecha_ejecucion.
		if ( ! empty( $fecha_ejecucion ) && ! empty( $proxima_fecha ) ) {
			$dt_ejecucion = DateTime::createFromFormat( 'Y-m-d', $fecha_ejecucion );
			$dt_proxima   = DateTime::createFromFormat( 'Y-m-d', $proxima_fecha );

			if ( false === $dt_ejecucion || false === $dt_proxima ) {
				$errores[] = __( 'Las fechas deben tener formato YYYY-MM-DD.', 'calibratrack' );
			} elseif ( $dt_proxima < $dt_ejecucion ) {
				$errores[] = __( 'La próxima fecha de control no puede ser anterior a la fecha de ejecución.', 'calibratrack' );
			}
		}

		// Verificar que el equipo existe.
		if ( $equipo_id > 0 ) {
			$equipo_post = get_post( $equipo_id );
			if ( ! $equipo_post || CalibraTrack_CPT_Equipo::SLUG !== $equipo_post->post_type ) {
				$errores[] = __( 'El equipo seleccionado no existe en el sistema.', 'calibratrack' );
				$equipo_id = 0;
			}
		}

		if ( ! empty( $errores ) ) {
			self::set_admin_notice( 'error', implode( ' | ', $errores ), $post_id );
			return;
		}

		// ── Sanitizar ítems de costo ──────────────────────────────────────────────
		$items_sanitizados = array();
		foreach ( $items_raw as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			$detalle         = isset( $item['detalle'] ) ? sanitize_text_field( wp_unslash( $item['detalle'] ) ) : '';
			$cantidad        = isset( $item['cantidad'] ) ? (float) $item['cantidad'] : 0.0;
			$precio_unitario = isset( $item['precio_unitario'] ) ? (float) $item['precio_unitario'] : 0.0;

			// Omitir filas vacías.
			if ( '' === $detalle && 0.0 === $cantidad && 0.0 === $precio_unitario ) {
				continue;
			}

			$items_sanitizados[] = array(
				'detalle'         => $detalle,
				'cantidad'        => $cantidad,
				'precio_unitario' => $precio_unitario,
			);
		}

		// ── Calcular totales en servidor (D-02: nunca confiar en el navegador) ────
		$totales = CalibraTrack_Helpers::calcular_totales_costo( $items_sanitizados );

		// ── Guardar meta fields ───────────────────────────────────────────────────
		update_post_meta( $post_id, CalibraTrack_Meta_Keys::EVENTO_EQUIPO_ID, $equipo_id );
		update_post_meta( $post_id, CalibraTrack_Meta_Keys::EVENTO_NUMERO_OT, $numero_ot );
		update_post_meta( $post_id, CalibraTrack_Meta_Keys::EVENTO_TIPO, $tipo_evento );
		update_post_meta( $post_id, CalibraTrack_Meta_Keys::EVENTO_FECHA_EJECUCION, $fecha_ejecucion );
		update_post_meta( $post_id, CalibraTrack_Meta_Keys::EVENTO_PROXIMA_FECHA_CONTROL, $proxima_fecha );
		update_post_meta( $post_id, CalibraTrack_Meta_Keys::EVENTO_TECNICO_RESPONSABLE, $tecnico_id );
		update_post_meta( $post_id, CalibraTrack_Meta_Keys::EVENTO_FALLA_REPORTADA, $falla );
		update_post_meta( $post_id, CalibraTrack_Meta_Keys::EVENTO_DESCRIPCION_TRABAJO, $descripcion );
		update_post_meta( $post_id, CalibraTrack_Meta_Keys::EVENTO_OBSERVACIONES, $observaciones );
		update_post_meta( $post_id, CalibraTrack_Meta_Keys::EVENTO_GARANTIA, $garantia );
		update_post_meta( $post_id, CalibraTrack_Meta_Keys::EVENTO_DIAS_GARANTIA, $dias_garantia );
		update_post_meta( $post_id, CalibraTrack_Meta_Keys::EVENTO_ESTADO_SERVICIO, $estado_servicio );
		// cert_pdf_id y ot_pdf_id son gestionados por el sistema — no se sobreescriben aquí.
		update_post_meta( $post_id, CalibraTrack_Meta_Keys::EVENTO_EVIDENCIA_FOTOGRAFICA, $evidencia_json );

		// Guardar totales calculados en servidor (solo lectura — no editables desde formulario).
		update_post_meta( $post_id, 'calibratrack_subtotal', $totales['subtotal'] );
		update_post_meta( $post_id, 'calibratrack_iva',      $totales['iva'] );
		update_post_meta( $post_id, 'calibratrack_total',    $totales['total'] );

		// ── Guardar ítems de costo en tabla custom ────────────────────────────────
		CalibraTrack_DB::save_items_costo( $post_id, $items_sanitizados );

		// ── Invalidar transient de vigencia del equipo relacionado (D-17) ─────────
		if ( $equipo_id > 0 ) {
			delete_transient( 'calibratrack_vigencia_' . $equipo_id );
		}

		// ── Guardar tipo de documento (OI / OT) e ingreso relacionado ──────────────
		$tipo_doc_nonce_val = isset( $_POST['calibratrack_tipo_doc_nonce'] )
			? sanitize_text_field( wp_unslash( $_POST['calibratrack_tipo_doc_nonce'] ) )
			: '';
		$tiene_tipo_doc_nonce = wp_verify_nonce( $tipo_doc_nonce_val, 'calibratrack_tipo_doc_' . $post_id );

		if ( $tiene_tipo_doc_nonce ) {
			$tipo_doc_raw = isset( $_POST['calibratrack_tipo_documento'] )
				? sanitize_key( wp_unslash( $_POST['calibratrack_tipo_documento'] ) )
				: 'ot';
			$tipo_doc = in_array( $tipo_doc_raw, array( 'ingreso', 'ot' ), true ) ? $tipo_doc_raw : 'ot';

			// Detectar si es la primera vez que se guarda este campo (para disparar correo).
			$tipo_doc_anterior = (string) get_post_meta( $post_id, CalibraTrack_Meta_Keys::EVENTO_TIPO_DOCUMENTO, true );
			$es_primer_guardado_tipo = empty( $tipo_doc_anterior );

			update_post_meta( $post_id, CalibraTrack_Meta_Keys::EVENTO_TIPO_DOCUMENTO, $tipo_doc );

			$ingreso_relacionado_id = absint( isset( $_POST['calibratrack_ingreso_relacionado_id'] ) ? $_POST['calibratrack_ingreso_relacionado_id'] : 0 );
			update_post_meta( $post_id, CalibraTrack_Meta_Keys::EVENTO_INGRESO_RELACIONADO_ID, $ingreso_relacionado_id );
		} else {
			// Sin nonce de tipo_doc: usar el valor almacenado (o 'ot' por defecto).
			$tipo_doc               = (string) get_post_meta( $post_id, CalibraTrack_Meta_Keys::EVENTO_TIPO_DOCUMENTO, true );
			$tipo_doc               = empty( $tipo_doc ) ? 'ot' : $tipo_doc;
			$es_primer_guardado_tipo = false;
		}

		// ── Generación de PDFs y envío de correos ────────────────────────────────────
		if ( class_exists( 'CalibraTrack_PDF_Generator' ) ) {

			if ( 'ingreso' === $tipo_doc ) {
				// OI: no genera OT ni certificado. Solo envía aviso al técnico si es nuevo.
				if ( $es_primer_guardado_tipo && class_exists( 'CalibraTrack_Mailer' ) ) {
					CalibraTrack_Mailer::send_oi_a_tecnico( $post_id );
				}
			} else {
				// OT: flujo estándar de OT + certificado.
				if ( ! $update ) {
					// EVENTO NUEVO: generar OT PDF + enviar aviso al cliente.
					CalibraTrack_PDF_Generator::generate_orden_trabajo( $post_id );
					if ( class_exists( 'CalibraTrack_Mailer' ) ) {
						CalibraTrack_Mailer::send_ot_a_cliente( $post_id );
					}
				} else {
					// EVENTO EDITADO: regenerar OT (datos pueden haber cambiado).
					CalibraTrack_PDF_Generator::generate_orden_trabajo( $post_id );
				}

				if ( 'completado' === $estado_servicio ) {
					// Generar/regenerar certificado.
					CalibraTrack_PDF_Generator::generate_certificado( $post_id );

					// Enviar certificado al cliente solo en la transición en_proceso → completado.
					if ( 'en_proceso' === $estado_anterior && class_exists( 'CalibraTrack_Mailer' ) ) {
						CalibraTrack_Mailer::send_certificado_a_cliente( $post_id );
					}
				}
			}
		}
	}

	// ─── BORRADO DE EVENTO ───────────────────────────────────────────────────────

	/**
	 * Hook before_delete_post: limpia los ítems de costo al borrar un evento definitivamente.
	 *
	 * @param int $post_id Post ID que se va a borrar.
	 * @return void
	 */
	public static function on_before_delete_post( $post_id ) {
		$post = get_post( $post_id );
		if ( ! $post || CalibraTrack_CPT_EventoServicio::SLUG !== $post->post_type ) {
			return;
		}

		// Obtener el equipo relacionado antes de borrar los meta.
		$equipo_id = (int) get_post_meta( $post_id, CalibraTrack_Meta_Keys::EVENTO_EQUIPO_ID, true );

		// Eliminar ítems de la tabla custom.
		CalibraTrack_DB::delete_items_costo( $post_id );

		// Invalidar transient de vigencia del equipo relacionado.
		if ( $equipo_id > 0 ) {
			delete_transient( 'calibratrack_vigencia_' . $equipo_id );
		}
	}

	// ─── COLUMNAS Y FILTROS DE EVENTO_SERVICIO ───────────────────────────────────

	/**
	 * Filtra la lista de evento_servicio en wp-admin por tipo de documento (OI o OT)
	 * cuando viene el parámetro ?ct_tipo en la URL del menú.
	 *
	 * @param WP_Query $query Query en ejecución.
	 * @return void
	 */
	public static function filtrar_eventos_por_tipo( $query ) {
		if ( ! is_admin() || ! $query->is_main_query() ) {
			return;
		}
		if ( 'evento_servicio' !== $query->get( 'post_type' ) ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$ct_tipo = isset( $_GET['ct_tipo'] ) ? sanitize_key( wp_unslash( $_GET['ct_tipo'] ) ) : '';
		if ( ! in_array( $ct_tipo, array( 'ingreso', 'ot' ), true ) ) {
			return;
		}

		$meta_query = array(
			'relation' => 'OR',
			array(
				'key'   => CalibraTrack_Meta_Keys::EVENTO_TIPO_DOCUMENTO,
				'value' => $ct_tipo,
			),
		);
		// Si el filtro es OT, incluir también eventos sin tipo_documento (backward compat).
		if ( 'ot' === $ct_tipo ) {
			$meta_query[] = array(
				'key'     => CalibraTrack_Meta_Keys::EVENTO_TIPO_DOCUMENTO,
				'compare' => 'NOT EXISTS',
			);
		}
		$query->set( 'meta_query', $meta_query );
	}

	/**
	 * Define las columnas visibles en la lista de evento_servicio en wp-admin.
	 *
	 * @param array $columns Columnas actuales.
	 * @return array
	 */
	public static function evento_servicio_columns( $columns ) {
		$new = array();
		if ( isset( $columns['cb'] ) )    { $new['cb']    = $columns['cb']; }
		if ( isset( $columns['title'] ) ) { $new['title'] = $columns['title']; }
		$new['ct_tipo_doc']   = __( 'Tipo', 'calibratrack' );
		$new['ct_numero_ot']  = __( 'N° OT', 'calibratrack' );
		$new['ct_equipo']     = __( 'Equipo (serie)', 'calibratrack' );
		$new['ct_fecha']      = __( 'Fecha ejecución', 'calibratrack' );
		if ( isset( $columns['date'] ) )  { $new['date']  = $columns['date']; }
		return $new;
	}

	/**
	 * Renderiza el contenido de las columnas personalizadas de evento_servicio.
	 *
	 * @param string $column  Slug de la columna.
	 * @param int    $post_id ID del post.
	 * @return void
	 */
	public static function evento_servicio_column_content( $column, $post_id ) {
		switch ( $column ) {
			case 'ct_tipo_doc':
				$tipo = (string) get_post_meta( $post_id, CalibraTrack_Meta_Keys::EVENTO_TIPO_DOCUMENTO, true );
				if ( 'ingreso' === $tipo ) {
					$nueva_ot_url = admin_url( 'post-new.php?post_type=evento_servicio&ct_tipo_preset=ot&ct_oi_id=' . $post_id );
					echo '<span style="background:#dbeafe;color:#1e40af;padding:2px 8px;border-radius:4px;font-size:11px;font-weight:600;">OI</span>';
					echo '&nbsp;<a href="' . esc_url( $nueva_ot_url ) . '" class="button button-small" style="font-size:11px;padding:1px 6px;height:auto;line-height:1.6;" title="' . esc_attr__( 'Crear Orden de Trabajo desde esta OI', 'calibratrack' ) . '">';
					echo '+ ' . esc_html__( 'Crear OT', 'calibratrack' ) . '</a>';
				} else {
					$oi_vinculada = (int) get_post_meta( $post_id, CalibraTrack_Meta_Keys::EVENTO_INGRESO_RELACIONADO_ID, true );
					echo '<span style="background:#dcfce7;color:#166534;padding:2px 8px;border-radius:4px;font-size:11px;font-weight:600;">OT</span>';
					if ( $oi_vinculada > 0 ) {
						$oi_numero = (string) get_post_meta( $oi_vinculada, CalibraTrack_Meta_Keys::EVENTO_NUMERO_OT, true );
						$oi_url    = get_edit_post_link( $oi_vinculada );
						echo '&nbsp;<a href="' . esc_url( $oi_url ) . '" style="font-size:11px;color:#555;" title="' . esc_attr__( 'Ver OI vinculada', 'calibratrack' ) . '">';
						echo '← ' . esc_html( $oi_numero ?: 'OI #' . $oi_vinculada ) . '</a>';
					}
				}
				break;
			case 'ct_numero_ot':
				$ot = (string) get_post_meta( $post_id, CalibraTrack_Meta_Keys::EVENTO_NUMERO_OT, true );
				echo esc_html( $ot ?: '—' );
				break;
			case 'ct_equipo':
				$equipo_id = (int) get_post_meta( $post_id, CalibraTrack_Meta_Keys::EVENTO_EQUIPO_ID, true );
				$serie     = $equipo_id ? (string) get_post_meta( $equipo_id, CalibraTrack_Meta_Keys::EQUIPO_SERIE, true ) : '—';
				echo esc_html( $serie );
				break;
			case 'ct_fecha':
				$fecha = (string) get_post_meta( $post_id, CalibraTrack_Meta_Keys::EVENTO_FECHA_EJECUCION, true );
				if ( $fecha ) {
					$dt = DateTime::createFromFormat( 'Y-m-d', $fecha );
					echo $dt ? esc_html( $dt->format( 'd/m/Y' ) ) : esc_html( $fecha );
				} else {
					echo '—';
				}
				break;
		}
	}

	// ─── RESTRICCIÓN DE CAPABILITIES ────────────────────────────────────────────

	/**
	 * Filtro user_has_cap: un técnico solo puede editar los eventos de servicio
	 * de los que es autor (post_author == current_user_id).
	 *
	 * Este filtro complementa las capabilities del rol tecnico_calibracion.
	 * Sin él, un técnico con edit_eventos_servicio podría editar eventos de otros
	 * técnicos si conociera la URL directa del post en el admin.
	 *
	 * @param array   $allcaps   Todas las capabilities del usuario.
	 * @param array   $caps      Capabilities requeridas para la operación.
	 * @param array   $args      Argumentos adicionales [0 => cap, 1 => user_id, 2 => object_id].
	 * @param WP_User $user      Objeto usuario.
	 * @return array
	 */
	public static function filter_tecnico_solo_propios_eventos( $allcaps, $caps, $args, $user ) {
		// Solo intervenir si la capability solicitada es una de edición de evento.
		if ( empty( $args[0] ) || empty( $args[2] ) ) {
			return $allcaps;
		}

		$cap_solicitada = $args[0];
		// Cubrir también caps de borrado: aunque el rol técnico no tiene delete_eventos_servicio,
		// la defensa en profundidad exige que aunque alguien asigne esa cap manualmente,
		// un técnico nunca pueda borrar un evento de otro técnico.
		$caps_de_edicion = array(
			'edit_evento_servicio',
			'edit_post',           // WP puede usar esta cap genérica con map_meta_cap.
			'delete_evento_servicio',
			'delete_post',
		);

		if ( ! in_array( $cap_solicitada, $caps_de_edicion, true ) ) {
			return $allcaps;
		}

		$post_id = (int) $args[2];
		$post    = get_post( $post_id );

		// Solo aplica a posts del tipo evento_servicio.
		if ( ! $post || CalibraTrack_CPT_EventoServicio::SLUG !== $post->post_type ) {
			return $allcaps;
		}

		$user_id = (int) $user->ID;

		// Los administradores (manage_options) no tienen restricción de autoría.
		if ( ! empty( $allcaps['manage_options'] ) ) {
			return $allcaps;
		}

		// Si el usuario NO es el autor del evento, revocar la capability de edición.
		if ( (int) $post->post_author !== $user_id ) {
			// Revocar caps de edición de evento específico.
			foreach ( $caps as $cap ) {
				$allcaps[ $cap ] = false;
			}
		}

		return $allcaps;
	}

	// ─── COLUMNAS EN LIST TABLE ──────────────────────────────────────────────────

	/**
	 * Agrega columnas personalizadas a la lista de equipos en el admin.
	 *
	 * @param array $columns Columnas existentes.
	 * @return array
	 */
	public static function equipo_columns( $columns ) {
		$new_columns = array();

		// Preservar checkbox y title al inicio.
		if ( isset( $columns['cb'] ) ) {
			$new_columns['cb'] = $columns['cb'];
		}
		if ( isset( $columns['title'] ) ) {
			$new_columns['title'] = $columns['title'];
		}

		// Columnas custom.
		$new_columns['calibratrack_serie']   = __( 'Serie', 'calibratrack' );
		$new_columns['calibratrack_marca']   = __( 'Marca', 'calibratrack' );
		$new_columns['calibratrack_modelo']  = __( 'Modelo', 'calibratrack' );
		$new_columns['calibratrack_estado']  = __( 'Estado', 'calibratrack' );

		// Preservar fecha al final.
		if ( isset( $columns['date'] ) ) {
			$new_columns['date'] = $columns['date'];
		}

		return $new_columns;
	}

	/**
	 * Renderiza el contenido de las columnas personalizadas de equipos.
	 *
	 * @param string $column  Slug de la columna.
	 * @param int    $post_id ID del post.
	 * @return void
	 */
	public static function equipo_column_content( $column, $post_id ) {
		switch ( $column ) {
			case 'calibratrack_serie':
				echo esc_html( (string) get_post_meta( $post_id, CalibraTrack_Meta_Keys::EQUIPO_SERIE, true ) );
				break;

			case 'calibratrack_marca':
				echo esc_html( (string) get_post_meta( $post_id, CalibraTrack_Meta_Keys::EQUIPO_MARCA, true ) );
				break;

			case 'calibratrack_modelo':
				echo esc_html( (string) get_post_meta( $post_id, CalibraTrack_Meta_Keys::EQUIPO_MODELO, true ) );
				break;

			case 'calibratrack_estado':
				// Usar caché de transient (D-12, D-17).
				$transient_key = 'calibratrack_vigencia_' . $post_id;
				$estado        = get_transient( $transient_key );

				if ( false === $estado ) {
					$ultimo_evento = CalibraTrack_DB::get_ultimo_evento( $post_id );
					$proxima       = $ultimo_evento ? (string) $ultimo_evento->proxima_fecha_control : '';
					$estado        = CalibraTrack_Helpers::calcular_estado_vigencia( $proxima );
					set_transient( $transient_key, $estado, HOUR_IN_SECONDS );
				}

				$etiquetas = CalibraTrack_Helpers::get_estados_equipo();
				$etiqueta  = isset( $etiquetas[ $estado ] ) ? $etiquetas[ $estado ] : $estado;

				// Clases CSS para distinguir visualmente los estados.
				$clases = array(
					'vigente'    => 'calibratrack-estado-vigente',
					'por_vencer' => 'calibratrack-estado-por-vencer',
					'vencido'    => 'calibratrack-estado-vencido',
					'sin_evento' => 'calibratrack-estado-sin-evento',
				);
				$clase = isset( $clases[ $estado ] ) ? $clases[ $estado ] : '';

				echo '<span class="' . esc_attr( $clase ) . '">' . esc_html( $etiqueta ) . '</span>';
				break;
		}
	}

	// ─── AVISOS ADMIN ────────────────────────────────────────────────────────────

	/**
	 * Guarda un aviso de error/éxito para mostrarlo en la siguiente carga de página.
	 * Se almacena en un transient de corta vida (60s) para sobrevivir la redirección.
	 *
	 * @param string $tipo    'error' o 'success'.
	 * @param string $mensaje Mensaje a mostrar (sin HTML de usuario).
	 * @param int    $post_id ID del post relacionado (para namespace del transient).
	 * @return void
	 */
	private static function set_admin_notice( $tipo, $mensaje, $post_id ) {
		$user_id = get_current_user_id();
		set_transient( 'calibratrack_notice_' . $user_id . '_' . $post_id, array( 'tipo' => $tipo, 'mensaje' => $mensaje ), 60 );
	}

	/**
	 * Muestra avisos de validación guardados para el usuario actual.
	 *
	 * @return void
	 */
	public static function show_admin_notices() {
		$user_id = get_current_user_id();
		$screen  = get_current_screen();

		if ( ! $screen ) {
			return;
		}

		// Solo mostrar en páginas de edición de los CPTs del plugin.
		$cpts_validos = array(
			CalibraTrack_CPT_Equipo::SLUG,
			CalibraTrack_CPT_Cliente::SLUG,
			CalibraTrack_CPT_EventoServicio::SLUG,
		);

		if ( ! in_array( $screen->post_type, $cpts_validos, true ) ) {
			return;
		}

		global $post;
		if ( ! $post ) {
			return;
		}

		$transient_key = 'calibratrack_notice_' . $user_id . '_' . $post->ID;
		$notice        = get_transient( $transient_key );

		if ( ! $notice || ! is_array( $notice ) ) {
			return;
		}

		delete_transient( $transient_key );

		$tipo    = ( 'success' === $notice['tipo'] ) ? 'updated' : 'notice-error';
		$mensaje = isset( $notice['mensaje'] ) ? $notice['mensaje'] : '';

		if ( $mensaje ) {
			echo '<div class="notice ' . esc_attr( $tipo ) . ' is-dismissible"><p>' . esc_html( $mensaje ) . '</p></div>';
		}
	}

	// ─── PERFIL DEL TÉCNICO ──────────────────────────────────────────────────────

	/**
	 * Agrega enctype="multipart/form-data" al formulario de perfil de wp-admin.
	 * Sin esto, $_FILES nunca se puebla y la subida de firma no funciona.
	 * El filtro user_edit_form_tag está disponible desde WordPress 5.5.
	 *
	 * @return string Atributo enctype.
	 */
	public static function add_enctype_to_profile_form() {
		return 'enctype="multipart/form-data"';
	}

	/**
	 * Renderiza los campos de CalibraTrack en el perfil de usuario de wp-admin.
	 *
	 * @param WP_User $user Usuario que se está visualizando.
	 * @return void
	 */
	public static function render_tecnico_profile_fields( $user ) {
		if ( ! current_user_can( 'manage_options' ) && $user->ID !== get_current_user_id() ) {
			return;
		}
		$cargo    = (string) get_user_meta( $user->ID, 'calibratrack_cargo', true );
		$firma_id = (int) get_user_meta( $user->ID, 'calibratrack_firma_id', true );
		$firma_url = $firma_id ? wp_get_attachment_image_url( $firma_id, 'thumbnail' ) : '';
		?>
		<h2><?php esc_html_e( 'CalibraTrack', 'calibratrack' ); ?></h2>
		<table class="form-table" role="presentation">
			<tr>
				<th><label for="calibratrack_cargo"><?php esc_html_e( 'Cargo / título profesional', 'calibratrack' ); ?></label></th>
				<td>
					<input type="text" id="calibratrack_cargo" name="calibratrack_cargo"
						value="<?php echo esc_attr( $cargo ); ?>" class="regular-text">
					<p class="description"><?php esc_html_e( 'Aparece debajo del nombre en los certificados PDF.', 'calibratrack' ); ?></p>
				</td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'Firma digital', 'calibratrack' ); ?></th>
				<td>
					<?php if ( $firma_url ) : ?>
						<img src="<?php echo esc_url( $firma_url ); ?>" alt="<?php esc_attr_e( 'Firma', 'calibratrack' ); ?>"
							style="max-height:70px;border:1px solid #ddd;padding:4px;background:#fff;margin-bottom:8px;display:block;">
					<?php endif; ?>
					<input type="file" name="calibratrack_firma_upload" accept="image/jpeg,image/png,image/webp">
					<p class="description"><?php esc_html_e( 'PNG con fondo transparente recomendado. Máx. 1 MB. Sube una nueva imagen para reemplazar la actual.', 'calibratrack' ); ?></p>
					<?php wp_nonce_field( 'calibratrack_tecnico_profile_' . $user->ID, 'calibratrack_profile_nonce' ); ?>
				</td>
			</tr>
		</table>
		<?php
	}

	/**
	 * Guarda los campos de CalibraTrack al actualizar el perfil de usuario.
	 *
	 * @param int $user_id ID del usuario.
	 * @return void
	 */
	public static function save_tecnico_profile_fields( $user_id ) {
		if ( ! isset( $_POST['calibratrack_profile_nonce'] )
			|| ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['calibratrack_profile_nonce'] ) ), 'calibratrack_tecnico_profile_' . $user_id )
		) {
			return;
		}

		if ( ! current_user_can( 'edit_user', $user_id ) ) {
			return;
		}

		// Cargo.
		$cargo = sanitize_text_field( isset( $_POST['calibratrack_cargo'] ) ? wp_unslash( $_POST['calibratrack_cargo'] ) : '' );
		update_user_meta( $user_id, 'calibratrack_cargo', $cargo );

		// Firma.
		if ( ! empty( $_FILES['calibratrack_firma_upload']['name'] ) ) {
			if ( ! function_exists( 'wp_handle_upload' ) ) {
				require_once ABSPATH . 'wp-admin/includes/file.php';
			}
			if ( ! function_exists( 'wp_insert_attachment' ) ) {
				require_once ABSPATH . 'wp-admin/includes/media.php';
			}
			if ( ! function_exists( 'wp_generate_attachment_metadata' ) ) {
				require_once ABSPATH . 'wp-admin/includes/image.php';
			}

			if ( $_FILES['calibratrack_firma_upload']['size'] <= 1048576 ) {
				$overrides = array(
					'test_form' => false,
					'mimes'     => array(
						'jpg|jpeg' => 'image/jpeg',
						'png'      => 'image/png',
						'webp'     => 'image/webp',
					),
				);
				$upload = wp_handle_upload( $_FILES['calibratrack_firma_upload'], $overrides );
				if ( isset( $upload['file'] ) ) {
					$att_id = wp_insert_attachment( array(
						'post_title'     => 'firma-tecnico-' . $user_id,
						'post_mime_type' => $upload['type'],
						'post_status'    => 'inherit',
					), $upload['file'] );

					if ( ! is_wp_error( $att_id ) && $att_id ) {
						$meta = wp_generate_attachment_metadata( $att_id, $upload['file'] );
						wp_update_attachment_metadata( $att_id, $meta );

						$firma_anterior = (int) get_user_meta( $user_id, 'calibratrack_firma_id', true );
						if ( $firma_anterior > 0 && $firma_anterior !== $att_id ) {
							wp_delete_attachment( $firma_anterior, true );
						}
						update_user_meta( $user_id, 'calibratrack_firma_id', $att_id );
					}
				}
			}
		}
	}

	// ─── CONFIGURACIÓN DEL PLUGIN ────────────────────────────────────────────────

	/**
	 * Registra las opciones del plugin con la Settings API de WordPress.
	 *
	 * @return void
	 */
	public static function register_settings() {
		$opciones = array(
			'calibratrack_empresa_nombre'     => 'sanitize_text_field',
			'calibratrack_empresa_rut'        => 'sanitize_text_field',
			'calibratrack_empresa_direccion'  => 'sanitize_text_field',
			'calibratrack_empresa_telefono'   => 'sanitize_text_field',
			'calibratrack_pdf_color_primario' => 'sanitize_hex_color',
			'calibratrack_email_enabled'      => 'rest_sanitize_boolean',
			'calibratrack_email_from'         => 'sanitize_email',
			'calibratrack_email_cc_tecnico'   => 'rest_sanitize_boolean',
		);

		foreach ( $opciones as $opcion => $sanitize_cb ) {
			register_setting( 'calibratrack_settings', $opcion, array( 'sanitize_callback' => $sanitize_cb ) );
		}

		// Logo: callback especial que procesa el archivo subido durante el flujo de options.php.
		register_setting( 'calibratrack_settings', 'calibratrack_logo_id', array(
			'sanitize_callback' => array( __CLASS__, 'sanitize_logo_upload' ),
		) );

		// SMTP en su propio grupo para que el formulario principal no borre estos valores.
		$opciones_smtp = array(
			'calibratrack_smtp_enabled'    => 'rest_sanitize_boolean',
			'calibratrack_smtp_host'       => 'sanitize_text_field',
			'calibratrack_smtp_port'       => 'absint',
			'calibratrack_smtp_user'       => 'sanitize_text_field',
			'calibratrack_smtp_pass'       => 'sanitize_text_field',
			'calibratrack_smtp_encryption' => 'sanitize_text_field',
		);
		foreach ( $opciones_smtp as $opcion => $sanitize_cb ) {
			register_setting( 'calibratrack_smtp_settings', $opcion, array( 'sanitize_callback' => $sanitize_cb ) );
		}

		// Recordatorio de vencimiento al cliente.
		register_setting( 'calibratrack_settings', 'calibratrack_recordatorio_cliente_enabled', array(
			'sanitize_callback' => 'rest_sanitize_boolean',
		) );
		register_setting( 'calibratrack_settings', 'calibratrack_dias_recordatorio_cliente', array(
			'sanitize_callback' => 'absint',
		) );
	}

	/**
	 * Sanitize callback para calibratrack_logo_id.
	 * Si se subió un archivo, lo procesa y devuelve el nuevo attachment ID.
	 * Si no hay archivo nuevo, devuelve el valor actual guardado.
	 *
	 * Se ejecuta dentro del flujo de options.php donde $_FILES sí está disponible.
	 *
	 * @param mixed $value Valor recibido desde el formulario (ignorado — la fuente es $_FILES).
	 * @return int Attachment ID del logo activo.
	 */
	public static function sanitize_logo_upload( $value ) {
		// Si no se subió ningún archivo, conservar el logo actual.
		if ( empty( $_FILES['calibratrack_logo_upload']['name'] ) ) {
			return (int) get_option( 'calibratrack_logo_id', 0 );
		}

		if ( ! function_exists( 'wp_handle_upload' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		if ( ! function_exists( 'wp_insert_attachment' ) ) {
			require_once ABSPATH . 'wp-admin/includes/media.php';
		}
		if ( ! function_exists( 'wp_generate_attachment_metadata' ) ) {
			require_once ABSPATH . 'wp-admin/includes/image.php';
		}

		$overrides = array(
			'test_form' => false,
			'mimes'     => array(
				'jpg|jpeg' => 'image/jpeg',
				'png'      => 'image/png',
				'webp'     => 'image/webp',
			),
		);

		$upload = wp_handle_upload( $_FILES['calibratrack_logo_upload'], $overrides ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput

		if ( isset( $upload['error'] ) || ! isset( $upload['file'] ) ) {
			// Upload fallido — conservar logo actual.
			add_settings_error(
				'calibratrack_logo_id',
				'logo_upload_error',
				isset( $upload['error'] ) ? $upload['error'] : __( 'Error al subir el logo.', 'calibratrack' ),
				'error'
			);
			return (int) get_option( 'calibratrack_logo_id', 0 );
		}

		$att_id = wp_insert_attachment(
			array(
				'post_title'     => 'logo-calibratrack',
				'post_mime_type' => $upload['type'],
				'post_status'    => 'inherit',
			),
			$upload['file']
		);

		if ( is_wp_error( $att_id ) || ! $att_id ) {
			return (int) get_option( 'calibratrack_logo_id', 0 );
		}

		$meta = wp_generate_attachment_metadata( $att_id, $upload['file'] );
		wp_update_attachment_metadata( $att_id, $meta );

		return (int) $att_id;
	}

	/**
	 * Renderiza la página de Configuración del plugin.
	 *
	 * @return void
	 */
	public static function render_settings_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Sin permisos.', 'calibratrack' ) );
		}

		$logo_id  = (int) get_option( 'calibratrack_logo_id', 0 );
		$logo_url = $logo_id ? wp_get_attachment_image_url( $logo_id, 'medium' ) : '';
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'CalibraTrack — Configuración', 'calibratrack' ); ?></h1>
			<form method="post" action="options.php" enctype="multipart/form-data">
				<?php settings_fields( 'calibratrack_settings' ); ?>

				<h2><?php esc_html_e( 'Datos de la empresa', 'calibratrack' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th><label for="ct-empresa-nombre"><?php esc_html_e( 'Nombre', 'calibratrack' ); ?></label></th>
						<td><input type="text" id="ct-empresa-nombre" name="calibratrack_empresa_nombre" class="regular-text"
							value="<?php echo esc_attr( get_option( 'calibratrack_empresa_nombre', 'TrueTech SpA' ) ); ?>"></td>
					</tr>
					<tr>
						<th><label for="ct-empresa-rut"><?php esc_html_e( 'RUT', 'calibratrack' ); ?></label></th>
						<td><input type="text" id="ct-empresa-rut" name="calibratrack_empresa_rut" class="regular-text"
							value="<?php echo esc_attr( get_option( 'calibratrack_empresa_rut', '' ) ); ?>"></td>
					</tr>
					<tr>
						<th><label for="ct-empresa-dir"><?php esc_html_e( 'Dirección', 'calibratrack' ); ?></label></th>
						<td><input type="text" id="ct-empresa-dir" name="calibratrack_empresa_direccion" class="regular-text"
							value="<?php echo esc_attr( get_option( 'calibratrack_empresa_direccion', '' ) ); ?>"></td>
					</tr>
					<tr>
						<th><label for="ct-empresa-tel"><?php esc_html_e( 'Teléfono', 'calibratrack' ); ?></label></th>
						<td><input type="text" id="ct-empresa-tel" name="calibratrack_empresa_telefono" class="regular-text"
							value="<?php echo esc_attr( get_option( 'calibratrack_empresa_telefono', '' ) ); ?>"></td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Logo', 'calibratrack' ); ?></th>
						<td>
							<?php if ( $logo_url ) : ?>
								<img src="<?php echo esc_url( $logo_url ); ?>" style="max-height:60px;display:block;margin-bottom:8px;">
							<?php endif; ?>
							<input type="file" name="calibratrack_logo_upload" accept="image/jpeg,image/png,image/webp">
							<p class="description"><?php esc_html_e( 'PNG recomendado. Sube una nueva para reemplazar la actual.', 'calibratrack' ); ?></p>
						</td>
					</tr>
					<tr>
						<th><label for="ct-color"><?php esc_html_e( 'Color primario (PDF)', 'calibratrack' ); ?></label></th>
						<td>
							<input type="color" id="ct-color" name="calibratrack_pdf_color_primario"
								value="<?php echo esc_attr( get_option( 'calibratrack_pdf_color_primario', '#00AEEF' ) ); ?>">
							<p class="description"><?php esc_html_e( 'Color de encabezado y bandas del certificado PDF.', 'calibratrack' ); ?></p>
						</td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'Correo electrónico', 'calibratrack' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th><?php esc_html_e( 'Envío automático', 'calibratrack' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="calibratrack_email_enabled" value="1"
									<?php checked( get_option( 'calibratrack_email_enabled', true ) ); ?>>
								<?php esc_html_e( 'Enviar correo al cliente cuando se crea un nuevo evento', 'calibratrack' ); ?>
							</label>
						</td>
					</tr>
					<tr>
						<th><label for="ct-email-from"><?php esc_html_e( 'Correo remitente', 'calibratrack' ); ?></label></th>
						<td>
							<input type="email" id="ct-email-from" name="calibratrack_email_from" class="regular-text"
								value="<?php echo esc_attr( get_option( 'calibratrack_email_from', get_option( 'admin_email' ) ) ); ?>">
							<p class="description"><?php esc_html_e( 'Correo desde el que se envían los mensajes. Debe ser válido para evitar que caigan en spam.', 'calibratrack' ); ?></p>
						</td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Copia al técnico', 'calibratrack' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="calibratrack_email_cc_tecnico" value="1"
									<?php checked( get_option( 'calibratrack_email_cc_tecnico', false ) ); ?>>
								<?php esc_html_e( 'Enviar copia (CC) al técnico responsable del evento', 'calibratrack' ); ?>
							</label>
						</td>
					</tr>
					<tr>
						<th>
							<label for="ct-recordatorio-cliente-enabled">
								<?php esc_html_e( 'Recordatorio de vencimiento al cliente', 'calibratrack' ); ?>
							</label>
						</th>
						<td>
							<label>
								<input type="checkbox" id="ct-recordatorio-cliente-enabled"
									name="calibratrack_recordatorio_cliente_enabled" value="1"
									<?php checked( (bool) get_option( 'calibratrack_recordatorio_cliente_enabled', true ) ); ?>>
								<?php esc_html_e( 'Enviar correo automático al cliente antes del vencimiento', 'calibratrack' ); ?>
							</label>
						</td>
					</tr>
					<tr>
						<th>
							<label for="ct-dias-recordatorio">
								<?php esc_html_e( 'Días de anticipación del recordatorio', 'calibratrack' ); ?>
							</label>
						</th>
						<td>
							<input type="number" id="ct-dias-recordatorio"
								name="calibratrack_dias_recordatorio_cliente"
								value="<?php echo esc_attr( (int) get_option( 'calibratrack_dias_recordatorio_cliente', 30 ) ); ?>"
								min="1" max="365" step="1" class="small-text">
							<p class="description">
								<?php esc_html_e( 'Días antes del vencimiento para enviar el recordatorio al cliente. Por defecto: 30.', 'calibratrack' ); ?>
							</p>
						</td>
					</tr>
				</table>

				<?php submit_button( __( 'Guardar configuración', 'calibratrack' ) ); ?>
			</form>

			<hr>

			<h2><?php esc_html_e( 'SMTP (correo saliente)', 'calibratrack' ); ?></h2>
			<p class="description"><?php esc_html_e( 'Para enviar correos desde entornos de hosting o Docker sin sendmail. Para Gmail usa smtp.gmail.com, puerto 587, cifrado TLS y una Contraseña de Aplicación de Google.', 'calibratrack' ); ?></p>
			<form method="post" action="options.php">
				<?php settings_fields( 'calibratrack_smtp_settings' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th><?php esc_html_e( 'Habilitar SMTP', 'calibratrack' ); ?></th>
						<td>
							<label>
								<input type="checkbox" id="ct-smtp-enabled" name="calibratrack_smtp_enabled" value="1"
									<?php checked( get_option( 'calibratrack_smtp_enabled', false ) ); ?>>
								<?php esc_html_e( 'Usar servidor SMTP personalizado', 'calibratrack' ); ?>
							</label>
						</td>
					</tr>
					<tr>
						<th><label for="ct-smtp-host"><?php esc_html_e( 'Servidor SMTP', 'calibratrack' ); ?></label></th>
						<td>
							<input type="text" id="ct-smtp-host" name="calibratrack_smtp_host" class="regular-text"
								value="<?php echo esc_attr( get_option( 'calibratrack_smtp_host', 'smtp.gmail.com' ) ); ?>"
								placeholder="smtp.gmail.com">
						</td>
					</tr>
					<tr>
						<th><label for="ct-smtp-port"><?php esc_html_e( 'Puerto', 'calibratrack' ); ?></label></th>
						<td>
							<input type="number" id="ct-smtp-port" name="calibratrack_smtp_port" class="small-text"
								value="<?php echo esc_attr( get_option( 'calibratrack_smtp_port', 587 ) ); ?>"
								min="1" max="65535">
						</td>
					</tr>
					<tr>
						<th><label for="ct-smtp-encryption"><?php esc_html_e( 'Cifrado', 'calibratrack' ); ?></label></th>
						<td>
							<select id="ct-smtp-encryption" name="calibratrack_smtp_encryption">
								<option value="tls" <?php selected( get_option( 'calibratrack_smtp_encryption', 'tls' ), 'tls' ); ?>>TLS (STARTTLS — puerto 587)</option>
								<option value="ssl" <?php selected( get_option( 'calibratrack_smtp_encryption', 'tls' ), 'ssl' ); ?>>SSL (SMTPS — puerto 465)</option>
								<option value="" <?php selected( get_option( 'calibratrack_smtp_encryption', 'tls' ), '' ); ?>><?php esc_html_e( 'Ninguno', 'calibratrack' ); ?></option>
							</select>
						</td>
					</tr>
					<tr>
						<th><label for="ct-smtp-user"><?php esc_html_e( 'Usuario (correo)', 'calibratrack' ); ?></label></th>
						<td>
							<input type="email" id="ct-smtp-user" name="calibratrack_smtp_user" class="regular-text"
								value="<?php echo esc_attr( get_option( 'calibratrack_smtp_user', '' ) ); ?>"
								placeholder="tucuenta@gmail.com">
						</td>
					</tr>
					<tr>
						<th><label for="ct-smtp-pass"><?php esc_html_e( 'Contraseña / App Password', 'calibratrack' ); ?></label></th>
						<td>
							<input type="password" id="ct-smtp-pass" name="calibratrack_smtp_pass" class="regular-text"
								value="<?php echo esc_attr( get_option( 'calibratrack_smtp_pass', '' ) ); ?>"
								autocomplete="new-password">
							<p class="description"><?php esc_html_e( 'Para Gmail: genera una Contraseña de Aplicación en myaccount.google.com → Seguridad → Verificación en dos pasos → Contraseñas de aplicaciones.', 'calibratrack' ); ?></p>
						</td>
					</tr>
				</table>
				<p class="submit">
					<button type="button" id="ct-btn-guardar-smtp" class="button button-secondary">
						<?php esc_html_e( 'Guardar SMTP', 'calibratrack' ); ?>
					</button>
					<span id="ct-smtp-save-result" style="margin-left:8px;font-weight:500;"></span>
				</p>
			</form>
			<script>
			(function(){
				var btn = document.getElementById('ct-btn-guardar-smtp');
				if (!btn) return;
				btn.addEventListener('click', function(){
					var form = document.querySelectorAll('form[action="options.php"]')[1];
					var result = document.getElementById('ct-smtp-save-result');
					btn.disabled = true;
					result.style.color = '#666';
					result.textContent = '<?php esc_js( __( 'Guardando…', 'calibratrack' ) ); ?>';
					var data = new FormData(form);
					fetch('<?php echo esc_js( admin_url( 'options.php' ) ); ?>', { method: 'POST', body: data })
						.then(function(r){ return r.text(); })
						.then(function(){
							btn.disabled = false;
							result.style.color = '#0a6b0a';
							result.textContent = '<?php echo esc_js( __( '✓ Configuración SMTP guardada.', 'calibratrack' ) ); ?>';
						})
						.catch(function(){
							btn.disabled = false;
							result.style.color = '#c00';
							result.textContent = '<?php echo esc_js( __( 'Error al guardar.', 'calibratrack' ) ); ?>';
						});
				});
			})();
			</script>

			<hr>
			<h3><?php esc_html_e( 'Enviar correo de prueba', 'calibratrack' ); ?></h3>
			<p class="description"><?php esc_html_e( 'Verifica que el envío funciona antes de crear un evento real.', 'calibratrack' ); ?></p>
			<table class="form-table" role="presentation">
				<tr>
					<th><label for="ct-test-email-to"><?php esc_html_e( 'Destinatario', 'calibratrack' ); ?></label></th>
					<td>
						<input type="email" id="ct-test-email-to" class="regular-text"
							value="<?php echo esc_attr( get_option( 'admin_email' ) ); ?>"
							placeholder="destino@ejemplo.com">
						<button type="button" id="ct-btn-test-email" class="button button-secondary" style="margin-left:8px;">
							<?php esc_html_e( 'Enviar correo de prueba', 'calibratrack' ); ?>
						</button>
						<span id="ct-test-email-result" style="margin-left:8px;font-weight:500;"></span>
					</td>
				</tr>
			</table>
			<script>
			(function(){
				var btn = document.getElementById('ct-btn-test-email');
				if (!btn) return;
				btn.addEventListener('click', function(){
					var to = document.getElementById('ct-test-email-to').value.trim();
					var result = document.getElementById('ct-test-email-result');
					if (!to) { result.style.color='#c00'; result.textContent='Ingrese un correo destino.'; return; }
					btn.disabled = true;
					result.style.color='#666';
					result.textContent='Enviando…';
					var data = new FormData();
					data.append('action', 'calibratrack_test_email');
					data.append('to', to);
					data.append('_nonce', '<?php echo esc_js( wp_create_nonce( 'calibratrack_test_email' ) ); ?>');
					fetch('<?php echo esc_js( admin_url( 'admin-ajax.php' ) ); ?>', { method:'POST', body: data })
						.then(function(r){ return r.json(); })
						.then(function(res){
							btn.disabled = false;
							if (res.success) {
								result.style.color = '#0a6b0a';
								result.textContent = res.data.message;
							} else {
								result.style.color = '#c00';
								result.textContent = (res.data && res.data.message) ? res.data.message : 'Error desconocido.';
							}
						})
						.catch(function(){
							btn.disabled = false;
							result.style.color = '#c00';
							result.textContent = 'Error de red.';
						});
				});
			})();
			</script>
		</div>
		<?php
	}

	// ─── AJAX: BÚSQUEDA DE PRODUCTOS WOOCOMMERCE ─────────────────────────────────

	/**
	 * Endpoint AJAX para buscar productos de WooCommerce por nombre.
	 * Solo accesible para administradores.
	 * Responde JSON: array de { id, name, price }.
	 *
	 * @return void
	 */
	/**
	 * AJAX: busca productos WooCommerce y retorna datos para pre-llenar el formulario
	 * de nuevo equipo (marca, modelo, tipo_sugerido).
	 *
	 * Nonce: calibratrack_buscar_equipo_wc
	 *
	 * Mapeo:
	 *  - Atributo "marca" / "brand"         → marca
	 *  - Atributo "modelo" / "model" o SKU  → modelo
	 *  - Categoría del producto              → tipo_sugerido (coincidencia aproximada)
	 *
	 * @return void  Responde con wp_send_json_success/error y termina.
	 */
	public static function ajax_buscar_equipo_wc() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Sin permisos.' ), 403 );
		}

		check_ajax_referer( 'calibratrack_buscar_equipo_wc', 'nonce' );

		if ( ! function_exists( 'wc_get_products' ) ) {
			wp_send_json_error( array( 'message' => 'WooCommerce no disponible.' ), 503 );
		}

		$term = isset( $_GET['term'] ) ? sanitize_text_field( wp_unslash( $_GET['term'] ) ) : '';

		// wc_get_products() con 's' solo busca por nombre/título, no por SKU.
		// Buscamos en paralelo:
		//   1. Por nombre (s)
		//   2. Por SKU exacto
		//   3. Por SKU parcial (LIKE) vía $wpdb
		// Luego deduplicamos por ID.

		global $wpdb;

		$ids_encontrados = array();

		if ( ! empty( $term ) ) {
			// 1. Por nombre.
			$por_nombre = wc_get_products( array(
				'status'  => 'publish',
				'limit'   => 20,
				'orderby' => 'title',
				'order'   => 'ASC',
				's'       => $term,
				'return'  => 'ids',
			) );
			if ( is_array( $por_nombre ) ) {
				$ids_encontrados = array_merge( $ids_encontrados, $por_nombre );
			}

			// 2. Por SKU exacto.
			$por_sku_exacto = wc_get_products( array(
				'status' => 'publish',
				'limit'  => 20,
				'sku'    => $term,
				'return' => 'ids',
			) );
			if ( is_array( $por_sku_exacto ) ) {
				$ids_encontrados = array_merge( $ids_encontrados, $por_sku_exacto );
			}

			// 3. Por SKU parcial (LIKE) — útil para fragmentos como "FHO5000".
			$like = '%' . $wpdb->esc_like( $term ) . '%';
			$por_sku_parcial = $wpdb->get_col( $wpdb->prepare(
				"SELECT DISTINCT post_id FROM {$wpdb->postmeta}
				 WHERE meta_key = '_sku'
				   AND meta_value LIKE %s
				 LIMIT 20",
				$like
			) );
			if ( is_array( $por_sku_parcial ) ) {
				$ids_encontrados = array_merge( $ids_encontrados, array_map( 'intval', $por_sku_parcial ) );
			}

			// Deduplicar y filtrar solo publicados.
			$ids_encontrados = array_unique( array_filter( array_map( 'intval', $ids_encontrados ) ) );

			if ( empty( $ids_encontrados ) ) {
				wp_send_json_success( array() );
			}

			$productos = array_map( 'wc_get_product', $ids_encontrados );
			$productos = array_filter( $productos, static function( $p ) {
				return $p && 'publish' === $p->get_status();
			} );
		} else {
			$productos = wc_get_products( array(
				'status'  => 'publish',
				'limit'   => 20,
				'orderby' => 'title',
				'order'   => 'ASC',
			) );
		}

		// Mapa categoría WC → slug tipo_equipo de CalibraTrack.
		$mapa_tipos = array(
			'otdr'          => 'otdr',
			'power meter'   => 'power_meter',
			'power_meter'   => 'power_meter',
			'medidor'       => 'power_meter',
			'fuente de luz' => 'fuente_luz',
			'fuente_luz'    => 'fuente_luz',
			'fuente luz'    => 'fuente_luz',
			'empalmadora'   => 'empalmadora',
			'fusion'        => 'empalmadora',
			'fusión'        => 'empalmadora',
			'certificador'  => 'certificador_red',
			'certificadora' => 'certificador_red',
		);

		$resultado = array();

		foreach ( $productos as $producto ) {
			$marca  = '';
			$modelo = '';

			// Extraer marca y modelo desde atributos del producto.
			$atributos = $producto->get_attributes();
			foreach ( $atributos as $attr_key => $attr ) {
				$attr_name = strtolower( str_replace( '-', ' ', $attr_key ) );
				// El atributo puede ser un objeto WC_Product_Attribute o un array.
				if ( is_object( $attr ) && method_exists( $attr, 'get_options' ) ) {
					$valores = $attr->get_options();
					$valor   = is_array( $valores ) && ! empty( $valores ) ? (string) reset( $valores ) : '';
					// Si el valor es un term_id, obtener el nombre.
					if ( is_numeric( $valor ) ) {
						$term_obj = get_term( (int) $valor );
						if ( $term_obj && ! is_wp_error( $term_obj ) ) {
							$valor = $term_obj->name;
						}
					}
				} else {
					$valor = '';
				}
				if ( false !== strpos( $attr_name, 'marca' ) || false !== strpos( $attr_name, 'brand' ) ) {
					$marca = $valor;
				}
				if ( false !== strpos( $attr_name, 'modelo' ) || false !== strpos( $attr_name, 'model' ) ) {
					$modelo = $valor;
				}
			}

			// Si no hay atributo de modelo, usar SKU.
			if ( empty( $modelo ) ) {
				$modelo = $producto->get_sku();
			}

			// Determinar tipo_sugerido desde la primera categoría del producto.
			$tipo_sugerido = '';
			$term_ids = $producto->get_category_ids();
			if ( ! empty( $term_ids ) ) {
				$cat_term = get_term( (int) reset( $term_ids ), 'product_cat' );
				if ( $cat_term && ! is_wp_error( $cat_term ) ) {
					$cat_name_lower = strtolower( $cat_term->name );
					foreach ( $mapa_tipos as $buscar => $slug ) {
						if ( false !== strpos( $cat_name_lower, $buscar ) ) {
							$tipo_sugerido = $slug;
							break;
						}
					}
					// También intentar con el slug de la categoría.
					if ( empty( $tipo_sugerido ) ) {
						$cat_slug_lower = strtolower( str_replace( '-', ' ', $cat_term->slug ) );
						foreach ( $mapa_tipos as $buscar => $slug ) {
							if ( false !== strpos( $cat_slug_lower, $buscar ) ) {
								$tipo_sugerido = $slug;
								break;
							}
						}
					}
				}
			}

			$resultado[] = array(
				'id'            => $producto->get_id(),
				'name'          => $producto->get_name(),
				'sku'           => $producto->get_sku(),
				'marca'         => $marca,
				'modelo'        => $modelo,
				'tipo_sugerido' => $tipo_sugerido,
			);
		}

		wp_send_json_success( $resultado );
	}

	public static function ajax_buscar_productos_wc() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Sin permisos.' ), 403 );
		}

		check_ajax_referer( 'calibratrack_buscar_wc', 'nonce' );

		if ( ! function_exists( 'wc_get_products' ) ) {
			wp_send_json_error( array( 'message' => 'WooCommerce no disponible.' ), 503 );
		}

		$term = isset( $_GET['term'] ) ? sanitize_text_field( wp_unslash( $_GET['term'] ) ) : '';

		$args = array(
			'status'  => 'publish',
			'limit'   => 20,
			'orderby' => 'title',
			'order'   => 'ASC',
		);
		if ( ! empty( $term ) ) {
			$args['s'] = $term;
		}

		$productos = wc_get_products( $args );
		$resultado = array();
		foreach ( $productos as $producto ) {
			$resultado[] = array(
				'id'    => $producto->get_id(),
				'name'  => $producto->get_name(),
				'price' => (float) $producto->get_price(),
			);
		}

		wp_send_json_success( $resultado );
	}

	// ─────────────────────────────────────────────────────────────────────────────
	// LIQUIDACIÓN TÉCNICOS
	// ─────────────────────────────────────────────────────────────────────────────

	/**
	 * AJAX: Guarda el estado de pago y/o número de factura de una OT en la liquidación.
	 * Acción: calibratrack_guardar_pago_ot (solo admins).
	 *
	 * @return void (JSON + exit)
	 */
	public static function ajax_guardar_pago_ot() {
		check_ajax_referer( 'calibratrack_pago_ot', '_nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Sin permisos.', 'calibratrack' ) ) );
		}

		$ot_id          = isset( $_POST['ot_id'] ) ? absint( $_POST['ot_id'] ) : 0;
		$estado_pago    = isset( $_POST['estado_pago'] ) ? sanitize_key( wp_unslash( $_POST['estado_pago'] ) ) : '';
		$factura_numero = isset( $_POST['factura_numero'] ) ? sanitize_text_field( wp_unslash( $_POST['factura_numero'] ) ) : '';

		if ( $ot_id <= 0 || 'evento_servicio' !== get_post_type( $ot_id ) ) {
			wp_send_json_error( array( 'message' => __( 'OT inválida.', 'calibratrack' ) ) );
		}

		$estados_validos = array( 'pendiente', 'pagado' );
		if ( ! in_array( $estado_pago, $estados_validos, true ) ) {
			$estado_pago = 'pendiente';
		}

		update_post_meta( $ot_id, 'calibratrack_pago_estado', $estado_pago );
		update_post_meta( $ot_id, 'calibratrack_factura_numero', $factura_numero );

		wp_send_json_success( array(
			'message'        => __( 'Guardado correctamente.', 'calibratrack' ),
			'estado_pago'    => $estado_pago,
			'factura_numero' => $factura_numero,
		) );
	}

	/**
	 * Renderiza la página de Liquidación por Técnico.
	 * Permite filtrar OTs completadas por técnico y rango de fechas para
	 * calcular el total de trabajo a liquidar a fin de mes.
	 *
	 * Solo accesible para administradores (manage_options).
	 *
	 * @return void
	 */
	public static function render_liquidacion_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Sin permisos.', 'calibratrack' ) );
		}

		// ── Filtros ────────────────────────────────────────────────────────────
		$hoy       = gmdate( 'Y-m-d' );
		$mes_ini   = gmdate( 'Y-m-01' );   // Primer día del mes actual.
		$mes_fin   = gmdate( 'Y-m-t' );    // Último día del mes actual.

		$filtro_tec  = isset( $_GET['tecnico_id'] ) ? absint( $_GET['tecnico_id'] ) : 0;
		$filtro_desde = isset( $_GET['desde'] ) && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $_GET['desde'] )
			? sanitize_text_field( wp_unslash( $_GET['desde'] ) )
			: $mes_ini;
		$filtro_hasta = isset( $_GET['hasta'] ) && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $_GET['hasta'] )
			? sanitize_text_field( wp_unslash( $_GET['hasta'] ) )
			: $mes_fin;

		// Asegurar que desde <= hasta.
		if ( $filtro_desde > $filtro_hasta ) {
			$filtro_hasta = $filtro_desde;
		}

		// ── Lista de técnicos ─────────────────────────────────────────────────
		$tecnicos = get_users( array(
			'role__in' => array( 'tecnico_calibracion', 'administrator' ),
			'orderby'  => 'display_name',
			'order'    => 'ASC',
		) );

		// ── Query de OTs completadas ───────────────────────────────────────────
		global $wpdb;

		$where_extra = '';
		$params      = array(
			'calibratrack_tipo_documento',
			'ot',
			'calibratrack_estado_servicio',
			'completado',
			'calibratrack_fecha_ejecucion',
			$filtro_desde,
			$filtro_hasta,
		);

		if ( $filtro_tec > 0 ) {
			$where_extra = " AND EXISTS (
				SELECT 1 FROM {$wpdb->postmeta} pm_tec
				WHERE pm_tec.post_id = p.ID
				  AND pm_tec.meta_key = 'calibratrack_tecnico_responsable'
				  AND pm_tec.meta_value = %s
			)";
			$params[] = (string) $filtro_tec;
		}

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$ots = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT p.ID, p.post_date
				 FROM {$wpdb->posts} p
				 WHERE p.post_type   = 'evento_servicio'
				   AND p.post_status = 'publish'
				   AND EXISTS (
					   SELECT 1 FROM {$wpdb->postmeta} pm1
					   WHERE pm1.post_id   = p.ID
					     AND pm1.meta_key  = %s
					     AND pm1.meta_value = %s
				   )
				   AND EXISTS (
					   SELECT 1 FROM {$wpdb->postmeta} pm2
					   WHERE pm2.post_id   = p.ID
					     AND pm2.meta_key  = %s
					     AND pm2.meta_value = %s
				   )
				   AND EXISTS (
					   SELECT 1 FROM {$wpdb->postmeta} pm3
					   WHERE pm3.post_id   = p.ID
					     AND pm3.meta_key  = %s
					     AND pm3.meta_value BETWEEN %s AND %s
				   )
				   {$where_extra}
				 ORDER BY p.post_date DESC",
				$params
			)
		);
		// phpcs:enable

		// Pre-cargar postmeta de todas las OTs en 1 sola query.
		$ot_ids = wp_list_pluck( $ots, 'ID' );
		if ( ! empty( $ot_ids ) ) {
			update_postmeta_cache( $ot_ids );
		}

		// Calcular totales y recopilar datos por OT + por técnico.
		$filas          = array();
		$gran_subtotal  = 0.0;
		$gran_iva       = 0.0;
		$gran_total     = 0.0;
		$totales_tec    = array(); // [ user_id => [ 'nombre' => ..., 'ots' => n, 'total' => n ] ]

		$tipos_map = CalibraTrack_Helpers::get_tipos_evento();

		foreach ( $ots as $ot ) {
			$ot_id      = (int) $ot->ID;
			$numero_ot  = (string) get_post_meta( $ot_id, CalibraTrack_Meta_Keys::EVENTO_NUMERO_OT, true );
			$equipo_id  = (int) get_post_meta( $ot_id, CalibraTrack_Meta_Keys::EVENTO_EQUIPO_ID, true );
			$tecnico_id = (int) get_post_meta( $ot_id, CalibraTrack_Meta_Keys::EVENTO_TECNICO_RESPONSABLE, true );
			$tipo       = (string) get_post_meta( $ot_id, CalibraTrack_Meta_Keys::EVENTO_TIPO, true );
			$fecha      = (string) get_post_meta( $ot_id, CalibraTrack_Meta_Keys::EVENTO_FECHA_EJECUCION, true );
			$subtotal   = (float) get_post_meta( $ot_id, 'calibratrack_subtotal', true );
			$iva        = (float) get_post_meta( $ot_id, 'calibratrack_iva', true );
			$total      = (float) get_post_meta( $ot_id, 'calibratrack_total', true );

			$serie = $equipo_id ? (string) get_post_meta( $equipo_id, CalibraTrack_Meta_Keys::EQUIPO_SERIE, true ) : '—';
			$marca = $equipo_id ? (string) get_post_meta( $equipo_id, CalibraTrack_Meta_Keys::EQUIPO_MARCA, true ) : '';
			$modelo= $equipo_id ? (string) get_post_meta( $equipo_id, CalibraTrack_Meta_Keys::EQUIPO_MODELO, true ) : '';

			$tecnico_user   = $tecnico_id ? get_userdata( $tecnico_id ) : null;
			$tecnico_nombre = $tecnico_user ? $tecnico_user->display_name : '—';

			// Formateamos la fecha para display.
			$fecha_dt  = $fecha ? DateTime::createFromFormat( 'Y-m-d', $fecha ) : null;
			$fecha_fmt = $fecha_dt ? $fecha_dt->format( 'd/m/Y' ) : '—';

			$filas[] = array(
				'ot_id'          => $ot_id,
				'numero_ot'      => $numero_ot,
				'tecnico_id'     => $tecnico_id,
				'tecnico_nombre' => $tecnico_nombre,
				'tipo_label'     => isset( $tipos_map[ $tipo ] ) ? $tipos_map[ $tipo ] : $tipo,
				'fecha_fmt'      => $fecha_fmt,
				'serie'          => $serie,
				'equipo'         => trim( $marca . ' ' . $modelo ),
				'subtotal'       => $subtotal,
				'iva'            => $iva,
				'total'          => $total,
				'estado_pago'    => (string) get_post_meta( $ot_id, 'calibratrack_pago_estado', true ) ?: 'pendiente',
				'factura_numero' => (string) get_post_meta( $ot_id, 'calibratrack_factura_numero', true ),
			);

			$gran_subtotal += $subtotal;
			$gran_iva      += $iva;
			$gran_total    += $total;

			if ( $tecnico_id > 0 ) {
				if ( ! isset( $totales_tec[ $tecnico_id ] ) ) {
					$totales_tec[ $tecnico_id ] = array(
						'nombre'   => $tecnico_nombre,
						'cantidad' => 0,
						'subtotal' => 0.0,
						'iva'      => 0.0,
						'total'    => 0.0,
					);
				}
				$totales_tec[ $tecnico_id ]['cantidad']++;
				$totales_tec[ $tecnico_id ]['subtotal'] += $subtotal;
				$totales_tec[ $tecnico_id ]['iva']      += $iva;
				$totales_tec[ $tecnico_id ]['total']    += $total;
			}
		}

		// ── Render HTML ────────────────────────────────────────────────────────
		$color = esc_attr( (string) get_option( 'calibratrack_pdf_color_primario', '#00AEEF' ) );
		?>
		<div class="wrap">
			<h1 style="display:flex;align-items:center;gap:10px;">
				<span class="dashicons dashicons-money-alt" style="font-size:28px;width:28px;height:28px;color:<?php echo $color; ?>;"></span>
				<?php esc_html_e( 'Liquidación Técnicos', 'calibratrack' ); ?>
			</h1>
			<p style="color:#666;margin-bottom:20px;">
				<?php esc_html_e( 'Resumen de OTs completadas por técnico en el período seleccionado. Útil para calcular pagos a fin de mes.', 'calibratrack' ); ?>
			</p>

			<?php /* ── BARRA DE FILTROS ── */ ?>
			<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>" style="display:flex;flex-wrap:wrap;gap:12px;align-items:flex-end;background:#fff;padding:16px 20px;border-radius:8px;box-shadow:0 1px 4px rgba(0,0,0,.08);margin-bottom:24px;">
				<input type="hidden" name="page" value="calibratrack-liquidacion">

				<div>
					<label style="display:block;font-size:12px;font-weight:600;color:#374151;margin-bottom:4px;"><?php esc_html_e( 'Técnico', 'calibratrack' ); ?></label>
					<select name="tecnico_id" style="min-width:200px;padding:6px 10px;border:1px solid #d1d5db;border-radius:6px;font-size:13px;">
						<option value="0"><?php esc_html_e( '— Todos los técnicos —', 'calibratrack' ); ?></option>
						<?php foreach ( $tecnicos as $tec ) : ?>
							<option value="<?php echo esc_attr( $tec->ID ); ?>" <?php selected( $filtro_tec, $tec->ID ); ?>>
								<?php echo esc_html( $tec->display_name ); ?>
							</option>
						<?php endforeach; ?>
					</select>
				</div>

				<div>
					<label style="display:block;font-size:12px;font-weight:600;color:#374151;margin-bottom:4px;"><?php esc_html_e( 'Desde', 'calibratrack' ); ?></label>
					<input type="date" name="desde" value="<?php echo esc_attr( $filtro_desde ); ?>"
						style="padding:6px 10px;border:1px solid #d1d5db;border-radius:6px;font-size:13px;">
				</div>

				<div>
					<label style="display:block;font-size:12px;font-weight:600;color:#374151;margin-bottom:4px;"><?php esc_html_e( 'Hasta', 'calibratrack' ); ?></label>
					<input type="date" name="hasta" value="<?php echo esc_attr( $filtro_hasta ); ?>"
						style="padding:6px 10px;border:1px solid #d1d5db;border-radius:6px;font-size:13px;">
				</div>

				<div style="display:flex;gap:8px;">
					<button type="submit" class="button button-primary"><?php esc_html_e( 'Filtrar', 'calibratrack' ); ?></button>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=calibratrack-liquidacion' ) ); ?>" class="button"><?php esc_html_e( 'Limpiar', 'calibratrack' ); ?></a>
					<?php if ( ! empty( $filas ) ) : ?>
					<button type="button" onclick="window.print();" class="button" style="margin-left:8px;">
						<span class="dashicons dashicons-printer" style="font-size:16px;width:16px;height:16px;vertical-align:middle;margin-right:4px;"></span>
						<?php esc_html_e( 'Imprimir', 'calibratrack' ); ?>
					</button>
					<?php endif; ?>
				</div>

				<?php /* Accesos rápidos por mes */ ?>
				<div style="width:100%;border-top:1px solid #f0f0f0;padding-top:10px;margin-top:4px;display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
					<span style="font-size:12px;color:#666;"><?php esc_html_e( 'Mes rápido:', 'calibratrack' ); ?></span>
					<?php
					for ( $i = 0; $i <= 5; $i++ ) {
						$ts      = strtotime( "-$i months" );
						$m_ini   = gmdate( 'Y-m-01', $ts );
						$m_fin   = gmdate( 'Y-m-t', $ts );
						$m_label = gmdate( 'M Y', $ts );
						$active  = ( $filtro_desde === $m_ini && $filtro_hasta === $m_fin );
						$q = add_query_arg( array(
							'page'       => 'calibratrack-liquidacion',
							'tecnico_id' => $filtro_tec,
							'desde'      => $m_ini,
							'hasta'      => $m_fin,
						), admin_url( 'admin.php' ) );
						echo '<a href="' . esc_url( $q ) . '" style="font-size:12px;padding:3px 10px;border-radius:4px;border:1px solid #d1d5db;text-decoration:none;color:' . ( $active ? '#fff' : '#374151' ) . ';background:' . ( $active ? $color : '#f9fafb' ) . ';">' . esc_html( $m_label ) . '</a>';
					}
					?>
				</div>
			</form>

			<?php if ( empty( $filas ) ) : ?>
			<div style="background:#fff;border-radius:8px;padding:40px;text-align:center;color:#6b7280;box-shadow:0 1px 4px rgba(0,0,0,.08);">
				<span class="dashicons dashicons-info" style="font-size:32px;width:32px;height:32px;color:#d1d5db;display:block;margin:0 auto 12px;"></span>
				<?php esc_html_e( 'No hay OTs completadas en el período seleccionado.', 'calibratrack' ); ?>
			</div>
			<?php else : ?>

			<?php /* ── RESUMEN POR TÉCNICO ── */ ?>
			<?php if ( count( $totales_tec ) > 1 || $filtro_tec === 0 ) : ?>
			<h2 style="font-size:14px;font-weight:700;color:#374151;margin:0 0 12px;"><?php esc_html_e( 'Resumen por técnico', 'calibratrack' ); ?></h2>
			<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:16px;margin-bottom:28px;">
				<?php foreach ( $totales_tec as $t_data ) : ?>
				<div style="background:#fff;border-radius:8px;padding:16px 20px;box-shadow:0 1px 4px rgba(0,0,0,.08);border-left:4px solid <?php echo $color; ?>;">
					<p style="margin:0 0 6px;font-weight:700;color:#111827;font-size:14px;"><?php echo esc_html( $t_data['nombre'] ); ?></p>
					<p style="margin:0 0 2px;font-size:13px;color:#6b7280;">
						<?php echo (int) $t_data['cantidad']; ?> <?php esc_html_e( 'OT(s) completada(s)', 'calibratrack' ); ?>
					</p>
					<p style="margin:8px 0 0;font-size:20px;font-weight:800;color:#111827;">
						$<?php echo esc_html( number_format( $t_data['total'], 0, ',', '.' ) ); ?>
					</p>
					<p style="margin:2px 0 0;font-size:11px;color:#9ca3af;">
						<?php esc_html_e( 'Subtotal', 'calibratrack' ); ?> $<?php echo esc_html( number_format( $t_data['subtotal'], 0, ',', '.' ) ); ?>
						+ <?php esc_html_e( 'IVA', 'calibratrack' ); ?> $<?php echo esc_html( number_format( $t_data['iva'], 0, ',', '.' ) ); ?>
					</p>
				</div>
				<?php endforeach; ?>
			</div>
			<?php endif; ?>

			<?php /* ── DETALLE DE OTs ── */ ?>
			<h2 style="font-size:14px;font-weight:700;color:#374151;margin:0 0 12px;">
				<?php printf( esc_html__( 'Detalle de OTs completadas (%d)', 'calibratrack' ), count( $filas ) ); ?>
			</h2>
			<div style="background:#fff;border-radius:8px;box-shadow:0 1px 4px rgba(0,0,0,.08);overflow:auto;">
				<table style="width:100%;border-collapse:collapse;font-size:13px;">
					<thead>
						<tr style="background:#f8fafc;border-bottom:2px solid #e5e7eb;">
							<th style="padding:10px 14px;text-align:left;font-weight:700;color:#374151;white-space:nowrap;"><?php esc_html_e( 'N° OT', 'calibratrack' ); ?></th>
							<?php if ( $filtro_tec === 0 ) : ?>
							<th style="padding:10px 14px;text-align:left;font-weight:700;color:#374151;"><?php esc_html_e( 'Técnico', 'calibratrack' ); ?></th>
							<?php endif; ?>
							<th style="padding:10px 14px;text-align:left;font-weight:700;color:#374151;"><?php esc_html_e( 'Equipo', 'calibratrack' ); ?></th>
							<th style="padding:10px 14px;text-align:left;font-weight:700;color:#374151;"><?php esc_html_e( 'Tipo', 'calibratrack' ); ?></th>
							<th style="padding:10px 14px;text-align:left;font-weight:700;color:#374151;white-space:nowrap;"><?php esc_html_e( 'Fecha ejec.', 'calibratrack' ); ?></th>
							<th style="padding:10px 14px;text-align:right;font-weight:700;color:#374151;white-space:nowrap;"><?php esc_html_e( 'Subtotal', 'calibratrack' ); ?></th>
							<th style="padding:10px 14px;text-align:right;font-weight:700;color:#374151;white-space:nowrap;"><?php esc_html_e( 'IVA 19%', 'calibratrack' ); ?></th>
							<th style="padding:10px 14px;text-align:right;font-weight:700;color:#374151;white-space:nowrap;"><?php esc_html_e( 'Total', 'calibratrack' ); ?></th>
							<th style="padding:10px 14px;text-align:center;font-weight:700;color:#374151;white-space:nowrap;"><?php esc_html_e( 'Estado Pago', 'calibratrack' ); ?></th>
							<th style="padding:10px 14px;text-align:left;font-weight:700;color:#374151;white-space:nowrap;"><?php esc_html_e( 'N° Factura', 'calibratrack' ); ?></th>
							<th style="padding:10px 14px;text-align:left;font-weight:700;color:#374151;white-space:nowrap;"><?php esc_html_e( 'Acciones', 'calibratrack' ); ?></th>
						</tr>
					</thead>
					<tbody>
					<?php foreach ( $filas as $i => $fila ) :
						$bg = ( $i % 2 === 0 ) ? '#fff' : '#f9fafb';
					?>
					<tr style="background:<?php echo $bg; ?>;border-bottom:1px solid #f0f0f0;">
						<td style="padding:9px 14px;font-weight:600;color:#111827;">
							<?php echo esc_html( $fila['numero_ot'] ?: '—' ); ?>
						</td>
						<?php if ( $filtro_tec === 0 ) : ?>
						<td style="padding:9px 14px;color:#374151;">
							<?php echo esc_html( $fila['tecnico_nombre'] ); ?>
						</td>
						<?php endif; ?>
						<td style="padding:9px 14px;color:#374151;">
							<span style="font-family:monospace;font-size:12px;background:#f1f5f9;padding:2px 6px;border-radius:4px;"><?php echo esc_html( $fila['serie'] ); ?></span>
							<span style="color:#6b7280;margin-left:4px;"><?php echo esc_html( $fila['equipo'] ); ?></span>
						</td>
						<td style="padding:9px 14px;color:#374151;"><?php echo esc_html( $fila['tipo_label'] ); ?></td>
						<td style="padding:9px 14px;color:#374151;white-space:nowrap;"><?php echo esc_html( $fila['fecha_fmt'] ); ?></td>
						<td style="padding:9px 14px;text-align:right;color:#374151;">
							<?php echo $fila['subtotal'] > 0 ? '$' . esc_html( number_format( $fila['subtotal'], 0, ',', '.' ) ) : '<span style="color:#94a3b8;">—</span>'; ?>
						</td>
						<td style="padding:9px 14px;text-align:right;color:#374151;">
							<?php echo $fila['iva'] > 0 ? '$' . esc_html( number_format( $fila['iva'], 0, ',', '.' ) ) : '<span style="color:#94a3b8;">—</span>'; ?>
						</td>
						<td style="padding:9px 14px;text-align:right;font-weight:700;color:#111827;">
							<?php echo $fila['total'] > 0 ? '$' . esc_html( number_format( $fila['total'], 0, ',', '.' ) ) : '<span style="color:#94a3b8;">—</span>'; ?>
						</td>
						<td style="padding:9px 14px;text-align:center;">
							<span class="ct-liq-badge-estado" data-ot-id="<?php echo esc_attr( $fila['ot_id'] ); ?>"
								style="display:inline-block;padding:3px 10px;border-radius:12px;font-size:11px;font-weight:600;
								<?php echo 'pagado' === $fila['estado_pago'] ? 'background:#d1fae5;color:#065f46;' : 'background:#fef3c7;color:#92400e;'; ?>">
								<?php echo 'pagado' === $fila['estado_pago'] ? esc_html__( 'Pagado', 'calibratrack' ) : esc_html__( 'Pendiente', 'calibratrack' ); ?>
							</span>
						</td>
						<td style="padding:9px 14px;font-size:12px;color:#374151;">
							<span class="ct-liq-texto-factura" data-ot-id="<?php echo esc_attr( $fila['ot_id'] ); ?>">
								<?php echo $fila['factura_numero'] ? esc_html( $fila['factura_numero'] ) : '<span style="color:#94a3b8;">—</span>'; ?>
							</span>
						</td>
						<td style="padding:9px 14px;white-space:nowrap;">
							<button type="button" class="button button-small ct-liq-btn-editar"
								data-ot-id="<?php echo esc_attr( $fila['ot_id'] ); ?>"
								data-numero-ot="<?php echo esc_attr( $fila['numero_ot'] ?: '—' ); ?>"
								data-estado="<?php echo esc_attr( $fila['estado_pago'] ); ?>"
								data-factura="<?php echo esc_attr( $fila['factura_numero'] ); ?>">
								<?php esc_html_e( 'Editar', 'calibratrack' ); ?>
							</button>
						</td>
					</tr>
					<?php endforeach; ?>
					</tbody>
					<tfoot>
						<tr style="background:#f0fdf4;border-top:2px solid #86efac;">
							<td colspan="<?php echo $filtro_tec === 0 ? 5 : 4; ?>" style="padding:12px 14px;font-weight:700;color:#15803d;">
								<?php printf( esc_html__( 'TOTAL — %d OTs', 'calibratrack' ), count( $filas ) ); ?>
							</td>
							<td style="padding:12px 14px;text-align:right;font-weight:700;color:#15803d;">
								$<?php echo esc_html( number_format( $gran_subtotal, 0, ',', '.' ) ); ?>
							</td>
							<td style="padding:12px 14px;text-align:right;font-weight:700;color:#15803d;">
								$<?php echo esc_html( number_format( $gran_iva, 0, ',', '.' ) ); ?>
							</td>
							<td style="padding:12px 14px;text-align:right;font-weight:800;font-size:15px;color:#15803d;">
								$<?php echo esc_html( number_format( $gran_total, 0, ',', '.' ) ); ?>
							</td>
							<td></td>
							<td></td>
							<td></td>
						</tr>
					</tfoot>
				</table>
			</div>

			<?php endif; ?>
		</div>

<!-- Modal Editar Pago -->
<div id="ct-liq-modal" style="display:none;position:fixed;inset:0;z-index:100000;background:rgba(0,0,0,.5);align-items:center;justify-content:center;">
	<div style="background:#fff;border-radius:10px;padding:28px 32px;width:360px;max-width:95vw;box-shadow:0 8px 32px rgba(0,0,0,.2);">
		<h3 style="margin:0 0 4px;font-size:15px;font-weight:700;color:#111827;"><?php esc_html_e( 'Editar pago', 'calibratrack' ); ?></h3>
		<p id="ct-liq-modal-ot" style="margin:0 0 20px;font-size:12px;color:#6b7280;"></p>

		<div style="margin-bottom:16px;">
			<label style="display:block;font-size:12px;font-weight:600;color:#374151;margin-bottom:6px;">
				<?php esc_html_e( 'Estado pago', 'calibratrack' ); ?>
			</label>
			<select id="ct-liq-modal-estado" style="width:100%;padding:7px 10px;border:1px solid #d1d5db;border-radius:6px;font-size:13px;">
				<option value="pendiente"><?php esc_html_e( 'Pendiente', 'calibratrack' ); ?></option>
				<option value="pagado"><?php esc_html_e( 'Pagado', 'calibratrack' ); ?></option>
			</select>
		</div>

		<div style="margin-bottom:24px;">
			<label style="display:block;font-size:12px;font-weight:600;color:#374151;margin-bottom:6px;">
				<?php esc_html_e( 'N° Factura', 'calibratrack' ); ?>
			</label>
			<input type="text" id="ct-liq-modal-factura"
				placeholder="<?php esc_attr_e( 'Ej: 001234', 'calibratrack' ); ?>"
				style="width:100%;padding:7px 10px;border:1px solid #d1d5db;border-radius:6px;font-size:13px;box-sizing:border-box;">
		</div>

		<p id="ct-liq-modal-error" style="display:none;color:#dc2626;font-size:12px;margin:0 0 12px;"></p>

		<div style="display:flex;gap:10px;justify-content:flex-end;">
			<button type="button" id="ct-liq-modal-cancelar" class="button">
				<?php esc_html_e( 'Cancelar', 'calibratrack' ); ?>
			</button>
			<button type="button" id="ct-liq-modal-guardar" class="button button-primary">
				<?php esc_html_e( 'Guardar', 'calibratrack' ); ?>
			</button>
		</div>
	</div>
</div>

<script>
(function() {
	var nonce   = '<?php echo esc_js( wp_create_nonce( 'calibratrack_pago_ot' ) ); ?>';
	var ajaxUrl = '<?php echo esc_js( admin_url( 'admin-ajax.php' ) ); ?>';
	var modal   = document.getElementById('ct-liq-modal');
	var modalOt     = document.getElementById('ct-liq-modal-ot');
	var modalEstado = document.getElementById('ct-liq-modal-estado');
	var modalFact   = document.getElementById('ct-liq-modal-factura');
	var modalErr    = document.getElementById('ct-liq-modal-error');
	var modalGuardar = document.getElementById('ct-liq-modal-guardar');
	var modalCancelar = document.getElementById('ct-liq-modal-cancelar');
	var currentOtId = null;
	var currentBtn  = null;

	function abrirModal(btn) {
		currentOtId  = btn.dataset.otId;
		currentBtn   = btn;
		modalOt.textContent = 'OT: ' + btn.dataset.numeroOt;
		modalEstado.value   = btn.dataset.estado || 'pendiente';
		modalFact.value     = btn.dataset.factura || '';
		modalErr.style.display = 'none';
		modalErr.textContent   = '';
		modal.style.display = 'flex';
		modalFact.focus();
	}

	function cerrarModal() {
		modal.style.display = 'none';
		currentOtId = null;
		currentBtn  = null;
	}

	function actualizarFila(otId, estadoPago, facturaNro) {
		// Badge de estado
		var badge = document.querySelector('.ct-liq-badge-estado[data-ot-id="' + otId + '"]');
		if (badge) {
			var esPagado = 'pagado' === estadoPago;
			badge.style.background = esPagado ? '#d1fae5' : '#fef3c7';
			badge.style.color      = esPagado ? '#065f46' : '#92400e';
			badge.textContent      = esPagado ? '<?php echo esc_js( __( 'Pagado', 'calibratrack' ) ); ?>' : '<?php echo esc_js( __( 'Pendiente', 'calibratrack' ) ); ?>';
		}
		// Texto factura
		var textoFact = document.querySelector('.ct-liq-texto-factura[data-ot-id="' + otId + '"]');
		if (textoFact) {
			textoFact.textContent = facturaNro || '—';
		}
		// Actualizar data del botón para la próxima apertura
		var btnRow = document.querySelector('.ct-liq-btn-editar[data-ot-id="' + otId + '"]');
		if (btnRow) {
			btnRow.dataset.estado  = estadoPago;
			btnRow.dataset.factura = facturaNro;
		}
	}

	// Abrir modal al hacer click en "Editar"
	document.querySelectorAll('.ct-liq-btn-editar').forEach(function(btn) {
		btn.addEventListener('click', function() { abrirModal(this); });
	});

	// Cerrar modal
	modalCancelar.addEventListener('click', cerrarModal);
	modal.addEventListener('click', function(e) { if (e.target === modal) cerrarModal(); });
	document.addEventListener('keydown', function(e) { if ('Escape' === e.key) cerrarModal(); });

	// Guardar via AJAX
	modalGuardar.addEventListener('click', function() {
		if (!currentOtId) return;
		var estadoPago = modalEstado.value;
		var facturaNro = modalFact.value.trim();

		modalGuardar.disabled = true;
		modalGuardar.textContent = '<?php echo esc_js( __( 'Guardando…', 'calibratrack' ) ); ?>';
		modalErr.style.display = 'none';

		var data = new FormData();
		data.append('action', 'calibratrack_guardar_pago_ot');
		data.append('_nonce', nonce);
		data.append('ot_id', currentOtId);
		data.append('estado_pago', estadoPago);
		data.append('factura_numero', facturaNro);

		fetch(ajaxUrl, { method: 'POST', body: data })
			.then(function(r) { return r.json(); })
			.then(function(res) {
				if (res.success) {
					actualizarFila(currentOtId, res.data.estado_pago, res.data.factura_numero);
					cerrarModal();
				} else {
					modalErr.textContent   = res.data && res.data.message ? res.data.message : '<?php echo esc_js( __( 'Error al guardar.', 'calibratrack' ) ); ?>';
					modalErr.style.display = 'block';
				}
			})
			.catch(function() {
				modalErr.textContent   = '<?php echo esc_js( __( 'Error de conexión.', 'calibratrack' ) ); ?>';
				modalErr.style.display = 'block';
			})
			.finally(function() {
				modalGuardar.disabled = false;
				modalGuardar.textContent = '<?php echo esc_js( __( 'Guardar', 'calibratrack' ) ); ?>';
			});
	});
})();
</script>

		<style>
		@media print {
			#adminmenumain, #wpadminbar, .notice, .update-nag, .wrap > form,
			.wrap > h1 + p, .button, a[href*="Ver"] { display: none !important; }
			body, .wrap { margin: 0 !important; font-size: 12px !important; }
			table { font-size: 11px !important; }
			.wrap > h1 { margin-bottom: 8px !important; }
			#wpcontent { margin-left: 0 !important; }
			#wpbody { padding-top: 0 !important; }
		}
		</style>
		<?php
	}
}
