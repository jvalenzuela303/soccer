<?php
/**
 * Template del correo de alerta de vencimiento de calibración.
 *
 * Se usa para notificar internamente (a administradores/técnicos) cuando
 * un equipo está a 30 o 7 días de vencer su próxima fecha de control.
 *
 * Contexto disponible (inyectado por el módulo de notificaciones):
 *   $equipo_nombre  string  Título del post del equipo.
 *   $serie          string  Número de serie.
 *   $proxima_fecha  string  Próxima fecha de control (YYYY-MM-DD).
 *   $dias_restantes int     Días hasta el vencimiento.
 *   $url_admin      string  URL del equipo en el admin de WP.
 *
 * PENDIENTE para el agente notifications-agent:
 *   - Implementar el HTML del correo.
 *   - Registrar el cron job (wp_schedule_event) en activación.
 *   - El cron job está en fase 2 según el roadmap — documentado aquí
 *     para que el template esté disponible cuando se implemente.
 *
 * @package CalibraTrack
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<!-- Pendiente de implementación: agente notifications-agent (Fase 2) -->
