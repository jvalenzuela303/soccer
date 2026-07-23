<?php
/**
 * Registro del CPT `cliente`.
 *
 * Decisiones arquitectónicas:
 *  - public => false / publicly_queryable => false: el cliente nunca tiene
 *    una página pública propia. Su información es privada (RUT, teléfono,
 *    correo — ver §9 de la especificación). Solo se accede via admin o REST
 *    con autenticación.
 *  - show_in_rest => true: necesario para consultas AJAX desde el admin
 *    (ej. selector de cliente al crear un equipo). El acceso REST está
 *    restringido por las capabilities del CPT.
 *  - capability_type => 'cliente': caps propias para control granular.
 *    El técnico puede leer clientes (read_cliente) para seleccionar al
 *    crear un equipo, pero no crear/editar clientes (esa decisión queda
 *    pendiente para el agente de roles/admin — ver docs/decisiones.md).
 *
 * @package CalibraTrack
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class CalibraTrack_CPT_Cliente
 */
class CalibraTrack_CPT_Cliente {

	/**
	 * Slug del CPT.
	 */
	const SLUG = 'cliente';

	/**
	 * Registra el CPT.
	 *
	 * @return void
	 */
	public static function register() {
		$labels = array(
			'name'                  => _x( 'Clientes', 'Post Type General Name', 'calibratrack' ),
			'singular_name'         => _x( 'Cliente', 'Post Type Singular Name', 'calibratrack' ),
			'menu_name'             => __( 'Clientes', 'calibratrack' ),
			'name_admin_bar'        => __( 'Cliente', 'calibratrack' ),
			'add_new'               => __( 'Agregar nuevo', 'calibratrack' ),
			'add_new_item'          => __( 'Agregar nuevo cliente', 'calibratrack' ),
			'new_item'              => __( 'Nuevo cliente', 'calibratrack' ),
			'edit_item'             => __( 'Editar cliente', 'calibratrack' ),
			'view_item'             => __( 'Ver cliente', 'calibratrack' ),
			'all_items'             => __( 'Todos los clientes', 'calibratrack' ),
			'search_items'          => __( 'Buscar clientes', 'calibratrack' ),
			'not_found'             => __( 'No se encontraron clientes.', 'calibratrack' ),
			'not_found_in_trash'    => __( 'No se encontraron clientes en la papelera.', 'calibratrack' ),
		);

		$args = array(
			'label'               => __( 'Cliente', 'calibratrack' ),
			'labels'              => $labels,
			'description'         => __( 'Empresas clientes con equipos en el sistema.', 'calibratrack' ),
			'public'              => false,        // Sin página pública.
			'publicly_queryable'  => false,        // No consultable públicamente.
			'show_ui'             => true,         // UI disponible por URL directa (acceso de emergencia).
			'show_in_menu'        => false,        // Oculto del menú wp-admin; gestión vía modales en el formulario OI.
			'show_in_nav_menus'   => false,
			'show_in_admin_bar'   => false,
			'show_in_rest'        => true,         // REST con autenticación para selects en admin.
			'rest_base'           => 'clientes',
			'menu_icon'           => 'dashicons-businessman',
			'menu_position'       => 27,
			'capability_type'     => array( 'cliente', 'clientes' ),
			'map_meta_cap'        => true,
			'hierarchical'        => false,
			'supports'            => array( 'title', 'author' ),
			// Sin 'revisions' para clientes: menos overhead, los cambios de datos
			// de contacto no requieren historial de versiones.
			'rewrite'             => false,        // Sin URL pública.
			'query_var'           => false,
			'has_archive'         => false,
			'delete_with_user'    => false,
		);

		register_post_type( self::SLUG, $args );
	}
}
