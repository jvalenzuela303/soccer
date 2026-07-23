<?php
/**
 * Registro del CPT `equipo`.
 *
 * Decisiones arquitectónicas:
 *  - public => true: necesario para la URL /verificar/{serie} (rewrite rules).
 *  - show_in_rest => true: necesario para el editor de bloques y para el
 *    endpoint REST interno (/wp-json/calibratrack/v1/...). Los campos sensibles
 *    se protegen a nivel de permisos REST, no ocultando el CPT.
 *  - capability_type => 'equipo': capabilities granulares propias, no las de
 *    'post', para poder asignarlas de forma diferenciada al rol tecnico_calibracion.
 *  - map_meta_cap => true: delegar la resolución de meta-caps (edit_post,
 *    delete_post, etc.) a WP core, que las convierte en caps primitivas del CPT.
 *
 * @package CalibraTrack
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class CalibraTrack_CPT_Equipo
 */
class CalibraTrack_CPT_Equipo {

	/**
	 * Slug del CPT.
	 */
	const SLUG = 'equipo';

	/**
	 * Registra el CPT. Se llama en el hook 'init' (y también en activación
	 * antes del flush_rewrite_rules).
	 *
	 * @return void
	 */
	public static function register() {
		$labels = array(
			'name'                  => _x( 'Equipos', 'Post Type General Name', 'calibratrack' ),
			'singular_name'         => _x( 'Equipo', 'Post Type Singular Name', 'calibratrack' ),
			'menu_name'             => __( 'Equipos', 'calibratrack' ),
			'name_admin_bar'        => __( 'Equipo', 'calibratrack' ),
			'add_new'               => __( 'Agregar nuevo', 'calibratrack' ),
			'add_new_item'          => __( 'Agregar nuevo equipo', 'calibratrack' ),
			'new_item'              => __( 'Nuevo equipo', 'calibratrack' ),
			'edit_item'             => __( 'Editar equipo', 'calibratrack' ),
			'view_item'             => __( 'Ver equipo', 'calibratrack' ),
			'all_items'             => __( 'Todos los equipos', 'calibratrack' ),
			'search_items'          => __( 'Buscar equipos', 'calibratrack' ),
			'not_found'             => __( 'No se encontraron equipos.', 'calibratrack' ),
			'not_found_in_trash'    => __( 'No se encontraron equipos en la papelera.', 'calibratrack' ),
		);

		$args = array(
			'label'               => __( 'Equipo', 'calibratrack' ),
			'labels'              => $labels,
			'description'         => __( 'Equipos de fibra óptica registrados en el sistema de trazabilidad.', 'calibratrack' ),
			'public'              => true,
			'publicly_queryable'  => true,
			'show_ui'             => true,
			'show_in_menu'        => false, // Oculto del menú wp-admin; gestión vía modales en el formulario OI.
			'show_in_nav_menus'   => false, // No aparece en menús de navegación del sitio.
			'show_in_admin_bar'   => true,
			'show_in_rest'        => true,  // Requerido para Gutenberg y REST API propia.
			'rest_base'           => 'equipos',
			'menu_icon'           => 'dashicons-networking',
			'menu_position'       => 26,
			'capability_type'     => array( 'equipo', 'equipos' ), // Caps propias del CPT.
			'map_meta_cap'        => true,  // WP resuelve meta-caps automáticamente.
			'hierarchical'        => false,
			'supports'            => array( 'title', 'author', 'revisions' ),
			// 'editor' no incluido: el contenido textual va en metafields, no en el editor.
			'rewrite'             => array(
				'slug'       => 'equipo',
				'with_front' => false,
			),
			'query_var'           => true,
			'has_archive'         => false, // Sin página de archivo pública para equipos.
			'delete_with_user'    => false,
		);

		register_post_type( self::SLUG, $args );
	}
}
