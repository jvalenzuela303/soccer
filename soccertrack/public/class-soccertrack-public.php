<?php
/**
 * Módulo público de SoccerTrack.
 *
 * Registra el shortcode [soccertrack_tabla] para mostrar la tabla de posiciones
 * del torneo activo en cualquier página.
 *
 * PHP 8.2: uso de match, nullsafe operator, union types.
 *
 * @package SoccerTrack
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SoccerTrack_Public {

	public static function init(): void {
		add_shortcode( 'soccertrack_tabla', [ self::class, 'shortcode_tabla' ] );
		add_shortcode( 'soccertrack_fixture', [ self::class, 'shortcode_fixture' ] );
		add_action( 'wp_enqueue_scripts', [ self::class, 'enqueue_public_assets' ] );
	}

	/**
	 * Shortcode [soccertrack_tabla torneo_id="123" grupo="A"]
	 *
	 * @param array<string, string>|string $atts
	 */
	public static function shortcode_tabla( array|string $atts ): string {
		$atts = shortcode_atts(
			[
				'torneo_id' => SoccerTrack_Helpers::get_torneo_activo_id(),
				'grupo'     => '',
			],
			$atts,
			'soccertrack_tabla'
		);

		$torneo_id = absint( $atts['torneo_id'] );
		$grupo     = sanitize_text_field( $atts['grupo'] );

		if ( ! $torneo_id ) {
			return '<p>' . esc_html__( 'No hay torneo activo.', 'soccertrack' ) . '</p>';
		}

		ob_start();
		include SOCCERTRACK_PLUGIN_DIR . 'templates/public/tabla-posiciones.php';
		return ob_get_clean() ?: '';
	}

	/**
	 * Shortcode [soccertrack_fixture torneo_id="123" fase="fase_grupos"]
	 *
	 * @param array<string, string>|string $atts
	 */
	public static function shortcode_fixture( array|string $atts ): string {
		$atts = shortcode_atts(
			[
				'torneo_id' => SoccerTrack_Helpers::get_torneo_activo_id(),
				'fase'      => '',
			],
			$atts,
			'soccertrack_fixture'
		);

		$torneo_id = absint( $atts['torneo_id'] );
		$fase      = sanitize_text_field( $atts['fase'] );

		if ( ! $torneo_id ) {
			return '<p>' . esc_html__( 'No hay torneo activo.', 'soccertrack' ) . '</p>';
		}

		ob_start();
		include SOCCERTRACK_PLUGIN_DIR . 'templates/public/fixture.php';
		return ob_get_clean() ?: '';
	}

	public static function enqueue_public_assets(): void {
		// Solo cargar si algún shortcode del plugin está activo en la página.
		global $post;

		if ( ! $post instanceof \WP_Post ) {
			return;
		}

		$has_shortcode = has_shortcode( $post->post_content, 'soccertrack_tabla' )
			|| has_shortcode( $post->post_content, 'soccertrack_fixture' );

		if ( ! $has_shortcode ) {
			return;
		}

		wp_enqueue_style(
			'soccertrack-public',
			SOCCERTRACK_PLUGIN_URL . 'public/css/public.css',
			[],
			SOCCERTRACK_VERSION
		);
	}
}
