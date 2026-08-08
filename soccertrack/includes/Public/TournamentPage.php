<?php
/**
 * Panel unificado de SoccerTrack + Página pública de torneo.
 *
 * Rutas del panel (requieren login con rol soccertrack):
 *   /panel/              → dashboard (según rol)
 *   /panel/login/        → login propio
 *   /panel/salir/        → logout
 *   /panel/torneos/      → lista de torneos (coordinador)
 *   /panel/torneo/{id}/  → gestión de torneo (coordinador)
 *   /panel/partido/{id}/ → planilla arbitral (árbitro)
 *   /panel/importar/     → carga masiva CSV/XLSX (coordinador)
 *   /panel/tribunal/     → tribunal disciplinario (coordinador)
 *
 * Rutas públicas (sin login):
 *   /torneo/{id}/        → portal público del torneo (pestañas)
 *
 * @package SoccerTrack
 */

declare(strict_types=1);

namespace SportsLeague\Public;

final class TournamentPage {

	private const QV_PANEL = 'st_panel_page';
	private const QV_VISTA = 'st_panel_vista';
	private const QV_ID    = 'st_panel_id';
	private const QV_PUB   = 'st_torneo_id';

	public static function init(): void {
		add_action( 'init',               [ self::class, 'add_rewrite_rules' ] );
		add_filter( 'query_vars',         [ self::class, 'add_query_vars' ] );
		add_action( 'template_redirect',  [ self::class, 'handle_requests' ] );

		// Bloquear wp-admin para roles que no son admins.
		add_action( 'admin_init',         [ self::class, 'block_admin_for_panel_roles' ] );
		add_filter( 'show_admin_bar',     [ self::class, 'hide_admin_bar' ] );
		add_filter( 'login_redirect',     [ self::class, 'redirect_after_login' ], 10, 3 );
	}

	/* ------------------------------------------------------------------ */
	/* Rewrite rules                                                        */
	/* ------------------------------------------------------------------ */

	public static function add_rewrite_rules(): void {
		$qv  = self::QV_PANEL;
		$qvv = self::QV_VISTA;
		$qvi = self::QV_ID;
		$qvp = self::QV_PUB;

		// ── Panel ────────────────────────────────────────────────────────
		add_rewrite_rule( '^panel/login/?$',             "index.php?{$qv}=1&{$qvv}=login",              'top' );
		add_rewrite_rule( '^panel/salir/?$',             "index.php?{$qv}=1&{$qvv}=salir",              'top' );
		add_rewrite_rule( '^panel/torneos/?$',           "index.php?{$qv}=1&{$qvv}=torneos",            'top' );
		add_rewrite_rule( '^panel/torneo/([0-9]+)/?$',   "index.php?{$qv}=1&{$qvv}=torneo&{$qvi}=\$matches[1]", 'top' );
		add_rewrite_rule( '^panel/partido/([0-9]+)/?$',  "index.php?{$qv}=1&{$qvv}=partido&{$qvi}=\$matches[1]", 'top' );
		add_rewrite_rule( '^panel/equipo/([0-9]+)/?$',   "index.php?{$qv}=1&{$qvv}=equipo&{$qvi}=\$matches[1]", 'top' );
		add_rewrite_rule( '^panel/importar/?$',          "index.php?{$qv}=1&{$qvv}=importar",           'top' );
		add_rewrite_rule( '^panel/tribunal/?$',          "index.php?{$qv}=1&{$qvv}=tribunal",           'top' );
		add_rewrite_rule( '^panel/recintos/?$',          "index.php?{$qv}=1&{$qvv}=recintos",                     'top' );
		add_rewrite_rule( '^panel/recinto/([0-9]+)/?$',  "index.php?{$qv}=1&{$qvv}=recinto&{$qvi}=\$matches[1]", 'top' );
		add_rewrite_rule( '^panel/mis-partidos/?$',      "index.php?{$qv}=1&{$qvv}=mis-partidos",                'top' );
		add_rewrite_rule( '^panel/usuarios/?$',          "index.php?{$qv}=1&{$qvv}=usuarios",           'top' );
		add_rewrite_rule( '^panel/carga-fecha/?$',       "index.php?{$qv}=1&{$qvv}=carga-fecha",        'top' );
		add_rewrite_rule( '^panel/?$',                   "index.php?{$qv}=1&{$qvv}=dashboard",          'top' );

		// ── Público ──────────────────────────────────────────────────────
		add_rewrite_rule( '^torneo/([0-9]+)/?$',         "index.php?{$qvp}=\$matches[1]",               'top' );
	}

	public static function add_query_vars( array $vars ): array {
		return array_merge( $vars, [ self::QV_PANEL, self::QV_VISTA, self::QV_ID, self::QV_PUB ] );
	}

	/* ------------------------------------------------------------------ */
	/* Router principal                                                     */
	/* ------------------------------------------------------------------ */

	public static function handle_requests(): void {
		$is_panel  = (bool) get_query_var( self::QV_PANEL );
		$torneo_id = absint( get_query_var( self::QV_PUB ) );

		if ( $is_panel ) {
			self::handle_panel();
			exit;
		}

		if ( $torneo_id ) {
			self::handle_public( $torneo_id );
			exit;
		}
	}

	/* ------------------------------------------------------------------ */
	/* Panel privado                                                        */
	/* ------------------------------------------------------------------ */

	private static function handle_panel(): void {
		$vista = sanitize_key( get_query_var( self::QV_VISTA ) ?: 'dashboard' );
		$id    = absint( get_query_var( self::QV_ID ) );

		// Login y logout no necesitan autenticación previa.
		if ( $vista === 'login' ) {
			self::show_login();
			return;
		}

		if ( $vista === 'salir' ) {
			wp_logout();
			wp_safe_redirect( home_url( '/panel/login/' ) );
			exit;
		}

		// Todas las demás vistas requieren autenticación.
		if ( ! is_user_logged_in() ) {
			wp_safe_redirect( home_url( '/panel/login/?redirect_to=' . rawurlencode( home_url( '/panel/' . $vista . ( $id ? "/{$id}/" : '/' ) ) ) ) );
			exit;
		}

		// Verificar que el usuario tiene al menos un rol del plugin.
		if ( ! self::user_has_panel_access() ) {
			wp_die( esc_html__( 'No tienes permisos para acceder al panel de SoccerTrack.', 'soccertrack' ), '', [ 'response' => 403 ] );
		}

		match ( $vista ) {
			'torneos'  => self::view_torneos(),
			'torneo'   => self::view_torneo( $id ),
			'equipo'   => self::view_equipo( $id ),
			'partido'  => self::view_partido( $id ),
			'importar' => self::view_importar(),
			'tribunal'      => self::view_tribunal(),
			'recintos'      => self::view_recintos(),
			'recinto'       => self::view_recinto( $id ),
			'mis-partidos'  => self::view_mis_partidos(),
			'usuarios'      => self::view_usuarios(),
			'carga-fecha'   => self::view_carga_fecha(),
			default         => self::view_dashboard(),
		};
	}

	/* ------------------------------------------------------------------ */
	/* Vistas del panel                                                     */
	/* ------------------------------------------------------------------ */

	private static function view_recintos(): void {
		global $wpdb;

		if ( ! current_user_can( 'ds_manage_tournaments' ) ) {
			wp_die( esc_html__( 'Sin permiso.', 'soccertrack' ), '', [ 'response' => 403 ] );
		}

		$notice = '';
		$error  = '';

		if ( $_SERVER['REQUEST_METHOD'] === 'POST' && isset( $_POST['st_create_venue'] ) ) {
			check_admin_referer( 'st_create_venue' );
			$name         = sanitize_text_field( $_POST['name'] ?? '' );
			$address      = sanitize_text_field( $_POST['address'] ?? '' );
			$total_courts = absint( $_POST['total_courts'] ?? 1 );

			if ( ! $name ) {
				$error = __( 'El nombre del recinto es obligatorio.', 'soccertrack' );
			} elseif ( $total_courts < 1 || $total_courts > 20 ) {
				$error = __( 'El número de canchas debe estar entre 1 y 20.', 'soccertrack' );
			} else {
				$wpdb->insert( // phpcs:ignore
					"{$wpdb->prefix}ds_venues",
					[ 'name' => $name, 'address' => $address ?: null, 'total_courts' => $total_courts ],
					[ '%s', '%s', '%d' ]
				);
				$venue_id = (int) $wpdb->insert_id;
				for ( $i = 1; $i <= $total_courts; $i++ ) {
					$wpdb->insert( // phpcs:ignore
						"{$wpdb->prefix}ds_courts",
						[ 'venue_id' => $venue_id, 'court_name' => "Cancha {$i}" ],
						[ '%d', '%s' ]
					);
				}
				$notice = 'created';
			}
		}

		if ( $_SERVER['REQUEST_METHOD'] === 'POST' && isset( $_POST['st_delete_venue'] ) ) {
			check_admin_referer( 'st_delete_venue' );
			$vid         = absint( $_POST['venue_id'] ?? 0 );
			$match_count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}ds_matches WHERE venue_id = %d", $vid ) ); // phpcs:ignore
			if ( $match_count > 0 ) {
				$error = __( 'No se puede eliminar un recinto con partidos asignados.', 'soccertrack' );
			} else {
				$wpdb->delete( "{$wpdb->prefix}ds_courts", [ 'venue_id' => $vid ], [ '%d' ] ); // phpcs:ignore
				$wpdb->delete( "{$wpdb->prefix}ds_venues", [ 'id' => $vid ],       [ '%d' ] ); // phpcs:ignore
				$notice = 'deleted';
			}
		}

		$venues = $wpdb->get_results( // phpcs:ignore
			"SELECT v.*, COUNT(DISTINCT c.id) AS court_count, COUNT(DISTINCT m.id) AS match_count
			 FROM {$wpdb->prefix}ds_venues v
			 LEFT JOIN {$wpdb->prefix}ds_courts  c ON c.venue_id = v.id
			 LEFT JOIN {$wpdb->prefix}ds_matches m ON m.venue_id = v.id
			 GROUP BY v.id ORDER BY v.id DESC",
			ARRAY_A
		);

