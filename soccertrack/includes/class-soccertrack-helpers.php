<?php
/**
 * Utilidades compartidas del plugin SoccerTrack.
 *
 * Diferencias con CalibraTrack (PHP 7.4):
 *  - Se usan enums nativos en lugar de arrays manuales para las listas de opciones.
 *  - Union types en firmas de métodos (PHP 8.0+).
 *  - Constructor property promotion donde aplique.
 *  - Nullsafe operator (?->) en lugar de isset() anidados.
 *
 * @package SoccerTrack
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SoccerTrack_Helpers {

	/**
	 * Tipos de evento disponibles para un partido.
	 * Delega al enum TipoEvento — fuente única de verdad.
	 *
	 * @return array<string, string>
	 */
	public static function get_tipos_evento(): array {
		return TipoEvento::options();
	}

	/**
	 * Estados posibles de un partido.
	 *
	 * @return array<string, string>
	 */
	public static function get_estados_partido(): array {
		return EstadoPartido::options();
	}

	/**
	 * Fases de un torneo.
	 *
	 * @return array<string, string>
	 */
	public static function get_fases_torneo(): array {
		return FaseTorneo::options();
	}

	/**
	 * Formatea un resultado de partido como cadena legible.
	 *
	 * @param int|null $goles_local    Goles del equipo local.
	 * @param int|null $goles_visita   Goles del equipo visitante.
	 */
	public static function formato_resultado( ?int $goles_local, ?int $goles_visita ): string {
		if ( $goles_local === null || $goles_visita === null ) {
			return __( 'Por jugar', 'soccertrack' );
		}

		return "{$goles_local} - {$goles_visita}";
	}

	/**
	 * Sanitiza un nombre propio (jugador, equipo).
	 * Versión PHP 8.2: usa mb_convert_case en lugar de ucwords manual.
	 */
	public static function sanitize_nombre( string $nombre ): string {
		$nombre = sanitize_text_field( $nombre );
		return mb_convert_case( $nombre, MB_CASE_TITLE, 'UTF-8' );
	}

	/**
	 * Genera el slug URL de un torneo a partir de su nombre.
	 */
	public static function slug_torneo( string $nombre ): string {
		return sanitize_title( $nombre );
	}

	/**
	 * Retorna el ID del torneo activo (si existe).
	 * Usa nullsafe operator — PHP 8.0+.
	 *
	 * @return int|null ID del torneo o null si no hay ninguno activo.
	 */
	public static function get_torneo_activo_id(): ?int {
		$posts = get_posts( [
			'post_type'      => 'st_torneo',
			'post_status'    => 'publish',
			'meta_key'       => '_st_torneo_activo',
			'meta_value'     => '1',
			'posts_per_page' => 1,
			'fields'         => 'ids',
		] );

		return $posts[0] ?? null;
	}

	/**
	 * Verifica si un valor corresponde a un caso válido de un BackedEnum.
	 *
	 * @template T of \BackedEnum
	 * @param  class-string<T> $enum_class  Nombre completo del enum.
	 * @param  string|int      $value       Valor a verificar.
	 * @return bool
	 */
	public static function is_valid_enum( string $enum_class, string|int $value ): bool {
		return $enum_class::tryFrom( $value ) !== null;
	}
}
