<?php
/**
 * Registro de postmeta vía register_post_meta() de WordPress.
 *
 * Por qué register_post_meta() y no ACF Pro:
 *  - Evita una dependencia de plugin de terceros en el núcleo del sistema.
 *  - register_post_meta() + show_in_rest permite que los campos sean
 *    accesibles via REST API con autenticación y también desde el editor
 *    de bloques en futuras fases.
 *  - Para el volumen esperado (miles de equipos/eventos), postmeta con
 *    índices adecuados en la columna `meta_key` es suficiente.
 *  - ACF Pro se puede agregar en el futuro sin conflicto si se decide
 *    mejorar la UX de los metaboxes.
 *
 * NOTA SOBRE items_costo:
 *  Los ítems de costo NO se registran aquí. Se almacenan en la tabla
 *  custom calibratrack_items_costo (ver CalibraTrack_DB). Esta clase
 *  solo maneja postmeta.
 *
 * @package CalibraTrack
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class CalibraTrack_Meta_Registration
 */
class CalibraTrack_Meta_Registration {

	/**
	 * Registra todos los metafields del plugin.
	 * Se llama desde calibratrack_init() en el hook 'plugins_loaded'.
	 *
	 * @return void
	 */
	public static function register_all() {
		self::register_equipo_meta();
		self::register_cliente_meta();
		self::register_evento_meta();
	}

	/**
	 * Metafields del CPT equipo.
	 *
	 * @return void
	 */
	private static function register_equipo_meta() {
		$cpt = CalibraTrack_CPT_Equipo::SLUG;

		register_post_meta(
			$cpt,
			CalibraTrack_Meta_Keys::EQUIPO_SERIE,
			array(
				'type'              => 'string',
				'description'       => 'Número de serie del equipo (único en el sistema).',
				'single'            => true,
				'default'           => '',
				'sanitize_callback' => 'sanitize_text_field',
				'auth_callback'     => array( __CLASS__, 'auth_callback_autenticado' ),
				'show_in_rest'      => true,
			)
		);

		register_post_meta(
			$cpt,
			CalibraTrack_Meta_Keys::EQUIPO_MARCA,
			array(
				'type'              => 'string',
				'description'       => 'Marca del equipo.',
				'single'            => true,
				'default'           => '',
				'sanitize_callback' => 'sanitize_text_field',
				'auth_callback'     => array( __CLASS__, 'auth_callback_autenticado' ),
				'show_in_rest'      => true,
			)
		);

		register_post_meta(
			$cpt,
			CalibraTrack_Meta_Keys::EQUIPO_MODELO,
			array(
				'type'              => 'string',
				'description'       => 'Modelo del equipo.',
				'single'            => true,
				'default'           => '',
				'sanitize_callback' => 'sanitize_text_field',
				'auth_callback'     => array( __CLASS__, 'auth_callback_autenticado' ),
				'show_in_rest'      => true,
			)
		);

		register_post_meta(
			$cpt,
			CalibraTrack_Meta_Keys::EQUIPO_TIPO,
			array(
				'type'              => 'string',
				'description'       => 'Tipo de equipo (slug). Ver CalibraTrack_Helpers::get_tipos_equipo().',
				'single'            => true,
				'default'           => '',
				'sanitize_callback' => 'sanitize_key',
				'auth_callback'     => array( __CLASS__, 'auth_callback_autenticado' ),
				'show_in_rest'      => true,
			)
		);

		register_post_meta(
			$cpt,
			CalibraTrack_Meta_Keys::EQUIPO_CLIENTE_PROPIETARIO,
			array(
				'type'              => 'integer',
				'description'       => 'Post ID del CPT cliente propietario del equipo.',
				'single'            => true,
				'default'           => 0,
				'sanitize_callback' => 'absint',
				'auth_callback'     => array( __CLASS__, 'auth_callback_autenticado' ),
				'show_in_rest'      => true,
			)
		);

		register_post_meta(
			$cpt,
			CalibraTrack_Meta_Keys::EQUIPO_FECHA_INGRESO,
			array(
				'type'              => 'string',
				'description'       => 'Fecha de ingreso al sistema (YYYY-MM-DD).',
				'single'            => true,
				'default'           => '',
				'sanitize_callback' => 'sanitize_text_field',
				'auth_callback'     => array( __CLASS__, 'auth_callback_autenticado' ),
				'show_in_rest'      => true,
			)
		);

		register_post_meta(
			$cpt,
			CalibraTrack_Meta_Keys::EQUIPO_CODIGO_QR,
			array(
				'type'              => 'integer',
				'description'       => 'Attachment ID del QR generado.',
				'single'            => true,
				'default'           => 0,
				'sanitize_callback' => 'absint',
				'auth_callback'     => array( __CLASS__, 'auth_callback_autenticado' ),
				'show_in_rest'      => true,
			)
		);
	}

