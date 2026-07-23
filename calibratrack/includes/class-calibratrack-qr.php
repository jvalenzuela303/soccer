<?php
/**
 * Generación de códigos QR para equipos de calibración.
 *
 * LIBRERÍA: endroid/qr-code ^3.0
 *   - Requiere PHP ^7.1 (compatible con el servidor de producción PHP 7.4.33).
 *   - chillerlan/php-qrcode ^4.3 fue descartada porque su dependencia
 *     php-settings-container 3.3.0 usa union types de PHP 8.0.
 *
 * SEGURIDAD (§9 — no negociable):
 *   - Nombre del archivo PNG: UUID v4 sin guiones (32 chars hex).
 *   - El attachment ID (no la URL) se guarda en postmeta EQUIPO_CODIGO_QR.
 *
 * @package CalibraTrack
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class CalibraTrack_QR
 */
class CalibraTrack_QR {

	/**
	 * Genera el QR para un equipo, lo sube a la Media Library y actualiza el postmeta.
	 *
	 * @param string $serie     Número de serie del equipo.
	 * @param int    $equipo_id Post ID del equipo.
	 * @return int|false Attachment ID, o false si falla.
	 */
	public static function generate_for_equipo( $serie, $equipo_id ) {
		if ( empty( $serie ) || $equipo_id <= 0 ) {
			return false;
		}

		if ( ! self::libreria_disponible() ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( 'CalibraTrack_QR: endroid/qr-code no disponible. Ejecute composer install.' );
			return false;
		}

		// Usar el token único del equipo para que la URL del QR no sea enumerable.
		// Si el equipo aún no tiene token (equipos creados antes de esta versión),
		// generarlo ahora y guardarlo.
		$token = (string) get_post_meta( $equipo_id, CalibraTrack_Meta_Keys::EQUIPO_VERIFICACION_TOKEN, true );
		if ( empty( $token ) ) {
			$token = wp_generate_uuid4();
			update_post_meta( $equipo_id, CalibraTrack_Meta_Keys::EQUIPO_VERIFICACION_TOKEN, $token );
		}
		$url_verificacion = home_url( '/verificar/?token=' ) . rawurlencode( $token );
		$png_data         = self::generar_png( $url_verificacion );

		if ( false === $png_data ) {
			return false;
		}

		$uuid     = CalibraTrack_Helpers::generar_token_archivo();
		$filename = $uuid . '.png';
		$upload   = wp_upload_bits( $filename, null, $png_data );

		if ( ! empty( $upload['error'] ) ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( 'CalibraTrack_QR: wp_upload_bits falló — ' . $upload['error'] );
			return false;
		}

		if ( ! function_exists( 'wp_generate_attachment_metadata' ) ) {
			require_once ABSPATH . 'wp-admin/includes/image.php';
		}

		$attachment_id = wp_insert_attachment(
			array(
				'post_title'     => sprintf( __( 'QR equipo %s', 'calibratrack' ), $serie ),
				'post_content'   => '',
				'post_status'    => 'inherit',
				'post_mime_type' => 'image/png',
				'post_parent'    => $equipo_id,
			),
			$upload['file'],
			$equipo_id
		);

		if ( is_wp_error( $attachment_id ) || ! $attachment_id ) {
			@unlink( $upload['file'] ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			return false;
		}

		$metadata = wp_generate_attachment_metadata( $attachment_id, $upload['file'] );
		wp_update_attachment_metadata( $attachment_id, $metadata );

		// Borrar QR anterior para evitar attachments huérfanos.
		$qr_anterior_id = (int) get_post_meta( $equipo_id, CalibraTrack_Meta_Keys::EQUIPO_CODIGO_QR, true );
		if ( $qr_anterior_id > 0 && $qr_anterior_id !== $attachment_id ) {
			wp_delete_attachment( $qr_anterior_id, true );
		}

		update_post_meta( $equipo_id, CalibraTrack_Meta_Keys::EQUIPO_CODIGO_QR, $attachment_id );

		return $attachment_id;
	}

	/**
	 * Genera PNG del QR usando endroid/qr-code v3.
	 *
	 * @param string $url URL a codificar.
	 * @return string|false Bytes PNG, o false si falla.
	 */
	private static function generar_png( $url ) {
		try {
			$qr_code = new \Endroid\QrCode\QrCode( $url );
			$qr_code->setSize( 300 );
			$qr_code->setMargin( 10 );
			$qr_code->setErrorCorrectionLevel( \Endroid\QrCode\ErrorCorrectionLevel::HIGH() );
			$qr_code->setForegroundColor( array( 'r' => 0,   'g' => 0,   'b' => 0,   'a' => 0 ) );
			$qr_code->setBackgroundColor( array( 'r' => 255, 'g' => 255, 'b' => 255, 'a' => 0 ) );
			$qr_code->setWriterByName( 'png' );

			return $qr_code->writeString();

		} catch ( Exception $e ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( 'CalibraTrack_QR: excepción al generar QR — ' . $e->getMessage() );
			return false;
		}
	}

	/**
	 * Verifica disponibilidad de endroid/qr-code via Composer autoload.
	 *
	 * @return bool
	 */
	private static function libreria_disponible() {
		$autoload = CALIBRATRACK_PLUGIN_DIR . 'vendor/autoload.php';
		if ( file_exists( $autoload ) ) {
			require_once $autoload;
		}
		return class_exists( 'Endroid\QrCode\QrCode' );
	}
}
