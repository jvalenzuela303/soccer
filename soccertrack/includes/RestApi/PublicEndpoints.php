<?php
/**
 * Endpoints REST públicos de SoccerTrack (sin autenticación).
 *
 * Base: /wp-json/soccertrack/v1/public/
 *
 * GET /public/tournament/{id}/standings  — Tabla de posiciones
 * GET /public/tournament/{id}/fixture    — Fixture con resultados
 * GET /public/tournament/{id}/teams      — Equipos del torneo
 * GET /public/tournament/{id}/tribunal   — Sanciones disciplinarias
 *
 * Estos endpoints alimentan las pestañas del portal público.
 * Objetivo: < 100ms de respuesta (queries indexadas).
 *
 * @package SoccerTrack
 */

declare(strict_types=1);

namespace SportsLeague\RestApi;

use SportsLeague\Core\StandingsCalculator;

final class PublicEndpoints {

	private const NAMESPACE = 'soccertrack/v1';

	public static function init(): void {
		add_action( 'rest_api_init', [ self::class, 'register_routes' ] );
	}

	public static function register_routes(): void {
		$tid_arg = [
			'validate_callback' => static fn( mixed $v ): bool => is_numeric( $v ) && (int) $v > 0,
			'sanitize_callback' => 'absint',
		];

		$routes = [
			'standings' => [ self::class, 'get_standings' ],
			'fixture'   => [ self::class, 'get_fixture' ],
			'teams'     => [ self::class, 'get_teams' ],
			'tribunal'  => [ self::class, 'get_tribunal' ],
			'scorers'   => [ self::class, 'get_scorers' ],
			'stats'     => [ self::class, 'get_stats' ],
			'brackets'  => [ self::class, 'get_public_brackets' ],
			'groups'    => [ self::class, 'get_groups' ],
		];

		foreach ( $routes as $suffix => $callback ) {
			register_rest_route(
				self::NAMESPACE,
				'/public/tournament/(?P<id>\d+)/' . $suffix,
				[
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => $callback,
					'permission_callback' => '__return_true',
					'args'                => [ 'id' => $tid_arg ],
				]
			);
		}
	}

	/** TTL en segundos para los transients públicos (60 s). */
	private const CACHE_TTL = 60;

	/**
	 * Clave de caché por torneo y endpoint.
	 */
	private static function cache_key( int $tournament_id, string $suffix ): string {
		return "st_pub_{$tournament_id}_{$suffix}";
	}

	/**
	 * Invalida todos los transients públicos de un torneo (llamar al cerrar un partido).
	 */
	public static function invalidate_cache( int $tournament_id ): void {
		foreach ( [ 'standings', 'fixture', 'scorers', 'tribunal', 'teams', 'stats', 'brackets', 'groups' ] as $s ) {
			delete_transient( self::cache_key( $tournament_id, $s ) );
		}
	}

	/**
	 * Tabla de posiciones calculada en tiempo real (con caché 60 s).
	 */
	public static function get_standings( \WP_REST_Request $request ): \WP_REST_Response {
		$tid = (int) $request['id'];
		$key = self::cache_key( $tid, 'standings' );

		$cached = get_transient( $key );
		if ( false !== $cached ) {
			return rest_ensure_response( $cached );
		}

		$data = ( new StandingsCalculator() )->recalculate( $tid );
		set_transient( $key, $data, self::CACHE_TTL );
		return rest_ensure_response( $data );
	}

