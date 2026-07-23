<?php
/**
 * Envío de correos transaccionales de CalibraTrack.
 *
 * Decisión D-16: wp_mail() con headers MIME multipart/mixed.
 *   - El QR PNG del equipo se adjunta al correo (no se embebe inline)
 *     para máxima compatibilidad con clientes de correo.
 *   - El enlace a /verificar/{serie}/ permite al cliente descargar el
 *     certificado sin necesidad de credenciales.
 *   - El envío es opcional (opción calibratrack_email_enabled). Si Dompdf
 *     o wp_mail() fallan, el evento igual se guarda — el correo no es
 *     parte crítica del flujo.
 *
 * @package CalibraTrack
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class CalibraTrack_Mailer
 */
class CalibraTrack_Mailer {

	/**
	 * Registra los hooks del mailer.
	 * Se llama desde calibratrack_init().
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'phpmailer_init',                   array( __CLASS__, 'configure_smtp' ) );
		add_action( 'wp_ajax_calibratrack_test_email',  array( __CLASS__, 'handle_test_email_ajax' ) );
	}

	/**
	 * Configura PHPMailer con los ajustes SMTP del plugin (si están habilitados).
	 * Hook: phpmailer_init.
	 *
	 * @param PHPMailer $phpmailer Instancia de PHPMailer.
	 * @return void
	 */
	public static function configure_smtp( $phpmailer ) {
		if ( ! (bool) get_option( 'calibratrack_smtp_enabled', false ) ) {
			return;
		}

		$host       = (string) get_option( 'calibratrack_smtp_host', '' );
		$port       = (int)    get_option( 'calibratrack_smtp_port', 587 );
		$user       = (string) get_option( 'calibratrack_smtp_user', '' );
		$pass       = (string) get_option( 'calibratrack_smtp_pass', '' );
		$encryption = (string) get_option( 'calibratrack_smtp_encryption', 'tls' );

		if ( empty( $host ) || empty( $user ) ) {
			return;
		}

		$phpmailer->isSMTP();
		$phpmailer->Host       = $host;
		$phpmailer->Port       = $port;
		$phpmailer->SMTPAuth   = ! empty( $pass );
		$phpmailer->Username   = $user;
		$phpmailer->Password   = $pass;
		$phpmailer->SMTPSecure = $encryption; // 'tls' (STARTTLS) o 'ssl' (SMTPS).
	}

