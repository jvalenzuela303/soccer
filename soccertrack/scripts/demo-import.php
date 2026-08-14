<?php
/**
 * SoccerTrack — Importador de Datos Demo
 *
 * Carga los torneos de prueba desde demo-data.sql:
 *   • Torneo 13 — Corporativo Test 2026 (10 equipos, regular + semis + final)
 *   • Torneo 17 — Copa Corporativa 2026 (16 equipos, octavos + cuartos + semis + final)
 *
 * ─── USO ──────────────────────────────────────────────────────────────────────
 * IMPORTAR:  wp eval-file wp-content/plugins/soccertrack/scripts/demo-import.php
 * ELIMINAR:  wp eval-file wp-content/plugins/soccertrack/scripts/demo-import.php cleanup
 * ─────────────────────────────────────────────────────────────────────────────
 *
 * Los IDs de torneos demo se guardan en la opción WP `soccertrack_demo_import_ids`
 * para que el modo cleanup pueda eliminar solo esos datos sin tocar otros registros.
 */

if ( ! defined( 'ABSPATH' ) ) {
	die( "Ejecutar con: wp eval-file wp-content/plugins/soccertrack/scripts/demo-import.php [cleanup]\n" );
}

// ── Ejecución directa desde WP-CLI ───────────────────────────────────────────
if ( defined( 'WP_CLI' ) && WP_CLI ) {
	global $wpdb;

	$action = isset( $args[0] ) ? trim( (string) $args[0] ) : 'import';

	if ( 'cleanup' === $action ) {
		demo_import_cleanup( $wpdb );
	} else {
		demo_import_run( $wpdb );
	}
}

// ═══════════════════════════════════════════════════════════════════════════════
// IMPORTACIÓN
// ═══════════════════════════════════════════════════════════════════════════════

function demo_import_run( wpdb $wpdb ): void {

	if ( get_option( 'soccertrack_demo_import_ids' ) ) {
		demo_import_log( '⚠️  Ya existen datos demo importados. Ejecuta con "cleanup" primero.' );
		return;
	}

	$sql_file = __DIR__ . '/demo-data.sql';

	if ( ! file_exists( $sql_file ) ) {
		demo_import_log( '❌ No se encontró demo-data.sql en ' . $sql_file );
		return;
	}

	demo_import_log( '📥 Importando datos demo desde demo-data.sql...' );

	// Leer y adaptar el SQL al prefijo real de tablas
	$sql_raw = file_get_contents( $sql_file );
	// Adaptar prefijo de tablas (wp_ → prefijo real, aplica tanto a INSERT como a REPLACE INTO)
	$sql_raw = str_replace( '`wp_ds_', '`' . $wpdb->prefix . 'ds_', $sql_raw );

	// Ejecutar solo las líneas de datos (REPLACE/INSERT, una por línea en el dump)
	$wpdb->query( 'SET FOREIGN_KEY_CHECKS=0' );
	$wpdb->query( 'SET UNIQUE_CHECKS=0' );

	$ok    = 0;
	$fails = 0;
	foreach ( explode( "\n", $sql_raw ) as $line ) {
		$line = rtrim( $line );
		if ( '' === $line || str_starts_with( $line, '--' ) || str_starts_with( $line, 'SET' ) ) {
			continue;
		}
		$stmt = rtrim( $line, ';' );
		if ( false === $wpdb->query( $stmt ) ) { // phpcs:ignore WordPress.DB.PreparedSQL
			++$fails;
			demo_import_log( '  ⚠️  Error: ' . substr( $line, 0, 80 ) . '...' );
			demo_import_log( '     DB error: ' . $wpdb->last_error );
		} else {
			++$ok;
		}
	}

	$wpdb->query( 'SET FOREIGN_KEY_CHECKS=1' );
	$wpdb->query( 'SET UNIQUE_CHECKS=1' );

	demo_import_log( "✅ Statements ejecutados: {$ok} OK / {$fails} errores" );

	if ( $fails > 0 ) {
		demo_import_log( '⚠️  Hubo errores al importar. Verifica el log anterior.' );
		return;
	}

	// Leer los IDs de torneos importados directamente del SQL (son fijos en el archivo)
	$tournament_ids = demo_import_parse_ids_from_sql( $sql_raw, $wpdb->prefix . 'ds_tournaments' );
	$team_ids       = demo_import_parse_ids_from_sql( $sql_raw, $wpdb->prefix . 'ds_teams' );
	$player_ids     = demo_import_parse_ids_from_sql( $sql_raw, $wpdb->prefix . 'ds_players' );
	$venue_ids      = demo_import_parse_ids_from_sql( $sql_raw, $wpdb->prefix . 'ds_venues' );
	$court_ids      = demo_import_parse_ids_from_sql( $sql_raw, $wpdb->prefix . 'ds_courts' );
	$staff_ids      = demo_import_parse_ids_from_sql( $sql_raw, $wpdb->prefix . 'ds_staff' );

	$tracked = compact( 'tournament_ids', 'team_ids', 'player_ids', 'venue_ids', 'court_ids', 'staff_ids' );
	update_option( 'soccertrack_demo_import_ids', $tracked, false );

	$tid_list = implode( ', ', $tournament_ids );
	demo_import_log( '' );
	demo_import_log( '🏆 ¡Datos demo importados correctamente!' );
	demo_import_log( "   Torneos importados: [{$tid_list}]" );
	demo_import_log( '   Para eliminar: wp eval-file scripts/demo-import.php cleanup' );
}

