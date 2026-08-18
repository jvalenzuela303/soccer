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
	 * Construye un datetime a partir de una fecha elegida ('Y-m-d') y la hora del torneo,
	 * aplicando el desplazamiento de franja (batch_offset × duration).
	 *
	 * @param string $date           Fecha en formato 'Y-m-d'.
	 * @param string $time           Hora en formato 'H:i:s'.
	 * @param int    $batch_offset   Franja (0 = primer partido, 1 = segundo, …).
	 * @param int    $duration_minutes Duración de cada franja en minutos.
	 */
	private function datetime_from_date( string $date, string $time, int $batch_offset, int $duration_minutes ): string {
		[ $h, $m, $s ]  = array_map( 'intval', explode( ':', $time . ':00' ) );
		$start_minutes   = $h * 60 + $m + $batch_offset * $duration_minutes;
		$dt              = new \DateTimeImmutable( $date );
		$dt              = $dt->setTime( intdiv( $start_minutes, 60 ) % 24, $start_minutes % 60, $s );
		return $dt->format( 'Y-m-d H:i:s' );
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
	 * @param  int         $bracket_id  ID del bracket en ds_playoff_brackets.
	 * @param  int         $venue_id    Recinto donde se disputarán las semi-finales.
	 * @param  string|null $match_date  Fecha elegida por el coordinador ('Y-m-d'). Si null, usa el próximo día hábil del torneo.
	 * @return array{match_ids: int[], error?: string}
	 */
	public function generate_bracket_playoffs( array $tournament, int $bracket_id, int $venue_id, ?string $match_date = null ): array {
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

		// 3. Detectar estado de cuartos para este bracket.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$qf_matches = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, home_team_id, away_team_id, home_score, away_score, status
				 FROM {$wpdb->prefix}ds_matches
				 WHERE tournament_id = %d AND bracket_id = %d AND phase = 'quarterfinal'
				 ORDER BY id ASC",
				$tournament_id,
				$bracket_id
			),
			ARRAY_A
		) ?: [];

		$has_qf         = ! empty( $qf_matches );
		$qf_done        = $has_qf && count( array_filter( $qf_matches, static fn( $m ) => ! in_array( $m['status'], [ 'finished', 'suspended', 'postponed' ], true ) ) ) === 0;
		$qf_in_progress = $has_qf && ! $qf_done;

		if ( $qf_in_progress ) {
			return [ 'match_ids' => [], 'error' => __( 'Los cuartos de final de este bracket están en curso. Espera a que terminen.', 'soccertrack' ) ];
		}

		// 4. Extraer equipos del rango del bracket de la tabla de posiciones.
		$standings     = ( new StandingsCalculator() )->recalculate( $tournament_id );
		$bracket_teams = array_slice( $standings, $rank_from - 1, $rank_to - $rank_from + 1 );
		$num_teams     = count( $bracket_teams );

		if ( ! $has_qf && $num_teams < 4 ) {
			return [ 'match_ids' => [], 'error' => __( 'Se necesitan al menos 4 equipos en el rango del bracket.', 'soccertrack' ) ];
		}

		// 5a. Generar SEMI-FINALES desde ganadores de cuartos (cuando cuartos done).
		if ( $qf_done ) {
			if ( count( $qf_matches ) < 4 ) {
				return [ 'match_ids' => [], 'error' => __( 'Se esperaban 4 partidos de cuartos de final finalizados, pero se encontraron menos.', 'soccertrack' ) ];
			}

			// Verificar que no existan ya semi-finales.
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

			// Resolver ganadores de cuartos (empate → gana local).
			$resolve = static function ( array $m ): int {
				return (int) $m['home_score'] >= (int) $m['away_score']
					? (int) $m['home_team_id']
					: (int) $m['away_team_id'];
			};

			// QF1 ganador vs QF4 ganador → SF1; QF2 ganador vs QF3 ganador → SF2.
			$sf_pairs = [
				[ 'home' => $resolve( $qf_matches[0] ), 'away' => $resolve( $qf_matches[3] ) ],
				[ 'home' => $resolve( $qf_matches[1] ), 'away' => $resolve( $qf_matches[2] ) ],
			];

			if ( $match_date ) {
				$sf_pairs[0]['dt'] = $this->datetime_from_date( $match_date, $time, 0, $duration );
				$sf_pairs[1]['dt'] = $this->datetime_from_date( $match_date, $time, 1, $duration );
			} else {
				$sf_pairs[0]['dt'] = $this->next_match_datetime( $weekdays, $time, 0, 0, $duration );
				$sf_pairs[1]['dt'] = $this->next_match_datetime( $weekdays, $time, 1, 0, $duration );
			}

			$ids = [];
			foreach ( $sf_pairs as $pair ) {
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

		// 5b. Generar CUARTOS DE FINAL (bracket de 8 equipos, seeding estándar).
		if ( $num_teams >= 8 ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$existing_qf = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$wpdb->prefix}ds_matches
					 WHERE tournament_id = %d AND bracket_id = %d AND phase = 'quarterfinal'",
					$tournament_id,
					$bracket_id
				)
			);
			if ( $existing_qf > 0 ) {
				return [ 'match_ids' => [], 'error' => __( 'Los cuartos de final de este bracket ya fueron generados.', 'soccertrack' ) ];
			}

			// Seeding: 1º vs 8º, 2º vs 7º, 3º vs 6º, 4º vs 5º.
			$qf_pairs = [
				[ 'home' => (int) $bracket_teams[0]['team_id'], 'away' => (int) $bracket_teams[7]['team_id'] ],
				[ 'home' => (int) $bracket_teams[1]['team_id'], 'away' => (int) $bracket_teams[6]['team_id'] ],
				[ 'home' => (int) $bracket_teams[2]['team_id'], 'away' => (int) $bracket_teams[5]['team_id'] ],
				[ 'home' => (int) $bracket_teams[3]['team_id'], 'away' => (int) $bracket_teams[4]['team_id'] ],
			];

			$ids = [];
			foreach ( $qf_pairs as $i => $pair ) {
				$dt = $match_date
					? $this->datetime_from_date( $match_date, $time, $i, $duration )
					: $this->next_match_datetime( $weekdays, $time, $i, 0, $duration );

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
						'match_datetime' => $dt,
						'status'         => 'scheduled',
						'phase'          => 'quarterfinal',
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

		// 5c. Generar SEMI-FINALES directamente (bracket de 4 equipos — comportamiento actual).
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

		$first  = (int) $bracket_teams[0]['team_id'];
		$second = (int) $bracket_teams[1]['team_id'];
		$third  = (int) $bracket_teams[ $num_teams - 2 ]['team_id'];
		$last   = (int) $bracket_teams[ $num_teams - 1 ]['team_id'];

		if ( $match_date ) {
			$dt_sf1 = $this->datetime_from_date( $match_date, $time, 0, $duration );
			$dt_sf2 = $this->datetime_from_date( $match_date, $time, 1, $duration );
		} else {
			$dt_sf1 = $this->next_match_datetime( $weekdays, $time, 0, 0, $duration );
			$dt_sf2 = $this->next_match_datetime( $weekdays, $time, 1, 0, $duration );
		}

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
	 * Genera el fixture completo de un torneo en Fase de Grupos.
	 *
	 * Flujo:
	 *  1. Carga y baraja equipos (Fisher-Yates).
	 *  2. Distribuye en N grupos (letras A, B, C…). Si total no divide exactamente,
	 *     los últimos grupos tienen un equipo menos (diferencia máxima de 1).
	 *  3. Actualiza ds_teams.group_label.
	 *  4. Por cada grupo: genera round-robin completo con phase='regular', group_label='X'.
	 *  5. Asigna canchas.
	 *
	 * @param  array{id:int,match_weekday:int,match_weekdays:string,match_time:string,match_duration:int,group_count:int,teams_advancing_per_group:int,has_third_place:int} $tournament
	 * @param  int $venue_id
	 * @return array{match_ids: int[], error?: string}
	 */
	public function generate_group_stage( array $tournament, int $venue_id ): array {
		global $wpdb;

		$tournament_id = (int) $tournament['id'];
		$group_count   = max( 2, min( 8, (int) ( $tournament['group_count'] ?? 2 ) ) );
		$weekdays      = $this->weekdays_from_tournament( $tournament );
		$time          = (string) ( $tournament['match_time'] ?? '19:00:00' );
		$duration      = $this->duration_from_tournament( $tournament );
		$num_courts    = $this->count_courts( $venue_id );

		// 1. Cargar equipos.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$team_ids = array_map(
			'intval',
			$wpdb->get_col(
				$wpdb->prepare(
					"SELECT id FROM {$wpdb->prefix}ds_teams WHERE tournament_id = %d ORDER BY id ASC",
					$tournament_id
				)
			)
		);

		$total = count( $team_ids );

		// 2. Validar mínimo 2 equipos por grupo.
		if ( $total < $group_count * 2 ) {
			return [
				'match_ids' => [],
				'error'     => sprintf(
					'Equipos insuficientes para %d grupos (mínimo %d equipos).',
					$group_count,
					$group_count * 2
				),
			];
		}

		// 3. Verificar que no exista fixture previo.
		$existing = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}ds_matches WHERE tournament_id = %d",
				$tournament_id
			)
		);
		if ( $existing > 0 ) {
			return [ 'match_ids' => [], 'error' => 'Ya existen partidos generados para este torneo.' ];
		}

		// 4. Fisher-Yates shuffle.
		for ( $i = $total - 1; $i > 0; $i-- ) {
			$j             = random_int( 0, $i );
			[ $team_ids[ $i ], $team_ids[ $j ] ] = [ $team_ids[ $j ], $team_ids[ $i ] ];
		}

		// 5. Distribuir en grupos: ceil() para los primeros grupos, el resto tiene 1 menos.
		$base_size    = (int) ceil( $total / $group_count );
		$groups       = [];
		$group_labels = range( 'A', chr( ord( 'A' ) + $group_count - 1 ) );
		$offset       = 0;
		foreach ( $group_labels as $label ) {
			$remaining   = $total - $offset;
			$groups_left = $group_count - count( $groups );
			$size        = (int) ceil( $remaining / $groups_left );
			$groups[ $label ] = array_slice( $team_ids, $offset, $size );
			$offset += $size;
		}

		// 6. Actualizar group_label en ds_teams.
		foreach ( $groups as $label => $group_team_ids ) {
			foreach ( $group_team_ids as $tid ) {
				$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
					"{$wpdb->prefix}ds_teams",
					[ 'group_label' => $label ],
					[ 'id' => $tid ],
					[ '%s' ],
					[ '%d' ]
				);
			}
		}

		// 7. Generar round-robin por grupo; round_index continuo a través de grupos
		//    para que los partidos de cada grupo caigan en fechas distintas.
		$all_match_ids  = [];
		$round_offset   = 0; // Acumula rondas para calcular fechas escaladas por grupo.

		foreach ( $groups as $label => $group_team_ids ) {
			$n     = count( $group_team_ids );
			$teams = $group_team_ids;

			// Número impar → agregar null (bye).
			if ( $n % 2 !== 0 ) {
				$teams[] = null;
				$n++;
			}

			$rounds_in_group = $n - 1;

			for ( $r = 1; $r <= $rounds_in_group; $r++ ) {
				$pairs = [];
				for ( $i = 0; $i < $n / 2; $i++ ) {
					$home = $teams[ $i ];
					$away = $teams[ $n - 1 - $i ];
					if ( $home === null || $away === null ) {
						continue;
					}
					if ( $r % 2 === 0 ) {
						[ $home, $away ] = [ $away, $home ];
					}
					$pairs[] = [ 'home' => $home, 'away' => $away ];
				}

				$round_index  = $round_offset + $r - 1;
				$n_pairs      = count( $pairs );
				$num_batches  = max( 1, (int) ceil( $n_pairs / $num_courts ) );

				foreach ( $pairs as $idx => $pair ) {
					$base_batch    = (int) floor( $idx / $num_courts );
					$rotated_batch = ( $base_batch + ( $r - 1 ) ) % $num_batches;
					$dt            = $this->next_match_datetime( $weekdays, $time, $rotated_batch, $round_index, $duration );

					// phpcs:ignore WordPress.DB.DirectDatabaseQuery
					$wpdb->insert(
						"{$wpdb->prefix}ds_matches",
						[
							'tournament_id'  => $tournament_id,
							'round_number'   => $round_offset + $r, // Jornada global.
							'home_team_id'   => $pair['home'],
							'away_team_id'   => $pair['away'],
							'venue_id'       => $venue_id,
							'court_id'       => 0,
							'match_datetime' => $dt,
							'status'         => 'scheduled',
							'phase'          => 'regular',
							'group_label'    => $label,
						],
						[ '%d', '%d', '%d', '%d', '%d', '%d', '%s', '%s', '%s', '%s' ]
					);

					if ( $wpdb->insert_id ) {
						$all_match_ids[] = (int) $wpdb->insert_id;
					}
				}

				// Rotación circular (algoritmo Round-Robin estándar).
				$fixed = array_shift( $teams );
				$last  = array_pop( $teams );
				array_unshift( $teams, $last );
				array_unshift( $teams, $fixed );
			}

			$round_offset += $rounds_in_group;
		}

		// 8. Asignar canchas.
		$this->assign_courts( $all_match_ids, $venue_id );

		return [ 'match_ids' => $all_match_ids ];
	}

	/**
	 * Genera la Final y el partido por el 3.er puesto de un bracket específico.
	 *
	 * Requiere que ambas semi-finales del bracket estén finalizadas.
	 * En caso de empate en semi: gana el equipo local (misma regla que generate_finals()).
	 *
	 * @param  array{id:int,match_weekday:int,match_weekdays:string,match_time:string,match_duration:int} $tournament
	 * @param  int         $bracket_id
	 * @param  int         $venue_id
	 * @param  string|null $match_date  Fecha elegida por el coordinador ('Y-m-d'). Si null, usa el próximo día hábil del torneo.
	 * @return array{match_ids: int[], error?: string}
	 */
	public function generate_bracket_finals( array $tournament, int $bracket_id, int $venue_id, ?string $match_date = null ): array {
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

		if ( $match_date ) {
			$dt_3rd   = $this->datetime_from_date( $match_date, $time, 0, $duration );
			$dt_final = $this->datetime_from_date( $match_date, $time, 1, $duration );
		} else {
			$dt_3rd   = $this->next_match_datetime( $weekdays, $time, 0, 0, $duration );
			$dt_final = $this->next_match_datetime( $weekdays, $time, 1, 0, $duration );
		}

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

	// =========================================================================
	// Swiss tournament methods
	// =========================================================================

	/**
	 * Retorna un Set de claves "min:max" de pares de equipos que ya jugaron entre sí.
	 * Excluye partidos contra el equipo fantasma (bye) para no bloquear rematches vs LIBRE.
	 *
	 * @param  int   $tournament_id
	 * @param  int   $ghost_id  ID del equipo LIBRE (0 si no existe aún).
	 * @return array<string, true>  Claves "minId:maxId" => true.
	 */
	private function get_played_pairs( int $tournament_id, int $ghost_id = 0 ): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT home_team_id, away_team_id
				 FROM {$wpdb->prefix}ds_matches
				 WHERE tournament_id = %d AND phase = 'regular' AND status != 'suspended'",
				$tournament_id
			),
			ARRAY_A
		);

		$pairs = [];
		foreach ( $rows as $row ) {
			$a = (int) $row['home_team_id'];
			$b = (int) $row['away_team_id'];
			// No registrar partidos vs el equipo fantasma como "jugados" entre reales.
			if ( $ghost_id > 0 && ( $a === $ghost_id || $b === $ghost_id ) ) {
				continue;
			}
			$key           = min( $a, $b ) . ':' . max( $a, $b );
			$pairs[ $key ] = true;
		}

		return $pairs;
	}

	/**
	 * Empareja equipos usando algoritmo greedy top-down con backtracking simple.
	 *
	 * Orden de candidatos dentro del mismo grupo de puntos: DG DESC (ya viene así de standings).
	 * Si todos los candidatos cercanos ya jugaron contra el equipo actual, desciende al siguiente bucket.
	 * Backtracking de 1 nivel: si un equipo queda sin pareja, intenta intercambiar el último par formado.
	 *
	 * @param  int[]                 $team_ids    IDs en orden standings (pts DESC, dg DESC, gf DESC).
	 * @param  array<string, true>   $played      Set de pares ya jugados ("minId:maxId").
	 * @return array<array{home:int,away:int}>  Pares emparejados.
	 */
	private function build_swiss_pairs( array $team_ids, array $played ): array {
		$paired = [];   // team_id => true (ya tiene pareja).
		$pairs  = [];

		$n = count( $team_ids );

		for ( $i = 0; $i < $n; $i++ ) {
			$a = $team_ids[ $i ];
			if ( isset( $paired[ $a ] ) ) {
				continue;
			}

			$found = false;
			for ( $j = $i + 1; $j < $n; $j++ ) {
				$b = $team_ids[ $j ];
				if ( isset( $paired[ $b ] ) ) {
					continue;
				}
				$key = min( $a, $b ) . ':' . max( $a, $b );
				if ( isset( $played[ $key ] ) ) {
					continue;
				}
				// Par válido encontrado.
				$pairs[]      = [ 'home' => $a, 'away' => $b ];
				$paired[ $a ] = true;
				$paired[ $b ] = true;
				$found        = true;
				break;
			}

			if ( ! $found ) {
				if ( ! empty( $pairs ) ) {
					// Backtracking: deshacer el último par e intentar reasignar.
					$last = array_pop( $pairs );
					$c    = $last['home'];
					$d    = $last['away'];
					unset( $paired[ $c ], $paired[ $d ] );

					$key_ca = min( $c, $a ) . ':' . max( $c, $a );
					$key_da = min( $d, $a ) . ':' . max( $d, $a );

					if ( ! isset( $played[ $key_ca ] ) ) {
						// c juega con a; d queda libre para la siguiente iteración.
						$pairs[]      = [ 'home' => $c, 'away' => $a ];
						$paired[ $c ] = true;
						$paired[ $a ] = true;
					} elseif ( ! isset( $played[ $key_da ] ) ) {
						// d juega con a; c queda libre.
						$pairs[]      = [ 'home' => $d, 'away' => $a ];
						$paired[ $d ] = true;
						$paired[ $a ] = true;
					} else {
						// No hay solución de backtracking — restaurar par original, a quedará sin pareja (bye).
						$pairs[]      = [ 'home' => $c, 'away' => $d ];
						$paired[ $c ] = true;
						$paired[ $d ] = true;
						// $a queda explícitamente sin pareja — será recogido por el path de bye.
					}
				}
				// Si $pairs está vacío (primer equipo sin candidato válido), $a queda sin pareja.
				// El path de bye en generate_swiss_round() lo detectará vía array_diff().
				// No se necesita acción adicional — $a no está en $paired, así que aparecerá en $unpaired.
			}
		}

		return $pairs;
	}

	/**
	 * Selecciona el equipo que descansará esta ronda (bye).
	 *
	 * Prioridad: el equipo de menor ranking que aún no haya tenido bye.
	 * Si todos tuvieron bye, asigna al de menor ranking.
	 *
	 * @param  int[] $unpaired_ids  IDs sin pareja, en orden standings (mejor primero).
	 * @param  int[] $bye_history   IDs que ya tuvieron bye en rondas anteriores.
	 * @return int   team_id seleccionado para el bye.
	 */
	private function select_bye_team( array $unpaired_ids, array $bye_history ): int {
		// Invertir para tener peor primero (menor ranking).
		$worst_first = array_reverse( $unpaired_ids );
		foreach ( $worst_first as $tid ) {
			if ( ! in_array( $tid, $bye_history, true ) ) {
				return $tid;
			}
		}
		// Todos tuvieron bye — asignar al de peor ranking.
		return $worst_first[0];
	}

	/**
	 * Retorna el ID del equipo LIBRE del torneo, creándolo si no existe.
	 *
	 * @param  int $tournament_id
	 * @return int  ID del equipo fantasma.
	 */
	private function ensure_ghost_team( int $tournament_id ): int {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$existing = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$wpdb->prefix}ds_teams WHERE tournament_id = %d AND is_ghost = 1 LIMIT 1",
				$tournament_id
			)
		);

		if ( $existing > 0 ) {
			return $existing;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->insert(
			"{$wpdb->prefix}ds_teams",
			[
				'tournament_id' => $tournament_id,
				'name'          => 'LIBRE',
				'is_ghost'      => 1,
			],
			[ '%d', '%s', '%d' ]
		);

		return (int) $wpdb->insert_id;
	}

	/**
	 * Retorna el estado de la fase liga Swiss de un torneo.
	 *
	 * @param  int $tournament_id
	 * @return array{current_round:int, total_rounds:int, round_complete:bool, swiss_done:bool, bye_history:int[]}
	 */
	public function get_swiss_status( int $tournament_id ): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$swiss_rounds = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT swiss_rounds FROM {$wpdb->prefix}ds_tournaments WHERE id = %d",
				$tournament_id
			)
		);
		$total_rounds = max( 1, $swiss_rounds ?: 8 );

		// Última ronda generada.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$current_round = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT MAX(round_number) FROM {$wpdb->prefix}ds_matches
				 WHERE tournament_id = %d AND phase = 'regular'",
				$tournament_id
			)
		);

		// ¿Todos los partidos de la ronda actual están finalizados?
		$round_complete = false;
		if ( $current_round > 0 ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$pending = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$wpdb->prefix}ds_matches
					 WHERE tournament_id = %d AND phase = 'regular'
					   AND round_number = %d AND status NOT IN ('finished', 'suspended')",
					$tournament_id,
					$current_round
				)
			);
			$round_complete = ( 0 === $pending );
		}

		// Historial de byes: equipos que jugaron contra el equipo LIBRE.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$ghost_id = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$wpdb->prefix}ds_teams WHERE tournament_id = %d AND is_ghost = 1 LIMIT 1",
				$tournament_id
			)
		);

		$bye_history = [];
		if ( $ghost_id > 0 ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$bye_rows = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT home_team_id FROM {$wpdb->prefix}ds_matches
					 WHERE tournament_id = %d AND away_team_id = %d AND phase = 'regular'",
					$tournament_id,
					$ghost_id
				)
			);
			$bye_history = array_map( 'intval', $bye_rows );
		}

		return [
			'current_round'  => $current_round,
			'total_rounds'   => $total_rounds,
			'round_complete' => $round_complete,
			'swiss_done'     => $current_round >= $total_rounds && $round_complete,
			'bye_history'    => $bye_history,
		];
	}

	/**
	 * Genera los emparejamientos de la ronda N del formato Swiss.
	 *
	 * Flujo:
	 *  1. Guard de idempotencia: si ya existen partidos de esta ronda, retorna error.
	 *  2. Obtiene standings actuales via StandingsCalculator.
	 *  3. Carga pares ya jugados desde ds_matches.
	 *  4. Empareja con algoritmo greedy + backtracking (build_swiss_pairs).
	 *  5. Si número impar, asigna bye al equipo de menor ranking sin bye previo.
	 *  6. Inserta partidos; el partido bye se inserta como 'finished' 0-0 (no suma puntos
	 *     porque StandingsCalculator excluye el equipo LIBRE).
	 *  7. Asigna canchas con assign_courts().
	 *
	 * @param  array{id:int,swiss_rounds:int,match_weekday:int,match_weekdays:string,match_time:string,match_time_weekend:string,match_duration:int} $tournament
	 * @param  int         $round_number  Número de ronda 1-based.
	 * @param  int         $venue_id
	 * @param  string|null $match_date    Fecha override 'Y-m-d'. Null usa next_match_datetime().
	 * @return array{match_ids:int[], error?:string}
	 */
	public function generate_swiss_round(
		array   $tournament,
		int     $round_number,
		int     $venue_id,
		?string $match_date = null
	): array {
		global $wpdb;

		$tournament_id = (int) $tournament['id'];

		// 1. Guard de idempotencia.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$existing = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}ds_matches
				 WHERE tournament_id = %d AND round_number = %d AND phase = 'regular'",
				$tournament_id,
				$round_number
			)
		);
		if ( $existing > 0 ) {
			return [
				'match_ids' => [],
				'error'     => sprintf(
					/* translators: %d: número de ronda */
					__( 'La ronda %d ya fue generada.', 'soccertrack' ),
					$round_number
				),
			];
		}

		// 2. Standings actuales (excluye equipo LIBRE por is_ghost=0 en StandingsCalculator).
		$standings = ( new StandingsCalculator() )->recalculate( $tournament_id );

		if ( count( $standings ) < 2 ) {
			return [
				'match_ids' => [],
				'error'     => __( 'Se necesitan al menos 2 equipos para generar una ronda Swiss.', 'soccertrack' ),
			];
		}

		// IDs en orden standings (mejor → peor).
		$team_ids = array_column( $standings, 'team_id' );

		// 3. Ghost team y historial de byes.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$ghost_id = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$wpdb->prefix}ds_teams WHERE tournament_id = %d AND is_ghost = 1 LIMIT 1",
				$tournament_id
			)
		);

		$bye_history = [];
		if ( $ghost_id > 0 ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$bye_rows    = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT home_team_id FROM {$wpdb->prefix}ds_matches
					 WHERE tournament_id = %d AND away_team_id = %d AND phase = 'regular'",
					$tournament_id,
					$ghost_id
				)
			);
			$bye_history = array_map( 'intval', $bye_rows );
		}

		// 4. Pares ya jugados.
		$played_pairs = $this->get_played_pairs( $tournament_id, $ghost_id );

		// 5. Construir emparejamientos.
		$pairs = $this->build_swiss_pairs( $team_ids, $played_pairs );

		// 6. Detectar equipo sin pareja (número impar de equipos).
		$paired_ids = [];
		foreach ( $pairs as $pair ) {
			$paired_ids[] = $pair['home'];
			$paired_ids[] = $pair['away'];
		}
		$unpaired = array_values( array_diff( $team_ids, $paired_ids ) );

		$bye_pair = null;
		if ( ! empty( $unpaired ) ) {
			$bye_team_id = $this->select_bye_team( $unpaired, $bye_history );
			$ghost_id    = $this->ensure_ghost_team( $tournament_id );
			$bye_pair    = [ 'home' => $bye_team_id, 'away' => $ghost_id, 'is_bye' => true ];
		}

		// 7. Insertar partidos regulares usando insert_round().
		$weekdays   = $this->weekdays_from_tournament( $tournament );
		$time       = (string) ( $tournament['match_time'] ?? '19:00:00' );
		$duration   = $this->duration_from_tournament( $tournament );
		$num_courts = $this->count_courts( $venue_id );

		$match_ids = $this->insert_round(
			$tournament_id,
			$round_number,
			$pairs,
			$venue_id,
			$weekdays,
			$time,
			$num_courts,
			$duration
		);

		// 8. Insertar partido bye (si aplica) como 'finished' 0-0.
		if ( null !== $bye_pair ) {
			$dt = $match_date
				? $match_date . ' ' . $time
				: $this->next_match_datetime( $weekdays, $time, count( $pairs ), $round_number - 1, $duration );

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->insert(
				"{$wpdb->prefix}ds_matches",
				[
					'tournament_id'  => $tournament_id,
					'round_number'   => $round_number,
					'home_team_id'   => $bye_pair['home'],
					'away_team_id'   => $bye_pair['away'],
					'venue_id'       => $venue_id,
					'court_id'       => 0,
					'match_datetime' => $dt,
					'status'         => 'finished',
					'phase'          => 'regular',
					'home_score'     => 0,
					'away_score'     => 0,
				],
				[ '%d', '%d', '%d', '%d', '%d', '%d', '%s', '%s', '%s', '%d', '%d' ]
			);
			if ( $wpdb->insert_id ) {
				$match_ids[] = (int) $wpdb->insert_id;
			}
		}

		// 9. Asignar canchas a todos los partidos.
		$this->assign_courts( $match_ids, $venue_id );

		return [ 'match_ids' => $match_ids ];
	}

	// =========================================================================
	// Knockout tournament methods
	// =========================================================================

	/**
	 * Genera el cuadro inicial de un torneo de eliminación directa.
	 *
	 * - Fisher-Yates shuffle de los equipos.
	 * - Rellena hasta la siguiente potencia de 2 con byes.
	 * - Los byes se insertan como partidos finalizados (home gana 1-0, away_team_id = NULL).
	 *
	 * @param  array{id:int,match_weekday:int,match_time:string,match_duration?:int} $tournament
	 * @param  int $venue_id
	 * @return array{match_ids: int[]}|array{match_ids: int[], error: string}
	 */
	public function generate_knockout_initial( array $tournament, int $venue_id ): array {
		global $wpdb;

		$tournament_id = (int) $tournament['id'];
		$weekdays      = $this->weekdays_from_tournament( $tournament );
		$time          = (string) ( $tournament['match_time'] ?? '19:00:00' );
		$duration      = $this->duration_from_tournament( $tournament );

		// 1. Cargar equipos.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$team_ids = array_map(
			'intval',
			$wpdb->get_col(
				$wpdb->prepare(
					"SELECT id FROM {$wpdb->prefix}ds_teams WHERE tournament_id = %d ORDER BY id ASC",
					$tournament_id
				)
			)
		);

		$n = count( $team_ids );

		// 2. Validar rango.
		if ( $n < 2 ) {
			return [ 'match_ids' => [], 'error' => __( 'Se necesitan al menos 2 equipos.', 'soccertrack' ) ];
		}
		if ( $n > 16 ) {
			return [ 'match_ids' => [], 'error' => __( 'No soportado (máx. 16 equipos).', 'soccertrack' ) ];
		}

		// 3. Verificar que no existan partidos previos de eliminación.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$existing = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}ds_matches
				 WHERE tournament_id = %d AND phase != 'regular'",
				$tournament_id
			)
		);
		if ( $existing > 0 ) {
			return [ 'match_ids' => [], 'error' => __( 'El cuadro ya fue generado.', 'soccertrack' ) ];
		}

		// 4. Fisher-Yates shuffle.
		for ( $i = $n - 1; $i > 0; $i-- ) {
			$j             = random_int( 0, $i );
			[ $team_ids[ $i ], $team_ids[ $j ] ] = [ $team_ids[ $j ], $team_ids[ $i ] ];
		}

		// 5. Calcular tamaño del bracket (siguiente potencia de 2).
		$s    = 2;
		while ( $s < $n ) {
			$s *= 2;
		}

		// 6. Determinar fase inicial.
		$first_phase = match( true ) {
			$s <= 2  => 'final',
			$s <= 4  => 'semifinal',
			$s <= 8  => 'quarterfinal',
			default  => 'octavos',
		};

		// Rellenar con null para los byes (primeras posiciones = mayor seeding).
		// Los primeros $byes equipos recibirán un bye (ganan automáticamente).
		// Estructura: [ bye_team_1, bye_team_2, ..., real_A, real_B, ..., real_X ]
		// Emparejamiento estándar: posición i vs posición (S-1-i).
		// Si la posición (S-1-i) >= N → ese oponente es un bye → bye match para teams[i].
		$all_ids       = [];
		$scheduled_ids = [];
		$now           = current_time( 'mysql' );

		for ( $i = 0; $i < $s / 2; $i++ ) {
			$home_slot = $i;
			$away_slot = $s - 1 - $i;

			$home_team = $home_slot < $n ? $team_ids[ $home_slot ] : null;
			$away_team = $away_slot < $n ? $team_ids[ $away_slot ] : null;

			if ( $home_team === null ) {
				// Ambos slots son byes — no insertar partido.
				continue;
			}

			if ( $away_team === null ) {
				// Bye: home avanza automáticamente.
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$wpdb->insert(
					"{$wpdb->prefix}ds_matches",
					[
						'tournament_id'  => $tournament_id,
						'round_number'   => 0,
						'home_team_id'   => $home_team,
						'away_team_id'   => null,
						'venue_id'       => $venue_id,
						'court_id'       => 0,
						'match_datetime' => $now,
						'status'         => 'finished',
						'home_score'     => 1,
						'away_score'     => 0,
						'phase'          => $first_phase,
					],
					[ '%d', '%d', '%d', null, '%d', '%d', '%s', '%s', '%d', '%d', '%s' ]
				);
			} else {
				// Partido real.
				$dt = $this->next_match_datetime( $weekdays, $time, (int) floor( $i / 2 ), 0, $duration );
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$wpdb->insert(
					"{$wpdb->prefix}ds_matches",
					[
						'tournament_id'  => $tournament_id,
						'round_number'   => 0,
						'home_team_id'   => $home_team,
						'away_team_id'   => $away_team,
						'venue_id'       => $venue_id,
						'court_id'       => 0,
						'match_datetime' => $dt,
						'status'         => 'scheduled',
						'phase'          => $first_phase,
					],
					[ '%d', '%d', '%d', '%d', '%d', '%d', '%s', '%s', '%s' ]
				);
				if ( $wpdb->insert_id ) {
					$scheduled_ids[] = (int) $wpdb->insert_id;
				}
			}

			if ( $wpdb->insert_id ) {
				$all_ids[] = (int) $wpdb->insert_id;
			}
		}

		if ( ! empty( $scheduled_ids ) ) {
			$this->assign_courts( $scheduled_ids, $venue_id );
		}

		return [ 'match_ids' => $all_ids ];
	}

	/**
	 * Detecta la fase activa del cuadro de eliminación y genera la siguiente ronda.
	 *
	 * Llámalo inline después de guardar el resultado de cualquier partido
	 * cuando el torneo es de formato 'knockout'. Si la fase activa no está
	 * completa todavía, retorna match_ids = [] sin error.
	 *
	 * @param  int $tournament_id
	 * @param  int $venue_id      Recinto donde se disputará la siguiente ronda.
	 * @return array{match_ids: int[]}|array{match_ids: int[], error: string}
	 */
	public function generate_knockout_next_round( int $tournament_id, int $venue_id ): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$t = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT match_weekday, match_weekdays, match_time, match_time_weekend, match_duration, has_third_place
				 FROM {$wpdb->prefix}ds_tournaments WHERE id = %d",
				$tournament_id
			),
			ARRAY_A
		) ?: [];

		$weekdays        = $this->weekdays_from_tournament( $t );
		$time            = (string) ( $t['match_time'] ?? '19:00:00' );
		$duration        = $this->duration_from_tournament( $t );
		$has_third_place = (int) ( $t['has_third_place'] ?? 0 );

		// 1. Detectar fase activa: la más reciente donde todos los partidos están terminados.
		$phase_order = [ 'octavos', 'quarterfinal', 'semifinal', 'third_place', 'final' ];

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT phase,
				        COUNT(*) AS total,
				        SUM( status IN ('finished','suspended') ) AS done
				 FROM {$wpdb->prefix}ds_matches
				 WHERE tournament_id = %d AND phase != 'regular'
				 GROUP BY phase",
				$tournament_id
			),
			ARRAY_A
		) ?: [];

		// Ordenar por posición en el árbol.
		usort( $rows, static function ( array $a, array $b ) use ( $phase_order ): int {
			return array_search( $a['phase'], $phase_order, true ) <=> array_search( $b['phase'], $phase_order, true );
		} );

		// Excluir third_place de la detección de fase activa
		// (third_place nunca genera una fase sucesora).
		$rows_for_detection = array_filter( $rows, static fn( array $r ) => $r['phase'] !== 'third_place' );

		// La fase activa es la última que tiene todos sus partidos terminados
		// pero cuya siguiente fase aún no existe.
		$active_phase = null;
		foreach ( $rows_for_detection as $row ) {
			if ( (int) $row['total'] > 0 && (int) $row['done'] === (int) $row['total'] ) {
				$active_phase = $row['phase'];
			}
		}

		if ( $active_phase === null ) {
			return [ 'match_ids' => [] ]; // Nada que hacer aún.
		}

		// 2. Determinar siguiente fase.
		$next_phase = match( $active_phase ) {
			'octavos'      => 'quarterfinal',
			'quarterfinal' => 'semifinal',
			'semifinal'    => 'final',
			default        => null, // 'final' o 'third_place' → torneo completo.
		};

		if ( $next_phase === null ) {
			return [ 'match_ids' => [] ]; // Torneo completo.
		}

		// 3. Guard: la siguiente fase ya existe.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$next_exists = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}ds_matches
				 WHERE tournament_id = %d AND phase = %s",
				$tournament_id,
				$next_phase
			)
		);
		if ( $next_exists > 0 ) {
			return [ 'match_ids' => [] ]; // Ya generada (idempotente).
		}

		// 4. Colectar ganadores (ignorar byes: away_team_id IS NULL → home gana).
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$matches = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT home_team_id, away_team_id, home_score, away_score, status
				 FROM {$wpdb->prefix}ds_matches
				 WHERE tournament_id = %d AND phase = %s
				 ORDER BY id ASC",
				$tournament_id,
				$active_phase
			),
			ARRAY_A
		) ?: [];

		$winners = [];
		$losers  = []; // Necesarios para 3.er puesto.

		foreach ( $matches as $m ) {
			if ( $m['away_team_id'] === null ) {
				$winners[] = (int) $m['home_team_id']; // Bye.
				continue;
			}
			$hs = (int) $m['home_score'];
			$as = (int) $m['away_score'];
			if ( $hs > $as ) {
				$winners[] = (int) $m['home_team_id'];
				$losers[]  = (int) $m['away_team_id'];
			} elseif ( $as > $hs ) {
				$winners[] = (int) $m['away_team_id'];
				$losers[]  = (int) $m['home_team_id'];
			} else {
				return [
					'match_ids' => [],
					'error'     => __( 'Hay partidos empatados — el formato knockout no admite empates.', 'soccertrack' ),
				];
			}
		}

		// 5. Generar partidos de la siguiente fase.
		$all_ids = [];

		for ( $i = 0; $i < count( $winners ) - 1; $i += 2 ) {
			$dt = $this->next_match_datetime( $weekdays, $time, (int) ( $i / 2 ), 0, $duration );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->insert(
				"{$wpdb->prefix}ds_matches",
				[
					'tournament_id'  => $tournament_id,
					'round_number'   => 0,
					'home_team_id'   => $winners[ $i ],
					'away_team_id'   => $winners[ $i + 1 ],
					'venue_id'       => $venue_id,
					'court_id'       => 0,
					'match_datetime' => $dt,
					'status'         => 'scheduled',
					'phase'          => $next_phase,
				],
				[ '%d', '%d', '%d', '%d', '%d', '%d', '%s', '%s', '%s' ]
			);
			if ( $wpdb->insert_id ) {
				$all_ids[] = (int) $wpdb->insert_id;
			}
		}

		// 6. Si viene de semifinal → también generar 3.er puesto (si está habilitado).
		if ( $active_phase === 'semifinal' && $has_third_place && count( $losers ) >= 2 ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$third_exists = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$wpdb->prefix}ds_matches
					 WHERE tournament_id = %d AND phase = 'third_place'",
					$tournament_id
				)
			);
			if ( $third_exists === 0 ) {
				$dt_third = $this->next_match_datetime( $weekdays, $time, 1, 0, $duration );
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$wpdb->insert(
					"{$wpdb->prefix}ds_matches",
					[
						'tournament_id'  => $tournament_id,
						'round_number'   => 0,
						'home_team_id'   => $losers[0],
						'away_team_id'   => $losers[1],
						'venue_id'       => $venue_id,
						'court_id'       => 0,
						'match_datetime' => $dt_third,
						'status'         => 'scheduled',
						'phase'          => 'third_place',
					],
					[ '%d', '%d', '%d', '%d', '%d', '%d', '%s', '%s', '%s' ]
				);
				if ( $wpdb->insert_id ) {
					$all_ids[] = (int) $wpdb->insert_id;
				}
			}
		}

		if ( ! empty( $all_ids ) ) {
			$this->assign_courts( $all_ids, $venue_id );
		}

		return [ 'match_ids' => $all_ids ];
	}

	/**
	 * Genera la siguiente ronda eliminatoria de un torneo en Fase de Grupos.
	 *
	 * Se llama múltiples veces: cada llamada genera la siguiente ronda disponible.
	 * Detecta automáticamente el estado del torneo:
	 *   Estado 1 — Sin eliminatoria: genera Cuartos (8 clasificados) o Semis (2 o 4 clasificados).
	 *   Estado 2 — Cuartos terminados, sin Semis: genera Semis con los 4 ganadores de QF.
	 *   Estado 3 — Semis terminadas, sin Final: genera Final + 3.er Puesto (si has_third_place=1).
	 *
	 * @param  array{id:int,match_weekday:int,match_weekdays:string,match_time:string,match_duration:int,teams_advancing_per_group:int,has_third_place:int} $tournament
	 * @param  int     $venue_id
	 * @param  ?string $match_date  Fecha específica 'Y-m-d' (opcional). Null = próximo día hábil.
	 * @return array{match_ids: int[], phase: string, error?: string}
	 */
	public function generate_group_knockout( array $tournament, int $venue_id, ?string $match_date = null ): array {
		global $wpdb;

		$tournament_id       = (int) $tournament['id'];
		$has_third_place     = (bool) ( $tournament['has_third_place'] ?? 1 );
		$weekdays            = $this->weekdays_from_tournament( $tournament );
		$time                = (string) ( $tournament['match_time'] ?? '19:00:00' );
		$duration            = $this->duration_from_tournament( $tournament );

		$resolve_winner = static function ( array $m ): int {
			return (int) $m['home_score'] >= (int) $m['away_score']
				? (int) $m['home_team_id']
				: (int) $m['away_team_id'];
		};

		$dt = static function ( int $offset ) use ( $match_date, $weekdays, $time, $duration ): string {
			if ( $match_date !== null ) {
				[ $h, $min, $s ] = array_map( 'intval', explode( ':', $time . ':00' ) );
				$start  = $h * 60 + $min + $offset * $duration;
				$base   = ( new \DateTimeImmutable( $match_date ) )->setTime( intdiv( $start, 60 ) % 24, $start % 60, $s );
				return $base->format( 'Y-m-d H:i:s' );
			}
			$day_names = [ 0 => 'sunday', 1 => 'monday', 2 => 'tuesday', 3 => 'wednesday',
				4 => 'thursday', 5 => 'friday', 6 => 'saturday' ];
			$first_day = $weekdays[0] ?? 6;
			$base      = new \DateTimeImmutable( 'next ' . ( $day_names[ $first_day ] ?? 'saturday' ) );
			[ $h, $min, $s ] = array_map( 'intval', explode( ':', $time . ':00' ) );
			$start     = $h * 60 + $min + $offset * $duration;
			$base      = $base->setTime( intdiv( $start, 60 ) % 24, $start % 60, $s );
			return $base->format( 'Y-m-d H:i:s' );
		};

		// ── Detectar estado actual ────────────────────────────────────────────

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$qf_matches = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, home_team_id, away_team_id, home_score, away_score, status
				 FROM {$wpdb->prefix}ds_matches
				 WHERE tournament_id = %d AND phase = 'quarterfinal'
				 ORDER BY id ASC",
				$tournament_id
			),
			ARRAY_A
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$sf_matches = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, home_team_id, away_team_id, home_score, away_score, status
				 FROM {$wpdb->prefix}ds_matches
				 WHERE tournament_id = %d AND phase = 'semifinal'
				 ORDER BY id ASC",
				$tournament_id
			),
			ARRAY_A
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$has_final = (bool) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}ds_matches
				 WHERE tournament_id = %d AND phase IN ('final','third_place')",
				$tournament_id
			)
		);

		// ── Estado 3: Semis terminadas, sin Final ────────────────────────────
		if ( ! empty( $sf_matches ) && ! $has_final ) {
			$all_sf_done = count( array_filter( $sf_matches, static fn( $m ) => ! in_array( $m['status'], [ 'finished', 'suspended', 'postponed' ], true ) ) ) === 0;

			if ( ! $all_sf_done ) {
				return [ 'match_ids' => [], 'error' => 'Las semi-finales aún no han terminado.' ];
			}

			$sf1 = $sf_matches[0];
			$sf2 = $sf_matches[1] ?? null;
			if ( ! $sf2 ) {
				return [ 'match_ids' => [], 'error' => 'Se necesitan exactamente 2 semi-finales para generar la final.' ];
			}

			$resolve_loser = static function ( array $m ): int {
				return (int) $m['home_score'] >= (int) $m['away_score']
					? (int) $m['away_team_id']
					: (int) $m['home_team_id'];
			};

			$w1 = $resolve_winner( $sf1 );
			$w2 = $resolve_winner( $sf2 );
			$l1 = $resolve_loser( $sf1 );
			$l2 = $resolve_loser( $sf2 );

			$ids      = [];
			$inserts  = [];

			if ( $has_third_place ) {
				$inserts[] = [ 'home' => $l1, 'away' => $l2, 'dt' => $dt( 0 ), 'phase' => 'third_place' ];
			}
			$inserts[] = [ 'home' => $w1, 'away' => $w2, 'dt' => $dt( $has_third_place ? 1 : 0 ), 'phase' => 'final' ];

			foreach ( $inserts as $pair ) {
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
						'group_label'    => null,
					],
					[ '%d', '%d', '%d', '%d', '%d', '%d', '%s', '%s', '%s', '%s' ]
				);
				if ( $wpdb->insert_id ) {
					$ids[] = (int) $wpdb->insert_id;
				}
			}

			$this->assign_courts( $ids, $venue_id );
			return [ 'match_ids' => $ids, 'phase' => 'final' ];
		}

		// ── Estado 2: Cuartos terminados, sin Semis ──────────────────────────
		if ( ! empty( $qf_matches ) && empty( $sf_matches ) ) {
			$all_qf_done = count( array_filter( $qf_matches, static fn( $m ) => ! in_array( $m['status'], [ 'finished', 'suspended', 'postponed' ], true ) ) ) === 0;

			if ( ! $all_qf_done ) {
				return [ 'match_ids' => [], 'error' => 'Los cuartos de final aún no han terminado.' ];
			}

			$winners = array_map( $resolve_winner, $qf_matches );

			// Fisher-Yates sobre los 4 ganadores.
			for ( $i = count( $winners ) - 1; $i > 0; $i-- ) {
				$j = random_int( 0, $i );
				[ $winners[ $i ], $winners[ $j ] ] = [ $winners[ $j ], $winners[ $i ] ];
			}

			if ( count( $winners ) !== 4 ) {
				return [ 'match_ids' => [], 'error' => 'Se esperaban 4 ganadores de cuartos de final.' ];
			}

			$ids = [];
			foreach ( [ [ $winners[0], $winners[1] ], [ $winners[2], $winners[3] ] ] as $k => $pair ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$wpdb->insert(
					"{$wpdb->prefix}ds_matches",
					[
						'tournament_id'  => $tournament_id,
						'round_number'   => 0,
						'home_team_id'   => $pair[0],
						'away_team_id'   => $pair[1],
						'venue_id'       => $venue_id,
						'court_id'       => 0,
						'match_datetime' => $dt( $k ),
						'status'         => 'scheduled',
						'phase'          => 'semifinal',
						'group_label'    => null,
					],
					[ '%d', '%d', '%d', '%d', '%d', '%d', '%s', '%s', '%s', '%s' ]
				);
				if ( $wpdb->insert_id ) {
					$ids[] = (int) $wpdb->insert_id;
				}
			}

			$this->assign_courts( $ids, $venue_id );
			return [ 'match_ids' => $ids, 'phase' => 'semifinal' ];
		}

		// ── Estado 1: Sin eliminatoria — calcular clasificados ───────────────
		if ( ! empty( $qf_matches ) || ! empty( $sf_matches ) || $has_final ) {
			return [ 'match_ids' => [], 'error' => 'Estado del torneo no soportado para generar eliminatoria.' ];
		}

		// Verificar que todos los partidos regulares estén finalizados.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$pending_regular = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}ds_matches
				 WHERE tournament_id = %d AND phase = 'regular'
				   AND status NOT IN ('finished', 'suspended', 'postponed')",
				$tournament_id
			)
		);
		if ( $pending_regular > 0 ) {
			return [ 'match_ids' => [], 'error' => 'Aún hay partidos de fase de grupos sin finalizar.' ];
		}

		// Calcular clasificados: top N de cada grupo.
		$advancing_per_group = max( 1, min( 4, (int) ( $tournament['teams_advancing_per_group'] ?? 2 ) ) );
		$standings_by_group  = ( new StandingsCalculator() )->recalculate_by_group( $tournament_id );

		if ( empty( $standings_by_group ) ) {
			return [ 'match_ids' => [], 'error' => 'No se encontraron grupos para este torneo.' ];
		}

		$qualifiers = [];
		foreach ( $standings_by_group as $rows ) {
			foreach ( array_slice( $rows, 0, $advancing_per_group ) as $row ) {
				$qualifiers[] = (int) $row['team_id'];
			}
		}

		$n_qual = count( $qualifiers );
		if ( ! in_array( $n_qual, [ 2, 4, 8 ], true ) ) {
			return [
				'match_ids' => [],
				'error'     => sprintf(
					'El número de clasificados (%d) no soporta un bracket limpio. Deben ser 2, 4 u 8.',
					$n_qual
				),
			];
		}

		// Fisher-Yates sobre los clasificados.
		for ( $i = $n_qual - 1; $i > 0; $i-- ) {
			$j = random_int( 0, $i );
			[ $qualifiers[ $i ], $qualifiers[ $j ] ] = [ $qualifiers[ $j ], $qualifiers[ $i ] ];
		}

		$phase = match ( $n_qual ) {
			2, 4 => 'semifinal',
			8    => 'quarterfinal',
		};

		$ids = [];
		$pairs = array_chunk( $qualifiers, 2 );
		foreach ( $pairs as $k => $pair ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->insert(
				"{$wpdb->prefix}ds_matches",
				[
					'tournament_id'  => $tournament_id,
					'round_number'   => 0,
					'home_team_id'   => $pair[0],
					'away_team_id'   => $pair[1],
					'venue_id'       => $venue_id,
					'court_id'       => 0,
					'match_datetime' => $dt( $k ),
					'status'         => 'scheduled',
					'phase'          => $phase,
					'group_label'    => null,
				],
				[ '%d', '%d', '%d', '%d', '%d', '%d', '%s', '%s', '%s', '%s' ]
			);
			if ( $wpdb->insert_id ) {
				$ids[] = (int) $wpdb->insert_id;
			}
		}

		$this->assign_courts( $ids, $venue_id );
		return [ 'match_ids' => $ids, 'phase' => $phase ];
	}
}
