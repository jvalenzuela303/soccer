<?php
/**
 * SoccerTrack — Exportador de torneo a SQL
 *
 * Genera un archivo SQL con todos los datos del torneo especificado,
 * listo para importar directamente en otra base de datos WordPress.
 *
 * ─── USO ──────────────────────────────────────────────────────────────────────
 * wp eval-file wp-content/plugins/soccertrack/scripts/export-tournament.php -- --tournament=17
 * wp eval-file wp-content/plugins/soccertrack/scripts/export-tournament.php -- --tournament=17 --out=/tmp/torneo17.sql
 * ─────────────────────────────────────────────────────────────────────────────
 *
 * Si el prefijo de la BD destino es distinto al de origen, busca y reemplaza:
 *   sed 's/wpq7_/wp_/g' torneo17.sql > torneo17_wp.sql
 */

if ( ! defined( 'ABSPATH' ) ) {
	die( "Ejecutar con: wp eval-file ...\n" );
}

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	die( "Este script requiere WP-CLI.\n" );
}

global $wpdb, $argv;

// ── Argumentos ────────────────────────────────────────────────────────────────
$tid     = 0;
$out     = '';
foreach ( $args as $arg ) {
	if ( preg_match( '/^--tournament=(\d+)$/', $arg, $m ) ) {
		$tid = (int) $m[1];
	}
	if ( preg_match( '/^--out=(.+)$/', $arg, $m ) ) {
		$out = trim( $m[1] );
	}
}

// Fallback: leer de argv si WP-CLI no pasó $args
if ( ! $tid ) {
	foreach ( $_SERVER['argv'] ?? [] as $sarg ) {
		if ( preg_match( '/^--tournament=(\d+)$/', $sarg, $m ) ) {
			$tid = (int) $m[1];
		}
		if ( preg_match( '/^--out=(.+)$/', $sarg, $m ) ) {
			$out = trim( $m[1] );
		}
	}
}

if ( ! $tid ) {
	WP_CLI::error( 'Uso: wp eval-file export-tournament.php -- --tournament=<id> [--out=<archivo.sql>]' );
}

// ── Archivo de salida ─────────────────────────────────────────────────────────
if ( ! $out ) {
	$out = WP_CONTENT_DIR . "/plugins/soccertrack/scripts/torneo-{$tid}-export.sql";
}

WP_CLI::log( "⚙️  Exportando torneo ID: {$tid}" );
WP_CLI::log( "📄 Destino: {$out}" );

$lines = export_tournament_sql( $wpdb, $tid );
file_put_contents( $out, implode( "\n", $lines ) . "\n" );

WP_CLI::success( "Exportación completa → {$out}" );

// ═══════════════════════════════════════════════════════════════════════════════
// LÓGICA DE EXPORTACIÓN
// ═══════════════════════════════════════════════════════════════════════════════