// ═══════════════════════════════════════════════════════════════════════════════
// CLEANUP
// ═══════════════════════════════════════════════════════════════════════════════

function demo_import_cleanup( wpdb $wpdb ): void {
	$tracked = get_option( 'soccertrack_demo_import_ids', null );

	if ( ! $tracked ) {
		demo_import_log( '⚠️  No se encontraron datos demo importados. Nada que eliminar.' );
		return;
	}

	demo_import_log( '🗑️  Eliminando datos demo importados...' );

	$p = $wpdb->prefix;

	$tids    = array_map( 'intval', (array) ( $tracked['tournament_ids'] ?? [] ) );
	$vids    = array_map( 'intval', (array) ( $tracked['venue_ids']      ?? [] ) );
	$cids    = array_map( 'intval', (array) ( $tracked['court_ids']      ?? [] ) );
	$sids    = array_map( 'intval', (array) ( $tracked['staff_ids']      ?? [] ) );
	$tids_q  = implode( ',', $tids ?: [0] );
	$vids_q  = implode( ',', $vids ?: [0] );
	$cids_q  = implode( ',', $cids ?: [0] );
	$sids_q  = implode( ',', $sids ?: [0] );

	// Eliminar en orden inverso de dependencia
	$wpdb->query( "DELETE FROM {$p}ds_disciplinary_sanctions WHERE tournament_id IN ({$tids_q})" ); // phpcs:ignore
	$wpdb->query( "DELETE FROM {$p}ds_match_events            WHERE tournament_id IN ({$tids_q})" ); // phpcs:ignore
	$wpdb->query( "DELETE FROM {$p}ds_matches                 WHERE tournament_id IN ({$tids_q})" ); // phpcs:ignore
	$wpdb->query( "DELETE FROM {$p}ds_playoff_brackets        WHERE tournament_id IN ({$tids_q})" ); // phpcs:ignore

	$team_ids = $wpdb->get_col( "SELECT id FROM {$p}ds_teams WHERE tournament_id IN ({$tids_q})" ); // phpcs:ignore
	if ( ! empty( $team_ids ) ) {
		$team_ids_q = implode( ',', array_map( 'intval', $team_ids ) );
		$player_ids = $wpdb->get_col( "SELECT player_id FROM {$p}ds_team_players WHERE team_id IN ({$team_ids_q})" ); // phpcs:ignore
		$wpdb->query( "DELETE FROM {$p}ds_team_players WHERE team_id IN ({$team_ids_q})" ); // phpcs:ignore
		if ( ! empty( $player_ids ) ) {
			$player_ids_q = implode( ',', array_map( 'intval', $player_ids ) );
			$wpdb->query( "DELETE FROM {$p}ds_players WHERE id IN ({$player_ids_q})" ); // phpcs:ignore
		}
	}

	$wpdb->query( "DELETE FROM {$p}ds_teams                   WHERE tournament_id IN ({$tids_q})" ); // phpcs:ignore
	$wpdb->query( "DELETE FROM {$p}ds_tournament_venues       WHERE tournament_id IN ({$tids_q})" ); // phpcs:ignore
	$wpdb->query( "DELETE FROM {$p}ds_tournaments             WHERE id IN ({$tids_q})" ); // phpcs:ignore

	if ( $vids ) {
		$wpdb->query( "DELETE FROM {$p}ds_courts WHERE id IN ({$cids_q})" ); // phpcs:ignore
		$wpdb->query( "DELETE FROM {$p}ds_venues WHERE id IN ({$vids_q})" ); // phpcs:ignore
	}

	if ( $sids ) {
		$wpdb->query( "DELETE FROM {$p}ds_staff WHERE id IN ({$sids_q})" ); // phpcs:ignore
	}

	delete_option( 'soccertrack_demo_import_ids' );

	demo_import_log( '✅ Datos demo eliminados correctamente.' );
}

// ═══════════════════════════════════════════════════════════════════════════════
// UTILIDADES
// ═══════════════════════════════════════════════════════════════════════════════

/**
 * Extrae los IDs de la primera columna del primer REPLACE/INSERT de la tabla indicada en el SQL.
 * Asume que el dump tiene --complete-insert y que el id es la primera columna.
 *
 * @param string $sql_raw   SQL completo ya con el prefijo real.
 * @param string $full_table Nombre completo de la tabla (ej. wp_ds_tournaments).
 * @return int[]
 */
function demo_import_parse_ids_from_sql( string $sql_raw, string $full_table ): array {
	// Busca la línea: REPLACE INTO `{full_table}` (`id`, ...) VALUES (id1,...),(id2,...),...;
	$pattern = '/(?:REPLACE|INSERT)\s+INTO\s+`' . preg_quote( $full_table, '/' ) . '`\s+\([^)]+\)\s+VALUES\s+(.*);$/m';
	if ( ! preg_match( $pattern, $sql_raw, $m ) ) {
		return [];
	}
	// Extraer primer valor de cada grupo (...)
	preg_match_all( '/\((\d+),/', $m[1], $ids );
	return array_map( 'intval', $ids[1] );
}

/**
 * Retorna array de IDs actuales de la tabla indicada.
 */
function demo_import_existing_ids( wpdb $wpdb, string $table ): array {
	$full = $wpdb->prefix . $table;
	return array_map( 'intval', $wpdb->get_col( "SELECT id FROM {$full}" ) ); // phpcs:ignore
}

function demo_import_log( string $msg ): void {
	if ( class_exists( 'WP_CLI' ) ) {
		WP_CLI::log( $msg );
	} else {
		echo $msg . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput
	}
}
