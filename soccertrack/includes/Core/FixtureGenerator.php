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
	 * Calcula la fecha de un partido dado un ciclo de días de la semana.
	 *
	 * Ancla al primer día del ciclo ("next {primer_día}") y avanza semanas completas
	 * más el desplazamiento en días dentro de la misma semana. Esto garantiza que
	 * los partidos de la misma ronda queden siempre en orden cronológico correcto
	 * independientemente del día actual.
	 *
	 * @param int[]  $weekdays         Días activos ordenados lunes-primero, ej. [1,3,5].
	 *                                 Convención date('w'): 0=domingo … 6=sábado.
	 * @param string $time             Hora de inicio en formato 'H:i:s', ej. '19:00:00'.
	 * @param int    $batch_offset     Número de franjas a desplazar (0 = primer partido del día).
	 * @param int    $round_index      Índice de ronda 0-based (round_number - 1).
	 * @param int    $duration_minutes Duración de cada partido en minutos (determina el espaciado).
	 */
	private function next_match_datetime(
		array  $weekdays,
		string $time,
		int    $batch_offset     = 0,
		int    $round_index      = 0,
		int    $duration_minutes = 60
	): string {
		$day_names = [
			0 => 'sunday', 1 => 'monday', 2 => 'tuesday', 3 => 'wednesday',
			4 => 'thursday', 5 => 'friday', 6 => 'saturday',
		];

		if ( empty( $weekdays ) ) {
			$weekdays = [ 6 ]; // default: sábado.
		}

		$count        = count( $weekdays );
		$day_in_cycle = $round_index % $count;
		$week_num     = intdiv( $round_index, $count );

		$first_day  = $weekdays[0];
		$target_day = $weekdays[ $day_in_cycle ];

		// Ancla al primer día del ciclo para garantizar orden dentro de la semana.
		$first_day_name = $day_names[ $first_day ] ?? 'saturday';
		$base           = new \DateTimeImmutable( "next {$first_day_name}" );

		if ( $week_num > 0 ) {
			$base = $base->modify( "+{$week_num} weeks" );
		}

		// Desplazamiento en días desde el primer día del ciclo hasta el día objetivo
		// usando orden lunes-primero: (d + 6) % 7 → lun=0 … dom=6.
		$first_pos  = ( $first_day + 6 ) % 7;
		$target_pos = ( $target_day + 6 ) % 7;
		$day_offset = ( $target_pos - $first_pos + 7 ) % 7;

		if ( $day_offset > 0 ) {
			$base = $base->modify( "+{$day_offset} days" );
		}

		// Calcular la hora usando minutos para respetar duración real (p.ej. 90 min).
		[ $h, $m, $s ] = array_map( 'intval', explode( ':', $time . ':00' ) );
		$start_minutes  = $h * 60 + $m + $batch_offset * $duration_minutes;
		$base           = $base->setTime(
			intdiv( $start_minutes, 60 ) % 24,
			$start_minutes % 60,
			$s
		);

		return $base->format( 'Y-m-d H:i:s' );
	}

	/**
	 * Devuelve la duración del partido en minutos (mínimo 30, máximo 180).
	 */
	private function duration_from_tournament( array $tournament ): int {
		return max( 30, min( 180, (int) ( $tournament['match_duration'] ?? 60 ) ) );
	}


	/**
	 * Decodifica match_weekdays desde el array del torneo.
	 *
	 * @param  array $tournament
	 * @return int[]  Días ordenados lunes-primero, nunca vacío.
	 */
	private function weekdays_from_tournament( array $tournament ): array {
		$raw = $tournament['match_weekdays'] ?? null;

		if ( is_string( $raw ) && $raw !== '' ) {
			$decoded = json_decode( $raw, true );
			if ( is_array( $decoded ) && ! empty( $decoded ) ) {
				$days = array_unique( array_map( 'intval', $decoded ) );
				$days = array_values( array_filter( $days, fn( int $d ) => $d >= 0 && $d <= 6 ) );
				if ( ! empty( $days ) ) {
					// Orden lunes-primero.
					usort( $days, fn( int $a, int $b ) => ( ( $a + 6 ) % 7 ) - ( ( $b + 6 ) % 7 ) );
					return $days;
				}
			}
		}

		// Fallback: columna legada match_weekday.
		$legacy = isset( $tournament['match_weekday'] ) ? (int) $tournament['match_weekday'] : 6;
		return [ $legacy ];
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
		$weekdays      = $this->weekdays_from_tournament( $tournament );
		$time     = (string) ( $tournament['match_time'] ?? '19:00:00' );
		$duration = $this->duration_from_tournament( $tournament );

		// 1. Verificar que todos los partidos regulares estén finalizados.
		$pending = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}ds_matches
				 WHERE tournament_id = %d AND phase = 'regular' AND status NOT IN ('finished', 'suspended', 'postponed')",
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

		$dt_sf1 = $this->next_match_datetime( $weekdays, $time, 0, 0, $duration );
		$dt_sf2 = $this->next_match_datetime( $weekdays, $time, 1, 0, $duration );

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
		$weekdays      = $this->weekdays_from_tournament( $tournament );
		$time     = (string) ( $tournament['match_time'] ?? '19:00:00' );
		$duration = $this->duration_from_tournament( $tournament );

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

		$dt_3rd   = $this->next_match_datetime( $weekdays, $time, 0, 0, $duration );
		$dt_final = $this->next_match_datetime( $weekdays, $time, 1, 0, $duration );

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
		$weekdays      = $this->weekdays_from_tournament( $tournament );
		$time       = (string) ( $tournament['match_time'] ?? '19:00:00' );
		$duration   = $this->duration_from_tournament( $tournament );
		$num_courts = $this->count_courts( $venue_id );

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
				$this->insert_round( $tournament_id, $round, $pairs, $venue_id, $weekdays, $time, $num_courts, $duration )
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
	 * Lógica de franjas horarias:
	 *  - Los partidos se agrupan en tandas del tamaño del número de canchas.
	 *    Todos los partidos de una misma tanda son simultáneos (+0h, +0h, …).
	 *  - La tanda base de cada partido se rota por `(round - 1) % num_batches`
	 *    para que los equipos no siempre jueguen en el mismo horario.
	 *  - Las canchas se asignan en assign_courts() por orden de inserción,
	 *    garantizando que los partidos de la misma tanda caigan en canchas distintas.
	 *
	 * @param  int                             $tournament_id
	 * @param  int                             $round
	 * @param  array<array{home:int,away:int}> $pairs
	 * @param  int                             $venue_id
	 * @param  int[]                           $weekdays         Días activos ordenados lunes-primero.
	 * @param  string                          $time             Hora de inicio, formato 'H:i:s'.
	 * @param  int                             $num_courts       Canchas disponibles (partidos simultáneos por franja).
	 * @param  int                             $duration_minutes Duración de cada partido en minutos.
	 * @return int[]
	 */
	private function insert_round(
		int    $tournament_id,
		int    $round,
		array  $pairs,
		int    $venue_id,
		array  $weekdays,
		string $time,
		int    $num_courts       = 1,
		int    $duration_minutes = 60
	): array {
		global $wpdb;
		$ids = [];

		$n_pairs     = count( $pairs );
		$num_batches = max( 1, (int) ceil( $n_pairs / $num_courts ) );

		foreach ( $pairs as $idx => $pair ) {
			// Tanda base según el índice del partido dentro de la jornada.
			$base_batch    = (int) floor( $idx / $num_courts );
			// Rotación: desplazar la tanda por el número de jornada (0-based)
			// para que los equipos que antes jugaban primero ahora jueguen después.
			$rotated_batch = ( $base_batch + ( $round - 1 ) ) % $num_batches;

			$dt = $this->next_match_datetime( $weekdays, $time, $rotated_batch, $round - 1, $duration_minutes );

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
	 * Devuelve el número de canchas activas de un recinto (mínimo 1).
	 */
	private function count_courts( int $venue_id ): int {
		global $wpdb;
		$count = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}ds_courts WHERE venue_id = %d",
				$venue_id
			)
		);
		return max( 1, $count );
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

		// Bulk UPDATE: one query instead of one per match.
		$case_clauses = [];
		$case_params  = [];
		$in_ids       = [];

		foreach ( $match_ids as $i => $match_id ) {
			$court_id       = (int) $court_ids[ $i % $count ];
			$case_clauses[] = 'WHEN %d THEN %d';
			$case_params[]  = (int) $match_id;
			$case_params[]  = $court_id;
			$in_ids[]       = (int) $match_id;
		}

		$case_sql = implode( ' ', $case_clauses );
		$in_sql   = implode( ', ', array_fill( 0, count( $in_ids ), '%d' ) );
		$params   = array_merge( $case_params, $in_ids );

		$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"UPDATE {$wpdb->prefix}ds_matches SET court_id = CASE id {$case_sql} END WHERE id IN ({$in_sql})",
				...$params
			)
		);
	}

	/**
	 * Genera las semi-finales de un bracket específico de play-offs.
	 *
	 * Emparejamiento: primero del bracket vs último, segundo vs penúltimo.
	 * Requiere que todos los partidos 'regular' del torneo estén 'finished'.
	 *
	 * @param  array{id:int,match_weekday:int,match_weekdays:string,match_time:string,match_duration:int} $tournament
	 * @param  int $bracket_id  ID del bracket en ds_playoff_brackets.
	 * @param  int $venue_id    Recinto donde se disputarán las semi-finales.
	 * @return array{match_ids: int[], error?: string}
	 */
	public function generate_bracket_playoffs( array $tournament, int $bracket_id, int $venue_id ): array {
		global $wpdb;

		$tournament_id = (int) $tournament['id'];
		$weekdays      = $this->weekdays_from_tournament( $tournament );
		$time          = (string) ( $tournament['match_time'] ?? '19:00:00' );
		$duration      = $this->duration_from_tournament( $tournament );

		// 1. Leer y validar el bracket.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$bracket = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT id, tournament_id, rank_from, rank_to
				 FROM {$wpdb->prefix}ds_playoff_brackets
				 WHERE id = %d",
				$bracket_id
			),
			ARRAY_A
		);

		if ( ! $bracket || (int) $bracket['tournament_id'] !== $tournament_id ) {
			return [ 'match_ids' => [], 'error' => __( 'Bracket no encontrado o no pertenece a este torneo.', 'soccertrack' ) ];
		}

		$rank_from = (int) $bracket['rank_from'];
		$rank_to   = (int) $bracket['rank_to'];

		// 2. Verificar que todos los partidos regulares están finalizados.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$pending = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}ds_matches
				 WHERE tournament_id = %d AND phase = 'regular' AND status NOT IN ('finished', 'suspended', 'postponed')",
				$tournament_id
			)
		);

		if ( $pending > 0 ) {
			return [ 'match_ids' => [], 'error' => __( 'Aún hay partidos de fase regular sin finalizar.', 'soccertrack' ) ];
		}

		// 3. Verificar que no existan ya semi-finales de este bracket.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$existing_sf = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}ds_matches
				 WHERE tournament_id = %d AND bracket_id = %d AND phase = 'semifinal'",
				$tournament_id,
				$bracket_id
			)
		);

		if ( $existing_sf > 0 ) {
			return [ 'match_ids' => [], 'error' => __( 'Las semi-finales de este bracket ya fueron generadas.', 'soccertrack' ) ];
		}

		// 4. Extraer equipos del rango del bracket de la tabla de posiciones.
		$standings     = ( new StandingsCalculator() )->recalculate( $tournament_id );
		$bracket_teams = array_slice( $standings, $rank_from - 1, $rank_to - $rank_from + 1 );
		$num_teams     = count( $bracket_teams );

		if ( $num_teams < 4 ) {
			return [ 'match_ids' => [], 'error' => __( 'Se necesitan al menos 4 equipos en el rango del bracket.', 'soccertrack' ) ];
		}

		// 5. Emparejamiento: primero vs último, segundo vs penúltimo.
		$first  = (int) $bracket_teams[0]['team_id'];
		$second = (int) $bracket_teams[1]['team_id'];
		$third  = (int) $bracket_teams[ $num_teams - 2 ]['team_id'];
		$last   = (int) $bracket_teams[ $num_teams - 1 ]['team_id'];

		$dt_sf1 = $this->next_match_datetime( $weekdays, $time, 0, 0, $duration );
		$dt_sf2 = $this->next_match_datetime( $weekdays, $time, 1, 0, $duration );

		$ids = [];

		foreach ( [
			[ 'home' => $first,  'away' => $last,  'dt' => $dt_sf1 ],
			[ 'home' => $second, 'away' => $third, 'dt' => $dt_sf2 ],
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
					'phase'          => 'semifinal',
					'bracket_id'     => $bracket_id,
				],
				[ '%d', '%d', '%d', '%d', '%d', '%d', '%s', '%s', '%s', '%d' ]
			);

			if ( $wpdb->insert_id ) {
				$ids[] = (int) $wpdb->insert_id;
			}
		}

		$this->assign_courts( $ids, $venue_id );

		return [ 'match_ids' => $ids ];
	}

	/**
	 * Genera la Final y el partido por el 3.er puesto de un bracket específico.
	 *
	 * Requiere que ambas semi-finales del bracket estén finalizadas.
	 * En caso de empate en semi: gana el equipo local (misma regla que generate_finals()).
	 *
	 * @param  array{id:int,match_weekday:int,match_weekdays:string,match_time:string,match_duration:int} $tournament
	 * @param  int $bracket_id
	 * @param  int $venue_id
	 * @return array{match_ids: int[], error?: string}
	 */
	public function generate_bracket_finals( array $tournament, int $bracket_id, int $venue_id ): array {
		global $wpdb;

		$tournament_id = (int) $tournament['id'];
		$weekdays      = $this->weekdays_from_tournament( $tournament );
		$time          = (string) ( $tournament['match_time'] ?? '19:00:00' );
		$duration      = $this->duration_from_tournament( $tournament );

		// 1. Leer semi-finales finalizadas del bracket.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$semis = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, home_team_id, away_team_id, home_score, away_score
				 FROM {$wpdb->prefix}ds_matches
				 WHERE tournament_id = %d AND bracket_id = %d AND phase = 'semifinal' AND status = 'finished'
				 ORDER BY id ASC",
				$tournament_id,
				$bracket_id
			),
			ARRAY_A
		);

		if ( count( $semis ) < 2 ) {
			return [ 'match_ids' => [], 'error' => __( 'Ambas semi-finales del bracket deben estar finalizadas.', 'soccertrack' ) ];
		}

		// 2. Verificar que no existan ya final/3er puesto de este bracket.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$existing = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}ds_matches
				 WHERE tournament_id = %d AND bracket_id = %d AND phase IN ('final', 'third_place')",
				$tournament_id,
				$bracket_id
			)
		);

		if ( $existing > 0 ) {
			return [ 'match_ids' => [], 'error' => __( 'La final de este bracket ya fue generada.', 'soccertrack' ) ];
		}

		// 3. Determinar ganadores y perdedores (empate → gana local).
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

		$dt_3rd   = $this->next_match_datetime( $weekdays, $time, 0, 0, $duration );
		$dt_final = $this->next_match_datetime( $weekdays, $time, 1, 0, $duration );

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
					'bracket_id'     => $bracket_id,
				],
				[ '%d', '%d', '%d', '%d', '%d', '%d', '%s', '%s', '%s', '%d' ]
			);

			if ( $wpdb->insert_id ) {
				$ids[] = (int) $wpdb->insert_id;
			}
		}

		$this->assign_courts( $ids, $venue_id );

		return [ 'match_ids' => $ids ];
	}
}
