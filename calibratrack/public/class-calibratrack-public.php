<?php
/**
 * Bootstrap del módulo público de CalibraTrack.
 *
 * Gestiona todo lo que aparece en el front-end del sitio:
 *   - Página de verificación pública (/verificar/{serie}).
 *   - Shortcode [calibratrack_verificar] para insertar el buscador en cualquier página.
 *   - Enqueue de assets públicos (CSS/JS).
 *
 * Decisión D-10: Template PHP renderizado en servidor para el MVP.
 * El QR apunta a /verificar/{serie} que carga el template con wp_remote_get().
 *
 * @package CalibraTrack
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class CalibraTrack_Public
 */
class CalibraTrack_Public {

	/**
	 * Query var que identifica la página de verificación.
	 */
	const QUERY_VAR = 'calibratrack_verificar_page';

	/**
	 * Inicializa los hooks del módulo público.
	 * Se llama desde calibratrack_init() en el hook 'plugins_loaded'.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'init',              array( __CLASS__, 'register_rewrite_rules' ) );
		add_filter( 'query_vars',        array( __CLASS__, 'add_query_vars' ) );
		add_action( 'template_redirect', array( __CLASS__, 'handle_verificar_page' ) );
		add_action( 'template_redirect', array( __CLASS__, 'block_direct_equipo_access' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_public_assets' ) );
		add_shortcode( 'calibratrack_verificar', array( __CLASS__, 'shortcode_verificar' ) );
	}

	// ─── REWRITE RULES ──────────────────────────────────────────────────────────

	/**
	 * Registra la rewrite rule custom para /verificar/.
	 * La serie y la acción se pasan como query params (?serie=, ?accion=, ?evento_id=).
	 *
	 * @return void
	 */
	public static function register_rewrite_rules() {
		add_rewrite_rule(
			'^verificar/?$',
			'index.php?' . self::QUERY_VAR . '=1',
			'top'
		);
	}

	/**
	 * Registra las query vars custom para que WP las reconozca y no las elimine.
	 *
	 * @param array $vars Query vars existentes.
	 * @return array
	 */
	public static function add_query_vars( $vars ) {
		$vars[] = self::QUERY_VAR;
		return $vars;
	}

	// ─── TEMPLATE REDIRECT ───────────────────────────────────────────────────────

