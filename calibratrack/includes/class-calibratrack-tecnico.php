<?php
/**
 * Panel del técnico de CalibraTrack.
 *
 * Gestiona el frontend privado del técnico de calibración:
 *   - Login propio en /tecnico/login (wp_signon, sin wp-admin)
 *   - Dashboard, formulario de evento, listas de equipos/eventos
 *   - Bloqueo de wp-admin para el rol tecnico_calibracion
 *   - Generación y descarga de certificados PDF
 *
 * Patrón: igual que CalibraTrack_Public (rewrite rules + template_redirect).
 *
 * @package CalibraTrack
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class CalibraTrack_Tecnico
 */
class CalibraTrack_Tecnico {

	const QUERY_VAR       = 'calibratrack_tecnico_page';
	const QUERY_VAR_VISTA = 'calibratrack_tecnico_vista';
	const QUERY_VAR_ID    = 'calibratrack_tecnico_id';

	/**
	 * Inicializa todos los hooks del panel técnico.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'init',               array( __CLASS__, 'register_rewrite_rules' ) );
		add_filter( 'query_vars',         array( __CLASS__, 'add_query_vars' ) );
		add_action( 'template_redirect',  array( __CLASS__, 'handle_panel' ) );

		// Bloquear wp-admin para técnicos.
		add_action( 'admin_init',         array( __CLASS__, 'block_admin_for_tecnico' ) );

		// Ocultar barra de admin para técnicos.
		add_filter( 'show_admin_bar',     array( __CLASS__, 'hide_admin_bar_for_tecnico' ) );

		// Redirigir a /tecnico/ después del login si el usuario es técnico.
		add_filter( 'login_redirect',     array( __CLASS__, 'redirect_after_login' ), 10, 3 );
	}

	// ─── REWRITE RULES ──────────────────────────────────────────────────────────

	/**
	 * Registra las rewrite rules del panel técnico.
	 *
	 * @return void
	 */
	public static function register_rewrite_rules() {
		// /tecnico/login
		add_rewrite_rule(
			'^tecnico/login/?$',
			'index.php?' . self::QUERY_VAR . '=1&' . self::QUERY_VAR_VISTA . '=login',
			'top'
		);

		// /tecnico/salir
		add_rewrite_rule(
			'^tecnico/salir/?$',
			'index.php?' . self::QUERY_VAR . '=1&' . self::QUERY_VAR_VISTA . '=salir',
			'top'
		);

		// /tecnico/nuevo-evento
		add_rewrite_rule(
			'^tecnico/nuevo-evento/?$',
			'index.php?' . self::QUERY_VAR . '=1&' . self::QUERY_VAR_VISTA . '=nuevo-evento',
			'top'
		);

		// /tecnico/eventos
		add_rewrite_rule(
			'^tecnico/eventos/?$',
			'index.php?' . self::QUERY_VAR . '=1&' . self::QUERY_VAR_VISTA . '=eventos',
			'top'
		);

		// /tecnico/equipos
		add_rewrite_rule(
			'^tecnico/equipos/?$',
			'index.php?' . self::QUERY_VAR . '=1&' . self::QUERY_VAR_VISTA . '=equipos',
			'top'
		);

		// /tecnico/evento/{id}/certificado
		add_rewrite_rule(
			'^tecnico/evento/([0-9]+)/certificado/?$',
			'index.php?' . self::QUERY_VAR . '=1&' . self::QUERY_VAR_VISTA . '=certificado&' . self::QUERY_VAR_ID . '=$matches[1]',
			'top'
		);

		// /tecnico/evento/{id} (editar)
		add_rewrite_rule(
			'^tecnico/evento/([0-9]+)/?$',
			'index.php?' . self::QUERY_VAR . '=1&' . self::QUERY_VAR_VISTA . '=evento&' . self::QUERY_VAR_ID . '=$matches[1]',
			'top'
		);

		// /tecnico/perfil
		add_rewrite_rule(
			'^tecnico/perfil/?$',
			'index.php?' . self::QUERY_VAR . '=1&' . self::QUERY_VAR_VISTA . '=perfil',
			'top'
		);

		// /tecnico/ (dashboard)
		add_rewrite_rule(
			'^tecnico/?$',
			'index.php?' . self::QUERY_VAR . '=1&' . self::QUERY_VAR_VISTA . '=dashboard',
			'top'
		);
	}