function export_tournament_sql( wpdb $wpdb, int $tid ): array {
	$p    = $wpdb->prefix;
	$now  = gmdate( 'Y-m-d H:i:s' );
	$sql  = [];

	$sql[] = "-- =============================================================";
	$sql[] = "-- SoccerTrack — Exportación torneo ID: {$tid}";
	$sql[] = "-- Generado: {$now}";
	$sql[] = "-- Prefijo origen: {$p}";
	$sql[] = "-- Si tu prefijo destino es distinto, reemplázalo antes de importar:";
	$sql[] = "--   sed 's/{$p}/wp_/g' este-archivo.sql > destino.sql";
	$sql[] = "-- =============================================================";
	$sql[] = "";
	$sql[] = "SET FOREIGN_KEY_CHECKS = 0;";
	$sql[] = "SET NAMES utf8mb4;";
	$sql[] = "";

	// ── 1. Torneo ─────────────────────────────────────────────────────────────
	$tournament = $wpdb->get_row(
		$wpdb->prepare( "SELECT * FROM {$p}ds_tournaments WHERE id = %d", $tid ),
		ARRAY_A
	);
	if ( ! $tournament ) {
		WP_CLI::error( "Torneo ID {$tid} no encontrado." );
	}

	$sql[] = "-- 1. TORNEO";
	$sql[] = export_replace_into( $p . 'ds_tournaments', [ $tournament ] );
	$sql[] = "";

	// ── 2. Recintos (venues) vinculados al torneo ─────────────────────────────
	$venue_ids = $wpdb->get_col(
		$wpdb->prepare( "SELECT venue_id FROM {$p}ds_tournament_venues WHERE tournament_id = %d", $tid )
	);

	$venues = [];
	if ( $venue_ids ) {
		$in     = implode( ',', array_map( 'intval', $venue_ids ) );
		$venues = $wpdb->get_results( "SELECT * FROM {$p}ds_venues WHERE id IN ({$in})", ARRAY_A ); // phpcs:ignore
	}

	$sql[] = "-- 2. RECINTOS";
	if ( $venues ) {
		$sql[] = export_replace_into( $p . 'ds_venues', $venues );
	} else {
		$sql[] = "-- (sin recintos)";
	}
	$sql[] = "";

	// ── 3. Canchas ────────────────────────────────────────────────────────────
	$courts = [];
	if ( $venue_ids ) {
		$in     = implode( ',', array_map( 'intval', $venue_ids ) );
		$courts = $wpdb->get_results( "SELECT * FROM {$p}ds_courts WHERE venue_id IN ({$in})", ARRAY_A ); // phpcs:ignore
	}

	$sql[] = "-- 3. CANCHAS";
	if ( $courts ) {
		$sql[] = export_replace_into( $p . 'ds_courts', $courts );
	} else {
		$sql[] = "-- (sin canchas)";
	}
	$sql[] = "";

	// ── 4. Vínculo torneo ↔ venues ────────────────────────────────────────────
	$tv_rows = $wpdb->get_results(
		$wpdb->prepare( "SELECT * FROM {$p}ds_tournament_venues WHERE tournament_id = %d", $tid ),
		ARRAY_A
	);

	$sql[] = "-- 4. TORNEO ↔ VENUES";
	if ( $tv_rows ) {
		$sql[] = export_replace_into( $p . 'ds_tournament_venues', $tv_rows );
	} else {
		$sql[] = "-- (sin vínculos)";
	}
	$sql[] = "";

	// ── 5. Equipos ────────────────────────────────────────────────────────────
	$teams = $wpdb->get_results(
		$wpdb->prepare( "SELECT * FROM {$p}ds_teams WHERE tournament_id = %d ORDER BY id", $tid ),
		ARRAY_A
	);
	$team_ids = array_column( $teams, 'id' );

	$sql[] = "-- 5. EQUIPOS (" . count( $teams ) . ")";
	if ( $teams ) {
		$sql[] = export_replace_into( $p . 'ds_teams', $teams );
	}
	$sql[] = "";

	// ── 6. Jugadores ──────────────────────────────────────────────────────────
	$player_ids = [];
	$tp_rows    = [];
	if ( $team_ids ) {
		$in      = implode( ',', array_map( 'intval', $team_ids ) );
		$tp_rows = $wpdb->get_results( "SELECT * FROM {$p}ds_team_players WHERE team_id IN ({$in}) ORDER BY id", ARRAY_A ); // phpcs:ignore
		$player_ids = array_unique( array_column( $tp_rows, 'player_id' ) );
	}

	$players = [];
	if ( $player_ids ) {
		$in      = implode( ',', array_map( 'intval', $player_ids ) );
		$players = $wpdb->get_results( "SELECT * FROM {$p}ds_players WHERE id IN ({$in}) ORDER BY id", ARRAY_A ); // phpcs:ignore
	}

	$sql[] = "-- 6. JUGADORES (" . count( $players ) . ")";
	if ( $players ) {
		$sql[] = export_replace_into( $p . 'ds_players', $players );
	}
	$sql[] = "";

	// ── 7. Equipo ↔ Jugadores ─────────────────────────────────────────────────
	$sql[] = "-- 7. EQUIPO ↔ JUGADORES (" . count( $tp_rows ) . ")";
	if ( $tp_rows ) {
		$sql[] = export_replace_into( $p . 'ds_team_players', $tp_rows );
	}
	$sql[] = "";

	// ── 8. Staff (todo el catálogo, es global) ────────────────────────────────
	$staff = $wpdb->get_results( "SELECT * FROM {$p}ds_staff ORDER BY id", ARRAY_A ); // phpcs:ignore

	$sql[] = "-- 8. STAFF (" . count( $staff ) . ")";
	if ( $staff ) {
		$sql[] = export_replace_into( $p . 'ds_staff', $staff );
	}
	$sql[] = "";

	// ── 9. Brackets de play-offs ──────────────────────────────────────────────
	$brackets = $wpdb->get_results(
		$wpdb->prepare( "SELECT * FROM {$p}ds_playoff_brackets WHERE tournament_id = %d ORDER BY id", $tid ),
		ARRAY_A
	);

	$sql[] = "-- 9. BRACKETS DE PLAY-OFFS (" . count( $brackets ) . ")";
	if ( $brackets ) {
		$sql[] = export_replace_into( $p . 'ds_playoff_brackets', $brackets );
	} else {
		$sql[] = "-- (sin brackets)";
	}
	$sql[] = "";

	// ── 10. Partidos ──────────────────────────────────────────────────────────
	$matches = $wpdb->get_results(
		$wpdb->prepare( "SELECT * FROM {$p}ds_matches WHERE tournament_id = %d ORDER BY id", $tid ),
		ARRAY_A
	);

	$sql[] = "-- 10. PARTIDOS (" . count( $matches ) . ")";
	if ( $matches ) {
		// Exportar en bloques de 50 para no saturar el parser SQL
		foreach ( array_chunk( $matches, 50 ) as $chunk ) {
			$sql[] = export_replace_into( $p . 'ds_matches', $chunk );
		}
	}
	$sql[] = "";

	// ── 11. Eventos de partido ────────────────────────────────────────────────
	$events = $wpdb->get_results(
		$wpdb->prepare( "SELECT * FROM {$p}ds_match_events WHERE tournament_id = %d ORDER BY id", $tid ),
		ARRAY_A
	);

	$sql[] = "-- 11. EVENTOS DE PARTIDO (" . count( $events ) . ")";
	if ( $events ) {
		foreach ( array_chunk( $events, 100 ) as $chunk ) {
			$sql[] = export_replace_into( $p . 'ds_match_events', $chunk );
		}
	}
	$sql[] = "";

	// ── 12. Sanciones disciplinarias ─────────────────────────────────────────
	$sanctions = $wpdb->get_results(
		$wpdb->prepare( "SELECT * FROM {$p}ds_disciplinary_sanctions WHERE tournament_id = %d ORDER BY id", $tid ),
		ARRAY_A
	);

	$sql[] = "-- 12. SANCIONES DISCIPLINARIAS (" . count( $sanctions ) . ")";
	if ( $sanctions ) {
		$sql[] = export_replace_into( $p . 'ds_disciplinary_sanctions', $sanctions );
	} else {
		$sql[] = "-- (sin sanciones)";
	}
	$sql[] = "";

	$sql[] = "SET FOREIGN_KEY_CHECKS = 1;";
	$sql[] = "";
	$sql[] = "SELECT CONCAT('✅ Torneo {$tid} importado: ', COUNT(*), ' equipos') AS resultado";
	$sql[] = "FROM `{$p}ds_teams` WHERE tournament_id = {$tid};";

	// Resumen en consola
	WP_CLI::log( "  Torneo:      1 registro" );
	WP_CLI::log( "  Recintos:    " . count( $venues ) );
	WP_CLI::log( "  Canchas:     " . count( $courts ) );
	WP_CLI::log( "  Equipos:     " . count( $teams ) );
	WP_CLI::log( "  Jugadores:   " . count( $players ) );
	WP_CLI::log( "  Team/Player: " . count( $tp_rows ) );
	WP_CLI::log( "  Staff:       " . count( $staff ) );
	WP_CLI::log( "  Brackets:    " . count( $brackets ) );
	WP_CLI::log( "  Partidos:    " . count( $matches ) );
	WP_CLI::log( "  Eventos:     " . count( $events ) );
	WP_CLI::log( "  Sanciones:   " . count( $sanctions ) );

	return $sql;
}

