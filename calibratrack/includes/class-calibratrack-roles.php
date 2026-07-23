<?php
/**
 * Definición del rol `tecnico_calibracion` y sus capabilities.
 *
 * DECISIÓN ARQUITECTÓNICA — Capabilities por CPT:
 *
 * Se usan capability_type propios ('equipo', 'cliente', 'evento_servicio') en
 * lugar del genérico 'post', lo que genera automáticamente capabilities primitivas
 * granulares para cada CPT. Con map_meta_cap => true, WP resuelve las meta-caps
 * (edit_post, delete_post...) en estas primitivas, permitiendo control fino.
 *
 * Capabilities primitivas generadas por WP para cada CPT con capability_type
 * array('slug', 'slug_plural'):
 *   - read_{slug}           (leer un post específico)
 *   - edit_{slug}           (editar un post específico — meta-cap)
 *   - delete_{slug}         (borrar un post específico — meta-cap)
 *   - edit_{slugs}          (editar posts propios en lista)
 *   - edit_others_{slugs}   (editar posts de otros)
 *   - publish_{slugs}       (publicar/guardar en estado publish)
 *   - read_private_{slugs}  (leer posts privados de otros)
 *   - delete_{slugs}        (borrar posts propios)
 *   - delete_others_{slugs} (borrar posts de otros)
 *   - delete_published_{slugs}
 *   - delete_private_{slugs}
 *   - edit_published_{slugs}
 *   - edit_private_{slugs}
 *   - create_{slugs}        (crear nuevos posts)
 *
 * ROL tecnico_calibracion — principio de mínimo privilegio:
 *   - Puede CREAR y EDITAR sus propios eventos de servicio.
 *   - NO puede editar/borrar eventos de otros técnicos.
 *   - Puede LEER equipos y clientes (para seleccionar al crear un evento).
 *   - NO puede crear/editar/borrar equipos ni clientes (solo el admin).
 *   - NO puede administrar usuarios ni opciones del sitio.
 *
 * PENDIENTE para el agente de roles/admin:
 *   - Implementar el filtro 'user_has_cap' que restringe edit_evento_servicio
 *     solo al autor del post (map_meta_cap ayuda pero no es suficiente solo).
 *   - Decidir si el técnico puede crear clientes nuevos al registrar un equipo
 *     (actualmente no, solo leer).
 *
 * @package CalibraTrack
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class CalibraTrack_Roles
 */
class CalibraTrack_Roles {

	/**
	 * Slug del rol de técnico.
	 */
	const TECNICO_SLUG = 'tecnico_calibracion';

	/**
	 * Capabilities del rol tecnico_calibracion.
	 *
	 * Organizadas por dominio para facilitar la auditoría.
	 * Comentario junto a cada cap indica la justificación.
	 *
	 * @return array<string, bool>
	 */
	public static function get_tecnico_capabilities() {
		return array(

			// ── WordPress base ───────────────────────────────────────────────
			'read'                           => true,  // Leer el admin de WP.

			// ── CPT: equipo ──────────────────────────────────────────────────
			// Técnico: solo lectura. El admin crea/edita/borra equipos.
			'read_equipo'                    => true,  // Leer un equipo específico.
			'edit_equipos'                   => false, // No editar lista de equipos.
			'edit_others_equipos'            => false,
			'publish_equipos'                => false,
			'read_private_equipos'           => true,  // Leer equipos no publicados (estado cualquiera).
			'delete_equipos'                 => false,
			'delete_others_equipos'          => false,
			'delete_published_equipos'       => false,
			'delete_private_equipos'         => false,
			'edit_published_equipos'         => false,
			'edit_private_equipos'           => false,
			'create_equipos'                 => false,

			// ── CPT: cliente ─────────────────────────────────────────────────
			// Técnico: solo lectura de nombre_empresa para selects en formularios.
			// Los campos privados (RUT, teléfono, correo) se protegen a nivel
			// de postmeta auth_callback y de la API REST.
			'read_cliente'                   => true,  // Leer un cliente específico.
			'edit_clientes'                  => false,
			'edit_others_clientes'           => false,
			'publish_clientes'               => false,
			'read_private_clientes'          => true,  // Ver clientes en estado cualquiera.
			'delete_clientes'                => false,
			'delete_others_clientes'         => false,
			'delete_published_clientes'      => false,
			'delete_private_clientes'        => false,
			'edit_published_clientes'        => false,
			'edit_private_clientes'          => false,
			'create_clientes'                => false,

			// ── CPT: evento_servicio ─────────────────────────────────────────
			// Técnico: crear y editar SUS PROPIOS eventos.
			// La restricción de "solo los propios" se implementa via filtro
			// 'user_has_cap' en el agente de admin (map_meta_cap + author check).
			'read_evento_servicio'           => true,
			'edit_eventos_servicio'          => true,  // Editar sus propios eventos.
			'edit_others_eventos_servicio'   => false, // NO editar eventos de otros.
			'publish_eventos_servicio'       => true,  // Guardar/publicar un evento.
			'read_private_eventos_servicio'  => false,
			'delete_eventos_servicio'        => false, // Técnico NO borra eventos.
			'delete_others_eventos_servicio' => false,
			'delete_published_eventos_servicio' => false,
			'delete_private_eventos_servicio'   => false,
			'edit_published_eventos_servicio'   => true,  // Editar evento ya publicado (propio).
			'edit_private_eventos_servicio'     => false,
			'create_eventos_servicio'           => true,  // Crear nuevos eventos.
		);
	}

