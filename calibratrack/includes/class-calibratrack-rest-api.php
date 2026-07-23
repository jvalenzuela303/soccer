<?php
/**
 * Bootstrap del módulo REST API de CalibraTrack.
 *
 * DECISIÓN ARQUITECTÓNICA — Endpoint REST propio vs. REST nativo de CPT:
 *
 * Los CPTs tienen show_in_rest => true para el editor de bloques y para
 * que WP resuelva correctamente las capabilities REST. Sin embargo, el
 * endpoint público de verificación (/wp-json/calibratrack/v1/verificar/{serie})
 * es un endpoint PROPIO del plugin, no el endpoint genérico de WP REST (/wp/v2/).
 *
 * Razones:
 *   1. Control total sobre qué campos se exponen (nunca RUT, teléfono, correo).
 *   2. Implementación del rate limiting específico para la verificación pública.
 *   3. Lógica de negocio (cálculo de vigencia, join con evento más reciente)
 *      que sería compleja de lograr con el endpoint genérico.
 *   4. Desacoplamiento: si en el futuro el back-end cambia de CPT a tabla custom,
 *      el contrato de la API REST permanece igual para el cliente.
 *
 * ESTRUCTURA DE RUTAS (namespace: calibratrack/v1):
 *   GET /verificar/{serie}          — pública, rate limited, sin auth
 *   GET /equipos                    — privada, requiere autenticación
 *   GET /equipos/{id}               — privada
 *   GET /equipos/{id}/historial     — privada (lista eventos del equipo)
 *   POST /eventos                   — privada, tecnico o admin
 *   PUT  /eventos/{id}              — privada, solo autor o admin
 *   GET  /eventos/{id}/documentos   — privada, devuelve URL firmada/controlada del PDF
 *
 * @package CalibraTrack
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class CalibraTrack_REST_API
 */
class CalibraTrack_REST_API {

	/**
	 * Namespace de la API REST del plugin.
	 * NOTA: 'NAMESPACE' es palabra reservada en PHP — se usa 'REST_NAMESPACE' para evitar conflicto.
	 */
	const REST_NAMESPACE = 'calibratrack/v1';

	/**
	 * Máximo de requests por IP por minuto para el endpoint de verificación.
	 */
	const RATE_LIMIT_MAX = 20;

	/**
	 * Ventana de tiempo en segundos para el rate limiting.
	 */
	const RATE_LIMIT_WINDOW = 60;

