<?php
/**
 * Panel unificado de CalibraTrack.
 *
 * Fusiona el panel del técnico (/tecnico/) y el panel del administrador
 * (/administrar/) en una sola interfaz en /panel/.
 * Las URLs antiguas siguen funcionando mediante rewrite rules que las mapean
 * al mismo query var calibratrack_panel_page=1, sin redirección HTTP visible.
 *
 * @package CalibraTrack
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class CalibraTrack_Panel
 */
class CalibraTrack_Panel {

	const QUERY_VAR       = 'calibratrack_panel_page';
	const QUERY_VAR_VISTA = 'calibratrack_panel_vista';
	const QUERY_VAR_ID    = 'calibratrack_panel_id';

	/**
	 * Inicializa todos los hooks del panel unificado.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'init',              array( __CLASS__, 'register_rewrite_rules' ) );
		add_filter( 'query_vars',        array( __CLASS__, 'add_query_vars' ) );
		add_action( 'template_redirect', array( __CLASS__, 'handle_panel' ) );

		// Bloquear wp-admin para técnicos (redirige a /panel/).
		add_action( 'admin_init',        array( __CLASS__, 'block_admin_for_tecnico' ) );

		// Ocultar barra de admin para técnicos.
		add_filter( 'show_admin_bar',    array( __CLASS__, 'hide_admin_bar_for_tecnico' ) );

		// Redirigir a /panel/ después del login desde wp-login.php.
		add_filter( 'login_redirect',    array( __CLASS__, 'redirect_after_login' ), 10, 3 );

		// AJAX: mensajería interna por OT (solo usuarios autenticados).
		add_action( 'wp_ajax_calibratrack_enviar_mensaje',       array( __CLASS__, 'ajax_enviar_mensaje' ) );
		add_action( 'wp_ajax_calibratrack_obtener_mensajes',     array( __CLASS__, 'ajax_obtener_mensajes' ) );

		// AJAX: creación rápida de cliente/equipo desde el formulario de OI.
		add_action( 'wp_ajax_calibratrack_quick_create_cliente', array( __CLASS__, 'ajax_quick_create_cliente' ) );
		add_action( 'wp_ajax_calibratrack_quick_create_equipo',  array( __CLASS__, 'ajax_quick_create_equipo' ) );
	}

	// ─── REWRITE RULES ──────────────────────────────────────────────────────────

	/**
	 * Registra las rewrite rules del panel unificado en /panel/ y los alias
	 * de compatibilidad /tecnico/* y /administrar/*.
	 *
	 * Los alias NO son redirects HTTP — son rewrite rules que mapean las URLs
	 * antiguas al mismo query var, por lo que las URLs viejas siguen funcionando
	 * sin redirección visible para el usuario.
	 *
	 * @return void
	 */
	public static function register_rewrite_rules() {
		$qv      = self::QUERY_VAR;
		$qv_v    = self::QUERY_VAR_VISTA;
		$qv_id   = self::QUERY_VAR_ID;

		// ── Rutas principales /panel/* ─────────────────────────────────────────

		add_rewrite_rule(
			'^panel/login/?$',
			'index.php?' . $qv . '=1&' . $qv_v . '=login',
			'top'
		);
		add_rewrite_rule(
			'^panel/salir/?$',
			'index.php?' . $qv . '=1&' . $qv_v . '=salir',
			'top'
		);
		add_rewrite_rule(
			'^panel/eventos/?$',
			'index.php?' . $qv . '=1&' . $qv_v . '=eventos',
			'top'
		);
		add_rewrite_rule(
			'^panel/evento/([0-9]+)/certificado/?$',
			'index.php?' . $qv . '=1&' . $qv_v . '=certificado&' . $qv_id . '=$matches[1]',
			'top'
		);
		add_rewrite_rule(
			'^panel/evento/([0-9]+)/?$',
			'index.php?' . $qv . '=1&' . $qv_v . '=evento&' . $qv_id . '=$matches[1]',
			'top'
		);
		add_rewrite_rule(
			'^panel/nuevo-evento/?$',
			'index.php?' . $qv . '=1&' . $qv_v . '=nuevo-evento',
			'top'
		);
		add_rewrite_rule(
			'^panel/perfil/?$',
			'index.php?' . $qv . '=1&' . $qv_v . '=perfil',
			'top'
		);
		// Solo administradores.
		add_rewrite_rule(
			'^panel/nueva-oi/?$',
			'index.php?' . $qv . '=1&' . $qv_v . '=nueva-oi',
			'top'
		);
		add_rewrite_rule(
			'^panel/oi/([0-9]+)/?$',
			'index.php?' . $qv . '=1&' . $qv_v . '=oi&' . $qv_id . '=$matches[1]',
			'top'
		);
		add_rewrite_rule(
			'^panel/nueva-ot/?$',
			'index.php?' . $qv . '=1&' . $qv_v . '=nueva-ot',
			'top'
		);
		add_rewrite_rule(
			'^panel/ot/([0-9]+)/?$',
			'index.php?' . $qv . '=1&' . $qv_v . '=ot&' . $qv_id . '=$matches[1]',
			'top'
		);
		add_rewrite_rule(
			'^panel/tecnicos/?$',
			'index.php?' . $qv . '=1&' . $qv_v . '=tecnicos',
			'top'
		);
		add_rewrite_rule(
			'^panel/tecnico/nuevo/?$',
			'index.php?' . $qv . '=1&' . $qv_v . '=tecnico-nuevo',
			'top'
		);
		add_rewrite_rule(
			'^panel/tecnico/([0-9]+)/?$',
			'index.php?' . $qv . '=1&' . $qv_v . '=tecnico&' . $qv_id . '=$matches[1]',
			'top'
		);
		// /panel/ (dashboard).
		add_rewrite_rule(
			'^panel/?$',
			'index.php?' . $qv . '=1&' . $qv_v . '=dashboard',
			'top'
		);

		// ── Alias de compatibilidad /tecnico/* → /panel/* ─────────────────────

		add_rewrite_rule(
			'^tecnico/login/?$',
			'index.php?' . $qv . '=1&' . $qv_v . '=login',
			'top'
		);
		add_rewrite_rule(
			'^tecnico/salir/?$',
			'index.php?' . $qv . '=1&' . $qv_v . '=salir',
			'top'
		);
		add_rewrite_rule(
			'^tecnico/nuevo-evento/?$',
			'index.php?' . $qv . '=1&' . $qv_v . '=nuevo-evento',
			'top'
		);
		add_rewrite_rule(
			'^tecnico/eventos/?$',
			'index.php?' . $qv . '=1&' . $qv_v . '=eventos',
			'top'
		);
		add_rewrite_rule(
			'^tecnico/equipos/?$',
			'index.php?' . $qv . '=1&' . $qv_v . '=equipos',
			'top'
		);
		add_rewrite_rule(
			'^tecnico/evento/([0-9]+)/certificado/?$',
			'index.php?' . $qv . '=1&' . $qv_v . '=certificado&' . $qv_id . '=$matches[1]',
			'top'
		);
		add_rewrite_rule(
			'^tecnico/evento/([0-9]+)/?$',
			'index.php?' . $qv . '=1&' . $qv_v . '=evento&' . $qv_id . '=$matches[1]',
			'top'
		);
		add_rewrite_rule(
			'^tecnico/perfil/?$',
			'index.php?' . $qv . '=1&' . $qv_v . '=perfil',
			'top'
		);
		add_rewrite_rule(
			'^tecnico/?$',
			'index.php?' . $qv . '=1&' . $qv_v . '=dashboard',
			'top'
		);

	}

	/**
	 * Agrega las query vars del panel unificado a WordPress.
	 *
	 * @param array $vars Query vars existentes.
	 * @return array
	 */
	public static function add_query_vars( $vars ) {
		$vars[] = self::QUERY_VAR;
		$vars[] = self::QUERY_VAR_VISTA;
		$vars[] = self::QUERY_VAR_ID;
		return $vars;
	}

	// ─── ROUTING ────────────────────────────────────────────────────────────────

	/**
	 * Detecta si estamos en el panel unificado y despacha al handler correcto.
	 *
	 * @return void
	 */
	public static function handle_panel() {
		if ( ! (bool) get_query_var( self::QUERY_VAR, false ) ) {
			return;
		}

		$vista = (string) get_query_var( self::QUERY_VAR_VISTA, 'dashboard' );

		// Login y logout no requieren autenticación.
		if ( 'login' === $vista ) {
			self::handle_login();
			return;
		}

		if ( 'salir' === $vista ) {
			self::handle_logout();
			return;
		}

		// Todas las demás vistas requieren autenticación.
		self::require_auth();

		switch ( $vista ) {
			case 'dashboard':
				self::load_template( 'dashboard' );
				break;
			case 'eventos':
				self::load_template( 'lista-eventos' );
				break;
			case 'tecnicos':
				self::require_admin();
				self::handle_lista_tecnicos();
				break;
			case 'tecnico-nuevo':
				self::require_admin();
				self::handle_tecnico( 0 );
				break;
			case 'tecnico':
				self::require_admin();
				$id = (int) get_query_var( self::QUERY_VAR_ID, 0 );
				self::handle_tecnico( $id );
				break;
			case 'evento':
				self::handle_editar_evento();
				break;
			case 'certificado':
				self::handle_certificado();
				break;
			case 'nuevo-evento':
				// Solo administradores crean eventos desde el panel.
				if ( ! current_user_can( 'manage_options' ) ) {
					wp_redirect( home_url( '/panel/' ) );
					exit;
				}
				self::handle_nuevo_evento();
				break;
			case 'perfil':
				self::handle_perfil();
				break;
			case 'nueva-oi':
				self::require_admin();
				self::handle_nueva_oi();
				break;
			case 'oi':
				$id = (int) get_query_var( self::QUERY_VAR_ID, 0 );
				// Admin: acceso completo. Técnico: solo puede ver sus propias OIs (solo lectura).
				if ( ! current_user_can( 'manage_options' ) ) {
					self::handle_oi_tecnico( $id );
				} else {
					self::handle_oi( $id );
				}
				break;
			case 'nueva-ot':
				self::require_admin();
				self::handle_nueva_ot();
				break;
			case 'ot':
				self::require_admin();
				$id = (int) get_query_var( self::QUERY_VAR_ID, 0 );
				self::handle_ot( $id );
				break;
			default:
				self::load_template( 'dashboard' );
				break;
		}
	}

	// ─── AUTH ────────────────────────────────────────────────────────────────────

	/**
	 * Verifica autenticación y capability. Redirige al login si no cumple.
	 *
	 * @return void
	 */
	private static function require_auth() {
		if ( is_user_logged_in() && current_user_can( 'create_eventos_servicio' ) ) {
			return;
		}

		$current_url = home_url( add_query_arg( array(), $GLOBALS['wp']->request ) );
		wp_redirect( home_url( '/panel/login/?redirect_to=' . rawurlencode( $current_url ) ) );
		exit;
	}

	/**
	 * Verifica que el usuario tenga manage_options. Si no, redirige al panel.
	 *
	 * @return void
	 */
	private static function require_admin() {
		if ( is_user_logged_in() && current_user_can( 'manage_options' ) ) {
			return;
		}
		wp_redirect( home_url( '/panel/' ) );
		exit;
	}