	/**
	 * Fixture completo del torneo con nombre de equipos, recinto y cancha (caché 60 s).
	 *
	 * Cuando fixture_release_days > 0, sólo devuelve partidos de jornadas visibles:
	 *  - Jornada 1: siempre visible.
	 *  - Jornada N (N > 1): visible si max(match_datetime de jornada N-1) + fixture_release_days días <= hoy.
	 */
	public static function get_fixture( \WP_REST_Request $request ): \WP_REST_Response {
		global $wpdb;

		$tid = (int) $request['id'];
		$key = self::cache_key( $tid, 'fixture' );
		$cached = get_transient( $key );
		if ( false !== $cached ) return rest_ensure_response( $cached );

		// Cargar fixture_release_days del torneo.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$tournament = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT fixture_release_days FROM {$wpdb->prefix}ds_tournaments WHERE id = %d",
				$tid
			),
			ARRAY_A
		);

		$release_days = (int) ( $tournament['fixture_release_days'] ?? 0 );

		// Calcular jornadas visibles cuando el filtro está activo.
		$round_filter = '';
		if ( $release_days > 0 ) {
			// Pre-computar MAX(match_datetime) por jornada en un solo pass.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$round_max_dt = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT round_number, MAX(match_datetime) AS max_dt
					 FROM {$wpdb->prefix}ds_matches
					 WHERE tournament_id = %d AND phase = 'regular'
					 GROUP BY round_number",
					$tid
				),
				ARRAY_A
			);

			// Construir mapa round_number → max_dt.
			$max_by_round = [];
			foreach ( $round_max_dt as $row ) {
				$max_by_round[ (int) $row['round_number'] ] = $row['max_dt'];
			}

			// Jornada 1 siempre visible; jornada N visible si la anterior ya venció.
			$today          = gmdate( 'Y-m-d' );
			$visible_rounds = [];
			foreach ( array_keys( $max_by_round ) as $rn ) {
				if ( 1 === $rn ) {
					$visible_rounds[] = 1;
					continue;
				}
				$prev_max = $max_by_round[ $rn - 1 ] ?? null;
				if ( null !== $prev_max ) {
					$unlock_date = gmdate( 'Y-m-d', strtotime( "+{$release_days} days", strtotime( $prev_max ) ) );
					if ( $unlock_date <= $today ) {
						$visible_rounds[] = $rn;
					}
				}
			}
			$visible_rounds = array_map( 'intval', $visible_rounds );

			if ( empty( $visible_rounds ) ) {
				// Ninguna jornada visible aún — devolver array vacío.
				set_transient( $key, [], self::CACHE_TTL );
				return rest_ensure_response( [] );
			}

			$placeholders  = implode( ', ', array_fill( 0, count( $visible_rounds ), '%d' ) );
			// Los partidos de playoffs (phase != 'regular') siempre se muestran; el filtro de jornadas aplica solo a la fase regular.
			$round_filter  = $wpdb->prepare( " AND ( m.phase != 'regular' OR m.round_number IN ( {$placeholders} ) )", ...$visible_rounds ); // phpcs:ignore
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT
				    m.id,
				    m.round_number,
				    COALESCE(m.phase, 'regular') AS phase,
				    m.bracket_id,
				    b.name                      AS bracket_name,
				    m.group_label,
				    m.match_datetime,
				    m.home_score,
				    m.away_score,
				    m.status,
				    ht.name     AS home_team,
				    ht.logo_url AS home_logo,
				    ht.is_ghost AS home_is_ghost,
				    at.name     AS away_team,
				    at.logo_url AS away_logo,
				    at.is_ghost AS away_is_ghost,
				    v.name      AS venue,
				    c.court_name
				 FROM {$wpdb->prefix}ds_matches m USE INDEX (idx_fixture_order)
				 JOIN {$wpdb->prefix}ds_teams   ht ON ht.id = m.home_team_id
				 JOIN {$wpdb->prefix}ds_teams   at ON at.id = m.away_team_id
				 JOIN {$wpdb->prefix}ds_venues  v  ON v.id  = m.venue_id
				 LEFT JOIN {$wpdb->prefix}ds_courts          c ON c.id = m.court_id
				 LEFT JOIN {$wpdb->prefix}ds_playoff_brackets b ON b.id = m.bracket_id
				 WHERE m.tournament_id = %d{$round_filter}
				 ORDER BY m.round_number ASC, m.match_datetime ASC",
				$tid
			),
			ARRAY_A
		);

		$result = $rows ?: [];
		set_transient( $key, $result, self::CACHE_TTL );
		return rest_ensure_response( $result );
	}

	/**
	 * Lista de equipos del torneo (caché 60 s).
	 */
	public static function get_teams( \WP_REST_Request $request ): \WP_REST_Response {
		global $wpdb;

		$tid = (int) $request['id'];
		$key = self::cache_key( $tid, 'teams' );
		$cached = get_transient( $key );
		if ( false !== $cached ) return rest_ensure_response( $cached );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, name, logo_url
				 FROM {$wpdb->prefix}ds_teams
				 WHERE tournament_id = %d
				 ORDER BY name ASC",
				$tid
			),
			ARRAY_A
		);

		$result = $rows ?: [];
		set_transient( $key, $result, self::CACHE_TTL );
		return rest_ensure_response( $result );
	}

	/**
	 * Goleadores, tarjetas amarillas y rojas del torneo (caché 60 s).
	 */
	public static function get_scorers( \WP_REST_Request $request ): \WP_REST_Response {
		global $wpdb;

		$tid = (int) $request['id'];
		$key = self::cache_key( $tid, 'scorers' );
		$cached = get_transient( $key );
		if ( false !== $cached ) return rest_ensure_response( $cached );

		// Arranca desde ds_match_events USE INDEX(idx_scorers) para evitar full-scan en ds_players.
		// GROUP BY sobre columnas ya indexadas → sin filesort en el agregado.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT
				    e.player_id,
				    p.first_name,
				    p.last_name,
				    t.name AS team_name,
				    SUM( e.event_type IN ('goal','own_goal') ) AS goals,
				    SUM( e.event_type = 'yellow_card' )        AS yellows,
				    SUM( e.event_type = 'red_card' )           AS reds
				 FROM {$wpdb->prefix}ds_match_events e USE INDEX (idx_scorers)
				 JOIN {$wpdb->prefix}ds_players p ON p.id = e.player_id
				 JOIN {$wpdb->prefix}ds_teams   t ON t.id = e.team_id
				 WHERE e.tournament_id = %d
				 GROUP BY e.player_id, e.team_id
				 HAVING goals > 0 OR yellows > 0 OR reds > 0
				 ORDER BY goals DESC, yellows DESC, p.last_name ASC",
				$tid
			),
			ARRAY_A
		);

		$result = $rows ?: [];
		set_transient( $key, $result, self::CACHE_TTL );
		return rest_ensure_response( $result );
	}

	/**
	 * Records del torneo y podio de goleadores (caché 60 s).
	 *
	 * Deriva records de standings ya calculados. Sin queries adicionales salvo goleadores.
	 */
	public static function get_stats( \WP_REST_Request $request ): \WP_REST_Response {
		global $wpdb;

		$tid = (int) $request['id'];
		$key = self::cache_key( $tid, 'stats' );

		$cached = get_transient( $key );
		if ( false !== $cached ) {
			return rest_ensure_response( $cached );
		}

		// Reutilizar standings (o recalcular si no hay caché aún).
		$standings_key = self::cache_key( $tid, 'standings' );
		$standings     = get_transient( $standings_key );
		if ( false === $standings ) {
			$standings = ( new \SportsLeague\Core\StandingsCalculator() )->recalculate( $tid );
			set_transient( $standings_key, $standings, self::CACHE_TTL );
		}

		// Derivar records desde standings.
		$records = [
			'best_attack'       => null,
			'best_defense'      => null,
			'most_clean_sheets' => null,
			'longest_streak'    => null,
		];

		$played = array_filter( $standings, static fn( array $r ): bool => $r['pj'] > 0 );

		if ( ! empty( $played ) ) {
			$best_attack = array_reduce(
				$played,
				static fn( ?array $carry, array $r ): array =>
					( null === $carry || $r['gf'] > $carry['gf'] ) ? $r : $carry
			);
			$records['best_attack'] = [ 'team' => $best_attack['name'], 'gf' => (int) $best_attack['gf'] ];

			$best_defense = array_reduce(
				$played,
				static fn( ?array $carry, array $r ): array =>
					( null === $carry || $r['gc'] < $carry['gc'] ) ? $r : $carry
			);
			$records['best_defense'] = [ 'team' => $best_defense['name'], 'gc' => (int) $best_defense['gc'] ];

			$most_cs = array_reduce(
				$played,
				static fn( ?array $carry, array $r ): array =>
					( null === $carry || $r['clean_sheets'] > $carry['clean_sheets'] ) ? $r : $carry
			);
			$records['most_clean_sheets'] = [ 'team' => $most_cs['name'], 'count' => (int) $most_cs['clean_sheets'] ];

			$longest = array_reduce(
				$played,
				static fn( ?array $carry, array $r ): array =>
					( null === $carry || $r['win_streak'] > $carry['win_streak'] ) ? $r : $carry
			);
			$records['longest_streak'] = [ 'team' => $longest['name'], 'wins' => (int) $longest['win_streak'] ];
		}

		// Mapa team_name => pj para calcular goles/partido.
		$team_pj = [];
		foreach ( $standings as $row ) {
			$team_pj[ $row['name'] ] = (int) $row['pj'];
		}

		// Top 10 goleadores (al menos 1 gol).
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$scorer_rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT
				    e.player_id,
				    p.first_name,
				    p.last_name,
				    t.name AS team_name,
				    SUM( e.event_type IN ('goal','own_goal') ) AS goals
				 FROM {$wpdb->prefix}ds_match_events e USE INDEX (idx_scorers)
				 JOIN {$wpdb->prefix}ds_players p ON p.id = e.player_id
				 JOIN {$wpdb->prefix}ds_teams   t ON t.id = e.team_id
				 WHERE e.tournament_id = %d
				 GROUP BY e.player_id, e.team_id
				 HAVING goals > 0
				 ORDER BY goals DESC, p.last_name ASC
				 LIMIT 10",
				$tid
			),
			ARRAY_A
		);

		$top_scorers = [];
		foreach ( $scorer_rows ?? [] as $idx => $sr ) {
			$goals         = (int) $sr['goals'];
			$pj            = $team_pj[ $sr['team_name'] ] ?? 0;
			$top_scorers[] = [
				'rank'            => $idx + 1,
				'first_name'      => sanitize_text_field( $sr['first_name'] ),
				'last_name'       => sanitize_text_field( $sr['last_name'] ),
				'team_name'       => sanitize_text_field( $sr['team_name'] ),
				'goals'           => $goals,
				'goals_per_match' => $pj > 0 ? round( $goals / $pj, 1 ) : 0.0,
			];
		}

		$result = compact( 'records', 'top_scorers' );
		set_transient( $key, $result, self::CACHE_TTL );
		return rest_ensure_response( $result );
	}

	/**
	 * Tribunal disciplinario del torneo (caché 60 s).
	 *
	 * Devuelve un objeto con dos secciones:
	 *  - pending_review: tarjetas ROJAS de la última fecha jugada,
	 *    para que el tribunal decida la sanción. (Las amarillas no se muestran.)
	 *  - sanctions: sanciones ya resueltas (activas y cumplidas).
	 */
	public static function get_tribunal( \WP_REST_Request $request ): \WP_REST_Response {
		global $wpdb;

		$tid = (int) $request['id'];
		$key = self::cache_key( $tid, 'tribunal' );
		$cached = get_transient( $key );
		if ( false !== $cached ) return rest_ensure_response( $cached );

		// ── Última fecha con partidos finalizados ─────────────────────────
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$last_round = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT MAX(round_number)
				 FROM {$wpdb->prefix}ds_matches
				 WHERE tournament_id = %d AND status = 'finished'",
				$tid
			)
		);

		// ── Casos pendientes: tarjetas de la última fecha ─────────────────
		$pending = [];
		if ( $last_round > 0 ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$pending = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT
					    e.player_id,
					    p.first_name,
					    p.last_name,
					    t.name        AS team_name,
					    e.event_type,
					    m.round_number,
					    m.match_datetime
					 FROM {$wpdb->prefix}ds_match_events e
					 JOIN {$wpdb->prefix}ds_players p ON p.id = e.player_id
					 JOIN {$wpdb->prefix}ds_teams   t ON t.id = e.team_id
					 JOIN {$wpdb->prefix}ds_matches  m ON m.id = e.match_id
					 WHERE e.tournament_id = %d
					   AND m.round_number  = %d
					   AND m.status        = 'finished'
					   AND e.event_type = 'red_card'
					 ORDER BY p.last_name ASC",
					$tid,
					$last_round
				),
				ARRAY_A
			) ?: [];
		}

		// ── Sanciones resueltas (activas y cumplidas) ─────────────────────
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$sanctions = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT
				    ds.id,
				    p.first_name,
				    p.last_name,
				    t.name            AS team_name,
				    ds.reason,
				    ds.ban_days_or_matches,
				    ds.remaining_matches,
				    ds.status
				 FROM {$wpdb->prefix}ds_disciplinary_sanctions ds USE INDEX (idx_tournament_status)
				 JOIN {$wpdb->prefix}ds_players p  ON p.id  = ds.player_id
				 LEFT JOIN {$wpdb->prefix}ds_teams t ON t.id = ds.team_id
				 WHERE ds.tournament_id = %d
				 ORDER BY ds.status ASC, ds.id DESC",
				$tid
			),
			ARRAY_A
		) ?: [];

		$result = [
			'last_round'     => $last_round,
			'pending_review' => $pending,
			'sanctions'      => $sanctions,
		];

		set_transient( $key, $result, self::CACHE_TTL );
		return rest_ensure_response( $result );
	}

	/**
	 * GET /public/tournament/{id}/brackets
	 *
	 * Retorna la estructura de brackets del torneo con equipos clasificados
	 * (si la fase regular terminó) y los partidos de playoff ya generados.
	 * Sin autenticación.
	 */
	public static function get_public_brackets( \WP_REST_Request $request ): \WP_REST_Response {
		global $wpdb;

		$tid = (int) $request['id'];
		$key = self::cache_key( $tid, 'brackets' );

		$cached = get_transient( $key );
		if ( false !== $cached ) {
			return rest_ensure_response( $cached );
		}

		// Cargar brackets del torneo.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$brackets = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, name, rank_from, rank_to, sort_order
				 FROM {$wpdb->prefix}ds_playoff_brackets
				 WHERE tournament_id = %d
				 ORDER BY sort_order ASC, rank_from ASC",
				$tid
			),
			ARRAY_A
		) ?: [];

		if ( empty( $brackets ) ) {
			set_transient( $key, [], self::CACHE_TTL );
			return rest_ensure_response( [] );
		}

		// Determinar si la fase regular está completa.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$regular_pending = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}ds_matches
				 WHERE tournament_id = %d AND phase = 'regular' AND status NOT IN ('finished', 'suspended', 'postponed')",
				$tid
			)
		);

		$regular_complete = 0 === $regular_pending;

		// Standings para asignar equipos a brackets (solo si fase regular completa).
		$standings = $regular_complete
			? ( new \SportsLeague\Core\StandingsCalculator() )->recalculate( $tid )
			: [];

		// Cargar partidos de playoff agrupados por bracket.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$playoff_matches = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT m.id, m.bracket_id, m.phase, m.status,
				        m.home_score, m.away_score, m.match_datetime,
				        ht.name AS home_team, at.name AS away_team
				 FROM {$wpdb->prefix}ds_matches m
				 JOIN {$wpdb->prefix}ds_teams ht ON ht.id = m.home_team_id
				 JOIN {$wpdb->prefix}ds_teams at ON at.id = m.away_team_id
				 WHERE m.tournament_id = %d AND m.bracket_id IS NOT NULL
				 ORDER BY m.bracket_id ASC, m.match_datetime ASC",
				$tid
			),
			ARRAY_A
		) ?: [];

		// Indexar partidos por bracket_id → phase.
		$matches_by_bracket = [];
		foreach ( $playoff_matches as $m ) {
			$bid   = (int) $m['bracket_id'];
			$phase = $m['phase'];
			if ( ! isset( $matches_by_bracket[ $bid ] ) ) {
				$matches_by_bracket[ $bid ] = [];
			}
			$matches_by_bracket[ $bid ][ $phase ][] = $m;
		}

		// Construir respuesta.
		$result = [];
		foreach ( $brackets as $bracket ) {
			$bid       = (int) $bracket['id'];
			$rank_from = (int) $bracket['rank_from'];
			$rank_to   = (int) $bracket['rank_to'];

			// Equipos del rango (solo si fase regular completa).
			$teams = [];
			if ( $regular_complete ) {
				foreach ( $standings as $rank_idx => $row ) {
					$rank = $rank_idx + 1;
					if ( $rank >= $rank_from && $rank <= $rank_to ) {
						$teams[] = [
							'rank'      => $rank,
							'team_id'   => (int) $row['team_id'],
							'team_name' => $row['name'],
							'pts'       => (int) $row['pts'],
						];
					}
				}
			}

			$result[] = [
				'id'         => $bid,
				'name'       => $bracket['name'],
				'rank_from'  => $rank_from,
				'rank_to'    => $rank_to,
				'sort_order' => (int) $bracket['sort_order'],
				'teams'      => $teams,
				'matches'    => $matches_by_bracket[ $bid ] ?? [],
			];
		}

		set_transient( $key, $result, self::CACHE_TTL );
		return rest_ensure_response( $result );
	}

	/**
	 * GET /public/tournament/{id}/groups
	 *
	 * Retorna standings y partidos por grupo para torneos en Fase de Grupos.
	 * Sin autenticación. Caché 60 s.
	 */
	public static function get_groups( \WP_REST_Request $request ): \WP_REST_Response {
		global $wpdb;

		$tid = (int) $request['id'];
		$key = self::cache_key( $tid, 'groups' );

		$cached = get_transient( $key );
		if ( false !== $cached ) {
			return rest_ensure_response( $cached );
		}

		// Standings por grupo.
		$standings_by_group = ( new \SportsLeague\Core\StandingsCalculator() )->recalculate_by_group( $tid );

		if ( empty( $standings_by_group ) ) {
			set_transient( $key, [], self::CACHE_TTL );
			return rest_ensure_response( [] );
		}

		// Partidos de fase regular con group_label.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$matches_raw = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT m.id, m.round_number, m.group_label, m.match_datetime,
				        m.home_score, m.away_score, m.status,
				        ht.name AS home_team, at.name AS away_team,
				        ht.logo_url AS home_logo, at.logo_url AS away_logo,
				        v.name AS venue, c.court_name
				 FROM {$wpdb->prefix}ds_matches m
				 JOIN {$wpdb->prefix}ds_teams ht ON ht.id = m.home_team_id
				 JOIN {$wpdb->prefix}ds_teams at ON at.id = m.away_team_id
				 LEFT JOIN {$wpdb->prefix}ds_venues v ON v.id = m.venue_id
				 LEFT JOIN {$wpdb->prefix}ds_courts c ON c.id = m.court_id
				 WHERE m.tournament_id = %d AND m.phase = 'regular' AND m.group_label IS NOT NULL
				 ORDER BY m.group_label ASC, m.round_number ASC, m.match_datetime ASC",
				$tid
			),
			ARRAY_A
		);

		// Agrupar partidos por group_label.
		$matches_by_group = [];
		foreach ( $matches_raw as $m ) {
			$lbl = (string) $m['group_label'];
			if ( ! isset( $matches_by_group[ $lbl ] ) ) {
				$matches_by_group[ $lbl ] = [];
			}
			$matches_by_group[ $lbl ][] = [
				'id'             => (int) $m['id'],
				'round_number'   => (int) $m['round_number'],
				'match_datetime' => $m['match_datetime'],
				'home_team'      => $m['home_team'],
				'away_team'      => $m['away_team'],
				'home_logo'      => $m['home_logo'],
				'away_logo'      => $m['away_logo'],
				'home_score'     => (int) $m['home_score'],
				'away_score'     => (int) $m['away_score'],
				'status'         => $m['status'],
				'venue'          => $m['venue'],
				'court_name'     => $m['court_name'],
			];
		}

		// Construir respuesta final.
		$data = [];
		foreach ( $standings_by_group as $label => $rows ) {
			$data[] = [
				'label'     => $label,
				'standings' => $rows,
				'matches'   => $matches_by_group[ $label ] ?? [],
			];
		}

		set_transient( $key, $data, self::CACHE_TTL );
		return rest_ensure_response( $data );
	}
}