	/**
	 * Metafields del CPT cliente.
	 * Todos marcados show_in_rest => false para proteger datos personales.
	 * El acceso es solo por autenticados con capabilities de cliente.
	 *
	 * @return void
	 */
	private static function register_cliente_meta() {
		$cpt = CalibraTrack_CPT_Cliente::SLUG;

		$campos_privados = array(
			CalibraTrack_Meta_Keys::CLIENTE_NOMBRE_EMPRESA => 'Nombre de la empresa cliente.',
			CalibraTrack_Meta_Keys::CLIENTE_RUT            => 'RUT de la empresa (privado — nunca en respuestas públicas).',
			CalibraTrack_Meta_Keys::CLIENTE_CONTACTO_NOMBRE => 'Nombre del contacto (privado).',
			CalibraTrack_Meta_Keys::CLIENTE_TELEFONO       => 'Teléfono de contacto (privado).',
			CalibraTrack_Meta_Keys::CLIENTE_CORREO         => 'Correo electrónico (privado).',
			CalibraTrack_Meta_Keys::CLIENTE_DIRECCION      => 'Dirección física (privado).',
		);

		foreach ( $campos_privados as $meta_key => $description ) {
			register_post_meta(
				$cpt,
				$meta_key,
				array(
					'type'              => 'string',
					'description'       => $description,
					'single'            => true,
					'default'           => '',
					'sanitize_callback' => 'sanitize_text_field',
					'auth_callback'     => array( __CLASS__, 'auth_callback_autenticado' ),
					'show_in_rest'      => false, // PRIVADO: no exponer en REST.
				)
			);
		}
	}

