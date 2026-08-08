<?php
/**
 * Motor de validación logística anti-colisión.
 *
 * Verifica que al programar un partido no haya:
 *  1. Cancha ya ocupada en la franja horaria (±60 minutos).
 *  2. Árbitro asignado a otro partido simultáneo.
 *  3. Jugadores inscritos en dos partidos al mismo tiempo (multi-inscripción).
 *
 * Tiempo de respuesta objetivo: < 100ms (queries indexadas sobre wp_ds_matches).
 *
 * @package SoccerTrack
 */

declare(strict_types=1);

namespace SportsLeague\Core;

final class AntiCollisionEngine {

	/**
	 * Valida la viabilidad logística de un partido.
	 *
	 * @param  int      $court_id     ID de la cancha asignada.
	 * @param  int      $referee_id   ID de usuario árbitro (0 = sin árbitro).
	 * @param  string[] $player_ruts  RUTs de los jugadores de ambos equipos.
	 * @param  string   $datetime_str Fecha y hora del partido ('Y-m-d H:i:s').
	 * @return array{valid: bool, errors: string[]}
	 */
	public function validate(
		int    $court_id,
		int    $referee_id,
		array  $player_ruts,
		string $datetime_str
	): array {
		$errors = [];
		$dt     = new \DateTimeImmutable( $datetime_str );
		$start  = $dt->modify( '-60 minutes' )->format( 'Y-m-d H:i:s' );
		$end    = $dt->modify( '+60 minutes' )->format( 'Y-m-d H:i:s' );

		// 1. Cancha ocupada.
		if ( $this->is_court_occupied( $court_id, $start, $end ) ) {
			$errors[] = __( 'La cancha seleccionada ya tiene un partido programado en esta franja horaria.', 'soccertrack' );
		}

		// 2. Árbitro asignado a otro partido.
		if ( $referee_id > 0 && $this->is_referee_assigned( $referee_id, $start, $end ) ) {
			$errors[] = __( 'El árbitro asignado ya arbitra otro partido simultáneo en el complejo.', 'soccertrack' );
		}

		// 3. Jugadores con cruce de horario (multi-inscripción).
		$conflicted = $this->get_conflicted_players( $player_ruts, $start, $end );
		if ( ! empty( $conflicted ) ) {
			$errors[] = sprintf(
				__( 'Jugadores con cruce de horario detectado: %s', 'soccertrack' ),
				implode( ', ', $conflicted )
			);
		}

		return [
			'valid'  => empty( $errors ),
			'errors' => $errors,
		];
	}

	/**
	 * Verifica si la cancha tiene un partido activo en la ventana de tiempo.
	 */
	private function is_court_occupied( int $court_id, string $start, string $end ): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*)
				 FROM {$wpdb->prefix}ds_matches
				 WHERE court_id = %d
				   AND status NOT IN ('finished', 'suspended')
				   AND match_datetime BETWEEN %s AND %s",
				$court_id,
				$start,
				$end
			)
		);

		return $count > 0;
	}

	/**
	 * Verifica si el árbitro ya tiene partido asignado en la ventana de tiempo.
	 */
	private function is_referee_assigned( int $referee_id, string $start, string $end ): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*)
				 FROM {$wpdb->prefix}ds_matches
				 WHERE referee_user_id = %d
				   AND status NOT IN ('finished', 'suspended')
				   AND match_datetime BETWEEN %s AND %s",
				$referee_id,
				$start,
				$end
			)
		);

		return $count > 0;
	}

	/**
	 * Devuelve los RUTs de jugadores que ya tienen partido en la ventana de tiempo.
	 *
	 * @param  string[] $rut_ids
	 * @return string[]
	 */
	private function get_conflicted_players( array $rut_ids, string $start, string $end ): array {
		if ( empty( $rut_ids ) ) {
			return [];
		}

		global $wpdb;

		$placeholders = implode( ', ', array_fill( 0, count( $rut_ids ), '%s' ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		$rows = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT p.rut_id
				 FROM {$wpdb->prefix}ds_players p
				 JOIN {$wpdb->prefix}ds_team_players tp ON tp.player_id = p.id
				 JOIN {$wpdb->prefix}ds_matches m
				      ON ( m.home_team_id = tp.team_id OR m.away_team_id = tp.team_id )
				 WHERE p.rut_id IN ( $placeholders )
				   AND m.status NOT IN ('finished', 'suspended')
				   AND m.match_datetime BETWEEN %s AND %s",
				...[...$rut_ids, $start, $end]
			)
		);

		return $rows ?? [];
	}
}
