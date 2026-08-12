<?php
/**
 * Plugin Name:       SportManager — Gestión de Torneos
 * Plugin URI:        https://neurolabs.cl/
 * Description:       Motor de gestión multi-torneos: fixture Round-Robin, tribunal disciplinario, planilla digital, portal público con pestañas.
 * Version:           1.8.4
 * Requires at least: 7.0
 * Requires PHP:      8.2
 * Author:            Neurolabs
 * Author URI:        https://neurolabs.cl/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       soccertrack
 * Domain Path:       /languages
 *
 * @package SoccerTrack
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Verificación temprana de versión PHP.
if ( version_compare( PHP_VERSION, '8.2', '<' ) ) {
	add_action(
		'admin_notices',
		static fn() => printf(
			'<div class="notice notice-error"><p>%s</p></div>',
			esc_html__( 'SoccerTrack requiere PHP 8.2 o superior. Por favor actualiza PHP.', 'soccertrack' )
		)
	);
	return;
}

// ── Constantes ────────────────────────────────────────────────────────────────
define( 'SOCCERTRACK_VERSION',    '1.9.2' );
define( 'SOCCERTRACK_DB_VERSION', '1.9.2' );
define( 'SOCCERTRACK_FILE',       __FILE__ );
define( 'SOCCERTRACK_DIR',        plugin_dir_path( __FILE__ ) );
define( 'SOCCERTRACK_URL',        plugin_dir_url( __FILE__ ) );
define( 'SOCCERTRACK_BASENAME',   plugin_basename( __FILE__ ) );

// ── Autoloader PSR-4 ──────────────────────────────────────────────────────────
require_once SOCCERTRACK_DIR . 'includes/Autoloader.php';
\SportsLeague\Autoloader::register();

// ── Boot ──────────────────────────────────────────────────────────────────────

/**
 * Inicializa el plugin en plugins_loaded.
 * Orden: i18n → DB upgrade → REST API → Panel + Página pública.
 */
function soccertrack_boot(): void {
	load_plugin_textdomain(
		'soccertrack',
		false,
		dirname( SOCCERTRACK_BASENAME ) . '/languages'
	);

	// Composer vendor se carga bajo demanda (lazy) desde SpreadsheetImporter.
	// No se carga aquí para evitar consumo de memoria en cada request.

	// Migración de DB si la versión cambió.
	\SportsLeague\Core\DatabaseInstaller::maybe_upgrade();

	// Actualizar caps de roles existentes (idempotente — solo agrega lo que falta).
	\SportsLeague\Core\RolesManager::update_roles();

	// Menú de administración de WordPress.
	\SportsLeague\Admin\AdminController::init();

	// Registrar endpoints REST.
	\SportsLeague\RestApi\PublicEndpoints::init();
	\SportsLeague\RestApi\AdminEndpoints::init();

	// Panel privado (/panel/) + Página pública del torneo (/torneo/{id}/).
	\SportsLeague\Public\TournamentPage::init();
}

add_action( 'plugins_loaded', 'soccertrack_boot' );

// ── Optimización: eliminar recursos WP innecesarios en páginas del plugin ─────
add_action(
	'init',
	static function (): void {
		// Desactivar emoji JS/CSS — no se usan en el panel ni en el portal público.
		remove_action( 'wp_head',             'print_emoji_detection_script', 7 );
		remove_action( 'wp_print_styles',     'print_emoji_styles' );
		remove_action( 'admin_print_scripts', 'print_emoji_detection_script' );
		remove_action( 'admin_print_styles',  'print_emoji_styles' );

		// Eliminar etiquetas wp_head innecesarias (RSD, feeds, wlwmanifest, etc.).
		remove_action( 'wp_head', 'rsd_link' );
		remove_action( 'wp_head', 'wlwmanifest_link' );
		remove_action( 'wp_head', 'wp_shortlink_wp_head' );
		remove_action( 'wp_head', 'wp_generator' );
		remove_action( 'wp_head', 'adjacent_posts_rel_link_wp_head', 10 );
		remove_action( 'wp_head', 'feed_links',              2 );
		remove_action( 'wp_head', 'feed_links_extra',        3 );
		remove_action( 'wp_head', 'rest_output_link_wp_head', 10 );
	}
);

// ── Activación ────────────────────────────────────────────────────────────────
register_activation_hook(
	SOCCERTRACK_FILE,
	static function (): void {
		require_once SOCCERTRACK_DIR . 'includes/Autoloader.php';
		\SportsLeague\Autoloader::register();

		\SportsLeague\Core\RolesManager::add_roles();
		\SportsLeague\Core\DatabaseInstaller::create_tables();

		flush_rewrite_rules();
		update_option( 'soccertrack_version', SOCCERTRACK_VERSION );
	}
);

// ── Desactivación ─────────────────────────────────────────────────────────────
register_deactivation_hook(
	SOCCERTRACK_FILE,
	static function (): void {
		wp_clear_scheduled_hook( 'soccertrack_mail_queue' );
		flush_rewrite_rules();
	}
);
