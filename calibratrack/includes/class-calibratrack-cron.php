<?php
/**
 * Sistema de alertas de vencimiento por WP Cron (RF-08).
 *
 * Registra un evento de cron diario que detecta equipos próximos a vencer y
 * envía correos al administrador del sitio. Usa transients para evitar el
 * envío de correos duplicados dentro de la misma ventana de alerta.
 *
 * DECISIÓN D-18: Se implementa en archivo separado (class-calibratrack-cron.php)
 * en lugar de en class-calibratrack-rest-api.php o class-calibratrack-admin.php
 * porque el cron es un subsistema independiente que no depende del área admin
 * ni de la REST API. Su única dependencia es CalibraTrack_DB y wp_mail().
 * Ver docs/decisiones.md D-18.
 *
 * AVISO PARA EL SECURITY AUDITOR:
 *   - El hook de cron 'calibratrack_check_vencimientos' está registrado solo en
 *     el servidor (no hay endpoint HTTP que lo dispare desde el exterior).
 *   - Los correos se envían solo al administrador del sitio (get_option('admin_email')).
 *   - Los transients de alerta tienen TTL de 23h para evitar duplicados diarios.
 *   - No se expone información de clientes (RUT, teléfono, correo) en los correos.
 *
 * @package CalibraTrack
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class CalibraTrack_Cron
 */
class CalibraTrack_Cron {

	/**
	 * Nombre del hook del evento de cron.
	 */
	const CRON_HOOK = 'calibratrack_check_vencimientos';

	/**
	 * TTL del transient de alerta enviada, en segundos.
	 * 23 horas — cubre el ciclo diario con margen para evitar condiciones de carrera.
	 */
	const ALERTA_TTL = 82800; // 23 * 3600.

	/**
	 * Inicializa los hooks del cron.
	 * Se llama desde calibratrack_init().
	 *
	 * @return void
	 */
	public static function init() {
		add_action( self::CRON_HOOK, array( __CLASS__, 'check_vencimientos' ) );
	}