	/**
	 * Agrega las query vars del panel técnico a WordPress.
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
	 * Detecta si estamos en el panel técnico y despacha al template correcto.
	 *
	 * @return void
	 */
	public static function handle_panel() {
		if ( ! (bool) get_query_var( self::QUERY_VAR, false ) ) {
			return;
		}

		$vista = (string) get_query_var( self::QUERY_VAR_VISTA, 'dashboard' );

		// Login y logout son las únicas vistas sin autenticación requerida.
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
			case 'nuevo-evento':
				// NUEVO FLUJO: Solo los administradores crean eventos (OIs y OTs) desde wp-admin.
				// Los técnicos externos son redirigidos al dashboard.
				if ( ! current_user_can( 'manage_options' ) ) {
					wp_redirect( home_url( '/tecnico/' ) );
					exit;
				}
				self::handle_nuevo_evento();
				break;
			case 'evento':
				self::handle_editar_evento();
				break;
			case 'eventos':
				self::load_template( 'lista-eventos' );
				break;
			case 'equipos':
				self::load_template( 'lista-equipos' );
				break;
			case 'certificado':
				self::handle_certificado();
				break;
			case 'perfil':
				self::handle_perfil();
				break;
			case 'dashboard':
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
		wp_redirect( home_url( '/tecnico/login/?redirect_to=' . rawurlencode( $current_url ) ) );
		exit;
	}