// ═══════════════════════════════════════════════════════════════════════════════
// HELPER: genera un REPLACE INTO multi-fila a partir de array de filas ARRAY_A
// ═══════════════════════════════════════════════════════════════════════════════

function export_replace_into( string $table, array $rows ): string {
	if ( empty( $rows ) ) {
		return '';
	}

	$cols      = array_keys( $rows[0] );
	$col_list  = '`' . implode( '`, `', $cols ) . '`';
	$value_sets = [];

	foreach ( $rows as $row ) {
		$vals = array_map( 'export_escape_value', array_values( $row ) );
		$value_sets[] = '(' . implode( ', ', $vals ) . ')';
	}

	return "REPLACE INTO `{$table}` ({$col_list}) VALUES\n"
		. implode( ",\n", $value_sets ) . ";";
}

/**
 * Escapa un valor para SQL de forma segura sin usar wpdb->prepare()
 * (que requiere un placeholder por valor).
 */
function export_escape_value( mixed $val ): string {
	if ( null === $val ) {
		return 'NULL';
	}
	if ( is_int( $val ) || is_float( $val ) ) {
		return (string) $val;
	}
	// Para strings: escapar caracteres especiales manualmente
	$val = (string) $val;
	$val = str_replace( '\\', '\\\\', $val );
	$val = str_replace( "'",  "\\'",  $val );
	$val = str_replace( "\n", "\\n",  $val );
	$val = str_replace( "\r", "\\r",  $val );
	$val = str_replace( "\0", "\\0",  $val );
	return "'{$val}'";
}
