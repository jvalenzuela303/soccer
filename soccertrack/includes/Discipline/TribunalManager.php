<?php
/**
 * Gestión del Tribunal Disciplinario.
 *
 * Responsabilidades:
 *  - Registrar sanciones (tarjetas rojas directas, acumulación de amarillas).
 *  - Bloquear automáticamente al jugador en su planilla (is_suspended = 1).
 *  - Decrementar fechas restantes al cerrar cada jornada.
 *  - Levantar el bloqueo cuando remaining_matches llega a 0.
 *
 * @package SoccerTrack
 */

declare(strict_types=1);

namespace SportsLeague\Discipline;

final class TribunalManager {

	/**
	 * Registra una sanción y bloquea al jugador en su equipo.
	 *
	 * @param  int    $player_id     ID del jugador sancionado.
	 * @param  int    $tournament_id ID del torneo.
	 * @param  int    $match_id      ID del partido donde ocurrió el incidente.
	 * @param  string $reason        Motivo de la sanción.
	 * @param  int    $ban_matches   Número de fechas de suspensión.
	 * @return int    ID de la sanción creada.
	 */
	public function sanction(
		int    $player_id,
		int    $tournament_id,
		int    $match_id,
		string $reason,
		int    $ban_matches
	): int {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->insert(
			"{$wpdb->prefix}ds_disciplinary_sanctions",
			[
				'player_id'          => $player_id,
				'tournament_id'      => $tournament_id,
				'match_id'           => $match_id,
				'reason'             => sanitize_text_field( $reason ),
				'ban_days_or_matches'=> $ban_matches,
				'remaining_matches'  => $ban_matches,
				'status'             => 'active',
			],
			[ '%d', '%d', '%d', '%s', '%d', '%d', '%s' ]
		);

		$sanction_id = (int) $wpdb->insert_id;

		// Marcar al jugador como suspendido en su equipo dentro de este torneo.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->prefix}ds_team_players tp
				 INNER JOIN {$wpdb->prefix}ds_teams t ON t.id = tp.team_id
				 SET tp.is_suspended = 1
				 WHERE tp.player_id = %d
				   AND t.tournament_id = %d",
				$player_id,
				$tournament_id
			)
		);

		return $sanction_id;
	}

	/**
	 * Verifica si un jugador está actualmente suspendido en un torneo.
	 */
	public function is_player_suspended( int $player_id, int $tournament_id ): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*)
				 FROM {$wpdb->prefix}ds_disciplinary_sanctions
				 WHERE player_id = %d
				   AND tournament_id = %d
				   AND status = 'active'",
				$player_id,
				$tournament_id
			)
		);

		return $count > 0;
	}

	/**
	 * Descuenta 1 fecha de suspensión a los jugadores que participaron en el partido cerrado.
	 *
	 * La unidad "fecha de sanción" es cada partido que el equipo del jugador disputa.
	 * Solo se descuenta a los jugadores de los dos equipos que jugaron el partido,
	 * no a todos los equipos del torneo.
	 *
	 * @param int $tournament_id  ID del torneo.
	 * @param int $home_team_id   ID del equipo local del partido cerrado.
	 * @param int $away_team_id   ID del equipo visitante del partido cerrado.
	 */
	public function decrement_after_match( int $tournament_id, int $home_team_id, int $away_team_id ): void {
		global $wpdb;

		// Obtener IDs de jugadores de los dos equipos que disputaron el partido.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$player_ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT tp.player_id
				 FROM {$wpdb->prefix}ds_team_players tp
				 WHERE tp.team_id IN (%d, %d)",
				$home_team_id,
				$away_team_id
			)
		);

		if ( empty( $player_ids ) ) {
			return;
		}

		$id_placeholders = implode( ', ', array_fill( 0, count( $player_ids ), '%d' ) );

		// Decrementar solo las sanciones activas de esos jugadores en este torneo.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->prefix}ds_disciplinary_sanctions
				 SET remaining_matches = GREATEST(0, remaining_matches - 1)
				 WHERE tournament_id = %d
				   AND status = 'active'
				   AND player_id IN ($id_placeholders)",
				array_merge( [ $tournament_id ], array_map( 'intval', $player_ids ) )
			)
		);

		// Marcar como cumplidas las que llegaron a 0 (solo de estos jugadores).
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->prefix}ds_disciplinary_sanctions
				 SET status = 'served'
				 WHERE tournament_id = %d
				   AND remaining_matches = 0
				   AND status = 'active'
				   AND player_id IN ($id_placeholders)",
				array_merge( [ $tournament_id ], array_map( 'intval', $player_ids ) )
			)
		);

		// Levantar el bloqueo de los jugadores de estos equipos que ya no tienen sanciones activas.
		// NOT EXISTS es más predecible que NOT IN con subquery en MariaDB 10.6.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->prefix}ds_team_players tp
				 SET tp.is_suspended = 0
				 WHERE tp.team_id IN (%d, %d)
				   AND NOT EXISTS (
				       SELECT 1
				       FROM {$wpdb->prefix}ds_disciplinary_sanctions ds
				       WHERE ds.player_id     = tp.player_id
				         AND ds.tournament_id = %d
				         AND ds.status        = 'active'
				   )",
				$home_team_id,
				$away_team_id,
				$tournament_id
			)
		);
	}
}
