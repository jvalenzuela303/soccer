<?php
/**
 * Gestión de tablas custom de SoccerTrack.
 *
 * Tablas:
 *  - {prefix}st_eventos_partido : goles, tarjetas, sustituciones con minuto.
 *  - {prefix}st_tabla_posiciones: caché calculado de la tabla por torneo/grupo.
 *
 * Usa dbDelta() para creación y migración — sin privilegio SUPER requerido.
 * Compatible con MariaDB 10.6 en hosting compartido.
 *
 * @package SoccerTrack
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SoccerTrack_DB {

	/**
	 * Crea o actualiza las tablas.
	 * Se llama en activación y en maybe_upgrade().
	 */
	public static function create_tables(): void {
		global $wpdb;

		$charset_collate = $wpdb->get_charset_collate();

		// Tabla de eventos por partido (goles, tarjetas, sustituciones).
		$sql_eventos = "CREATE TABLE {$wpdb->prefix}st_eventos_partido (
			id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			partido_id    BIGINT UNSIGNED NOT NULL,
			jugador_id    BIGINT UNSIGNED NOT NULL,
			equipo_id     BIGINT UNSIGNED NOT NULL,
			tipo_evento   VARCHAR(30)     NOT NULL,
			minuto        TINYINT UNSIGNED NOT NULL DEFAULT 0,
			minuto_extra  TINYINT UNSIGNED NOT NULL DEFAULT 0,
			descripcion   TEXT,
			creado_en     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY idx_partido (partido_id),
			KEY idx_jugador (jugador_id)
		) {$charset_collate};";

		// Tabla de tabla de posiciones (caché por torneo + grupo).
		$sql_tabla = "CREATE TABLE {$wpdb->prefix}st_tabla_posiciones (
			id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			torneo_id     BIGINT UNSIGNED NOT NULL,
			grupo         VARCHAR(10)     NOT NULL DEFAULT 'A',
			equipo_id     BIGINT UNSIGNED NOT NULL,
			pj            TINYINT UNSIGNED NOT NULL DEFAULT 0,
			pg            TINYINT UNSIGNED NOT NULL DEFAULT 0,
			pe            TINYINT UNSIGNED NOT NULL DEFAULT 0,
			pp            TINYINT UNSIGNED NOT NULL DEFAULT 0,
			gf            SMALLINT UNSIGNED NOT NULL DEFAULT 0,
			gc            SMALLINT UNSIGNED NOT NULL DEFAULT 0,
			puntos        TINYINT UNSIGNED NOT NULL DEFAULT 0,
			actualizado   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			UNIQUE KEY uniq_torneo_grupo_equipo (torneo_id, grupo, equipo_id),
			KEY idx_torneo_grupo (torneo_id, grupo)
		) {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql_eventos );
		dbDelta( $sql_tabla );

		update_option( 'soccertrack_db_version', SOCCERTRACK_DB_VERSION );
	}

	/**
	 * Verifica si la versión de DB en BD difiere de la constante del plugin
	 * y ejecuta create_tables() para aplicar cambios pendientes.
	 */
	public static function maybe_upgrade(): void {
		$installed = get_option( 'soccertrack_db_version', '0' );

		if ( version_compare( $installed, SOCCERTRACK_DB_VERSION, '<' ) ) {
			self::create_tables();
		}
	}

	/**
	 * Elimina todas las tablas custom del plugin.
	 * Se llama desde uninstall.php únicamente.
	 */
	public static function drop_tables(): void {
		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.SchemaChange
		$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}st_eventos_partido" );
		$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}st_tabla_posiciones" );
		// phpcs:enable
	}
}