	/**
	 * Procesa el formulario de login o carga el template.
	 *
	 * @return void
	 */
	private static function handle_login() {
		// Si ya está logueado como técnico o admin, redirigir al panel.
		if ( is_user_logged_in() && current_user_can( 'create_eventos_servicio' ) ) {
			wp_redirect( home_url( '/panel/' ) );
			exit;
		}

		$error       = '';
		$redirect_to = isset( $_GET['redirect_to'] ) ? wp_validate_redirect( wp_unslash( $_GET['redirect_to'] ), home_url( '/panel/' ) ) : home_url( '/panel/' );

		if ( 'POST' === $_SERVER['REQUEST_METHOD'] ) {
			// Verificar nonce.
			if ( ! isset( $_POST['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ), 'calibratrack_tecnico_login' ) ) {
				$error = __( 'Error de seguridad. Por favor recarga la página.', 'calibratrack' );
			} else {
				$username = isset( $_POST['username'] ) ? sanitize_user( wp_unslash( $_POST['username'] ) ) : '';
				$password = isset( $_POST['password'] ) ? wp_unslash( $_POST['password'] ) : '';
				$remember = isset( $_POST['remember'] ) && '1' === $_POST['remember'];

				if ( empty( $username ) || empty( $password ) ) {
					$error = __( 'Ingrese usuario y contraseña.', 'calibratrack' );
				} else {
					$user = wp_signon( array(
						'user_login'    => $username,
						'user_password' => $password,
						'remember'      => $remember,
					), false );

					if ( is_wp_error( $user ) ) {
						$error = __( 'Usuario o contraseña incorrectos.', 'calibratrack' );
					} elseif ( ! user_can( $user, 'create_eventos_servicio' ) ) {
						wp_logout();
						$error = __( 'No tienes permisos para acceder a este panel.', 'calibratrack' );
					} else {
						wp_redirect( $redirect_to );
						exit;
					}
				}
			}
		}

		self::load_template( 'login', array(
			'error'       => $error,
			'redirect_to' => $redirect_to,
		) );
	}

	/**
	 * Procesa el logout del usuario.
	 *
	 * @return void
	 */
	private static function handle_logout() {
		if ( is_user_logged_in() ) {
			wp_logout();
		}
		wp_redirect( home_url( '/panel/login/' ) );
		exit;
	}

	/**
	 * Redirige técnicos al panel después del login desde /wp-login.php.
	 *
	 * @param string       $redirect_to URL de redirección solicitada.
	 * @param string       $requested   URL solicitada originalmente.
	 * @param WP_User|WP_Error $user    Usuario que acaba de iniciar sesión.
	 * @return string
	 */
	public static function redirect_after_login( $redirect_to, $requested, $user ) {
		if ( ! is_wp_error( $user ) && user_can( $user, 'create_eventos_servicio' ) && ! user_can( $user, 'manage_options' ) ) {
			return home_url( '/panel/' );
		}
		return $redirect_to;
	}

	// ─── BLOQUEO WP-ADMIN ────────────────────────────────────────────────────────

	/**
	 * Redirige a /panel/ si un técnico intenta acceder a wp-admin.
	 *
	 * @return void
	 */
	public static function block_admin_for_tecnico() {
		if ( ! is_user_logged_in() ) {
			return;
		}
		// Permitir AJAX (wp-admin/admin-ajax.php lo necesita).
		if ( defined( 'DOING_AJAX' ) && DOING_AJAX ) {
			return;
		}
		if ( current_user_can( 'create_eventos_servicio' ) && ! current_user_can( 'manage_options' ) ) {
			wp_redirect( home_url( '/panel/' ) );
			exit;
		}
	}

	/**
	 * Oculta la barra de administración para técnicos.
	 *
	 * @param bool $show Si mostrar la barra.
	 * @return bool
	 */
	public static function hide_admin_bar_for_tecnico( $show ) {
		if ( is_user_logged_in() && current_user_can( 'create_eventos_servicio' ) && ! current_user_can( 'manage_options' ) ) {
			return false;
		}
		return $show;
	}

	// ─── HANDLERS DE VISTAS ──────────────────────────────────────────────────────

	/**
	 * Maneja la vista de nuevo evento (GET muestra formulario, POST guarda).
	 *
	 * @return void
	 */
	private static function handle_nuevo_evento() {
		$errors  = array();
		$valores = array();

		if ( 'POST' === $_SERVER['REQUEST_METHOD'] ) {
			$resultado = self::procesar_guardar_evento( 0, $errors, $valores );
			if ( $resultado ) {
				wp_redirect( home_url( '/panel/eventos/?guardado=1' ) );
				exit;
			}
		} else {
			$valores['numero_ot'] = CalibraTrack_Helpers::generar_numero_ot();
		}

		self::load_template( 'nuevo-evento', array(
			'errors'  => $errors,
			'valores' => $valores,
			'equipos' => CalibraTrack_Tecnico::get_equipos_para_select(),
		) );
	}

	/**
	 * Maneja la vista de edición de evento existente.
	 *
	 * @return void
	 */
	private static function handle_editar_evento() {
		$evento_id = (int) get_query_var( self::QUERY_VAR_ID, 0 );
		$errors    = array();
		$valores   = array();

		if ( $evento_id <= 0 || 'evento_servicio' !== get_post_type( $evento_id ) ) {
			wp_redirect( home_url( '/panel/eventos/' ) );
			exit;
		}

		// Admin → redirigir a vista completa de OT.
		if ( current_user_can( 'manage_options' ) ) {
			wp_redirect( home_url( '/panel/ot/' . $evento_id . '/' ) );
			exit;
		}

		// Técnico: única fuente de verdad es calibratrack_tecnico_responsable.
		$tecnico_asignado = (int) get_post_meta( $evento_id, CalibraTrack_Meta_Keys::EVENTO_TECNICO_RESPONSABLE, true );
		if ( $tecnico_asignado !== get_current_user_id() ) {
			wp_redirect( home_url( '/panel/' ) );
			exit;
		}

		$estado_actual  = (string) get_post_meta( $evento_id, CalibraTrack_Meta_Keys::EVENTO_ESTADO_SERVICIO, true );
		$cert_id_actual = (int) get_post_meta( $evento_id, CalibraTrack_Meta_Keys::EVENTO_CERTIFICADO_PDF, true );
		$es_completado  = ( 'completado' === $estado_actual );

		if ( $es_completado && 'POST' === $_SERVER['REQUEST_METHOD'] ) {
			$errors['general'] = __( 'Esta orden de trabajo ya está finalizada y no puede ser modificada.', 'calibratrack' );
		} elseif ( ! $es_completado && 'POST' === $_SERVER['REQUEST_METHOD'] ) {
			$resultado = self::procesar_guardar_evento( $evento_id, $errors, $valores );
			if ( $resultado ) {
				wp_redirect( home_url( '/panel/evento/' . $evento_id . '/?actualizado=1' ) );
				exit;
			}
		}

		if ( empty( $valores ) ) {
			$valores = CalibraTrack_Tecnico::cargar_valores_evento( $evento_id );
		}

		// Cargar mensajes y marcar como leídos para el técnico.
		$mensajes_ot = array();
		if ( class_exists( 'CalibraTrack_Mensajes' ) ) {
			CalibraTrack_Mensajes::marcar_leidos( $evento_id, get_current_user_id() );
			$mensajes_ot = CalibraTrack_Mensajes::get_by_evento( $evento_id );
		}

		self::load_template( 'evento-detalle', array(
			'evento_id'     => $evento_id,
			'errors'        => $errors,
			'valores'       => $valores,
			'equipos'       => CalibraTrack_Tecnico::get_equipos_para_select(),
			'es_completado' => $es_completado,
			'es_tecnico'    => true,
			'mensajes_ot'   => $mensajes_ot,
		) );
	}

	/**
	 * Sirve el certificado PDF del evento vía proxy PHP.
	 *
	 * @return void
	 */
	private static function handle_certificado() {
		$evento_id = (int) get_query_var( self::QUERY_VAR_ID, 0 );

		if ( $evento_id <= 0 || 'evento_servicio' !== get_post_type( $evento_id ) ) {
			wp_die( esc_html__( 'Evento no encontrado.', 'calibratrack' ), '', array( 'response' => 404 ) );
		}

		$autor_id = (int) get_post_field( 'post_author', $evento_id );
		if ( $autor_id !== get_current_user_id() && ! current_user_can( 'edit_others_eventos_servicio' ) ) {
			wp_die( esc_html__( 'Acceso denegado.', 'calibratrack' ), '', array( 'response' => 403 ) );
		}

		$cert_id = (int) get_post_meta( $evento_id, CalibraTrack_Meta_Keys::EVENTO_CERTIFICADO_PDF, true );

		if ( ! $cert_id ) {
			if ( class_exists( 'CalibraTrack_PDF_Generator' ) ) {
				$cert_id = CalibraTrack_PDF_Generator::generate_certificado( $evento_id );
			}
		}

		if ( ! $cert_id ) {
			wp_die( esc_html__( 'Certificado no disponible.', 'calibratrack' ), '', array( 'response' => 404 ) );
		}

		$file_path = get_attached_file( $cert_id );
		if ( ! $file_path || ! file_exists( $file_path ) ) {
			wp_die( esc_html__( 'Archivo no encontrado.', 'calibratrack' ), '', array( 'response' => 404 ) );
		}

		$numero_ot = get_post_meta( $evento_id, CalibraTrack_Meta_Keys::EVENTO_NUMERO_OT, true );
		$filename  = 'certificado-' . sanitize_file_name( $numero_ot ) . '.pdf';

		header( 'Content-Type: application/pdf' );
		header( 'Content-Disposition: inline; filename="' . $filename . '"' );
		header( 'Content-Length: ' . filesize( $file_path ) );
		header( 'Cache-Control: private, no-cache' );
		readfile( $file_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile
		exit;
	}

	/**
	 * Maneja la vista y guardado del perfil del usuario.
	 *
	 * @return void
	 */
	private static function handle_perfil() {
		wp_safe_redirect( home_url( '/panel/' ) );
		exit;

		if ( 'POST' === $_SERVER['REQUEST_METHOD'] && isset( $_POST['ct_perfil_submit'] ) ) {
			if ( ! isset( $_POST['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ), 'calibratrack_tecnico_perfil' ) ) {
				$error = __( 'Error de seguridad. Recarga la página.', 'calibratrack' );
			} else {
				$user_id = get_current_user_id();
				$cargo   = sanitize_text_field( isset( $_POST['calibratrack_cargo'] ) ? wp_unslash( $_POST['calibratrack_cargo'] ) : '' );
				update_user_meta( $user_id, 'calibratrack_cargo', $cargo );
				$success = __( 'Perfil actualizado correctamente.', 'calibratrack' );
			}
		}

		self::load_template( 'perfil', array(
			'success' => $success,
			'error'   => $error,
		) );
	}

	// ─── HANDLERS ADMIN: OI ──────────────────────────────────────────────────────

	/**
	 * GET muestra formulario de nueva OI. POST guarda.
	 *
	 * @return void
	 */
	private static function handle_nueva_oi() {
		$errors  = array();
		$valores = array();

		if ( 'POST' === $_SERVER['REQUEST_METHOD'] ) {
			$evento_id = self::guardar_oi( 0, $errors, $valores );
			if ( false !== $evento_id ) {
				wp_redirect( home_url( '/panel/oi/' . $evento_id . '/?guardado=1' ) );
				exit;
			}
		}

		self::load_template( 'form-oi', array(
			'errors'         => $errors,
			'valores'        => $valores,
			'evento_id'      => 0,
			'equipos'        => CalibraTrack_Helpers::get_equipos_para_select(),
			'clientes'       => CalibraTrack_Helpers::get_clientes_para_select(),
			'cliente_actual' => 0,
			'tecnicos'       => CalibraTrack_Helpers::get_tecnicos_para_select(),
			'tipos_ev'       => CalibraTrack_Helpers::get_tipos_evento(),
		) );
	}

	/**
	 * GET muestra formulario de edición de OI. POST guarda cambios.
	 *
	 * @param int $id Post ID del evento_servicio (tipo ingreso).
	 * @return void
	 */
	private static function handle_oi( $id ) {
		if ( $id <= 0 || 'evento_servicio' !== get_post_type( $id ) ) {
			wp_redirect( home_url( '/panel/' ) );
			exit;
		}

		$errors  = array();
		$valores = array();

		if ( 'POST' === $_SERVER['REQUEST_METHOD'] ) {
			$resultado = self::guardar_oi( $id, $errors, $valores );
			if ( false !== $resultado ) {
				wp_redirect( home_url( '/panel/oi/' . $id . '/?guardado=1' ) );
				exit;
			}
		}

		if ( empty( $valores ) ) {
			$valores = CalibraTrack_Helpers::cargar_valores_oi( $id );
		}

		$ots_vinculadas = get_posts( array(
			'post_type'      => CalibraTrack_CPT_EventoServicio::SLUG,
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'meta_query'     => array(
				array(
					'key'   => CalibraTrack_Meta_Keys::EVENTO_INGRESO_RELACIONADO_ID,
					'value' => $id,
					'type'  => 'NUMERIC',
				),
			),
		) );

		// Derivar el cliente actual desde el equipo vinculado.
		$eq_id_actual   = isset( $valores['equipo_id'] ) ? (int) $valores['equipo_id'] : 0;
		$cliente_actual = $eq_id_actual > 0
			? (int) get_post_meta( $eq_id_actual, CalibraTrack_Meta_Keys::EQUIPO_CLIENTE_PROPIETARIO, true )
			: 0;

		self::load_template( 'form-oi', array(
			'errors'         => $errors,
			'valores'        => $valores,
			'evento_id'      => $id,
			'equipos'        => CalibraTrack_Helpers::get_equipos_para_select(),
			'clientes'       => CalibraTrack_Helpers::get_clientes_para_select(),
			'cliente_actual' => $cliente_actual,
			'tecnicos'       => CalibraTrack_Helpers::get_tecnicos_para_select(),
			'tipos_ev'       => CalibraTrack_Helpers::get_tipos_evento(),
			'ots_vinculadas' => $ots_vinculadas,
		) );
	}

	// ─── HANDLER TÉCNICO: OI (solo lectura) ────────────────────────────────────

	/**
	 * Permite al técnico ver (solo lectura) una OI que tenga asignada.
	 * Redirige al panel si no le pertenece.
	 *
	 * @param int $id Post ID de la OI.
	 * @return void
	 */
	private static function handle_oi_tecnico( $id ) {
		if ( $id <= 0 || 'evento_servicio' !== get_post_type( $id ) ) {
			wp_redirect( home_url( '/panel/' ) );
			exit;
		}

		$tecnico_asignado = (int) get_post_meta( $id, CalibraTrack_Meta_Keys::EVENTO_TECNICO_RESPONSABLE, true );
		if ( $tecnico_asignado !== get_current_user_id() ) {
			wp_redirect( home_url( '/panel/' ) );
			exit;
		}

		$valores = CalibraTrack_Helpers::cargar_valores_oi( $id );

		$ots_vinculadas = get_posts( array(
			'post_type'      => CalibraTrack_CPT_EventoServicio::SLUG,
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'meta_query'     => array( array(
				'key'   => CalibraTrack_Meta_Keys::EVENTO_INGRESO_RELACIONADO_ID,
				'value' => $id,
				'type'  => 'NUMERIC',
			) ),
		) );

		$eq_id_tec      = isset( $valores['equipo_id'] ) ? (int) $valores['equipo_id'] : 0;
		$cliente_actual = $eq_id_tec > 0
			? (int) get_post_meta( $eq_id_tec, CalibraTrack_Meta_Keys::EQUIPO_CLIENTE_PROPIETARIO, true )
			: 0;

		self::load_template( 'form-oi', array(
			'errors'         => array(),
			'valores'        => $valores,
			'evento_id'      => $id,
			'equipos'        => CalibraTrack_Helpers::get_equipos_para_select(),
			'clientes'       => CalibraTrack_Helpers::get_clientes_para_select(),
			'cliente_actual' => $cliente_actual,
			'tecnicos'       => CalibraTrack_Helpers::get_tecnicos_para_select(),
			'tipos_ev'       => CalibraTrack_Helpers::get_tipos_evento(),
			'ots_vinculadas' => $ots_vinculadas,
			'es_solo_lectura'=> true,
		) );
	}

	// ─── HANDLERS ADMIN: OT ──────────────────────────────────────────────────────

	/**
	 * GET muestra formulario de nueva OT. POST guarda.
	 *
	 * @return void
	 */
	private static function handle_nueva_ot() {
		$errors  = array();
		$valores = array();

		if ( 'POST' === $_SERVER['REQUEST_METHOD'] ) {
			$evento_id = self::guardar_ot( 0, $errors, $valores );
			if ( false !== $evento_id ) {
				wp_redirect( home_url( '/panel/ot/' . $evento_id . '/?guardado=1' ) );
				exit;
			}
		} else {
			$oi_id = isset( $_GET['ct_oi_id'] ) ? absint( $_GET['ct_oi_id'] ) : 0;
			if ( $oi_id > 0 && 'evento_servicio' === get_post_type( $oi_id ) ) {
				$valores_oi = CalibraTrack_Helpers::cargar_valores_oi( $oi_id );
				$valores['equipo_id']              = isset( $valores_oi['equipo_id'] ) ? $valores_oi['equipo_id'] : 0;
				$valores['tecnico_id']             = isset( $valores_oi['tecnico_id'] ) ? $valores_oi['tecnico_id'] : 0;
				$valores['tipo']                   = isset( $valores_oi['tipo'] ) ? $valores_oi['tipo'] : '';
				$valores['falla_reportada']        = isset( $valores_oi['falla_reportada'] ) ? $valores_oi['falla_reportada'] : '';
				$valores['ingreso_relacionado_id'] = $oi_id;
			}
			$valores['numero_ot'] = CalibraTrack_Helpers::generar_numero_ot();
		}

		$oi_id_get = isset( $_GET['ct_oi_id'] ) ? absint( $_GET['ct_oi_id'] ) : 0;

		self::load_template( 'form-ot', array(
			'errors'    => $errors,
			'valores'   => $valores,
			'evento_id' => 0,
			'equipos'   => CalibraTrack_Helpers::get_equipos_para_select(),
			'tecnicos'  => CalibraTrack_Helpers::get_tecnicos_para_select(),
			'tipos_ev'  => CalibraTrack_Helpers::get_tipos_evento(),
			'ois'       => CalibraTrack_Helpers::get_ois_para_select(),
			'oi_id_get' => $oi_id_get,
		) );
	}

	/**
	 * GET muestra formulario de edición de OT. POST guarda cambios.
	 *
	 * @param int $id Post ID del evento_servicio (tipo ot).
	 * @return void
	 */
	private static function handle_ot( $id ) {
		if ( $id <= 0 || 'evento_servicio' !== get_post_type( $id ) ) {
			wp_redirect( home_url( '/panel/' ) );
			exit;
		}

		$errors  = array();
		$valores = array();

		if ( 'POST' === $_SERVER['REQUEST_METHOD'] ) {
			$resultado = self::guardar_ot( $id, $errors, $valores );
			if ( false !== $resultado ) {
				wp_redirect( home_url( '/panel/ot/' . $id . '/?guardado=1' ) );
				exit;
			}
		}

		if ( empty( $valores ) ) {
			$valores = CalibraTrack_Helpers::cargar_valores_ot( $id );
		}

		$cert_id   = (int) get_post_meta( $id, CalibraTrack_Meta_Keys::EVENTO_CERTIFICADO_PDF, true );
		$ot_pdf_id = (int) get_post_meta( $id, CalibraTrack_Meta_Keys::EVENTO_ORDEN_TRABAJO_PDF, true );

		// Cargar mensajes y marcar como leídos los mensajes que no escribió el admin.
		$mensajes_ot = array();
		if ( class_exists( 'CalibraTrack_Mensajes' ) ) {
			CalibraTrack_Mensajes::marcar_leidos( $id, get_current_user_id() );
			$mensajes_ot = CalibraTrack_Mensajes::get_by_evento( $id );
		}

		// La OI ya vinculada a esta OT debe seguir apareciendo en el select aunque sea 1:1.
		$current_oi_id = isset( $valores['ingreso_relacionado_id'] ) ? (int) $valores['ingreso_relacionado_id'] : 0;

		self::load_template( 'form-ot', array(
			'errors'      => $errors,
			'valores'     => $valores,
			'evento_id'   => $id,
			'equipos'     => CalibraTrack_Helpers::get_equipos_para_select(),
			'tecnicos'    => CalibraTrack_Helpers::get_tecnicos_para_select(),
			'tipos_ev'    => CalibraTrack_Helpers::get_tipos_evento(),
			'ois'         => CalibraTrack_Helpers::get_ois_para_select( $current_oi_id ),
			'cert_id'     => $cert_id,
			'ot_pdf_id'   => $ot_pdf_id,
			'oi_id_get'   => 0,
			'mensajes_ot' => $mensajes_ot,
		) );
	}

	// ─── LÓGICA DE GUARDADO: OI ─────────────────────────────────────────────────

	/**
	 * Valida y guarda una Orden de Ingreso.
	 * Retorna el post ID si tiene éxito, false si hay errores.
	 *
	 * @param int   $evento_id  0 para nueva OI, ID para editar.
	 * @param array $errors     Array de errores (por referencia).
	 * @param array $valores    Valores del formulario (por referencia).
	 * @return int|false Post ID en éxito, false en error.
	 */
	private static function guardar_oi( $evento_id, &$errors, &$valores ) {
		if ( ! isset( $_POST['_wpnonce'] ) || ! wp_verify_nonce(
			sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ),
			'calibratrack_admin_oi'
		) ) {
			$errors['general'] = __( 'Error de seguridad. Recarga la página.', 'calibratrack' );
			return false;
		}

		$valores = array(
			'equipo_id'       => absint( isset( $_POST['equipo_id'] ) ? $_POST['equipo_id'] : 0 ),
			'tecnico_id'      => absint( isset( $_POST['tecnico_id'] ) ? $_POST['tecnico_id'] : 0 ),
			'tipo'            => sanitize_key( isset( $_POST['tipo'] ) ? wp_unslash( $_POST['tipo'] ) : '' ),
			'fecha_ejecucion' => sanitize_text_field( isset( $_POST['fecha_ejecucion'] ) ? wp_unslash( $_POST['fecha_ejecucion'] ) : '' ),
			'falla_reportada' => sanitize_textarea_field( isset( $_POST['falla_reportada'] ) ? wp_unslash( $_POST['falla_reportada'] ) : '' ),
		);

		if ( $valores['equipo_id'] <= 0 ) {
			$errors['equipo_id'] = __( 'Seleccione un equipo.', 'calibratrack' );
		}
		if ( $valores['tecnico_id'] <= 0 ) {
			$errors['tecnico_id'] = __( 'Seleccione un técnico responsable.', 'calibratrack' );
		}
		$tipos_validos = array_keys( CalibraTrack_Helpers::get_tipos_evento() );
		if ( ! in_array( $valores['tipo'], $tipos_validos, true ) ) {
			$errors['tipo'] = __( 'Seleccione un tipo de servicio.', 'calibratrack' );
		}
		if ( empty( $valores['fecha_ejecucion'] ) || ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $valores['fecha_ejecucion'] ) ) {
			$errors['fecha_ejecucion'] = __( 'La fecha de recepción es obligatoria.', 'calibratrack' );
		}

		if ( ! empty( $errors ) ) {
			return false;
		}

		$es_nuevo = ( 0 === (int) $evento_id );

		// Generar número OI solo en la primera creación.
		if ( $es_nuevo ) {
			$valores['numero_oi'] = CalibraTrack_Helpers::generar_numero_oi();
		} else {
			$valores['numero_oi'] = (string) get_post_meta( $evento_id, CalibraTrack_Meta_Keys::EVENTO_NUMERO_OI, true );
		}

		$tipos_ev   = CalibraTrack_Helpers::get_tipos_evento();
		$tipo_label = isset( $tipos_ev[ $valores['tipo'] ] ) ? $tipos_ev[ $valores['tipo'] ] : $valores['tipo'];
		$post_title = $valores['numero_oi'] . ' — ' . $tipo_label;

		if ( $es_nuevo ) {
			$post_data = array(
				'post_title'  => $post_title,
				'post_type'   => CalibraTrack_CPT_EventoServicio::SLUG,
				'post_status' => 'publish',
				'post_author' => get_current_user_id(),
			);
			$result = wp_insert_post( $post_data, true );
		} else {
			$post_data = array(
				'ID'          => $evento_id,
				'post_title'  => $post_title,
				'post_status' => 'publish',
				'post_type'   => CalibraTrack_CPT_EventoServicio::SLUG,
			);
			$result = wp_update_post( $post_data, true );
		}

		if ( is_wp_error( $result ) ) {
			$errors['general'] = $result->get_error_message();
			return false;
		}

		$evento_id = (int) $result;

		update_post_meta( $evento_id, CalibraTrack_Meta_Keys::EVENTO_TIPO_DOCUMENTO,      'ingreso' );
		update_post_meta( $evento_id, CalibraTrack_Meta_Keys::EVENTO_NUMERO_OI,           $valores['numero_oi'] );
		update_post_meta( $evento_id, CalibraTrack_Meta_Keys::EVENTO_EQUIPO_ID,           $valores['equipo_id'] );
		update_post_meta( $evento_id, CalibraTrack_Meta_Keys::EVENTO_TECNICO_RESPONSABLE, $valores['tecnico_id'] );
		update_post_meta( $evento_id, CalibraTrack_Meta_Keys::EVENTO_TIPO,                $valores['tipo'] );
		update_post_meta( $evento_id, CalibraTrack_Meta_Keys::EVENTO_FECHA_EJECUCION,     $valores['fecha_ejecucion'] );
		update_post_meta( $evento_id, CalibraTrack_Meta_Keys::EVENTO_FALLA_REPORTADA,     $valores['falla_reportada'] );

		if ( $es_nuevo && class_exists( 'CalibraTrack_Mailer' ) ) {
			CalibraTrack_Mailer::send_oi_a_tecnico( $evento_id );
			CalibraTrack_Mailer::send_oi_a_cliente( $evento_id );
		}

		self::procesar_uploads( $evento_id );

		delete_transient( 'calibratrack_vigencia_' . $valores['equipo_id'] );

		return $evento_id;
	}

	// ─── LÓGICA DE GUARDADO: OT ─────────────────────────────────────────────────

	/**
	 * Valida y guarda una Orden de Trabajo.
	 * Retorna el post ID si tiene éxito, false si hay errores.
	 *
	 * @param int   $evento_id  0 para nueva OT, ID para editar.
	 * @param array $errors     Array de errores (por referencia).
	 * @param array $valores    Valores del formulario (por referencia).
	 * @return int|false Post ID en éxito, false en error.
	 */
	private static function guardar_ot( $evento_id, &$errors, &$valores ) {
		if ( ! isset( $_POST['_wpnonce'] ) || ! wp_verify_nonce(
			sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ),
			'calibratrack_admin_ot'
		) ) {
			$errors['general'] = __( 'Error de seguridad. Recarga la página.', 'calibratrack' );
			return false;
		}

		$estado_raw   = isset( $_POST['estado_servicio'] )
			? sanitize_key( wp_unslash( $_POST['estado_servicio'] ) )
			: 'en_proceso';
		$estado_valido = in_array( $estado_raw, array( 'en_proceso', 'en_ejecucion', 'listo_revision', 'completado' ), true )
			? $estado_raw
			: 'en_proceso';

		$valores = array(
			'equipo_id'              => absint( isset( $_POST['equipo_id'] ) ? $_POST['equipo_id'] : 0 ),
			'tecnico_id'             => absint( isset( $_POST['tecnico_id'] ) ? $_POST['tecnico_id'] : 0 ),
			'numero_ot'              => sanitize_text_field( isset( $_POST['numero_ot'] ) ? wp_unslash( $_POST['numero_ot'] ) : '' ),
			'tipo'                   => sanitize_key( isset( $_POST['tipo'] ) ? wp_unslash( $_POST['tipo'] ) : '' ),
			'fecha_ejecucion'        => sanitize_text_field( isset( $_POST['fecha_ejecucion'] ) ? wp_unslash( $_POST['fecha_ejecucion'] ) : '' ),
			'proxima_fecha'          => sanitize_text_field( isset( $_POST['proxima_fecha'] ) ? wp_unslash( $_POST['proxima_fecha'] ) : '' ),
			'falla_reportada'        => sanitize_textarea_field( isset( $_POST['falla_reportada'] ) ? wp_unslash( $_POST['falla_reportada'] ) : '' ),
			'descripcion_trabajo'    => sanitize_textarea_field( isset( $_POST['descripcion_trabajo'] ) ? wp_unslash( $_POST['descripcion_trabajo'] ) : '' ),
			'observaciones'          => sanitize_textarea_field( isset( $_POST['observaciones'] ) ? wp_unslash( $_POST['observaciones'] ) : '' ),
			'estado_servicio'        => $estado_valido,
			'ingreso_relacionado_id' => absint( isset( $_POST['ingreso_relacionado_id'] ) ? $_POST['ingreso_relacionado_id'] : 0 ),
			'items'                  => array(),
		);

		if ( $valores['ingreso_relacionado_id'] <= 0 ) {
			$errors['ingreso_relacionado_id'] = __( 'Debe seleccionar una Orden de Ingreso vinculada.', 'calibratrack' );
		}
		if ( $valores['equipo_id'] <= 0 ) {
			$errors['equipo_id'] = __( 'Seleccione un equipo.', 'calibratrack' );
		}
		if ( $valores['tecnico_id'] <= 0 ) {
			$errors['tecnico_id'] = __( 'Seleccione un técnico responsable.', 'calibratrack' );
		}
		if ( empty( $valores['numero_ot'] ) ) {
			$errors['numero_ot'] = __( 'El N° de OT es obligatorio.', 'calibratrack' );
		} elseif ( CalibraTrack_Helpers::numero_ot_existe( $valores['numero_ot'], $evento_id ) ) {
			$errors['numero_ot'] = __( 'Este N° de OT ya está en uso. Elige otro número.', 'calibratrack' );
		}
		$tipos_validos = array_keys( CalibraTrack_Helpers::get_tipos_evento() );
		if ( ! in_array( $valores['tipo'], $tipos_validos, true ) ) {
			$errors['tipo'] = __( 'Seleccione un tipo de servicio.', 'calibratrack' );
		}
		if ( empty( $valores['fecha_ejecucion'] ) || ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $valores['fecha_ejecucion'] ) ) {
			$errors['fecha_ejecucion'] = __( 'La fecha de ejecución es obligatoria.', 'calibratrack' );
		}
		// Validar coherencia de fechas.
		if (
			! empty( $valores['proxima_fecha'] ) &&
			preg_match( '/^\d{4}-\d{2}-\d{2}$/', $valores['proxima_fecha'] ) &&
			! empty( $valores['fecha_ejecucion'] ) &&
			preg_match( '/^\d{4}-\d{2}-\d{2}$/', $valores['fecha_ejecucion'] )
		) {
			if ( $valores['proxima_fecha'] < $valores['fecha_ejecucion'] ) {
				$errors['proxima_fecha'] = __( 'La próxima fecha de control no puede ser anterior a la fecha de ejecución.', 'calibratrack' );
			}
		}

		$items_raw = isset( $_POST['calibratrack_items'] ) && is_array( $_POST['calibratrack_items'] )
			? $_POST['calibratrack_items']
			: array();
		foreach ( $items_raw as $item ) {
			$detalle  = sanitize_text_field( wp_unslash( isset( $item['detalle'] ) ? $item['detalle'] : '' ) );
			$cantidad = isset( $item['cantidad'] ) ? (float) $item['cantidad'] : 0.0;
			$precio   = isset( $item['precio_unitario'] ) ? (float) $item['precio_unitario'] : 0.0;
			if ( ! empty( $detalle ) ) {
				$valores['items'][] = array(
					'detalle'         => $detalle,
					'cantidad'        => $cantidad,
					'precio_unitario' => $precio,
				);
			}
		}

		if ( ! empty( $errors ) ) {
			return false;
		}

		$es_nuevo = ( 0 === (int) $evento_id );

		$estado_anterior = '';
		if ( ! $es_nuevo ) {
			$estado_anterior = (string) get_post_meta( $evento_id, CalibraTrack_Meta_Keys::EVENTO_ESTADO_SERVICIO, true );
		}

		$tipos_label = CalibraTrack_Helpers::get_tipos_evento();
		$tipo_label  = isset( $tipos_label[ $valores['tipo'] ] ) ? $tipos_label[ $valores['tipo'] ] : $valores['tipo'];
		$post_title  = sprintf( '%s — %s', $valores['numero_ot'], $tipo_label );

		if ( $es_nuevo ) {
			$post_data = array(
				'post_title'  => $post_title,
				'post_type'   => CalibraTrack_CPT_EventoServicio::SLUG,
				'post_status' => 'publish',
				'post_author' => get_current_user_id(),
			);
			$result = wp_insert_post( $post_data, true );
		} else {
			$post_data = array(
				'ID'          => $evento_id,
				'post_title'  => $post_title,
				'post_status' => 'publish',
				'post_type'   => CalibraTrack_CPT_EventoServicio::SLUG,
			);
			$result = wp_update_post( $post_data, true );
		}

		if ( is_wp_error( $result ) ) {
			$errors['general'] = $result->get_error_message();
			return false;
		}

		$evento_id = (int) $result;

		update_post_meta( $evento_id, CalibraTrack_Meta_Keys::EVENTO_TIPO_DOCUMENTO,        'ot' );
		update_post_meta( $evento_id, CalibraTrack_Meta_Keys::EVENTO_EQUIPO_ID,             $valores['equipo_id'] );
		update_post_meta( $evento_id, CalibraTrack_Meta_Keys::EVENTO_TECNICO_RESPONSABLE,   $valores['tecnico_id'] );
		update_post_meta( $evento_id, CalibraTrack_Meta_Keys::EVENTO_NUMERO_OT,             $valores['numero_ot'] );
		update_post_meta( $evento_id, CalibraTrack_Meta_Keys::EVENTO_TIPO,                  $valores['tipo'] );
		update_post_meta( $evento_id, CalibraTrack_Meta_Keys::EVENTO_FECHA_EJECUCION,       $valores['fecha_ejecucion'] );
		update_post_meta( $evento_id, CalibraTrack_Meta_Keys::EVENTO_PROXIMA_FECHA_CONTROL, $valores['proxima_fecha'] );
		update_post_meta( $evento_id, CalibraTrack_Meta_Keys::EVENTO_FALLA_REPORTADA,       $valores['falla_reportada'] );
		update_post_meta( $evento_id, CalibraTrack_Meta_Keys::EVENTO_DESCRIPCION_TRABAJO,   $valores['descripcion_trabajo'] );
		update_post_meta( $evento_id, CalibraTrack_Meta_Keys::EVENTO_OBSERVACIONES,         $valores['observaciones'] );
		update_post_meta( $evento_id, CalibraTrack_Meta_Keys::EVENTO_ESTADO_SERVICIO,       $valores['estado_servicio'] );

		if ( $valores['ingreso_relacionado_id'] > 0 ) {
			update_post_meta( $evento_id, CalibraTrack_Meta_Keys::EVENTO_INGRESO_RELACIONADO_ID, $valores['ingreso_relacionado_id'] );
		}

		// Calcular totales en el servidor — nunca confiar en el navegador.
		CalibraTrack_DB::save_items_costo( $evento_id, $valores['items'] );
		$totales = CalibraTrack_Helpers::calcular_totales_costo( $valores['items'] );
		update_post_meta( $evento_id, 'calibratrack_subtotal', $totales['subtotal'] );
		update_post_meta( $evento_id, 'calibratrack_iva',      $totales['iva'] );
		update_post_meta( $evento_id, 'calibratrack_total',    $totales['total'] );

		self::procesar_uploads( $evento_id );

		delete_transient( 'calibratrack_vigencia_' . $valores['equipo_id'] );

		if ( class_exists( 'CalibraTrack_PDF_Generator' ) ) {
			CalibraTrack_PDF_Generator::generate_orden_trabajo( $evento_id );

			if ( $es_nuevo && class_exists( 'CalibraTrack_Mailer' ) ) {
				CalibraTrack_Mailer::send_ot_a_cliente( $evento_id );
				CalibraTrack_Mailer::send_ot_a_tecnico( $evento_id );
			}

			if ( 'completado' === $valores['estado_servicio'] ) {
				CalibraTrack_PDF_Generator::generate_certificado( $evento_id );

				if ( 'completado' !== $estado_anterior && class_exists( 'CalibraTrack_Mailer' ) ) {
					CalibraTrack_Mailer::send_certificado_a_cliente( $evento_id );
				}
			}
		}

		return $evento_id;
	}

	// ─── PROCESAMIENTO DE FORMULARIO (EVENTO TÉCNICO) ────────────────────────────

	/**
	 * Valida y guarda un evento de servicio (flujo técnico).
	 * Retorna true si se guardó exitosamente, false si hay errores.
	 *
	 * @param int   $evento_id  0 para nuevo, ID para editar.
	 * @param array $errors     Array de errores (por referencia).
	 * @param array $valores    Valores del formulario (por referencia).
	 * @return bool
	 */
	private static function procesar_guardar_evento( $evento_id, &$errors, &$valores ) {
		$evento_id_original = $evento_id;

		if ( ! isset( $_POST['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ), 'calibratrack_tecnico_evento' ) ) {
			$errors['general'] = __( 'Error de seguridad. Recarga la página.', 'calibratrack' );
			return false;
		}

		// Técnico: nunca puede usar el flujo admin, ni siquiera crear desde cero.
		if ( ! current_user_can( 'manage_options' ) ) {
			if ( 0 === $evento_id ) {
				$errors['general'] = __( 'Sin permisos para esta operación.', 'calibratrack' );
				return false;
			}
			return self::procesar_guardar_evento_tecnico( $evento_id, $errors, $valores );
		}

		$estado_raw = isset( $_POST['estado_servicio'] ) ? sanitize_key( wp_unslash( $_POST['estado_servicio'] ) ) : 'en_proceso';
		$valores = array(
			'equipo_id'           => absint( isset( $_POST['equipo_id'] ) ? $_POST['equipo_id'] : 0 ),
			'numero_ot'           => sanitize_text_field( isset( $_POST['numero_ot'] ) ? wp_unslash( $_POST['numero_ot'] ) : '' ),
			'tipo'                => sanitize_key( isset( $_POST['tipo'] ) ? wp_unslash( $_POST['tipo'] ) : '' ),
			'fecha_ejecucion'     => sanitize_text_field( isset( $_POST['fecha_ejecucion'] ) ? wp_unslash( $_POST['fecha_ejecucion'] ) : '' ),
			'proxima_fecha'       => sanitize_text_field( isset( $_POST['proxima_fecha'] ) ? wp_unslash( $_POST['proxima_fecha'] ) : '' ),
			'falla_reportada'     => sanitize_textarea_field( isset( $_POST['falla_reportada'] ) ? wp_unslash( $_POST['falla_reportada'] ) : '' ),
			'descripcion_trabajo' => sanitize_textarea_field( isset( $_POST['descripcion_trabajo'] ) ? wp_unslash( $_POST['descripcion_trabajo'] ) : '' ),
			'observaciones'       => sanitize_textarea_field( isset( $_POST['observaciones'] ) ? wp_unslash( $_POST['observaciones'] ) : '' ),
			'garantia'            => isset( $_POST['garantia'] ) ? '1' : '0',
			'dias_garantia'       => absint( isset( $_POST['dias_garantia'] ) ? $_POST['dias_garantia'] : 0 ),
			'estado_servicio'     => in_array( $estado_raw, array( 'en_proceso', 'en_ejecucion', 'listo_revision', 'completado' ), true ) ? $estado_raw : 'en_proceso',
			'items'               => array(),
		);

		if ( $valores['equipo_id'] <= 0 ) {
			$errors['equipo_id'] = __( 'Seleccione un equipo.', 'calibratrack' );
		}
		if ( empty( $valores['numero_ot'] ) ) {
			$errors['numero_ot'] = __( 'El N° de OT es obligatorio.', 'calibratrack' );
		} elseif ( CalibraTrack_Helpers::numero_ot_existe( $valores['numero_ot'], $evento_id_original ) ) {
			$errors['numero_ot'] = __( 'Este N° de OT ya está en uso. Elige otro número.', 'calibratrack' );
		}
		$tipos_validos = array_keys( CalibraTrack_Helpers::get_tipos_evento() );
		if ( ! in_array( $valores['tipo'], $tipos_validos, true ) ) {
			$errors['tipo'] = __( 'Seleccione un tipo de servicio.', 'calibratrack' );
		}
		if ( empty( $valores['fecha_ejecucion'] ) || ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $valores['fecha_ejecucion'] ) ) {
			$errors['fecha_ejecucion'] = __( 'La fecha de ejecución es obligatoria.', 'calibratrack' );
		}

		$items_raw = isset( $_POST['calibratrack_items'] ) && is_array( $_POST['calibratrack_items'] )
			? $_POST['calibratrack_items']
			: array();
		foreach ( $items_raw as $item ) {
			$detalle  = sanitize_text_field( wp_unslash( isset( $item['detalle'] ) ? $item['detalle'] : '' ) );
			$cantidad = isset( $item['cantidad'] ) ? (float) $item['cantidad'] : 0;
			$precio   = isset( $item['precio_unitario'] ) ? (float) $item['precio_unitario'] : 0;
			if ( ! empty( $detalle ) ) {
				$valores['items'][] = array(
					'detalle'         => $detalle,
					'cantidad'        => $cantidad,
					'precio_unitario' => $precio,
				);
			}
		}

		if ( ! empty( $errors ) ) {
			return false;
		}

		$tipos_label = CalibraTrack_Helpers::get_tipos_evento();
		$post_title  = sprintf( '%s — %s', $valores['numero_ot'], isset( $tipos_label[ $valores['tipo'] ] ) ? $tipos_label[ $valores['tipo'] ] : $valores['tipo'] );

		if ( $evento_id > 0 ) {
			$post_data = array(
				'ID'          => $evento_id,
				'post_title'  => $post_title,
				'post_status' => 'publish',
				'post_type'   => 'evento_servicio',
			);
			$result = wp_update_post( $post_data, true );
		} else {
			$post_data = array(
				'post_title'  => $post_title,
				'post_type'   => 'evento_servicio',
				'post_status' => 'publish',
				'post_author' => get_current_user_id(),
			);
			$result = wp_insert_post( $post_data, true );
		}

		if ( is_wp_error( $result ) ) {
			$errors['general'] = $result->get_error_message();
			return false;
		}

		$evento_id = $result;

		update_post_meta( $evento_id, CalibraTrack_Meta_Keys::EVENTO_EQUIPO_ID,             $valores['equipo_id'] );
		update_post_meta( $evento_id, CalibraTrack_Meta_Keys::EVENTO_NUMERO_OT,             $valores['numero_ot'] );
		update_post_meta( $evento_id, CalibraTrack_Meta_Keys::EVENTO_TIPO,                  $valores['tipo'] );
		update_post_meta( $evento_id, CalibraTrack_Meta_Keys::EVENTO_FECHA_EJECUCION,       $valores['fecha_ejecucion'] );
		update_post_meta( $evento_id, CalibraTrack_Meta_Keys::EVENTO_PROXIMA_FECHA_CONTROL, $valores['proxima_fecha'] );
		update_post_meta( $evento_id, CalibraTrack_Meta_Keys::EVENTO_FALLA_REPORTADA,       $valores['falla_reportada'] );
		update_post_meta( $evento_id, CalibraTrack_Meta_Keys::EVENTO_DESCRIPCION_TRABAJO,   $valores['descripcion_trabajo'] );
		update_post_meta( $evento_id, CalibraTrack_Meta_Keys::EVENTO_OBSERVACIONES,         $valores['observaciones'] );
		update_post_meta( $evento_id, CalibraTrack_Meta_Keys::EVENTO_GARANTIA,              $valores['garantia'] );
		update_post_meta( $evento_id, CalibraTrack_Meta_Keys::EVENTO_DIAS_GARANTIA,         $valores['dias_garantia'] );
		update_post_meta( $evento_id, CalibraTrack_Meta_Keys::EVENTO_TECNICO_RESPONSABLE,   get_current_user_id() );

		if ( current_user_can( 'manage_options' ) ) {
			CalibraTrack_DB::save_items_costo( $evento_id, $valores['items'] );
			$totales = CalibraTrack_Helpers::calcular_totales_costo( $valores['items'] );
			update_post_meta( $evento_id, 'calibratrack_subtotal', $totales['subtotal'] );
			update_post_meta( $evento_id, 'calibratrack_iva',      $totales['iva'] );
			update_post_meta( $evento_id, 'calibratrack_total',    $totales['total'] );
		}

		self::procesar_uploads( $evento_id );

		delete_transient( 'calibratrack_vigencia_' . $valores['equipo_id'] );

		if ( class_exists( 'CalibraTrack_PDF_Generator' ) ) {
			$es_nuevo        = ( 0 === $evento_id_original );
			$estado_anterior = $es_nuevo ? '' : (string) get_post_meta( $evento_id, CalibraTrack_Meta_Keys::EVENTO_ESTADO_SERVICIO, true );
			$estado_nuevo    = $valores['estado_servicio'];

			$estado_guardar = $es_nuevo ? 'en_proceso' : $estado_nuevo;
			update_post_meta( $evento_id, CalibraTrack_Meta_Keys::EVENTO_ESTADO_SERVICIO, $estado_guardar );

			CalibraTrack_PDF_Generator::generate_orden_trabajo( $evento_id );

			if ( $es_nuevo && class_exists( 'CalibraTrack_Mailer' ) ) {
				CalibraTrack_Mailer::send_ot_a_cliente( $evento_id );
			}

			if ( 'completado' === $estado_guardar ) {
				CalibraTrack_PDF_Generator::generate_certificado( $evento_id );

				if ( 'completado' !== $estado_anterior && class_exists( 'CalibraTrack_Mailer' ) ) {
					CalibraTrack_Mailer::send_certificado_a_cliente( $evento_id );
				}
			}
		}

		return true;
	}

	/**
	 * Procesa los archivos subidos con el formulario.
	 *
	 * @param int $evento_id Post ID del evento.
	 * @return void
	 */
	private static function procesar_uploads( $evento_id ) {
		if ( ! function_exists( 'wp_handle_upload' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		if ( ! function_exists( 'wp_generate_attachment_metadata' ) ) {
			require_once ABSPATH . 'wp-admin/includes/image.php';
		}
		if ( ! function_exists( 'wp_insert_attachment' ) ) {
			require_once ABSPATH . 'wp-admin/includes/media.php';
		}

		// Fotos de evidencia.
		if ( ! empty( $_FILES['evidencia_fotografica']['name'][0] ) ) {
			$ids_existentes = json_decode( (string) get_post_meta( $evento_id, CalibraTrack_Meta_Keys::EVENTO_EVIDENCIA_FOTOGRAFICA, true ), true );
			if ( ! is_array( $ids_existentes ) ) {
				$ids_existentes = array();
			}

			$archivos = $_FILES['evidencia_fotografica'];
			$count    = count( $archivos['name'] );

			$nuevos_ids = array();
			for ( $i = 0; $i < $count; $i++ ) {
				if ( empty( $archivos['name'][ $i ] ) ) {
					continue;
				}
				// Saltar archivos con error de subida (incluye UPLOAD_ERR_INI_SIZE).
				if ( isset( $archivos['error'][ $i ] ) && UPLOAD_ERR_OK !== (int) $archivos['error'][ $i ] ) {
					continue;
				}
				$archivo_individual = array(
					'name'     => $archivos['name'][ $i ],
					'type'     => $archivos['type'][ $i ],
					'tmp_name' => $archivos['tmp_name'][ $i ],
					'error'    => $archivos['error'][ $i ],
					'size'     => $archivos['size'][ $i ],
				);
				$overrides = array(
					'test_form'     => false,
					'max_file_size' => 20 * 1024 * 1024, // 20 MB.
					'mimes'         => array(
						'jpg|jpeg' => 'image/jpeg',
						'png'      => 'image/png',
						'webp'     => 'image/webp',
					),
				);
				$upload = wp_handle_upload( $archivo_individual, $overrides );
				if ( isset( $upload['file'] ) ) {
					$att_id = wp_insert_attachment( array(
						'post_title'     => basename( $upload['file'] ),
						'post_mime_type' => $upload['type'],
						'post_status'    => 'inherit',
						'post_parent'    => $evento_id,
					), $upload['file'], $evento_id );
					if ( ! is_wp_error( $att_id ) ) {
						$meta = wp_generate_attachment_metadata( $att_id, $upload['file'] );
						wp_update_attachment_metadata( $att_id, $meta );
						$nuevos_ids[] = $att_id;
					}
				}
			}
			// Solo actualizar el meta si al menos un archivo se subió correctamente.
			if ( ! empty( $nuevos_ids ) ) {
				$ids_existentes = array_merge( $ids_existentes, $nuevos_ids );
				update_post_meta( $evento_id, CalibraTrack_Meta_Keys::EVENTO_EVIDENCIA_FOTOGRAFICA, wp_json_encode( $ids_existentes ) );
			}
		}

		// Documentos adjuntos (PDF).
		if ( ! empty( $_FILES['documentos_adjuntos']['name'][0] ) ) {
			$docs_existentes = json_decode( (string) get_post_meta( $evento_id, CalibraTrack_Meta_Keys::EVENTO_DOCUMENTOS_ADJUNTOS, true ), true );
			if ( ! is_array( $docs_existentes ) ) {
				$docs_existentes = array();
			}

			$archivos_doc = $_FILES['documentos_adjuntos'];
			$count_doc    = count( $archivos_doc['name'] );

			$nuevos_docs = array();
			for ( $i = 0; $i < $count_doc; $i++ ) {
				if ( empty( $archivos_doc['name'][ $i ] ) ) {
					continue;
				}
				if ( isset( $archivos_doc['error'][ $i ] ) && UPLOAD_ERR_OK !== (int) $archivos_doc['error'][ $i ] ) {
					continue;
				}
				$archivo_individual = array(
					'name'     => $archivos_doc['name'][ $i ],
					'type'     => $archivos_doc['type'][ $i ],
					'tmp_name' => $archivos_doc['tmp_name'][ $i ],
					'error'    => $archivos_doc['error'][ $i ],
					'size'     => $archivos_doc['size'][ $i ],
				);
				$overrides = array(
					'test_form'     => false,
					'max_file_size' => 20 * 1024 * 1024, // 20 MB.
					'mimes'         => array(
						'pdf' => 'application/pdf',
					),
				);
				$upload = wp_handle_upload( $archivo_individual, $overrides );
				if ( isset( $upload['file'] ) ) {
					$att_id = wp_insert_attachment( array(
						'post_title'     => basename( $upload['file'] ),
						'post_mime_type' => 'application/pdf',
						'post_status'    => 'inherit',
						'post_parent'    => $evento_id,
					), $upload['file'], $evento_id );
					if ( ! is_wp_error( $att_id ) ) {
						$nuevos_docs[] = $att_id;
					}
				}
			}
			// Solo actualizar el meta si al menos un documento se subió correctamente.
			if ( ! empty( $nuevos_docs ) ) {
				$docs_existentes = array_merge( $docs_existentes, $nuevos_docs );
				update_post_meta( $evento_id, CalibraTrack_Meta_Keys::EVENTO_DOCUMENTOS_ADJUNTOS, wp_json_encode( $docs_existentes ) );
			}
		}
	}

	/**
	 * Guarda únicamente los campos que el técnico puede editar:
	 * descripcion_trabajo, observaciones, estado_servicio (max listo_revision),
	 * evidencia fotográfica y documentos adjuntos (vía procesar_uploads).
	 *
	 * Los campos de costo, equipo, fechas y N° OT NO se tocan.
	 *
	 * @param int   $evento_id Post ID de la OT.
	 * @param array $errors    Por referencia.
	 * @param array $valores   Por referencia.
	 * @return bool
	 */
	private static function procesar_guardar_evento_tecnico( $evento_id, &$errors, &$valores ) {
		// Leer estado actual en BD como fallback.
		$estado_db = (string) get_post_meta( $evento_id, CalibraTrack_Meta_Keys::EVENTO_ESTADO_SERVICIO, true );
		if ( empty( $estado_db ) ) {
			$estado_db = 'en_proceso';
		}

		$estado_raw = isset( $_POST['estado_servicio'] )
			? sanitize_key( wp_unslash( $_POST['estado_servicio'] ) )
			: '';

		// Técnico solo puede asignar estos tres estados — jamás 'completado'.
		$estados_permitidos = array( 'en_proceso', 'en_ejecucion', 'listo_revision' );
		$estado_nuevo = in_array( $estado_raw, $estados_permitidos, true ) ? $estado_raw : $estado_db;

		$valores = array(
			'descripcion_trabajo' => sanitize_textarea_field( isset( $_POST['descripcion_trabajo'] ) ? wp_unslash( $_POST['descripcion_trabajo'] ) : '' ),
			'observaciones'       => sanitize_textarea_field( isset( $_POST['observaciones'] )       ? wp_unslash( $_POST['observaciones'] )       : '' ),
			'estado_servicio'     => $estado_nuevo,
		);

		update_post_meta( $evento_id, CalibraTrack_Meta_Keys::EVENTO_DESCRIPCION_TRABAJO, $valores['descripcion_trabajo'] );
		update_post_meta( $evento_id, CalibraTrack_Meta_Keys::EVENTO_OBSERVACIONES,       $valores['observaciones'] );
		update_post_meta( $evento_id, CalibraTrack_Meta_Keys::EVENTO_ESTADO_SERVICIO,     $valores['estado_servicio'] );

		// Notificar al admin solo cuando el estado CAMBIA a listo_revision.
		if ( 'listo_revision' === $estado_nuevo && 'listo_revision' !== $estado_db ) {
			if ( class_exists( 'CalibraTrack_Mailer' ) ) {
				CalibraTrack_Mailer::send_listo_revision_a_admin( $evento_id, get_current_user_id() );
			}
		}

		self::procesar_uploads( $evento_id );

		$equipo_id = (int) get_post_meta( $evento_id, CalibraTrack_Meta_Keys::EVENTO_EQUIPO_ID, true );
		if ( $equipo_id > 0 ) {
			delete_transient( 'calibratrack_vigencia_' . $equipo_id );
		}

		return true;
	}

	// ─── AJAX: CREACIÓN RÁPIDA ───────────────────────────────────────────────────

	/**
	 * Crea un cliente desde el modal de la OI. Solo administradores.
	 * Acción: calibratrack_quick_create_cliente (solo wp_ajax — requiere login).
	 *
	 * @return void (JSON + exit vía wp_send_json_*)
	 */
	public static function ajax_quick_create_cliente() {
		if ( ! isset( $_POST['_nonce'] ) || ! wp_verify_nonce(
			sanitize_text_field( wp_unslash( $_POST['_nonce'] ) ),
			'calibratrack_quick_create'
		) ) {
			wp_send_json_error( array( 'message' => __( 'Error de seguridad.', 'calibratrack' ) ), 403 );
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Sin permisos.', 'calibratrack' ) ), 403 );
		}

		$nombre_empresa  = sanitize_text_field( wp_unslash( isset( $_POST['nombre_empresa'] )   ? $_POST['nombre_empresa']   : '' ) );
		$rut             = sanitize_text_field( wp_unslash( isset( $_POST['rut'] )              ? $_POST['rut']              : '' ) );
		$contacto_nombre = sanitize_text_field( wp_unslash( isset( $_POST['contacto_nombre'] )  ? $_POST['contacto_nombre']  : '' ) );
		$telefono        = sanitize_text_field( wp_unslash( isset( $_POST['telefono'] )         ? $_POST['telefono']         : '' ) );
		$correo          = sanitize_email( wp_unslash( isset( $_POST['correo'] )                ? $_POST['correo']           : '' ) );
		$direccion       = sanitize_text_field( wp_unslash( isset( $_POST['direccion'] )        ? $_POST['direccion']        : '' ) );

		if ( empty( $nombre_empresa ) ) {
			wp_send_json_error( array( 'message' => __( 'El nombre de la empresa es obligatorio.', 'calibratrack' ) ), 422 );
		}

		if ( ! empty( $correo ) && ! is_email( $correo ) ) {
			wp_send_json_error( array( 'message' => __( 'El correo electrónico no es válido.', 'calibratrack' ) ), 422 );
		}

		$result = wp_insert_post( array(
			'post_title'  => $nombre_empresa,
			'post_type'   => 'cliente',
			'post_status' => 'publish',
		), true );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ), 500 );
		}

		update_post_meta( $result, CalibraTrack_Meta_Keys::CLIENTE_NOMBRE_EMPRESA, $nombre_empresa );
		update_post_meta( $result, CalibraTrack_Meta_Keys::CLIENTE_RUT,            $rut );
		update_post_meta( $result, CalibraTrack_Meta_Keys::CLIENTE_CONTACTO_NOMBRE,$contacto_nombre );
		update_post_meta( $result, CalibraTrack_Meta_Keys::CLIENTE_TELEFONO,       $telefono );
		update_post_meta( $result, CalibraTrack_Meta_Keys::CLIENTE_CORREO,         $correo );
		update_post_meta( $result, CalibraTrack_Meta_Keys::CLIENTE_DIRECCION,      $direccion );

		wp_send_json_success( array(
			'id'     => $result,
			'nombre' => $nombre_empresa,
		) );
	}

	/**
	 * Crea un equipo desde el modal de la OI. Solo administradores.
	 * Acción: calibratrack_quick_create_equipo (solo wp_ajax — requiere login).
	 *
	 * @return void (JSON + exit vía wp_send_json_*)
	 */
	public static function ajax_quick_create_equipo() {
		if ( ! isset( $_POST['_nonce'] ) || ! wp_verify_nonce(
			sanitize_text_field( wp_unslash( $_POST['_nonce'] ) ),
			'calibratrack_quick_create'
		) ) {
			wp_send_json_error( array( 'message' => __( 'Error de seguridad.', 'calibratrack' ) ), 403 );
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Sin permisos.', 'calibratrack' ) ), 403 );
		}

		$nombre      = sanitize_text_field( wp_unslash( isset( $_POST['nombre'] )      ? $_POST['nombre']      : '' ) );
		$serie       = sanitize_text_field( wp_unslash( isset( $_POST['serie'] )       ? $_POST['serie']       : '' ) );
		$marca       = sanitize_text_field( wp_unslash( isset( $_POST['marca'] )       ? $_POST['marca']       : '' ) );
		$modelo      = sanitize_text_field( wp_unslash( isset( $_POST['modelo'] )      ? $_POST['modelo']      : '' ) );
		$tipo_equipo = sanitize_key( wp_unslash( isset( $_POST['tipo_equipo'] )        ? $_POST['tipo_equipo'] : '' ) );
		$cliente_id  = absint( isset( $_POST['cliente_propietario'] ) ? $_POST['cliente_propietario'] : 0 );

		if ( empty( $nombre ) ) {
			wp_send_json_error( array( 'message' => __( 'El nombre del equipo es obligatorio.', 'calibratrack' ) ), 422 );
		}
		if ( empty( $serie ) ) {
			wp_send_json_error( array( 'message' => __( 'El número de serie es obligatorio.', 'calibratrack' ) ), 422 );
		}

		$tipos_validos = array_keys( CalibraTrack_Helpers::get_tipos_equipo() );
		if ( ! in_array( $tipo_equipo, $tipos_validos, true ) ) {
			wp_send_json_error( array( 'message' => __( 'Seleccione un tipo de equipo válido.', 'calibratrack' ) ), 422 );
		}

		// Verificar unicidad de serie.
		$existente = get_posts( array(
			'post_type'      => 'equipo',
			'post_status'    => 'publish',
			'posts_per_page' => 1,
			'fields'         => 'ids',
			'no_found_rows'  => true,
			'meta_query'     => array( array(
				'key'     => CalibraTrack_Meta_Keys::EQUIPO_SERIE,
				'value'   => $serie,
				'compare' => '=',
			) ),
		) );
		if ( ! empty( $existente ) ) {
			wp_send_json_error( array( 'message' => __( 'Ya existe un equipo con ese número de serie.', 'calibratrack' ) ), 422 );
		}

		$result = wp_insert_post( array(
			'post_title'  => $nombre,
			'post_type'   => 'equipo',
			'post_status' => 'publish',
		), true );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ), 500 );
		}

		update_post_meta( $result, CalibraTrack_Meta_Keys::EQUIPO_SERIE,               $serie );
		update_post_meta( $result, CalibraTrack_Meta_Keys::EQUIPO_MARCA,               $marca );
		update_post_meta( $result, CalibraTrack_Meta_Keys::EQUIPO_MODELO,              $modelo );
		update_post_meta( $result, CalibraTrack_Meta_Keys::EQUIPO_TIPO,                $tipo_equipo );
		update_post_meta( $result, CalibraTrack_Meta_Keys::EQUIPO_CLIENTE_PROPIETARIO, $cliente_id );
		update_post_meta( $result, CalibraTrack_Meta_Keys::EQUIPO_FECHA_INGRESO,       gmdate( 'Y-m-d' ) );

		$token = wp_generate_uuid4();
		update_post_meta( $result, CalibraTrack_Meta_Keys::EQUIPO_VERIFICACION_TOKEN, $token );

		$label = $serie;
		if ( ! empty( $marca ) )  { $label .= ' — ' . $marca; }
		if ( ! empty( $modelo ) ) { $label .= ' ' . $modelo; }

		wp_send_json_success( array(
			'id'         => $result,
			'label'      => $label,
			'cliente_id' => $cliente_id,
		) );
	}

	// ─── HANDLERS ADMIN: TÉCNICOS ────────────────────────────────────────────────

	/**
	 * Lista de técnicos para el panel de admin.
	 *
	 * @return void
	 */
	private static function handle_lista_tecnicos() {
		$success = isset( $_GET['guardado'] ) ? (int) $_GET['guardado'] : 0;
		$deleted  = isset( $_GET['eliminado'] ) && '1' === $_GET['eliminado'];

		$tecnicos = get_users( array(
			'role__in' => array( 'tecnico_calibracion', 'administrator' ),
			'orderby'  => 'display_name',
			'order'    => 'ASC',
		) );

		self::load_template( 'lista-tecnicos', array(
			'tecnicos' => $tecnicos,
			'success'  => $success,
			'deleted'  => $deleted,
		) );
	}

	/**
	 * Crea o edita un técnico desde el panel.
	 * $id = 0 → crear nuevo. $id > 0 → editar existente.
	 *
	 * @param int $id User ID del técnico (0 para nuevo).
	 * @return void
	 */
	private static function handle_tecnico( $id ) {
		$es_nuevo  = ( 0 === $id );
		$errors    = array();
		$valores   = array();
		$user      = null;

		if ( ! $es_nuevo ) {
			$user = get_userdata( $id );
			if ( ! $user ) {
				wp_redirect( home_url( '/panel/tecnicos/' ) );
				exit;
			}
			// Los administradores no se editan desde el panel de técnicos — usar wp-admin.
			if ( in_array( 'administrator', (array) $user->roles, true ) ) {
				wp_redirect( admin_url( 'user-edit.php?user_id=' . $id ) );
				exit;
			}
		}

		if ( 'POST' === $_SERVER['REQUEST_METHOD'] ) {
			if ( ! isset( $_POST['_wpnonce'] ) || ! wp_verify_nonce(
				sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ),
				'calibratrack_tecnico_form_' . $id
			) ) {
				$errors['general'] = __( 'Error de seguridad. Recarga la página.', 'calibratrack' );
			} else {
				$display_name  = sanitize_text_field( wp_unslash( isset( $_POST['display_name'] ) ? $_POST['display_name'] : '' ) );
				$user_email    = sanitize_email( wp_unslash( isset( $_POST['user_email'] ) ? $_POST['user_email'] : '' ) );
				$cargo         = sanitize_text_field( wp_unslash( isset( $_POST['calibratrack_cargo'] ) ? $_POST['calibratrack_cargo'] : '' ) );
				$password      = isset( $_POST['password'] ) ? wp_unslash( $_POST['password'] ) : '';
				$password2     = isset( $_POST['password2'] ) ? wp_unslash( $_POST['password2'] ) : '';
				$user_login    = $es_nuevo ? sanitize_user( wp_unslash( isset( $_POST['user_login'] ) ? $_POST['user_login'] : '' ) ) : '';

				if ( empty( $display_name ) ) {
					$errors['display_name'] = __( 'El nombre es obligatorio.', 'calibratrack' );
				}
				if ( empty( $user_email ) || ! is_email( $user_email ) ) {
					$errors['user_email'] = __( 'El correo electrónico es inválido.', 'calibratrack' );
				}
				if ( $es_nuevo && empty( $user_login ) ) {
					$errors['user_login'] = __( 'El nombre de usuario es obligatorio.', 'calibratrack' );
				}
				if ( $es_nuevo ) {
					if ( empty( $password ) ) {
						$errors['password'] = __( 'La contraseña es obligatoria.', 'calibratrack' );
					} elseif ( $password !== $password2 ) {
						$errors['password2'] = __( 'Las contraseñas no coinciden.', 'calibratrack' );
					}
					if ( empty( $errors['user_login'] ) && username_exists( $user_login ) ) {
						$errors['user_login'] = __( 'Ese nombre de usuario ya está en uso.', 'calibratrack' );
					}
					if ( empty( $errors['user_email'] ) && email_exists( $user_email ) ) {
						$errors['user_email'] = __( 'Ese correo ya tiene una cuenta registrada.', 'calibratrack' );
					}
				} else {
					if ( ! empty( $password ) && $password !== $password2 ) {
						$errors['password2'] = __( 'Las contraseñas no coinciden.', 'calibratrack' );
					}
					$otro = get_user_by( 'email', $user_email );
					if ( $otro && (int) $otro->ID !== $id ) {
						$errors['user_email'] = __( 'Ese correo ya tiene una cuenta registrada.', 'calibratrack' );
					}
				}

				if ( empty( $errors ) ) {
					if ( $es_nuevo ) {
						$user_data = array(
							'user_login'   => $user_login,
							'user_email'   => $user_email,
							'display_name' => $display_name,
							'user_pass'    => $password,
							'role'         => 'tecnico_calibracion',
						);
						$new_id = wp_insert_user( $user_data );
						if ( is_wp_error( $new_id ) ) {
							$errors['general'] = $new_id->get_error_message();
						} else {
							update_user_meta( $new_id, 'calibratrack_cargo', $cargo );
							self::procesar_firma_tecnico( $new_id );
							wp_redirect( home_url( '/panel/tecnicos/?guardado=1' ) );
							exit;
						}
					} else {
						$user_data = array(
							'ID'           => $id,
							'user_email'   => $user_email,
							'display_name' => $display_name,
						);
						if ( ! empty( $password ) ) {
							$user_data['user_pass'] = $password;
						}
						$result = wp_update_user( $user_data );
						if ( is_wp_error( $result ) ) {
							$errors['general'] = $result->get_error_message();
						} else {
							update_user_meta( $id, 'calibratrack_cargo', $cargo );
							self::procesar_firma_tecnico( $id );
							wp_redirect( home_url( '/panel/tecnicos/?guardado=2' ) );
							exit;
						}
					}
				}

				// Repoblar valores en caso de error.
				$valores = array(
					'display_name'       => $display_name,
					'user_email'         => $user_email,
					'user_login'         => $user_login,
					'calibratrack_cargo' => $cargo,
				);
			}
		}

		// Cargar valores del usuario existente si no hay POST o hubo error sin valores.
		if ( empty( $valores ) && ! $es_nuevo && $user ) {
			$valores = array(
				'display_name'       => $user->display_name,
				'user_email'         => $user->user_email,
				'user_login'         => $user->user_login,
				'calibratrack_cargo' => (string) get_user_meta( $id, 'calibratrack_cargo', true ),
			);
		}

		$firma_id  = $es_nuevo ? 0 : (int) get_user_meta( $id, 'calibratrack_firma_id', true );
		$firma_url = $firma_id ? wp_get_attachment_image_url( $firma_id, 'thumbnail' ) : '';

		self::load_template( 'form-tecnico', array(
			'errors'    => $errors,
			'valores'   => $valores,
			'es_nuevo'  => $es_nuevo,
			'tecnico_id'=> $id,
			'user'      => $user,
			'firma_url' => $firma_url,
		) );
	}

	/**
	 * Procesa la subida de la firma digital del técnico.
	 *
	 * @param int $user_id ID del usuario técnico.
	 * @return void
	 */
	private static function procesar_firma_tecnico( $user_id ) {
		if ( empty( $_FILES['calibratrack_firma_upload']['name'] ) ) {
			return;
		}
		if ( UPLOAD_ERR_OK !== (int) $_FILES['calibratrack_firma_upload']['error'] ) {
			return;
		}
		if ( $_FILES['calibratrack_firma_upload']['size'] > 1048576 ) {
			return; // Máx. 1 MB.
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

		$upload = wp_handle_upload( $_FILES['calibratrack_firma_upload'], $overrides );
		if ( ! isset( $upload['file'] ) ) {
			return;
		}

		$att_id = wp_insert_attachment( array(
			'post_title'     => 'Firma técnico ' . $user_id,
			'post_mime_type' => $upload['type'],
			'post_status'    => 'inherit',
		), $upload['file'] );

		if ( is_wp_error( $att_id ) ) {
			return;
		}

		$meta = wp_generate_attachment_metadata( $att_id, $upload['file'] );
		wp_update_attachment_metadata( $att_id, $meta );

		// Borrar firma anterior antes de actualizar.
		$firma_anterior = (int) get_user_meta( $user_id, 'calibratrack_firma_id', true );
		if ( $firma_anterior > 0 && $firma_anterior !== $att_id ) {
			wp_delete_attachment( $firma_anterior, true );
		}

		update_user_meta( $user_id, 'calibratrack_firma_id', $att_id );
	}

	// ─── AJAX: MENSAJERÍA ────────────────────────────────────────────────────────

	/**
	 * Handler AJAX para enviar un mensaje en el hilo de una OT.
	 * Acción: calibratrack_enviar_mensaje (solo wp_ajax — requiere login).
	 *
	 * Verifica:
	 *   1. Nonce específico de la OT: calibratrack_mensaje_{evento_id}
	 *   2. Que el usuario sea admin o el técnico asignado a la OT.
	 *   3. Sanitiza y recorta el mensaje.
	 *   4. Guarda en DB.
	 *   5. Envía email de notificación.
	 *   6. Devuelve JSON con los datos de la burbuja nueva.
	 *
	 * @return void (JSON + exit vía wp_send_json_*)
	 */
	public static function ajax_enviar_mensaje() {
		// 1. Validar nonce.
		$evento_id = isset( $_POST['evento_id'] ) ? (int) $_POST['evento_id'] : 0;
		if ( $evento_id <= 0 ) {
			wp_send_json_error( array( 'message' => __( 'OT no especificada.', 'calibratrack' ) ), 400 );
		}

		$nonce_action = 'calibratrack_mensaje_' . $evento_id;
		if ( ! isset( $_POST['_nonce_mensaje'] ) || ! wp_verify_nonce(
			sanitize_text_field( wp_unslash( $_POST['_nonce_mensaje'] ) ),
			$nonce_action
		) ) {
			wp_send_json_error( array( 'message' => __( 'Error de seguridad.', 'calibratrack' ) ), 403 );
		}

		// 2. Verificar que el usuario esté logueado y tenga acceso a esta OT.
		if ( ! is_user_logged_in() ) {
			wp_send_json_error( array( 'message' => __( 'No autenticado.', 'calibratrack' ) ), 401 );
		}

		$usuario_id = get_current_user_id();

		// El post debe ser un evento_servicio existente.
		if ( 'evento_servicio' !== get_post_type( $evento_id ) ) {
			wp_send_json_error( array( 'message' => __( 'OT no encontrada.', 'calibratrack' ) ), 404 );
		}

		$es_admin = current_user_can( 'manage_options' );

		if ( ! $es_admin ) {
			// El técnico solo puede escribir si es el técnico asignado a esta OT.
			$tecnico_asignado = (int) get_post_meta( $evento_id, CalibraTrack_Meta_Keys::EVENTO_TECNICO_RESPONSABLE, true );
			if ( $tecnico_asignado !== $usuario_id ) {
				wp_send_json_error( array( 'message' => __( 'Sin permisos para esta OT.', 'calibratrack' ) ), 403 );
			}
		}

		// 3. Sanitizar el mensaje.
		$texto_raw = isset( $_POST['ct_mensaje'] ) ? wp_unslash( $_POST['ct_mensaje'] ) : '';
		$texto     = sanitize_textarea_field( $texto_raw );
		// Truncar a 1000 chars.
		if ( mb_strlen( $texto, 'UTF-8' ) > 1000 ) {
			$texto = mb_substr( $texto, 0, 1000, 'UTF-8' );
		}

		if ( '' === trim( $texto ) ) {
			wp_send_json_error( array( 'message' => __( 'El mensaje no puede estar vacío.', 'calibratrack' ) ), 422 );
		}

		// 4. Guardar.
		if ( ! class_exists( 'CalibraTrack_Mensajes' ) ) {
			wp_send_json_error( array( 'message' => __( 'Error interno del servidor.', 'calibratrack' ) ), 500 );
		}

		$nuevo_id = CalibraTrack_Mensajes::save( $evento_id, $usuario_id, $texto );
		if ( false === $nuevo_id ) {
			wp_send_json_error( array( 'message' => __( 'No se pudo guardar el mensaje.', 'calibratrack' ) ), 500 );
		}

		// 5. Notificación por email.
		if ( class_exists( 'CalibraTrack_Mailer' ) ) {
			if ( $es_admin ) {
				CalibraTrack_Mailer::send_mensaje_a_tecnico( $evento_id, $usuario_id, $texto );
			} else {
				CalibraTrack_Mailer::send_mensaje_a_admin( $evento_id, $usuario_id, $texto );
			}
		}

		// 6. Construir respuesta con los datos de la burbuja nueva.
		$userdata   = get_userdata( $usuario_id );
		$roles      = ( $userdata && isset( $userdata->roles ) ) ? (array) $userdata->roles : array();
		$autor_rol  = in_array( 'administrator', $roles, true ) ? 'admin' : 'tecnico';
		$autor_nombre = $userdata ? $userdata->display_name : __( 'Usuario', 'calibratrack' );

		// Formatear fecha en zona horaria de WordPress.
		$fecha_obj     = new DateTime( 'now', new DateTimeZone( wp_timezone_string() ) );
		$fecha_formato = $fecha_obj->format( 'd/m/Y H:i' );

		wp_send_json_success( array(
			'mensaje' => array(
				'id'              => $nuevo_id,
				'autor_nombre'    => $autor_nombre,
				'autor_rol'       => $autor_rol,
				'mensaje'         => $texto,
				'fecha_formateada' => $fecha_formato,
			),
		) );
	}

	/**
	 * AJAX: devuelve los mensajes nuevos de una OT (polling).
	 *
	 * Recibe via GET: evento_id, ultimo_id, _nonce_mensaje.
	 * Retorna JSON con array de mensajes con id > ultimo_id.
	 *
	 * @return void (JSON + exit vía wp_send_json_*)
	 */
	public static function ajax_obtener_mensajes() {
		$evento_id = isset( $_GET['evento_id'] ) ? (int) $_GET['evento_id'] : 0;
		$ultimo_id = isset( $_GET['ultimo_id'] ) ? (int) $_GET['ultimo_id'] : 0;
		$nonce     = isset( $_GET['_nonce_mensaje'] ) ? sanitize_text_field( wp_unslash( $_GET['_nonce_mensaje'] ) ) : '';

		if ( ! wp_verify_nonce( $nonce, 'calibratrack_mensaje_' . $evento_id ) ) {
			wp_send_json_error( array( 'message' => 'Nonce inválido.' ), 403 );
		}

		if ( $evento_id <= 0 ) {
			wp_send_json_error( array( 'message' => 'OT inválida.' ), 400 );
		}

		$usuario_id = get_current_user_id();

		// Verificar acceso: admin o técnico asignado.
		$es_admin = current_user_can( 'manage_options' );
		if ( ! $es_admin ) {
			$tecnico_asignado = (int) get_post_meta( $evento_id, CalibraTrack_Meta_Keys::EVENTO_TECNICO_RESPONSABLE, true );
			if ( $tecnico_asignado !== $usuario_id ) {
				wp_send_json_error( array( 'message' => 'Sin acceso.' ), 403 );
			}
		}

		if ( ! class_exists( 'CalibraTrack_Mensajes' ) ) {
			wp_send_json_success( array( 'mensajes' => array() ) );
		}

		$nuevos = CalibraTrack_Mensajes::get_nuevos( $evento_id, $ultimo_id );

		// Marcar como leídos los nuevos que no son propios.
		if ( ! empty( $nuevos ) ) {
			CalibraTrack_Mensajes::marcar_leidos( $evento_id, $usuario_id );
		}

		// Formatear fechas para el cliente.
		$resultado = array();
		foreach ( $nuevos as $msj ) {
			$fecha_obj = DateTime::createFromFormat( 'Y-m-d H:i:s', $msj->fecha );
			$resultado[] = array(
				'id'               => (int) $msj->id,
				'autor_id'         => (int) $msj->autor_id,
				'autor_nombre'     => $msj->autor_nombre,
				'autor_rol'        => $msj->autor_rol,
				'mensaje'          => $msj->mensaje,
				'fecha_formateada' => $fecha_obj ? $fecha_obj->format( 'd/m/Y H:i' ) : $msj->fecha,
			);
		}

		wp_send_json_success( array( 'mensajes' => $resultado ) );
	}

	// ─── CARGADOR DE TEMPLATES ───────────────────────────────────────────────────

	/**
	 * Carga un template del panel unificado con las variables dadas.
	 *
	 * @param string $template Nombre del template (sin .php).
	 * @param array  $vars     Variables a inyectar en el template.
	 * @return void
	 */
	public static function load_template( $template, $vars = array() ) {
		$path = CALIBRATRACK_PLUGIN_DIR . 'templates/panel/' . $template . '.php';
		if ( ! file_exists( $path ) ) {
			wp_die( esc_html( 'Template no encontrado: ' . $template ) );
		}
		if ( ! empty( $vars ) ) {
			extract( $vars, EXTR_SKIP ); // phpcs:ignore WordPress.PHP.DontExtract.extract_extract
		}
		include $path;
		exit;
	}

	// ─── CLIENTE ─────────────────────────────────────────────────────────────────

	/**
	 * Crea o edita un cliente (CPT cliente) desde el panel.
	 * $id = 0 → crear nuevo. $id > 0 → editar existente.
	 *
	 * @param int $id Post ID del cliente (0 para nuevo).
	 * @return void
	 */
	private static function handle_cliente( $id ) {
		$es_nuevo = ( 0 === $id );
		$errors   = array();
		$valores  = array();
		$post     = null;

		if ( ! $es_nuevo ) {
			$post = get_post( $id );
			if ( ! $post || 'cliente' !== $post->post_type ) {
				wp_redirect( home_url( '/panel/clientes/' ) );
				exit;
			}
		}

		if ( 'POST' === $_SERVER['REQUEST_METHOD'] ) {
			if ( ! isset( $_POST['_wpnonce'] ) || ! wp_verify_nonce(
				sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ),
				'calibratrack_cliente_form_' . $id
			) ) {
				$errors['general'] = __( 'Error de seguridad. Recarga la página.', 'calibratrack' );
			} else {
				$nombre_empresa   = sanitize_text_field( wp_unslash( isset( $_POST['nombre_empresa'] ) ? $_POST['nombre_empresa'] : '' ) );
				$rut              = sanitize_text_field( wp_unslash( isset( $_POST['rut'] ) ? $_POST['rut'] : '' ) );
				$contacto_nombre  = sanitize_text_field( wp_unslash( isset( $_POST['contacto_nombre'] ) ? $_POST['contacto_nombre'] : '' ) );
				$telefono         = sanitize_text_field( wp_unslash( isset( $_POST['telefono'] ) ? $_POST['telefono'] : '' ) );
				$correo           = sanitize_email( wp_unslash( isset( $_POST['correo'] ) ? $_POST['correo'] : '' ) );
				$direccion        = sanitize_text_field( wp_unslash( isset( $_POST['direccion'] ) ? $_POST['direccion'] : '' ) );

				if ( empty( $nombre_empresa ) ) {
					$errors['nombre_empresa'] = __( 'El nombre de la empresa es obligatorio.', 'calibratrack' );
				}
				if ( ! empty( $correo ) && ! is_email( $correo ) ) {
					$errors['correo'] = __( 'El correo electrónico no es válido.', 'calibratrack' );
				}

				if ( empty( $errors ) ) {
					$post_data = array(
						'post_title'  => $nombre_empresa,
						'post_type'   => 'cliente',
						'post_status' => 'publish',
					);
					if ( ! $es_nuevo ) {
						$post_data['ID'] = $id;
						$result = wp_update_post( $post_data, true );
					} else {
						$result = wp_insert_post( $post_data, true );
					}

					if ( is_wp_error( $result ) ) {
						$errors['general'] = $result->get_error_message();
					} else {
						$saved_id = $es_nuevo ? $result : $id;
						update_post_meta( $saved_id, CalibraTrack_Meta_Keys::CLIENTE_NOMBRE_EMPRESA, $nombre_empresa );
						update_post_meta( $saved_id, CalibraTrack_Meta_Keys::CLIENTE_RUT, $rut );
						update_post_meta( $saved_id, CalibraTrack_Meta_Keys::CLIENTE_CONTACTO_NOMBRE, $contacto_nombre );
						update_post_meta( $saved_id, CalibraTrack_Meta_Keys::CLIENTE_TELEFONO, $telefono );
						update_post_meta( $saved_id, CalibraTrack_Meta_Keys::CLIENTE_CORREO, $correo );
						update_post_meta( $saved_id, CalibraTrack_Meta_Keys::CLIENTE_DIRECCION, $direccion );
						wp_redirect( home_url( '/panel/clientes/?guardado=' . ( $es_nuevo ? '1' : '2' ) ) );
						exit;
					}
				}

				$valores = array(
					'nombre_empresa'  => $nombre_empresa,
					'rut'             => $rut,
					'contacto_nombre' => $contacto_nombre,
					'telefono'        => $telefono,
					'correo'          => $correo,
					'direccion'       => $direccion,
				);
			}
		}

		if ( empty( $valores ) && ! $es_nuevo && $post ) {
			$valores = array(
				'nombre_empresa'  => (string) get_post_meta( $id, CalibraTrack_Meta_Keys::CLIENTE_NOMBRE_EMPRESA, true ),
				'rut'             => (string) get_post_meta( $id, CalibraTrack_Meta_Keys::CLIENTE_RUT, true ),
				'contacto_nombre' => (string) get_post_meta( $id, CalibraTrack_Meta_Keys::CLIENTE_CONTACTO_NOMBRE, true ),
				'telefono'        => (string) get_post_meta( $id, CalibraTrack_Meta_Keys::CLIENTE_TELEFONO, true ),
				'correo'          => (string) get_post_meta( $id, CalibraTrack_Meta_Keys::CLIENTE_CORREO, true ),
				'direccion'       => (string) get_post_meta( $id, CalibraTrack_Meta_Keys::CLIENTE_DIRECCION, true ),
			);
		}

		self::load_template( 'form-cliente', array(
			'errors'     => $errors,
			'valores'    => $valores,
			'es_nuevo'   => $es_nuevo,
			'cliente_id' => $id,
			'post'       => $post,
		) );
	}

	// ─── EQUIPO ──────────────────────────────────────────────────────────────────

	/**
	 * Crea o edita un equipo (CPT equipo) desde el panel.
	 * $id = 0 → crear nuevo. $id > 0 → editar existente.
	 *
	 * @param int $id Post ID del equipo (0 para nuevo).
	 * @return void
	 */
	private static function handle_equipo( $id ) {
		$es_nuevo = ( 0 === $id );
		$errors   = array();
		$valores  = array();
		$post     = null;

		if ( ! $es_nuevo ) {
			$post = get_post( $id );
			if ( ! $post || 'equipo' !== $post->post_type ) {
				wp_redirect( home_url( '/panel/equipos/' ) );
				exit;
			}
		}

		$tipos_equipo = CalibraTrack_Helpers::get_tipos_equipo();

		// Obtener lista de clientes para el selector.
		$clientes_ids = get_posts( array(
			'post_type'      => 'cliente',
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'orderby'        => 'title',
			'order'          => 'ASC',
			'no_found_rows'  => true,
		) );
		$clientes_lista = array();
		if ( ! empty( $clientes_ids ) ) {
			update_postmeta_cache( $clientes_ids );
			foreach ( $clientes_ids as $cid ) {
				$empresa = (string) get_post_meta( $cid, CalibraTrack_Meta_Keys::CLIENTE_NOMBRE_EMPRESA, true );
				$clientes_lista[ $cid ] = $empresa ?: get_the_title( $cid );
			}
		}

		if ( 'POST' === $_SERVER['REQUEST_METHOD'] ) {
			if ( ! isset( $_POST['_wpnonce'] ) || ! wp_verify_nonce(
				sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ),
				'calibratrack_equipo_form_' . $id
			) ) {
				$errors['general'] = __( 'Error de seguridad. Recarga la página.', 'calibratrack' );
			} else {
				$nombre           = sanitize_text_field( wp_unslash( isset( $_POST['nombre'] ) ? $_POST['nombre'] : '' ) );
				$serie            = sanitize_text_field( wp_unslash( isset( $_POST['serie'] ) ? $_POST['serie'] : '' ) );
				$marca            = sanitize_text_field( wp_unslash( isset( $_POST['marca'] ) ? $_POST['marca'] : '' ) );
				$modelo           = sanitize_text_field( wp_unslash( isset( $_POST['modelo'] ) ? $_POST['modelo'] : '' ) );
				$tipo_equipo      = sanitize_key( wp_unslash( isset( $_POST['tipo_equipo'] ) ? $_POST['tipo_equipo'] : '' ) );
				$cliente_id       = absint( isset( $_POST['cliente_propietario'] ) ? $_POST['cliente_propietario'] : 0 );
				$fecha_ingreso    = sanitize_text_field( wp_unslash( isset( $_POST['fecha_ingreso'] ) ? $_POST['fecha_ingreso'] : '' ) );

				if ( empty( $nombre ) ) {
					$errors['nombre'] = __( 'El nombre es obligatorio.', 'calibratrack' );
				}
				if ( empty( $serie ) ) {
					$errors['serie'] = __( 'El número de serie es obligatorio.', 'calibratrack' );
				}
				if ( ! isset( $tipos_equipo[ $tipo_equipo ] ) ) {
					$errors['tipo_equipo'] = __( 'Seleccione un tipo de equipo válido.', 'calibratrack' );
				}

				// Verificar unicidad de serie.
				if ( empty( $errors['serie'] ) ) {
					$serie_existente = get_posts( array(
						'post_type'      => 'equipo',
						'post_status'    => 'publish',
						'posts_per_page' => 1,
						'fields'         => 'ids',
						'no_found_rows'  => true,
						'meta_query'     => array( array(
							'key'     => CalibraTrack_Meta_Keys::EQUIPO_SERIE,
							'value'   => $serie,
							'compare' => '=',
						) ),
					) );
					if ( ! empty( $serie_existente ) && ( $es_nuevo || (int) $serie_existente[0] !== $id ) ) {
						$errors['serie'] = __( 'Ya existe un equipo con ese número de serie.', 'calibratrack' );
					}
				}

				if ( empty( $errors ) ) {
					$post_data = array(
						'post_title'  => $nombre,
						'post_type'   => 'equipo',
						'post_status' => 'publish',
					);
					if ( ! $es_nuevo ) {
						$post_data['ID'] = $id;
						$result = wp_update_post( $post_data, true );
					} else {
						$result = wp_insert_post( $post_data, true );
					}

					if ( is_wp_error( $result ) ) {
						$errors['general'] = $result->get_error_message();
					} else {
						$saved_id = $es_nuevo ? $result : $id;
						update_post_meta( $saved_id, CalibraTrack_Meta_Keys::EQUIPO_SERIE, $serie );
						update_post_meta( $saved_id, CalibraTrack_Meta_Keys::EQUIPO_MARCA, $marca );
						update_post_meta( $saved_id, CalibraTrack_Meta_Keys::EQUIPO_MODELO, $modelo );
						update_post_meta( $saved_id, CalibraTrack_Meta_Keys::EQUIPO_TIPO, $tipo_equipo );
						update_post_meta( $saved_id, CalibraTrack_Meta_Keys::EQUIPO_CLIENTE_PROPIETARIO, $cliente_id );
						if ( ! empty( $fecha_ingreso ) ) {
							update_post_meta( $saved_id, CalibraTrack_Meta_Keys::EQUIPO_FECHA_INGRESO, $fecha_ingreso );
						}
						// Generar token de verificación si es nuevo.
						if ( $es_nuevo ) {
							$token = wp_generate_uuid4();
							update_post_meta( $saved_id, CalibraTrack_Meta_Keys::EQUIPO_VERIFICACION_TOKEN, $token );
						}
						wp_redirect( home_url( '/panel/equipos/?guardado=' . ( $es_nuevo ? '1' : '2' ) ) );
						exit;
					}
				}

				$valores = array(
					'nombre'              => $nombre,
					'serie'               => $serie,
					'marca'               => $marca,
					'modelo'              => $modelo,
					'tipo_equipo'         => $tipo_equipo,
					'cliente_propietario' => $cliente_id,
					'fecha_ingreso'       => $fecha_ingreso,
				);
			}
		}

		if ( empty( $valores ) && ! $es_nuevo && $post ) {
			$valores = array(
				'nombre'              => $post->post_title,
				'serie'               => (string) get_post_meta( $id, CalibraTrack_Meta_Keys::EQUIPO_SERIE, true ),
				'marca'               => (string) get_post_meta( $id, CalibraTrack_Meta_Keys::EQUIPO_MARCA, true ),
				'modelo'              => (string) get_post_meta( $id, CalibraTrack_Meta_Keys::EQUIPO_MODELO, true ),
				'tipo_equipo'         => (string) get_post_meta( $id, CalibraTrack_Meta_Keys::EQUIPO_TIPO, true ),
				'cliente_propietario' => (int) get_post_meta( $id, CalibraTrack_Meta_Keys::EQUIPO_CLIENTE_PROPIETARIO, true ),
				'fecha_ingreso'       => (string) get_post_meta( $id, CalibraTrack_Meta_Keys::EQUIPO_FECHA_INGRESO, true ),
			);
		}

		self::load_template( 'form-equipo', array(
			'errors'         => $errors,
			'valores'        => $valores,
			'es_nuevo'       => $es_nuevo,
			'equipo_id'      => $id,
			'post'           => $post,
			'tipos_equipo'   => $tipos_equipo,
			'clientes_lista' => $clientes_lista,
		) );
	}
}
