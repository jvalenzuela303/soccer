<?php
/**
 * Calculadora de Tabla de Posiciones.
 *
 * Fórmula: PTS = (PG × 3) + (PE × 1)
 *          DG  = GF - GC
 *
 * Criterios de desempate (en orden):
 *  1º Puntos (PTS)
 *  2º Diferencia de goles (DG)
 *  3º Goles a favor (GF)
 *  4º Resultado directo (no implementado en este recálculo global — ver playoff)
 *
 * @package SoccerTrack
 */

declare(strict_types=1);

namespace SportsLeague\Core;

final class StandingsCalculator {

	/**
	 * Recalcula la tabla de posiciones completa de un torneo.
	 *
	 * Solo considera partidos con status = 'finished'.
	 *
	 * @param  int $tournament_id
	 * @return array<int, array{team_id:int, name:string, pj:int, pg:int, pe:int, pp:int, gf:int, gc:int, dg:int, pts:int, form:list<string>, clean_sheets:int, win_streak:int}>
	 */
	public function recalculate( int $tournament_id ): array {
		global $wpdb;

		// Cargar partidos finalizados del torneo.
		// USE INDEX (idx_tournament_status) — evita full-scan; ambas columnas en el índice compuesto.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$matches = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT home_team_id, away_team_id, home_score, away_score, match_datetime
				 FROM {$wpdb->prefix}ds_matches USE INDEX (idx_tournament_status)
				 WHERE tournament_id = %d AND status = 'finished'
				 ORDER BY match_datetime ASC",
				$tournament_id
			),
			ARRAY_A
		);

		// Cargar equipos del torneo.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$teams = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, name FROM {$wpdb->prefix}ds_teams WHERE tournament_id = %d AND is_ghost = 0 ORDER BY name ASC",
				$tournament_id
			),
			ARRAY_A
		);

		// Inicializar tabla con todos los equipos en cero.
		$table        = [];
		$team_history = []; // team_id => [['result' => 'W'|'D'|'L', 'clean' => bool], ...].
		foreach ( $teams as $team ) {
			$tid                  = (int) $team['id'];
			$table[ $tid ]        = [
				'team_id'      => $tid,
				'name'         => $team['name'],
				'pj'           => 0,
				'pg'           => 0,
				'pe'           => 0,
				'pp'           => 0,
				'gf'           => 0,
				'gc'           => 0,
				'dg'           => 0,
				'pts'          => 0,
				'form'         => [],
				'clean_sheets' => 0,
				'win_streak'   => 0,
			];
			$team_history[ $tid ] = [];
		}

		// Procesar cada partido.
		foreach ( $matches as $match ) {
			$h  = (int) $match['home_team_id'];
			$a  = (int) $match['away_team_id'];
			$hs = (int) $match['home_score'];
			$as = (int) $match['away_score'];

			// Ignorar si el equipo no está en la tabla (datos inconsistentes).
			if ( ! isset( $table[ $h ], $table[ $a ] ) ) {
				continue;
			}

			// Partidos jugados y goles.
			$table[ $h ]['pj']++;
			$table[ $a ]['pj']++;
			$table[ $h ]['gf'] += $hs;
			$table[ $h ]['gc'] += $as;
			$table[ $a ]['gf'] += $as;
			$table[ $a ]['gc'] += $hs;

			if ( $hs > $as ) {
				// Victoria local.
				$table[ $h ]['pg']++;
				$table[ $h ]['pts'] += 3;
				$table[ $a ]['pp']++;
				$team_history[ $h ][] = [ 'result' => 'W', 'clean' => 0 === $as ];
				$team_history[ $a ][] = [ 'result' => 'L', 'clean' => 0 === $hs ];
			} elseif ( $hs < $as ) {
				// Victoria visitante.
				$table[ $a ]['pg']++;
				$table[ $a ]['pts'] += 3;
				$table[ $h ]['pp']++;
				$team_history[ $h ][] = [ 'result' => 'L', 'clean' => 0 === $as ];
				$team_history[ $a ][] = [ 'result' => 'W', 'clean' => 0 === $hs ];
			} else {
				// Empate.
				$table[ $h ]['pe']++;
				$table[ $h ]['pts']++;
				$table[ $a ]['pe']++;
				$table[ $a ]['pts']++;
				$team_history[ $h ][] = [ 'result' => 'D', 'clean' => 0 === $as ];
				$team_history[ $a ][] = [ 'result' => 'D', 'clean' => 0 === $hs ];
			}
		}

		// Calcular form, clean_sheets y win_streak a partir del historial por equipo.
		foreach ( $table as $tid => &$row ) {
			$history = $team_history[ $tid ] ?? [];

			$row['form'] = array_column( array_slice( $history, -5 ), 'result' );

			$row['clean_sheets'] = count(
				array_filter( $history, static fn( array $entry ): bool => $entry['clean'] )
			);

			$streak          = 0;
			$reversed_history = array_reverse( $history );
			foreach ( $reversed_history as $entry ) {
				if ( 'W' !== $entry['result'] ) {
					break;
				}
				++$streak;
			}
			$row['win_streak'] = $streak;
		}
		unset( $row );

		// Calcular diferencia de goles.
		foreach ( $table as &$row ) {
			$row['dg'] = $row['gf'] - $row['gc'];
		}
		unset( $row );

		// Ordenar: PTS desc → DG desc → GF desc.
		usort(
			$table,
			static fn( array $a, array $b ): int =>
				[ $b['pts'], $b['dg'], $b['gf'] ] <=> [ $a['pts'], $a['dg'], $a['gf'] ]
		);

		$sorted = array_values( $table );

		// Enriquecer con bracket si el torneo tiene brackets configurados
		// y la fase regular está completa.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$brackets = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, name, rank_from, rank_to
				 FROM {$wpdb->prefix}ds_playoff_brackets
				 WHERE tournament_id = %d
				 ORDER BY rank_from ASC",
				$tournament_id
			),
			ARRAY_A
		);

		$regular_pending = 0;
		if ( ! empty( $brackets ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$regular_pending = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$wpdb->prefix}ds_matches
					 WHERE tournament_id = %d AND phase = 'regular' AND status NOT IN ('finished', 'suspended', 'postponed')",
					$tournament_id
				)
			);
		}

		foreach ( $sorted as $rank_idx => &$row ) {
			$row['bracket_id']   = null;
			$row['bracket_name'] = null;

			if ( empty( $brackets ) || $regular_pending > 0 ) {
				continue;
			}

			$rank = $rank_idx + 1; // 1-based.
			foreach ( $brackets as $bracket ) {
				if ( $rank >= (int) $bracket['rank_from'] && $rank <= (int) $bracket['rank_to'] ) {
					$row['bracket_id']   = (int) $bracket['id'];
					$row['bracket_name'] = $bracket['name'];
					break;
				}
			}
		}
		unset( $row );

		return $sorted;
	}

	/**
	 * Recalcula la tabla de posiciones por grupo para torneos con fase de grupos.
	 *
	 * Solo considera partidos con phase = 'regular' y status = 'finished'.
	 * Retorna mapa de group_label → array de rows, misma estructura que recalculate().
	 *
	 * @param  int $tournament_id
	 * @return array<string, array<int, array{team_id:int, name:string, pj:int, pg:int, pe:int, pp:int, gf:int, gc:int, dg:int, pts:int, form:list<string>, clean_sheets:int, win_streak:int}>>
	 */
	public function recalculate_by_group( int $tournament_id ): array {
		global $wpdb;

		// Cargar partidos regulares finalizados con su group_label.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$matches = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT home_team_id, away_team_id, home_score, away_score, match_datetime, group_label
				 FROM {$wpdb->prefix}ds_matches USE INDEX (idx_tournament_status)
				 WHERE tournament_id = %d AND status = 'finished' AND phase = 'regular' AND group_label IS NOT NULL
				 ORDER BY match_datetime ASC",
				$tournament_id
			),
			ARRAY_A
		);

		// Cargar equipos con su group_label.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$teams = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, name, group_label FROM {$wpdb->prefix}ds_teams
				 WHERE tournament_id = %d AND group_label IS NOT NULL
				 ORDER BY group_label ASC, name ASC",
				$tournament_id
			),
			ARRAY_A
		);

		if ( empty( $teams ) ) {
			return [];
		}

		// Agrupar equipos por group_label.
		$groups      = [];
		$team_groups = []; // team_id → group_label.
		foreach ( $teams as $team ) {
			$tid   = (int) $team['id'];
			$label = (string) $team['group_label'];
			if ( ! isset( $groups[ $label ] ) ) {
				$groups[ $label ] = [];
			}
			$groups[ $label ][ $tid ] = [
				'team_id'      => $tid,
				'name'         => $team['name'],
				'pj'           => 0,
				'pg'           => 0,
				'pe'           => 0,
				'pp'           => 0,
				'gf'           => 0,
				'gc'           => 0,
				'dg'           => 0,
				'pts'          => 0,
				'form'         => [],
				'clean_sheets' => 0,
				'win_streak'   => 0,
			];
			$team_groups[ $tid ] = $label;
		}

		// Historial por equipo.
		$team_history = array_fill_keys( array_keys( $team_groups ), [] );

		// Procesar partidos — cada partido pertenece al group_label del equipo.
		foreach ( $matches as $match ) {
			$h     = (int) $match['home_team_id'];
			$a     = (int) $match['away_team_id'];
			$hs    = (int) $match['home_score'];
			$as    = (int) $match['away_score'];
			$label = (string) $match['group_label'];

			if ( ! isset( $groups[ $label ][ $h ], $groups[ $label ][ $a ] ) ) {
				continue;
			}

			$groups[ $label ][ $h ]['pj']++;
			$groups[ $label ][ $a ]['pj']++;
			$groups[ $label ][ $h ]['gf'] += $hs;
			$groups[ $label ][ $h ]['gc'] += $as;
			$groups[ $label ][ $a ]['gf'] += $as;
			$groups[ $label ][ $a ]['gc'] += $hs;

			if ( $hs > $as ) {
				$groups[ $label ][ $h ]['pg']++;
				$groups[ $label ][ $h ]['pts'] += 3;
				$groups[ $label ][ $a ]['pp']++;
				$team_history[ $h ][] = [ 'result' => 'W', 'clean' => 0 === $as ];
				$team_history[ $a ][] = [ 'result' => 'L', 'clean' => 0 === $hs ];
			} elseif ( $hs < $as ) {
				$groups[ $label ][ $a ]['pg']++;
				$groups[ $label ][ $a ]['pts'] += 3;
				$groups[ $label ][ $h ]['pp']++;
				$team_history[ $h ][] = [ 'result' => 'L', 'clean' => 0 === $as ];
				$team_history[ $a ][] = [ 'result' => 'W', 'clean' => 0 === $hs ];
			} else {
				$groups[ $label ][ $h ]['pe']++;
				$groups[ $label ][ $h ]['pts']++;
				$groups[ $label ][ $a ]['pe']++;
				$groups[ $label ][ $a ]['pts']++;
				$team_history[ $h ][] = [ 'result' => 'D', 'clean' => 0 === $as ];
				$team_history[ $a ][] = [ 'result' => 'D', 'clean' => 0 === $hs ];
			}
		}

		// Calcular form, clean_sheets, win_streak y dg; ordenar por PTS → DG → GF.
		$result = [];
		ksort( $groups ); // Orden alfabético de grupos.

		foreach ( $groups as $label => $table ) {
			foreach ( $table as $tid => &$row ) {
				$history        = $team_history[ $tid ] ?? [];
				$row['form']    = array_column( array_slice( $history, -5 ), 'result' );
				$row['clean_sheets'] = count( array_filter( $history, static fn( array $e ): bool => $e['clean'] ) );
				$row['dg']      = $row['gf'] - $row['gc'];

				$streak          = 0;
				foreach ( array_reverse( $history ) as $entry ) {
					if ( 'W' !== $entry['result'] ) {
						break;
					}
					++$streak;
				}
				$row['win_streak'] = $streak;
			}
			unset( $row );

			$sorted = array_values( $table );
			usort(
				$sorted,
				static fn( array $a, array $b ): int =>
					[ $b['pts'], $b['dg'], $b['gf'] ] <=> [ $a['pts'], $a['dg'], $a['gf'] ]
			);
			$result[ $label ] = $sorted;
		}

		return $result;
	}
}
