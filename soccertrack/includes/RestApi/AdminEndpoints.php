<?php
/**
 * Endpoints REST administrativos de SoccerTrack (requieren autenticación).
 *
 * Base: /wp-json/soccertrack/v1/admin/
 *
 * POST   /admin/match/{id}/result              — Cerrar partido y registrar resultado (árbitro)
 * POST   /admin/match/{id}/event               — Registrar gol/tarjeta con detalle (planillero+árbitro)
 * PATCH  /admin/match/{id}/event/{event_id}    — Editar incidente (árbitro)
 * DELETE /admin/match/{id}/event/{event_id}    — Eliminar incidente (propio o árbitro)
 * GET    /admin/match/{id}/events              — Listar incidentes con auditoría
 * POST   /admin/match/{id}/planillero          — Asignar planillero (coordinador)
 * POST /admin/tournament/{id}/fixture          — Generar fixture (coordinador)
 * POST /admin/tournament/{id}/playoffs         — Generar semi-finales (coordinador)
 * POST /admin/tournament/{id}/finals           — Generar final y 3.er puesto (coordinador)
 * POST /admin/player/sanction                  — Sancionar jugador (coordinador)
 * POST /admin/import/players                   — Importar jugadores CSV/XLSX (coordinador)
 *
 * @package SoccerTrack
 */

declare(strict_types=1);

namespace SportsLeague\RestApi;

use SportsLeague\Core\AntiCollisionEngine;
use SportsLeague\Core\FixtureGenerator;
use SportsLeague\Core\StandingsCalculator;
use SportsLeague\Discipline\TribunalManager;
use SportsLeague\Importers\SpreadsheetImporter;
use SportsLeague\RestApi\PublicEndpoints;

final class AdminEndpoints {

	private const NAMESPACE = 'soccertrack/v1';

	public static function init(): void {
		add_action( 'rest_api_init', [ self::class, 'register_routes' ] );
	}

