<?php
/**
 * Custom Post Type: st_jugador
 *
 * Representa un jugador registrado en un equipo.
 *
 * @package SoccerTrack
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SoccerTrack_CPT_Jugador {

	public static function register(): void {
		register_post_type(
			'st_jugador',
			[
				'labels'          => self::labels(),
				'public'          => false,
				'show_ui'         => true,
				'show_in_menu'    => 'edit.php?post_type=st_torneo',
				'show_in_rest'    => true,
				'capability_type' => [ 'st_jugador', 'st_jugadores' ],
				'map_meta_cap'    => true,
				'hierarchical'    => false,
				'has_archive'     => false,
				'rewrite'         => false,
				'supports'        => [ 'title', 'thumbnail', 'custom-fields' ],
				'menu_icon'       => 'dashicons-admin-users',
			]
		);
	}

	/** @return array<string, string> */
	private static function labels(): array {
		return [
			'name'               => __( 'Jugadores', 'soccertrack' ),
			'singular_name'      => __( 'Jugador', 'soccertrack' ),
			'add_new'            => __( 'Nuevo Jugador', 'soccertrack' ),
			'add_new_item'       => __( 'Agregar Jugador', 'soccertrack' ),
			'edit_item'          => __( 'Editar Jugador', 'soccertrack' ),
			'view_item'          => __( 'Ver Jugador', 'soccertrack' ),
			'search_items'       => __( 'Buscar Jugadores', 'soccertrack' ),
			'not_found'          => __( 'No se encontraron jugadores.', 'soccertrack' ),
			'not_found_in_trash' => __( 'No hay jugadores en la papelera.', 'soccertrack' ),
		];
	}
}
