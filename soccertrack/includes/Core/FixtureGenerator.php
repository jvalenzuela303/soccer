<?php
/**
 * Generador de Fixture Round-Robin.
 *
 * Implementa el algoritmo de rotación circular con:
 *  - Alternancia equitativa local/visitante por ronda.
 *  - Soporte para número impar de equipos (agrega "Equipo Libre").
 *  - Asignación rotativa de canchas dentro del recinto.
 *
 * Las fechas de los partidos se calculan a partir del día de semana y hora
 * configurados en el torneo (match_weekday y match_time).
 *
 * @package SoccerTrack
 */

declare(strict_types=1);

namespace SportsLeague\Core;

final class FixtureGenerator {

	/**
	 * Calcula la fecha del próximo día de la semana dado a partir de hoy.
	 *
	 * @param int    $weekday      0=domingo … 6=sábado (convención date('w')).
	 * @param string $time         Hora en formato 'H:i:s', ej. '19:00:00'.
	 * @param int    $hour_offset  Horas a sumar (para partidos del mismo día).
	 * @param int    $week_offset  Semanas a sumar (0=primera disponible).
	 */
	private function next_match_datetime(
		int    $weekday,
		string $time,
		int    $hour_offset = 0,
		int    $week_offset = 0
	): string {
		$day_names = [
			0 => 'sunday', 1 => 'monday', 2 => 'tuesday', 3 => 'wednesday',
			4 => 'thursday', 5 => 'friday', 6 => 'saturday',
		];

		$day_name = $day_names[ $weekday ] ?? 'saturday';
		$base     = new \DateTimeImmutable( "next {$day_name}" );

		if ( $week_offset > 0 ) {
			$base = $base->modify( "+{$week_offset} weeks" );
		}

		[ $h, $m, $s ] = array_map( 'intval', explode( ':', $time . ':00' ) );
		$base = $base->setTime( $h + $hour_offset, $m, $s );

		return $base->format( 'Y-m-d H:i:s' );
	}