	/**
	 * Procesa el formulario de login o carga el template.
	 *
	 * @return void
	 */
	private static function handle_login() {
		// Si ya está logueado como técnico, redirigir al panel.
		if ( is_user_logged_in() && current_user_can( 'create_eventos_servicio' ) ) {
			wp_redirect( home_url( '/tecnico/' ) );
			exit;
		}

		$error        = '';
		$redirect_to  = isset( $_GET['redirect_to'] ) ? esc_url_raw( wp_unslash( $_GET['redirect_to'] ) ) : home_url( '/tecnico/' );

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
	 * Procesa el logout del técnico.
	 *
	 * @return void
	 */
	private static function handle_logout() {
		if ( is_user_logged_in() ) {
			wp_logout();
		}
		wp_redirect( home_url( '/tecnico/login/' ) );
		exit;
	}

	/**
	 * Redirige técnicos al panel después del login desde /wp-login.php.
	 *
	 * @param string  $redirect_to  URL de redirección solicitada.
	 * @param string  $requested    URL solicitada originalmente.
	 * @param WP_User $user         Usuario que acaba de iniciar sesión.
	 * @return string
	 */
	public static function redirect_after_login( $redirect_to, $requested, $user ) {
		if ( ! is_wp_error( $user ) && user_can( $user, 'create_eventos_servicio' ) && ! user_can( $user, 'manage_options' ) ) {
			return home_url( '/tecnico/' );
		}
		return $redirect_to;
	}

	// ─── BLOQUEO WP-ADMIN ────────────────────────────────────────────────────────

	/**
	 * Redirige a /tecnico/ si un técnico intenta acceder a wp-admin.
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
			wp_redirect( home_url( '/tecnico/' ) );
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
				wp_redirect( home_url( '/tecnico/eventos/?guardado=1' ) );
				exit;
			}
		} else {
			// Pre-rellenar el número OT con el siguiente correlativo.
			$valores['numero_ot'] = CalibraTrack_Helpers::generar_numero_ot();
		}

		self::load_template( 'nuevo-evento', array(
			'errors'  => $errors,
			'valores' => $valores,
			'equipos' => self::get_equipos_para_select(),
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
			wp_redirect( home_url( '/tecnico/eventos/' ) );
			exit;
		}

		// Verificar que el evento pertenece al técnico actual.
		$autor_id = (int) get_post_field( 'post_author', $evento_id );
		if ( $autor_id !== get_current_user_id() && ! current_user_can( 'edit_others_eventos_servicio' ) ) {
			wp_redirect( home_url( '/tecnico/eventos/' ) );
			exit;
		}

		// Verificar si el evento está completado — solo lectura, no se puede editar.
		$estado_actual = (string) get_post_meta( $evento_id, CalibraTrack_Meta_Keys::EVENTO_ESTADO_SERVICIO, true );
		$cert_id_actual = (int) get_post_meta( $evento_id, CalibraTrack_Meta_Keys::EVENTO_CERTIFICADO_PDF, true );
		$es_completado  = ( 'completado' === $estado_actual && $cert_id_actual > 0 );

		if ( $es_completado && 'POST' === $_SERVER['REQUEST_METHOD'] ) {
			// Bloquear intento de guardado de evento finalizado.
			$errors['general'] = __( 'Esta orden de trabajo ya está finalizada y no puede ser modificada.', 'calibratrack' );
		} elseif ( ! $es_completado && 'POST' === $_SERVER['REQUEST_METHOD'] ) {
			$resultado = self::procesar_guardar_evento( $evento_id, $errors, $valores );
			if ( $resultado ) {
				wp_redirect( home_url( '/tecnico/evento/' . $evento_id . '/?actualizado=1' ) );
				exit;
			}
		}

		if ( empty( $valores ) ) {
			// Pre-cargar valores desde el evento existente.
			$valores = self::cargar_valores_evento( $evento_id );
		}

		self::load_template( 'evento-detalle', array(
			'evento_id'    => $evento_id,
			'errors'       => $errors,
			'valores'      => $valores,
			'equipos'      => self::get_equipos_para_select(),
			'es_completado' => $es_completado,
		) );
	}

	/**
	 * Sirve el certificado PDF del evento vía proxy PHP.
	 * Verifica que el técnico sea el autor antes de servir.
	 *
	 * @return void
	 */
	private static function handle_certificado() {
		$evento_id = (int) get_query_var( self::QUERY_VAR_ID, 0 );

		if ( $evento_id <= 0 || 'evento_servicio' !== get_post_type( $evento_id ) ) {
			wp_die( esc_html__( 'Evento no encontrado.', 'calibratrack' ), '', array( 'response' => 404 ) );
		}

		// Verificar autoría.
		$autor_id = (int) get_post_field( 'post_author', $evento_id );
		if ( $autor_id !== get_current_user_id() && ! current_user_can( 'edit_others_eventos_servicio' ) ) {
			wp_die( esc_html__( 'Acceso denegado.', 'calibratrack' ), '', array( 'response' => 403 ) );
		}

		$cert_id = (int) get_post_meta( $evento_id, CalibraTrack_Meta_Keys::EVENTO_CERTIFICADO_PDF, true );

		// Si no hay PDF guardado, generarlo ahora.
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

	// ─── PROCESAMIENTO DE FORMULARIO ─────────────────────────────────────────────

	/**
	 * Valida y guarda un evento de servicio.
	 * Retorna true si se guardó exitosamente, false si hay errores.
	 *
	 * @param int   $evento_id  0 para nuevo, ID para editar.
	 * @param array $errors     Array de errores (por referencia).
	 * @param array $valores    Valores del formulario (por referencia).
	 * @return bool
	 */
	private static function procesar_guardar_evento( $evento_id, &$errors, &$valores ) {
		$evento_id_original = $evento_id; // Guardar antes de que se sobreescriba con el ID nuevo.
		// Verificar nonce.
		if ( ! isset( $_POST['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ), 'calibratrack_tecnico_evento' ) ) {
			$errors['general'] = __( 'Error de seguridad. Recarga la página.', 'calibratrack' );
			return false;
		}

		// Leer y sanitizar campos.
		$estado_raw = isset( $_POST['estado_servicio'] ) ? sanitize_key( wp_unslash( $_POST['estado_servicio'] ) ) : 'en_proceso';
		$valores = array(
			'equipo_id'            => absint( isset( $_POST['equipo_id'] ) ? $_POST['equipo_id'] : 0 ),
			'numero_ot'            => sanitize_text_field( isset( $_POST['numero_ot'] ) ? wp_unslash( $_POST['numero_ot'] ) : '' ),
			'tipo'                 => sanitize_key( isset( $_POST['tipo'] ) ? wp_unslash( $_POST['tipo'] ) : '' ),
			'fecha_ejecucion'      => sanitize_text_field( isset( $_POST['fecha_ejecucion'] ) ? wp_unslash( $_POST['fecha_ejecucion'] ) : '' ),
			'proxima_fecha'        => sanitize_text_field( isset( $_POST['proxima_fecha'] ) ? wp_unslash( $_POST['proxima_fecha'] ) : '' ),
			'falla_reportada'      => sanitize_textarea_field( isset( $_POST['falla_reportada'] ) ? wp_unslash( $_POST['falla_reportada'] ) : '' ),
			'descripcion_trabajo'  => sanitize_textarea_field( isset( $_POST['descripcion_trabajo'] ) ? wp_unslash( $_POST['descripcion_trabajo'] ) : '' ),
			'observaciones'        => sanitize_textarea_field( isset( $_POST['observaciones'] ) ? wp_unslash( $_POST['observaciones'] ) : '' ),
			'garantia'             => isset( $_POST['garantia'] ) ? '1' : '0',
			'dias_garantia'        => absint( isset( $_POST['dias_garantia'] ) ? $_POST['dias_garantia'] : 0 ),
			'estado_servicio'      => in_array( $estado_raw, array( 'en_proceso', 'completado' ), true ) ? $estado_raw : 'en_proceso',
			'items'                => array(),
		);

		// Validaciones obligatorias.
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

		// Ítems de costo.
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

		// Insertar o actualizar el post.
		$tipos_label  = CalibraTrack_Helpers::get_tipos_evento();
		$post_title   = sprintf( '%s — %s', $valores['numero_ot'], isset( $tipos_label[ $valores['tipo'] ] ) ? $tipos_label[ $valores['tipo'] ] : $valores['tipo'] );

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

		// Guardar meta fields.
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

		// Guardar ítems y calcular totales solo si el usuario es administrador.
		// Los técnicos no ven ni envían ítems de costo, no deben sobreescribir los existentes.
		if ( current_user_can( 'manage_options' ) ) {
			CalibraTrack_DB::save_items_costo( $evento_id, $valores['items'] );
			$totales = CalibraTrack_Helpers::calcular_totales_costo( $valores['items'] );
			update_post_meta( $evento_id, 'calibratrack_subtotal', $totales['subtotal'] );
			update_post_meta( $evento_id, 'calibratrack_iva',      $totales['iva'] );
			update_post_meta( $evento_id, 'calibratrack_total',    $totales['total'] );
		}

		// Subir archivos adjuntos.
		self::procesar_uploads( $evento_id );

		// Invalidar caché de vigencia del equipo.
		delete_transient( 'calibratrack_vigencia_' . $valores['equipo_id'] );

		// ── Flujo de dos correos y generación de PDFs ─────────────────────────────
		if ( class_exists( 'CalibraTrack_PDF_Generator' ) ) {
			$es_nuevo        = ( 0 === $evento_id_original );
			$estado_anterior = $es_nuevo ? '' : (string) get_post_meta( $evento_id, CalibraTrack_Meta_Keys::EVENTO_ESTADO_SERVICIO, true );
			$estado_nuevo    = $valores['estado_servicio'];

			// Guardar el estado (el nuevo evento siempre arranca como en_proceso).
			$estado_guardar = $es_nuevo ? 'en_proceso' : $estado_nuevo;
			update_post_meta( $evento_id, CalibraTrack_Meta_Keys::EVENTO_ESTADO_SERVICIO, $estado_guardar );

			// Siempre regenerar OT (datos pueden haber cambiado).
			CalibraTrack_PDF_Generator::generate_orden_trabajo( $evento_id );

			if ( $es_nuevo ) {
				// Email 1: aviso de recepción + OT al cliente.
				if ( class_exists( 'CalibraTrack_Mailer' ) ) {
					CalibraTrack_Mailer::send_ot_a_cliente( $evento_id );
				}
			}

			if ( 'completado' === $estado_guardar ) {
				// Generar certificado.
				CalibraTrack_PDF_Generator::generate_certificado( $evento_id );

				// Email 2: solo en la transición en_proceso → completado.
				if ( 'en_proceso' === $estado_anterior && class_exists( 'CalibraTrack_Mailer' ) ) {
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

			for ( $i = 0; $i < $count; $i++ ) {
				if ( empty( $archivos['name'][ $i ] ) ) {
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
					'test_form' => false,
					'mimes'     => array(
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
						$ids_existentes[] = $att_id;
					}
				}
			}
			update_post_meta( $evento_id, CalibraTrack_Meta_Keys::EVENTO_EVIDENCIA_FOTOGRAFICA, wp_json_encode( $ids_existentes ) );
		}

		// Documentos adjuntos (PDF).
		if ( ! empty( $_FILES['documentos_adjuntos']['name'][0] ) ) {
			$docs_existentes = json_decode( (string) get_post_meta( $evento_id, CalibraTrack_Meta_Keys::EVENTO_DOCUMENTOS_ADJUNTOS, true ), true );
			if ( ! is_array( $docs_existentes ) ) {
				$docs_existentes = array();
			}

			$archivos_doc = $_FILES['documentos_adjuntos'];
			$count_doc    = count( $archivos_doc['name'] );

			for ( $i = 0; $i < $count_doc; $i++ ) {
				if ( empty( $archivos_doc['name'][ $i ] ) ) {
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
					'test_form' => false,
					'mimes'     => array(
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
						$docs_existentes[] = $att_id;
					}
				}
			}
			update_post_meta( $evento_id, CalibraTrack_Meta_Keys::EVENTO_DOCUMENTOS_ADJUNTOS, wp_json_encode( $docs_existentes ) );
		}
	}

	// ─── PERFIL DEL TÉCNICO ──────────────────────────────────────────────────────

	/**
	 * Maneja la vista y guardado del perfil del técnico.
	 *
	 * @return void
	 */
	private static function handle_perfil() {
		$success = '';
		$error   = '';

		if ( 'POST' === $_SERVER['REQUEST_METHOD'] && isset( $_POST['ct_perfil_submit'] ) ) {
			if ( ! isset( $_POST['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ), 'calibratrack_tecnico_perfil' ) ) {
				$error = __( 'Error de seguridad. Recarga la página.', 'calibratrack' );
			} else {
				$user_id = get_current_user_id();

				// Guardar cargo.
				$cargo = sanitize_text_field( isset( $_POST['calibratrack_cargo'] ) ? wp_unslash( $_POST['calibratrack_cargo'] ) : '' );
				update_user_meta( $user_id, 'calibratrack_cargo', $cargo );

				$success = __( 'Perfil actualizado correctamente.', 'calibratrack' );
			}
		}

		self::load_template( 'perfil', array(
			'success' => $success,
			'error'   => $error,
		) );
	}

	// ─── HELPERS ─────────────────────────────────────────────────────────────────

	/**
	 * Devuelve todos los equipos para el select del formulario.
	 *
	 * @return WP_Post[]
	 */
	public static function get_equipos_para_select() {
		return get_posts( array(
			'post_type'      => 'equipo',
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'orderby'        => 'title',
			'order'          => 'ASC',
		) );
	}

	/**
	 * Carga los valores de un evento existente para pre-poblar el formulario.
	 *
	 * @param int $evento_id Post ID del evento.
	 * @return array
	 */
	public static function cargar_valores_evento( $evento_id ) {
		$items_raw = CalibraTrack_DB::get_items_costo( $evento_id );
		$items     = array();
		foreach ( $items_raw as $item ) {
			$items[] = array(
				'detalle'         => $item->detalle,
				'cantidad'        => $item->cantidad,
				'precio_unitario' => $item->precio_unitario,
			);
		}

		$estado = (string) get_post_meta( $evento_id, CalibraTrack_Meta_Keys::EVENTO_ESTADO_SERVICIO, true );

		return array(
			'equipo_id'           => (int) get_post_meta( $evento_id, CalibraTrack_Meta_Keys::EVENTO_EQUIPO_ID, true ),
			'numero_ot'           => (string) get_post_meta( $evento_id, CalibraTrack_Meta_Keys::EVENTO_NUMERO_OT, true ),
			'tipo'                => (string) get_post_meta( $evento_id, CalibraTrack_Meta_Keys::EVENTO_TIPO, true ),
			'fecha_ejecucion'     => (string) get_post_meta( $evento_id, CalibraTrack_Meta_Keys::EVENTO_FECHA_EJECUCION, true ),
			'proxima_fecha'       => (string) get_post_meta( $evento_id, CalibraTrack_Meta_Keys::EVENTO_PROXIMA_FECHA_CONTROL, true ),
			'falla_reportada'     => (string) get_post_meta( $evento_id, CalibraTrack_Meta_Keys::EVENTO_FALLA_REPORTADA, true ),
			'descripcion_trabajo' => (string) get_post_meta( $evento_id, CalibraTrack_Meta_Keys::EVENTO_DESCRIPCION_TRABAJO, true ),
			'observaciones'       => (string) get_post_meta( $evento_id, CalibraTrack_Meta_Keys::EVENTO_OBSERVACIONES, true ),
			'garantia'            => (string) get_post_meta( $evento_id, CalibraTrack_Meta_Keys::EVENTO_GARANTIA, true ),
			'dias_garantia'       => (int) get_post_meta( $evento_id, CalibraTrack_Meta_Keys::EVENTO_DIAS_GARANTIA, true ),
			'estado_servicio'     => '' !== $estado ? $estado : 'en_proceso',
			'items'               => $items,
		);
	}

	/**
	 * Carga un template del panel técnico con las variables dadas.
	 *
	 * @param string $template Nombre del template (sin .php).
	 * @param array  $vars     Variables a inyectar en el template.
	 * @return void
	 */
	public static function load_template( $template, $vars = array() ) {
		$path = CALIBRATRACK_PLUGIN_DIR . 'templates/tecnico/' . $template . '.php';
		if ( ! file_exists( $path ) ) {
			wp_die( esc_html( 'Template no encontrado: ' . $template ) );
		}
		// Inyectar variables en el scope del template.
		if ( ! empty( $vars ) ) {
			extract( $vars, EXTR_SKIP ); // phpcs:ignore WordPress.PHP.DontExtract.extract_extract
		}
		include $path;
		exit;
	}
}
