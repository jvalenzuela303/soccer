<?php
/**
 * Integración del plugin con el admin de WordPress.
 *
 * Agrega columnas custom en las listas de CPTs y meta boxes de edición.
 * PHP 8.2: readonly constructor promotion en DTO de columna.
 *
 * @package SoccerTrack
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SoccerTrack_Admin {

	public static function init(): void {
		// Columnas en lista de partidos.
		add_filter( 'manage_st_partido_posts_columns', [ self::class, 'partido_columns' ] );
		add_action( 'manage_st_partido_posts_custom_column', [ self::class, 'partido_column_content' ], 10, 2 );

		// Columnas en lista de jugadores.
		add_filter( 'manage_st_jugador_posts_columns', [ self::class, 'jugador_columns' ] );
		add_action( 'manage_st_jugador_posts_custom_column', [ self::class, 'jugador_column_content' ], 10, 2 );
	}

	/** @param array<string, string> $columns */
	public static function partido_columns( array $columns ): array {
		$new = [];

		foreach ( $columns as $key => $label ) {
			$new[ $key ] = $label;
			if ( $key === 'title' ) {
				$new['st_fecha']   = __( 'Fecha', 'soccertrack' );
				$new['st_estado']  = __( 'Estado', 'soccertrack' );
				$new['st_fase']    = __( 'Fase', 'soccertrack' );
				$new['st_resultado'] = __( 'Resultado', 'soccertrack' );
			}
		}

		return $new;
	}

	public static function partido_column_content( string $column, int $post_id ): void {
		match( $column ) {
			'st_fecha'     => print( esc_html( get_post_meta( $post_id, '_st_partido_fecha', true ) ?: '—' ) ),
			'st_estado'    => print( esc_html( EstadoPartido::tryFrom( get_post_meta( $post_id, '_st_partido_estado', true ) )?->label() ?? '—' ) ),
			'st_fase'      => print( esc_html( FaseTorneo::tryFrom( get_post_meta( $post_id, '_st_partido_fase', true ) )?->label() ?? '—' ) ),
			'st_resultado' => print( esc_html( SoccerTrack_Helpers::formato_resultado(
				(int) get_post_meta( $post_id, '_st_partido_goles_local', true ) ?: null,
				(int) get_post_meta( $post_id, '_st_partido_goles_vis', true ) ?: null
			) ) ),
			default        => null,
		};
	}

	/** @param array<string, string> $columns */
	public static function jugador_columns( array $columns ): array {
		$new = [];

		foreach ( $columns as $key => $label ) {
			$new[ $key ] = $label;
			if ( $key === 'title' ) {
				$new['st_dorsal']   = __( 'Dorsal', 'soccertrack' );
				$new['st_posicion'] = __( 'Posición', 'soccertrack' );
				$new['st_equipo']   = __( 'Equipo', 'soccertrack' );
			}
		}

		return $new;
	}

	public static function jugador_column_content( string $column, int $post_id ): void {
		match( $column ) {
			'st_dorsal'   => print( esc_html( get_post_meta( $post_id, '_st_jugador_dorsal', true ) ?: '—' ) ),
			'st_posicion' => print( esc_html( get_post_meta( $post_id, '_st_jugador_posicion', true ) ?: '—' ) ),
			'st_equipo'   => print( esc_html( get_the_title( (int) get_post_meta( $post_id, '_st_jugador_equipo_id', true ) ) ?: '—' ) ),
			default       => null,
		};
	}
}
