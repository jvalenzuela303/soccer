<?php
/**
 * Custom Post Type: st_partido
 *
 * Representa un partido dentro de un torneo.
 *
 * @package SoccerTrack
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SoccerTrack_CPT_Partido {

	public static function register(): void {
		register_post_type(
			'st_partido',
			[
				'labels'          => self::labels(),
				'public'          => false,
				'show_ui'         => true,
				'show_in_menu'    => 'edit.php?post_type=st_torneo',
				'show_in_rest'    => true,
				'capability_type' => [ 'st_partido', 'st_partidos' ],
				'map_meta_cap'    => true,
				'hierarchical'    => false,
				'has_archive'     => false,
				'rewrite'         => false,
				'supports'        => [ 'title', 'custom-fields' ],
				'menu_icon'       => 'dashicons-calendar-alt',
			]
		);
	}

	/** @return array<string, string> */
	private static function labels(): array {
		return [
			'name'               => __( 'Partidos', 'soccertrack' ),
			'singular_name'      => __( 'Partido', 'soccertrack' ),
			'add_new'            => __( 'Nuevo Partido', 'soccertrack' ),
			'add_new_item'       => __( 'Agregar Partido', 'soccertrack' ),
			'edit_item'          => __( 'Editar Partido', 'soccertrack' ),
			'view_item'          => __( 'Ver Partido', 'soccertrack' ),
			'search_items'       => __( 'Buscar Partidos', 'soccertrack' ),
			'not_found'          => __( 'No se encontraron partidos.', 'soccertrack' ),
			'not_found_in_trash' => __( 'No hay partidos en la papelera.', 'soccertrack' ),
		];
	}
}
