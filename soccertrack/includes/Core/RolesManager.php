<?php
/**
 * Gestión de roles y capabilities de SoccerTrack.
 *
 * Roles definidos:
 *  - ds_coordinador : Comisario/Coordinador de Liga — carga masiva, fixture, tribunal.
 *  - ds_arbitro     : Árbitro/Veedor — revisa incidentes, cierra el acta.
 *  - ds_planillero  : Planillero — registra goles y tarjetas con detalle durante el partido.
 *
 * Capabilities por responsabilidad (RACI):
 *  - ds_manage_tournaments    : Crear/editar/eliminar torneos y asignar árbitros/planilleros.
 *  - ds_load_excel            : Importar CSV/XLSX de equipos y jugadores.
 *  - ds_generate_fixture      : Ejecutar generador Round-Robin.
 *  - ds_manage_discipline     : Emitir y gestionar sanciones del tribunal.
 *  - ds_view_admin_panel      : Acceder al panel de administración del torneo.
 *  - ds_view_match_sheet      : Acceder a la planilla digital (lectura).
 *  - ds_enter_match_incidents : Registrar goles y tarjetas (planillero + árbitro + coordinador).
 *  - ds_edit_incidents        : Editar o eliminar incidentes ajenos (árbitro + coordinador).
 *  - ds_close_match           : Cerrar el acta y registrar resultado final (árbitro + coordinador).
 *
 * @package SoccerTrack
 */

declare(strict_types=1);

namespace SportsLeague\Core;

final class RolesManager {

	/** @var array<string, bool> */
	private const CAPS_COORDINADOR = [
		// Capabilities personalizadas del plugin.
		'read'                        => true,
		'ds_manage_tournaments'       => true,
		'ds_load_excel'               => true,
		'ds_generate_fixture'         => true,
		'ds_manage_discipline'        => true,
		'ds_view_admin_panel'         => true,
		'ds_view_match_sheet'         => true,
		'ds_enter_match_incidents'    => true,
		'ds_edit_incidents'           => true,
		'ds_close_match'              => true,
		// Capabilities de CPT: Torneos.
		'create_st_torneos'           => true,
		'edit_st_torneos'             => true,
		'edit_others_st_torneos'      => true,
		'delete_st_torneos'           => true,
		'publish_st_torneos'          => true,
		// Capabilities de CPT: Equipos.
		'create_st_equipos'           => true,
		'edit_st_equipos'             => true,
		'edit_others_st_equipos'      => true,
		'delete_st_equipos'           => true,
		'publish_st_equipos'          => true,
		// Capabilities de CPT: Jugadores.
		'create_st_jugadores'         => true,
		'edit_st_jugadores'           => true,
		'edit_others_st_jugadores'    => true,
		'delete_st_jugadores'         => true,
		'publish_st_jugadores'        => true,
		// Capabilities de CPT: Partidos.
		'create_st_partidos'          => true,
		'edit_st_partidos'            => true,
		'edit_others_st_partidos'     => true,
		'delete_st_partidos'          => true,
		'publish_st_partidos'         => true,
	];

	/** @var array<string, bool> */
	private const CAPS_ARBITRO = [
		// Capabilities personalizadas del plugin.
		'read'                     => true,
		'ds_view_match_sheet'      => true,
		'ds_enter_match_incidents' => true,
		'ds_edit_incidents'        => true,
		'ds_close_match'           => true,
		// Capabilities de CPT: solo editar partidos asignados (sin crear ni borrar).
		'edit_st_partidos'         => true,
		'create_st_partidos'       => false,
		'delete_st_partidos'       => false,
		'publish_st_partidos'      => false,
	];

	/** @var array<string, bool> */
	private const CAPS_PLANILLERO = [
		// Capabilities personalizadas del plugin.
		'read'                     => true,
		'ds_view_match_sheet'      => true,
		'ds_enter_match_incidents' => true,
		// No tiene ds_edit_incidents ni ds_close_match.
	];

	/** @var array<string, array{label:string, caps:array<string,bool>}> */
	private const ROLE_DEFINITIONS = [
		'ds_coordinador' => [
			'label' => 'Coordinador de Liga',
			'caps'  => self::CAPS_COORDINADOR,
		],
		'ds_arbitro' => [
			'label' => 'Árbitro / Veedor de Campo',
			'caps'  => self::CAPS_ARBITRO,
		],
		'ds_planillero' => [
			'label' => 'Planillero',
			'caps'  => self::CAPS_PLANILLERO,
		],
	];

	/**
	 * Registra los roles del plugin si no existen.
	 * Se llama en el hook de activación.
	 */
	public static function add_roles(): void {
		foreach ( self::ROLE_DEFINITIONS as $slug => $def ) {
			if ( ! get_role( $slug ) ) {
				add_role( $slug, __( $def['label'], 'soccertrack' ), $def['caps'] );
			}
		}

		self::sync_admin_caps();
	}

	/**
	 * Actualiza caps de roles existentes (agrega las nuevas sin borrar las custom).
	 * Idempotente: llama en plugins_loaded para garantizar migración sin reactivación.
	 */
	public static function update_roles(): void {
		foreach ( self::ROLE_DEFINITIONS as $slug => $def ) {
			$role = get_role( $slug );
			if ( ! $role ) {
				add_role( $slug, __( $def['label'], 'soccertrack' ), $def['caps'] );
				continue;
			}
			// Agregar caps faltantes al rol existente.
			foreach ( $def['caps'] as $cap => $grant ) {
				if ( ! isset( $role->capabilities[ $cap ] ) ) {
					$role->add_cap( $cap, $grant );
				}
			}
		}

		self::sync_admin_caps();
	}

	/**
	 * Garantiza que el administrador tenga todas las caps del plugin.
	 */
	private static function sync_admin_caps(): void {
		$admin = get_role( 'administrator' );
		if ( ! $admin instanceof \WP_Role ) {
			return;
		}

		$all_caps = array_merge(
			self::CAPS_COORDINADOR,
			self::CAPS_ARBITRO,
			self::CAPS_PLANILLERO
		);

		foreach ( $all_caps as $cap => $grant ) {
			$admin->add_cap( $cap, true );
		}
	}

	/**
	 * Elimina los roles al desinstalar el plugin.
	 */
	public static function remove_roles(): void {
		foreach ( array_keys( self::ROLE_DEFINITIONS ) as $slug ) {
			remove_role( $slug );
		}
	}
}