	/**
	 * Registra o actualiza el rol tecnico_calibracion.
	 * Se llama en el hook de activación del plugin.
	 *
	 * Si el rol ya existe (ej. reactivación), se actualiza para reflejar
	 * cualquier cambio en las capabilities — usando remove_role + add_role
	 * para garantizar que el array de caps esté siempre sincronizado.
	 *
	 * @return void
	 */
	/**
	 * Capabilities completas que el administrador necesita sobre los CPTs custom.
	 * WP no las asigna automáticamente cuando se usa capability_type propio.
	 *
	 * @return array<string, bool>
	 */
	public static function get_admin_capabilities() {
		$caps = array();
		$cpts = array(
			array( 'equipo',          'equipos' ),
			array( 'cliente',         'clientes' ),
			array( 'evento_servicio', 'eventos_servicio' ),
		);
		foreach ( $cpts as $pair ) {
			list( $singular, $plural ) = $pair;
			$caps[ "read_{$singular}" ]                      = true;
			$caps[ "edit_{$plural}" ]                        = true;
			$caps[ "edit_others_{$plural}" ]                 = true;
			$caps[ "publish_{$plural}" ]                     = true;
			$caps[ "read_private_{$plural}" ]                = true;
			$caps[ "delete_{$plural}" ]                      = true;
			$caps[ "delete_others_{$plural}" ]               = true;
			$caps[ "delete_published_{$plural}" ]            = true;
			$caps[ "delete_private_{$plural}" ]              = true;
			$caps[ "edit_published_{$plural}" ]              = true;
			$caps[ "edit_private_{$plural}" ]                = true;
			$caps[ "create_{$plural}" ]                      = true;
		}
		return $caps;
	}

	public static function add_roles() {
		// 1. Otorgar capabilities custom al administrador.
		$admin_role = get_role( 'administrator' );
		if ( $admin_role ) {
			foreach ( self::get_admin_capabilities() as $cap => $grant ) {
				$admin_role->add_cap( $cap, $grant );
			}
		}

		// 2. Crear/actualizar rol tecnico_calibracion.
		remove_role( self::TECNICO_SLUG );
		add_role(
			self::TECNICO_SLUG,
			__( 'Técnico de calibración', 'calibratrack' ),
			self::get_tecnico_capabilities()
		);
	}

	/**
	 * Elimina el rol tecnico_calibracion.
	 * Se llama desde uninstall.php — no desde desactivación.
	 *
	 * Los usuarios con este rol quedan sin rol asignado en WP (comportamiento
	 * estándar de remove_role). El agente de desinstalación debe documentar
	 * este efecto colateral para el administrador.
	 *
	 * @return void
	 */
	public static function remove_roles() {
		remove_role( self::TECNICO_SLUG );
	}
}