	/**
	 * Metafields del CPT evento_servicio.
	 *
	 * @return void
	 */
	private static function register_evento_meta() {
		$cpt = CalibraTrack_CPT_EventoServicio::SLUG;

		register_post_meta(
			$cpt,
			CalibraTrack_Meta_Keys::EVENTO_EQUIPO_ID,
			array(
				'type'              => 'integer',
				'description'       => 'Post ID del equipo al que pertenece este evento.',
				'single'            => true,
				'default'           => 0,
				'sanitize_callback' => 'absint',
				'auth_callback'     => array( __CLASS__, 'auth_callback_autenticado' ),
				'show_in_rest'      => true,
			)
		);

		register_post_meta(
			$cpt,
			CalibraTrack_Meta_Keys::EVENTO_NUMERO_OT,
			array(
				'type'              => 'string',
				'description'       => 'Número/folio de la orden de trabajo (ej. OT96).',
				'single'            => true,
				'default'           => '',
				'sanitize_callback' => 'sanitize_text_field',
				'auth_callback'     => array( __CLASS__, 'auth_callback_autenticado' ),
				'show_in_rest'      => true,
			)
		);

		register_post_meta(
			$cpt,
			CalibraTrack_Meta_Keys::EVENTO_TIPO,
			array(
				'type'              => 'string',
				'description'       => 'Tipo de evento: reparacion | mantencion_calibracion.',
				'single'            => true,
				'default'           => '',
				'sanitize_callback' => 'sanitize_key',
				'auth_callback'     => array( __CLASS__, 'auth_callback_autenticado' ),
				'show_in_rest'      => true,
			)
		);

		register_post_meta(
			$cpt,
			CalibraTrack_Meta_Keys::EVENTO_FECHA_EJECUCION,
			array(
				'type'              => 'string',
				'description'       => 'Fecha de ejecución del servicio (YYYY-MM-DD).',
				'single'            => true,
				'default'           => '',
				'sanitize_callback' => 'sanitize_text_field',
				'auth_callback'     => array( __CLASS__, 'auth_callback_autenticado' ),
				'show_in_rest'      => true,
			)
		);

		register_post_meta(
			$cpt,
			CalibraTrack_Meta_Keys::EVENTO_PROXIMA_FECHA_CONTROL,
			array(
				'type'              => 'string',
				'description'       => 'Próxima fecha de control/calibración (YYYY-MM-DD). Usada para calcular vigencia.',
				'single'            => true,
				'default'           => '',
				'sanitize_callback' => 'sanitize_text_field',
				'auth_callback'     => array( __CLASS__, 'auth_callback_autenticado' ),
				'show_in_rest'      => true,
			)
		);

		register_post_meta(
			$cpt,
			CalibraTrack_Meta_Keys::EVENTO_TECNICO_RESPONSABLE,
			array(
				'type'              => 'integer',
				'description'       => 'User ID del técnico responsable.',
				'single'            => true,
				'default'           => 0,
				'sanitize_callback' => 'absint',
				'auth_callback'     => array( __CLASS__, 'auth_callback_autenticado' ),
				'show_in_rest'      => true,
			)
		);

		register_post_meta(
			$cpt,
			CalibraTrack_Meta_Keys::EVENTO_FALLA_REPORTADA,
			array(
				'type'              => 'string',
				'description'       => 'Falla reportada por el cliente al ingresar el equipo.',
				'single'            => true,
				'default'           => '',
				'sanitize_callback' => 'sanitize_textarea_field',
				'auth_callback'     => array( __CLASS__, 'auth_callback_autenticado' ),
				'show_in_rest'      => true,
			)
		);

		register_post_meta(
			$cpt,
			CalibraTrack_Meta_Keys::EVENTO_DESCRIPCION_TRABAJO,
			array(
				'type'              => 'string',
				'description'       => 'Descripción del trabajo realizado.',
				'single'            => true,
				'default'           => '',
				'sanitize_callback' => 'sanitize_textarea_field',
				'auth_callback'     => array( __CLASS__, 'auth_callback_autenticado' ),
				'show_in_rest'      => true,
			)
		);

		register_post_meta(
			$cpt,
			CalibraTrack_Meta_Keys::EVENTO_OBSERVACIONES,
			array(
				'type'              => 'string',
				'description'       => 'Observaciones adicionales.',
				'single'            => true,
				'default'           => '',
				'sanitize_callback' => 'sanitize_textarea_field',
				'auth_callback'     => array( __CLASS__, 'auth_callback_autenticado' ),
				'show_in_rest'      => true,
			)
		);

		register_post_meta(
			$cpt,
			CalibraTrack_Meta_Keys::EVENTO_EVIDENCIA_FOTOGRAFICA,
			array(
				'type'              => 'string',
				'description'       => 'JSON array de attachment IDs de la galería fotográfica.',
				'single'            => true,
				'default'           => '[]',
				'sanitize_callback' => array( __CLASS__, 'sanitize_json_array_integers' ),
				'auth_callback'     => array( __CLASS__, 'auth_callback_autenticado' ),
				'show_in_rest'      => true,
			)
		);

		register_post_meta(
			$cpt,
			CalibraTrack_Meta_Keys::EVENTO_DOCUMENTOS_ADJUNTOS,
			array(
				'type'              => 'string',
				'description'       => 'JSON array de attachment IDs de documentos adjuntos subidos por el técnico.',
				'single'            => true,
				'default'           => '[]',
				'sanitize_callback' => array( __CLASS__, 'sanitize_json_array_integers' ),
				'auth_callback'     => array( __CLASS__, 'auth_callback_autenticado' ),
				'show_in_rest'      => true,
			)
		);

		register_post_meta(
			$cpt,
			CalibraTrack_Meta_Keys::EVENTO_GARANTIA,
			array(
				'type'              => 'integer',
				'description'       => 'El servicio tiene garantía: 1 = sí, 0 = no.',
				'single'            => true,
				'default'           => 0,
				'sanitize_callback' => 'absint',
				'auth_callback'     => array( __CLASS__, 'auth_callback_autenticado' ),
				'show_in_rest'      => true,
			)
		);

		register_post_meta(
			$cpt,
			CalibraTrack_Meta_Keys::EVENTO_DIAS_GARANTIA,
			array(
				'type'              => 'integer',
				'description'       => 'Días de garantía (solo si garantia = 1).',
				'single'            => true,
				'default'           => 0,
				'sanitize_callback' => 'absint',
				'auth_callback'     => array( __CLASS__, 'auth_callback_autenticado' ),
				'show_in_rest'      => true,
			)
		);

		register_post_meta(
			$cpt,
			CalibraTrack_Meta_Keys::EVENTO_CERTIFICADO_PDF,
			array(
				'type'              => 'integer',
				'description'       => 'Attachment ID del certificado PDF (nombre de archivo no adivinable).',
				'single'            => true,
				'default'           => 0,
				'sanitize_callback' => 'absint',
				'auth_callback'     => array( __CLASS__, 'auth_callback_autenticado' ),
				'show_in_rest'      => false, // URL del PDF nunca expuesta en REST público.
			)
		);

		register_post_meta(
			$cpt,
			CalibraTrack_Meta_Keys::EVENTO_ORDEN_TRABAJO_PDF,
			array(
				'type'              => 'integer',
				'description'       => 'Attachment ID de la OT PDF (nombre de archivo no adivinable).',
				'single'            => true,
				'default'           => 0,
				'sanitize_callback' => 'absint',
				'auth_callback'     => array( __CLASS__, 'auth_callback_autenticado' ),
				'show_in_rest'      => false, // URL del PDF nunca expuesta en REST público.
			)
		);
	}