	public static function register_routes(): void {
		// POST /admin/match/{id}/result
		register_rest_route(
			self::NAMESPACE,
			'/admin/match/(?P<id>\d+)/result',
			[
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => [ self::class, 'post_match_result' ],
				'permission_callback' => static fn() => current_user_can( 'ds_close_match' ),
				'args'                => [
					'id'         => [
						'validate_callback' => static fn( mixed $v ): bool => is_numeric( $v ) && (int) $v > 0,
						'sanitize_callback' => 'absint',
					],
					'home_score' => [
						'required'          => true,
						'validate_callback' => static fn( mixed $v ): bool => is_numeric( $v ) && (int) $v >= 0,
						'sanitize_callback' => 'absint',
					],
					'away_score' => [
						'required'          => true,
						'validate_callback' => static fn( mixed $v ): bool => is_numeric( $v ) && (int) $v >= 0,
						'sanitize_callback' => 'absint',
					],
				],
			]
		);

		// POST /admin/tournament/{id}/fixture
		register_rest_route(
			self::NAMESPACE,
			'/admin/tournament/(?P<id>\d+)/fixture',
			[
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => [ self::class, 'post_generate_fixture' ],
				'permission_callback' => static fn() => current_user_can( 'ds_generate_fixture' ),
				'args'                => [
					'id'       => [
						'validate_callback' => static fn( mixed $v ): bool => is_numeric( $v ) && (int) $v > 0,
						'sanitize_callback' => 'absint',
					],
					'venue_id' => [
						'required'          => true,
						'validate_callback' => static fn( mixed $v ): bool => is_numeric( $v ) && (int) $v > 0,
						'sanitize_callback' => 'absint',
					],
				],
			]
		);

		// POST /admin/player/sanction
		register_rest_route(
			self::NAMESPACE,
			'/admin/player/sanction',
			[
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => [ self::class, 'post_sanction' ],
				'permission_callback' => static fn() => current_user_can( 'ds_manage_discipline' ),
				'args'                => [
					'player_id'     => [
						'required'          => true,
						'validate_callback' => static fn( mixed $v ): bool => is_numeric( $v ) && (int) $v > 0,
						'sanitize_callback' => 'absint',
					],
					'tournament_id' => [
						'required'          => true,
						'validate_callback' => static fn( mixed $v ): bool => is_numeric( $v ) && (int) $v > 0,
						'sanitize_callback' => 'absint',
					],
					'match_id'      => [
						'required'          => true,
						'validate_callback' => static fn( mixed $v ): bool => is_numeric( $v ) && (int) $v > 0,
						'sanitize_callback' => 'absint',
					],
					'reason'        => [
						'required'          => true,
						'validate_callback' => static fn( mixed $v ): bool => is_string( $v ) && strlen( trim( $v ) ) > 0,
						'sanitize_callback' => 'sanitize_text_field',
					],
					'ban_matches'   => [
						'required'          => true,
						'validate_callback' => static fn( mixed $v ): bool => is_numeric( $v ) && (int) $v >= 1,
						'sanitize_callback' => 'absint',
					],
				],
			]
		);

		// POST /admin/match/{id}/event  — registrar evento (gol, tarjeta) con detalle
		register_rest_route(
			self::NAMESPACE,
			'/admin/match/(?P<id>\d+)/event',
			[
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => [ self::class, 'post_match_event' ],
				'permission_callback' => static fn() => current_user_can( 'ds_enter_match_incidents' ),
				'args'                => [
					'id'          => [
						'validate_callback' => static fn( mixed $v ): bool => is_numeric( $v ) && (int) $v > 0,
						'sanitize_callback' => 'absint',
					],
					'player_id'   => [
						'required'          => true,
						'validate_callback' => static fn( mixed $v ): bool => is_numeric( $v ) && (int) $v > 0,
						'sanitize_callback' => 'absint',
					],
					'team_id'     => [
						'required'          => true,
						'validate_callback' => static fn( mixed $v ): bool => is_numeric( $v ) && (int) $v > 0,
						'sanitize_callback' => 'absint',
					],
					'event_type'  => [
						'required'          => true,
						'validate_callback' => static fn( mixed $v ): bool => in_array( $v, [ 'goal', 'own_goal', 'yellow_card', 'red_card' ], true ),
						'sanitize_callback' => 'sanitize_key',
					],
					'minute'      => [
						'required'          => true,
						'validate_callback' => static fn( mixed $v ): bool => is_numeric( $v ) && (int) $v >= 1 && (int) $v <= 120,
						'sanitize_callback' => 'absint',
					],
					'description' => [
						'required'          => false,
						'validate_callback' => static fn( mixed $v ): bool => is_string( $v ),
						'sanitize_callback' => 'sanitize_textarea_field',
					],
				],
			]
		);

		// PATCH /admin/match/{id}/event/{event_id}  — editar un evento (árbitro/coordinador)
		register_rest_route(
			self::NAMESPACE,
			'/admin/match/(?P<id>\d+)/event/(?P<event_id>\d+)',
			[
				[
					'methods'             => 'PATCH',
					'callback'            => [ self::class, 'patch_match_event' ],
					'permission_callback' => static fn() => current_user_can( 'ds_edit_incidents' ),
					'args'                => [
						'id'          => [
							'validate_callback' => static fn( mixed $v ): bool => is_numeric( $v ) && (int) $v > 0,
							'sanitize_callback' => 'absint',
						],
						'event_id'    => [
							'validate_callback' => static fn( mixed $v ): bool => is_numeric( $v ) && (int) $v > 0,
							'sanitize_callback' => 'absint',
						],
						'description' => [
							'required'          => false,
							'validate_callback' => static fn( mixed $v ): bool => is_string( $v ),
							'sanitize_callback' => 'sanitize_textarea_field',
						],
						'minute'      => [
							'required'          => false,
							'validate_callback' => static fn( mixed $v ): bool => is_numeric( $v ) && (int) $v >= 1 && (int) $v <= 120,
							'sanitize_callback' => 'absint',
						],
					],
				],
				[
					'methods'             => \WP_REST_Server::DELETABLE,
					'callback'            => [ self::class, 'delete_match_event' ],
					'permission_callback' => static fn() => current_user_can( 'ds_enter_match_incidents' ),
					'args'                => [
						'id'       => [
							'validate_callback' => static fn( mixed $v ): bool => is_numeric( $v ) && (int) $v > 0,
							'sanitize_callback' => 'absint',
						],
						'event_id' => [
							'validate_callback' => static fn( mixed $v ): bool => is_numeric( $v ) && (int) $v > 0,
							'sanitize_callback' => 'absint',
						],
					],
				],
			]
		);

		// GET /admin/match/{id}/events  — cargar eventos del partido con auditoría
		register_rest_route(
			self::NAMESPACE,
			'/admin/match/(?P<id>\d+)/events',
			[
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => [ self::class, 'get_match_events' ],
				'permission_callback' => static fn() => current_user_can( 'ds_view_match_sheet' ) || current_user_can( 'ds_enter_match_incidents' ),
				'args'                => [
					'id' => [
						'validate_callback' => static fn( mixed $v ): bool => is_numeric( $v ) && (int) $v > 0,
						'sanitize_callback' => 'absint',
					],
				],
			]
		);

		// POST /admin/match/{id}/planillero  — asignar planillero con control de conflicto
		register_rest_route(
			self::NAMESPACE,
			'/admin/match/(?P<id>\d+)/planillero',
			[
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => [ self::class, 'post_match_planillero' ],
				'permission_callback' => static fn() => current_user_can( 'ds_manage_tournaments' ),
				'args'                => [
					'id'                 => [
						'validate_callback' => static fn( mixed $v ): bool => is_numeric( $v ) && (int) $v > 0,
						'sanitize_callback' => 'absint',
					],
					'planillero_user_id' => [
						'required'          => true,
						'validate_callback' => static fn( mixed $v ): bool => is_numeric( $v ) && (int) $v >= 0,
						'sanitize_callback' => 'absint',
					],
				],
			]
		);

		// POST /admin/import/roster — planilla oficial (equipo + jugadores en un solo archivo)
		register_rest_route(
			self::NAMESPACE,
			'/admin/import/roster',
			[
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => [ self::class, 'post_import_roster' ],
				'permission_callback' => static fn() => current_user_can( 'ds_load_excel' ),
			]
		);

		// POST /admin/import/players
		register_rest_route(
			self::NAMESPACE,
			'/admin/import/players',
			[
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => [ self::class, 'post_import_players' ],
				'permission_callback' => static fn() => current_user_can( 'ds_load_excel' ),
			]
		);

		// POST /admin/import/referees
		register_rest_route(
			self::NAMESPACE,
			'/admin/import/referees',
			[
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => [ self::class, 'post_import_referees' ],
				'permission_callback' => static fn() => current_user_can( 'ds_load_excel' ),
			]
		);

		// POST /admin/import/team-logo
		register_rest_route(
			self::NAMESPACE,
			'/admin/import/team-logo',
			[
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => [ self::class, 'post_import_team_logo' ],
				'permission_callback' => static fn() => current_user_can( 'ds_load_excel' ),
			]
		);

		$tid_arg = [
			'validate_callback' => static fn( mixed $v ): bool => is_numeric( $v ) && (int) $v > 0,
			'sanitize_callback' => 'absint',
		];
		$venue_arg = [
			'required'          => true,
			'validate_callback' => static fn( mixed $v ): bool => is_numeric( $v ) && (int) $v > 0,
			'sanitize_callback' => 'absint',
		];

		// POST /admin/tournament/{id}/playoffs  — genera semi-finales
		register_rest_route(
			self::NAMESPACE,
			'/admin/tournament/(?P<id>\d+)/playoffs',
			[
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => [ self::class, 'post_generate_playoffs' ],
				'permission_callback' => static fn() => current_user_can( 'ds_generate_fixture' ),
				'args'                => [
					'id'         => $tid_arg,
					'venue_id'   => $venue_arg,
					'bracket_id' => [
						'required'          => false,
						'validate_callback' => static fn( mixed $v ): bool => is_numeric( $v ) && (int) $v > 0,
						'sanitize_callback' => 'absint',
					],
				],
			]
		);

		// POST /admin/tournament/{id}/finals  — genera final y 3.er puesto
		register_rest_route(
			self::NAMESPACE,
			'/admin/tournament/(?P<id>\d+)/finals',
			[
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => [ self::class, 'post_generate_finals' ],
				'permission_callback' => static fn() => current_user_can( 'ds_generate_fixture' ),
				'args'                => [
					'id'         => $tid_arg,
					'venue_id'   => $venue_arg,
					'bracket_id' => [
						'required'          => false,
						'validate_callback' => static fn( mixed $v ): bool => is_numeric( $v ) && (int) $v > 0,
						'sanitize_callback' => 'absint',
					],
				],
			]
		);

		self::register_bracket_routes();
	}

