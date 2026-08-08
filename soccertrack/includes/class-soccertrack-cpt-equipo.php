<?php
/**
 * Custom Post Type: st_equipo
 *
 * Representa un equipo participante en torneos.
 *
 * @package SoccerTrack
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SoccerTrack_CPT_Equipo {

	public static function register(): void {
		register_post_type(
			'st_equipo',
			[
				'labels'          => self::labels(),
				'public'          => false,
				'show_ui'         => true,
				'show_in_menu'    => 'edit.php?post_type=st_torneo',
				'show_in_rest'    => true,
				'capability_type' => [ 'st_equipo', 'st_equipos' ],
				'map_meta_cap'    => true,
				'hierarchical'    => false,
				'has_archive'     => false,
				'rewrite'         => false,
				'supports'        => [ 'title', 'thumbnail', 'custom-fields' ],
				'menu_icon'       => 'dashicons-groups',
			]
		);
	}

	/** @return array<string, string> */
	private static function labels(): array {
		return [
			'name'               => __( 'Equipos', 'soccertrack' ),
			'singular_name'      => __( 'Equipo', 'soccertrack' ),
			'add_new'            => __( 'Nuevo Equipo', 'soccertrack' ),
			'add_new_item'       => __( 'Agregar Equipo', 'soccertrack' ),
			'edit_item'          => __( 'Editar Equipo', 'soccertrack' ),
			'view_item'          => __( 'Ver Equipo', 'soccertrack' ),
			'search_items'       => __( 'Buscar Equipos', 'soccertrack' ),
			'not_found'          => __( 'No se encontraron equipos.', 'soccertrack' ),
			'not_found_in_trash' => __( 'No hay equipos en la papelera.', 'soccertrack' ),
		];
	}
}
