<?php
/**
 * Registro del CPT `evento_servicio`.
 *
 * Decisiones arquitectónicas:
 *  - public => false / publicly_queryable => false: los eventos no tienen
 *    página pública propia. La información se expone ÚNICAMENTE a través
 *    del endpoint controlado /verificar/{serie}, que filtra qué campos
 *    muestra (nunca RUT, teléfono, correo — ver §9).
 *  - show_in_rest => true: REST para el admin (crear/editar eventos).
 *    El acceso a documentos PDF usa un endpoint propio, no la Media API.
 *  - capability_type => 'evento_servicio': caps propias críticas para
 *    el requisito de seguridad §9: un técnico no debe poder editar/borrar
 *    eventos de otros técnicos.
 *  - map_meta_cap => true: permite que WP resuelva 'edit_post' del evento
 *    en 'edit_evento_servicio' + verificación de autoría (el agente de roles
 *    implementará el filtro de autoría adicional con 'user_has_cap').
 *  - supports => ['title', 'author']: el título sirve como referencia interna
 *    (ej. "OT96 — Equipo GS-401"), 'author' es clave para el control de
 *    autoría por técnico.
 *
 * @package CalibraTrack
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class CalibraTrack_CPT_EventoServicio
 */
class CalibraTrack_CPT_EventoServicio {

	/**
	 * Slug del CPT.
	 */
	const SLUG = 'evento_servicio';

	/**
	 * Registra el CPT.
	 *
	 * @return void
	 */
	public static function register() {
		$labels = array(
			'name'                  => _x( 'Eventos de servicio', 'Post Type General Name', 'calibratrack' ),
			'singular_name'         => _x( 'Evento de servicio', 'Post Type Singular Name', 'calibratrack' ),
			'menu_name'             => __( 'Eventos de servicio', 'calibratrack' ),
			'name_admin_bar'        => __( 'Evento de servicio', 'calibratrack' ),
			'add_new'               => __( 'Registrar nuevo', 'calibratrack' ),
			'add_new_item'          => __( 'Registrar nuevo evento de servicio', 'calibratrack' ),
			'new_item'              => __( 'Nuevo evento de servicio', 'calibratrack' ),
			'edit_item'             => __( 'Editar evento de servicio', 'calibratrack' ),
			'view_item'             => __( 'Ver evento de servicio', 'calibratrack' ),
			'all_items'             => __( 'Todos los eventos', 'calibratrack' ),
			'search_items'          => __( 'Buscar eventos', 'calibratrack' ),
			'not_found'             => __( 'No se encontraron eventos de servicio.', 'calibratrack' ),
			'not_found_in_trash'    => __( 'No se encontraron eventos de servicio en la papelera.', 'calibratrack' ),
		);

		$args = array(
			'label'               => __( 'Evento de servicio', 'calibratrack' ),
			'labels'              => $labels,
			'description'         => __( 'Registro de calibraciones y mantenciones de equipos.', 'calibratrack' ),
			'public'              => false,         // Sin página pública directa.
			'publicly_queryable'  => false,
			'show_ui'             => true,
			'show_in_menu'        => false,  // Menú gestionado manualmente en register_admin_menu().
			'show_in_nav_menus'   => false,
			'show_in_admin_bar'   => false,   // Ocultar del botón "+ Nuevo" de la barra de admin.
			'show_in_rest'        => true,
			'rest_base'           => 'eventos-servicio',
			'menu_icon'           => 'dashicons-clipboard',
			'menu_position'       => 28,
			'capability_type'     => array( 'evento_servicio', 'eventos_servicio' ),
			'map_meta_cap'        => true,
			'hierarchical'        => false,
			'supports'            => array( 'title', 'author', 'revisions' ),
			// 'revisions' incluido: los eventos de servicio son registros de auditoría;
			// guardar quién cambió qué y cuándo es parte del requisito de trazabilidad.
			'rewrite'             => false,
			'query_var'           => false,
			'has_archive'         => false,
			'delete_with_user'    => false,
		);

		register_post_type( self::SLUG, $args );
	}
}
