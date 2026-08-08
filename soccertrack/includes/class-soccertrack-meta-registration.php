<?php
/**
 * Registro de meta fields de SoccerTrack en la REST API y bloque de edición.
 *
 * PHP 8.2: usa named arguments en register_post_meta() para mayor claridad.
 *
 * @package SoccerTrack
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SoccerTrack_Meta_Registration {

	public static function register_all(): void {
		self::register_torneo_meta();
		self::register_equipo_meta();
		self::register_jugador_meta();
		self::register_partido_meta();
	}

	private static function register_torneo_meta(): void {
		$fields = [
			'_st_torneo_activo'      => [ 'type' => 'boolean', 'description' => 'Si el torneo es el activo actualmente.' ],
			'_st_torneo_fecha_inicio'=> [ 'type' => 'string',  'description' => 'Fecha de inicio (YYYY-MM-DD).' ],
			'_st_torneo_fecha_fin'   => [ 'type' => 'string',  'description' => 'Fecha de cierre (YYYY-MM-DD).' ],
			'_st_torneo_formato'     => [ 'type' => 'string',  'description' => 'Formato: grupos_eliminacion, liga, copa.' ],
			'_st_torneo_grupos'      => [ 'type' => 'integer', 'description' => 'Número de grupos en fase inicial.' ],
		];

		foreach ( $fields as $key => $args ) {
			register_post_meta(
				'st_torneo',
				$key,
				[
					'type'         => $args['type'],
					'description'  => $args['description'],
					'single'       => true,
					'show_in_rest' => true,
					'auth_callback' => static fn() => current_user_can( 'edit_st_torneos' ),
				]
			);
		}
	}

	private static function register_equipo_meta(): void {
		$fields = [
			'_st_equipo_torneo_id' => [ 'type' => 'integer', 'description' => 'ID del torneo al que pertenece.' ],
			'_st_equipo_grupo'     => [ 'type' => 'string',  'description' => 'Grupo (A, B, C…).' ],
			'_st_equipo_ciudad'    => [ 'type' => 'string',  'description' => 'Ciudad del equipo.' ],
			'_st_equipo_colores'   => [ 'type' => 'string',  'description' => 'Colores del equipo.' ],
			'_st_equipo_dt'        => [ 'type' => 'string',  'description' => 'Nombre del director técnico.' ],
		];

		foreach ( $fields as $key => $args ) {
			register_post_meta(
				'st_equipo',
				$key,
				[
					'type'          => $args['type'],
					'description'   => $args['description'],
					'single'        => true,
					'show_in_rest'  => true,
					'auth_callback' => static fn() => current_user_can( 'edit_st_equipos' ),
				]
			);
		}
	}

	private static function register_jugador_meta(): void {
		$fields = [
			'_st_jugador_equipo_id'  => [ 'type' => 'integer', 'description' => 'ID del equipo.' ],
			'_st_jugador_dorsal'     => [ 'type' => 'integer', 'description' => 'Número de dorsal.' ],
			'_st_jugador_posicion'   => [ 'type' => 'string',  'description' => 'Posición: portero, defensa, mediocampista, delantero.' ],
			'_st_jugador_fecha_nac'  => [ 'type' => 'string',  'description' => 'Fecha de nacimiento (YYYY-MM-DD).' ],
			'_st_jugador_documento'  => [ 'type' => 'string',  'description' => 'RUT/DNI del jugador.' ],
		];

		foreach ( $fields as $key => $args ) {
			register_post_meta(
				'st_jugador',
				$key,
				[
					'type'          => $args['type'],
					'description'   => $args['description'],
					'single'        => true,
					'show_in_rest'  => false, // Datos sensibles — no exponer en REST público.
					'auth_callback' => static fn() => current_user_can( 'edit_st_jugadores' ),
				]
			);
		}
	}

	private static function register_partido_meta(): void {
		$fields = [
			'_st_partido_torneo_id'   => [ 'type' => 'integer', 'description' => 'ID del torneo.' ],
			'_st_partido_fase'        => [ 'type' => 'string',  'description' => 'Fase: fase_grupos, octavos, cuartos, semifinal, tercer_puesto, final.' ],
			'_st_partido_grupo'       => [ 'type' => 'string',  'description' => 'Grupo (solo para fase de grupos).' ],
			'_st_partido_local_id'    => [ 'type' => 'integer', 'description' => 'ID del equipo local.' ],
			'_st_partido_visita_id'   => [ 'type' => 'integer', 'description' => 'ID del equipo visitante.' ],
			'_st_partido_fecha'       => [ 'type' => 'string',  'description' => 'Fecha y hora del partido (YYYY-MM-DD HH:MM).' ],
			'_st_partido_cancha'      => [ 'type' => 'string',  'description' => 'Nombre o dirección de la cancha.' ],
			'_st_partido_estado'      => [ 'type' => 'string',  'description' => 'Estado: programado, en_curso, finalizado, suspendido, aplazado.' ],
			'_st_partido_goles_local' => [ 'type' => 'integer', 'description' => 'Goles del equipo local.' ],
			'_st_partido_goles_vis'   => [ 'type' => 'integer', 'description' => 'Goles del equipo visitante.' ],
			'_st_partido_arbitro'     => [ 'type' => 'string',  'description' => 'Nombre del árbitro principal.' ],
		];

		foreach ( $fields as $key => $args ) {
			register_post_meta(
				'st_partido',
				$key,
				[
					'type'          => $args['type'],
					'description'   => $args['description'],
					'single'        => true,
					'show_in_rest'  => true,
					'auth_callback' => static fn() => current_user_can( 'edit_st_partidos' ),
				]
			);
		}
	}
}