	/**
	 * Inicializa los hooks de la REST API.
	 * Se llama desde calibratrack_init().
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );

		// §9 — Bloquear los endpoints REST genéricos de WP (/wp/v2/equipos, /wp/v2/clientes,
		// /wp/v2/eventos-servicio) para acceso no autenticado. Los tres CPTs tienen
		// show_in_rest => true (requerido por el editor de bloques y el admin), pero no deben
		// ser consultables públicamente — la única API pública es /calibratrack/v1/verificar/{serie}.
		add_filter( 'rest_pre_dispatch', array( __CLASS__, 'block_unauthenticated_cpt_rest' ), 10, 3 );
	}

	/**
	 * Registra todas las rutas REST del plugin.
	 *
	 * @return void
	 */
	public static function register_routes() {
		// Verificación pública por número de serie.
		// Acceso: público, rate limited. NO devuelve nombre_empresa (§9 — anti-enumeración).
		register_rest_route(
			self::REST_NAMESPACE,
			'/verificar/(?P<serie>[a-zA-Z0-9_\-\.]+)',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'handle_verificar' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'serie' => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
						'description'       => __( 'Número de serie del equipo a verificar.', 'calibratrack' ),
					),
				),
			)
		);

		// Verificación pública por token único (UUID).
		// Acceso: público, rate limited. Devuelve datos completos incluyendo nombre_empresa.
		// La URL con token es la que va en el QR y en el correo al cliente — no es enumerable.
		register_rest_route(
			self::REST_NAMESPACE,
			'/verificar-token/(?P<token>[a-f0-9\-]{36})',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'handle_verificar_token' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'token' => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
						'description'       => __( 'Token único de verificación del equipo.', 'calibratrack' ),
					),
				),
			)
		);
	}

	/**
	 * Obtiene la dirección IP del cliente de forma segura.
	 *
	 * Soporta hosting compartido detrás de proxy/CloudLinux.
	 * No confía ciegamente en X-Forwarded-For — solo lo usa si está configurado
	 * como encabezado de confianza (en hosting compartido, usualmente REMOTE_ADDR
	 * ya viene del proxy, así que se acepta HTTP_X_FORWARDED_FOR con precaución).
	 *
	 * @return string IP del cliente, o '0.0.0.0' si no se puede determinar.
	 */
	private static function get_client_ip() {
		// En hosting compartido cPanel/CloudLinux el proxy ya pone la IP real en REMOTE_ADDR.
		// X-Forwarded-For puede ser falsificado por el cliente, usarlo solo como fallback.
		$ip = '';

		if ( ! empty( $_SERVER['REMOTE_ADDR'] ) ) {
			$ip = sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) );
		}

		if ( empty( $ip ) || '127.0.0.1' === $ip || '::1' === $ip ) {
			// Detrás de proxy local: usar X-Forwarded-For solo como fallback.
			if ( ! empty( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) {
				$forwarded = sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_FORWARDED_FOR'] ) );
				// Puede ser una lista CSV — tomar el primero.
				$parts = explode( ',', $forwarded );
				$ip    = trim( $parts[0] );
			}
		}

		// Validar que sea una IP válida; si no, usar placeholder.
		if ( ! filter_var( $ip, FILTER_VALIDATE_IP ) ) {
			$ip = '0.0.0.0';
		}

		return $ip;
	}

	/**
	 * Verifica y aplica rate limiting por IP para el endpoint de verificación.
	 *
	 * Usa transients de WP como almacén de contadores (sin Redis requerido).
	 * Clave del transient: calibratrack_rl_{ip_hash}
	 * TTL: RATE_LIMIT_WINDOW segundos.
	 *
	 * @return bool True si el request está permitido, false si se debe bloquear.
	 */
	private static function check_rate_limit() {
		$ip          = self::get_client_ip();
		// Incluir get_current_blog_id() para evitar colisiones entre sitios cuando el
		// object cache es compartido entre múltiples sitios en hosting compartido/cPanel.
		$ip_hash     = md5( $ip . '|' . get_current_blog_id() ); // No almacenar IP directa en transients.
		$transient_key = 'calibratrack_rl_' . $ip_hash;

		$count = get_transient( $transient_key );

		if ( false === $count ) {
			// Primera request en esta ventana de tiempo.
			set_transient( $transient_key, 1, self::RATE_LIMIT_WINDOW );
			return true;
		}

		$count = (int) $count;

		if ( $count >= self::RATE_LIMIT_MAX ) {
			return false;
		}

		// Incrementar contador. No se puede usar set_transient con el TTL original
		// porque no conocemos el TTL restante. Usamos wp_cache si está disponible,
		// o un segundo transient auxiliar para el TTL.
		// Solución compatible con hosting compartido: actualizar el valor pero el
		// TTL se renueva. Esto es conservador y puede permitir ligeramente más de
		// RATE_LIMIT_MAX en la transición, pero es aceptable para el MVP.
		set_transient( $transient_key, $count + 1, self::RATE_LIMIT_WINDOW );

		return true;
	}

	/**
	 * Manejador del endpoint de verificación pública por NÚMERO DE SERIE.
	 *
	 * GET /wp-json/calibratrack/v1/verificar/{serie}
	 *
	 * Anti-enumeración: NO devuelve nombre_empresa. Cualquiera puede buscar por serie
	 * desde el formulario público, pero solo verá datos técnicos del equipo (marca,
	 * modelo, historial de fechas). Para ver el nombre de empresa, se necesita el token.
	 *
	 * @param WP_REST_Request $request Solicitud REST.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function handle_verificar( $request ) {
		if ( ! self::check_rate_limit() ) {
			return new \WP_Error(
				'rate_limit_exceeded',
				__( 'Demasiadas solicitudes. Intente nuevamente en un minuto.', 'calibratrack' ),
				array( 'status' => 429 )
			);
		}

		$serie = sanitize_text_field( $request->get_param( 'serie' ) );
		if ( empty( $serie ) ) {
			return new \WP_Error( 'serie_requerida', __( 'El número de serie es requerido.', 'calibratrack' ), array( 'status' => 400 ) );
		}

		$equipo_id = self::buscar_equipo_por_serie( $serie );
		if ( ! $equipo_id ) {
			return new \WP_Error( 'equipo_no_encontrado', __( 'Equipo no encontrado.', 'calibratrack' ), array( 'status' => 404 ) );
		}

		// Serie válida → armar respuesta SIN nombre_empresa (anti-enumeración).
		return new \WP_REST_Response( self::build_response( $equipo_id, false ), 200 );
	}

	/**
	 * Manejador del endpoint de verificación pública por TOKEN único (UUID).
	 *
	 * GET /wp-json/calibratrack/v1/verificar-token/{token}
	 *
	 * El token es un UUID v4 aleatorio e impredecible, generado al crear el equipo.
	 * Este es el endpoint que usa el QR del certificado y el enlace del correo al cliente.
	 * Devuelve datos completos incluyendo nombre_empresa.
	 *
	 * @param WP_REST_Request $request Solicitud REST.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function handle_verificar_token( $request ) {
		if ( ! self::check_rate_limit() ) {
			return new \WP_Error(
				'rate_limit_exceeded',
				__( 'Demasiadas solicitudes. Intente nuevamente en un minuto.', 'calibratrack' ),
				array( 'status' => 429 )
			);
		}

		$token = sanitize_text_field( $request->get_param( 'token' ) );
		if ( empty( $token ) ) {
			return new \WP_Error( 'token_requerido', __( 'Token de verificación requerido.', 'calibratrack' ), array( 'status' => 400 ) );
		}

		$equipo_id = self::buscar_equipo_por_token( $token );
		if ( ! $equipo_id ) {
			// Respuesta genérica — no revelar si el token nunca existió.
			return new \WP_Error( 'equipo_no_encontrado', __( 'Equipo no encontrado.', 'calibratrack' ), array( 'status' => 404 ) );
		}

		// Token válido → armar respuesta CON nombre_empresa.
		return new \WP_REST_Response( self::build_response( $equipo_id, true ), 200 );
	}

	/**
	 * Busca un equipo publicado por número de serie.
	 *
	 * @param string $serie Número de serie.
	 * @return int|null Post ID o null si no se encuentra.
	 */
	private static function buscar_equipo_por_serie( $serie ) {
		$query = new WP_Query( array(
			'post_type'      => CalibraTrack_CPT_Equipo::SLUG,
			'post_status'    => 'publish',
			'posts_per_page' => 1,
			'no_found_rows'  => true,
			'meta_query'     => array( array(
				'key'     => CalibraTrack_Meta_Keys::EQUIPO_SERIE,
				'value'   => $serie,
				'compare' => '=',
			) ),
		) );
		return $query->have_posts() ? (int) $query->posts[0]->ID : null;
	}

	/**
	 * Busca un equipo publicado por token de verificación.
	 *
	 * @param string $token UUID v4 del equipo.
	 * @return int|null Post ID o null si no se encuentra.
	 */
	private static function buscar_equipo_por_token( $token ) {
		$query = new WP_Query( array(
			'post_type'      => CalibraTrack_CPT_Equipo::SLUG,
			'post_status'    => 'publish',
			'posts_per_page' => 1,
			'no_found_rows'  => true,
			'meta_query'     => array( array(
				'key'     => CalibraTrack_Meta_Keys::EQUIPO_VERIFICACION_TOKEN,
				'value'   => $token,
				'compare' => '=',
			) ),
		) );
		return $query->have_posts() ? (int) $query->posts[0]->ID : null;
	}

	/**
	 * Construye el array de respuesta para un equipo dado.
	 *
	 * @param int  $equipo_id       Post ID del equipo.
	 * @param bool $incluir_empresa Si true, incluye nombre_empresa en la respuesta.
	 * @return array
	 */
	private static function build_response( $equipo_id, $incluir_empresa ) {
		$serie_guardada = (string) get_post_meta( $equipo_id, CalibraTrack_Meta_Keys::EQUIPO_SERIE, true );
		$marca          = (string) get_post_meta( $equipo_id, CalibraTrack_Meta_Keys::EQUIPO_MARCA, true );
		$modelo         = (string) get_post_meta( $equipo_id, CalibraTrack_Meta_Keys::EQUIPO_MODELO, true );
		$tipo_equipo    = (string) get_post_meta( $equipo_id, CalibraTrack_Meta_Keys::EQUIPO_TIPO, true );
		$cliente_id     = (int) get_post_meta( $equipo_id, CalibraTrack_Meta_Keys::EQUIPO_CLIENTE_PROPIETARIO, true );

		$tipos_equipo      = CalibraTrack_Helpers::get_tipos_equipo();
		$tipo_equipo_label = isset( $tipos_equipo[ $tipo_equipo ] ) ? $tipos_equipo[ $tipo_equipo ] : $tipo_equipo;

		// Estado de vigencia (con caché de transient).
		$transient_key   = 'calibratrack_vigencia_' . $equipo_id;
		$estado_vigencia = get_transient( $transient_key );
		if ( false === $estado_vigencia ) {
			$ultimo_evento   = CalibraTrack_DB::get_ultimo_evento( $equipo_id );
			$proxima_fecha   = $ultimo_evento ? (string) $ultimo_evento->proxima_fecha_control : '';
			$estado_vigencia = CalibraTrack_Helpers::calcular_estado_vigencia( $proxima_fecha );
			set_transient( $transient_key, $estado_vigencia, HOUR_IN_SECONDS );
		}

		// Historial de eventos.
		$historial_raw = CalibraTrack_DB::get_historial_eventos( $equipo_id, 50 );
		$historial     = array();
		$tipos_evento  = CalibraTrack_Helpers::get_tipos_evento();

		foreach ( $historial_raw as $evento ) {
			$tecnico_nombre = '';
			$tecnico_id_raw = (int) $evento->tecnico_id;
			if ( $tecnico_id_raw > 0 ) {
				$tecnico_user = get_user_by( 'ID', $tecnico_id_raw );
				if ( $tecnico_user ) {
					$tecnico_nombre = $tecnico_user->display_name;
				}
			}

			$tipo_slug  = (string) $evento->tipo_evento;
			$tipo_label = isset( $tipos_evento[ $tipo_slug ] ) ? $tipos_evento[ $tipo_slug ] : $tipo_slug;

			// Contar documentos adjuntos del técnico.
			$docs_raw     = get_post_meta( (int) $evento->post_id, CalibraTrack_Meta_Keys::EVENTO_DOCUMENTOS_ADJUNTOS, true );
			$docs_ids     = json_decode( (string) $docs_raw, true );
			$docs_ids     = is_array( $docs_ids ) ? array_filter( array_map( 'intval', $docs_ids ) ) : array();
			$docs_count   = count( $docs_ids );

			$historial[] = array(
				'evento_id'             => (int) $evento->post_id,
				'tipo'                  => $tipo_label,
				'numero_ot'             => (string) $evento->numero_ot,
				'fecha_ejecucion'       => (string) $evento->fecha_ejecucion,
				'proxima_fecha_control' => (string) $evento->proxima_fecha_control,
				'tecnico'               => $tecnico_nombre,
				'tiene_certificado'     => ( ! empty( $evento->certificado_pdf_id ) && (int) $evento->certificado_pdf_id > 0 ),
				'tiene_orden_trabajo'   => ( ! empty( $evento->orden_trabajo_pdf_id ) && (int) $evento->orden_trabajo_pdf_id > 0 ),
				'num_documentos'        => $docs_count,
			);
		}

		$data = array(
			'serie'           => esc_html( $serie_guardada ),
			'marca'           => esc_html( $marca ),
			'modelo'          => esc_html( $modelo ),
			'tipo_equipo'     => esc_html( $tipo_equipo_label ),
			'estado_vigencia' => $estado_vigencia,
			'historial'       => $historial,
		);

		// nombre_empresa solo se incluye cuando se accede por token (no enumerable).
		if ( $incluir_empresa && $cliente_id > 0 ) {
			$data['nombre_empresa'] = esc_html( (string) get_post_meta( $cliente_id, CalibraTrack_Meta_Keys::CLIENTE_NOMBRE_EMPRESA, true ) );
		}

		return $data;
	}

	/**
	 * Intercepta peticiones REST a los CPTs internos del plugin antes de que se
	 * despachen, y las bloquea si el usuario no está autenticado con la capability
	 * mínima necesaria.
	 *
	 * §9 — Los endpoints /wp/v2/equipos, /wp/v2/clientes y /wp/v2/eventos-servicio
	 * NO son la interfaz pública. Solo /calibratrack/v1/verificar/{serie} lo es.
	 *
	 * Esto protege contra:
	 *   1. Scraping masivo del catálogo de equipos via la REST API genérica.
	 *   2. Fuga de metafields del CPT equipo (cliente_propietario, etc.) a anónimos.
	 *   3. Enumeración de IDs de equipos/eventos/clientes.
	 *
	 * El filtro actúa solo en peticiones al namespace 'wp/v2' cuyo path comience
	 * por una de las tres bases REST de los CPTs propios.
	 *
	 * @param mixed            $result  Resultado previo (null si no hay interceptación previa).
	 * @param WP_REST_Server   $server  Servidor REST.
	 * @param WP_REST_Request  $request Petición en curso.
	 * @return mixed WP_Error si el acceso está bloqueado, o el $result original.
	 */
	public static function block_unauthenticated_cpt_rest( $result, $server, $request ) {
		// Solo intervenir si aún no hay un resultado de interceptación previo.
		if ( null !== $result ) {
			return $result;
		}

		// Rutas base REST de los CPTs propios del plugin (definidas en el CPT con rest_base).
		$rutas_privadas = array(
			'/wp/v2/equipos',
			'/wp/v2/clientes',
			'/wp/v2/eventos-servicio',
		);

		$ruta = $request->get_route();

		$es_ruta_privada = false;
		foreach ( $rutas_privadas as $base ) {
			if ( 0 === strpos( $ruta, $base ) ) {
				$es_ruta_privada = true;
				break;
			}
		}

		if ( ! $es_ruta_privada ) {
			return $result;
		}

		// Bloquear si el usuario no está autenticado.
		if ( ! is_user_logged_in() ) {
			return new \WP_Error(
				'rest_forbidden',
				__( 'No tiene permisos para acceder a este recurso.', 'calibratrack' ),
				array( 'status' => 401 )
			);
		}

		// Usuario autenticado: verificar que tiene al menos la capability 'read'
		// (cualquier rol del plugin la tiene). Los permisos granulares por CPT
		// se delegan al sistema de capabilities de WP (map_meta_cap en los CPTs).
		if ( ! current_user_can( 'read' ) ) {
			return new \WP_Error(
				'rest_forbidden',
				__( 'No tiene permisos para acceder a este recurso.', 'calibratrack' ),
				array( 'status' => 403 )
			);
		}

		return $result;
	}
}