	private static function register_bracket_routes(): void {
		$tid_arg = [
			'validate_callback' => static fn( mixed $v ): bool => is_numeric( $v ) && (int) $v > 0,
			'sanitize_callback' => 'absint',
		];
		$bid_arg = [
			'validate_callback' => static fn( mixed $v ): bool => is_numeric( $v ) && (int) $v > 0,
			'sanitize_callback' => 'absint',
		];

		// POST + GET /admin/tournament/{id}/brackets
		register_rest_route(
			self::NAMESPACE,
			'/admin/tournament/(?P<id>\d+)/brackets',
			[
				[
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => [ self::class, 'post_bracket' ],
					'permission_callback' => static fn() => current_user_can( 'ds_generate_fixture' ),
					'args'                => [
						'id'         => $tid_arg,
						'name'       => [
							'required'          => true,
							'validate_callback' => static fn( mixed $v ): bool => is_string( $v ) && strlen( trim( $v ) ) > 0,
							'sanitize_callback' => 'sanitize_text_field',
						],
						'rank_from'  => [
							'required'          => true,
							'validate_callback' => static fn( mixed $v ): bool => is_numeric( $v ) && (int) $v >= 1,
							'sanitize_callback' => 'absint',
						],
						'rank_to'    => [
							'required'          => true,
							'validate_callback' => static fn( mixed $v ): bool => is_numeric( $v ) && (int) $v >= 1,
							'sanitize_callback' => 'absint',
						],
						'sort_order' => [
							'required'          => false,
							'default'           => 0,
							'validate_callback' => static fn( mixed $v ): bool => is_numeric( $v ) && (int) $v >= 0,
							'sanitize_callback' => 'absint',
						],
					],
				],
				[
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => [ self::class, 'get_brackets' ],
					'permission_callback' => static fn() => current_user_can( 'ds_generate_fixture' ),
					'args'                => [ 'id' => $tid_arg ],
				],
			]
		);

		// PATCH + DELETE /admin/tournament/{id}/brackets/{bid}
		register_rest_route(
			self::NAMESPACE,
			'/admin/tournament/(?P<id>\d+)/brackets/(?P<bid>\d+)',
			[
				[
					'methods'             => 'PATCH',
					'callback'            => [ self::class, 'patch_bracket' ],
					'permission_callback' => static fn() => current_user_can( 'ds_generate_fixture' ),
					'args'                => [
						'id'         => $tid_arg,
						'bid'        => $bid_arg,
						'name'       => [
							'required'          => false,
							'validate_callback' => static fn( mixed $v ): bool => is_string( $v ) && strlen( trim( $v ) ) > 0,
							'sanitize_callback' => 'sanitize_text_field',
						],
						'rank_from'  => [
							'required'          => false,
							'validate_callback' => static fn( mixed $v ): bool => is_numeric( $v ) && (int) $v >= 1,
							'sanitize_callback' => 'absint',
						],
						'rank_to'    => [
							'required'          => false,
							'validate_callback' => static fn( mixed $v ): bool => is_numeric( $v ) && (int) $v >= 1,
							'sanitize_callback' => 'absint',
						],
						'sort_order' => [
							'required'          => false,
							'validate_callback' => static fn( mixed $v ): bool => is_numeric( $v ) && (int) $v >= 0,
							'sanitize_callback' => 'absint',
						],
					],
				],
				[
					'methods'             => \WP_REST_Server::DELETABLE,
					'callback'            => [ self::class, 'delete_bracket' ],
					'permission_callback' => static fn() => current_user_can( 'ds_generate_fixture' ),
					'args'                => [
						'id'  => $tid_arg,
						'bid' => $bid_arg,
					],
				],
			]
		);
	}

