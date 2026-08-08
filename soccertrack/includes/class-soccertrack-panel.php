<?php
/**
 * Panel de administración de SoccerTrack (rutas /torneo/*).
 *
 * Gestiona las rewrite rules y el despacho de vistas del panel.
 * PHP 8.2: readonly en propiedades de configuración estáticas.
 *
 * @package SoccerTrack
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SoccerTrack_Panel {

	/** Prefijo base de las URLs del panel. */
	private const PANEL_SLUG = 'torneo';

	public static function init(): void {
		self::register_rewrite_rules();
		add_filter( 'query_vars', [ self::class, 'add_query_vars' ] );
		add_action( 'template_redirect', [ self::class, 'dispatch' ] );
		add_action( 'wp_enqueue_scripts', [ self::class, 'enqueue_assets' ] );
	}

	/**
	 * Registra las rewrite rules del panel.
	 * Llamado también desde el hook de activación.
	 */
	public static function register_rewrite_rules(): void {
		$slug = self::PANEL_SLUG;

		add_rewrite_rule( "^{$slug}/dashboard/?$",           'index.php?st_panel=dashboard',           'top' );
		add_rewrite_rule( "^{$slug}/torneo/([0-9]+)/?$",     'index.php?st_panel=torneo&st_id=$matches[1]', 'top' );
		add_rewrite_rule( "^{$slug}/torneo/nuevo/?$",         'index.php?st_panel=torneo_nuevo',         'top' );
		add_rewrite_rule( "^{$slug}/partido/([0-9]+)/?$",    'index.php?st_panel=partido&st_id=$matches[1]', 'top' );
		add_rewrite_rule( "^{$slug}/partido/nuevo/?$",        'index.php?st_panel=partido_nuevo',        'top' );
		add_rewrite_rule( "^{$slug}/equipo/([0-9]+)/?$",     'index.php?st_panel=equipo&st_id=$matches[1]', 'top' );
		add_rewrite_rule( "^{$slug}/login/?$",                'index.php?st_panel=login',                'top' );
	}

	/** @param string[] $vars */
	public static function add_query_vars( array $vars ): array {
		$vars[] = 'st_panel';
		$vars[] = 'st_id';
		return $vars;
	}

	/**
	 * Despacha la vista correcta según la query var st_panel.
	 */
	public static function dispatch(): void {
		$panel = get_query_var( 'st_panel' );

		if ( empty( $panel ) ) {
			return;
		}

		// Autenticación requerida para todas las vistas excepto login.
		if ( $panel !== 'login' && ! is_user_logged_in() ) {
			wp_redirect( home_url( '/' . self::PANEL_SLUG . '/login/' ) );
			exit;
		}

		$template = match( $panel ) {
			'dashboard'     => 'panel/dashboard.php',
			'torneo'        => 'panel/torneo-detalle.php',
			'torneo_nuevo'  => 'panel/form-torneo.php',
			'partido'       => 'panel/partido-detalle.php',
			'partido_nuevo' => 'panel/form-partido.php',
			'equipo'        => 'panel/form-equipo.php',
			'login'         => 'panel/login.php',
			default         => null,
		};

		if ( $template === null ) {
			return;
		}

		$template_path = SOCCERTRACK_PLUGIN_DIR . 'templates/' . $template;

		if ( ! file_exists( $template_path ) ) {
			wp_die( esc_html__( 'Plantilla no encontrada.', 'soccertrack' ) );
		}

		include $template_path;
		exit;
	}

	/**
	 * Encola estilos y scripts del panel solo cuando estamos en una vista de panel.
	 */
	public static function enqueue_assets(): void {
		if ( empty( get_query_var( 'st_panel' ) ) ) {
			return;
		}

		wp_enqueue_style(
			'soccertrack-panel',
			SOCCERTRACK_PLUGIN_URL . 'public/css/panel.css',
			[],
			SOCCERTRACK_VERSION
		);

		wp_enqueue_script(
			'soccertrack-panel',
			SOCCERTRACK_PLUGIN_URL . 'public/js/panel.js',
			[ 'jquery' ],
			SOCCERTRACK_VERSION,
			true
		);
	}
}
