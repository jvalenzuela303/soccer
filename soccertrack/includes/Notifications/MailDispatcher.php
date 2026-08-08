<?php
/**
 * Despachador de correos de SoccerTrack.
 *
 * Envía notificaciones por correo usando wp_mail().
 * No depende de bibliotecas externas.
 *
 * Escenarios:
 *  - Sanción disciplinaria aplicada.
 *  - Resultado de partido registrado.
 *  - Fixture generado.
 *
 * @package SoccerTrack
 */

declare(strict_types=1);

namespace SportsLeague\Notifications;

final class MailDispatcher {

	private string $from_name;
	private string $from_email;

	public function __construct() {
		$this->from_name  = get_bloginfo( 'name' );
		$this->from_email = get_bloginfo( 'admin_email' );
	}

	/**
	 * Notifica al delegado del club cuando un jugador es sancionado.
	 *
	 * @param string $delegate_email  Correo del delegado.
	 * @param string $player_name     Nombre completo del jugador.
	 * @param string $reason          Motivo de la sanción.
	 * @param int    $ban_matches     Número de fechas de suspensión.
	 * @param string $tournament_name Nombre del torneo.
	 */
	public function notify_sanction(
		string $delegate_email,
		string $player_name,
		string $reason,
		int    $ban_matches,
		string $tournament_name
	): bool {
		$subject = sprintf(
			/* translators: %1$s player name, %2$s tournament */
			__( '[%2$s] Sanción disciplinaria — %1$s', 'soccertrack' ),
			$player_name,
			$tournament_name
		);

		$body = sprintf(
			/* translators: %1$s player, %2$s reason, %3$d matches, %4$s tournament */
			__(
				"Estimado delegado,\n\n" .
				"Le informamos que el jugador %1\$s ha sido sancionado en el torneo %4\$s.\n\n" .
				"Motivo: %2\$s\n" .
				"Fechas de suspensión: %3\$d\n\n" .
				"El jugador quedará inhabilitado automáticamente en las próximas planillas.\n\n" .
				"Atentamente,\n" .
				"Tribunal Disciplinario",
				'soccertrack'
			),
			$player_name,
			$reason,
			$ban_matches,
			$tournament_name
		);

		return $this->send( $delegate_email, $subject, $body );
	}

	/**
	 * Notifica a los delegados de ambos equipos cuando se registra un resultado.
	 *
	 * @param string[] $emails         Correos de los delegados.
	 * @param string   $home_team      Equipo local.
	 * @param string   $away_team      Equipo visitante.
	 * @param int      $home_score     Goles local.
	 * @param int      $away_score     Goles visitante.
	 * @param int      $round          Número de fecha.
	 * @param string   $tournament_name Nombre del torneo.
	 */
	public function notify_match_result(
		array  $emails,
		string $home_team,
		string $away_team,
		int    $home_score,
		int    $away_score,
		int    $round,
		string $tournament_name
	): bool {
		if ( empty( $emails ) ) {
			return false;
		}

		$subject = sprintf(
			/* translators: %1$s tournament, %2$d round */
			__( '[%1$s] Resultado Fecha %2$d', 'soccertrack' ),
			$tournament_name,
			$round
		);

		$body = sprintf(
			/* translators: %1$s home team, %2$d home score, %3$d away score, %4$s away team, %5$d round, %6$s tournament */
			__(
				"Resultado registrado:\n\n" .
				"%1\$s %2\$d — %3\$d %4\$s\n" .
				"Fecha %5\$d · Torneo: %6\$s\n\n" .
				"La tabla de posiciones ha sido actualizada automáticamente.\n\n" .
				"Atentamente,\nSistema SoccerTrack",
				'soccertrack'
			),
			$home_team,
			$home_score,
			$away_score,
			$away_team,
			$round,
			$tournament_name
		);

		$all_sent = true;

		foreach ( $emails as $email ) {
			if ( ! $this->send( $email, $subject, $body ) ) {
				$all_sent = false;
			}
		}

		return $all_sent;
	}

