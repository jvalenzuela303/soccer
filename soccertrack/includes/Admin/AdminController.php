<?php
/**
 * Controlador del panel de administración de SoccerTrack.
 *
 * Registra los menús de WordPress Admin y despacha las vistas.
 *
 * Menú principal:
 *   Dashboard   — resumen del sistema
 *   Torneos     — CRUD de torneos
 *   Equipos     — equipos por torneo
 *   Planilla    — planilla arbitral por partido
 *   Importar    — carga masiva CSV/XLSX
 *   Tribunal    — sanciones disciplinarias
 *
 * @package SoccerTrack
 */

declare(strict_types=1);

namespace SportsLeague\Admin;

final class AdminController {

	public static function init(): void {
		add_action( 'admin_menu',            [ self::class, 'register_menus' ] );
		add_action( 'admin_init',            [ self::class, 'maybe_redirect_to_panel' ] );
		add_action( 'admin_enqueue_scripts', [ self::class, 'enqueue_assets' ] );
		DemoDataPage::init();
	}

	public static function maybe_redirect_to_panel(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ( $_GET['page'] ?? '' ) === 'soccertrack-panel-login' ) {
			wp_safe_redirect( home_url( '/panel/' ) );
			exit;
		}
	}

	public static function register_menus(): void {
		add_menu_page(
			__( 'Gestión de Torneos', 'soccertrack' ),
			__( 'Gestión de Torneos', 'soccertrack' ),
			'read',
			'soccertrack-panel-login',
			[ self::class, 'redirect_to_panel' ],
			'dashicons-awards',
			30
		);

		// Eliminar el submenú duplicado que WordPress genera automáticamente.
		remove_submenu_page( 'soccertrack-panel-login', 'soccertrack-panel-login' );
	}

	public static function redirect_to_panel(): void {
		// Fallback — el redirect real ocurre en maybe_redirect_to_panel() vía admin_init.
	}

	public static function enqueue_assets( string $hook ): void {
		if ( ! str_contains( $hook, 'soccertrack' ) ) {
			return;
		}

		wp_enqueue_style(
			'soccertrack-admin',
			SOCCERTRACK_URL . 'assets/css/admin-panel.css',
			[],
			SOCCERTRACK_VERSION
		);

		// Fuentes Poppins + Abril Fatface.
		wp_enqueue_style(
			'soccertrack-fonts',
			'https://fonts.googleapis.com/css2?family=Abril+Fatface&family=Poppins:wght@400;600;700&display=swap',
			[],
			null
		);

		// JS de planilla solo en esa página.
		if ( str_contains( $hook, 'soccertrack-planilla' ) ) {
			wp_enqueue_script(
				'soccertrack-match-sheet',
				SOCCERTRACK_URL . 'assets/js/match-sheet.js',
				[],
				SOCCERTRACK_VERSION,
				true
			);
		}
	}

	/* ------------------------------------------------------------------ */
	/* Páginas                                                              */
	/* ------------------------------------------------------------------ */

	public static function page_dashboard(): void {
		global $wpdb;

		$stats = get_transient( 'st_admin_dashboard_stats' );
		if ( false === $stats ) {
			$stats = [
				'tournaments' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}ds_tournaments" ), // phpcs:ignore
				'teams'       => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}ds_teams" ),       // phpcs:ignore
				'players'     => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}ds_players" ),     // phpcs:ignore
				'matches'     => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}ds_matches" ),     // phpcs:ignore
				'finished'    => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}ds_matches WHERE status = 'finished'" ), // phpcs:ignore
				'sanctions'   => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}ds_disciplinary_sanctions WHERE status = 'active'" ), // phpcs:ignore
			];
			set_transient( 'st_admin_dashboard_stats', $stats, 60 );
		}

		// Últimos torneos.
		$tournaments = $wpdb->get_results( // phpcs:ignore
			"SELECT id, name, status, start_date FROM {$wpdb->prefix}ds_tournaments ORDER BY id DESC LIMIT 5",
			ARRAY_A
		);

		include SOCCERTRACK_DIR . 'templates/admin/dashboard.php';
	}

	public static function page_torneos(): void {
		global $wpdb;

		// Acción: crear torneo.
		if ( $_SERVER['REQUEST_METHOD'] === 'POST' && isset( $_POST['st_create_tournament'] ) ) {
			check_admin_referer( 'st_tournament_action' );

			$name       = sanitize_text_field( $_POST['name'] ?? '' );
			$start_date = sanitize_text_field( $_POST['start_date'] ?? '' );
			$end_date   = sanitize_text_field( $_POST['end_date'] ?? '' );
			$format     = sanitize_text_field( $_POST['format'] ?? 'round_robin' );

			if ( $name ) {
				$wpdb->insert( // phpcs:ignore
					"{$wpdb->prefix}ds_tournaments",
					[
						'name'       => $name,
						'start_date' => $start_date ?: null,
						'end_date'   => $end_date ?: null,
						'format'     => $format,
						'status'     => 'draft',
					],
					[ '%s', '%s', '%s', '%s', '%s' ]
				);
			}
		}

		$tournaments = $wpdb->get_results( // phpcs:ignore
			"SELECT id, name, start_date, end_date, format, status FROM {$wpdb->prefix}ds_tournaments ORDER BY id DESC LIMIT 100",
			ARRAY_A
		);

		include SOCCERTRACK_DIR . 'templates/admin/torneos.php';
	}

	public static function page_equipos(): void {
		global $wpdb;

		$tournament_id = absint( $_GET['tournament_id'] ?? 0 );

		$tournaments = $wpdb->get_results( // phpcs:ignore
			"SELECT id, name FROM {$wpdb->prefix}ds_tournaments ORDER BY id DESC LIMIT 200",
			ARRAY_A
		);

		$teams = [];
		if ( $tournament_id ) {
			$teams = $wpdb->get_results( // phpcs:ignore
				$wpdb->prepare(
					"SELECT t.*, COUNT(tp.player_id) AS player_count
					 FROM {$wpdb->prefix}ds_teams t
					 LEFT JOIN {$wpdb->prefix}ds_team_players tp ON tp.team_id = t.id
					 WHERE t.tournament_id = %d
					 GROUP BY t.id
					 ORDER BY t.name ASC",
					$tournament_id
				),
				ARRAY_A
			);
		}

		include SOCCERTRACK_DIR . 'templates/admin/equipos.php';
	}

	public static function page_planilla(): void {
		global $wpdb;

		$match_id = absint( $_GET['match_id'] ?? 0 );

		// Lista de partidos disponibles si no se eligió uno.
		$matches = $wpdb->get_results( // phpcs:ignore
			"SELECT m.id, m.round_number, m.match_datetime, m.status,
			        ht.name AS home_team, at.name AS away_team,
			        tr.name AS tournament
			 FROM {$wpdb->prefix}ds_matches m
			 JOIN {$wpdb->prefix}ds_teams ht ON ht.id = m.home_team_id
			 JOIN {$wpdb->prefix}ds_teams at ON at.id = m.away_team_id
			 JOIN {$wpdb->prefix}ds_tournaments tr ON tr.id = m.tournament_id
			 WHERE m.status != 'finished'
			 ORDER BY m.match_datetime ASC
			 LIMIT 50",
			ARRAY_A
		);

		$match        = null;
		$home_team    = null;
		$away_team    = null;
		$home_players = [];
		$away_players = [];

		if ( $match_id ) {
			$match = $wpdb->get_row( // phpcs:ignore
				$wpdb->prepare(
					"SELECT m.*, tr.name AS tournament_name,
					        v.name AS venue, c.court_name
					 FROM {$wpdb->prefix}ds_matches m
					 JOIN {$wpdb->prefix}ds_tournaments tr ON tr.id = m.tournament_id
					 LEFT JOIN {$wpdb->prefix}ds_venues v ON v.id = m.venue_id
					 LEFT JOIN {$wpdb->prefix}ds_courts c ON c.id = m.court_id
					 WHERE m.id = %d",
					$match_id
				),
				ARRAY_A
			);

			if ( $match ) {
				$match['tournament_id'] = (int) $match['tournament_id'];

				$home_team = $wpdb->get_row( // phpcs:ignore
					$wpdb->prepare( "SELECT id, name, logo_url FROM {$wpdb->prefix}ds_teams WHERE id = %d", $match['home_team_id'] ),
					ARRAY_A
				);
				$away_team = $wpdb->get_row( // phpcs:ignore
					$wpdb->prepare( "SELECT id, name, logo_url FROM {$wpdb->prefix}ds_teams WHERE id = %d", $match['away_team_id'] ),
					ARRAY_A
				);

				$home_players = self::get_team_roster( (int) $match['home_team_id'] );
				$away_players = self::get_team_roster( (int) $match['away_team_id'] );

				// Localizar el JS de planilla.
				wp_localize_script(
					'soccertrack-match-sheet',
					'stMatchSheet',
					[
						'apiBase' => esc_url_raw( get_rest_url() ),
						'matchId' => $match_id,
						'nonce'   => wp_create_nonce( 'wp_rest' ),
						'i18n'    => [
							'saving'          => __( 'Guardando…', 'soccertrack' ),
							'confirm_result'  => __( '¿Confirmar resultado?', 'soccertrack' ),
							'result_saved'    => __( 'Resultado registrado.', 'soccertrack' ),
							'error_save'      => __( 'Error al guardar.', 'soccertrack' ),
							'incident_missing_fields' => __( 'Completa jugador y minuto.', 'soccertrack' ),
							'red_card_reason' => __( 'Tarjeta roja directa', 'soccertrack' ),
						],
					]
				);
			}
		}

		include SOCCERTRACK_DIR . 'templates/admin/match-sheet.php';
	}

	public static function page_import(): void {
		global $wpdb;

		$tournament_id = absint( $_GET['tournament_id'] ?? 0 );

		$tournaments = $wpdb->get_results( // phpcs:ignore
			"SELECT id, name FROM {$wpdb->prefix}ds_tournaments ORDER BY id DESC LIMIT 200",
			ARRAY_A
		);

		$teams = [];
		if ( $tournament_id ) {
			$teams = $wpdb->get_results( // phpcs:ignore
				$wpdb->prepare(
					"SELECT id, name, logo_url FROM {$wpdb->prefix}ds_teams WHERE tournament_id = %d ORDER BY name ASC",
					$tournament_id
				),
				ARRAY_A
			);
		}

		include SOCCERTRACK_DIR . 'templates/admin/import-upload.php';
	}

	public static function page_tribunal(): void {
		global $wpdb;

		$tournament_id = absint( $_GET['tournament_id'] ?? 0 );

		$tournaments = $wpdb->get_results( // phpcs:ignore
			"SELECT id, name FROM {$wpdb->prefix}ds_tournaments ORDER BY id DESC LIMIT 200",
			ARRAY_A
		);

		$sanctions = [];
		if ( $tournament_id ) {
			$sanctions = $wpdb->get_results( // phpcs:ignore
				$wpdb->prepare(
					"SELECT
					    ds.id, ds.player_id, ds.reason, ds.observaciones, ds.resolved_at,
					    ds.ban_days_or_matches, ds.remaining_matches, ds.status, ds.created_at,
					    ds.team_id,
					    p.first_name, p.last_name,
					    t.name AS team_name
					 FROM {$wpdb->prefix}ds_disciplinary_sanctions ds
					 JOIN {$wpdb->prefix}ds_players p ON p.id = ds.player_id
					 LEFT JOIN {$wpdb->prefix}ds_teams t ON t.id = ds.team_id
					 WHERE ds.tournament_id = %d
					 ORDER BY ds.status ASC, ds.created_at DESC",
					$tournament_id
				),
				ARRAY_A
			);
		}

		include SOCCERTRACK_DIR . 'templates/admin/tribunal.php';
	}

	/* ------------------------------------------------------------------ */
	/* Helpers                                                              */
	/* ------------------------------------------------------------------ */

	private static function get_team_roster( int $team_id ): array {
		global $wpdb;

		return $wpdb->get_results( // phpcs:ignore
			$wpdb->prepare(
				"SELECT p.id, p.first_name, p.last_name, tp.dorsal, tp.is_suspended
				 FROM {$wpdb->prefix}ds_team_players tp
				 JOIN {$wpdb->prefix}ds_players p ON p.id = tp.player_id
				 WHERE tp.team_id = %d
				 ORDER BY tp.dorsal ASC",
				$team_id
			),
			ARRAY_A
		);
	}
}
