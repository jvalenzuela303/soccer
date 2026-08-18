<?php
/**
 * Página de administración: Datos Demo.
 *
 * Permite importar y eliminar el torneo de demo desde el panel de WordPress
 * sin necesidad de acceso a la terminal.
 *
 * Usa el seeder PHP (demo-seeder.php) para que los IDs sean auto-incrementales
 * y no conflictúen con datos reales en producción.
 *
 * Acceso: Menú WordPress Admin → SoccerTrack → Datos Demo
 *
 * @package SoccerTrack
 */

declare(strict_types=1);

namespace SportsLeague\Admin;

final class DemoDataPage {

	private const MENU_SLUG      = 'soccertrack-demo-data';
	private const OPTION_KEY     = 'soccertrack_demo_seed_ids';
	private const ACTION_IMPORT  = 'soccertrack_demo_import';
	private const ACTION_CLEANUP = 'soccertrack_demo_cleanup';

	public static function init(): void {
		add_action( 'admin_menu',                                [ self::class, 'register_menu' ] );
		add_action( 'admin_post_' . self::ACTION_IMPORT,        [ self::class, 'handle_import' ] );
		add_action( 'admin_post_' . self::ACTION_CLEANUP,       [ self::class, 'handle_cleanup' ] );
	}

	public static function register_menu(): void {
		add_submenu_page(
			'soccertrack-panel-login',
			__( 'Datos Demo', 'soccertrack' ),
			__( '🧪 Datos Demo', 'soccertrack' ),
			'manage_options',
			self::MENU_SLUG,
			[ self::class, 'render' ]
		);
	}

	// ── Renderizado ──────────────────────────────────────────────────────────