	/**
	 * Notifica que el fixture del torneo ha sido generado.
	 *
	 * @param string[] $emails          Correos de todos los delegados.
	 * @param string   $tournament_name Nombre del torneo.
	 * @param int      $total_matches   Total de partidos generados.
	 * @param string   $portal_url      URL del portal público del torneo.
	 */
	public function notify_fixture_generated(
		array  $emails,
		string $tournament_name,
		int    $total_matches,
		string $portal_url
	): bool {
		if ( empty( $emails ) ) {
			return false;
		}

		$subject = sprintf(
			/* translators: %s tournament name */
			__( '[%s] Fixture generado — ¡Ya disponible!', 'soccertrack' ),
			$tournament_name
		);

		$body = sprintf(
			/* translators: %1$s tournament, %2$d matches, %3$s URL */
			__(
				"El fixture del torneo %1\$s ha sido generado.\n\n" .
				"Total de partidos programados: %2\$d\n\n" .
				"Puedes consultarlo en el portal público:\n%3\$s\n\n" .
				"Atentamente,\nCoordinación de Liga",
				'soccertrack'
			),
			$tournament_name,
			$total_matches,
			$portal_url
		);

		$all_sent = true;

		foreach ( $emails as $email ) {
			if ( ! $this->send( $email, $subject, $body ) ) {
				$all_sent = false;
			}
		}

		return $all_sent;
	}

	/**
	 * Envía correo de bienvenida con credenciales al nuevo usuario.
	 *
	 * @param string $email      Correo del nuevo usuario.
	 * @param string $name       Nombre para mostrar.
	 * @param string $role_label Etiqueta legible del rol (ej: "Árbitro / Veedor de Campo").
	 * @param string $password   Contraseña generada (texto plano).
	 * @param string $panel_url  URL del panel de SoccerTrack.
	 */
	public function notify_welcome(
		string $email,
		string $name,
		string $role_label,
		string $password,
		string $panel_url
	): bool {
		$subject = __( 'Bienvenido a SoccerTrack — tus credenciales de acceso', 'soccertrack' );

		$body = sprintf(
			/* translators: %1$s name, %2$s role, %3$s email, %4$s password, %5$s URL */
			__(
				"Hola %1\$s,\n\n" .
				"Se ha creado tu cuenta en el sistema SoccerTrack con el rol de %2\$s.\n\n" .
				"Correo: %3\$s\n" .
				"Contraseña: %4\$s\n\n" .
				"Accede al panel aquí:\n%5\$s\n\n" .
				"Te recomendamos cambiar tu contraseña tras el primer ingreso.\n\n" .
				"Atentamente,\nAdministración SoccerTrack",
				'soccertrack'
			),
			$name,
			$role_label,
			$email,
			$password,
			$panel_url
		);

		return $this->send( $email, $subject, $body );
	}

	/**
	 * Notifica al usuario que su contraseña ha sido restablecida por un administrador.
	 *
	 * @param string $email     Correo del usuario.
	 * @param string $name      Nombre para mostrar.
	 * @param string $password  Nueva contraseña (texto plano).
	 * @param string $panel_url URL del panel de SoccerTrack.
	 */
	public function notify_password_reset(
		string $email,
		string $name,
		string $password,
		string $panel_url
	): bool {
		$subject = __( 'SoccerTrack — Tu contraseña ha sido restablecida', 'soccertrack' );

		$body = sprintf(
			/* translators: %1$s name, %2$s email, %3$s password, %4$s URL */
			__(
				"Hola %1\$s,\n\n" .
				"Un administrador ha restablecido tu contraseña en SoccerTrack.\n\n" .
				"Correo: %2\$s\n" .
				"Nueva contraseña: %3\$s\n\n" .
				"Accede al panel aquí:\n%4\$s\n\n" .
				"Si no solicitaste este cambio, contacta al administrador del sistema.\n\n" .
				"Atentamente,\nAdministración SoccerTrack",
				'soccertrack'
			),
			$name,
			$email,
			$password,
			$panel_url
		);

		return $this->send( $email, $subject, $body );
	}

	/**
	 * Envía un correo usando wp_mail con cabeceras correctas.
	 */
	private function send( string $to, string $subject, string $body ): bool {
		if ( ! is_email( $to ) ) {
			return false;
		}

		$headers = [
			'Content-Type: text/plain; charset=UTF-8',
			sprintf( 'From: %s <%s>', $this->from_name, $this->from_email ),
		];

		return (bool) wp_mail( $to, $subject, $body, $headers );
	}
}