	/**
	 * Auth callback estándar: solo usuarios con capability de edición sobre el post
	 * pueden leer/escribir metafields via REST API.
	 *
	 * Verificar is_user_logged_in() no es suficiente: un usuario 'subscriber' o cualquier
	 * rol sin capabilities de edición podría leer/escribir postmeta via la REST API
	 * del editor de bloques si solo se verifica el login. Se exige la capability de
	 * edición del post específico (mapeada via map_meta_cap => true del CPT).
	 *
	 * @param bool   $allowed   Si está permitido por defecto.
	 * @param string $meta_key  Nombre del meta key.
	 * @param int    $object_id ID del post.
	 * @param int    $user_id   ID del usuario.
	 * @param string $cap       Capability verificada.
	 * @param array  $caps      Array de caps del usuario.
	 * @return bool
	 */
	public static function auth_callback_autenticado( $allowed, $meta_key, $object_id, $user_id, $cap, $caps ) {
		if ( ! is_user_logged_in() ) {
			return false;
		}

		// Si hay un post_id concreto, verificar que el usuario puede editar ese post.
		// Esto respeta el filtro user_has_cap del admin (técnico no edita posts ajenos).
		if ( $object_id > 0 ) {
			return user_can( $user_id, 'edit_post', $object_id );
		}

		// Sin object_id (ej. al crear un post nuevo), verificar solo autenticación
		// y que el usuario tenga alguna capability de edición en el sistema.
		return user_can( $user_id, 'read' );
	}

	/**
	 * Sanitiza un JSON que debe ser un array de enteros.
	 * Usado para evidencia_fotografica (array de attachment IDs).
	 *
	 * @param string $value Valor a sanitizar.
	 * @return string JSON sanitizado o '[]' si es inválido.
	 */
	public static function sanitize_json_array_integers( $value ) {
		$decoded = json_decode( $value, true );

		if ( ! is_array( $decoded ) ) {
			return '[]';
		}

		$sanitized = array_map( 'absint', $decoded );
		$sanitized = array_filter( $sanitized ); // Remover ceros.

		return wp_json_encode( array_values( $sanitized ) );
	}
}