	public static function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'No tienes permiso para acceder a esta página.', 'soccertrack' ) );
		}

		$tracked  = get_option( self::OPTION_KEY, null );
		$has_data = ! empty( $tracked );
		$notice   = self::get_notice();

		?>
		<div class="wrap">
			<h1><?php esc_html_e( '🧪 Datos Demo — SoccerTrack', 'soccertrack' ); ?></h1>

			<?php if ( $notice ) : ?>
				<div class="notice notice-<?php echo esc_attr( $notice['type'] ); ?> is-dismissible">
					<p><?php echo esc_html( $notice['message'] ); ?></p>
				</div>
			<?php endif; ?>

			<div style="max-width:720px; margin-top:20px;">

				<!-- Estado actual -->
				<div style="background:#fff; border:1px solid #c3c4c7; border-radius:4px; padding:20px 24px; margin-bottom:24px;">
					<h2 style="margin-top:0;"><?php esc_html_e( 'Estado actual', 'soccertrack' ); ?></h2>

					<?php if ( $has_data ) : ?>
						<?php
						$tid       = (int) ( $tracked['tournament_id'] ?? 0 );
						$n_teams   = count( (array) ( $tracked['team_ids']          ?? [] ) );
						$n_players = count( (array) ( $tracked['player_ids']        ?? [] ) );
						$n_reg     = count( (array) ( $tracked['regular_match_ids'] ?? [] ) );
						$n_po      = count( (array) ( $tracked['playoff_match_ids'] ?? [] ) );
						?>
						<p>✅ <strong><?php esc_html_e( 'Datos demo importados', 'soccertrack' ); ?></strong></p>
						<table class="widefat striped" style="width:auto; min-width:300px;">
							<tbody>
								<tr><th><?php esc_html_e( 'Torneo ID', 'soccertrack' ); ?></th><td><?php echo esc_html( (string) $tid ); ?></td></tr>
								<tr><th><?php esc_html_e( 'Equipos', 'soccertrack' ); ?></th><td><?php echo esc_html( (string) $n_teams ); ?></td></tr>
								<tr><th><?php esc_html_e( 'Jugadores', 'soccertrack' ); ?></th><td><?php echo esc_html( (string) $n_players ); ?></td></tr>
								<tr><th><?php esc_html_e( 'Partidos (regular)', 'soccertrack' ); ?></th><td><?php echo esc_html( (string) $n_reg ); ?></td></tr>
								<tr><th><?php esc_html_e( 'Partidos (play-offs)', 'soccertrack' ); ?></th><td><?php echo esc_html( (string) $n_po ); ?></td></tr>
							</tbody>
						</table>
					<?php else : ?>
						<p>⭕ <?php esc_html_e( 'No hay datos demo cargados.', 'soccertrack' ); ?></p>
					<?php endif; ?>
				</div>

				<!-- Descripción de los datos demo -->
				<div style="background:#f0f6fc; border-left:4px solid #2271b1; padding:16px 20px; margin-bottom:24px; border-radius:0 4px 4px 0;">
					<h3 style="margin-top:0;"><?php esc_html_e( 'Contenido del demo', 'soccertrack' ); ?></h3>
					<ul style="margin:0; padding-left:20px;">
						<li><?php esc_html_e( '16 equipos empresariales con 20 jugadores cada uno (320 jugadores)', 'soccertrack' ); ?></li>
						<li><?php esc_html_e( 'Fixture completo: 15 jornadas × 8 partidos (fase regular)', 'soccertrack' ); ?></li>
						<li><?php esc_html_e( 'Play-offs: octavos → cuartos → semifinales → 3er puesto → final', 'soccertrack' ); ?></li>
						<li><?php esc_html_e( 'Resultados simulados con goles, tarjetas y sanciones de tribunal', 'soccertrack' ); ?></li>
					</ul>
					<p style="margin-bottom:0; font-size:12px; color:#555;">
						<?php esc_html_e( 'Los IDs se asignan automáticamente según el estado de la base de datos. Puede tardar 15–30 segundos en generarse.', 'soccertrack' ); ?>
					</p>
				</div>

				<!-- Acciones -->
				<div style="display:flex; gap:16px; flex-wrap:wrap;">

					<!-- Importar -->
					<?php if ( ! $has_data ) : ?>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<?php wp_nonce_field( self::ACTION_IMPORT, '_wpnonce_demo' ); ?>
						<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION_IMPORT ); ?>">
						<button type="submit" class="button button-primary button-hero"
							onclick="return confirm('<?php esc_attr_e( '¿Generar los datos demo? El proceso puede tardar 15–30 segundos.', 'soccertrack' ); ?>')">
							📥 <?php esc_html_e( 'Generar datos demo', 'soccertrack' ); ?>
						</button>
					</form>
					<?php else : ?>
					<button class="button button-primary button-hero" disabled>
						📥 <?php esc_html_e( 'Generar datos demo', 'soccertrack' ); ?>
					</button>
					<?php endif; ?>

					<!-- Cleanup -->
					<?php if ( $has_data ) : ?>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<?php wp_nonce_field( self::ACTION_CLEANUP, '_wpnonce_demo' ); ?>
						<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION_CLEANUP ); ?>">
						<button type="submit" class="button button-hero"
							style="color:#cc1818; border-color:#cc1818;"
							onclick="return confirm('<?php esc_attr_e( '¿Eliminar todos los datos demo? Esta acción no se puede deshacer.', 'soccertrack' ); ?>')">
							🗑️ <?php esc_html_e( 'Eliminar datos demo', 'soccertrack' ); ?>
						</button>
					</form>
					<?php endif; ?>

				</div><!-- /acciones -->

			</div><!-- /max-width -->
		</div>
		<?php
	}

	// ── Handlers de formulario ───────────────────────────────────────────────

	public static function handle_import(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Permiso denegado.', 'soccertrack' ) );
		}
		check_admin_referer( self::ACTION_IMPORT, '_wpnonce_demo' );

		if ( get_option( self::OPTION_KEY ) ) {
			self::redirect_with_notice( 'warning', __( 'Los datos demo ya están generados. Elimínalos primero.', 'soccertrack' ) );
			return;
		}

		global $wpdb;

		require_once SOCCERTRACK_DIR . 'scripts/demo-seeder.php';

		ob_start();
		demo_seed( $wpdb );
		ob_end_clean();

		$tracked = get_option( self::OPTION_KEY );
		if ( empty( $tracked ) ) {
			self::redirect_with_notice( 'error', __( 'Error al generar los datos demo. Revisa el log del servidor.', 'soccertrack' ) );
			return;
		}

		$tid = (int) ( $tracked['tournament_id'] ?? 0 );
		self::redirect_with_notice( 'success', sprintf(
			/* translators: %d: ID del torneo demo generado */
			__( '✅ Datos demo generados correctamente. Torneo ID: %d', 'soccertrack' ),
			$tid
		) );
	}

	public static function handle_cleanup(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Permiso denegado.', 'soccertrack' ) );
		}
		check_admin_referer( self::ACTION_CLEANUP, '_wpnonce_demo' );

		if ( ! get_option( self::OPTION_KEY ) ) {
			self::redirect_with_notice( 'warning', __( 'No hay datos demo para eliminar.', 'soccertrack' ) );
			return;
		}

		global $wpdb;

		require_once SOCCERTRACK_DIR . 'scripts/demo-seeder.php';

		ob_start();
		demo_cleanup( $wpdb );
		ob_end_clean();

		self::redirect_with_notice( 'success', __( '🗑️ Datos demo eliminados correctamente.', 'soccertrack' ) );
	}

	// ── Utilidades ───────────────────────────────────────────────────────────

	private static function redirect_with_notice( string $type, string $message ): void {
		$url = add_query_arg(
			[
				'page'        => self::MENU_SLUG,
				'demo_notice' => rawurlencode( $message ),
				'demo_type'   => $type,
			],
			admin_url( 'admin.php' )
		);
		wp_safe_redirect( $url );
		exit;
	}

	private static function get_notice(): ?array {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		if ( empty( $_GET['demo_notice'] ) ) {
			return null;
		}
		return [
			'type'    => sanitize_key( $_GET['demo_type'] ?? 'info' ),
			'message' => sanitize_text_field( wp_unslash( $_GET['demo_notice'] ) ),
		];
		// phpcs:enable
	}
}