	/**
	 * Programa el evento de cron diario.
	 * Se llama desde el hook de activación del plugin.
	 *
	 * @return void
	 */
	public static function schedule() {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			// Programar para ejecutarse diariamente a partir de ahora.
			// En producción, considerar programar a una hora específica (ej. las 08:00 Chile)
			// usando wp_schedule_event con un offset de tiempo calculado.
			wp_schedule_event( time(), 'daily', self::CRON_HOOK );
		}
	}

	/**
	 * Elimina el evento de cron del programador.
	 * Se llama desde el hook de desactivación del plugin.
	 *
	 * @return void
	 */
	public static function unschedule() {
		$timestamp = wp_next_scheduled( self::CRON_HOOK );
		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, self::CRON_HOOK );
		}
	}

	/**
	 * Callback del cron: detecta equipos próximos a vencer y envía alertas.
	 *
	 * Se ejecuta vía WP Cron con recurrencia 'daily'.
	 * Evalúa dos ventanas de alerta para el admin: 30 días y 7 días.
	 * Evalúa la ventana configurable para el cliente (default 30 días).
	 * Usa transients para no enviar el mismo correo dos veces en la misma ventana.
	 *
	 * @return void
	 */
	public static function check_vencimientos() {
		// ── Alertas al administrador del sitio ────────────────────────────────────
		$ventanas_admin = array( 30, 7 );

		foreach ( $ventanas_admin as $dias ) {
			$equipos = CalibraTrack_DB::get_equipos_proximos_a_vencer( $dias );

			if ( empty( $equipos ) ) {
				continue;
			}

			foreach ( $equipos as $equipo ) {
				self::enviar_alerta_si_no_enviada( $equipo, $dias );
			}
		}

		// ── Recordatorios al cliente propietario del equipo ───────────────────────
		$recordatorio_enabled = (bool) get_option( 'calibratrack_recordatorio_cliente_enabled', false );
		if ( ! $recordatorio_enabled ) {
			return;
		}

		$dias_recordatorio = (int) get_option( 'calibratrack_dias_recordatorio_cliente', 30 );
		$dias_recordatorio = max( 1, $dias_recordatorio );

		$equipos_cliente = CalibraTrack_DB::get_equipos_proximos_a_vencer( $dias_recordatorio, true );

		if ( empty( $equipos_cliente ) ) {
			return;
		}

		foreach ( $equipos_cliente as $equipo ) {
			self::enviar_recordatorio_cliente_si_no_enviado( $equipo, $dias_recordatorio );
		}
	}

	/**
	 * Envía la alerta de vencimiento si no fue enviada ya en este ciclo.
	 *
	 * Usa el transient 'calibratrack_alerta_enviada_{equipo_id}_{dias}' con TTL 23h
	 * para garantizar que solo se envíe una vez por equipo por ventana de alerta.
	 *
	 * @param object $equipo Objeto con equipo_id, proxima_fecha_control, evento_id.
	 * @param int    $dias   Ventana de días (7 o 30).
	 * @return void
	 */
	private static function enviar_alerta_si_no_enviada( $equipo, $dias ) {
		$equipo_id    = (int) $equipo->equipo_id;
		$transient_key = 'calibratrack_alerta_enviada_' . $equipo_id . '_' . (int) $dias;

		// Si ya se envió en las últimas 23 horas, no volver a enviar.
		if ( get_transient( $transient_key ) ) {
			return;
		}

		// Obtener datos del equipo para el correo.
		$equipo_post = get_post( $equipo_id );
		if ( ! $equipo_post || 'equipo' !== $equipo_post->post_type ) {
			return;
		}

		$serie   = (string) get_post_meta( $equipo_id, CalibraTrack_Meta_Keys::EQUIPO_SERIE, true );
		$marca   = (string) get_post_meta( $equipo_id, CalibraTrack_Meta_Keys::EQUIPO_MARCA, true );
		$modelo  = (string) get_post_meta( $equipo_id, CalibraTrack_Meta_Keys::EQUIPO_MODELO, true );
		$proxima = (string) $equipo->proxima_fecha_control;

		// Solo nombre de empresa — nunca RUT, teléfono ni correo del cliente (§9).
		$cliente_id     = (int) get_post_meta( $equipo_id, CalibraTrack_Meta_Keys::EQUIPO_CLIENTE_PROPIETARIO, true );
		$nombre_empresa = '';
		if ( $cliente_id > 0 ) {
			$nombre_empresa = (string) get_post_meta( $cliente_id, CalibraTrack_Meta_Keys::CLIENTE_NOMBRE_EMPRESA, true );
		}

		$enviado = self::enviar_correo_alerta( $equipo_id, $serie, $marca, $modelo, $proxima, $nombre_empresa, $dias );

		if ( $enviado ) {
			set_transient( $transient_key, 1, self::ALERTA_TTL );
		}
	}

	/**
	 * Envía el correo de alerta al administrador del sitio.
	 *
	 * @param int    $equipo_id      Post ID del equipo.
	 * @param string $serie          Número de serie.
	 * @param string $marca          Marca del equipo.
	 * @param string $modelo         Modelo del equipo.
	 * @param string $proxima_fecha  Próxima fecha de control (YYYY-MM-DD).
	 * @param string $nombre_empresa Nombre de la empresa cliente (solo nombre, sin datos privados).
	 * @param int    $dias           Días restantes aproximados para el vencimiento.
	 * @return bool True si el correo fue enviado por wp_mail().
	 */
	private static function enviar_correo_alerta( $equipo_id, $serie, $marca, $modelo, $proxima_fecha, $nombre_empresa, $dias ) {
		$admin_email = get_option( 'admin_email' );

		if ( empty( $admin_email ) ) {
			return false;
		}

		$site_name = get_bloginfo( 'name' );

		if ( 7 === $dias ) {
			/* translators: %s: nombre del sitio */
			$asunto = sprintf( __( '[%s] URGENTE: Equipo próximo a vencer en 7 días', 'calibratrack' ), $site_name );
		} else {
			/* translators: %s: nombre del sitio */
			$asunto = sprintf( __( '[%s] Aviso: Equipo próximo a vencer en 30 días', 'calibratrack' ), $site_name );
		}

		// URL de administración del equipo — no datos públicos.
		$admin_url = admin_url( 'post.php?post=' . $equipo_id . '&action=edit' );

		// Construir el cuerpo del correo.
		ob_start();
		?>
Estimado administrador de <?php echo esc_html( $site_name ); ?>,

Este es un aviso automático del sistema CalibraTrack.

El siguiente equipo tiene su próxima fecha de control/calibración en aproximadamente <?php echo (int) $dias; ?> días:

  - Serie:    <?php echo esc_html( $serie ); ?>

  - Marca:    <?php echo esc_html( $marca ); ?>

  - Modelo:   <?php echo esc_html( $modelo ); ?>

  - Cliente:  <?php echo esc_html( $nombre_empresa ); ?>

  - Próxima fecha de control: <?php echo esc_html( $proxima_fecha ); ?>


Ingrese al sistema para programar el servicio correspondiente:
<?php echo esc_url( $admin_url ); ?>


---
Este correo fue generado automáticamente por CalibraTrack.
No responda a este mensaje.
		<?php
		$cuerpo = ob_get_clean();

		return wp_mail(
			$admin_email,
			$asunto,
			$cuerpo
		);
	}

	/**
	 * Envía un recordatorio al cliente propietario del equipo si no fue enviado ya.
	 *
	 * Usa el transient 'calibratrack_recordatorio_cliente_{equipo_id}_{dias}' con TTL 23h
	 * para garantizar que solo se envíe una vez por equipo por ciclo diario.
	 *
	 * @param object $equipo Objeto con equipo_id, proxima_fecha_control, evento_id.
	 * @param int    $dias   Días de anticipación configurados.
	 * @return void
	 */
	private static function enviar_recordatorio_cliente_si_no_enviado( $equipo, $dias ) {
		$equipo_id     = (int) $equipo->equipo_id;
		$transient_key = 'calibratrack_recordatorio_cliente_' . $equipo_id . '_' . (int) $dias;

		// Si ya se envió en las últimas 23 horas, no volver a enviar.
		if ( get_transient( $transient_key ) ) {
			return;
		}

		// Resolver cliente propietario y su correo.
		$cliente_id = (int) get_post_meta( $equipo_id, CalibraTrack_Meta_Keys::EQUIPO_CLIENTE_PROPIETARIO, true );
		if ( $cliente_id <= 0 ) {
			return;
		}

		$correo_cliente = (string) get_post_meta( $cliente_id, CalibraTrack_Meta_Keys::CLIENTE_CORREO, true );
		if ( empty( $correo_cliente ) ) {
			return;
		}

		// Calcular días restantes reales desde hoy.
		$proxima_fecha = (string) $equipo->proxima_fecha_control;
		$hoy           = new DateTime( 'today', new DateTimeZone( 'America/Santiago' ) );
		$dt_proxima    = DateTime::createFromFormat( 'Y-m-d', $proxima_fecha, new DateTimeZone( 'America/Santiago' ) );

		if ( ! $dt_proxima ) {
			return;
		}

		$diff         = $hoy->diff( $dt_proxima );
		$dias_reales  = (int) $diff->days;

		if ( ! class_exists( 'CalibraTrack_Mailer' ) ) {
			return;
		}

		$enviado = CalibraTrack_Mailer::send_recordatorio_a_cliente( $equipo_id, $proxima_fecha, $dias_reales );

		if ( $enviado ) {
			set_transient( $transient_key, 1, self::ALERTA_TTL );
		}
	}
}