		$page_title = __( 'Recintos y Canchas', 'soccertrack' );
		self::render( 'recintos', compact( 'venues', 'notice', 'error', 'page_title' ) );
	}

	private static function view_recinto( int $id ): void {
		global $wpdb;

		if ( ! $id || ! current_user_can( 'ds_manage_tournaments' ) ) {
			wp_safe_redirect( home_url( '/panel/recintos/' ) );
			exit;
		}

		$venue = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}ds_venues WHERE id = %d", $id ), ARRAY_A ); // phpcs:ignore

		if ( ! $venue ) {
			wp_die( esc_html__( 'Recinto no encontrado.', 'soccertrack' ), '', [ 'response' => 404 ] );
		}

		$notice = '';
		$error  = '';

		if ( $_SERVER['REQUEST_METHOD'] === 'POST' && isset( $_POST['st_add_court'] ) ) {
			check_admin_referer( 'st_add_court_' . $id );
			$court_name = sanitize_text_field( $_POST['court_name'] ?? '' );
			if ( ! $court_name ) {
				$error = __( 'El nombre de la cancha es obligatorio.', 'soccertrack' );
			} else {
				$exists = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}ds_courts WHERE venue_id = %d AND court_name = %s", $id, $court_name ) ); // phpcs:ignore
				if ( $exists ) {
					$error = __( 'Ya existe una cancha con ese nombre en este recinto.', 'soccertrack' );
				} else {
					$wpdb->insert( "{$wpdb->prefix}ds_courts", [ 'venue_id' => $id, 'court_name' => $court_name ], [ '%d', '%s' ] ); // phpcs:ignore
					$notice = 'court_added';
				}
			}
		}

		if ( $_SERVER['REQUEST_METHOD'] === 'POST' && isset( $_POST['st_delete_court'] ) ) {
			check_admin_referer( 'st_delete_court_' . $id );
			$court_id = absint( $_POST['court_id'] ?? 0 );
			$in_use   = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}ds_matches WHERE court_id = %d", $court_id ) ); // phpcs:ignore
			if ( $in_use ) {
				$error = __( 'Esta cancha tiene partidos asignados y no puede eliminarse.', 'soccertrack' );
			} else {
				$wpdb->delete( "{$wpdb->prefix}ds_courts", [ 'id' => $court_id ], [ '%d' ] ); // phpcs:ignore
				$notice = 'court_deleted';
			}
		}

		$courts = $wpdb->get_results( // phpcs:ignore
			$wpdb->prepare(
				"SELECT c.*, (SELECT COUNT(*) FROM {$wpdb->prefix}ds_matches m WHERE m.court_id = c.id) AS match_count
				 FROM {$wpdb->prefix}ds_courts c WHERE c.venue_id = %d ORDER BY c.id ASC",
				$id
			),
			ARRAY_A
		);

		$page_title = esc_html( $venue['name'] );
		self::render( 'recinto-detalle', compact( 'venue', 'courts', 'notice', 'error', 'page_title' ) );
	}

	/**
	 * Vista de partidos asignados al árbitro o planillero actual.
	 * Acceso: ds_enter_match_incidents, ds_close_match, o ds_view_match_sheet.
	 */
	private static function view_mis_partidos(): void {
		global $wpdb;

		if ( ! current_user_can( 'ds_enter_match_incidents' ) && ! current_user_can( 'ds_view_match_sheet' ) ) {
			wp_safe_redirect( home_url( '/panel/' ) );
			exit;
		}

		$user_id = get_current_user_id();

		// Partidos asignados a este árbitro o planillero.
		$matches_assigned = $wpdb->get_results( // phpcs:ignore
			$wpdb->prepare(
				"SELECT m.id, m.round_number, m.match_datetime, m.status,
				        m.home_score, m.away_score,
				        th.name AS home_team, ta.name AS away_team,
				        tr.name AS tournament_name,
				        v.name  AS venue, c.court_name,
				        CASE WHEN m.planillero_user_id = %d THEN 'planillero' ELSE 'arbitro' END AS assigned_role
				 FROM {$wpdb->prefix}ds_matches m
				 JOIN {$wpdb->prefix}ds_teams  th ON th.id = m.home_team_id
				 JOIN {$wpdb->prefix}ds_teams  ta ON ta.id = m.away_team_id
				 JOIN {$wpdb->prefix}ds_tournaments tr ON tr.id = m.tournament_id
				 LEFT JOIN {$wpdb->prefix}ds_venues  v ON v.id  = m.venue_id
				 LEFT JOIN {$wpdb->prefix}ds_courts  c ON c.id  = m.court_id
				 WHERE m.referee_user_id = %d OR m.planillero_user_id = %d
				 ORDER BY m.status ASC, m.match_datetime ASC, m.round_number ASC
				 LIMIT 50",
				$user_id,
				$user_id,
				$user_id
			),
			ARRAY_A
		);

		// Si no hay partidos asignados por nombre, mostrar todos los pendientes del sistema
		// (útil cuando el árbitro aún no tiene partidos asignados formalmente).
		$matches_pending = [];
		if ( empty( $matches_assigned ) ) {
			$matches_pending = $wpdb->get_results( // phpcs:ignore
				"SELECT m.id, m.round_number, m.match_datetime, m.status,
				        m.home_score, m.away_score,
				        th.name AS home_team, ta.name AS away_team,
				        tr.name AS tournament_name,
				        v.name  AS venue, c.court_name
				 FROM {$wpdb->prefix}ds_matches m
				 JOIN {$wpdb->prefix}ds_teams  th ON th.id = m.home_team_id
				 JOIN {$wpdb->prefix}ds_teams  ta ON ta.id = m.away_team_id
				 JOIN {$wpdb->prefix}ds_tournaments tr ON tr.id = m.tournament_id
				 LEFT JOIN {$wpdb->prefix}ds_venues  v ON v.id  = m.venue_id
				 LEFT JOIN {$wpdb->prefix}ds_courts  c ON c.id  = m.court_id
				 WHERE m.status != 'finished'
				   AND tr.status = 'active'
				 ORDER BY m.match_datetime ASC, m.round_number ASC
				 LIMIT 50",
				ARRAY_A
			);
		}

		$page_title = __( 'Mis Partidos', 'soccertrack' );
		self::render( 'mis-partidos', compact( 'matches_assigned', 'matches_pending', 'page_title' ) );
	}

	private static function view_dashboard(): void {
		global $wpdb;

		$stats = get_transient( 'st_dashboard_stats' );
		if ( false === $stats ) {
			$stats = [
				'tournaments' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}ds_tournaments" ), // phpcs:ignore
				'teams'       => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}ds_teams" ),       // phpcs:ignore
				// Inscripciones reales (ds_team_players) — un jugador puede estar en varios equipos.
				'players'     => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}ds_team_players" ), // phpcs:ignore
				'matches'     => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}ds_matches WHERE status = 'finished'" ), // phpcs:ignore
				'sanctions'   => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}ds_disciplinary_sanctions WHERE status = 'active'" ), // phpcs:ignore
			];
			set_transient( 'st_dashboard_stats', $stats, 60 );
		}

		$tournaments = $wpdb->get_results( // phpcs:ignore
			"SELECT id, name, status, start_date FROM {$wpdb->prefix}ds_tournaments ORDER BY id DESC LIMIT 6",
			ARRAY_A
		);

		$page_title = __( 'Dashboard', 'soccertrack' );
		self::render( 'dashboard', compact( 'stats', 'tournaments', 'page_title' ) );
	}

	private static function view_torneos(): void {
		global $wpdb;

		if ( ! current_user_can( 'ds_manage_tournaments' ) ) {
			wp_die( esc_html__( 'Sin permiso.', 'soccertrack' ), '', [ 'response' => 403 ] );
		}

		// Cambiar estado del torneo.
		$notice = '';
		if ( $_SERVER['REQUEST_METHOD'] === 'POST' && isset( $_POST['st_change_status'] ) ) {
			check_admin_referer( 'st_change_status' );
			$tid        = absint( $_POST['tournament_id'] ?? 0 );
			$new_status = sanitize_key( $_POST['new_status'] ?? '' );
			$allowed    = [ 'draft', 'active', 'completed' ];

			if ( $tid && in_array( $new_status, $allowed, true ) ) {
				$wpdb->update( // phpcs:ignore
					"{$wpdb->prefix}ds_tournaments",
					[ 'status' => $new_status ],
					[ 'id'     => $tid ],
					[ '%s' ],
					[ '%d' ]
				);
				$notice = 'status_updated';
			}
		}

		// Crear torneo.
		if ( $_SERVER['REQUEST_METHOD'] === 'POST' && isset( $_POST['st_create_tournament'] ) ) {
			check_admin_referer( 'st_create_tournament' );
			$name = sanitize_text_field( $_POST['name'] ?? '' );

			if ( $name ) {
				$raw_weekday = absint( $_POST['match_weekday'] ?? 6 );
				$match_weekday = ( $raw_weekday >= 0 && $raw_weekday <= 6 ) ? $raw_weekday : 6;
				$raw_time    = sanitize_text_field( $_POST['match_time'] ?? '19:00' );
				$match_time  = preg_match( '/^\d{1,2}:\d{2}$/', $raw_time ) ? $raw_time . ':00' : '19:00:00';
				$reg_mode    = sanitize_key( $_POST['registration_mode'] ?? 'deferred' );
				$reg_mode    = in_array( $reg_mode, [ 'realtime', 'deferred' ], true ) ? $reg_mode : 'deferred';
				$release_days = max( -7, min( 30, (int) ( $_POST['fixture_release_days'] ?? 0 ) ) );

				$wpdb->insert( // phpcs:ignore
					"{$wpdb->prefix}ds_tournaments",
					[
						'name'                 => $name,
						'season'               => sanitize_text_field( $_POST['season'] ?? gmdate( 'Y' ) ),
						'start_date'           => sanitize_text_field( $_POST['start_date'] ?? '' ) ?: null,
						'end_date'             => sanitize_text_field( $_POST['end_date'] ?? '' ) ?: null,
						'format'               => sanitize_text_field( $_POST['format'] ?? 'round_robin' ),
						'status'               => 'draft',
						'match_weekday'        => $match_weekday,
						'match_time'           => $match_time,
						'registration_mode'    => $reg_mode,
						'fixture_release_days' => $release_days,
					],
					[ '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%d' ]
				);
				$notice = 'created';
			}
		}

		$tournaments = $wpdb->get_results( // phpcs:ignore
			"SELECT t.*, COUNT(DISTINCT eq.id) AS team_count, COUNT(DISTINCT m.id) AS match_count
			 FROM {$wpdb->prefix}ds_tournaments t
			 LEFT JOIN {$wpdb->prefix}ds_teams eq ON eq.tournament_id = t.id
			 LEFT JOIN {$wpdb->prefix}ds_matches m ON m.tournament_id = t.id
			 GROUP BY t.id
			 ORDER BY t.id DESC",
			ARRAY_A
		);

		$page_title = __( 'Torneos', 'soccertrack' );
		self::render( 'torneos', compact( 'tournaments', 'notice', 'page_title' ) );
	}

	private static function view_torneo( int $id ): void {
		global $wpdb;

		if ( ! $id || ! current_user_can( 'ds_manage_tournaments' ) ) {
			wp_safe_redirect( home_url( '/panel/torneos/' ) );
			exit;
		}

		$tournament = $wpdb->get_row( // phpcs:ignore
			$wpdb->prepare( "SELECT * FROM {$wpdb->prefix}ds_tournaments WHERE id = %d", $id ),
			ARRAY_A
		);

		if ( ! $tournament ) {
			wp_die( esc_html__( 'Torneo no encontrado.', 'soccertrack' ), '', [ 'response' => 404 ] );
		}

		// ── Cambiar estado de un partido ─────────────────────────────────
		$notice = '';
		$error  = '';
		if ( $_SERVER['REQUEST_METHOD'] === 'POST' && isset( $_POST['st_change_match_status'] ) ) {
			check_admin_referer( 'st_change_match_status_' . $id );

			$match_id   = absint( $_POST['match_id'] ?? 0 );
			$new_status = sanitize_key( $_POST['new_status'] ?? '' );
			$allowed    = [ 'scheduled', 'in_progress', 'finished', 'suspended', 'postponed' ];

			if ( $match_id && in_array( $new_status, $allowed, true ) ) {
				$wpdb->update( // phpcs:ignore
					"{$wpdb->prefix}ds_matches",
					[ 'status' => $new_status ],
					[ 'id' => $match_id, 'tournament_id' => $id ],
					[ '%s' ],
					[ '%d', '%d' ]
				);

				// Recalcular tabla y limpiar caché al finalizar o reabrir.
				if ( in_array( $new_status, [ 'finished', 'scheduled' ], true ) ) {
					( new \SportsLeague\Core\StandingsCalculator() )->recalculate( $id );
					\SportsLeague\RestApi\PublicEndpoints::invalidate_cache( $id );
				}

				$notice = 'match_status_updated';
			}
		}

		// ── Guardar / subir bases PDF ─────────────────────────────────────
		if ( $_SERVER['REQUEST_METHOD'] === 'POST' && isset( $_POST['st_save_bases'] ) ) {
			check_admin_referer( 'st_save_bases_' . $id );

			$pdf_url = esc_url_raw( trim( $_POST['bases_pdf_url'] ?? '' ) );

			// Si hay archivo subido, procesarlo con wp_handle_upload().
			if ( ! empty( $_FILES['bases_pdf_file']['name'] ) ) {
				// Carga las funciones de upload de WP si no están disponibles.
				if ( ! function_exists( 'wp_handle_upload' ) ) {
					require_once ABSPATH . 'wp-admin/includes/file.php';
				}

				$file      = $_FILES['bases_pdf_file'];
				$mime_type = $file['type'] ?? '';

				if ( ! in_array( $mime_type, [ 'application/pdf', 'application/x-pdf' ], true )
					&& ! str_ends_with( strtolower( $file['name'] ), '.pdf' ) ) {
					$error = __( 'Solo se permiten archivos PDF.', 'soccertrack' );
				} else {
					add_filter( 'upload_mimes', static function ( $mimes ) {
						$mimes['pdf'] = 'application/pdf';
						return $mimes;
					} );

					$uploaded = wp_handle_upload( $file, [ 'test_form' => false ] );

					if ( isset( $uploaded['error'] ) ) {
						$error = $uploaded['error'];
					} elseif ( isset( $uploaded['url'] ) ) {
						$pdf_url = esc_url_raw( $uploaded['url'] );
					}
				}
			}

			if ( ! $error ) {
				$wpdb->update( // phpcs:ignore
					"{$wpdb->prefix}ds_tournaments",
					[ 'bases_pdf_url' => $pdf_url ?: null ],
					[ 'id' => $id ],
					[ '%s' ],
					[ '%d' ]
				);
				$notice = 'bases_saved';
			}

			// Refrescar datos del torneo.
			$tournament = $wpdb->get_row( // phpcs:ignore
				$wpdb->prepare( "SELECT * FROM {$wpdb->prefix}ds_tournaments WHERE id = %d", $id ),
				ARRAY_A
			);
		}

		$teams = $wpdb->get_results( // phpcs:ignore
			$wpdb->prepare(
				"SELECT t.*, COUNT(tp.player_id) AS player_count
				 FROM {$wpdb->prefix}ds_teams t
				 LEFT JOIN {$wpdb->prefix}ds_team_players tp ON tp.team_id = t.id
				 WHERE t.tournament_id = %d
				 GROUP BY t.id ORDER BY t.name ASC",
				$id
			),
			ARRAY_A
		);

		// ── Asignar / reasignar árbitro desde el fixture ─────────────────────
		if ( $_SERVER['REQUEST_METHOD'] === 'POST' && isset( $_POST['st_update_referee'] ) ) {
			check_admin_referer( 'st_update_referee_' . $id );

			if ( ! current_user_can( 'ds_manage_tournaments' ) ) {
				wp_die( esc_html__( 'Sin permiso.', 'soccertrack' ), '', [ 'response' => 403 ] );
			}

			$match_id   = absint( $_POST['match_id'] ?? 0 );
			$referee_id = absint( $_POST['referee_user_id'] ?? 0 );

			$existing_match = $wpdb->get_row( // phpcs:ignore
				$wpdb->prepare(
					"SELECT id, status FROM {$wpdb->prefix}ds_matches WHERE id = %d AND tournament_id = %d",
					$match_id, $id
				),
				ARRAY_A
			);

			if ( ! $existing_match ) {
				$error = __( 'Partido no encontrado.', 'soccertrack' );
			} elseif ( 'finished' === $existing_match['status'] ) {
				$error = __( 'No se puede reasignar el árbitro de un partido finalizado.', 'soccertrack' );
			} else {
				if ( $referee_id > 0 ) {
					$ref_user = get_user_by( 'id', $referee_id );
					if ( ! $ref_user || ! $ref_user->has_cap( 'ds_enter_match_incidents' ) ) {
						$error = __( 'Usuario no válido o sin permisos de árbitro.', 'soccertrack' );
					}
				}

				if ( empty( $error ) && $referee_id > 0 ) {
					// Verificar que el árbitro no tenga otro partido en el mismo horario (±90 min).
					$match_dt = $wpdb->get_var( // phpcs:ignore
						$wpdb->prepare( "SELECT match_datetime FROM {$wpdb->prefix}ds_matches WHERE id = %d", $match_id )
					);
					if ( $match_dt ) {
						$conflict_ref = (int) $wpdb->get_var( // phpcs:ignore
							$wpdb->prepare(
								"SELECT COUNT(*) FROM {$wpdb->prefix}ds_matches
								 WHERE referee_user_id = %d AND id != %d
								   AND match_datetime BETWEEN DATE_SUB( %s, INTERVAL 90 MINUTE )
								                          AND DATE_ADD( %s, INTERVAL 90 MINUTE )",
								$referee_id, $match_id, $match_dt, $match_dt
							)
						);
						if ( $conflict_ref > 0 ) {
							$error = __( 'El árbitro ya tiene un partido asignado en ese horario (conflicto de menos de 90 minutos).', 'soccertrack' );
						}
					}
				}

				if ( empty( $error ) ) {
					$wpdb->update( // phpcs:ignore
						"{$wpdb->prefix}ds_matches",
						[ 'referee_user_id' => $referee_id ?: null ],
						[ 'id' => $match_id ],
						[ '%d' ],
						[ '%d' ]
					);
					$notice = 'referee_updated';
				}
			}
		}

		// ── Asignar / reasignar planillero desde el fixture ─────────────────
		if ( $_SERVER['REQUEST_METHOD'] === 'POST' && isset( $_POST['st_update_planillero'] ) ) {
			check_admin_referer( 'st_update_planillero_' . $id );

			if ( ! current_user_can( 'ds_manage_tournaments' ) ) {
				wp_die( esc_html__( 'Sin permiso.', 'soccertrack' ), '', [ 'response' => 403 ] );
			}

			$match_id      = absint( $_POST['match_id'] ?? 0 );
			$planillero_id = absint( $_POST['planillero_user_id'] ?? 0 );

			$existing_match = $wpdb->get_row( // phpcs:ignore
				$wpdb->prepare(
					"SELECT id, status FROM {$wpdb->prefix}ds_matches WHERE id = %d AND tournament_id = %d",
					$match_id, $id
				),
				ARRAY_A
			);

			if ( ! $existing_match ) {
				$error = __( 'Partido no encontrado.', 'soccertrack' );
			} elseif ( 'finished' === $existing_match['status'] ) {
				$error = __( 'No se puede reasignar el planillero de un partido finalizado.', 'soccertrack' );
			} else {
				if ( $planillero_id > 0 ) {
					$plan_user = get_user_by( 'id', $planillero_id );
					if ( ! $plan_user || ! $plan_user->has_cap( 'ds_enter_match_incidents' ) ) {
						$error = __( 'Usuario no válido o sin permisos de planillero.', 'soccertrack' );
					}
				}

				if ( empty( $error ) && $planillero_id > 0 ) {
					$match_dt = $wpdb->get_var( // phpcs:ignore
						$wpdb->prepare( "SELECT match_datetime FROM {$wpdb->prefix}ds_matches WHERE id = %d", $match_id )
					);
					if ( $match_dt ) {
						$conflict_plan = (int) $wpdb->get_var( // phpcs:ignore
							$wpdb->prepare(
								"SELECT COUNT(*) FROM {$wpdb->prefix}ds_matches
								 WHERE planillero_user_id = %d AND id != %d
								   AND match_datetime BETWEEN DATE_SUB( %s, INTERVAL 90 MINUTE )
								                         AND DATE_ADD( %s, INTERVAL 90 MINUTE )",
								$planillero_id, $match_id, $match_dt, $match_dt
							)
						);
						if ( $conflict_plan > 0 ) {
							$error = __( 'El planillero ya tiene un partido asignado en ese horario (conflicto de menos de 90 minutos).', 'soccertrack' );
						}
					}
				}

				if ( empty( $error ) ) {
					$wpdb->update( // phpcs:ignore
						"{$wpdb->prefix}ds_matches",
						[ 'planillero_user_id' => $planillero_id ?: null ],
						[ 'id' => $match_id ],
						[ '%d' ],
						[ '%d' ]
					);
					$notice  = 'planillero_updated';
				}
			}
		}

		// ── Reasignar cancha a un partido ────────────────────────────────────
		if ( $_SERVER['REQUEST_METHOD'] === 'POST' && isset( $_POST['st_update_court'] ) ) {
			check_admin_referer( 'st_update_court_' . $id );

			$match_id  = absint( $_POST['match_id'] ?? 0 );
			$new_court = absint( $_POST['court_id'] ?? 0 );

			$existing_match = $wpdb->get_row( // phpcs:ignore
				$wpdb->prepare(
					"SELECT id, court_id, match_datetime, status FROM {$wpdb->prefix}ds_matches WHERE id = %d AND tournament_id = %d",
					$match_id, $id
				),
				ARRAY_A
			);

			if ( ! $existing_match ) {
				$error = __( 'Partido no encontrado.', 'soccertrack' );
			} elseif ( 'finished' === $existing_match['status'] ) {
				$error = __( 'No se puede reasignar la cancha de un partido finalizado.', 'soccertrack' );
			} else {
				// Verificar que la nueva cancha no tenga conflicto de horario.
				$conflict = (int) $wpdb->get_var( // phpcs:ignore
					$wpdb->prepare(
						"SELECT COUNT(*) FROM {$wpdb->prefix}ds_matches
						 WHERE court_id = %d AND id != %d
						   AND match_datetime BETWEEN DATE_SUB( %s, INTERVAL 90 MINUTE )
						                         AND DATE_ADD( %s, INTERVAL 90 MINUTE )",
						$new_court, $match_id, $existing_match['match_datetime'], $existing_match['match_datetime']
					)
				);

				if ( $conflict > 0 ) {
					$error = __( 'La cancha seleccionada ya tiene un partido en ese horario (conflicto de menos de 90 minutos).', 'soccertrack' );
				} else {
					$wpdb->update( "{$wpdb->prefix}ds_matches", [ 'court_id' => $new_court ], [ 'id' => $match_id ], [ '%d' ], [ '%d' ] ); // phpcs:ignore
					$notice = 'court_updated';
				}
			}
		}

		// ── Actualizar fecha/hora de un partido ──────────────────────────────
		if ( $_SERVER['REQUEST_METHOD'] === 'POST' && isset( $_POST['st_update_datetime'] ) ) {
			check_admin_referer( 'st_update_datetime_' . $id );

			$match_id    = absint( $_POST['match_id'] ?? 0 );
			$raw_dt      = sanitize_text_field( $_POST['match_datetime'] ?? '' );

			// Convertir formato datetime-local (YYYY-MM-DDTHH:MM) → MySQL (YYYY-MM-DD HH:MM:SS).
			$dt_obj = \DateTime::createFromFormat( 'Y-m-d\TH:i', $raw_dt );

			if ( ! $dt_obj ) {
				$error = __( 'Formato de fecha/hora no válido.', 'soccertrack' );
			} else {
				$new_datetime = $dt_obj->format( 'Y-m-d H:i:s' );

				$existing_match = $wpdb->get_row( // phpcs:ignore
					$wpdb->prepare(
						"SELECT id, court_id, status FROM {$wpdb->prefix}ds_matches WHERE id = %d AND tournament_id = %d",
						$match_id, $id
					),
					ARRAY_A
				);

				if ( ! $existing_match ) {
					$error = __( 'Partido no encontrado.', 'soccertrack' );
				} elseif ( 'finished' === $existing_match['status'] ) {
					$error = __( 'No se puede modificar la fecha de un partido finalizado.', 'soccertrack' );
				} elseif (
					! empty( $existing_match['match_datetime'] ) &&
					( strtotime( $existing_match['match_datetime'] ) - time() ) < HOUR_IN_SECONDS
				) {
					$error = __( 'No se puede modificar el horario con menos de 1 hora de anticipación.', 'soccertrack' );
				} else {
					// Verificar conflicto de cancha con el nuevo horario (si tiene cancha asignada).
					$court_id = (int) ( $existing_match['court_id'] ?? 0 );
					if ( $court_id > 0 ) {
						$conflict = (int) $wpdb->get_var( // phpcs:ignore
							$wpdb->prepare(
								"SELECT COUNT(*) FROM {$wpdb->prefix}ds_matches
								 WHERE court_id = %d AND id != %d
								   AND match_datetime BETWEEN DATE_SUB( %s, INTERVAL 90 MINUTE )
								                         AND DATE_ADD( %s, INTERVAL 90 MINUTE )",
								$court_id, $match_id, $new_datetime, $new_datetime
							)
						);
						if ( $conflict > 0 ) {
							$error = __( 'La cancha ya tiene otro partido en ese horario (conflicto menor a 90 minutos).', 'soccertrack' );
						}
					}

					if ( ! $error ) {
						$wpdb->update( // phpcs:ignore
							"{$wpdb->prefix}ds_matches",
							[ 'match_datetime' => $new_datetime ],
							[ 'id' => $match_id ],
							[ '%s' ],
							[ '%d' ]
						);
						$notice = 'datetime_updated';
					}
				}
			}
		}

		// ── Auto-asignar árbitros y planilleros libres ───────────────────────
		if ( $_SERVER['REQUEST_METHOD'] === 'POST' && isset( $_POST['st_auto_assign'] ) ) {
			check_admin_referer( 'st_auto_assign_' . $id );

			if ( ! current_user_can( 'ds_manage_tournaments' ) ) {
				wp_die( esc_html__( 'Sin permiso.', 'soccertrack' ), '', [ 'response' => 403 ] );
			}

			$notice = 'auto_assigned';
			self::auto_assign_officials( $id );
		}

		// ── Actualizar parámetros de horario del torneo ──────────────────
		if ( $_SERVER['REQUEST_METHOD'] === 'POST' && isset( $_POST['st_update_schedule'] ) ) {
			check_admin_referer( 'st_update_schedule_' . $id );

			if ( ! current_user_can( 'ds_manage_tournaments' ) ) {
				wp_die( esc_html__( 'Sin permiso.', 'soccertrack' ), '', [ 'response' => 403 ] );
			}

			$raw_weekdays   = isset( $_POST['match_weekdays'] ) && is_array( $_POST['match_weekdays'] )
				? $_POST['match_weekdays']  // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
				: [];
			$match_weekdays = array_unique(
				array_filter(
					array_map( 'intval', $raw_weekdays ),
					fn( int $d ) => $d >= 0 && $d <= 6
				)
			);
			// Orden lunes-primero para que el fixture avance correctamente.
			usort( $match_weekdays, fn( int $a, int $b ) => ( ( $a + 6 ) % 7 ) - ( ( $b + 6 ) % 7 ) );
			if ( empty( $match_weekdays ) ) {
				$match_weekdays = [ 6 ]; // default: sábado.
			}
			$match_weekdays_json = wp_json_encode( array_values( $match_weekdays ) );

			$raw_time   = sanitize_text_field( $_POST['match_time'] ?? '19:00' );
			$match_time = preg_match( '/^\d{1,2}:\d{2}$/', $raw_time ) ? $raw_time . ':00' : '19:00:00';

			$wpdb->update( // phpcs:ignore
				"{$wpdb->prefix}ds_tournaments",
				[
					'match_weekday'  => $match_weekdays[0], // mantener columna legada.
					'match_weekdays' => $match_weekdays_json,
					'match_time'     => $match_time,
				],
				[ 'id' => $id ],
				[ '%d', '%s', '%s' ],
				[ '%d' ]
			);
			$notice = 'schedule_updated';

			// Refrescar datos del torneo.
			$tournament = $wpdb->get_row(
				$wpdb->prepare( "SELECT * FROM {$wpdb->prefix}ds_tournaments WHERE id = %d", $id ),
				ARRAY_A
			);
		}

		// ── Actualizar modo de registro ───────────────────────────────────
		if ( $_SERVER['REQUEST_METHOD'] === 'POST' && isset( $_POST['st_update_reg_mode'] ) ) {
			check_admin_referer( 'st_update_reg_mode_' . $id );

			if ( ! current_user_can( 'ds_manage_tournaments' ) ) {
				wp_die( esc_html__( 'Sin permiso.', 'soccertrack' ), '', [ 'response' => 403 ] );
			}

			$reg_mode = sanitize_key( $_POST['registration_mode'] ?? 'deferred' );
			$reg_mode = in_array( $reg_mode, [ 'realtime', 'deferred' ], true ) ? $reg_mode : 'deferred';

			$wpdb->update( // phpcs:ignore
				"{$wpdb->prefix}ds_tournaments",
				[ 'registration_mode' => $reg_mode ],
				[ 'id' => $id ],
				[ '%s' ],
				[ '%d' ]
			);
			$notice = 'reg_mode_updated';

			$tournament = $wpdb->get_row(
				$wpdb->prepare( "SELECT * FROM {$wpdb->prefix}ds_tournaments WHERE id = %d", $id ),
				ARRAY_A
			);
		}

		// ── Actualizar días de liberación del fixture ─────────────────────
		if ( $_SERVER['REQUEST_METHOD'] === 'POST' && isset( $_POST['st_update_release_days'] ) ) {
			check_admin_referer( 'st_update_release_days_' . $id );

			if ( ! current_user_can( 'ds_manage_tournaments' ) ) {
				wp_die( esc_html__( 'Sin permiso.', 'soccertrack' ), '', [ 'response' => 403 ] );
			}

			$release_days = max( -7, min( 30, (int) ( $_POST['fixture_release_days'] ?? 0 ) ) );

			$wpdb->update( // phpcs:ignore
				"{$wpdb->prefix}ds_tournaments",
				[ 'fixture_release_days' => $release_days ],
				[ 'id' => $id ],
				[ '%d' ],
				[ '%d' ]
			);
			$notice = 'release_days_updated';

			$tournament = $wpdb->get_row(
				$wpdb->prepare( "SELECT * FROM {$wpdb->prefix}ds_tournaments WHERE id = %d", $id ),
				ARRAY_A
			);
		}

		$venues = $wpdb->get_results( // phpcs:ignore
			"SELECT id, name FROM {$wpdb->prefix}ds_venues ORDER BY name ASC",
			ARRAY_A
		);

		$matches = $wpdb->get_results( // phpcs:ignore
			$wpdb->prepare(
				"SELECT m.id, m.round_number, m.home_team_id, m.away_team_id, m.venue_id, m.court_id,
				        m.referee_user_id, m.planillero_user_id, m.match_datetime, m.home_score, m.away_score, m.status,
				        COALESCE(m.phase, 'regular') AS phase,
				        ht.name AS home_team, at.name AS away_team
				 FROM {$wpdb->prefix}ds_matches m
				 JOIN {$wpdb->prefix}ds_teams ht ON ht.id = m.home_team_id
				 JOIN {$wpdb->prefix}ds_teams at ON at.id = m.away_team_id
				 WHERE m.tournament_id = %d
				 ORDER BY m.round_number ASC, m.match_datetime ASC
				 LIMIT 200",
				$id
			),
			ARRAY_A
		);

		$courts_by_venue = [];
		if ( ! empty( $matches ) ) {
			$venue_ids = array_values( array_filter( array_unique( array_map( 'intval', array_column( $matches, 'venue_id' ) ) ) ) );
			if ( ! empty( $venue_ids ) ) {
				$placeholders = implode( ', ', array_fill( 0, count( $venue_ids ), '%d' ) );
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared
				$court_rows = $wpdb->get_results(
					$wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
						"SELECT id, venue_id, court_name FROM {$wpdb->prefix}ds_courts WHERE venue_id IN ({$placeholders}) ORDER BY venue_id ASC, id ASC",
						...$venue_ids
					),
					ARRAY_A
				);
				foreach ( $court_rows as $row ) {
					$courts_by_venue[ (int) $row['venue_id'] ][] = [
						'id'         => $row['id'],
						'court_name' => $row['court_name'],
					];
				}
			}
		}

		$referees = get_users( [
			'role__in' => [ 'ds_arbitro' ],
			'orderby'  => 'display_name',
			'order'    => 'ASC',
			'fields'   => [ 'ID', 'display_name' ],
		] );

		$planilleros = get_users( [
			'role__in' => [ 'ds_planillero' ],
			'orderby'  => 'display_name',
			'order'    => 'ASC',
			'fields'   => [ 'ID', 'display_name' ],
		] );

		$page_title = esc_html( $tournament['name'] );

		// ── Estado play-offs (para mostrar/ocultar botones en template) ─────────
		$is_playoffs_format = in_array( $tournament['format'] ?? '', [ 'round_robin_playoffs' ], true );
		$regular_matches    = array_filter( $matches, static fn( $m ) => ( $m['phase'] ?? 'regular' ) === 'regular' );
		$all_regular_done   = ! empty( $regular_matches ) && count( array_filter( $regular_matches, static fn( $m ) => $m['status'] !== 'finished' ) ) === 0;
		$sf_matches         = array_filter( $matches, static fn( $m ) => ( $m['phase'] ?? '' ) === 'semifinal' );
		$has_semifinals     = ! empty( $sf_matches );
		$both_sf_done       = $has_semifinals && count( array_filter( $sf_matches, static fn( $m ) => $m['status'] !== 'finished' ) ) === 0;
		$has_finals         = ! empty( array_filter( $matches, static fn( $m ) => in_array( $m['phase'] ?? '', [ 'final', 'third_place' ], true ) ) );

		$playoffs_status = compact( 'is_playoffs_format', 'all_regular_done', 'has_semifinals', 'both_sf_done', 'has_finals' );

		self::render( 'torneo-detalle', compact( 'tournament', 'teams', 'matches', 'notice', 'error', 'venues', 'courts_by_venue', 'referees', 'planilleros', 'page_title', 'playoffs_status' ) );
	}

	/**
	 * Auto-asigna árbitros y planilleros disponibles a partidos sin asignar del torneo.
	 * Solo actúa sobre partidos con match_datetime y sin árbitro/planillero.
	 * Restricción: un oficial no puede cubrir dos partidos con menos de 90 min entre sí.
	 */
	private static function auto_assign_officials( int $tournament_id ): void {
		global $wpdb;

		$matches = $wpdb->get_results( // phpcs:ignore
			$wpdb->prepare(
				"SELECT id, match_datetime, referee_user_id, planillero_user_id
				 FROM {$wpdb->prefix}ds_matches
				 WHERE tournament_id = %d
				   AND match_datetime IS NOT NULL
				   AND status NOT IN ('finished','suspended')
				 ORDER BY match_datetime ASC, id ASC",
				$tournament_id
			),
			ARRAY_A
		);

		if ( empty( $matches ) ) {
			return;
		}

		$referee_ids = array_column(
			get_users( [ 'role__in' => [ 'ds_arbitro' ], 'fields' => [ 'ID' ] ] ),
			'ID'
		);

		$planillero_ids = array_column(
			get_users( [ 'role__in' => [ 'ds_planillero' ], 'fields' => [ 'ID' ] ] ),
			'ID'
		);

		// Pre-cargar asignaciones actuales para detectar conflictos en PHP (evita N+1).
		$assigned_ref_slots  = []; // referee_user_id  => int[] timestamps
		$assigned_plan_slots = []; // planillero_user_id => int[] timestamps

		// Los partidos finished/suspended no bloquean reasignación (intencional:
		// un partido suspendido no debe impedir usar al árbitro en otra fecha).
		$existing_assignments = $wpdb->get_results( // phpcs:ignore
			$wpdb->prepare(
				"SELECT referee_user_id, planillero_user_id, match_datetime
				 FROM {$wpdb->prefix}ds_matches
				 WHERE tournament_id = %d
				   AND status NOT IN ('finished', 'suspended')
				   AND ( referee_user_id IS NOT NULL OR planillero_user_id IS NOT NULL )",
				$tournament_id
			),
			ARRAY_A
		) ?: [];

		foreach ( $existing_assignments as $row ) {
			$ts = strtotime( $row['match_datetime'] );
			if ( $row['referee_user_id'] ) {
				$assigned_ref_slots[ (int) $row['referee_user_id'] ][] = $ts;
			}
			if ( $row['planillero_user_id'] ) {
				$assigned_plan_slots[ (int) $row['planillero_user_id'] ][] = $ts;
			}
		}

		$ninety       = 90 * 60;
		$has_conflict = static function ( array $slots, int $ts ) use ( $ninety ): bool {
			foreach ( $slots as $slot_ts ) {
				if ( abs( $ts - $slot_ts ) < $ninety ) {
					return true;
				}
			}
			return false;
		};

		foreach ( $matches as &$match ) {
			$dt       = $match['match_datetime'];
			$match_id = (int) $match['id'];
			$ts       = strtotime( $dt );

			// ── Asignar árbitro si falta ──────────────────────────────────
			if ( ! $match['referee_user_id'] && ! empty( $referee_ids ) ) {
				foreach ( $referee_ids as $rid ) {
					if ( ! $has_conflict( $assigned_ref_slots[ (int) $rid ] ?? [], $ts ) ) {
						$wpdb->update( // phpcs:ignore
							"{$wpdb->prefix}ds_matches",
							[ 'referee_user_id' => $rid ],
							[ 'id' => $match_id ],
							[ '%d' ],
							[ '%d' ]
						);
						$match['referee_user_id']            = $rid;
						$assigned_ref_slots[ (int) $rid ][] = $ts;
						break;
					}
				}
			}

			// ── Asignar planillero si falta ───────────────────────────────
			if ( ! $match['planillero_user_id'] && ! empty( $planillero_ids ) ) {
				$assigned_ref = (int) ( $match['referee_user_id'] ?? 0 );
				foreach ( $planillero_ids as $pid ) {
					if ( $pid === $assigned_ref ) {
						continue; // No puede ser el mismo que el árbitro.
					}
					if ( ! $has_conflict( $assigned_plan_slots[ (int) $pid ] ?? [], $ts ) ) {
						$wpdb->update( // phpcs:ignore
							"{$wpdb->prefix}ds_matches",
							[ 'planillero_user_id' => $pid ],
							[ 'id' => $match_id ],
							[ '%d' ],
							[ '%d' ]
						);
						$match['planillero_user_id']          = $pid;
						$assigned_plan_slots[ (int) $pid ][] = $ts;
						break;
					}
				}
			}
		}
		unset( $match );
	}

	private static function view_equipo( int $id ): void {
		global $wpdb;

		if ( ! $id || ! current_user_can( 'ds_manage_tournaments' ) ) {
			wp_safe_redirect( home_url( '/panel/torneos/' ) );
			exit;
		}

		$team = $wpdb->get_row( // phpcs:ignore
			$wpdb->prepare( "SELECT t.*, tr.name AS tournament_name, tr.id AS tournament_id FROM {$wpdb->prefix}ds_teams t JOIN {$wpdb->prefix}ds_tournaments tr ON tr.id = t.tournament_id WHERE t.id = %d", $id ),
			ARRAY_A
		);

		if ( ! $team ) {
			wp_die( esc_html__( 'Equipo no encontrado.', 'soccertrack' ), '', [ 'response' => 404 ] );
		}

		$notice = '';
		$error  = '';

		// ── Alta individual de jugador ────────────────────────────────────
		if ( $_SERVER['REQUEST_METHOD'] === 'POST' && isset( $_POST['st_add_player'] ) ) {
			check_admin_referer( 'st_add_player_' . $id );

			$rut      = sanitize_text_field( $_POST['rut_id'] ?? '' );
			$nombre   = sanitize_text_field( $_POST['first_name'] ?? '' );
			$apellido = sanitize_text_field( $_POST['last_name'] ?? '' );
			$dorsal   = absint( $_POST['dorsal'] ?? 0 );

			if ( ! $rut || ! $nombre || ! $apellido || ! $dorsal ) {
				$error = __( 'Todos los campos son obligatorios.', 'soccertrack' );
			} elseif ( $dorsal < 1 || $dorsal > 99 ) {
				$error = __( 'El dorsal debe estar entre 1 y 99.', 'soccertrack' );
			} else {
				// Verificar dorsal no tomado en este equipo.
				$dorsal_taken = $wpdb->get_var( // phpcs:ignore
					$wpdb->prepare(
						"SELECT COUNT(*) FROM {$wpdb->prefix}ds_team_players WHERE team_id = %d AND dorsal = %d",
						$id, $dorsal
					)
				);

				if ( $dorsal_taken ) {
					$error = sprintf( __( 'El dorsal %d ya está asignado en este equipo.', 'soccertrack' ), $dorsal );
				} else {
					// Buscar o crear jugador por RUT.
					$player_id = (int) $wpdb->get_var( // phpcs:ignore
						$wpdb->prepare( "SELECT id FROM {$wpdb->prefix}ds_players WHERE rut_id = %s", $rut )
					);

					if ( ! $player_id ) {
						$wpdb->insert( // phpcs:ignore
							"{$wpdb->prefix}ds_players",
							[ 'rut_id' => $rut, 'first_name' => $nombre, 'last_name' => $apellido ],
							[ '%s', '%s', '%s' ]
						);
						$player_id = (int) $wpdb->insert_id;
					}

					// Verificar que no esté ya inscrito en este equipo.
					$already = $wpdb->get_var( // phpcs:ignore
						$wpdb->prepare(
							"SELECT COUNT(*) FROM {$wpdb->prefix}ds_team_players WHERE team_id = %d AND player_id = %d",
							$id, $player_id
						)
					);

					if ( $already ) {
						$error = __( 'Este jugador ya está inscrito en el equipo.', 'soccertrack' );
					} else {
						// Verificar inscripción en otro equipo del mismo torneo.
						$conflict_team = $wpdb->get_var( // phpcs:ignore
							$wpdb->prepare(
								"SELECT t.name
								 FROM {$wpdb->prefix}ds_team_players tp
								 JOIN {$wpdb->prefix}ds_teams t ON t.id = tp.team_id
								 WHERE tp.player_id = %d AND t.tournament_id = %d AND tp.team_id != %d
								 LIMIT 1",
								$player_id,
								(int) $team['tournament_id'],
								$id
							)
						);

						$force = (bool) ( $_POST['st_force_inscription'] ?? false );
						$can_override = current_user_can( 'ds_manage_tournaments' );

						if ( $conflict_team && ! ( $force && $can_override ) ) {
							$error = sprintf(
								/* translators: 1: nombre del jugador, 2: nombre del equipo en conflicto */
								__( '%1$s ya está inscrito en "%2$s" dentro del mismo torneo. Solo un coordinador puede autorizar la excepción.', 'soccertrack' ),
								esc_html( "$nombre $apellido" ),
								esc_html( $conflict_team )
							);
							$notice = 'conflict';
						} else {
							$wpdb->insert( // phpcs:ignore
								"{$wpdb->prefix}ds_team_players",
								[ 'team_id' => $id, 'player_id' => $player_id, 'dorsal' => $dorsal ],
								[ '%d', '%d', '%d' ]
							);
							$notice = $conflict_team ? 'added_exception' : 'added';
						}
					}
				}
			}
		}

		// ── Eliminar jugador de la nómina ────────────────────────────────
		if ( $_SERVER['REQUEST_METHOD'] === 'POST' && isset( $_POST['st_remove_player'] ) ) {
			check_admin_referer( 'st_remove_player_' . $id );
			$tp_id = absint( $_POST['tp_id'] ?? 0 );
			if ( $tp_id ) {
				$wpdb->delete( "{$wpdb->prefix}ds_team_players", [ 'id' => $tp_id, 'team_id' => $id ], [ '%d', '%d' ] ); // phpcs:ignore
				$notice = 'removed';
			}
		}

		// ── Guardar logo del equipo ───────────────────────────────────────
		if ( $_SERVER['REQUEST_METHOD'] === 'POST' && isset( $_POST['st_save_logo'] ) ) {
			check_admin_referer( 'st_save_logo_' . $id );

			$logo_url = esc_url_raw( trim( $_POST['logo_url'] ?? '' ) );

			if ( ! empty( $_FILES['logo_file']['name'] ) ) {
				if ( ! function_exists( 'wp_handle_upload' ) ) {
					require_once ABSPATH . 'wp-admin/includes/file.php';
				}

				$file      = $_FILES['logo_file'];
				$allowed   = [ 'image/png', 'image/jpeg', 'image/svg+xml' ];
				$mime_type = mime_content_type( $file['tmp_name'] );

				if ( ! in_array( $mime_type, $allowed, true ) ) {
					$error = __( 'Solo se permiten imágenes PNG, JPG o SVG.', 'soccertrack' );
				} else {
					$uploaded = wp_handle_upload( $file, [ 'test_form' => false ] );

					if ( isset( $uploaded['error'] ) ) {
						$error = $uploaded['error'];
					} elseif ( isset( $uploaded['url'] ) ) {
						$logo_url = esc_url_raw( $uploaded['url'] );
					}
				}
			}

			if ( ! $error ) {
				$wpdb->update( // phpcs:ignore
					"{$wpdb->prefix}ds_teams",
					[ 'logo_url' => $logo_url ?: null ],
					[ 'id' => $id ],
					[ '%s' ],
					[ '%d' ]
				);
				$team['logo_url'] = $logo_url;
				$notice = 'logo_saved';
			}
		}

		$roster = $wpdb->get_results( // phpcs:ignore
			$wpdb->prepare(
				"SELECT tp.id AS tp_id, tp.dorsal, tp.is_suspended, p.id AS player_id, p.rut_id, p.first_name, p.last_name
				 FROM {$wpdb->prefix}ds_team_players tp
				 JOIN {$wpdb->prefix}ds_players p ON p.id = tp.player_id
				 WHERE tp.team_id = %d
				 ORDER BY tp.dorsal ASC",
				$id
			),
			ARRAY_A
		);

		$tournaments = $wpdb->get_results( // phpcs:ignore
			"SELECT id, name FROM {$wpdb->prefix}ds_tournaments ORDER BY id DESC",
			ARRAY_A
		);

		$page_title = esc_html( $team['name'] );
		self::render( 'equipo', compact( 'team', 'roster', 'notice', 'error', 'page_title', 'tournaments' ) );
	}

	private static function view_partido( int $id ): void {
		global $wpdb;

		// Árbitro/planillero/coordinador → puede ingresar incidentes. Delegado → solo lectura (RACI C).
		$can_edit = current_user_can( 'ds_enter_match_incidents' ) || current_user_can( 'ds_close_match' );

		if ( ! $id || ( ! $can_edit && ! current_user_can( 'ds_view_match_sheet' ) ) ) {
			wp_safe_redirect( home_url( '/panel/' ) );
			exit;
		}

		$match = $wpdb->get_row( // phpcs:ignore
			$wpdb->prepare(
				"SELECT m.*, v.name AS venue, c.court_name
				 FROM {$wpdb->prefix}ds_matches m
				 LEFT JOIN {$wpdb->prefix}ds_venues v ON v.id = m.venue_id
				 LEFT JOIN {$wpdb->prefix}ds_courts c ON c.id = m.court_id
				 WHERE m.id = %d",
				$id
			),
			ARRAY_A
		);

		if ( ! $match ) {
			wp_die( esc_html__( 'Partido no encontrado.', 'soccertrack' ), '', [ 'response' => 404 ] );
		}

		$notice_ref  = '';
		$error_ref   = '';
		$notice_plan = '';
		$error_plan  = '';

		// Procesar asignación de árbitro.
		if ( $_SERVER['REQUEST_METHOD'] === 'POST' && isset( $_POST['st_save_referee'] ) ) {
			check_admin_referer( 'st_save_referee_' . $id );

			if ( ! current_user_can( 'ds_manage_tournaments' ) ) {
				wp_die( esc_html__( 'Sin permiso.', 'soccertrack' ), '', [ 'response' => 403 ] );
			}

			$referee_id = absint( $_POST['referee_user_id'] ?? 0 );

			if ( $referee_id > 0 ) {
				$ref_user = get_user_by( 'id', $referee_id );
				if ( ! $ref_user || ! $ref_user->has_cap( 'ds_enter_match_incidents' ) ) {
					$error_ref = __( 'Usuario no válido o sin permisos de árbitro.', 'soccertrack' );
				}

				// Verificar que el árbitro no tenga otro partido en el mismo horario (±90 min).
				if ( ! $error_ref && ! empty( $match['match_datetime'] ) ) {
					$conflict_ref = (int) $wpdb->get_var( // phpcs:ignore
						$wpdb->prepare(
							"SELECT COUNT(*) FROM {$wpdb->prefix}ds_matches
							 WHERE referee_user_id = %d AND id != %d
							   AND match_datetime BETWEEN DATE_SUB( %s, INTERVAL 90 MINUTE )
							                         AND DATE_ADD( %s, INTERVAL 90 MINUTE )",
							$referee_id, $id, $match['match_datetime'], $match['match_datetime']
						)
					);
					if ( $conflict_ref > 0 ) {
						$error_ref = __( 'El árbitro ya tiene un partido asignado en ese horario (conflicto de menos de 90 minutos).', 'soccertrack' );
					}
				}
			}

			if ( ! $error_ref ) {
				$wpdb->update( // phpcs:ignore
					"{$wpdb->prefix}ds_matches",
					[ 'referee_user_id' => $referee_id ?: null ],
					[ 'id' => $id ],
					[ '%d' ],
					[ '%d' ]
				);
				$match['referee_user_id'] = $referee_id;
				$notice_ref = 'referee_saved';
			}
		}

		// Procesar asignación de planillero.
		if ( $_SERVER['REQUEST_METHOD'] === 'POST' && isset( $_POST['st_save_planillero'] ) ) {
			check_admin_referer( 'st_save_planillero_' . $id );

			if ( ! current_user_can( 'ds_manage_tournaments' ) ) {
				wp_die( esc_html__( 'Sin permiso.', 'soccertrack' ), '', [ 'response' => 403 ] );
			}

			$planillero_id = absint( $_POST['planillero_user_id'] ?? 0 );

			if ( $planillero_id > 0 ) {
				$plan_user = get_user_by( 'id', $planillero_id );
				if ( ! $plan_user || ! $plan_user->has_cap( 'ds_enter_match_incidents' ) ) {
					$error_plan = __( 'Usuario no válido o sin permisos de planillero.', 'soccertrack' );
				}

				// Verificar conflicto de horario (±120 min).
				if ( ! $error_plan && ! empty( $match['match_datetime'] ) ) {
					$conflict_plan = (int) $wpdb->get_var( // phpcs:ignore
						$wpdb->prepare(
							"SELECT COUNT(*) FROM {$wpdb->prefix}ds_matches
							 WHERE planillero_user_id = %d AND id != %d
							   AND status NOT IN ('finished','suspended')
							   AND match_datetime BETWEEN DATE_SUB( %s, INTERVAL 120 MINUTE )
							                         AND DATE_ADD( %s, INTERVAL 120 MINUTE )",
							$planillero_id, $id, $match['match_datetime'], $match['match_datetime']
						)
					);
					if ( $conflict_plan > 0 ) {
						$error_plan = __( 'El planillero ya está asignado a otro partido en ese horario.', 'soccertrack' );
					}
				}
			}

			if ( ! $error_plan ) {
				$updated = $wpdb->update( // phpcs:ignore
					"{$wpdb->prefix}ds_matches",
					[ 'planillero_user_id' => $planillero_id ?: null ],
					[ 'id' => $id ],
					[ '%d' ],
					[ '%d' ]
				);
				if ( false === $updated ) {
					// false = error de SQL (p.ej. columna inexistente).
					$error_plan = __( 'Error al guardar el planillero. Contacta al administrador.', 'soccertrack' );
				} else {
					// 0 = sin cambio (mismo valor), > 0 = actualizado — ambos son éxito.
					$match['planillero_user_id'] = $planillero_id;
					$notice_plan = 'planillero_saved';
				}
			}
		}

		// ── Guardar nombres de árbitro y planillero (modo deferred) ──────────
		$notice_staff = '';
		$error_staff  = '';
		if ( $_SERVER['REQUEST_METHOD'] === 'POST' && isset( $_POST['st_save_staff_names'] ) ) {
			check_admin_referer( 'st_save_staff_names_' . $id );

			if ( ! current_user_can( 'ds_manage_tournaments' ) ) {
				wp_die( esc_html__( 'Sin permiso.', 'soccertrack' ), '', [ 'response' => 403 ] );
			}

			$ref_name  = sanitize_text_field( $_POST['referee_name']    ?? '' );
			$plan_name = sanitize_text_field( $_POST['planillero_name'] ?? '' );

			$updated = $wpdb->update( // phpcs:ignore
				"{$wpdb->prefix}ds_matches",
				[ 'referee_name' => $ref_name ?: null, 'planillero_name' => $plan_name ?: null ],
				[ 'id' => $id ],
				[ '%s', '%s' ],
				[ '%d' ]
			);
			if ( false === $updated ) {
				$error_staff = __( 'Error al guardar los nombres.', 'soccertrack' );
			} else {
				$notice_staff = 'staff_saved';
			}

			$match = $wpdb->get_row( // phpcs:ignore
				$wpdb->prepare(
					"SELECT m.*, v.name AS venue, c.court_name
					 FROM {$wpdb->prefix}ds_matches m
					 LEFT JOIN {$wpdb->prefix}ds_venues v ON v.id = m.venue_id
					 LEFT JOIN {$wpdb->prefix}ds_courts c ON c.id = m.court_id
					 WHERE m.id = %d",
					$id
				),
				ARRAY_A
			);
		}

		$referees = get_users( [
			'role__in' => [ 'ds_arbitro', 'administrator' ],
			'orderby'  => 'display_name',
			'order'    => 'ASC',
			'fields'   => [ 'ID', 'display_name' ],
		] );

		// Planilleros: usuarios con cap ds_enter_match_incidents (planillero + arbitro + coordinador + admin).
		$planilleros = get_users( [
			'role__in' => [ 'ds_planillero', 'ds_arbitro', 'ds_coordinador', 'administrator' ],
			'orderby'  => 'display_name',
			'order'    => 'ASC',
			'fields'   => [ 'ID', 'display_name' ],
		] );

		$home_team = $wpdb->get_row( // phpcs:ignore
			$wpdb->prepare( "SELECT * FROM {$wpdb->prefix}ds_teams WHERE id = %d", $match['home_team_id'] ),
			ARRAY_A
		);
		$away_team = $wpdb->get_row( // phpcs:ignore
			$wpdb->prepare( "SELECT * FROM {$wpdb->prefix}ds_teams WHERE id = %d", $match['away_team_id'] ),
			ARRAY_A
		);

		$home_players = self::get_roster( (int) $match['home_team_id'] );
		$away_players = self::get_roster( (int) $match['away_team_id'] );

		$match['tournament_id'] = (int) $match['tournament_id'];

		$tournament = $wpdb->get_row( // phpcs:ignore
			$wpdb->prepare(
				"SELECT registration_mode FROM {$wpdb->prefix}ds_tournaments WHERE id = %d",
				(int) $match['tournament_id']
			),
			ARRAY_A
		);

		$page_title = sprintf( __( 'Partido: %s vs %s', 'soccertrack' ), $home_team['name'], $away_team['name'] );

		self::render( 'partido', compact(
			'match', 'tournament', 'home_team', 'away_team', 'home_players', 'away_players',
			'referees', 'planilleros',
			'notice_ref', 'error_ref',
			'notice_plan', 'error_plan',
			'notice_staff', 'error_staff',
			'can_edit', 'page_title'
		) );
	}

	private static function view_importar(): void {
		global $wpdb;

		if ( ! current_user_can( 'ds_load_excel' ) ) {
			wp_die( esc_html__( 'Sin permiso.', 'soccertrack' ), '', [ 'response' => 403 ] );
		}

		$tournament_id = absint( $_GET['tournament_id'] ?? 0 );

		$tournaments = $wpdb->get_results( // phpcs:ignore
			"SELECT id, name FROM {$wpdb->prefix}ds_tournaments ORDER BY id DESC",
			ARRAY_A
		);

		$teams = $tournament_id ? $wpdb->get_results( // phpcs:ignore
			$wpdb->prepare(
				"SELECT id, name, logo_url FROM {$wpdb->prefix}ds_teams WHERE tournament_id = %d ORDER BY name ASC",
				$tournament_id
			),
			ARRAY_A
		) : [];

		$page_title = __( 'Importar Datos', 'soccertrack' );
		self::render( 'importar', compact( 'tournaments', 'teams', 'tournament_id', 'page_title' ) );
	}

	private static function view_tribunal(): void {
		global $wpdb;

		if ( ! current_user_can( 'ds_manage_discipline' ) ) {
			wp_die( esc_html__( 'Sin permiso.', 'soccertrack' ), '', [ 'response' => 403 ] );
		}

		$tournament_id   = absint( $_GET['tournament_id'] ?? 0 );
		$round_number    = absint( $_GET['round_number'] ?? 0 );
		$team_filter     = absint( $_GET['team_filter'] ?? 0 );
		$notice          = '';
		$error           = '';
		$form_player_id  = 0;
		$form_reason     = '';
		$form_matches    = '';

		// ── Alta manual de sanción ────────────────────────────────────────
		if ( $_SERVER['REQUEST_METHOD'] === 'POST' && isset( $_POST['st_add_sanction'] ) ) {
			check_admin_referer( 'st_add_sanction' );

			$tid_post      = absint( $_POST['tournament_id'] ?? 0 );
			$player_id     = absint( $_POST['player_id'] ?? 0 );
			$team_id_post  = absint( $_POST['team_id'] ?? 0 );
			$reason        = sanitize_text_field( wp_unslash( $_POST['reason'] ?? '' ) );
			$matches       = absint( $_POST['ban_matches'] ?? 0 );
			$observaciones = sanitize_textarea_field( wp_unslash( $_POST['observaciones'] ?? '' ) );

			// Guardar para re-poblar el formulario en caso de error.
			$form_player_id = $player_id;
			$form_reason    = $reason;
			$form_matches   = $matches ?: '';

			if ( ! $tid_post || ! $player_id || ! $reason || ! $matches ) {
				$missing = [];
				if ( ! $player_id ) { $missing[] = __( 'Jugador', 'soccertrack' ); }
				if ( ! $reason )    { $missing[] = __( 'Motivo', 'soccertrack' ); }
				if ( ! $matches )   { $missing[] = __( 'Fechas de sanción', 'soccertrack' ); }
				$error = sprintf(
					/* translators: %s = list of missing field names */
					__( 'Faltan campos obligatorios: %s.', 'soccertrack' ),
					implode( ', ', $missing )
				);
				$tournament_id = $tid_post ?: $tournament_id;
			} elseif ( $matches < 1 || $matches > 20 ) {
				$error = __( 'Las fechas de sanción deben estar entre 1 y 20.', 'soccertrack' );
			} else {
				// Si no se envió team_id, inferirlo (primer equipo del jugador en el torneo).
				if ( ! $team_id_post ) {
					$team_id_post = (int) $wpdb->get_var( // phpcs:ignore
						$wpdb->prepare(
							"SELECT t.id FROM {$wpdb->prefix}ds_teams t
							 JOIN {$wpdb->prefix}ds_team_players tp ON tp.team_id = t.id
							 WHERE tp.player_id = %d AND t.tournament_id = %d
							 LIMIT 1",
							$player_id,
							$tid_post
						)
					);
				}

				$wpdb->insert( // phpcs:ignore
					"{$wpdb->prefix}ds_disciplinary_sanctions",
					[
						'player_id'           => $player_id,
						'tournament_id'       => $tid_post,
						'match_id'            => 0,
						'team_id'             => $team_id_post ?: null,
						'reason'              => $reason,
						'observaciones'       => $observaciones ?: null,
						'ban_days_or_matches' => $matches,
						'remaining_matches'   => $matches,
						'status'              => 'active',
					],
					[ '%d', '%d', '%d', $team_id_post ? '%d' : '%s', '%s', '%s', '%d', '%d', '%s' ]
				);

				// Marcar jugador como suspendido solo en su equipo específico.
				if ( $team_id_post ) {
					$wpdb->update( // phpcs:ignore
						"{$wpdb->prefix}ds_team_players",
						[ 'is_suspended' => 1 ],
						[ 'player_id' => $player_id, 'team_id' => $team_id_post ],
						[ '%d' ],
						[ '%d', '%d' ]
					);
				}

				$tournament_id = $tid_post;
				$notice        = 'added';
			}
		}

		// ── Resolver sanción (marcar como cumplida) ───────────────────────
		if ( $_SERVER['REQUEST_METHOD'] === 'POST' && isset( $_POST['st_resolve_sanction'] ) ) {
			check_admin_referer( 'st_resolve_sanction' );
			$sanction_id = absint( $_POST['sanction_id'] ?? 0 );
			if ( $sanction_id ) {
				// Obtener player_id y tournament_id para desmarcar suspensión.
				$san = $wpdb->get_row( // phpcs:ignore
					$wpdb->prepare(
						"SELECT player_id, tournament_id, team_id FROM {$wpdb->prefix}ds_disciplinary_sanctions WHERE id = %d",
						$sanction_id
					),
					ARRAY_A
				);
				$wpdb->update( // phpcs:ignore
					"{$wpdb->prefix}ds_disciplinary_sanctions",
					[ 'status' => 'served', 'remaining_matches' => 0, 'resolved_at' => current_time( 'mysql' ) ],
					[ 'id' => $sanction_id ],
					[ '%s', '%d', '%s' ],
					[ '%d' ]
				);
				// Desmarcar suspensión si no hay otras activas.
				if ( $san ) {
					// Usar team_id de la sanción; si es NULL (datos previos) inferirlo.
					$resolved_team_id = (int) ( $san['team_id'] ?? 0 );
					if ( ! $resolved_team_id ) {
						$resolved_team_id = (int) $wpdb->get_var( // phpcs:ignore
							$wpdb->prepare(
								"SELECT t.id FROM {$wpdb->prefix}ds_teams t
								 JOIN {$wpdb->prefix}ds_team_players tp ON tp.team_id = t.id
								 WHERE tp.player_id = %d AND t.tournament_id = %d LIMIT 1",
								$san['player_id'],
								$san['tournament_id']
							)
						);
					}
					$other_active = (int) $wpdb->get_var( // phpcs:ignore
						$wpdb->prepare(
							"SELECT COUNT(*) FROM {$wpdb->prefix}ds_disciplinary_sanctions
							 WHERE player_id = %d AND tournament_id = %d AND status = 'active' AND id != %d",
							$san['player_id'],
							$san['tournament_id'],
							$sanction_id
						)
					);
					if ( ! $other_active && $resolved_team_id ) {
						$wpdb->update( // phpcs:ignore
							"{$wpdb->prefix}ds_team_players",
							[ 'is_suspended' => 0 ],
							[ 'player_id' => $san['player_id'], 'team_id' => $resolved_team_id ],
							[ '%d' ],
							[ '%d', '%d' ]
						);
					}
				}
				$notice = 'resolved';
			}
		}

		$tournaments = $wpdb->get_results( // phpcs:ignore
			"SELECT id, name FROM {$wpdb->prefix}ds_tournaments ORDER BY id DESC",
			ARRAY_A
		);

		// Fechas jugadas disponibles para el filtro de fecha.
		$available_rounds = [];
		if ( $tournament_id ) {
			$available_rounds = $wpdb->get_col( // phpcs:ignore
				$wpdb->prepare(
					"SELECT DISTINCT round_number FROM {$wpdb->prefix}ds_matches
					 WHERE tournament_id = %d AND status = 'finished'
					 ORDER BY round_number ASC",
					$tournament_id
				)
			) ?: [];
		}

		// Incidentes de tarjetas de la fecha seleccionada (para revisión del tribunal).
		$round_events = [];
		if ( $tournament_id && $round_number ) {
			$round_team_sql    = '';
			$round_team_params = [];
			if ( $team_filter ) {
				$round_team_sql    = ' AND e.team_id = %d';
				$round_team_params = [ $team_filter ];
			}

			$round_events = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					"SELECT e.event_type, e.minute,
					        p.first_name, p.last_name, t.name AS team_name,
					        ht.name AS home_team, at.name AS away_team,
					        m.home_score, m.away_score
					 FROM {$wpdb->prefix}ds_match_events e
					 JOIN {$wpdb->prefix}ds_players p  ON p.id  = e.player_id
					 JOIN {$wpdb->prefix}ds_teams   t  ON t.id  = e.team_id
					 JOIN {$wpdb->prefix}ds_matches  m  ON m.id  = e.match_id
					 JOIN {$wpdb->prefix}ds_teams   ht ON ht.id = m.home_team_id
					 JOIN {$wpdb->prefix}ds_teams   at ON at.id = m.away_team_id
					 WHERE e.tournament_id = %d
					   AND m.round_number  = %d
					   AND m.status        = 'finished'
					   AND e.event_type IN ('red_card', 'yellow_card')
					   {$round_team_sql}
					 ORDER BY e.event_type DESC, p.last_name ASC",
					$tournament_id,
					$round_number,
					...$round_team_params
				),
				ARRAY_A
			) ?: [];
		}

		// Equipos y jugadores del torneo seleccionado (para el formulario de alta).
		// Una sola query con JOIN en lugar de N+1 (una query por equipo).
		$teams_with_players = [];
		if ( $tournament_id ) {
			$rows = $wpdb->get_results( // phpcs:ignore
				$wpdb->prepare(
					"SELECT t.id AS team_id, t.name AS team_name,
					        p.id AS player_id, p.first_name, p.last_name, tp.dorsal
					 FROM {$wpdb->prefix}ds_teams t
					 JOIN {$wpdb->prefix}ds_team_players tp ON tp.team_id = t.id
					 JOIN {$wpdb->prefix}ds_players p       ON p.id = tp.player_id
					 WHERE t.tournament_id = %d
					 ORDER BY t.name ASC, tp.dorsal ASC",
					$tournament_id
				),
				ARRAY_A
			) ?: [];

			// Agrupar por equipo en PHP (evita múltiples queries).
			$indexed = [];
			foreach ( $rows as $row ) {
				$tid = (int) $row['team_id'];
				if ( ! isset( $indexed[ $tid ] ) ) {
					$indexed[ $tid ] = [
						'team'    => [ 'id' => $tid, 'name' => $row['team_name'] ],
						'players' => [],
					];
				}
				$indexed[ $tid ]['players'][] = [
					'id'         => $row['player_id'],
					'first_name' => $row['first_name'],
					'last_name'  => $row['last_name'],
					'dorsal'     => $row['dorsal'],
				];
			}
			$teams_with_players = array_values( $indexed );

			// Si hay filtro de equipo, limitar el selector de sanción al equipo seleccionado.
			if ( $team_filter ) {
				$teams_with_players = array_values(
					array_filter( $teams_with_players, fn( $g ) => $g['team']['id'] === $team_filter )
				);
			}
		}

		$available_teams = array_map( fn( $g ) => $g['team'], $teams_with_players );

		// Nota: se usa ds.team_id (columna directa) para evitar fan-out cuando un jugador
		// está inscrito en varios equipos. Para sanciones antiguas sin team_id se usa
		// una subquery correlated que devuelve el primer equipo del jugador en el torneo.
		$team_sql    = '';
		$team_params = [];
		if ( $team_filter ) {
			$team_sql    = " AND (ds.team_id = %d OR (ds.team_id IS NULL AND EXISTS(
			                 SELECT 1 FROM {$wpdb->prefix}ds_team_players tp3
			                 WHERE tp3.player_id = ds.player_id AND tp3.team_id = %d
			               )))";
			$team_params = [ $team_filter, $team_filter ];
		}

		$sanctions = $tournament_id ? $wpdb->get_results( // phpcs:ignore
			$wpdb->prepare(
				"SELECT ds.id, ds.player_id, ds.reason, ds.observaciones, ds.resolved_at,
				        ds.ban_days_or_matches, ds.remaining_matches, ds.status, ds.created_at,
				        p.first_name, p.last_name,
				        COALESCE(
				            t_direct.name,
				            (SELECT MIN(t2.name)
				             FROM {$wpdb->prefix}ds_team_players tp2
				             JOIN {$wpdb->prefix}ds_teams t2 ON t2.id = tp2.team_id AND t2.tournament_id = %d
				             WHERE tp2.player_id = ds.player_id)
				        ) AS team_name
				 FROM {$wpdb->prefix}ds_disciplinary_sanctions ds
				 JOIN {$wpdb->prefix}ds_players p ON p.id = ds.player_id
				 LEFT JOIN {$wpdb->prefix}ds_teams t_direct ON t_direct.id = ds.team_id
				 WHERE ds.tournament_id = %d{$team_sql}
				 ORDER BY ds.status ASC, ds.id DESC",
				$tournament_id,
				$tournament_id,
				...$team_params
			),
			ARRAY_A
		) : [];

		// ── Historial completo por jugador (todos los torneos) ────────────────
		// Solo para los jugadores que tienen sanciones en el torneo seleccionado.
		$player_history = [];
		if ( $tournament_id && ! empty( $sanctions ) ) {
			$player_ids_in_tournament = array_unique( array_column( $sanctions, 'player_id' ) );
			if ( ! empty( $player_ids_in_tournament ) ) {
				$placeholders = implode( ', ', array_fill( 0, count( $player_ids_in_tournament ), '%d' ) );
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
				$history_rows = $wpdb->get_results(
					$wpdb->prepare(
						"SELECT ds.id, ds.player_id, ds.reason, ds.observaciones, ds.resolved_at,
						        ds.ban_days_or_matches, ds.remaining_matches, ds.status, ds.created_at,
						        p.first_name, p.last_name,
						        tn.name AS tournament_name,
						        COALESCE(
						            t_direct.name,
						            (SELECT MIN(t2.name)
						             FROM {$wpdb->prefix}ds_team_players tp2
						             JOIN {$wpdb->prefix}ds_teams t2 ON t2.id = tp2.team_id AND t2.tournament_id = ds.tournament_id
						             WHERE tp2.player_id = ds.player_id)
						        ) AS team_name
						 FROM {$wpdb->prefix}ds_disciplinary_sanctions ds
						 JOIN {$wpdb->prefix}ds_players p ON p.id = ds.player_id
						 JOIN {$wpdb->prefix}ds_tournaments tn ON tn.id = ds.tournament_id
						 LEFT JOIN {$wpdb->prefix}ds_teams t_direct ON t_direct.id = ds.team_id
						 WHERE ds.player_id IN ($placeholders)
						 ORDER BY ds.player_id ASC, ds.created_at DESC",
						...$player_ids_in_tournament
					),
					ARRAY_A
				);

				foreach ( $history_rows as $row ) {
					$pid = (int) $row['player_id'];
					if ( ! isset( $player_history[ $pid ] ) ) {
						$player_history[ $pid ] = [
							'name'       => $row['first_name'] . ' ' . $row['last_name'],
							'sanctions'  => [],
						];
					}
					$player_history[ $pid ]['sanctions'][] = $row;
				}
			}
		}

		$page_title = __( 'Tribunal Disciplinario', 'soccertrack' );
		self::render( 'tribunal', compact( 'tournaments', 'sanctions', 'tournament_id', 'round_number', 'available_rounds', 'round_events', 'teams_with_players', 'available_teams', 'team_filter', 'notice', 'error', 'form_player_id', 'form_reason', 'form_matches', 'player_history', 'page_title' ) );
	}

	private static function view_carga_fecha(): void {
		global $wpdb;

		if ( ! current_user_can( 'ds_manage_tournaments' ) ) {
			wp_die( esc_html__( 'Sin permiso.', 'soccertrack' ), '', [ 'response' => 403 ] );
		}

		$tournament_id = absint( $_GET['tournament_id'] ?? 0 );
		$round         = absint( $_GET['round'] ?? 0 );

		if ( ! $tournament_id || ! $round ) {
			wp_safe_redirect( home_url( '/panel/torneos/' ) );
			exit;
		}

		$tournament = $wpdb->get_row( // phpcs:ignore
			$wpdb->prepare( "SELECT * FROM {$wpdb->prefix}ds_tournaments WHERE id = %d", $tournament_id ),
			ARRAY_A
		);

		if ( ! $tournament || ( $tournament['registration_mode'] ?? 'realtime' ) !== 'deferred' ) {
			wp_die( esc_html__( 'Esta vista solo está disponible para torneos en modo planilla física.', 'soccertrack' ), '', [ 'response' => 403 ] );
		}

		// Cargar todos los partidos de la jornada.
		$matches = $wpdb->get_results( // phpcs:ignore
			$wpdb->prepare(
				"SELECT m.*,
				        th.name AS home_team_name,
				        ta.name AS away_team_name,
				        v.name  AS venue_name,
				        c.court_name
				 FROM {$wpdb->prefix}ds_matches m
				 JOIN {$wpdb->prefix}ds_teams th ON th.id = m.home_team_id
				 JOIN {$wpdb->prefix}ds_teams ta ON ta.id = m.away_team_id
				 LEFT JOIN {$wpdb->prefix}ds_venues v ON v.id = m.venue_id
				 LEFT JOIN {$wpdb->prefix}ds_courts c ON c.id = m.court_id
				 WHERE m.tournament_id = %d AND m.round_number = %d
				 ORDER BY m.match_datetime ASC",
				$tournament_id,
				$round
			),
			ARRAY_A
		) ?: [];

		$page_title = sprintf( __( 'Carga de Acta — Jornada %d', 'soccertrack' ), $round );

		// Pre-cargar eventos de partidos cerrados para evitar N+1 en el template.
		$events_by_match = [];
		$finished_ids    = array_column(
			array_filter( $matches, fn( $m ) => $m['status'] === 'finished' ),
			'id'
		);
		if ( ! empty( $finished_ids ) ) {
			$placeholders = implode( ', ', array_fill( 0, count( $finished_ids ), '%d' ) );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared
			$all_events = $wpdb->get_results(
				$wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					"SELECT e.match_id, e.event_type, e.minute, p.first_name, p.last_name, t.name AS team_name
					 FROM {$wpdb->prefix}ds_match_events e
					 JOIN {$wpdb->prefix}ds_players p ON p.id = e.player_id
					 JOIN {$wpdb->prefix}ds_teams t ON t.id = e.team_id
					 WHERE e.match_id IN ({$placeholders}) ORDER BY e.match_id ASC, e.minute ASC",
					...$finished_ids
				),
				ARRAY_A
			) ?: [];
			foreach ( $all_events as $ev ) {
				$events_by_match[ (int) $ev['match_id'] ][] = $ev;
			}
		}

		self::render( 'carga-fecha', compact( 'tournament', 'round', 'matches', 'events_by_match', 'page_title' ) );
	}

	/* ------------------------------------------------------------------ */
	/* Login                                                                */
	/* ------------------------------------------------------------------ */

	private static function show_login(): void {
		$error       = '';
		$redirect_to = esc_url_raw( sanitize_text_field( $_GET['redirect_to'] ?? home_url( '/panel/' ) ) );

		if ( $_SERVER['REQUEST_METHOD'] === 'POST' && isset( $_POST['st_login'] ) ) {
			check_admin_referer( 'st_login_action' );

			$user = wp_signon(
				[
					'user_login'    => sanitize_text_field( $_POST['username'] ?? '' ),
					'user_password' => $_POST['password'] ?? '',
					'remember'      => ! empty( $_POST['remember'] ),
				],
				false
			);

			if ( is_wp_error( $user ) ) {
				$error = __( 'Usuario o contraseña incorrectos.', 'soccertrack' );
			} elseif ( ! self::user_has_panel_access( $user ) ) {
				wp_logout();
				$error = __( 'Tu cuenta no tiene acceso al panel de SoccerTrack.', 'soccertrack' );
			} else {
				wp_safe_redirect( $redirect_to );
				exit;
			}
		}

		self::render( 'login', compact( 'error', 'redirect_to' ) );
	}

	/* ------------------------------------------------------------------ */
	/* Portal público                                                       */
	/* ------------------------------------------------------------------ */

	private static function handle_public( int $tournament_id ): void {
		global $wpdb;

		$tournament = $wpdb->get_row( // phpcs:ignore
			$wpdb->prepare( "SELECT * FROM {$wpdb->prefix}ds_tournaments WHERE id = %d", $tournament_id ),
			ARRAY_A
		);

		if ( ! $tournament ) {
			global $wp_query;
			$wp_query->set_404();
			status_header( 404 );
			include get_404_template();
			return;
		}

		self::render_public( $tournament_id, $tournament );
	}

	/* ------------------------------------------------------------------ */
	/* Render helpers                                                       */
	/* ------------------------------------------------------------------ */

	/**
	 * Renderiza un template del panel con header/footer propios.
	 *
	 * @param string               $view  Nombre del template (sin .php).
	 * @param array<string, mixed> $data  Variables a extraer.
	 */
	private static function render( string $view, array $data = [] ): void {
		extract( $data, EXTR_SKIP ); // phpcs:ignore WordPress.PHP.DontExtract

		$tpl_dir = SOCCERTRACK_DIR . 'templates/panel/';

		// Login es standalone (tiene su propio DOCTYPE/body).
		if ( $view === 'login' ) {
			include $tpl_dir . 'login.php';
			return;
		}

		include $tpl_dir . '_partials/header.php';
		include $tpl_dir . $view . '.php';
		include $tpl_dir . '_partials/footer.php';
	}

	/**
	 * Renderiza el portal público (página completa propia).
	 */
	private static function render_public( int $tournament_id, array $tournament ): void {
		include SOCCERTRACK_DIR . 'templates/public/tournament-page.php';
	}

	/* ------------------------------------------------------------------ */
	/* Acceso y hooks de WordPress                                          */
	/* ------------------------------------------------------------------ */

	private static function user_has_panel_access( ?\WP_User $user = null ): bool {
		$u = $user ?? wp_get_current_user();

		$panel_caps = [
			'ds_view_admin_panel',
			'ds_enter_match_incidents',
			'ds_close_match',
			'ds_view_match_sheet',
			'manage_options',
		];

		foreach ( $panel_caps as $cap ) {
			if ( $u->has_cap( $cap ) ) {
				return true;
			}
		}

		return false;
	}

	public static function block_admin_for_panel_roles(): void {
		if ( defined( 'DOING_AJAX' ) && DOING_AJAX ) {
			return;
		}

		if ( ! current_user_can( 'manage_options' ) && self::user_has_panel_access() ) {
			wp_safe_redirect( home_url( '/panel/' ) );
			exit;
		}
	}

	public static function hide_admin_bar( bool $show ): bool {
		if ( self::user_has_panel_access() && ! current_user_can( 'manage_options' ) ) {
			return false;
		}

		return $show;
	}

	public static function redirect_after_login( string $redirect, string $requested, \WP_User|\WP_Error $user ): string {
		if ( is_wp_error( $user ) ) {
			return $redirect;
		}

		if ( self::user_has_panel_access( $user ) && ! $user->has_cap( 'manage_options' ) ) {
			return home_url( '/panel/' );
		}

		return $redirect;
	}

	/* ------------------------------------------------------------------ */
	/* Vista: Gestión de Usuarios                                          */
	/* ------------------------------------------------------------------ */

	private static function view_usuarios(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Sin permiso.', 'soccertrack' ), '', [ 'response' => 403 ] );
		}

		$notice            = '';
		$error             = '';
		$created_password  = '';
		$reset_user_name   = '';

		// ── Crear usuario ────────────────────────────────────────────────
		if ( $_SERVER['REQUEST_METHOD'] === 'POST' && isset( $_POST['st_create_user'] ) ) {
			check_admin_referer( 'st_user_action' );

			$display_name = sanitize_text_field( $_POST['display_name'] ?? '' );
			$email        = sanitize_email( $_POST['email'] ?? '' );
			$role         = sanitize_key( $_POST['role'] ?? '' );
			$password     = wp_generate_password( 12, false );

			if ( ! $display_name || ! $email || ! $role ) {
				$error = __( 'Nombre, correo y rol son obligatorios.', 'soccertrack' );
			} else {
				$result = \SportsLeague\Core\UserManager::create( $email, $display_name, $role, $password );

				if ( is_wp_error( $result ) ) {
					$error = $result->get_error_message();
				} else {
					$mailer     = new \SportsLeague\Notifications\MailDispatcher();
					$role_label = \SportsLeague\Core\UserManager::PLUGIN_ROLES[ $role ] ?? $role;
					$mailer->notify_welcome(
						$email,
						$display_name,
						$role_label,
						$password,
						home_url( '/panel/login/' )
					);
					$notice           = 'created';
					$created_password = $password;
				}
			}
		}

		// ── Eliminar usuario ─────────────────────────────────────────────
		if ( $_SERVER['REQUEST_METHOD'] === 'POST' && isset( $_POST['st_delete_user'] ) ) {
			check_admin_referer( 'st_user_action' );
			$uid = absint( $_POST['user_id'] ?? 0 );
			if ( \SportsLeague\Core\UserManager::delete( $uid ) ) {
				$notice = 'deleted';
			} else {
				$error = __( 'No se pudo eliminar el usuario.', 'soccertrack' );
			}
		}

		// ── Resetear contraseña ──────────────────────────────────────────
		if ( $_SERVER['REQUEST_METHOD'] === 'POST' && isset( $_POST['st_reset_password'] ) ) {
			check_admin_referer( 'st_user_action' );

			$uid  = absint( $_POST['user_id'] ?? 0 );
			$user = get_user_by( 'id', $uid );

			if ( ! $user ) {
				$error = __( 'Usuario no encontrado.', 'soccertrack' );
			} elseif ( $uid === get_current_user_id() ) {
				$error = __( 'No puedes resetear tu propia contraseña desde aquí.', 'soccertrack' );
			} else {
				$new_password = wp_generate_password( 12, false );
				wp_set_password( $new_password, $uid );

				$mailer = new \SportsLeague\Notifications\MailDispatcher();
				$mailer->notify_password_reset(
					$user->user_email,
					$user->display_name,
					$new_password,
					home_url( '/panel/login/' )
				);

				$notice           = 'password_reset';
				$created_password = $new_password;
				$reset_user_name  = $user->display_name;
			}
		}

		$users     = \SportsLeague\Core\UserManager::list_plugin_users();
		$role_opts = \SportsLeague\Core\UserManager::PLUGIN_ROLES;

		include SOCCERTRACK_DIR . 'templates/panel/usuarios.php'; // phpcs:ignore -- $created_password passed via scope
	}

	/* ------------------------------------------------------------------ */
	/* Helper: nómina de jugadores                                          */
	/* ------------------------------------------------------------------ */

	private static function get_roster( int $team_id ): array {
		global $wpdb;

		return $wpdb->get_results( // phpcs:ignore
			$wpdb->prepare(
				"SELECT p.id, p.first_name, p.last_name, tp.dorsal, tp.is_suspended
				 FROM {$wpdb->prefix}ds_team_players tp
				 JOIN {$wpdb->prefix}ds_players p ON p.id = tp.player_id
				 WHERE tp.team_id = %d ORDER BY tp.dorsal ASC",
				$team_id
			),
			ARRAY_A
		);
	}
}