	/**
	 * Genera las semi-finales de un torneo Round-Robin + Play-offs.
	 *
	 * Requisitos previos (verificados internamente):
	 *  - Todos los partidos de fase 'regular' deben estar 'finished'.
	 *  - No deben existir ya partidos de fase 'semifinal'.
	 *
	 * Emparejamiento: 1.º vs 4.º (SF1)  y  2.º vs 3.º (SF2).
	 * Los partidos de Final y 3.º puesto se crean con generate_finals()
	 * una vez que ambas semi-finales estén finalizadas.
	 *
	 * @param  array{id:int,match_weekday:int,match_time:string} $tournament Datos del torneo.
	 * @param  int $venue_id      Recinto donde se disputarán las semi-finales.
	 * @return array{match_ids: int[], error?: string}
	 */
	public function generate_playoffs( array $tournament, int $venue_id ): array {
		global $wpdb;

		$tournament_id = (int) $tournament['id'];
		$weekday       = (int) ( $tournament['match_weekday'] ?? 6 );
		$time          = (string) ( $tournament['match_time'] ?? '19:00:00' );

		// 1. Verificar que todos los partidos regulares estén finalizados.
		$pending = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}ds_matches
				 WHERE tournament_id = %d AND phase = 'regular' AND status != 'finished'",
				$tournament_id
			)
		);

		if ( $pending > 0 ) {
			return [ 'match_ids' => [], 'error' => 'Aún hay partidos de fase regular sin finalizar.' ];
		}

		// 2. Verificar que no existan ya semi-finales.
		$existing_sf = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}ds_matches
				 WHERE tournament_id = %d AND phase = 'semifinal'",
				$tournament_id
			)
		);

		if ( $existing_sf > 0 ) {
			return [ 'match_ids' => [], 'error' => 'Las semi-finales ya fueron generadas.' ];
		}

		// 3. Leer tabla de posiciones (top 4).
		$standings = ( new StandingsCalculator() )->recalculate( $tournament_id );

		if ( count( $standings ) < 4 ) {
			return [ 'match_ids' => [], 'error' => 'Se necesitan al menos 4 equipos para generar play-offs.' ];
		}

		// Emparejamiento clásico: 1.º vs 4.º y 2.º vs 3.º.
		$rank1 = (int) $standings[0]['team_id'];
		$rank2 = (int) $standings[1]['team_id'];
		$rank3 = (int) $standings[2]['team_id'];
		$rank4 = (int) $standings[3]['team_id'];

		$dt_sf1 = $this->next_match_datetime( $weekday, $time, 0 );
		$dt_sf2 = $this->next_match_datetime( $weekday, $time, 1 );

		$ids = [];

		foreach ( [
			[ 'home' => $rank1, 'away' => $rank4, 'dt' => $dt_sf1 ],
			[ 'home' => $rank2, 'away' => $rank3, 'dt' => $dt_sf2 ],
		] as $pair ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->insert(
				"{$wpdb->prefix}ds_matches",
				[
					'tournament_id'  => $tournament_id,
					'round_number'   => 0,      // 0 = play-off (sin jornada regular).
					'home_team_id'   => $pair['home'],
					'away_team_id'   => $pair['away'],
					'venue_id'       => $venue_id,
					'court_id'       => 0,
					'match_datetime' => $pair['dt'],
					'status'         => 'scheduled',
					'phase'          => 'semifinal',
				],
				[ '%d', '%d', '%d', '%d', '%d', '%d', '%s', '%s', '%s' ]
			);

			if ( $wpdb->insert_id ) {
				$ids[] = (int) $wpdb->insert_id;
			}
		}

		$this->assign_courts( $ids, $venue_id );

		return [ 'match_ids' => $ids ];
	}

	/**
	 * Genera la Final y el partido por el 3.er puesto.
	 *
	 * Requisitos previos:
	 *  - Deben existir exactamente 2 semi-finales (phase='semifinal') con status='finished'.
	 *  - No deben existir ya partidos de fase 'final'.
	 *
	 * @param  array{id:int,match_weekday:int,match_time:string} $tournament Datos del torneo.
	 * @param  int $venue_id
	 * @return array{match_ids: int[], error?: string}
	 */
	public function generate_finals( array $tournament, int $venue_id ): array {
		global $wpdb;

		$tournament_id = (int) $tournament['id'];
		$weekday       = (int) ( $tournament['match_weekday'] ?? 6 );
		$time          = (string) ( $tournament['match_time'] ?? '19:00:00' );

		// 1. Leer semi-finales finalizadas.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$semis = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, home_team_id, away_team_id, home_score, away_score
				 FROM {$wpdb->prefix}ds_matches
				 WHERE tournament_id = %d AND phase = 'semifinal' AND status = 'finished'
				 ORDER BY id ASC",
				$tournament_id
			),
			ARRAY_A
		);

		if ( count( $semis ) < 2 ) {
			return [ 'match_ids' => [], 'error' => 'Ambas semi-finales deben estar finalizadas.' ];
		}

		// 2. Verificar que no exista ya la final.
		$existing_final = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}ds_matches
				 WHERE tournament_id = %d AND phase IN ('final','third_place')",
				$tournament_id
			)
		);

		if ( $existing_final > 0 ) {
			return [ 'match_ids' => [], 'error' => 'La final ya fue generada.' ];
		}

		// 3. Determinar ganadores y perdedores de cada semi.
		$resolve = static function ( array $m ): array {
			$hs = (int) $m['home_score'];
			$as = (int) $m['away_score'];
			if ( $hs >= $as ) {
				return [ 'winner' => (int) $m['home_team_id'], 'loser' => (int) $m['away_team_id'] ];
			}
			return [ 'winner' => (int) $m['away_team_id'], 'loser' => (int) $m['home_team_id'] ];
		};

		$sf1 = $resolve( $semis[0] );
		$sf2 = $resolve( $semis[1] );

		$dt_3rd   = $this->next_match_datetime( $weekday, $time, 0 );
		$dt_final = $this->next_match_datetime( $weekday, $time, 1 );

		$ids = [];

		foreach ( [
			[ 'home' => $sf1['loser'],  'away' => $sf2['loser'],  'dt' => $dt_3rd,   'phase' => 'third_place' ],
			[ 'home' => $sf1['winner'], 'away' => $sf2['winner'], 'dt' => $dt_final,  'phase' => 'final' ],
		] as $pair ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->insert(
				"{$wpdb->prefix}ds_matches",
				[
					'tournament_id'  => $tournament_id,
					'round_number'   => 0,
					'home_team_id'   => $pair['home'],
					'away_team_id'   => $pair['away'],
					'venue_id'       => $venue_id,
					'court_id'       => 0,
					'match_datetime' => $pair['dt'],
					'status'         => 'scheduled',
					'phase'          => $pair['phase'],
				],
				[ '%d', '%d', '%d', '%d', '%d', '%d', '%s', '%s', '%s' ]
			);

			if ( $wpdb->insert_id ) {
				$ids[] = (int) $wpdb->insert_id;
			}
		}

		$this->assign_courts( $ids, $venue_id );

		return [ 'match_ids' => $ids ];
	}

	/**
	 * Genera el fixture completo de un torneo Round-Robin.
	 *
	 * @param  array{id:int,match_weekday:int,match_time:string} $tournament Datos del torneo.
	 * @param  int[] $team_ids      IDs de equipos participantes.
	 * @param  int   $venue_id      ID del recinto donde se juegan los partidos.
	 * @return int[] IDs de los partidos creados en wp_ds_matches.
	 */
	public function generate( array $tournament, array $team_ids, int $venue_id ): array {
		$tournament_id = (int) $tournament['id'];
		$weekday       = (int) ( $tournament['match_weekday'] ?? 6 );
		$time          = (string) ( $tournament['match_time'] ?? '19:00:00' );

		$n     = count( $team_ids );
		$teams = $team_ids;

		// Número impar → agregar null como "Equipo Libre" (bye).
		if ( $n % 2 !== 0 ) {
			$teams[] = null;
			$n++;
		}

		$rounds    = $n - 1;
		$match_ids = [];

		for ( $round = 1; $round <= $rounds; $round++ ) {
			$pairs = [];

			for ( $i = 0; $i < $n / 2; $i++ ) {
				$home = $teams[ $i ];
				$away = $teams[ $n - 1 - $i ];

				// Ignorar partidos donde uno de los dos es "Equipo Libre".
				if ( $home === null || $away === null ) {
					continue;
				}

				// Alternar local/visitante en rondas pares.
				if ( $round % 2 === 0 ) {
					[ $home, $away ] = [ $away, $home ];
				}

				$pairs[] = [ 'home' => $home, 'away' => $away ];
			}

			$match_ids = array_merge(
				$match_ids,
				$this->insert_round( $tournament_id, $round, $pairs, $venue_id, $weekday, $time )
			);

			// Rotación circular: fijo el primer elemento, rotar el resto.
			$fixed    = array_shift( $teams );
			$last     = array_pop( $teams );
			array_unshift( $teams, $last );
			array_unshift( $teams, $fixed );
		}

		// Asignar canchas rotativamente.
		$this->assign_courts( $match_ids, $venue_id );

		return $match_ids;
	}

	/**
	 * Inserta una jornada de partidos en la base de datos.
	 *
	 * @param  int                             $tournament_id
	 * @param  int                             $round
	 * @param  array<array{home:int,away:int}> $pairs
	 * @param  int                             $venue_id
	 * @param  int                             $weekday  0=domingo … 6=sábado.
	 * @param  string                          $time     Hora en formato 'H:i:s'.
	 * @return int[]
	 */
	private function insert_round(
		int    $tournament_id,
		int    $round,
		array  $pairs,
		int    $venue_id,
		int    $weekday,
		string $time
	): array {
		global $wpdb;
		$ids = [];

		foreach ( $pairs as $idx => $pair ) {
			// Fecha basada en el weekday y time del torneo, desplazada por jornada y por slot.
			// Cada jornada (round) ocupa una semana. Cada partido del mismo día: +1 hora.
			$dt = $this->next_match_datetime( $weekday, $time, $idx, $round - 1 );

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->insert(
				"{$wpdb->prefix}ds_matches",
				[
					'tournament_id'  => $tournament_id,
					'round_number'   => $round,
					'home_team_id'   => $pair['home'],
					'away_team_id'   => $pair['away'],
					'venue_id'       => $venue_id,
					'court_id'       => 0, // Asignado en assign_courts().
					'match_datetime' => $dt,
					'status'         => 'scheduled',
				],
				[ '%d', '%d', '%d', '%d', '%d', '%d', '%s', '%s' ]
			);

			if ( $wpdb->insert_id ) {
				$ids[] = (int) $wpdb->insert_id;
			}
		}

		return $ids;
	}

	/**
	 * Asigna canchas del recinto a los partidos de forma rotativa.
	 *
	 * @param int[] $match_ids
	 */
	public function assign_courts( array $match_ids, int $venue_id ): void {
		if ( empty( $match_ids ) ) {
			return;
		}

		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$court_ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT id FROM {$wpdb->prefix}ds_courts WHERE venue_id = %d ORDER BY id ASC",
				$venue_id
			)
		);

		if ( empty( $court_ids ) ) {
			return;
		}

		$count = count( $court_ids );

		foreach ( $match_ids as $i => $match_id ) {
			$court_id = (int) $court_ids[ $i % $count ];

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->update(
				"{$wpdb->prefix}ds_matches",
				[ 'court_id' => $court_id ],
				[ 'id'       => $match_id ],
				[ '%d' ],
				[ '%d' ]
			);
		}
	}
}
