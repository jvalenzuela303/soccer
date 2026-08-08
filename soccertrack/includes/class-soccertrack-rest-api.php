<?php
/**
 * API REST de SoccerTrack.
 *
 * Endpoints públicos (sin autenticación):
 *  GET /wp-json/soccertrack/v1/torneo/{id}/tabla   → tabla de posiciones
 *  GET /wp-json/soccertrack/v1/torneo/{id}/partidos → fixtures del torneo
 *
 * PHP 8.2: readonly properties en clase de respuesta, named arguments,
 * union types en parámetros.
 *
 * @package SoccerTrack
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SoccerTrack_REST_API {

	private const NAMESPACE = 'soccertrack/v1';

	public static function init(): void {
		add_action( 'rest_api_init', [ self::class, 'register_routes' ] );
	}

	public static function register_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/torneo/(?P<id>\d+)/tabla',
			[
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => [ self::class, 'get_tabla_posiciones' ],
				'permission_callback' => '__return_true',
				'args'                => [
					'id' => [
						'validate_callback' => static fn( string|int $param ) => is_numeric( $param ),
						'sanitize_callback' => 'absint',
					],
				],
			]
		);

		register_rest_route(
			self::NAMESPACE,
			'/torneo/(?P<id>\d+)/partidos',
			[
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => [ self::class, 'get_partidos' ],
				'permission_callback' => '__return_true',
				'args'                => [
					'id' => [
						'validate_callback' => static fn( string|int $param ) => is_numeric( $param ),
						'sanitize_callback' => 'absint',
					],
					'fase' => [
						'required'          => false,
						'sanitize_callback' => 'sanitize_text_field',
						'validate_callback' => static fn( string $value ) => SoccerTrack_Helpers::is_valid_enum( FaseTorneo::class, $value ),
					],
				],
			]
		);
	}

	/**
	 * GET /torneo/{id}/tabla
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function get_tabla_posiciones( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		global $wpdb;

		$torneo_id = $request->get_param( 'id' );

		// Verificar que el torneo existe.
		if ( 'st_torneo' !== get_post_type( $torneo_id ) ) {
			return new \WP_Error( 'torneo_no_encontrado', __( 'Torneo no encontrado.', 'soccertrack' ), [ 'status' => 404 ] );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT tp.*, p.post_title AS equipo_nombre
				 FROM {$wpdb->prefix}st_tabla_posiciones tp
				 INNER JOIN {$wpdb->posts} p ON p.ID = tp.equipo_id
				 WHERE tp.torneo_id = %d
				 ORDER BY tp.grupo ASC, tp.puntos DESC, (tp.gf - tp.gc) DESC",
				$torneo_id
			),
			ARRAY_A
		);

		return rest_ensure_response( $rows );
	}

	/**
	 * GET /torneo/{id}/partidos
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function get_partidos( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$torneo_id = $request->get_param( 'id' );
		$fase      = $request->get_param( 'fase' );

		if ( 'st_torneo' !== get_post_type( $torneo_id ) ) {
			return new \WP_Error( 'torneo_no_encontrado', __( 'Torneo no encontrado.', 'soccertrack' ), [ 'status' => 404 ] );
		}

		$meta_query = [
			[ 'key' => '_st_partido_torneo_id', 'value' => $torneo_id, 'type' => 'NUMERIC' ],
		];

		if ( $fase !== null ) {
			$meta_query[] = [ 'key' => '_st_partido_fase', 'value' => $fase ];
		}

		$partidos = get_posts( [
			'post_type'      => 'st_partido',
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'meta_query'     => $meta_query, // phpcs:ignore WordPress.DB.SlowDBQuery
			'orderby'        => 'meta_value',
			'meta_key'       => '_st_partido_fecha',
			'order'          => 'ASC',
		] );

		$data = array_map(
			static fn( \WP_Post $p ) => [
				'id'           => $p->ID,
				'fase'         => get_post_meta( $p->ID, '_st_partido_fase', true ),
				'grupo'        => get_post_meta( $p->ID, '_st_partido_grupo', true ),
				'fecha'        => get_post_meta( $p->ID, '_st_partido_fecha', true ),
				'cancha'       => get_post_meta( $p->ID, '_st_partido_cancha', true ),
				'estado'       => get_post_meta( $p->ID, '_st_partido_estado', true ),
				'local_id'     => (int) get_post_meta( $p->ID, '_st_partido_local_id', true ),
				'visita_id'    => (int) get_post_meta( $p->ID, '_st_partido_visita_id', true ),
				'goles_local'  => (int) get_post_meta( $p->ID, '_st_partido_goles_local', true ),
				'goles_visita' => (int) get_post_meta( $p->ID, '_st_partido_goles_vis', true ),
			],
			$partidos
		);

		return rest_ensure_response( $data );
	}
}