	/**
	 * POST /admin/match/{id}/result
	 *
	 * Registra el resultado de un partido, decrementa suspensiones activas
	 * y devuelve la tabla de posiciones actualizada.
	 */
	public static function post_match_result( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		global $wpdb;

		$match_id   = (int) $request['id'];
		$home_score = (int) $request['home_score'];
		$away_score = (int) $request['away_score'];

		// Verificar que el partido existe y obtener el torneo + equipos.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$match = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT id, tournament_id, home_team_id, away_team_id, status, referee_user_id, round_number
				 FROM {$wpdb->prefix}ds_matches WHERE id = %d",
				$match_id
			),
			ARRAY_A
		);

		if ( ! $match ) {
			return new \WP_Error( 'match_not_found', __( 'Partido no encontrado.', 'soccertrack' ), [ 'status' => 404 ] );
		}

		$was_finished = 'finished' === $match['status'];
		$can_reopen   = current_user_can( 'manage_options' ) || current_user_can( 'ds_manage_tournaments' );

		if ( $was_finished && ! $can_reopen ) {
			return new \WP_Error( 'match_already_finished', __( 'El partido ya tiene resultado registrado.', 'soccertrack' ), [ 'status' => 409 ] );
		}

		$tournament_id = (int) $match['tournament_id'];
		$home_team_id  = (int) $match['home_team_id'];
		$away_team_id  = (int) $match['away_team_id'];

		// Actualizar resultado.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$updated = $wpdb->update(
			"{$wpdb->prefix}ds_matches",
			[
				'home_score' => $home_score,
				'away_score' => $away_score,
				'status'     => 'finished',
			],
			[ 'id' => $match_id ],
			[ '%d', '%d', '%s' ],
			[ '%d' ]
		);

		if ( false === $updated ) {
			return new \WP_Error( 'db_error', __( 'Error al guardar el resultado.', 'soccertrack' ), [ 'status' => 500 ] );
		}

		// Decrementar sanciones solo si el partido NO estaba ya cerrado (evitar doble decremento).
		if ( ! $was_finished ) {
			( new TribunalManager() )->decrement_after_match( $tournament_id, $home_team_id, $away_team_id );

			// Notificar resultado a los delegados de ambos equipos.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$teams_data = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT t.id, t.name, t.delegado_correo
					 FROM {$wpdb->prefix}ds_teams t
					 WHERE t.id IN (%d, %d)",
					$home_team_id,
					$away_team_id
				),
				ARRAY_A
			) ?: [];

			$teams_by_id     = array_column( $teams_data, null, 'id' );
			$delegate_emails = array_values( array_filter( array_column( $teams_data, 'delegado_correo' ) ) );
			$home_name       = $teams_by_id[ $home_team_id ]['name'] ?? '';
			$away_name       = $teams_by_id[ $away_team_id ]['name'] ?? '';

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$tournament_name = (string) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT name FROM {$wpdb->prefix}ds_tournaments WHERE id = %d",
					$tournament_id
				)
			);

			if ( ! empty( $delegate_emails ) && $tournament_name ) {
				( new \SportsLeague\Notifications\MailDispatcher() )->notify_match_result(
					$delegate_emails,
					$home_name,
					$away_name,
					$home_score,
					$away_score,
					(int) $match['round_number'],
					$tournament_name
				);
			}
		}

		// Recalcular tabla de posiciones.
		$standings = ( new StandingsCalculator() )->recalculate( $tournament_id );

		// Invalidar caché pública del torneo (fixture, posiciones, goleadores, tribunal).
		PublicEndpoints::invalidate_cache( $tournament_id );

		return rest_ensure_response(
			[
				'match_id'    => $match_id,
				'home_score'  => $home_score,
				'away_score'  => $away_score,
				'status'      => 'finished',
				'standings'   => $standings,
			]
		);
	}

	/**
	 * POST /admin/match/{id}/planillero
	 *
	 * Asigna o desasigna un planillero al partido.
	 * Verifica que el planillero no tenga otro partido simultáneo (ventana ±2 horas).
	 */
	public static function post_match_planillero( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		global $wpdb;

		$match_id           = (int) $request['id'];
		$planillero_user_id = (int) $request['planillero_user_id'];

		// Verificar que el partido existe y no está finalizado.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$match = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT id, status, match_datetime FROM {$wpdb->prefix}ds_matches WHERE id = %d",
				$match_id
			),
			ARRAY_A
		);

		if ( ! $match ) {
			return new \WP_Error( 'match_not_found', __( 'Partido no encontrado.', 'soccertrack' ), [ 'status' => 404 ] );
		}

		if ( 'finished' === $match['status'] ) {
			return new \WP_Error( 'match_finished', __( 'No se puede reasignar planillero a un partido cerrado.', 'soccertrack' ), [ 'status' => 409 ] );
		}

		// planillero_user_id = 0 significa desasignar.
		if ( $planillero_user_id > 0 ) {
			// Verificar que el usuario existe y tiene la capability de planillero.
			$user = get_user_by( 'id', $planillero_user_id );
			if ( ! $user ) {
				return new \WP_Error( 'user_not_found', __( 'Usuario no encontrado.', 'soccertrack' ), [ 'status' => 404 ] );
			}

			if ( ! user_can( $planillero_user_id, 'ds_enter_match_incidents' ) ) {
				return new \WP_Error(
					'invalid_role',
					__( 'El usuario seleccionado no tiene permisos de planillero.', 'soccertrack' ),
					[ 'status' => 422 ]
				);
			}

			// Verificar conflicto de horario: otro partido en ventana de ±120 min.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$conflict = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT id, match_datetime FROM {$wpdb->prefix}ds_matches
					 WHERE planillero_user_id = %d
					   AND id != %d
					   AND status NOT IN ('finished', 'suspended')
					   AND match_datetime BETWEEN DATE_SUB( %s, INTERVAL 120 MINUTE )
					                          AND DATE_ADD( %s, INTERVAL 120 MINUTE )",
					$planillero_user_id,
					$match_id,
					$match['match_datetime'],
					$match['match_datetime']
				),
				ARRAY_A
			);

			if ( $conflict ) {
				return new \WP_Error(
					'schedule_conflict',
					sprintf(
						/* translators: fecha/hora del partido en conflicto */
						__( 'El planillero ya está asignado a otro partido el %s. No puede estar en dos partidos al mismo tiempo.', 'soccertrack' ),
						$conflict['match_datetime']
					),
					[ 'status' => 409 ]
				);
			}
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->update(
			"{$wpdb->prefix}ds_matches",
			[ 'planillero_user_id' => $planillero_user_id > 0 ? $planillero_user_id : null ],
			[ 'id' => $match_id ],
			[ '%d' ],
			[ '%d' ]
		);

		$planillero_name = '';
		if ( $planillero_user_id > 0 ) {
			$u = get_user_by( 'id', $planillero_user_id );
			$planillero_name = $u ? $u->display_name : '';
		}

		return rest_ensure_response( [
			'match_id'           => $match_id,
			'planillero_user_id' => $planillero_user_id > 0 ? $planillero_user_id : null,
			'planillero_name'    => $planillero_name,
		] );
	}

	/**
	 * POST /admin/tournament/{id}/fixture
	 *
	 * Genera el fixture round-robin para todos los equipos del torneo.
	 */
	public static function post_generate_fixture( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		global $wpdb;

		$tournament_id = (int) $request['id'];
		$venue_id      = (int) $request['venue_id'];

		// Verificar torneo.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$tournament = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT id, name, match_weekday, match_weekdays, match_time, match_time_weekend, match_duration FROM {$wpdb->prefix}ds_tournaments WHERE id = %d",
				$tournament_id
			),
			ARRAY_A
		);

		if ( ! $tournament ) {
			return new \WP_Error( 'tournament_not_found', __( 'Torneo no encontrado.', 'soccertrack' ), [ 'status' => 404 ] );
		}

		// Verificar que no existe fixture previo.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$existing = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}ds_matches WHERE tournament_id = %d",
				$tournament_id
			)
		);

		if ( $existing > 0 ) {
			return new \WP_Error(
				'fixture_exists',
				__( 'El torneo ya tiene un fixture generado. Elimina los partidos existentes antes de regenerar.', 'soccertrack' ),
				[ 'status' => 409 ]
			);
		}

		// Obtener equipos del torneo.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$team_ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT id FROM {$wpdb->prefix}ds_teams WHERE tournament_id = %d ORDER BY id ASC",
				$tournament_id
			)
		);

		if ( count( $team_ids ) < 2 ) {
			return new \WP_Error(
				'not_enough_teams',
				__( 'Se necesitan al menos 2 equipos para generar el fixture.', 'soccertrack' ),
				[ 'status' => 422 ]
			);
		}

		$team_ids    = array_map( 'intval', $team_ids );
		$match_ids   = ( new FixtureGenerator() )->generate( $tournament, $team_ids, $venue_id );

		// Notificar a todos los delegados que el fixture está disponible.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$delegate_emails = array_values( array_filter(
			$wpdb->get_col(
				$wpdb->prepare(
					"SELECT delegado_correo FROM {$wpdb->prefix}ds_teams
					 WHERE tournament_id = %d AND delegado_correo IS NOT NULL AND delegado_correo != ''",
					$tournament_id
				)
			) ?: []
		) );

		if ( ! empty( $delegate_emails ) ) {
			( new \SportsLeague\Notifications\MailDispatcher() )->notify_fixture_generated(
				$delegate_emails,
				(string) $tournament['name'],
				count( $match_ids ),
				home_url( '/?torneo=' . $tournament_id )
			);
		}

		return rest_ensure_response(
			[
				'tournament_id'   => $tournament_id,
				'matches_created' => count( $match_ids ),
				'match_ids'       => $match_ids,
			]
		);
	}

	/**
	 * POST /admin/player/sanction
	 *
	 * Registra una sanción disciplinaria y bloquea al jugador.
	 */
	public static function post_sanction( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		global $wpdb;

		$player_id     = (int) $request['player_id'];
		$tournament_id = (int) $request['tournament_id'];
		$match_id      = (int) $request['match_id'];
		$reason        = (string) $request['reason'];
		$ban_matches   = (int) $request['ban_matches'];

		// Verificar jugador.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$player = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT id, first_name, last_name FROM {$wpdb->prefix}ds_players WHERE id = %d",
				$player_id
			),
			ARRAY_A
		);

		if ( ! $player ) {
			return new \WP_Error( 'player_not_found', __( 'Jugador no encontrado.', 'soccertrack' ), [ 'status' => 404 ] );
		}

		$sanction_id = ( new TribunalManager() )->sanction(
			$player_id,
			$tournament_id,
			$match_id,
			$reason,
			$ban_matches
		);

		// Notificar al delegado del club del jugador sancionado.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$delegate_data = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT t.delegado_correo, tr.name AS tournament_name
				 FROM {$wpdb->prefix}ds_team_players tp
				 JOIN {$wpdb->prefix}ds_teams t ON t.id = tp.team_id
				 JOIN {$wpdb->prefix}ds_tournaments tr ON tr.id = t.tournament_id
				 WHERE tp.player_id = %d AND t.tournament_id = %d
				 LIMIT 1",
				$player_id,
				$tournament_id
			),
			ARRAY_A
		);

		if ( ! empty( $delegate_data['delegado_correo'] ) ) {
			( new \SportsLeague\Notifications\MailDispatcher() )->notify_sanction(
				$delegate_data['delegado_correo'],
				"{$player['first_name']} {$player['last_name']}",
				$reason,
				$ban_matches,
				$delegate_data['tournament_name']
			);
		}

		return rest_ensure_response(
			[
				'sanction_id'   => $sanction_id,
				'player_id'     => $player_id,
				'player_name'   => "{$player['first_name']} {$player['last_name']}",
				'tournament_id' => $tournament_id,
				'ban_matches'   => $ban_matches,
				'status'        => 'active',
			]
		);
	}

	/**
	 * POST /admin/import/roster
	 *
	 * Importa la planilla oficial de nómina (equipo + jugadores en un solo XLSX).
	 * Param POST: tournament_id (int)
	 * File:       file (XLSX o CSV)
	 */
	public static function post_import_roster( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$tournament_id = (int) $request->get_param( 'tournament_id' );

		if ( $tournament_id <= 0 ) {
			return new \WP_Error( 'missing_tournament_id', __( 'Se requiere tournament_id.', 'soccertrack' ), [ 'status' => 422 ] );
		}

		$file = self::handle_upload( $request );

		if ( is_wp_error( $file ) ) {
			return $file;
		}

		$result = ( new SpreadsheetImporter() )->import_team_roster( $file, $tournament_id );

		@unlink( $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors

		return rest_ensure_response( $result );
	}

	/**
	 * POST /admin/import/players
	 *
	 * Importa jugadores desde un archivo CSV/XLSX subido vía multipart.
	 * Param POST: team_id (int)
	 * File:       file (CSV o XLSX)
	 */
	public static function post_import_players( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$team_id = (int) $request->get_param( 'team_id' );

		if ( $team_id <= 0 ) {
			return new \WP_Error( 'missing_team_id', __( 'Se requiere team_id.', 'soccertrack' ), [ 'status' => 422 ] );
		}

		$file = self::handle_upload( $request );

		if ( is_wp_error( $file ) ) {
			return $file;
		}

		$result = ( new SpreadsheetImporter() )->import_players( $file, $team_id );

		// Eliminar archivo temporal.
		@unlink( $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors

		return rest_ensure_response( $result );
	}

	/**
	 * POST /admin/import/referees
	 *
	 * Importa árbitros desde un CSV/XLSX. Crea usuarios WP con rol ds_arbitro.
	 * File: file (CSV o XLSX)
	 */
	public static function post_import_referees( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$file = self::handle_upload( $request );

		if ( is_wp_error( $file ) ) {
			return $file;
		}

		$result = ( new SpreadsheetImporter() )->import_referees( $file );

		@unlink( $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors

		return rest_ensure_response( $result );
	}

	/**
	 * POST /admin/import/team-logo
	 *
	 * Sube la imagen de logo de un equipo y guarda la URL en ds_teams.logo_url.
	 * Param POST: team_id (int)
	 * File:       file (JPG, PNG o WEBP)
	 */
	public static function post_import_team_logo( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		global $wpdb;

		$team_id = (int) $request->get_param( 'team_id' );
		if ( $team_id <= 0 ) {
			return new \WP_Error( 'missing_team_id', __( 'Se requiere team_id.', 'soccertrack' ), [ 'status' => 422 ] );
		}

		$files = $request->get_file_params();
		if ( empty( $files['file'] ) || UPLOAD_ERR_OK !== $files['file']['error'] ) {
			return new \WP_Error( 'upload_error', __( 'No se recibió ningún archivo válido.', 'soccertrack' ), [ 'status' => 400 ] );
		}

		$allowed_mimes = [ 'image/jpeg', 'image/png', 'image/webp' ];
		$mime          = mime_content_type( $files['file']['tmp_name'] );
		if ( ! in_array( $mime, $allowed_mimes, true ) ) {
			return new \WP_Error( 'invalid_file_type', __( 'Solo se permiten imágenes JPG, PNG o WEBP.', 'soccertrack' ), [ 'status' => 415 ] );
		}

		if ( ! function_exists( 'wp_handle_upload' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}

		add_filter( 'upload_mimes', static function ( array $mimes ): array {
			$mimes['jpg|jpeg|jpe'] = 'image/jpeg';
			$mimes['png']          = 'image/png';
			$mimes['webp']         = 'image/webp';
			return $mimes;
		} );

		$uploaded = wp_handle_upload( $files['file'], [ 'test_form' => false ] );

		if ( isset( $uploaded['error'] ) ) {
			return new \WP_Error( 'upload_failed', $uploaded['error'], [ 'status' => 500 ] );
		}

		$logo_url = esc_url_raw( $uploaded['url'] );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->update(
			"{$wpdb->prefix}ds_teams",
			[ 'logo_url' => $logo_url ],
			[ 'id'       => $team_id ],
			[ '%s' ],
			[ '%d' ]
		);

		return rest_ensure_response( [ 'team_id' => $team_id, 'logo_url' => $logo_url ] );
	}

	/**
	 * POST /admin/match/{id}/event
	 *
	 * Registra un evento de partido (gol, gol propio, tarjeta amarilla, tarjeta roja) con detalle.
	 * La tarjeta roja es SOLO un evento — la sanción la define el Tribunal, no este endpoint.
	 */
	public static function post_match_event( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		global $wpdb;

		$match_id    = (int) $request['id'];
		$player_id   = (int) $request['player_id'];
		$team_id     = (int) $request['team_id'];
		$event_type  = (string) $request['event_type'];
		$minute      = (int) $request['minute'];
		$description = sanitize_textarea_field( (string) ( $request['description'] ?? '' ) );

		// Verificar que el partido existe y no está finalizado.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$match = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT id, tournament_id, status, planillero_user_id FROM {$wpdb->prefix}ds_matches WHERE id = %d",
				$match_id
			),
			ARRAY_A
		);

		if ( ! $match ) {
			return new \WP_Error( 'match_not_found', __( 'Partido no encontrado.', 'soccertrack' ), [ 'status' => 404 ] );
		}

		if ( 'finished' === $match['status'] ) {
			return new \WP_Error( 'match_finished', __( 'El partido ya está cerrado.', 'soccertrack' ), [ 'status' => 409 ] );
		}

		// Atribuir el evento al planillero asignado del partido si existe;
		// de lo contrario al usuario actualmente conectado.
		$created_by = (int) ( $match['planillero_user_id'] ?? 0 ) ?: get_current_user_id();

		// Insertar evento con auditoría.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->insert(
			"{$wpdb->prefix}ds_match_events",
			[
				'match_id'      => $match_id,
				'tournament_id' => (int) $match['tournament_id'],
				'player_id'     => $player_id,
				'team_id'       => $team_id,
				'event_type'    => $event_type,
				'minute'        => $minute,
				'description'   => $description !== '' ? $description : null,
				'created_by'    => $created_by,
			],
			[ '%d', '%d', '%d', '%d', '%s', '%d', '%s', '%d' ]
		);

		if ( $wpdb->last_error ) {
			return new \WP_Error( 'db_error', __( 'Error al guardar el evento.', 'soccertrack' ), [ 'status' => 500 ] );
		}

		$event_id = (int) $wpdb->insert_id;

		// Obtener nombre del jugador para la respuesta.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$player = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT first_name, last_name FROM {$wpdb->prefix}ds_players WHERE id = %d",
				$player_id
			),
			ARRAY_A
		);

		$user        = get_user_by( 'id', $created_by );
		$player_name = $player ? "{$player['first_name']} {$player['last_name']}" : '';
		$auto_sanction = null;

		// ── Acumulación automática de amarillas: N amarillas = 1 fecha suspendido ──
		if ( 'yellow_card' === $event_type ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$yellows_threshold = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COALESCE(yellows_per_suspension, 3)
					 FROM {$wpdb->prefix}ds_tournaments WHERE id = %d",
					(int) $match['tournament_id']
				)
			);
			if ( $yellows_threshold < 2 ) {
				$yellows_threshold = 3; // salvaguarda
			}

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$yellow_count = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*)
					 FROM {$wpdb->prefix}ds_match_events
					 WHERE player_id = %d
					   AND tournament_id = %d
					   AND event_type = 'yellow_card'",
					$player_id,
					(int) $match['tournament_id']
				)
			);

			if ( $yellow_count > 0 && 0 === $yellow_count % $yellows_threshold ) {
				$reason = sprintf(
					/* translators: %d: número de amarillas acumuladas */
					__( 'Acumulación de %d tarjetas amarillas.', 'soccertrack' ),
					$yellow_count
				);

				( new TribunalManager() )->sanction(
					$player_id,
					(int) $match['tournament_id'],
					$match_id,
					$reason,
					1
				);

				// Notificar al delegado del club.
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$delegate = $wpdb->get_row(
					$wpdb->prepare(
						"SELECT t.delegado_correo, tr.name AS tournament_name
						 FROM {$wpdb->prefix}ds_team_players tp
						 JOIN {$wpdb->prefix}ds_teams t ON t.id = tp.team_id
						 JOIN {$wpdb->prefix}ds_tournaments tr ON tr.id = t.tournament_id
						 WHERE tp.player_id = %d AND t.tournament_id = %d
						 LIMIT 1",
						$player_id,
						(int) $match['tournament_id']
					),
					ARRAY_A
				);

				if ( ! empty( $delegate['delegado_correo'] ) ) {
					( new \SportsLeague\Notifications\MailDispatcher() )->notify_sanction(
						$delegate['delegado_correo'],
						$player_name,
						$reason,
						1,
						(string) $delegate['tournament_name']
					);
				}

				$auto_sanction = [
					'reason'       => $reason,
					'ban_matches'  => 1,
					'yellow_count' => $yellow_count,
				];
			}
		}

		return rest_ensure_response( [
			'event_id'         => $event_id,
			'match_id'         => $match_id,
			'player_id'        => $player_id,
			'player_name'      => $player_name,
			'team_id'          => $team_id,
			'event_type'       => $event_type,
			'minute'           => $minute,
			'description'      => $description,
			'created_by'       => $created_by,
			'created_by_name'  => $user ? $user->display_name : '',
			'updated_by'       => null,
			'updated_by_name'  => '',
			'updated_at'       => null,
			'auto_sanction'    => $auto_sanction,
		] );
	}

	/**
	 * PATCH /admin/match/{id}/event/{event_id}
	 *
	 * Edita el detalle o minuto de un evento. Solo árbitros y coordinadores.
	 * Registra quién editó y cuándo.
	 */
	public static function patch_match_event( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		global $wpdb;

		$match_id = (int) $request['id'];
		$event_id = (int) $request['event_id'];

		// Verificar que el partido no está cerrado.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$match = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT id, status FROM {$wpdb->prefix}ds_matches WHERE id = %d",
				$match_id
			),
			ARRAY_A
		);

		if ( ! $match ) {
			return new \WP_Error( 'match_not_found', __( 'Partido no encontrado.', 'soccertrack' ), [ 'status' => 404 ] );
		}

		if ( 'finished' === $match['status'] ) {
			return new \WP_Error( 'match_finished', __( 'El partido ya está cerrado.', 'soccertrack' ), [ 'status' => 409 ] );
		}

		// Verificar que el evento pertenece a este partido.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$event = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT id, event_type, minute, description FROM {$wpdb->prefix}ds_match_events
				 WHERE id = %d AND match_id = %d",
				$event_id,
				$match_id
			),
			ARRAY_A
		);

		if ( ! $event ) {
			return new \WP_Error( 'event_not_found', __( 'Evento no encontrado.', 'soccertrack' ), [ 'status' => 404 ] );
		}

		$update_data   = [];
		$update_format = [];

		if ( isset( $request['description'] ) ) {
			$desc = sanitize_textarea_field( (string) $request['description'] );
			$update_data['description'] = $desc !== '' ? $desc : null;
			$update_format[]            = '%s';
		}

		if ( isset( $request['minute'] ) ) {
			$update_data['minute'] = (int) $request['minute'];
			$update_format[]       = '%d';
		}

		if ( empty( $update_data ) ) {
			return new \WP_Error( 'no_fields', __( 'No se enviaron campos para actualizar.', 'soccertrack' ), [ 'status' => 400 ] );
		}

		$updated_by = get_current_user_id();
		$updated_at = current_time( 'mysql' );

		$update_data['updated_by'] = $updated_by;
		$update_data['updated_at'] = $updated_at;
		$update_format[]           = '%d';
		$update_format[]           = '%s';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$result = $wpdb->update(
			"{$wpdb->prefix}ds_match_events",
			$update_data,
			[ 'id' => $event_id, 'match_id' => $match_id ],
			$update_format,
			[ '%d', '%d' ]
		);

		if ( false === $result ) {
			return new \WP_Error( 'db_error', __( 'Error al actualizar el evento.', 'soccertrack' ), [ 'status' => 500 ] );
		}

		$user = get_user_by( 'id', $updated_by );

		return rest_ensure_response( [
			'event_id'        => $event_id,
			'match_id'        => $match_id,
			'description'     => $update_data['description'] ?? $event['description'],
			'minute'          => $update_data['minute'] ?? (int) $event['minute'],
			'updated_by'      => $updated_by,
			'updated_by_name' => $user ? $user->display_name : '',
			'updated_at'      => $updated_at,
		] );
	}

	/**
	 * DELETE /admin/match/{id}/event/{event_id}
	 *
	 * Elimina un evento de partido (solo si el partido no está finalizado).
	 */
	public static function delete_match_event( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		global $wpdb;

		$match_id = (int) $request['id'];
		$event_id = (int) $request['event_id'];

		// Verificar que el partido existe y no está cerrado.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$match = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT id, status FROM {$wpdb->prefix}ds_matches WHERE id = %d",
				$match_id
			),
			ARRAY_A
		);

		if ( ! $match ) {
			return new \WP_Error( 'match_not_found', __( 'Partido no encontrado.', 'soccertrack' ), [ 'status' => 404 ] );
		}

		if ( 'finished' === $match['status'] ) {
			return new \WP_Error( 'match_finished', __( 'El partido ya está cerrado.', 'soccertrack' ), [ 'status' => 409 ] );
		}

		// Verificar que el evento pertenece a este partido.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$event = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT id, event_type, created_by FROM {$wpdb->prefix}ds_match_events WHERE id = %d AND match_id = %d",
				$event_id,
				$match_id
			),
			ARRAY_A
		);

		if ( ! $event ) {
			return new \WP_Error( 'event_not_found', __( 'Evento no encontrado.', 'soccertrack' ), [ 'status' => 404 ] );
		}

		// Planillero solo puede eliminar sus propios eventos; árbitro/coordinador pueden eliminar cualquiera.
		if ( ! current_user_can( 'ds_edit_incidents' ) && (int) $event['created_by'] !== get_current_user_id() ) {
			return new \WP_Error(
				'forbidden',
				__( 'Solo puedes eliminar incidentes que tú mismo registraste.', 'soccertrack' ),
				[ 'status' => 403 ]
			);
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$deleted = $wpdb->delete(
			"{$wpdb->prefix}ds_match_events",
			[ 'id' => $event_id, 'match_id' => $match_id ],
			[ '%d', '%d' ]
		);

		if ( false === $deleted ) {
			return new \WP_Error( 'db_error', __( 'Error al eliminar el evento.', 'soccertrack' ), [ 'status' => 500 ] );
		}

		return rest_ensure_response( [
			'deleted'    => true,
			'event_id'   => $event_id,
			'event_type' => $event['event_type'],
		] );
	}

	/**
	 * GET /admin/match/{id}/events
	 *
	 * Devuelve todos los eventos del partido con datos de jugador, equipo y auditoría.
	 */
	public static function get_match_events( \WP_REST_Request $request ): \WP_REST_Response {
		global $wpdb;

		$match_id       = (int) $request['id'];
		$current_user_id = get_current_user_id();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$events = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT e.id, e.event_type, e.minute, e.description,
				        e.created_by, e.updated_by, e.updated_at,
				        e.player_id, e.team_id,
				        p.first_name, p.last_name, p.rut_id,
				        t.name AS team_name
				 FROM {$wpdb->prefix}ds_match_events e
				 JOIN {$wpdb->prefix}ds_players p ON p.id = e.player_id
				 JOIN {$wpdb->prefix}ds_teams   t ON t.id = e.team_id
				 WHERE e.match_id = %d
				 ORDER BY e.minute ASC, e.id ASC",
				$match_id
			),
			ARRAY_A
		);

		if ( ! $events ) {
			return rest_ensure_response( [] );
		}

		// Resolver nombres de usuarios de auditoría evitando N+1 con un mapa único.
		$user_ids = array_unique( array_filter( array_merge(
			array_column( $events, 'created_by' ),
			array_column( $events, 'updated_by' )
		) ) );

		$user_names = [];
		foreach ( $user_ids as $uid ) {
			$u = get_user_by( 'id', (int) $uid );
			$user_names[ (int) $uid ] = $u ? $u->display_name : '';
		}

		$can_edit = current_user_can( 'ds_edit_incidents' );

		foreach ( $events as &$ev ) {
			$created_by = (int) $ev['created_by'];
			$updated_by = (int) $ev['updated_by'];

			$ev['created_by']      = $created_by;
			$ev['created_by_name'] = $user_names[ $created_by ] ?? '';
			$ev['updated_by']      = $updated_by ?: null;
			$ev['updated_by_name'] = $updated_by ? ( $user_names[ $updated_by ] ?? '' ) : '';
			// Indica si el usuario actual puede editar/eliminar este evento.
			$ev['can_edit']   = $can_edit;
			$ev['can_delete'] = $can_edit || $created_by === $current_user_id;
		}
		unset( $ev );

		return rest_ensure_response( $events );
	}

	/**
	 * POST /admin/tournament/{id}/playoffs
	 *
	 * Genera las semi-finales a partir de la tabla de posiciones (top 4).
	 * Requisito: todos los partidos de fase regular deben estar finalizados.
	 */
	public static function post_generate_playoffs( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		global $wpdb;

		$tid      = (int) $request['id'];
		$venue_id = (int) $request['venue_id'];

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$tournament = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT id, match_weekday, match_weekdays, match_time, match_time_weekend, match_duration FROM {$wpdb->prefix}ds_tournaments WHERE id = %d",
				$tid
			),
			ARRAY_A
		);

		if ( ! $tournament ) {
			return new \WP_Error( 'tournament_not_found', __( 'Torneo no encontrado.', 'soccertrack' ), [ 'status' => 404 ] );
		}

		// Verificar si el torneo tiene brackets configurados.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$has_brackets = (bool) (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}ds_playoff_brackets WHERE tournament_id = %d",
				$tid
			)
		);

		$bracket_id = isset( $request['bracket_id'] ) ? (int) $request['bracket_id'] : 0;

		if ( $has_brackets ) {
			if ( ! $bracket_id ) {
				return new \WP_Error(
					'bracket_id_required',
					__( 'Este torneo tiene brackets configurados. Debes especificar bracket_id.', 'soccertrack' ),
					[ 'status' => 400 ]
				);
			}
			$result = ( new FixtureGenerator() )->generate_bracket_playoffs( $tournament, $bracket_id, $venue_id );
		} else {
			$result = ( new FixtureGenerator() )->generate_playoffs( $tournament, $venue_id );
		}

		if ( ! empty( $result['error'] ) ) {
			return new \WP_Error( 'playoffs_error', $result['error'], [ 'status' => 409 ] );
		}

		PublicEndpoints::invalidate_cache( $tid );

		return rest_ensure_response( [
			'matches_created' => count( $result['match_ids'] ),
			'match_ids'       => $result['match_ids'],
		] );
	}

	/**
	 * POST /admin/tournament/{id}/finals
	 *
	 * Genera la Final y el partido por el 3.er puesto una vez que
	 * ambas semi-finales están finalizadas.
	 */
	public static function post_generate_finals( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		global $wpdb;

		$tid      = (int) $request['id'];
		$venue_id = (int) $request['venue_id'];

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$tournament = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT id, match_weekday, match_weekdays, match_time, match_time_weekend, match_duration FROM {$wpdb->prefix}ds_tournaments WHERE id = %d",
				$tid
			),
			ARRAY_A
		);

		if ( ! $tournament ) {
			return new \WP_Error( 'tournament_not_found', __( 'Torneo no encontrado.', 'soccertrack' ), [ 'status' => 404 ] );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$has_brackets = (bool) (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}ds_playoff_brackets WHERE tournament_id = %d",
				$tid
			)
		);

		$bracket_id = isset( $request['bracket_id'] ) ? (int) $request['bracket_id'] : 0;

		if ( $has_brackets ) {
			if ( ! $bracket_id ) {
				return new \WP_Error(
					'bracket_id_required',
					__( 'Este torneo tiene brackets configurados. Debes especificar bracket_id.', 'soccertrack' ),
					[ 'status' => 400 ]
				);
			}
			$result = ( new FixtureGenerator() )->generate_bracket_finals( $tournament, $bracket_id, $venue_id );
		} else {
			$result = ( new FixtureGenerator() )->generate_finals( $tournament, $venue_id );
		}

		if ( ! empty( $result['error'] ) ) {
			return new \WP_Error( 'finals_error', $result['error'], [ 'status' => 409 ] );
		}

		PublicEndpoints::invalidate_cache( $tid );

		return rest_ensure_response( [
			'matches_created' => count( $result['match_ids'] ),
			'match_ids'       => $result['match_ids'],
		] );
	}

	/**
	 * Verifica si el rango [from, to] se solapa con brackets existentes del torneo.
	 * Excluye opcionalmente un bracket (para ediciones).
	 *
	 * @param  int      $tournament_id
	 * @param  int      $rank_from
	 * @param  int      $rank_to
	 * @param  int|null $exclude_id  ID del bracket a excluir (PATCH).
	 * @return bool  true si hay solapamiento.
	 */
	private static function brackets_overlap( int $tournament_id, int $rank_from, int $rank_to, ?int $exclude_id = null ): bool {
		global $wpdb;

		$exclude_sql = $exclude_id ? $wpdb->prepare( ' AND id != %d', $exclude_id ) : '';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		$count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}ds_playoff_brackets
				 WHERE tournament_id = %d
				   AND rank_from <= %d
				   AND rank_to   >= %d" . $exclude_sql, // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				$tournament_id,
				$rank_to,
				$rank_from
			)
		);

		return $count > 0;
	}

	/**
	 * Un bracket está bloqueado si ya tiene partidos generados.
	 */
	private static function bracket_is_locked( int $bracket_id ): bool {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return (bool) (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}ds_matches WHERE bracket_id = %d",
				$bracket_id
			)
		);
	}

	public static function post_bracket( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		global $wpdb;

		$tid        = (int) $request['id'];
		$name       = (string) $request['name'];
		$rank_from  = (int) $request['rank_from'];
		$rank_to    = (int) $request['rank_to'];
		$sort_order = (int) ( $request['sort_order'] ?? 0 );

		if ( $rank_from >= $rank_to ) {
			return new \WP_Error( 'invalid_range', __( 'rank_from debe ser menor que rank_to.', 'soccertrack' ), [ 'status' => 422 ] );
		}

		if ( self::brackets_overlap( $tid, $rank_from, $rank_to ) ) {
			return new \WP_Error( 'bracket_overlap', __( 'El rango se solapa con un bracket existente.', 'soccertrack' ), [ 'status' => 409 ] );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->insert(
			"{$wpdb->prefix}ds_playoff_brackets",
			[
				'tournament_id' => $tid,
				'name'          => $name,
				'rank_from'     => $rank_from,
				'rank_to'       => $rank_to,
				'sort_order'    => $sort_order,
			],
			[ '%d', '%s', '%d', '%d', '%d' ]
		);

		$bracket_id = (int) $wpdb->insert_id;
		if ( ! $bracket_id ) {
			return new \WP_Error( 'db_error', __( 'Error al crear el bracket.', 'soccertrack' ), [ 'status' => 500 ] );
		}

		return rest_ensure_response( [
			'id'         => $bracket_id,
			'name'       => $name,
			'rank_from'  => $rank_from,
			'rank_to'    => $rank_to,
			'sort_order' => $sort_order,
		] );
	}

	public static function get_brackets( \WP_REST_Request $request ): \WP_REST_Response {
		global $wpdb;

		$tid = (int) $request['id'];

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, name, rank_from, rank_to, sort_order
				 FROM {$wpdb->prefix}ds_playoff_brackets
				 WHERE tournament_id = %d
				 ORDER BY sort_order ASC, rank_from ASC",
				$tid
			),
			ARRAY_A
		) ?: [];

		return rest_ensure_response(
			array_map( static fn( array $r ): array => [
				'id'         => (int) $r['id'],
				'name'       => $r['name'],
				'rank_from'  => (int) $r['rank_from'],
				'rank_to'    => (int) $r['rank_to'],
				'sort_order' => (int) $r['sort_order'],
				'locked'     => self::bracket_is_locked( (int) $r['id'] ),
			], $rows )
		);
	}

	public static function patch_bracket( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		global $wpdb;

		$tid = (int) $request['id'];
		$bid = (int) $request['bid'];

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$bracket = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT id, tournament_id, name, rank_from, rank_to, sort_order
				 FROM {$wpdb->prefix}ds_playoff_brackets
				 WHERE id = %d AND tournament_id = %d",
				$bid,
				$tid
			),
			ARRAY_A
		);

		if ( ! $bracket ) {
			return new \WP_Error( 'bracket_not_found', __( 'Bracket no encontrado.', 'soccertrack' ), [ 'status' => 404 ] );
		}

		if ( self::bracket_is_locked( $bid ) ) {
			return new \WP_Error( 'bracket_locked', __( 'El bracket ya tiene partidos generados y no puede ser editado.', 'soccertrack' ), [ 'status' => 409 ] );
		}

		$name       = isset( $request['name'] )       ? (string) $request['name']       : $bracket['name'];
		$rank_from  = isset( $request['rank_from'] )  ? (int) $request['rank_from']     : (int) $bracket['rank_from'];
		$rank_to    = isset( $request['rank_to'] )    ? (int) $request['rank_to']       : (int) $bracket['rank_to'];
		$sort_order = isset( $request['sort_order'] ) ? (int) $request['sort_order']    : (int) $bracket['sort_order'];

		if ( $rank_from >= $rank_to ) {
			return new \WP_Error( 'invalid_range', __( 'rank_from debe ser menor que rank_to.', 'soccertrack' ), [ 'status' => 422 ] );
		}

		if ( self::brackets_overlap( $tid, $rank_from, $rank_to, $bid ) ) {
			return new \WP_Error( 'bracket_overlap', __( 'El rango se solapa con un bracket existente.', 'soccertrack' ), [ 'status' => 409 ] );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->update(
			"{$wpdb->prefix}ds_playoff_brackets",
			[
				'name'       => $name,
				'rank_from'  => $rank_from,
				'rank_to'    => $rank_to,
				'sort_order' => $sort_order,
			],
			[ 'id' => $bid ],
			[ '%s', '%d', '%d', '%d' ],
			[ '%d' ]
		);

		return rest_ensure_response( [
			'id'         => $bid,
			'name'       => $name,
			'rank_from'  => $rank_from,
			'rank_to'    => $rank_to,
			'sort_order' => $sort_order,
		] );
	}

	public static function delete_bracket( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		global $wpdb;

		$tid = (int) $request['id'];
		$bid = (int) $request['bid'];

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$exists = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}ds_playoff_brackets WHERE id = %d AND tournament_id = %d",
				$bid,
				$tid
			)
		);

		if ( ! $exists ) {
			return new \WP_Error( 'bracket_not_found', __( 'Bracket no encontrado.', 'soccertrack' ), [ 'status' => 404 ] );
		}

		if ( self::bracket_is_locked( $bid ) ) {
			return new \WP_Error( 'bracket_locked', __( 'El bracket ya tiene partidos generados y no puede ser eliminado.', 'soccertrack' ), [ 'status' => 409 ] );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->delete( "{$wpdb->prefix}ds_playoff_brackets", [ 'id' => $bid ], [ '%d' ] );

		return rest_ensure_response( [ 'deleted' => true, 'id' => $bid ] );
	}

	/**
	 * Mueve el archivo subido a un directorio temporal seguro y retorna su ruta.
	 *
	 * @return string|\WP_Error Ruta al archivo temporal, o WP_Error si falla.
	 */
	private static function handle_upload( \WP_REST_Request $request ): string|\WP_Error {
		$files = $request->get_file_params();

		if ( empty( $files['file'] ) || UPLOAD_ERR_OK !== $files['file']['error'] ) {
			return new \WP_Error( 'upload_error', __( 'No se recibió ningún archivo válido.', 'soccertrack' ), [ 'status' => 400 ] );
		}

		$allowed_types = [ 'text/csv', 'application/vnd.ms-excel', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' ];
		$mime          = mime_content_type( $files['file']['tmp_name'] );

		if ( ! in_array( $mime, $allowed_types, true ) ) {
			return new \WP_Error( 'invalid_file_type', __( 'Solo se permiten archivos CSV o XLSX.', 'soccertrack' ), [ 'status' => 415 ] );
		}

		// Mover a directorio temporal de WordPress (fuera de uploads/ público).
		$upload_dir = get_temp_dir();
		$tmp_name   = $upload_dir . 'soccertrack_import_' . wp_generate_password( 12, false ) . '.tmp';

		if ( ! move_uploaded_file( $files['file']['tmp_name'], $tmp_name ) ) {
			return new \WP_Error( 'move_failed', __( 'No se pudo procesar el archivo subido.', 'soccertrack' ), [ 'status' => 500 ] );
		}

		return $tmp_name;
	}
}
