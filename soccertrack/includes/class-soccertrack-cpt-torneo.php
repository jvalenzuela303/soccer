<?php
/**
 * Custom Post Type: st_torneo
 *
 * Representa un torneo de fútbol con su configuración (formato, fechas, fase actual).
 *
 * @package SoccerTrack
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SoccerTrack_CPT_Torneo {

	public static function register(): void {
		register_post_type(
			'st_torneo',
			[
				'labels'              => self::labels(),
				'public'              => false,
				'show_ui'             => true,
				'show_in_menu'        => true,
				'show_in_rest'        => true,
				'capability_type'     => [ 'st_torneo', 'st_torneos' ],
				'map_meta_cap'        => true,
				'hierarchical'        => false,
				'has_archive'         => false,
				'rewrite'             => false,
				'supports'            => [ 'title', 'thumbnail', 'custom-fields' ],
				'menu_icon'           => 'dashicons-awards',
				'menu_position'       => 30,
			]
		);
	}

	/** @return array<string, string> */
	private static function labels(): array {
		return [
			'name'               => __( 'Torneos', 'soccertrack' ),
			'singular_name'      => __( 'Torneo', 'soccertrack' ),
			'add_new'            => __( 'Nuevo Torneo', 'soccertrack' ),
			'add_new_item'       => __( 'Agregar Torneo', 'soccertrack' ),
			'edit_item'          => __( 'Editar Torneo', 'soccertrack' ),
			'view_item'          => __( 'Ver Torneo', 'soccertrack' ),
			'search_items'       => __( 'Buscar Torneos', 'soccertrack' ),
			'not_found'          => __( 'No se encontraron torneos.', 'soccertrack' ),
			'not_found_in_trash' => __( 'No hay torneos en la papelera.', 'soccertrack' ),
		];
	}
}