	/**
	 * Manejador AJAX para el correo de prueba.
	 * Acción: calibratrack_test_email (solo admins).
	 *
	 * @return void (JSON + exit)
	 */
	public static function handle_test_email_ajax() {
		check_ajax_referer( 'calibratrack_test_email', '_nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Sin permisos.', 'calibratrack' ) ) );
		}

		$to = isset( $_POST['to'] ) ? sanitize_email( wp_unslash( $_POST['to'] ) ) : '';
		if ( empty( $to ) || ! is_email( $to ) ) {
			wp_send_json_error( array( 'message' => __( 'Dirección de correo inválida.', 'calibratrack' ) ) );
		}

		$empresa = (string) get_option( 'calibratrack_empresa_nombre', 'CalibraTrack' );
		$from    = (string) get_option( 'calibratrack_email_from', get_option( 'admin_email' ) );

		$asunto  = sprintf( '[%s] Correo de prueba', $empresa );
		$cuerpo  = '<p>Este es un correo de prueba enviado desde <strong>' . esc_html( $empresa ) . '</strong>.</p>';
		$cuerpo .= '<p>Si recibes este mensaje, el envío de correos está correctamente configurado.</p>';
		$cuerpo .= '<p style="color:#666;font-size:12px;">Enviado el ' . gmdate( 'd/m/Y H:i:s' ) . ' UTC</p>';

		$headers = array(
			'Content-Type: text/html; charset=UTF-8',
			'From: ' . $empresa . ' <' . $from . '>',
		);

		$result = wp_mail( $to, $asunto, $cuerpo, $headers );

		if ( $result ) {
			wp_send_json_success( array(
				'message' => sprintf(
					/* translators: %s: email de destino */
					__( 'Correo enviado correctamente a %s.', 'calibratrack' ),
					$to
				),
			) );
		} else {
			global $phpmailer;
			$error_info = '';
			if ( isset( $phpmailer ) && isset( $phpmailer->ErrorInfo ) && ! empty( $phpmailer->ErrorInfo ) ) {
				$error_info = ' — ' . $phpmailer->ErrorInfo;
			}
			wp_send_json_error( array(
				'message' => __( 'Error al enviar el correo.', 'calibratrack' ) . $error_info,
			) );
		}
	}

	/**
	 * EMAIL 1 — Aviso de recepción y apertura de OT.
	 * Se envía al crear un nuevo evento (estado: en_proceso).
	 * Adjunta la Orden de Trabajo PDF generada automáticamente.
	 *
	 * @param int $evento_id Post ID del evento_servicio.
	 * @return bool
	 */
	public static function send_ot_a_cliente( $evento_id ) {
		if ( ! (bool) get_option( 'calibratrack_email_enabled', true ) ) {
			return false;
		}

		$datos = self::get_datos_correo( $evento_id );
		if ( ! $datos ) {
			return false;
		}

		$asunto = sprintf(
			/* translators: 1: número OT 2: tipo servicio 3: marca modelo */
			__( 'Orden de Trabajo %1$s — %2$s de equipo %3$s', 'calibratrack' ),
			$datos['numero_ot'],
			$datos['tipo_label'],
			$datos['marca'] . ' ' . $datos['modelo']
		);

		// Adjunto: OT PDF generada automáticamente.
		$adjuntos    = array();
		$ot_att_id   = (int) get_post_meta( $evento_id, CalibraTrack_Meta_Keys::EVENTO_ORDEN_TRABAJO_PDF, true );
		$ot_filepath = $ot_att_id ? get_attached_file( $ot_att_id ) : '';
		if ( $ot_filepath && file_exists( $ot_filepath ) ) {
			$adjuntos[] = $ot_filepath;
		}

		$cuerpo = self::build_email_ot( $datos );
		return self::despachar( $evento_id, $datos, $asunto, $cuerpo, $adjuntos );
	}

	/**
	 * EMAIL 2 — Aviso de trabajo completado con QR de verificación.
	 * Se envía cuando el técnico marca el evento como "completado".
	 * Adjunta el certificado PDF generado automáticamente.
	 *
	 * @param int $evento_id Post ID del evento_servicio.
	 * @return bool
	 */
	public static function send_certificado_a_cliente( $evento_id ) {
		if ( ! (bool) get_option( 'calibratrack_email_enabled', true ) ) {
			return false;
		}

		$datos = self::get_datos_correo( $evento_id );
		if ( ! $datos ) {
			return false;
		}

		$asunto = sprintf(
			/* translators: 1: tipo servicio 2: serie 3: empresa */
			__( '%1$s completado — equipo %2$s | %3$s', 'calibratrack' ),
			$datos['tipo_label'],
			$datos['serie'],
			$datos['empresa_nombre']
		);

		// Adjuntos: certificado PDF + OT PDF + fotos de evidencia + documentos adjuntos + QR PNG.
		$adjuntos = array();

		$cert_att_id   = (int) get_post_meta( $evento_id, CalibraTrack_Meta_Keys::EVENTO_CERTIFICADO_PDF, true );
		$cert_filepath = $cert_att_id ? get_attached_file( $cert_att_id ) : '';
		if ( $cert_filepath && file_exists( $cert_filepath ) ) {
			$adjuntos[] = $cert_filepath;
		}

		// Adjuntar la Orden de Trabajo PDF.
		$ot_att_id   = (int) get_post_meta( $evento_id, CalibraTrack_Meta_Keys::EVENTO_ORDEN_TRABAJO_PDF, true );
		$ot_filepath = $ot_att_id ? get_attached_file( $ot_att_id ) : '';
		if ( $ot_filepath && file_exists( $ot_filepath ) ) {
			$adjuntos[] = $ot_filepath;
		}

		// Adjuntar fotos de evidencia fotográfica.
		$fotos_json = (string) get_post_meta( $evento_id, CalibraTrack_Meta_Keys::EVENTO_EVIDENCIA_FOTOGRAFICA, true );
		$fotos_ids  = $fotos_json ? json_decode( $fotos_json, true ) : array();
		if ( is_array( $fotos_ids ) ) {
			foreach ( $fotos_ids as $foto_id ) {
				$foto_path = get_attached_file( (int) $foto_id );
				if ( $foto_path && file_exists( $foto_path ) ) {
					$adjuntos[] = $foto_path;
				}
			}
		}

		// Adjuntar documentos adicionales (PDF).
		$docs_json = (string) get_post_meta( $evento_id, CalibraTrack_Meta_Keys::EVENTO_DOCUMENTOS_ADJUNTOS, true );
		$docs_ids  = $docs_json ? json_decode( $docs_json, true ) : array();
		if ( is_array( $docs_ids ) ) {
			foreach ( $docs_ids as $doc_id ) {
				$doc_path = get_attached_file( (int) $doc_id );
				if ( $doc_path && file_exists( $doc_path ) ) {
					$adjuntos[] = $doc_path;
				}
			}
		}

		// QR PNG del equipo.
		$qr_att_id   = (int) get_post_meta( $datos['equipo_id'], CalibraTrack_Meta_Keys::EQUIPO_CODIGO_QR, true );
		$qr_filepath = $qr_att_id ? get_attached_file( $qr_att_id ) : '';
		if ( $qr_filepath && file_exists( $qr_filepath ) ) {
			$adjuntos[] = $qr_filepath;
		}

		$cuerpo = self::build_email_certificado( $datos );
		return self::despachar( $evento_id, $datos, $asunto, $cuerpo, $adjuntos );
	}

	/**
	 * Obtiene los datos comunes necesarios para cualquier correo del evento.
	 *
	 * @param int $evento_id Post ID del evento_servicio.
	 * @return array|false Array de datos o false si falta información crítica.
	 */
	private static function get_datos_correo( $evento_id ) {
		$equipo_id  = (int) get_post_meta( $evento_id, CalibraTrack_Meta_Keys::EVENTO_EQUIPO_ID, true );
		$cliente_id = $equipo_id ? (int) get_post_meta( $equipo_id, CalibraTrack_Meta_Keys::EQUIPO_CLIENTE_PROPIETARIO, true ) : 0;

		if ( ! $equipo_id || ! $cliente_id ) {
			return false;
		}

		$correo_cliente = (string) get_post_meta( $cliente_id, CalibraTrack_Meta_Keys::CLIENTE_CORREO, true );
		if ( empty( $correo_cliente ) || ! is_email( $correo_cliente ) ) {
			return false;
		}

		$tipo       = (string) get_post_meta( $evento_id, CalibraTrack_Meta_Keys::EVENTO_TIPO, true );
		$tipos_map  = CalibraTrack_Helpers::get_tipos_evento();
		$tipo_label = isset( $tipos_map[ $tipo ] ) ? $tipos_map[ $tipo ] : $tipo;

		// Token para URL de verificación (no enumerable).
		$token = (string) get_post_meta( $equipo_id, CalibraTrack_Meta_Keys::EQUIPO_VERIFICACION_TOKEN, true );
		if ( empty( $token ) ) {
			$token = wp_generate_uuid4();
			update_post_meta( $equipo_id, CalibraTrack_Meta_Keys::EQUIPO_VERIFICACION_TOKEN, $token );
		}

		return array(
			'equipo_id'      => $equipo_id,
			'cliente_id'     => $cliente_id,
			'correo_cliente' => $correo_cliente,
			'nombre_cliente' => (string) get_post_meta( $cliente_id, CalibraTrack_Meta_Keys::CLIENTE_NOMBRE_EMPRESA, true ),
			'serie'          => (string) get_post_meta( $equipo_id, CalibraTrack_Meta_Keys::EQUIPO_SERIE, true ),
			'marca'          => (string) get_post_meta( $equipo_id, CalibraTrack_Meta_Keys::EQUIPO_MARCA, true ),
			'modelo'         => (string) get_post_meta( $equipo_id, CalibraTrack_Meta_Keys::EQUIPO_MODELO, true ),
			'numero_ot'      => (string) get_post_meta( $evento_id, CalibraTrack_Meta_Keys::EVENTO_NUMERO_OT, true ),
			'tipo_label'     => $tipo_label,
			'empresa_nombre' => (string) get_option( 'calibratrack_empresa_nombre', 'TrueTech SpA' ),
			'empresa_correo' => (string) get_option( 'calibratrack_email_from', get_option( 'admin_email' ) ),
			'color_primario' => (string) get_option( 'calibratrack_pdf_color_primario', '#00AEEF' ),
			'url_verificar'  => home_url( '/verificar/?token=' . rawurlencode( $token ) ),
			'subtotal'       => (float) get_post_meta( $evento_id, 'calibratrack_subtotal', true ),
			'iva'            => (float) get_post_meta( $evento_id, 'calibratrack_iva', true ),
			'total'          => (float) get_post_meta( $evento_id, 'calibratrack_total', true ),
		);
	}

	/**
	 * Envía el correo al cliente con headers estándar.
	 * El técnico NO se copia en correos al cliente — pueden contener información
	 * financiera confidencial (precios, totales) que el técnico externo no debe ver.
	 *
	 * @param int    $evento_id Post ID del evento.
	 * @param array  $datos     Datos del correo.
	 * @param string $asunto    Asunto del correo.
	 * @param string $cuerpo    Cuerpo HTML.
	 * @param array  $adjuntos  Rutas de archivos adjuntos.
	 * @return bool
	 */
	private static function despachar( $evento_id, $datos, $asunto, $cuerpo, $adjuntos ) {
		$headers = array(
			'Content-Type: text/html; charset=UTF-8',
			'From: ' . $datos['empresa_nombre'] . ' <' . $datos['empresa_correo'] . '>',
		);

		return wp_mail( $datos['correo_cliente'], $asunto, $cuerpo, $headers, $adjuntos );
	}

	/**
	 * Construye el HTML del EMAIL 1: Aviso de recepción + OT adjunta.
	 * Se envía al crear el evento (estado: en_proceso).
	 *
	 * @param array $d Datos del correo.
	 * @return string HTML del correo.
	 */
	private static function build_email_ot( $d ) {
		$color   = esc_attr( $d['color_primario'] );
		$empresa = esc_html( $d['empresa_nombre'] );
		$cliente = esc_html( $d['nombre_cliente'] );
		$tipo    = esc_html( $d['tipo_label'] );

		$html  = '<!DOCTYPE html><html lang="es"><head><meta charset="UTF-8">';
		$html .= '<meta name="viewport" content="width=device-width,initial-scale=1.0"></head>';
		$html .= '<body style="margin:0;padding:0;font-family:Arial,sans-serif;background:#f3f4f6;">';

		$html .= '<table width="100%" cellpadding="0" cellspacing="0" style="background:#f3f4f6;">';
		$html .= '<tr><td align="center" style="padding:32px 16px;">';
		$html .= '<table width="600" cellpadding="0" cellspacing="0" style="max-width:600px;background:#fff;border-radius:8px;overflow:hidden;">';

		// Banda superior
		$html .= '<tr><td style="background:' . $color . ';height:6px;"></td></tr>';

		// Encabezado
		$html .= '<tr><td style="padding:24px 32px 12px;">';
		$html .= '<p style="margin:0;font-size:22px;font-weight:bold;color:' . $color . ';">' . $empresa . '</p>';
		$html .= '<p style="margin:4px 0 0;font-size:15px;color:#374151;font-weight:bold;">Recepción de equipo — Orden de Trabajo</p>';
		$html .= '</td></tr>';

		// Cuerpo
		$html .= '<tr><td style="padding:8px 32px 24px;">';
		$html .= '<p style="color:#374151;">Estimado/a <strong>' . $cliente . '</strong>,</p>';
		$html .= '<p style="color:#374151;">Le informamos que hemos recibido su equipo y hemos dado inicio al <strong>';
		$html .= $tipo . '</strong> solicitado. Adjunto a este correo encontrará la <strong>Orden de Trabajo</strong> ';
		$html .= 'con los detalles del servicio.</p>';

		// Tabla equipo
		$html .= '<table width="100%" cellpadding="0" cellspacing="0" style="border:1px solid #e5e7eb;border-radius:6px;margin:16px 0;font-size:14px;">';
		$html .= '<tr style="background:#f9fafb;"><td style="padding:8px 12px;font-weight:bold;width:130px;color:#374151;">N° OT</td>';
		$html .= '<td style="padding:8px 12px;color:#111827;">' . esc_html( $d['numero_ot'] ) . '</td></tr>';
		$html .= '<tr><td style="padding:8px 12px;font-weight:bold;color:#374151;">Serie</td>';
		$html .= '<td style="padding:8px 12px;color:#111827;font-family:monospace;">' . esc_html( $d['serie'] ) . '</td></tr>';
		$html .= '<tr style="background:#f9fafb;"><td style="padding:8px 12px;font-weight:bold;color:#374151;">Equipo</td>';
		$html .= '<td style="padding:8px 12px;color:#111827;">' . esc_html( $d['marca'] . ' ' . $d['modelo'] ) . '</td></tr>';
		$html .= '<tr><td style="padding:8px 12px;font-weight:bold;color:#374151;">Estado</td>';
		$html .= '<td style="padding:8px 12px;"><span style="background:#fff3cd;color:#856404;border:1px solid #ffc107;padding:2px 10px;border-radius:4px;font-weight:bold;">En proceso</span></td></tr>';
		$html .= '</table>';

		// Sección de montos del servicio (solo si hay total > 0).
		if ( isset( $d['total'] ) && $d['total'] > 0 ) {
			$html .= '<p style="margin:16px 0 6px;font-weight:bold;color:#374151;">Detalle de costos del servicio:</p>';
			$html .= '<table width="100%" cellpadding="0" cellspacing="0" style="border:1px solid #e5e7eb;border-radius:6px;font-size:14px;">';
			$html .= '<tr style="background:#f9fafb;"><td style="padding:8px 12px;font-weight:bold;color:#374151;">Subtotal</td>';
			$html .= '<td style="padding:8px 12px;color:#111827;text-align:right;">$' . number_format( (float) $d['subtotal'], 0, ',', '.' ) . '</td></tr>';
			$html .= '<tr><td style="padding:8px 12px;font-weight:bold;color:#374151;">IVA (19%)</td>';
			$html .= '<td style="padding:8px 12px;color:#111827;text-align:right;">$' . number_format( (float) $d['iva'], 0, ',', '.' ) . '</td></tr>';
			$html .= '<tr style="background:#f9fafb;"><td style="padding:8px 12px;font-weight:bold;color:#374151;font-size:15px;">Total</td>';
			$html .= '<td style="padding:8px 12px;color:#111827;text-align:right;font-weight:bold;font-size:15px;">$' . number_format( (float) $d['total'], 0, ',', '.' ) . '</td></tr>';
			$html .= '</table>';
		}

		$html .= '<p style="color:#374151;">Una vez finalizado el servicio, recibirá un segundo correo con el <strong>certificado</strong> ';
		$html .= 'y el código QR de verificación de autenticidad.</p>';

		$html .= '<p style="color:#6b7280;font-size:13px;">Si tiene alguna consulta, no dude en contactarnos respondiendo este correo.</p>';
		$html .= '</td></tr>';

		// Banda inferior + footer
		$html .= '<tr><td style="background:' . $color . ';height:4px;"></td></tr>';
		$html .= '<tr><td style="padding:16px 32px;text-align:center;color:#9ca3af;font-size:12px;">';
		$html .= '© ' . gmdate( 'Y' ) . ' ' . $empresa . ' — Este correo fue generado automáticamente.';
		$html .= '</td></tr>';

		$html .= '</table>';
		$html .= '</td></tr></table>';
		$html .= '</body></html>';

		return $html;
	}

	/**
	 * Construye el HTML del EMAIL 2: Servicio completado + certificado + QR.
	 * Se envía cuando el técnico marca el evento como "completado".
	 *
	 * @param array $d Datos del correo.
	 * @return string HTML del correo.
	 */
	private static function build_email_certificado( $d ) {
		$color   = esc_attr( $d['color_primario'] );
		$empresa = esc_html( $d['empresa_nombre'] );
		$cliente = esc_html( $d['nombre_cliente'] );
		$tipo    = esc_html( $d['tipo_label'] );

		$html  = '<!DOCTYPE html><html lang="es"><head><meta charset="UTF-8">';
		$html .= '<meta name="viewport" content="width=device-width,initial-scale=1.0"></head>';
		$html .= '<body style="margin:0;padding:0;font-family:Arial,sans-serif;background:#f3f4f6;">';

		$html .= '<table width="100%" cellpadding="0" cellspacing="0" style="background:#f3f4f6;">';
		$html .= '<tr><td align="center" style="padding:32px 16px;">';
		$html .= '<table width="600" cellpadding="0" cellspacing="0" style="max-width:600px;background:#fff;border-radius:8px;overflow:hidden;">';

		// Banda superior
		$html .= '<tr><td style="background:' . $color . ';height:6px;"></td></tr>';

		// Encabezado
		$html .= '<tr><td style="padding:24px 32px 12px;">';
		$html .= '<p style="margin:0;font-size:22px;font-weight:bold;color:' . $color . ';">' . $empresa . '</p>';
		$html .= '<p style="margin:4px 0 0;font-size:15px;color:#374151;font-weight:bold;">Servicio completado — Certificado adjunto</p>';
		$html .= '</td></tr>';

		// Cuerpo
		$html .= '<tr><td style="padding:8px 32px 24px;">';
		$html .= '<p style="color:#374151;">Estimado/a <strong>' . $cliente . '</strong>,</p>';
		$html .= '<p style="color:#374151;">Nos complace informarle que el <strong>' . $tipo . '</strong> ';
		$html .= 'de su equipo ha sido <strong>completado satisfactoriamente</strong>. ';
		$html .= 'Adjunto encontrará el <strong>certificado PDF</strong> del servicio.</p>';

		// Tabla equipo
		$html .= '<table width="100%" cellpadding="0" cellspacing="0" style="border:1px solid #e5e7eb;border-radius:6px;margin:16px 0;font-size:14px;">';
		$html .= '<tr style="background:#f9fafb;"><td style="padding:8px 12px;font-weight:bold;width:130px;color:#374151;">N° OT</td>';
		$html .= '<td style="padding:8px 12px;color:#111827;">' . esc_html( $d['numero_ot'] ) . '</td></tr>';
		$html .= '<tr><td style="padding:8px 12px;font-weight:bold;color:#374151;">Serie</td>';
		$html .= '<td style="padding:8px 12px;color:#111827;font-family:monospace;">' . esc_html( $d['serie'] ) . '</td></tr>';
		$html .= '<tr style="background:#f9fafb;"><td style="padding:8px 12px;font-weight:bold;color:#374151;">Equipo</td>';
		$html .= '<td style="padding:8px 12px;color:#111827;">' . esc_html( $d['marca'] . ' ' . $d['modelo'] ) . '</td></tr>';
		$html .= '<tr><td style="padding:8px 12px;font-weight:bold;color:#374151;">Estado</td>';
		$html .= '<td style="padding:8px 12px;"><span style="background:#d1fae5;color:#065f46;border:1px solid #10b981;padding:2px 10px;border-radius:4px;font-weight:bold;">Completado</span></td></tr>';
		$html .= '</table>';

		// Sección verificación
		$html .= '<div style="background:#f0f9ff;border:1px solid #bae6fd;border-radius:6px;padding:16px;margin:16px 0;">';
		$html .= '<p style="margin:0 0 8px;font-weight:bold;color:#0369a1;">Verificación de autenticidad</p>';
		$html .= '<p style="margin:0 0 12px;color:#374151;font-size:13px;">Puede verificar la autenticidad de este servicio en cualquier momento ';
		$html .= 'escaneando el código QR adjunto o haciendo clic en el siguiente enlace:</p>';
		$html .= '<p style="text-align:center;margin:16px 0;">';
		$html .= '<a href="' . esc_url( $d['url_verificar'] ) . '" ';
		$html .= 'style="background:' . $color . ';color:#fff;text-decoration:none;padding:10px 24px;border-radius:6px;font-weight:bold;font-size:14px;display:inline-block;">';
		$html .= 'Ver verificación en línea</a>';
		$html .= '</p>';
		$html .= '<p style="color:#9ca3af;font-size:12px;margin:0;">Si el botón no funciona: ';
		$html .= '<a href="' . esc_url( $d['url_verificar'] ) . '" style="color:' . $color . ';word-break:break-all;">' . esc_url( $d['url_verificar'] ) . '</a></p>';
		$html .= '</div>';

		$html .= '<p style="color:#6b7280;font-size:13px;">Gracias por confiar en nosotros. Si tiene alguna consulta, no dude en contactarnos.</p>';
		$html .= '</td></tr>';

		// Banda inferior + footer
		$html .= '<tr><td style="background:' . $color . ';height:4px;"></td></tr>';
		$html .= '<tr><td style="padding:16px 32px;text-align:center;color:#9ca3af;font-size:12px;">';
		$html .= '© ' . gmdate( 'Y' ) . ' ' . $empresa . ' — Este correo fue generado automáticamente.';
		$html .= '</td></tr>';

		$html .= '</table>';
		$html .= '</td></tr></table>';
		$html .= '</body></html>';

		return $html;
	}

	// ─── NUEVOS MÉTODOS: OI AL TÉCNICO + RECORDATORIO AL CLIENTE ─────────────────

	/**
	 * EMAIL — Notificación al cliente cuando el admin crea una nueva Orden de Ingreso.
	 * Informa que el equipo fue recibido y la OI está abierta. Sin montos.
	 *
	 * @param int $evento_id Post ID del evento_servicio (tipo 'ingreso').
	 * @return bool True si wp_mail reportó éxito.
	 */
	public static function send_oi_a_cliente( $evento_id ) {
		if ( ! (bool) get_option( 'calibratrack_email_enabled', true ) ) {
			return false;
		}

		$datos = self::get_datos_correo( $evento_id );
		if ( ! $datos ) {
			return false;
		}

		$numero_oi = (string) get_post_meta( $evento_id, CalibraTrack_Meta_Keys::EVENTO_NUMERO_OI, true );
		if ( empty( $numero_oi ) ) {
			$numero_oi = 'OI-' . $evento_id;
		}

		$falla     = (string) get_post_meta( $evento_id, CalibraTrack_Meta_Keys::EVENTO_FALLA_REPORTADA, true );
		$fecha_raw = (string) get_post_meta( $evento_id, CalibraTrack_Meta_Keys::EVENTO_FECHA_EJECUCION, true );
		$fecha_dt  = $fecha_raw ? DateTime::createFromFormat( 'Y-m-d', $fecha_raw ) : null;
		$fecha_fmt = $fecha_dt ? $fecha_dt->format( 'd/m/Y' ) : '—';

		$asunto = sprintf(
			/* translators: 1: número OI 2: empresa */
			__( 'Recepción de equipo confirmada — %1$s | %2$s', 'calibratrack' ),
			$numero_oi,
			$datos['empresa_nombre']
		);

		$color   = esc_attr( $datos['color_primario'] );
		$empresa = esc_html( $datos['empresa_nombre'] );
		$cliente = esc_html( $datos['nombre_cliente'] );
		$tipo    = esc_html( $datos['tipo_label'] );

		$html  = '<!DOCTYPE html><html lang="es"><head><meta charset="UTF-8">';
		$html .= '<meta name="viewport" content="width=device-width,initial-scale=1.0"></head>';
		$html .= '<body style="margin:0;padding:0;font-family:Arial,sans-serif;background:#f3f4f6;">';
		$html .= '<table width="100%" cellpadding="0" cellspacing="0" style="background:#f3f4f6;">';
		$html .= '<tr><td align="center" style="padding:32px 16px;">';
		$html .= '<table width="600" cellpadding="0" cellspacing="0" style="max-width:600px;background:#fff;border-radius:8px;overflow:hidden;">';
		$html .= '<tr><td style="background:' . $color . ';height:6px;"></td></tr>';
		$html .= '<tr><td style="padding:24px 32px 12px;">';
		$html .= '<p style="margin:0;font-size:22px;font-weight:bold;color:' . $color . ';">' . $empresa . '</p>';
		$html .= '<p style="margin:4px 0 0;font-size:15px;color:#374151;font-weight:bold;">Recepción de equipo confirmada</p>';
		$html .= '</td></tr>';
		$html .= '<tr><td style="padding:8px 32px 24px;">';
		$html .= '<p style="color:#374151;">Estimado/a <strong>' . $cliente . '</strong>,</p>';
		$html .= '<p style="color:#374151;">Le confirmamos que hemos recibido su equipo en nuestras instalaciones. ';
		$html .= 'Se ha abierto una <strong>Orden de Ingreso</strong> para registrar la recepción y programar el servicio correspondiente.</p>';

		$html .= '<table width="100%" cellpadding="0" cellspacing="0" style="border:1px solid #e5e7eb;border-radius:6px;margin:16px 0;font-size:14px;">';
		$html .= '<tr style="background:#f9fafb;"><td style="padding:8px 12px;font-weight:bold;width:160px;color:#374151;">N° Ingreso</td>';
		$html .= '<td style="padding:8px 12px;color:#111827;font-weight:600;">' . esc_html( $numero_oi ) . '</td></tr>';
		$html .= '<tr><td style="padding:8px 12px;font-weight:bold;color:#374151;">Tipo de servicio</td>';
		$html .= '<td style="padding:8px 12px;color:#111827;">' . $tipo . '</td></tr>';
		$html .= '<tr style="background:#f9fafb;"><td style="padding:8px 12px;font-weight:bold;color:#374151;">Serie</td>';
		$html .= '<td style="padding:8px 12px;color:#111827;font-family:monospace;">' . esc_html( $datos['serie'] ) . '</td></tr>';
		$html .= '<tr><td style="padding:8px 12px;font-weight:bold;color:#374151;">Equipo</td>';
		$html .= '<td style="padding:8px 12px;color:#111827;">' . esc_html( $datos['marca'] . ' ' . $datos['modelo'] ) . '</td></tr>';
		$html .= '<tr style="background:#f9fafb;"><td style="padding:8px 12px;font-weight:bold;color:#374151;">Fecha de recepción</td>';
		$html .= '<td style="padding:8px 12px;color:#111827;">' . esc_html( $fecha_fmt ) . '</td></tr>';
		$html .= '</table>';

		if ( ! empty( $falla ) ) {
			$html .= '<p style="margin:8px 0 4px;font-weight:bold;color:#374151;">Falla / defecto reportado:</p>';
			$html .= '<div style="border:1px solid #e5e7eb;border-radius:4px;padding:10px 14px;background:#fafafa;color:#374151;font-size:13px;white-space:pre-wrap;">';
			$html .= esc_html( $falla );
			$html .= '</div>';
		}

		$html .= '<p style="color:#374151;margin-top:16px;">A la brevedad nos pondremos en contacto con usted para coordinar el servicio. ';
		$html .= 'Recibirá un segundo correo con la <strong>Orden de Trabajo</strong> y los detalles del servicio a realizar.</p>';
		$html .= '<p style="color:#6b7280;font-size:13px;">Si tiene alguna consulta, no dude en contactarnos respondiendo este correo.</p>';
		$html .= '</td></tr>';
		$html .= '<tr><td style="background:' . $color . ';height:4px;"></td></tr>';
		$html .= '<tr><td style="padding:16px 32px;text-align:center;color:#9ca3af;font-size:12px;">';
		$html .= '© ' . gmdate( 'Y' ) . ' ' . $empresa . ' — Este correo fue generado automáticamente.';
		$html .= '</td></tr></table></td></tr></table></body></html>';

		$headers = array(
			'Content-Type: text/html; charset=UTF-8',
			'From: ' . $datos['empresa_nombre'] . ' <' . $datos['empresa_correo'] . '>',
		);

		return wp_mail( $datos['correo_cliente'], $asunto, $html, $headers );
	}

	/**
	 * Obtiene datos del técnico responsable de un evento.
	 *
	 * @param int $evento_id Post ID del evento_servicio.
	 * @return array|false Array con 'correo' y 'nombre', o false si no tiene técnico válido.
	 */
	private static function get_datos_tecnico( $evento_id ) {
		$tecnico_id = (int) get_post_meta( $evento_id, CalibraTrack_Meta_Keys::EVENTO_TECNICO_RESPONSABLE, true );
		if ( ! $tecnico_id ) {
			return false;
		}

		$tecnico_user = get_user_by( 'id', $tecnico_id );
		if ( ! $tecnico_user || ! is_email( $tecnico_user->user_email ) ) {
			return false;
		}

		return array(
			'correo' => $tecnico_user->user_email,
			'nombre' => $tecnico_user->display_name,
		);
	}

	/**
	 * EMAIL — Aviso al técnico de nueva Orden de Ingreso asignada.
	 * Se envía cuando el administrador crea una OI con un técnico responsable asignado.
	 * NO incluye montos ni precios — el técnico solo recibe información técnica del equipo.
	 *
	 * @param int $evento_id Post ID del evento_servicio (tipo 'ingreso').
	 * @return bool True si wp_mail reportó éxito.
	 */
	public static function send_oi_a_tecnico( $evento_id ) {
		if ( ! (bool) get_option( 'calibratrack_email_enabled', true ) ) {
			return false;
		}

		$datos_tecnico = self::get_datos_tecnico( $evento_id );
		if ( ! $datos_tecnico ) {
			return false; // Sin técnico asignado o sin correo válido — no enviar.
		}

		$datos = self::get_datos_correo( $evento_id );
		if ( ! $datos ) {
			return false;
		}

		$asunto = sprintf(
			/* translators: 1: número OI 2: tipo servicio 3: marca modelo */
			__( '[Nueva asignación] %1$s — %2$s de equipo %3$s', 'calibratrack' ),
			$datos['numero_ot'],
			$datos['tipo_label'],
			$datos['marca'] . ' ' . $datos['modelo']
		);

		$empresa = (string) get_option( 'calibratrack_empresa_nombre', 'TrueTech SpA' );
		$from    = (string) get_option( 'calibratrack_email_from', get_option( 'admin_email' ) );
		$color   = esc_attr( $datos['color_primario'] );

		// Datos adicionales del evento (solo técnicos — sin montos).
		$numero_oi = (string) get_post_meta( $evento_id, CalibraTrack_Meta_Keys::EVENTO_NUMERO_OI, true );
		$falla     = (string) get_post_meta( $evento_id, CalibraTrack_Meta_Keys::EVENTO_FALLA_REPORTADA, true );
		$fecha_raw = (string) get_post_meta( $evento_id, CalibraTrack_Meta_Keys::EVENTO_FECHA_EJECUCION, true );
		$fecha_dt  = $fecha_raw ? DateTime::createFromFormat( 'Y-m-d', $fecha_raw ) : null;
		$fecha_fmt = $fecha_dt ? $fecha_dt->format( 'd/m/Y' ) : '—';

		// URL directa a la OI en el panel (el técnico puede acceder).
		$url_oi = home_url( '/panel/oi/' . $evento_id . '/' );

		$nombre_tecnico = esc_html( $datos_tecnico['nombre'] );
		$marca_modelo   = esc_html( $datos['marca'] . ' ' . $datos['modelo'] );
		$serie          = esc_html( $datos['serie'] );
		$tipo_label     = esc_html( $datos['tipo_label'] );

		// Mostrar N° Ingreso (OI), no el N° OT.
		$numero_ingreso = ! empty( $numero_oi ) ? esc_html( $numero_oi ) : esc_html( 'OI-' . $evento_id );

		$html  = '<!DOCTYPE html><html lang="es"><head><meta charset="UTF-8">';
		$html .= '<meta name="viewport" content="width=device-width,initial-scale=1.0"></head>';
		$html .= '<body style="margin:0;padding:0;font-family:Arial,sans-serif;background:#f3f4f6;">';
		$html .= '<table width="100%" cellpadding="0" cellspacing="0" style="background:#f3f4f6;">';
		$html .= '<tr><td align="center" style="padding:32px 16px;">';
		$html .= '<table width="600" cellpadding="0" cellspacing="0" style="max-width:600px;background:#fff;border-radius:8px;overflow:hidden;">';
		$html .= '<tr><td style="background:' . $color . ';height:6px;"></td></tr>';
		$html .= '<tr><td style="padding:24px 32px 12px;">';
		$html .= '<p style="margin:0;font-size:22px;font-weight:bold;color:' . $color . ';">' . esc_html( $empresa ) . '</p>';
		$html .= '<p style="margin:4px 0 0;font-size:15px;color:#374151;font-weight:bold;">Nueva asignación de trabajo</p>';
		$html .= '</td></tr>';
		$html .= '<tr><td style="padding:8px 32px 24px;">';
		$html .= '<p style="color:#374151;">Estimado/a <strong>' . $nombre_tecnico . '</strong>,</p>';
		$html .= '<p style="color:#374151;">Se te ha asignado una nueva <strong>Orden de Ingreso</strong>. ';
		$html .= 'A continuación encontrarás los antecedentes del trabajo a realizar:</p>';
		$html .= '<table width="100%" cellpadding="0" cellspacing="0" style="border:1px solid #e5e7eb;border-radius:6px;margin:16px 0;font-size:14px;">';
		$html .= '<tr style="background:#f9fafb;"><td style="padding:8px 12px;font-weight:bold;width:160px;color:#374151;">N° Ingreso</td>';
		$html .= '<td style="padding:8px 12px;color:#111827;font-weight:600;">' . $numero_ingreso . '</td></tr>';
		$html .= '<tr><td style="padding:8px 12px;font-weight:bold;color:#374151;">Tipo de servicio</td>';
		$html .= '<td style="padding:8px 12px;color:#111827;">' . $tipo_label . '</td></tr>';
		$html .= '<tr style="background:#f9fafb;"><td style="padding:8px 12px;font-weight:bold;color:#374151;">Equipo</td>';
		$html .= '<td style="padding:8px 12px;color:#111827;">' . $marca_modelo . '</td></tr>';
		$html .= '<tr><td style="padding:8px 12px;font-weight:bold;color:#374151;">N° de Serie</td>';
		$html .= '<td style="padding:8px 12px;color:#111827;font-family:monospace;">' . $serie . '</td></tr>';
		$html .= '<tr style="background:#f9fafb;"><td style="padding:8px 12px;font-weight:bold;color:#374151;">Fecha</td>';
		$html .= '<td style="padding:8px 12px;color:#111827;">' . esc_html( $fecha_fmt ) . '</td></tr>';
		$html .= '</table>';
		if ( ! empty( $falla ) ) {
			$html .= '<p style="margin:8px 0 4px;font-weight:bold;color:#374151;">Falla / defecto reportado por el cliente:</p>';
			$html .= '<div style="border:1px solid #e5e7eb;border-radius:4px;padding:10px 14px;background:#fafafa;color:#374151;font-size:13px;white-space:pre-wrap;">';
			$html .= esc_html( $falla );
			$html .= '</div>';
		}

		// Botón de acceso directo al sistema.
		$html .= '<div style="background:#f0f9ff;border:1px solid #bae6fd;border-radius:6px;padding:16px;margin:20px 0;">';
		$html .= '<p style="margin:0 0 10px;font-weight:bold;color:#0369a1;">Acceso al sistema</p>';
		$html .= '<p style="margin:0 0 14px;color:#374151;font-size:13px;">Puedes revisar el detalle completo de esta OI e ir actualizando su estado directamente desde el panel:</p>';
		$html .= '<p style="text-align:center;margin:0;">';
		$html .= '<a href="' . esc_url( $url_oi ) . '" style="background:' . $color . ';color:#fff;text-decoration:none;padding:10px 24px;border-radius:6px;font-weight:bold;font-size:14px;display:inline-block;">Ver OI ' . $numero_ingreso . ' en el panel</a>';
		$html .= '</p>';
		$html .= '<p style="color:#9ca3af;font-size:12px;margin:10px 0 0;text-align:center;">Si el botón no funciona: <a href="' . esc_url( $url_oi ) . '" style="color:' . $color . ';word-break:break-all;">' . esc_url( $url_oi ) . '</a></p>';
		$html .= '</div>';

		$html .= '</td></tr>';
		$html .= '<tr><td style="background:' . $color . ';height:4px;"></td></tr>';
		$html .= '<tr><td style="padding:16px 32px;text-align:center;color:#9ca3af;font-size:12px;">';
		$html .= '© ' . gmdate( 'Y' ) . ' ' . esc_html( $empresa ) . ' — Este correo fue generado automáticamente.';
		$html .= '</td></tr></table></td></tr></table></body></html>';

		$headers = array(
			'Content-Type: text/html; charset=UTF-8',
			'From: ' . $empresa . ' <' . $from . '>',
		);

		return wp_mail( $datos_tecnico['correo'], $asunto, $html, $headers );
	}

	/**
	 * EMAIL — Aviso al técnico de nueva Orden de Trabajo asignada.
	 * Se envía cuando el administrador crea una OT y asigna un técnico responsable.
	 * NO incluye montos, precios ni información financiera.
	 *
	 * @param int $evento_id Post ID del evento_servicio (tipo 'ot').
	 * @return bool
	 */
	public static function send_ot_a_tecnico( $evento_id ) {
		if ( ! (bool) get_option( 'calibratrack_email_enabled', true ) ) {
			return false;
		}

		$datos_tecnico = self::get_datos_tecnico( $evento_id );
		if ( ! $datos_tecnico ) {
			return false;
		}

		$empresa  = (string) get_option( 'calibratrack_empresa_nombre', 'TrueTech SpA' );
		$from     = (string) get_option( 'calibratrack_email_from', get_option( 'admin_email' ) );
		$color    = esc_attr( (string) get_option( 'calibratrack_pdf_color_primario', '#00AEEF' ) );

		$numero_ot  = (string) get_post_meta( $evento_id, CalibraTrack_Meta_Keys::EVENTO_NUMERO_OT, true );
		$equipo_id  = (int) get_post_meta( $evento_id, CalibraTrack_Meta_Keys::EVENTO_EQUIPO_ID, true );
		$serie      = $equipo_id ? (string) get_post_meta( $equipo_id, CalibraTrack_Meta_Keys::EQUIPO_SERIE, true ) : '—';
		$marca      = $equipo_id ? (string) get_post_meta( $equipo_id, CalibraTrack_Meta_Keys::EQUIPO_MARCA, true ) : '';
		$modelo     = $equipo_id ? (string) get_post_meta( $equipo_id, CalibraTrack_Meta_Keys::EQUIPO_MODELO, true ) : '';
		$tipo       = (string) get_post_meta( $evento_id, CalibraTrack_Meta_Keys::EVENTO_TIPO, true );
		$tipos_map  = CalibraTrack_Helpers::get_tipos_evento();
		$tipo_label = isset( $tipos_map[ $tipo ] ) ? $tipos_map[ $tipo ] : $tipo;

		// OI vinculada (referencia para el técnico).
		$oi_id         = (int) get_post_meta( $evento_id, CalibraTrack_Meta_Keys::EVENTO_INGRESO_RELACIONADO_ID, true );
		$numero_oi     = $oi_id ? (string) get_post_meta( $oi_id, CalibraTrack_Meta_Keys::EVENTO_NUMERO_OI, true ) : '';
		$numero_oi_fmt = ! empty( $numero_oi ) ? $numero_oi : ( $oi_id > 0 ? 'OI-' . $oi_id : '—' );

		$falla_reportada = (string) get_post_meta( $evento_id, CalibraTrack_Meta_Keys::EVENTO_FALLA_REPORTADA, true );

		$fecha_raw = (string) get_post_meta( $evento_id, CalibraTrack_Meta_Keys::EVENTO_FECHA_EJECUCION, true );
		$fecha_dt  = $fecha_raw ? DateTime::createFromFormat( 'Y-m-d', $fecha_raw ) : null;
		$fecha_fmt = $fecha_dt ? $fecha_dt->format( 'd/m/Y' ) : '—';

		$proxima_raw = (string) get_post_meta( $evento_id, CalibraTrack_Meta_Keys::EVENTO_PROXIMA_FECHA_CONTROL, true );
		$proxima_dt  = $proxima_raw ? DateTime::createFromFormat( 'Y-m-d', $proxima_raw ) : null;
		$proxima_fmt = $proxima_dt ? $proxima_dt->format( 'd/m/Y' ) : '—';

		// URL de la OT en el panel del técnico (/panel/evento/{id}/).
		$url_ot = home_url( '/panel/evento/' . $evento_id . '/' );

		$nombre_tecnico = esc_html( $datos_tecnico['nombre'] );

		$asunto = sprintf(
			/* translators: 1: número OT 2: tipo servicio 3: marca modelo */
			__( '[OT asignada] %1$s — %2$s de equipo %3$s', 'calibratrack' ),
			$numero_ot,
			$tipo_label,
			$marca . ' ' . $modelo
		);

		$html  = '<!DOCTYPE html><html lang="es"><head><meta charset="UTF-8">';
		$html .= '<meta name="viewport" content="width=device-width,initial-scale=1.0"></head>';
		$html .= '<body style="margin:0;padding:0;font-family:Arial,sans-serif;background:#f3f4f6;">';
		$html .= '<table width="100%" cellpadding="0" cellspacing="0" style="background:#f3f4f6;">';
		$html .= '<tr><td align="center" style="padding:32px 16px;">';
		$html .= '<table width="600" cellpadding="0" cellspacing="0" style="max-width:600px;background:#fff;border-radius:8px;overflow:hidden;">';
		$html .= '<tr><td style="background:' . $color . ';height:6px;"></td></tr>';
		$html .= '<tr><td style="padding:24px 32px 12px;">';
		$html .= '<p style="margin:0;font-size:22px;font-weight:bold;color:' . $color . ';">' . esc_html( $empresa ) . '</p>';
		$html .= '<p style="margin:4px 0 0;font-size:15px;color:#374151;font-weight:bold;">Nueva Orden de Trabajo asignada</p>';
		$html .= '</td></tr>';
		$html .= '<tr><td style="padding:8px 32px 24px;">';
		$html .= '<p style="color:#374151;">Estimado/a <strong>' . $nombre_tecnico . '</strong>,</p>';
		$html .= '<p style="color:#374151;">Se te ha emitido una <strong>Orden de Trabajo</strong>. A continuación los antecedentes del servicio a realizar:</p>';
		$html .= '<table width="100%" cellpadding="0" cellspacing="0" style="border:1px solid #e5e7eb;border-radius:6px;margin:16px 0;font-size:14px;">';
		$html .= '<tr style="background:#f9fafb;"><td style="padding:8px 12px;font-weight:bold;width:160px;color:#374151;">N° OT</td>';
		$html .= '<td style="padding:8px 12px;color:#111827;font-weight:600;">' . esc_html( $numero_ot ) . '</td></tr>';
		$html .= '<tr><td style="padding:8px 12px;font-weight:bold;color:#374151;">OI vinculada</td>';
		$html .= '<td style="padding:8px 12px;color:#111827;">' . esc_html( $numero_oi_fmt ) . '</td></tr>';
		$html .= '<tr style="background:#f9fafb;"><td style="padding:8px 12px;font-weight:bold;color:#374151;">Tipo de servicio</td>';
		$html .= '<td style="padding:8px 12px;color:#111827;">' . esc_html( $tipo_label ) . '</td></tr>';
		$html .= '<tr><td style="padding:8px 12px;font-weight:bold;color:#374151;">Equipo</td>';
		$html .= '<td style="padding:8px 12px;color:#111827;">' . esc_html( $marca . ' ' . $modelo ) . '</td></tr>';
		$html .= '<tr style="background:#f9fafb;"><td style="padding:8px 12px;font-weight:bold;color:#374151;">N° de Serie</td>';
		$html .= '<td style="padding:8px 12px;color:#111827;font-family:monospace;">' . esc_html( $serie ) . '</td></tr>';
		$html .= '<tr><td style="padding:8px 12px;font-weight:bold;color:#374151;">Fecha recepción</td>';
		$html .= '<td style="padding:8px 12px;color:#111827;">' . esc_html( $fecha_fmt ) . '</td></tr>';
		$html .= '<tr style="background:#f9fafb;"><td style="padding:8px 12px;font-weight:bold;color:#374151;">Próx. control</td>';
		$html .= '<td style="padding:8px 12px;color:#111827;">' . esc_html( $proxima_fmt ) . '</td></tr>';
		$html .= '</table>';
		if ( ! empty( $falla_reportada ) ) {
			$html .= '<p style="margin:8px 0 4px;font-weight:bold;color:#374151;">Falla / defecto reportado:</p>';
			$html .= '<div style="border:1px solid #e5e7eb;border-radius:4px;padding:10px 14px;background:#fafafa;color:#374151;font-size:13px;white-space:pre-wrap;">';
			$html .= esc_html( $falla_reportada );
			$html .= '</div>';
		}
		$html .= '<div style="background:#f0f9ff;border:1px solid #bae6fd;border-radius:6px;padding:16px;margin:20px 0;">';
		$html .= '<p style="margin:0 0 10px;font-weight:bold;color:#0369a1;">Acceso al sistema</p>';
		$html .= '<p style="margin:0 0 14px;color:#374151;font-size:13px;">Puedes ingresar avances, agregar evidencia fotográfica y actualizar el estado desde tu panel:</p>';
		$html .= '<p style="text-align:center;margin:0;">';
		$html .= '<a href="' . esc_url( $url_ot ) . '" style="background:' . $color . ';color:#fff;text-decoration:none;padding:10px 24px;border-radius:6px;font-weight:bold;font-size:14px;display:inline-block;">Ver OT ' . esc_html( $numero_ot ) . ' en el panel</a>';
		$html .= '</p>';
		$html .= '<p style="color:#9ca3af;font-size:12px;margin:10px 0 0;text-align:center;">Si el botón no funciona: <a href="' . esc_url( $url_ot ) . '" style="color:' . $color . ';word-break:break-all;">' . esc_url( $url_ot ) . '</a></p>';
		$html .= '</div>';
		$html .= '</td></tr>';
		$html .= '<tr><td style="background:' . $color . ';height:4px;"></td></tr>';
		$html .= '<tr><td style="padding:16px 32px;text-align:center;color:#9ca3af;font-size:12px;">';
		$html .= '© ' . gmdate( 'Y' ) . ' ' . esc_html( $empresa ) . ' — Este correo fue generado automáticamente.';
		$html .= '</td></tr></table></td></tr></table></body></html>';

		$headers = array(
			'Content-Type: text/html; charset=UTF-8',
			'From: ' . $empresa . ' <' . $from . '>',
		);

		return wp_mail( $datos_tecnico['correo'], $asunto, $html, $headers );
	}

	/**
	 * EMAIL — Aviso al administrador cuando el técnico marca una OT como "Listo para revisión".
	 * Solo se envía cuando el estado CAMBIA a listo_revision (no si ya estaba en ese estado).
	 *
	 * @param int $evento_id   Post ID de la OT.
	 * @param int $tecnico_id  ID del técnico que hizo el cambio.
	 * @return bool
	 */
	public static function send_listo_revision_a_admin( $evento_id, $tecnico_id ) {
		if ( ! (bool) get_option( 'calibratrack_email_enabled', true ) ) {
			return false;
		}

		$admin_email = (string) get_option( 'admin_email' );
		if ( ! is_email( $admin_email ) ) {
			return false;
		}

		$empresa  = (string) get_option( 'calibratrack_empresa_nombre', 'TrueTech SpA' );
		$from     = (string) get_option( 'calibratrack_email_from', $admin_email );
		$color    = esc_attr( (string) get_option( 'calibratrack_pdf_color_primario', '#00AEEF' ) );

		$numero_ot  = (string) get_post_meta( $evento_id, CalibraTrack_Meta_Keys::EVENTO_NUMERO_OT, true );
		$equipo_id  = (int) get_post_meta( $evento_id, CalibraTrack_Meta_Keys::EVENTO_EQUIPO_ID, true );
		$serie      = $equipo_id ? (string) get_post_meta( $equipo_id, CalibraTrack_Meta_Keys::EQUIPO_SERIE, true ) : '—';
		$marca      = $equipo_id ? (string) get_post_meta( $equipo_id, CalibraTrack_Meta_Keys::EQUIPO_MARCA, true ) : '';
		$modelo     = $equipo_id ? (string) get_post_meta( $equipo_id, CalibraTrack_Meta_Keys::EQUIPO_MODELO, true ) : '';
		$tipo       = (string) get_post_meta( $evento_id, CalibraTrack_Meta_Keys::EVENTO_TIPO, true );
		$tipos_map  = CalibraTrack_Helpers::get_tipos_evento();
		$tipo_label = isset( $tipos_map[ $tipo ] ) ? $tipos_map[ $tipo ] : $tipo;

		$tecnico_user  = get_user_by( 'id', $tecnico_id );
		$nombre_tecnico = $tecnico_user ? $tecnico_user->display_name : __( 'Técnico', 'calibratrack' );

		$url_ot = home_url( '/panel/ot/' . $evento_id . '/' );

		$asunto = sprintf(
			/* translators: 1: número OT */
			__( '[CalibraTrack] OT %1$s — Listo para revisión', 'calibratrack' ),
			$numero_ot
		);

		$html  = '<!DOCTYPE html><html lang="es"><head><meta charset="UTF-8">';
		$html .= '<meta name="viewport" content="width=device-width,initial-scale=1.0"></head>';
		$html .= '<body style="margin:0;padding:0;font-family:Arial,sans-serif;background:#f3f4f6;">';
		$html .= '<table width="100%" cellpadding="0" cellspacing="0" style="background:#f3f4f6;">';
		$html .= '<tr><td align="center" style="padding:32px 16px;">';
		$html .= '<table width="600" cellpadding="0" cellspacing="0" style="max-width:600px;background:#fff;border-radius:8px;overflow:hidden;">';
		$html .= '<tr><td style="background:' . $color . ';height:6px;"></td></tr>';
		$html .= '<tr><td style="padding:24px 32px 12px;">';
		$html .= '<p style="margin:0;font-size:22px;font-weight:bold;color:' . $color . ';">' . esc_html( $empresa ) . '</p>';
		$html .= '<p style="margin:4px 0 0;font-size:15px;color:#374151;font-weight:bold;">OT lista para revisión — Acción requerida</p>';
		$html .= '</td></tr>';
		$html .= '<tr><td style="padding:8px 32px 24px;">';
		$html .= '<p style="color:#374151;">El técnico <strong>' . esc_html( $nombre_tecnico ) . '</strong> ha marcado la siguiente Orden de Trabajo como <strong style="color:#0369a1;">Listo para revisión</strong>:</p>';
		$html .= '<table width="100%" cellpadding="0" cellspacing="0" style="border:1px solid #e5e7eb;border-radius:6px;margin:16px 0;font-size:14px;">';
		$html .= '<tr style="background:#f9fafb;"><td style="padding:8px 12px;font-weight:bold;width:160px;color:#374151;">N° OT</td>';
		$html .= '<td style="padding:8px 12px;color:#111827;">' . esc_html( $numero_ot ) . '</td></tr>';
		$html .= '<tr><td style="padding:8px 12px;font-weight:bold;color:#374151;">Tipo de servicio</td>';
		$html .= '<td style="padding:8px 12px;color:#111827;">' . esc_html( $tipo_label ) . '</td></tr>';
		$html .= '<tr style="background:#f9fafb;"><td style="padding:8px 12px;font-weight:bold;color:#374151;">Equipo</td>';
		$html .= '<td style="padding:8px 12px;color:#111827;">' . esc_html( $marca . ' ' . $modelo ) . '</td></tr>';
		$html .= '<tr><td style="padding:8px 12px;font-weight:bold;color:#374151;">N° de Serie</td>';
		$html .= '<td style="padding:8px 12px;color:#111827;font-family:monospace;">' . esc_html( $serie ) . '</td></tr>';
		$html .= '<tr style="background:#f9fafb;"><td style="padding:8px 12px;font-weight:bold;color:#374151;">Estado</td>';
		$html .= '<td style="padding:8px 12px;"><span style="background:#dbeafe;color:#1e40af;border:1px solid #93c5fd;padding:2px 10px;border-radius:4px;font-weight:bold;">Listo para revisión</span></td></tr>';
		$html .= '</table>';
		$html .= '<div style="background:#fef9c3;border:1px solid #fde047;border-radius:6px;padding:14px 16px;margin:16px 0;">';
		$html .= '<p style="margin:0;color:#713f12;font-weight:bold;">Acción requerida:</p>';
		$html .= '<p style="margin:6px 0 0;color:#713f12;">Revise el trabajo realizado y cambie el estado a <strong>Completado</strong> para generar el certificado y notificar al cliente.</p>';
		$html .= '</div>';
		$html .= '<p style="text-align:center;margin:20px 0;">';
		$html .= '<a href="' . esc_url( $url_ot ) . '" style="background:' . $color . ';color:#fff;text-decoration:none;padding:12px 28px;border-radius:6px;font-weight:bold;font-size:15px;display:inline-block;">Ver OT y completar</a>';
		$html .= '</p>';
		$html .= '</td></tr>';
		$html .= '<tr><td style="background:' . $color . ';height:4px;"></td></tr>';
		$html .= '<tr><td style="padding:16px 32px;text-align:center;color:#9ca3af;font-size:12px;">';
		$html .= '© ' . gmdate( 'Y' ) . ' ' . esc_html( $empresa ) . ' — Este correo fue generado automáticamente.';
		$html .= '</td></tr></table></td></tr></table></body></html>';

		$headers = array(
			'Content-Type: text/html; charset=UTF-8',
			'From: ' . $empresa . ' <' . $from . '>',
		);

		return wp_mail( $admin_email, $asunto, $html, $headers );
	}

	/**
	 * EMAIL — Aviso al administrador cuando el técnico marca una OT como "Completado".
	 * Informa que el certificado ya fue generado y enviado al cliente.
	 *
	 * @param int $evento_id   Post ID de la OT.
	 * @param int $tecnico_id  ID del técnico que hizo el cambio.
	 * @return bool
	 */
	public static function send_completado_tecnico_a_admin( $evento_id, $tecnico_id ) {
		if ( ! (bool) get_option( 'calibratrack_email_enabled', true ) ) {
			return false;
		}

		$admin_email = (string) get_option( 'admin_email' );
		if ( ! is_email( $admin_email ) ) {
			return false;
		}

		$empresa  = (string) get_option( 'calibratrack_empresa_nombre', 'TrueTech SpA' );
		$from     = (string) get_option( 'calibratrack_email_from', $admin_email );
		$color    = esc_attr( (string) get_option( 'calibratrack_pdf_color_primario', '#00AEEF' ) );

		$numero_ot  = (string) get_post_meta( $evento_id, CalibraTrack_Meta_Keys::EVENTO_NUMERO_OT, true );
		$equipo_id  = (int) get_post_meta( $evento_id, CalibraTrack_Meta_Keys::EVENTO_EQUIPO_ID, true );
		$serie      = $equipo_id ? (string) get_post_meta( $equipo_id, CalibraTrack_Meta_Keys::EQUIPO_SERIE, true ) : '—';
		$marca      = $equipo_id ? (string) get_post_meta( $equipo_id, CalibraTrack_Meta_Keys::EQUIPO_MARCA, true ) : '';
		$modelo     = $equipo_id ? (string) get_post_meta( $equipo_id, CalibraTrack_Meta_Keys::EQUIPO_MODELO, true ) : '';
		$tipo       = (string) get_post_meta( $evento_id, CalibraTrack_Meta_Keys::EVENTO_TIPO, true );
		$tipos_map  = CalibraTrack_Helpers::get_tipos_evento();
		$tipo_label = isset( $tipos_map[ $tipo ] ) ? $tipos_map[ $tipo ] : $tipo;

		$tecnico_user   = get_user_by( 'id', $tecnico_id );
		$nombre_tecnico = $tecnico_user ? $tecnico_user->display_name : __( 'Técnico', 'calibratrack' );

		// Datos del cliente para informar al admin.
		$cliente_id    = (int) get_post_meta( $equipo_id, CalibraTrack_Meta_Keys::EQUIPO_CLIENTE_ID, true );
		$nombre_cliente = '';
		if ( $cliente_id > 0 ) {
			$nombre_cliente = get_post_meta( $cliente_id, CalibraTrack_Meta_Keys::CLIENTE_NOMBRE_EMPRESA, true );
			if ( empty( $nombre_cliente ) ) {
				$nombre_cliente = get_the_title( $cliente_id );
			}
		}

		$url_ot = home_url( '/panel/ot/' . $evento_id . '/' );

		$asunto = sprintf(
			/* translators: 1: número OT */
			__( '[CalibraTrack] OT %1$s — Completada por técnico', 'calibratrack' ),
			$numero_ot
		);

		$html  = '<!DOCTYPE html><html lang="es"><head><meta charset="UTF-8">';
		$html .= '<meta name="viewport" content="width=device-width,initial-scale=1.0"></head>';
		$html .= '<body style="margin:0;padding:0;font-family:Arial,sans-serif;background:#f3f4f6;">';
		$html .= '<table width="100%" cellpadding="0" cellspacing="0" style="background:#f3f4f6;">';
		$html .= '<tr><td align="center" style="padding:32px 16px;">';
		$html .= '<table width="600" cellpadding="0" cellspacing="0" style="max-width:600px;background:#fff;border-radius:8px;overflow:hidden;">';
		$html .= '<tr><td style="background:' . $color . ';height:6px;"></td></tr>';
		$html .= '<tr><td style="padding:24px 32px 12px;">';
		$html .= '<p style="margin:0;font-size:22px;font-weight:bold;color:' . $color . ';">' . esc_html( $empresa ) . '</p>';
		$html .= '<p style="margin:4px 0 0;font-size:15px;color:#374151;font-weight:bold;">OT completada por técnico — Certificado emitido</p>';
		$html .= '</td></tr>';
		$html .= '<tr><td style="padding:8px 32px 24px;">';
		$html .= '<p style="color:#374151;">El técnico <strong>' . esc_html( $nombre_tecnico ) . '</strong> ha marcado la siguiente Orden de Trabajo como <strong style="color:#059669;">Completada</strong>. El certificado fue generado y enviado al cliente.</p>';
		$html .= '<table width="100%" cellpadding="0" cellspacing="0" style="border:1px solid #e5e7eb;border-radius:6px;margin:16px 0;font-size:14px;">';
		$html .= '<tr style="background:#f9fafb;"><td style="padding:8px 12px;font-weight:bold;width:160px;color:#374151;">N° OT</td>';
		$html .= '<td style="padding:8px 12px;color:#111827;">' . esc_html( $numero_ot ) . '</td></tr>';
		$html .= '<tr><td style="padding:8px 12px;font-weight:bold;color:#374151;">Tipo de servicio</td>';
		$html .= '<td style="padding:8px 12px;color:#111827;">' . esc_html( $tipo_label ) . '</td></tr>';
		$html .= '<tr style="background:#f9fafb;"><td style="padding:8px 12px;font-weight:bold;color:#374151;">Equipo</td>';
		$html .= '<td style="padding:8px 12px;color:#111827;">' . esc_html( $marca . ' ' . $modelo ) . '</td></tr>';
		$html .= '<tr><td style="padding:8px 12px;font-weight:bold;color:#374151;">N° de Serie</td>';
		$html .= '<td style="padding:8px 12px;color:#111827;font-family:monospace;">' . esc_html( $serie ) . '</td></tr>';
		if ( ! empty( $nombre_cliente ) ) {
			$html .= '<tr style="background:#f9fafb;"><td style="padding:8px 12px;font-weight:bold;color:#374151;">Cliente</td>';
			$html .= '<td style="padding:8px 12px;color:#111827;">' . esc_html( $nombre_cliente ) . '</td></tr>';
		}
		$html .= '<tr' . ( ! empty( $nombre_cliente ) ? '' : ' style="background:#f9fafb;"' ) . '><td style="padding:8px 12px;font-weight:bold;color:#374151;">Estado</td>';
		$html .= '<td style="padding:8px 12px;"><span style="background:#d1fae5;color:#065f46;border:1px solid #6ee7b7;padding:2px 10px;border-radius:4px;font-weight:bold;">Completado</span></td></tr>';
		$html .= '</table>';
		$html .= '<p style="text-align:center;margin:20px 0;">';
		$html .= '<a href="' . esc_url( $url_ot ) . '" style="background:' . $color . ';color:#fff;text-decoration:none;padding:12px 28px;border-radius:6px;font-weight:bold;font-size:15px;display:inline-block;">Ver detalle de la OT</a>';
		$html .= '</p>';
		$html .= '</td></tr>';
		$html .= '<tr><td style="background:' . $color . ';height:4px;"></td></tr>';
		$html .= '<tr><td style="padding:16px 32px;text-align:center;color:#9ca3af;font-size:12px;">';
		$html .= '© ' . gmdate( 'Y' ) . ' ' . esc_html( $empresa ) . ' — Este correo fue generado automáticamente.';
		$html .= '</td></tr></table></td></tr></table></body></html>';

		$headers = array(
			'Content-Type: text/html; charset=UTF-8',
			'From: ' . $empresa . ' <' . $from . '>',
		);

		return wp_mail( $admin_email, $asunto, $html, $headers );
	}

	/**
	 * EMAIL — Notifica al técnico que el admin le escribió un mensaje en la OT.
	 *
	 * @param int    $evento_id Post ID de la OT.
	 * @param int    $autor_id  User ID del admin que escribió el mensaje.
	 * @param string $texto     Texto del mensaje (ya sanitizado).
	 * @return bool True si wp_mail reportó éxito.
	 */
	public static function send_mensaje_a_tecnico( $evento_id, $autor_id, $texto ) {
		if ( ! (bool) get_option( 'calibratrack_email_enabled', true ) ) {
			return false;
		}

		$datos_tecnico = self::get_datos_tecnico( $evento_id );
		if ( ! $datos_tecnico ) {
			return false;
		}

		$empresa    = (string) get_option( 'calibratrack_empresa_nombre', 'TrueTech SpA' );
		$from       = (string) get_option( 'calibratrack_email_from', get_option( 'admin_email' ) );
		$color      = esc_attr( (string) get_option( 'calibratrack_pdf_color_primario', '#00AEEF' ) );
		$numero_ot  = (string) get_post_meta( $evento_id, CalibraTrack_Meta_Keys::EVENTO_NUMERO_OT, true );
		$url_ot     = home_url( '/panel/evento/' . $evento_id . '/' );

		$autor_user    = get_userdata( $autor_id );
		$autor_nombre  = $autor_user ? $autor_user->display_name : $empresa;

		$asunto = sprintf(
			/* translators: %s: número de OT */
			__( 'Nuevo mensaje en OT #%s', 'calibratrack' ),
			$numero_ot
		);

		$html  = self::build_email_header( $color, $empresa, __( 'Nuevo mensaje en tu OT', 'calibratrack' ) );
		$html .= '<p style="color:#374151;">Hola <strong>' . esc_html( $datos_tecnico['nombre'] ) . '</strong>,</p>';
		$html .= '<p style="color:#374151;">El administrador <strong>' . esc_html( $autor_nombre ) . '</strong> ';
		$html .= 'te ha enviado un mensaje en la Orden de Trabajo <strong>#' . esc_html( $numero_ot ) . '</strong>:</p>';
		$html .= '<div style="background:#dbeafe;border:1px solid #93c5fd;border-radius:6px;padding:14px 16px;margin:16px 0;">';
		$html .= '<p style="margin:0;color:#1e3a5f;white-space:pre-wrap;">' . esc_html( $texto ) . '</p>';
		$html .= '</div>';
		$html .= '<p style="text-align:center;margin:20px 0;">';
		$html .= '<a href="' . esc_url( $url_ot ) . '" style="background:' . $color . ';color:#fff;text-decoration:none;padding:12px 28px;border-radius:6px;font-weight:bold;font-size:15px;display:inline-block;">';
		$html .= esc_html__( 'Ver OT y responder', 'calibratrack' ) . '</a>';
		$html .= '</p>';
		$html .= self::build_email_footer( $color, $empresa );

		$headers = array(
			'Content-Type: text/html; charset=UTF-8',
			'From: ' . $empresa . ' <' . $from . '>',
		);

		return wp_mail( $datos_tecnico['correo'], $asunto, $html, $headers );
	}

	/**
	 * EMAIL — Notifica al admin que el técnico le escribió un mensaje en la OT.
	 *
	 * @param int    $evento_id Post ID de la OT.
	 * @param int    $autor_id  User ID del técnico que escribió el mensaje.
	 * @param string $texto     Texto del mensaje (ya sanitizado).
	 * @return bool True si wp_mail reportó éxito.
	 */
	public static function send_mensaje_a_admin( $evento_id, $autor_id, $texto ) {
		if ( ! (bool) get_option( 'calibratrack_email_enabled', true ) ) {
			return false;
		}

		$admin_email = (string) get_option( 'admin_email' );
		if ( ! is_email( $admin_email ) ) {
			return false;
		}

		$empresa   = (string) get_option( 'calibratrack_empresa_nombre', 'TrueTech SpA' );
		$from      = (string) get_option( 'calibratrack_email_from', $admin_email );
		$color     = esc_attr( (string) get_option( 'calibratrack_pdf_color_primario', '#00AEEF' ) );
		$numero_ot = (string) get_post_meta( $evento_id, CalibraTrack_Meta_Keys::EVENTO_NUMERO_OT, true );
		$url_ot    = home_url( '/panel/ot/' . $evento_id . '/' );

		$autor_user   = get_userdata( $autor_id );
		$autor_nombre = $autor_user ? $autor_user->display_name : __( 'Técnico', 'calibratrack' );

		$asunto = sprintf(
			/* translators: %s: número de OT */
			__( 'Nuevo mensaje en OT #%s', 'calibratrack' ),
			$numero_ot
		);

		$html  = self::build_email_header( $color, $empresa, __( 'Nuevo mensaje en una OT', 'calibratrack' ) );
		$html .= '<p style="color:#374151;">El técnico <strong>' . esc_html( $autor_nombre ) . '</strong> ';
		$html .= 'ha enviado un mensaje en la Orden de Trabajo <strong>#' . esc_html( $numero_ot ) . '</strong>:</p>';
		$html .= '<div style="background:#dcfce7;border:1px solid #86efac;border-radius:6px;padding:14px 16px;margin:16px 0;">';
		$html .= '<p style="margin:0;color:#14532d;white-space:pre-wrap;">' . esc_html( $texto ) . '</p>';
		$html .= '</div>';
		$html .= '<p style="text-align:center;margin:20px 0;">';
		$html .= '<a href="' . esc_url( $url_ot ) . '" style="background:' . $color . ';color:#fff;text-decoration:none;padding:12px 28px;border-radius:6px;font-weight:bold;font-size:15px;display:inline-block;">';
		$html .= esc_html__( 'Ver OT y responder', 'calibratrack' ) . '</a>';
		$html .= '</p>';
		$html .= self::build_email_footer( $color, $empresa );

		$headers = array(
			'Content-Type: text/html; charset=UTF-8',
			'From: ' . $empresa . ' <' . $from . '>',
		);

		return wp_mail( $admin_email, $asunto, $html, $headers );
	}

	/**
	 * Construye el encabezado HTML reutilizable de los emails.
	 *
	 * @param string $color   Color primario (ya escapado con esc_attr).
	 * @param string $empresa Nombre de la empresa (ya escapado).
	 * @param string $titulo  Subtítulo del email (ya escapado).
	 * @return string HTML del header.
	 */
	private static function build_email_header( $color, $empresa, $titulo ) {
		$html  = '<!DOCTYPE html><html lang="es"><head><meta charset="UTF-8">';
		$html .= '<meta name="viewport" content="width=device-width,initial-scale=1.0"></head>';
		$html .= '<body style="margin:0;padding:0;font-family:Arial,sans-serif;background:#f3f4f6;">';
		$html .= '<table width="100%" cellpadding="0" cellspacing="0" style="background:#f3f4f6;">';
		$html .= '<tr><td align="center" style="padding:32px 16px;">';
		$html .= '<table width="600" cellpadding="0" cellspacing="0" style="max-width:600px;background:#fff;border-radius:8px;overflow:hidden;">';
		$html .= '<tr><td style="background:' . $color . ';height:6px;"></td></tr>';
		$html .= '<tr><td style="padding:24px 32px 12px;">';
		$html .= '<p style="margin:0;font-size:22px;font-weight:bold;color:' . $color . ';">' . esc_html( $empresa ) . '</p>';
		$html .= '<p style="margin:4px 0 0;font-size:15px;color:#374151;font-weight:bold;">' . esc_html( $titulo ) . '</p>';
		$html .= '</td></tr>';
		$html .= '<tr><td style="padding:8px 32px 24px;">';
		return $html;
	}

	/**
	 * Construye el pie HTML reutilizable de los emails.
	 *
	 * @param string $color   Color primario (ya escapado con esc_attr).
	 * @param string $empresa Nombre de la empresa (ya escapado).
	 * @return string HTML del footer.
	 */
	private static function build_email_footer( $color, $empresa ) {
		$html  = '</td></tr>';
		$html .= '<tr><td style="background:' . $color . ';height:4px;"></td></tr>';
		$html .= '<tr><td style="padding:16px 32px;text-align:center;color:#9ca3af;font-size:12px;">';
		$html .= '© ' . gmdate( 'Y' ) . ' ' . esc_html( $empresa ) . ' — Este correo fue generado automáticamente.';
		$html .= '</td></tr></table></td></tr></table></body></html>';
		return $html;
	}

	/**
	 * Recordatorio de vencimiento enviado directamente al cliente.
	 * Se llama desde CalibraTrack_Cron para alertar de próximas calibraciones/mantenciones.
	 *
	 * @param int    $equipo_id      Post ID del equipo.
	 * @param string $proxima_fecha  Próxima fecha de control (YYYY-MM-DD).
	 * @param int    $dias_restantes Días aproximados hasta el vencimiento.
	 * @return bool True si wp_mail reportó éxito.
	 */
	public static function send_recordatorio_a_cliente( $equipo_id, $proxima_fecha, $dias_restantes ) {
		if ( ! (bool) get_option( 'calibratrack_email_enabled', true ) ) {
			return false;
		}

		$cliente_id = (int) get_post_meta( $equipo_id, CalibraTrack_Meta_Keys::EQUIPO_CLIENTE_PROPIETARIO, true );
		if ( ! $cliente_id ) {
			return false;
		}

		$correo_cliente = (string) get_post_meta( $cliente_id, CalibraTrack_Meta_Keys::CLIENTE_CORREO, true );
		if ( empty( $correo_cliente ) || ! is_email( $correo_cliente ) ) {
			return false;
		}

		$empresa        = (string) get_option( 'calibratrack_empresa_nombre', 'TrueTech SpA' );
		$from           = (string) get_option( 'calibratrack_email_from', get_option( 'admin_email' ) );
		$color          = esc_attr( (string) get_option( 'calibratrack_pdf_color_primario', '#00AEEF' ) );
		$nombre_cliente = (string) get_post_meta( $cliente_id, CalibraTrack_Meta_Keys::CLIENTE_NOMBRE_EMPRESA, true );
		$serie          = (string) get_post_meta( $equipo_id, CalibraTrack_Meta_Keys::EQUIPO_SERIE, true );
		$marca          = (string) get_post_meta( $equipo_id, CalibraTrack_Meta_Keys::EQUIPO_MARCA, true );
		$modelo         = (string) get_post_meta( $equipo_id, CalibraTrack_Meta_Keys::EQUIPO_MODELO, true );

		$fecha_dt  = DateTime::createFromFormat( 'Y-m-d', $proxima_fecha );
		$fecha_fmt = $fecha_dt ? $fecha_dt->format( 'd/m/Y' ) : $proxima_fecha;

		$dias_int = (int) $dias_restantes;
		if ( $dias_int <= 7 ) {
			$asunto = sprintf(
				/* translators: 1: empresa 2: marca modelo */
				__( '[URGENTE] %1$s — Vencimiento próximo de calibración: %2$s', 'calibratrack' ),
				$empresa,
				$marca . ' ' . $modelo
			);
			$urgencia_label = __( 'URGENTE:', 'calibratrack' );
			$urgencia_color = '#dc2626';
		} else {
			$asunto = sprintf(
				/* translators: 1: empresa 2: marca modelo */
				__( '[Aviso] %1$s — Recordatorio de calibración próxima: %2$s', 'calibratrack' ),
				$empresa,
				$marca . ' ' . $modelo
			);
			$urgencia_label = __( 'Recordatorio:', 'calibratrack' );
			$urgencia_color = '#0369a1';
		}

		$html  = '<!DOCTYPE html><html lang="es"><head><meta charset="UTF-8">';
		$html .= '<meta name="viewport" content="width=device-width,initial-scale=1.0"></head>';
		$html .= '<body style="margin:0;padding:0;font-family:Arial,sans-serif;background:#f3f4f6;">';
		$html .= '<table width="100%" cellpadding="0" cellspacing="0" style="background:#f3f4f6;">';
		$html .= '<tr><td align="center" style="padding:32px 16px;">';
		$html .= '<table width="600" cellpadding="0" cellspacing="0" style="max-width:600px;background:#fff;border-radius:8px;overflow:hidden;">';
		$html .= '<tr><td style="background:' . $color . ';height:6px;"></td></tr>';
		$html .= '<tr><td style="padding:24px 32px 12px;">';
		$html .= '<p style="margin:0;font-size:22px;font-weight:bold;color:' . $color . ';">' . esc_html( $empresa ) . '</p>';
		$html .= '<p style="margin:4px 0 0;font-size:15px;color:' . esc_attr( $urgencia_color ) . ';font-weight:bold;">';
		$html .= esc_html( $urgencia_label ) . ' ' . __( 'Calibración / mantención próxima', 'calibratrack' );
		$html .= '</p></td></tr>';
		$html .= '<tr><td style="padding:8px 32px 24px;">';
		$html .= '<p style="color:#374151;">Estimado/a <strong>' . esc_html( $nombre_cliente ) . '</strong>,</p>';
		$html .= '<p style="color:#374151;">Le recordamos que el siguiente equipo tiene su próxima ';
		$html .= 'fecha de calibración o mantención en aproximadamente <strong>' . $dias_int . ' días</strong>:</p>';
		$html .= '<table width="100%" cellpadding="0" cellspacing="0" style="border:1px solid #e5e7eb;border-radius:6px;margin:16px 0;font-size:14px;">';
		$html .= '<tr style="background:#f9fafb;"><td style="padding:8px 12px;font-weight:bold;width:160px;color:#374151;">Equipo</td>';
		$html .= '<td style="padding:8px 12px;color:#111827;">' . esc_html( $marca . ' ' . $modelo ) . '</td></tr>';
		$html .= '<tr><td style="padding:8px 12px;font-weight:bold;color:#374151;">N° de Serie</td>';
		$html .= '<td style="padding:8px 12px;color:#111827;font-family:monospace;">' . esc_html( $serie ) . '</td></tr>';
		$html .= '<tr style="background:#f9fafb;"><td style="padding:8px 12px;font-weight:bold;color:#374151;">Fecha límite</td>';
		$html .= '<td style="padding:8px 12px;color:#111827;">' . esc_html( $fecha_fmt ) . '</td></tr>';
		$html .= '</table>';
		$html .= '<p style="color:#374151;">Le recomendamos contactarnos para programar el servicio con anticipación.</p>';
		$html .= '<p style="color:#6b7280;font-size:13px;">Para coordinar su servicio, responda este correo o contáctenos directamente.</p>';
		$html .= '</td></tr>';
		$html .= '<tr><td style="background:' . $color . ';height:4px;"></td></tr>';
		$html .= '<tr><td style="padding:16px 32px;text-align:center;color:#9ca3af;font-size:12px;">';
		$html .= '© ' . gmdate( 'Y' ) . ' ' . esc_html( $empresa ) . ' — Este correo fue generado automáticamente.';
		$html .= '</td></tr></table></td></tr></table></body></html>';

		$headers = array(
			'Content-Type: text/html; charset=UTF-8',
			'From: ' . $empresa . ' <' . $from . '>',
		);

		return wp_mail( $correo_cliente, $asunto, $html, $headers );
	}
}
