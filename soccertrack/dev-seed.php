<?php
/**
 * Script de datos de prueba — SoccerTrack.
 *
 * Uso: wp eval-file soccertrack/dev-seed.php
 *
 * Crea árbitros y coordinadores de prueba.
 * NO ejecutar en producción.
 *
 * @package SoccerTrack
 */

if ( ! defined( 'ABSPATH' ) ) {
	die( 'Ejecutar únicamente vía WP-CLI: wp eval-file dev-seed.php' . PHP_EOL );
}

$arbitros = [
	[
		'name'     => 'Carlos Mendoza',
		'email'    => 'c.mendoza.arbitro@test.local',
		'password' => 'Test1234!',
	],
	[
		'name'     => 'Roberto Fuentes',
		'email'    => 'r.fuentes.arbitro@test.local',
		'password' => 'Test1234!',
	],
	[
		'name'     => 'Andrés Vidal',
		'email'    => 'a.vidal.arbitro@test.local',
		'password' => 'Test1234!',
	],
	[
		'name'     => 'Miguel Herrera',
		'email'    => 'm.herrera.arbitro@test.local',
		'password' => 'Test1234!',
	],
];

foreach ( $arbitros as $data ) {
	if ( email_exists( $data['email'] ) ) {
		WP_CLI::warning( "Ya existe: {$data['email']} — omitido." );
		continue;
	}

	$result = \SportsLeague\Core\UserManager::create(
		$data['email'],
		$data['name'],
		'ds_arbitro',
		$data['password']
	);

	if ( is_wp_error( $result ) ) {
		WP_CLI::error( "Error al crear {$data['name']}: " . $result->get_error_message(), false );
	} else {
		WP_CLI::success( "Árbitro creado — ID {$result}: {$data['name']} <{$data['email']}> / pass: {$data['password']}" );
	}
}

// ── Coordinadores ────────────────────────────────────────────────────────────
$coordinadores = [
	[
		'name'     => 'Laura Espinoza',
		'email'    => 'l.espinoza.coordinador@test.local',
		'password' => 'Test1234!',
	],
	[
		'name'     => 'Patricio Rojas',
		'email'    => 'p.rojas.coordinador@test.local',
		'password' => 'Test1234!',
	],
];

foreach ( $coordinadores as $data ) {
	if ( email_exists( $data['email'] ) ) {
		WP_CLI::warning( "Ya existe: {$data['email']} — omitido." );
		continue;
	}

	$result = \SportsLeague\Core\UserManager::create(
		$data['email'],
		$data['name'],
		'ds_coordinador',
		$data['password']
	);

	if ( is_wp_error( $result ) ) {
		WP_CLI::error( "Error al crear {$data['name']}: " . $result->get_error_message(), false );
	} else {
		WP_CLI::success( "Coordinador creado — ID {$result}: {$data['name']} <{$data['email']}> / pass: {$data['password']}" );
	}
}