	/**
	 * Intercepta la URL /verificar/ y carga el template de verificación.
	 * También acepta el parámetro GET ?serie=X como fallback (URL compartible).
	 *
	 * @return void
	 */
	public static function handle_verificar_page() {
		// Verificar si estamos en la página de verificación (via rewrite rule).
		$es_pagina_verificar = (bool) get_query_var( self::QUERY_VAR, false );
		$request_uri         = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';

		// Fallback: detectar si la URL comienza con /verificar/ aunque las rewrite
		// rules aún no hayan sido flusheadas (hosting compartido, primera activación).
		if ( ! $es_pagina_verificar ) {
			if ( preg_match( '#^/verificar(/|$|\?)#i', $request_uri ) ) {
				$es_pagina_verificar = true;
			}
		}

		if ( ! $es_pagina_verificar ) {
			return;
		}

		// Compatibilidad hacia atrás: /verificar/{serie}/ → /verificar/?serie={serie}.
		// Necesario mientras el navegador tenga el JS antiguo en caché (producía URLs limpias).
		// Se usa 302 para que no quede en caché del navegador.
		$uri_path = strtok( $request_uri, '?' );
		if ( preg_match( '#^/verificar/([^/]+)/?$#i', $uri_path, $path_matches ) ) {
			$serie_from_path = rawurldecode( $path_matches[1] );
			wp_redirect( home_url( '/verificar/?serie=' . rawurlencode( $serie_from_path ) ), 302 );
			exit;
		}

		// Detectar si la acción es una descarga de certificado o documento adjunto.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$accion = isset( $_GET['accion'] ) ? sanitize_key( wp_unslash( $_GET['accion'] ) ) : '';
		if ( 'certificado' === $accion ) {
			self::handle_certificado_publico();
			return; // llama a exit internamente.
		}
		if ( 'documento' === $accion ) {
			self::handle_documento_publico();
			return; // llama a exit internamente.
		}

		// Detectar modo de búsqueda: por token (QR/correo) o por serie (formulario manual).
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$token = isset( $_GET['token'] ) ? sanitize_text_field( wp_unslash( $_GET['token'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$serie = isset( $_GET['serie'] ) ? trim( sanitize_text_field( wp_unslash( $_GET['serie'] ) ) ) : '';

		$api_data  = null;
		$api_error = '';
		$modo_token = false;

		if ( ! empty( $token ) ) {
			// Acceso por token (desde QR o correo) — endpoint completo con nombre_empresa.
			$modo_token = true;
			$api_data   = self::fetch_verificar_token_api( $token, $api_error );
		} elseif ( ! empty( $serie ) ) {
			// Acceso por serie (formulario público) — endpoint sin nombre_empresa.
			$api_data = self::fetch_verificar_api( $serie, $api_error );
		}

		// Inyectar variables para el template.
		$serie_sanitizada = $serie;

		// Cargar el template de verificación.
		$template_path = CALIBRATRACK_PLUGIN_DIR . 'templates/public/verificar.php';

		if ( file_exists( $template_path ) ) {
			// Encolar assets antes de cargar el template.
			self::enqueue_public_assets_now();

			// Variables disponibles en el template:
			// $serie_sanitizada  string       Serie buscada (ya sanitizada).
			// $api_data          array|null   Datos del equipo (null si no existe o sin búsqueda).
			// $api_error         string       Clave de error: '' | 'not_found' | 'rate_limit' | 'server_error'.
			include $template_path;
			exit;
		}
	}

	/**
	 * Intercepta el acceso directo a las URLs singulares del CPT equipo (/equipo/{slug}
	 * o /?equipo={slug}) y redirige a la página de verificación pública con la serie
	 * del equipo. Si no se puede obtener la serie, devuelve 404.
	 *
	 * El CPT equipo necesita public => true y publicly_queryable => true para que
	 * las rewrite rules de /verificar/{serie} funcionen correctamente. Sin embargo,
	 * la URL directa /equipo/{slug} no debe ser accesible: el tema podría renderizarla
	 * exponiendo metafields internos a través de template parts del tema.
	 *
	 * §9 — URLs de equipos no son la interfaz pública: la interfaz es /verificar/{serie}.
	 *
	 * @return void
	 */
	public static function block_direct_equipo_access() {
		if ( ! is_singular( CalibraTrack_CPT_Equipo::SLUG ) ) {
			return;
		}

		$equipo_id = get_the_ID();
		$serie     = $equipo_id ? (string) get_post_meta( $equipo_id, CalibraTrack_Meta_Keys::EQUIPO_SERIE, true ) : '';

		if ( ! empty( $serie ) ) {
			// Redirigir a la página de verificación pública con la serie del equipo.
			wp_redirect( home_url( '/verificar/?serie=' . rawurlencode( $serie ) ), 301 );
			exit;
		}

		// Sin serie: devolver 404 sin revelar que el post existe.
		global $wp_query;
		$wp_query->set_404();
		status_header( 404 );
		nocache_headers();
	}

	/**
	 * Consulta el endpoint REST /calibratrack/v1/verificar/{serie}.
	 * No devuelve nombre_empresa (anti-enumeración).
	 *
	 * @param string $serie  Número de serie a consultar.
	 * @param string $error  (por referencia) Clave de error si aplica.
	 * @return array|null
	 */
	private static function fetch_verificar_api( $serie, &$error ) {
		$error    = '';
		$request  = new WP_REST_Request( 'GET', '/calibratrack/v1/verificar/' . rawurlencode( $serie ) );
		$response = rest_do_request( $request );
		return self::parse_api_response( $response, $error );
	}

	/**
	 * Consulta el endpoint REST /calibratrack/v1/verificar-token/{token}.
	 * Devuelve datos completos incluyendo nombre_empresa.
	 *
	 * @param string $token  UUID de verificación del equipo.
	 * @param string $error  (por referencia) Clave de error si aplica.
	 * @return array|null
	 */
	private static function fetch_verificar_token_api( $token, &$error ) {
		$error    = '';
		$request  = new WP_REST_Request( 'GET', '/calibratrack/v1/verificar-token/' . rawurlencode( $token ) );
		$response = rest_do_request( $request );
		return self::parse_api_response( $response, $error );
	}

	/**
	 * Parsea una respuesta de la API REST interna.
	 *
	 * @param WP_REST_Response $response Respuesta de rest_do_request().
	 * @param string           $error    (por referencia) Clave de error.
	 * @return array|null
	 */
	private static function parse_api_response( $response, &$error ) {
		$http_code = (int) $response->get_status();
		$data      = $response->get_data();

		if ( 200 === $http_code && is_array( $data ) ) {
			return $data;
		}
		if ( 404 === $http_code ) {
			$error = 'not_found';
			return null;
		}
		if ( 429 === $http_code ) {
			$error = 'rate_limit';
			return null;
		}
		$error = 'server_error';
		return null;
	}

	// ─── DESCARGA PÚBLICA DE CERTIFICADO ────────────────────────────────────────

	/**
	 * Sirve el certificado PDF de un evento vía proxy PHP (descarga pública).
	 *
	 * Seguridad (§9):
	 *   - Verifica que el evento exista y esté publicado.
	 *   - Verifica que el evento pertenezca al equipo con la serie indicada en la URL.
	 *     La combinación serie+evento_id impide la enumeración arbitraria de PDFs.
	 *   - Serve via PHP proxy: nunca redirige al path real de uploads/.
	 *   - Content-Disposition: inline (visualización en browser) con nombre de archivo legible.
	 *
	 * @return void (llama a exit)
	 */
	private static function handle_certificado_publico() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$serie     = isset( $_GET['serie'] ) ? trim( sanitize_text_field( wp_unslash( $_GET['serie'] ) ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$evento_id = isset( $_GET['evento_id'] ) ? (int) $_GET['evento_id'] : 0;

		// Validaciones básicas.
		if ( empty( $serie ) || $evento_id <= 0 ) {
			wp_die( esc_html__( 'Solicitud inválida.', 'calibratrack' ), '', array( 'response' => 400 ) );
		}

		// Verificar que el evento existe y está publicado.
		$evento = get_post( $evento_id );
		if ( ! $evento || 'evento_servicio' !== $evento->post_type || 'publish' !== $evento->post_status ) {
			wp_die( esc_html__( 'Certificado no encontrado.', 'calibratrack' ), '', array( 'response' => 404 ) );
		}

		// Verificar que el evento pertenece al equipo con la serie indicada.
		$equipo_id = (int) get_post_meta( $evento_id, CalibraTrack_Meta_Keys::EVENTO_EQUIPO_ID, true );
		if ( $equipo_id <= 0 ) {
			wp_die( esc_html__( 'Certificado no encontrado.', 'calibratrack' ), '', array( 'response' => 404 ) );
		}

		$serie_equipo = (string) get_post_meta( $equipo_id, CalibraTrack_Meta_Keys::EQUIPO_SERIE, true );
		if ( $serie_equipo !== $serie ) {
			// Series no coinciden — 404 sin revelar si el evento existe.
			wp_die( esc_html__( 'Certificado no encontrado.', 'calibratrack' ), '', array( 'response' => 404 ) );
		}

		// Obtener attachment ID del certificado; intentar generar si no existe.
		$cert_id = (int) get_post_meta( $evento_id, CalibraTrack_Meta_Keys::EVENTO_CERTIFICADO_PDF, true );
		if ( ! $cert_id && class_exists( 'CalibraTrack_PDF_Generator' ) ) {
			$cert_id = CalibraTrack_PDF_Generator::generate_certificado( $evento_id );
		}

		if ( ! $cert_id ) {
			wp_die( esc_html__( 'El certificado aún no está disponible.', 'calibratrack' ), '', array( 'response' => 404 ) );
		}

		$file_path = get_attached_file( $cert_id );
		if ( ! $file_path || ! file_exists( $file_path ) ) {
			wp_die( esc_html__( 'Archivo no encontrado.', 'calibratrack' ), '', array( 'response' => 404 ) );
		}

		// Nombre de archivo legible para el usuario.
		$numero_ot = (string) get_post_meta( $evento_id, CalibraTrack_Meta_Keys::EVENTO_NUMERO_OT, true );
		$filename  = ! empty( $numero_ot )
			? 'certificado-' . sanitize_file_name( $numero_ot ) . '.pdf'
			: 'certificado-' . $evento_id . '.pdf';

		// Servir via proxy PHP.
		status_header( 200 ); // Anular el 404 que WordPress establece por defecto para esta URL.
		nocache_headers();
		header( 'Content-Type: application/pdf' );
		header( 'Content-Disposition: inline; filename="' . $filename . '"' );
		header( 'Content-Length: ' . filesize( $file_path ) );
		readfile( $file_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile
		exit;
	}

	/**
	 * Sirve un documento adjunto (PDF) de un evento vía proxy PHP (descarga pública).
	 *
	 * Seguridad:
	 *   - Verifica que el evento exista, esté publicado y pertenezca al equipo con la serie dada.
	 *   - El `doc_index` es el índice en el array EVENTO_DOCUMENTOS_ADJUNTOS — no un attachment ID
	 *     directo, para evitar que se adivinen IDs y se descarguen archivos de otros eventos.
	 *   - Solo sirve archivos PDF.
	 *
	 * @return void (llama a exit)
	 */
	private static function handle_documento_publico() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$serie     = isset( $_GET['serie'] ) ? trim( sanitize_text_field( wp_unslash( $_GET['serie'] ) ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$evento_id = isset( $_GET['evento_id'] ) ? (int) $_GET['evento_id'] : 0;
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$doc_index = isset( $_GET['doc_index'] ) ? (int) $_GET['doc_index'] : -1;

		if ( empty( $serie ) || $evento_id <= 0 || $doc_index < 0 ) {
			wp_die( esc_html__( 'Solicitud inválida.', 'calibratrack' ), '', array( 'response' => 400 ) );
		}

		// Verificar que el evento existe y está publicado.
		$evento = get_post( $evento_id );
		if ( ! $evento || 'evento_servicio' !== $evento->post_type || 'publish' !== $evento->post_status ) {
			wp_die( esc_html__( 'Documento no encontrado.', 'calibratrack' ), '', array( 'response' => 404 ) );
		}

		// Verificar que el evento pertenece al equipo con la serie indicada.
		$equipo_id    = (int) get_post_meta( $evento_id, CalibraTrack_Meta_Keys::EVENTO_EQUIPO_ID, true );
		$serie_equipo = $equipo_id > 0 ? (string) get_post_meta( $equipo_id, CalibraTrack_Meta_Keys::EQUIPO_SERIE, true ) : '';
		if ( $serie_equipo !== $serie ) {
			wp_die( esc_html__( 'Documento no encontrado.', 'calibratrack' ), '', array( 'response' => 404 ) );
		}

		// Obtener el array de documentos adjuntos.
		$docs_raw = get_post_meta( $evento_id, CalibraTrack_Meta_Keys::EVENTO_DOCUMENTOS_ADJUNTOS, true );
		$docs_ids = json_decode( (string) $docs_raw, true );
		if ( ! is_array( $docs_ids ) || ! isset( $docs_ids[ $doc_index ] ) ) {
			wp_die( esc_html__( 'Documento no encontrado.', 'calibratrack' ), '', array( 'response' => 404 ) );
		}

		$att_id = (int) $docs_ids[ $doc_index ];
		if ( $att_id <= 0 ) {
			wp_die( esc_html__( 'Documento no encontrado.', 'calibratrack' ), '', array( 'response' => 404 ) );
		}

		$file_path = get_attached_file( $att_id );
		if ( ! $file_path || ! file_exists( $file_path ) ) {
			wp_die( esc_html__( 'Archivo no encontrado.', 'calibratrack' ), '', array( 'response' => 404 ) );
		}

		// Solo servir PDFs.
		$mime = mime_content_type( $file_path );
		if ( 'application/pdf' !== $mime ) {
			wp_die( esc_html__( 'Tipo de archivo no permitido.', 'calibratrack' ), '', array( 'response' => 403 ) );
		}

		// Construir nombre de archivo sin duplicar la extensión.
		$titulo   = get_the_title( $att_id );
		$titulo   = preg_replace( '/\.pdf$/i', '', $titulo ); // quitar .pdf si ya viene en el título.
		$filename = sanitize_file_name( $titulo ) . '.pdf';

		status_header( 200 ); // Anular el 404 que WordPress establece por defecto para esta URL.
		nocache_headers();
		header( 'Content-Type: application/pdf' );
		header( 'Content-Disposition: inline; filename="' . $filename . '"' );
		header( 'Content-Length: ' . filesize( $file_path ) );
		readfile( $file_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile
		exit;
	}

	// ─── ASSETS ─────────────────────────────────────────────────────────────────

	/**
	 * Encola los assets públicos SOLO en páginas de verificación.
	 * Hook: wp_enqueue_scripts.
	 *
	 * @return void
	 */
	public static function enqueue_public_assets() {
		$es_pagina_verificar = (bool) get_query_var( self::QUERY_VAR, false );

		// Detectar shortcode en la página actual.
		global $post;
		$tiene_shortcode = ( $post instanceof WP_Post && has_shortcode( $post->post_content, 'calibratrack_verificar' ) );

		if ( ! $es_pagina_verificar && ! $tiene_shortcode ) {
			return;
		}

		self::enqueue_public_assets_now();
	}

	/**
	 * Encola los assets inmediatamente (sin chequeo de contexto).
	 * Se llama desde handle_verificar_page() y desde enqueue_public_assets().
	 *
	 * @return void
	 */
	private static function enqueue_public_assets_now() {
		wp_enqueue_style(
			'calibratrack-public',
			CALIBRATRACK_PLUGIN_URL . 'assets/css/public.css',
			array(),
			CALIBRATRACK_VERSION
		);

		wp_enqueue_script(
			'calibratrack-public',
			CALIBRATRACK_PLUGIN_URL . 'assets/js/public.js',
			array( 'jquery' ),
			CALIBRATRACK_VERSION,
			true // En el footer.
		);

		// Pasar la URL base de verificación al JS para el redirect al buscar.
		wp_localize_script(
			'calibratrack-public',
			'ctPublicData',
			array(
				'verificarUrl' => home_url( '/verificar/' ),
			)
		);
	}

	// ─── SHORTCODE ───────────────────────────────────────────────────────────────

	/**
	 * Shortcode [calibratrack_verificar].
	 *
	 * Renderiza el formulario de búsqueda embebido (y el resultado si hay ?serie= en la URL).
	 * Se puede insertar en cualquier página o entrada del editor de WordPress.
	 *
	 * Uso en el editor:
	 *   [calibratrack_verificar]
	 *
	 * No acepta atributos en el MVP.
	 *
	 * @param array  $atts    Atributos del shortcode (no usados).
	 * @param string $content Contenido interno (no usado).
	 * @return string HTML del buscador embebido.
	 */
	public static function shortcode_verificar( $atts, $content = '' ) {
		// Encolar assets para la página que tiene el shortcode.
		self::enqueue_public_assets_now();

		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$token = isset( $_GET['token'] ) ? sanitize_text_field( wp_unslash( $_GET['token'] ) ) : '';
		$serie = isset( $_GET['serie'] ) ? trim( sanitize_text_field( wp_unslash( $_GET['serie'] ) ) ) : '';
		// phpcs:enable

		$api_data   = null;
		$api_error  = '';
		$modo_token = false;

		if ( ! empty( $token ) ) {
			$modo_token = true;
			$api_data   = self::fetch_verificar_token_api( $token, $api_error );
		} elseif ( ! empty( $serie ) ) {
			$api_data = self::fetch_verificar_api( $serie, $api_error );
		}

		$serie_sanitizada = $serie;

		// Capturar output del template.
		ob_start();
		$template_path = CALIBRATRACK_PLUGIN_DIR . 'templates/public/verificar.php';
		if ( file_exists( $template_path ) ) {
			include $template_path;
		}
		return ob_get_clean();
	}
}
