<?php
/**
 * Generador de certificados PDF usando Dompdf.
 *
 * Decisión D-13: Dompdf con template HTML/CSS.
 * Decisión D-14: PDF se regenera al guardar el evento.
 * Decisión D-15: PDF se sirve vía proxy PHP (ver CalibraTrack_Tecnico::handle_certificado).
 *
 * @package CalibraTrack
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class CalibraTrack_PDF_Generator
 */
class CalibraTrack_PDF_Generator {

	/**
	 * Genera la Orden de Trabajo PDF para un evento de servicio.
	 * Se llama al crear el evento (estado en_proceso) — sirve como aviso de inicio al cliente.
	 *
	 * @param int $evento_id Post ID del evento_servicio.
	 * @return int|false Attachment ID, o false si falla.
	 */
	public static function generate_orden_trabajo( $evento_id ) {
		if ( ! self::libreria_disponible() ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( 'CalibraTrack_PDF_Generator: Dompdf no disponible. Ejecute composer install.' );
			return false;
		}

		$datos = self::recopilar_datos( $evento_id );
		if ( null === $datos ) {
			return false;
		}

		// Usar template de OT en lugar del de certificado.
		$template_path = CALIBRATRACK_PLUGIN_DIR . 'templates/pdf/orden-trabajo.php';
		if ( ! file_exists( $template_path ) ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( 'CalibraTrack_PDF_Generator: template OT no encontrado en ' . $template_path );
			return false;
		}

		ob_start();
		extract( $datos, EXTR_SKIP ); // phpcs:ignore WordPress.PHP.DontExtract.extract_extract
		include $template_path;
		$html = ob_get_clean();

		if ( empty( $html ) ) {
			return false;
		}

		$pdf_bytes = self::generar_pdf( $html );
		if ( false === $pdf_bytes ) {
			return false;
		}

		// Guardar como OT (prefijo y meta key distintos al certificado).
		$numero_ot = isset( $datos['numero_ot'] ) ? $datos['numero_ot'] : $evento_id;
		$filename  = sanitize_file_name( 'ot-' . $numero_ot . '-' . gmdate( 'Ymd' ) . '.pdf' );
		$upload    = wp_upload_bits( $filename, null, $pdf_bytes );

		if ( ! empty( $upload['error'] ) ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( 'CalibraTrack_PDF_Generator: wp_upload_bits OT falló — ' . $upload['error'] );
			return false;
		}

		if ( ! function_exists( 'wp_insert_attachment' ) ) {
			require_once ABSPATH . 'wp-admin/includes/media.php';
		}

		$att_id = wp_insert_attachment( array(
			'post_title'     => $filename,
			'post_content'   => '',
			'post_status'    => 'inherit',
			'post_mime_type' => 'application/pdf',
			'post_parent'    => $evento_id,
		), $upload['file'], $evento_id );

		if ( is_wp_error( $att_id ) || ! $att_id ) {
			@unlink( $upload['file'] ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			return false;
		}

		// Borrar OT anterior para evitar acumulación.
		$ot_anterior = (int) get_post_meta( $evento_id, CalibraTrack_Meta_Keys::EVENTO_ORDEN_TRABAJO_PDF, true );
		if ( $ot_anterior > 0 && $ot_anterior !== $att_id ) {
			wp_delete_attachment( $ot_anterior, true );
		}

		update_post_meta( $evento_id, CalibraTrack_Meta_Keys::EVENTO_ORDEN_TRABAJO_PDF, $att_id );
		return $att_id;
	}

	/**
	 * Genera el certificado PDF para un evento de servicio.
	 * Guarda el resultado como attachment en la Media Library.
	 *
	 * @param int $evento_id Post ID del evento_servicio.
	 * @return int|false Attachment ID, o false si falla.
	 */
	public static function generate_certificado( $evento_id ) {
		if ( ! self::libreria_disponible() ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( 'CalibraTrack_PDF_Generator: Dompdf no disponible. Ejecute composer install.' );
			return false;
		}

		$datos = self::recopilar_datos( $evento_id );
		if ( null === $datos ) {
			return false;
		}

		$html = self::renderizar_template( $datos );
		if ( empty( $html ) ) {
			return false;
		}

		$pdf_bytes = self::generar_pdf( $html );
		if ( false === $pdf_bytes ) {
			return false;
		}

		return self::guardar_attachment( $evento_id, $datos, $pdf_bytes );
	}

	/**
	 * Recopila todos los datos necesarios para el certificado.
	 *
	 * @param int $evento_id Post ID del evento.
	 * @return array|null Datos o null si falla.
	 */
	private static function recopilar_datos( $evento_id ) {
		$evento = get_post( $evento_id );
		if ( ! $evento || 'evento_servicio' !== $evento->post_type ) {
			return null;
		}

		$equipo_id = (int) get_post_meta( $evento_id, CalibraTrack_Meta_Keys::EVENTO_EQUIPO_ID, true );
		$equipo    = $equipo_id ? get_post( $equipo_id ) : null;

		$cliente_id = $equipo_id ? (int) get_post_meta( $equipo_id, CalibraTrack_Meta_Keys::EQUIPO_CLIENTE_PROPIETARIO, true ) : 0;
		$cliente    = $cliente_id ? get_post( $cliente_id ) : null;

		// Datos del técnico.
		$tecnico_id   = (int) get_post_meta( $evento_id, CalibraTrack_Meta_Keys::EVENTO_TECNICO_RESPONSABLE, true );
		$tecnico_user = $tecnico_id ? get_user_by( 'id', $tecnico_id ) : null;
		$tecnico_nombre = $tecnico_user ? $tecnico_user->display_name : '';
		$tecnico_cargo  = $tecnico_user ? (string) get_user_meta( $tecnico_id, 'calibratrack_cargo', true ) : '';
		if ( empty( $tecnico_cargo ) ) {
			$tecnico_cargo = __( 'Servicio Técnico', 'calibratrack' );
		}

		// Firma del técnico.
		$firma_id   = $tecnico_user ? (int) get_user_meta( $tecnico_id, 'calibratrack_firma_id', true ) : 0;
		$firma_path = $firma_id ? get_attached_file( $firma_id ) : '';
		$firma_src  = self::path_to_base64_src( $firma_path );

		// Logo de la empresa.
		$logo_id   = (int) get_option( 'calibratrack_logo_id', 0 );
		$logo_path = $logo_id ? get_attached_file( $logo_id ) : '';
		$logo_src  = self::path_to_base64_src( $logo_path );

		// QR del equipo — apunta a la URL con token (impredecible).
		$qr_att_id = $equipo_id ? (int) get_post_meta( $equipo_id, CalibraTrack_Meta_Keys::EQUIPO_CODIGO_QR, true ) : 0;
		$qr_path   = $qr_att_id ? get_attached_file( $qr_att_id ) : '';
		$qr_src    = self::path_to_base64_src( $qr_path );

		// URL de verificación con token (para mostrar como texto bajo el QR).
		$token_equipo = $equipo_id ? (string) get_post_meta( $equipo_id, CalibraTrack_Meta_Keys::EQUIPO_VERIFICACION_TOKEN, true ) : '';
		$url_verificar = $token_equipo ? home_url( '/verificar/?token=' . rawurlencode( $token_equipo ) ) : '';

		// Tipo de servicio.
		$tipo      = (string) get_post_meta( $evento_id, CalibraTrack_Meta_Keys::EVENTO_TIPO, true );
		$tipos_map = CalibraTrack_Helpers::get_tipos_evento();
		$tipo_label = isset( $tipos_map[ $tipo ] ) ? $tipos_map[ $tipo ] : $tipo;

		// Tipo de equipo.
		$tipo_equipo_slug  = $equipo_id ? (string) get_post_meta( $equipo_id, CalibraTrack_Meta_Keys::EQUIPO_TIPO, true ) : '';
		$tipos_equipo_map  = CalibraTrack_Helpers::get_tipos_equipo();
		$tipo_equipo_label = isset( $tipos_equipo_map[ $tipo_equipo_slug ] ) ? $tipos_equipo_map[ $tipo_equipo_slug ] : strtoupper( $tipo_equipo_slug );

		// Fecha formateada.
		$fecha_raw   = (string) get_post_meta( $evento_id, CalibraTrack_Meta_Keys::EVENTO_FECHA_EJECUCION, true );
		$fecha_obj   = $fecha_raw ? DateTime::createFromFormat( 'Y-m-d', $fecha_raw ) : null;
		$fecha_label = $fecha_obj ? $fecha_obj->format( 'd-m-Y' ) : $fecha_raw;

		// Garantía.
		$garantia      = '1' === (string) get_post_meta( $evento_id, CalibraTrack_Meta_Keys::EVENTO_GARANTIA, true );
		$dias_garantia = (int) get_post_meta( $evento_id, CalibraTrack_Meta_Keys::EVENTO_DIAS_GARANTIA, true );

		// Título del certificado según tipo.
		if ( 'reparacion' === $tipo ) {
			$titulo_cert = __( 'CERTIFICADO DE REPARACIÓN', 'calibratrack' );
		} else {
			$titulo_cert = __( 'CERTIFICADO DE MANTENCIÓN Y/O CALIBRACIÓN', 'calibratrack' );
		}

		return array(
			'titulo_cert'           => $titulo_cert,
			'numero_ot'             => (string) get_post_meta( $evento_id, CalibraTrack_Meta_Keys::EVENTO_NUMERO_OT, true ),
			'fecha'                 => $fecha_label,
			'proxima_fecha'         => (string) get_post_meta( $evento_id, CalibraTrack_Meta_Keys::EVENTO_PROXIMA_FECHA_CONTROL, true ),
			'tipo_label'            => $tipo_label,
			// Empresa emisora.
			'empresa_nombre'        => (string) get_option( 'calibratrack_empresa_nombre', 'TrueTech SpA' ),
			'empresa_rut'           => (string) get_option( 'calibratrack_empresa_rut', '' ),
			'empresa_direccion'     => (string) get_option( 'calibratrack_empresa_direccion', '' ),
			'empresa_telefono'      => (string) get_option( 'calibratrack_empresa_telefono', '' ),
			'logo_path'             => $logo_path,
			'logo_src'              => $logo_src,
			'color_primario'        => (string) get_option( 'calibratrack_pdf_color_primario', '#00AEEF' ),
			// Cliente.
			'cliente_nombre'        => $cliente ? (string) get_post_meta( $cliente_id, CalibraTrack_Meta_Keys::CLIENTE_NOMBRE_EMPRESA, true ) : '',
			'cliente_rut'           => $cliente ? (string) get_post_meta( $cliente_id, CalibraTrack_Meta_Keys::CLIENTE_RUT, true ) : '',
			'cliente_contacto'      => $cliente ? (string) get_post_meta( $cliente_id, CalibraTrack_Meta_Keys::CLIENTE_CONTACTO_NOMBRE, true ) : '',
			'cliente_telefono'      => $cliente ? (string) get_post_meta( $cliente_id, CalibraTrack_Meta_Keys::CLIENTE_TELEFONO, true ) : '',
			'cliente_direccion'     => $cliente ? (string) get_post_meta( $cliente_id, CalibraTrack_Meta_Keys::CLIENTE_DIRECCION, true ) : '',
			'cliente_correo'        => $cliente ? (string) get_post_meta( $cliente_id, CalibraTrack_Meta_Keys::CLIENTE_CORREO, true ) : '',
			// Equipo.
			'equipo_marca'          => $equipo_id ? (string) get_post_meta( $equipo_id, CalibraTrack_Meta_Keys::EQUIPO_MARCA, true ) : '',
			'equipo_modelo'         => $equipo_id ? (string) get_post_meta( $equipo_id, CalibraTrack_Meta_Keys::EQUIPO_MODELO, true ) : '',
			'equipo_serie'          => $equipo_id ? (string) get_post_meta( $equipo_id, CalibraTrack_Meta_Keys::EQUIPO_SERIE, true ) : '',
			'equipo_tipo'           => $tipo_equipo_label,
			// Servicio.
			'garantia'              => $garantia,
			'dias_garantia'         => $dias_garantia,
			'falla_reportada'       => (string) get_post_meta( $evento_id, CalibraTrack_Meta_Keys::EVENTO_FALLA_REPORTADA, true ),
			'descripcion_trabajo'   => (string) get_post_meta( $evento_id, CalibraTrack_Meta_Keys::EVENTO_DESCRIPCION_TRABAJO, true ),
			'observaciones'         => (string) get_post_meta( $evento_id, CalibraTrack_Meta_Keys::EVENTO_OBSERVACIONES, true ),
			// Técnico y firma.
			'tecnico_nombre'        => $tecnico_nombre,
			'tecnico_cargo'         => $tecnico_cargo,
			'firma_path'            => $firma_path,
			'firma_src'             => $firma_src,
			// QR y verificación.
			'qr_src'                => $qr_src,
			'url_verificar'         => $url_verificar,
		);
	}

	/**
	 * Renderiza el template HTML del certificado.
	 *
	 * @param array $datos Datos del certificado.
	 * @return string HTML renderizado.
	 */
	private static function renderizar_template( $datos ) {
		$template_path = CALIBRATRACK_PLUGIN_DIR . 'templates/pdf/certificado.php';
		if ( ! file_exists( $template_path ) ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( 'CalibraTrack_PDF_Generator: template no encontrado en ' . $template_path );
			return '';
		}
		ob_start();
		extract( $datos, EXTR_SKIP ); // phpcs:ignore WordPress.PHP.DontExtract.extract_extract
		include $template_path;
		return ob_get_clean();
	}

	/**
	 * Convierte HTML a PDF usando Dompdf.
	 *
	 * @param string $html HTML del certificado.
	 * @return string|false Bytes PDF, o false si falla.
	 */
	private static function generar_pdf( $html ) {
		try {
			$autoload = CALIBRATRACK_PLUGIN_DIR . 'vendor/autoload.php';
			if ( file_exists( $autoload ) ) {
				require_once $autoload;
			}

			$options = new \Dompdf\Options();
			$options->set( 'isHtml5ParserEnabled', true );
			$options->set( 'isRemoteEnabled', false ); // No cargar URLs remotas (seguridad).
			$options->set( 'defaultFont', 'helvetica' );
			$options->set( 'chroot', CALIBRATRACK_PLUGIN_DIR ); // Limitar acceso a archivos locales.

			$dompdf = new \Dompdf\Dompdf( $options );
			$dompdf->loadHtml( $html, 'UTF-8' );
			$dompdf->setPaper( 'letter', 'portrait' );
			$dompdf->render();

			return $dompdf->output();

		} catch ( Exception $e ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( 'CalibraTrack_PDF_Generator: error Dompdf — ' . $e->getMessage() );
			return false;
		}
	}

	/**
	 * Guarda el PDF como attachment en la Media Library.
	 * Borra el anterior para evitar attachments huérfanos.
	 *
	 * @param int    $evento_id  Post ID del evento.
	 * @param array  $datos      Datos del certificado (para el nombre de archivo).
	 * @param string $pdf_bytes  Bytes del PDF.
	 * @return int|false Attachment ID o false.
	 */
	private static function guardar_attachment( $evento_id, $datos, $pdf_bytes ) {
		if ( ! function_exists( 'wp_upload_bits' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		if ( ! function_exists( 'wp_insert_attachment' ) ) {
			require_once ABSPATH . 'wp-admin/includes/media.php';
		}

		$filename = sanitize_file_name( 'certificado-' . $datos['numero_ot'] . '-' . gmdate( 'Ymd' ) . '.pdf' );
		$upload   = wp_upload_bits( $filename, null, $pdf_bytes );

		if ( ! empty( $upload['error'] ) ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( 'CalibraTrack_PDF_Generator: wp_upload_bits falló — ' . $upload['error'] );
			return false;
		}

		$att_id = wp_insert_attachment( array(
			'post_title'     => $filename,
			'post_content'   => '',
			'post_status'    => 'inherit',
			'post_mime_type' => 'application/pdf',
			'post_parent'    => $evento_id,
		), $upload['file'], $evento_id );

		if ( is_wp_error( $att_id ) || ! $att_id ) {
			@unlink( $upload['file'] ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			return false;
		}

		// Borrar PDF anterior para evitar acumulación.
		$cert_anterior = (int) get_post_meta( $evento_id, CalibraTrack_Meta_Keys::EVENTO_CERTIFICADO_PDF, true );
		if ( $cert_anterior > 0 && $cert_anterior !== $att_id ) {
			wp_delete_attachment( $cert_anterior, true );
		}

		update_post_meta( $evento_id, CalibraTrack_Meta_Keys::EVENTO_CERTIFICADO_PDF, $att_id );
		return $att_id;
	}

	/**
	 * Convierte una imagen local a un data URI base64 para incrustarla en HTML/Dompdf.
	 * Dompdf no puede acceder a rutas fuera de su chroot ni a URLs HTTP locales;
	 * incrustar en base64 evita ambas restricciones.
	 *
	 * @param string $path Ruta absoluta al archivo de imagen.
	 * @return string  Data URI base64, o cadena vacía si no existe o falla.
	 */
	private static function path_to_base64_src( $path ) {
		if ( empty( $path ) || ! file_exists( $path ) ) {
			return '';
		}
		$ext      = strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );
		$mime_map = array(
			'png'  => 'image/png',
			'jpg'  => 'image/jpeg',
			'jpeg' => 'image/jpeg',
			'gif'  => 'image/gif',
			'svg'  => 'image/svg+xml',
		);
		$mime = isset( $mime_map[ $ext ] ) ? $mime_map[ $ext ] : 'image/png';
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$raw  = file_get_contents( $path );
		if ( false === $raw ) {
			return '';
		}
		return 'data:' . $mime . ';base64,' . base64_encode( $raw ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
	}

	/**
	 * Verifica si Dompdf está disponible via Composer autoload.
	 *
	 * @return bool
	 */
	private static function libreria_disponible() {
		$autoload = CALIBRATRACK_PLUGIN_DIR . 'vendor/autoload.php';
		if ( file_exists( $autoload ) ) {
			require_once $autoload;
		}
		return class_exists( 'Dompdf\Dompdf' );
	}
}
