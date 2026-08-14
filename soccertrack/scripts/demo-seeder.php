<?php
/**
 * SoccerTrack — Seeder de Datos Demo
 *
 * Genera un torneo completo de prueba "Todos contra todos + Play-offs":
 *   • 16 equipos empresariales con delegados y logos
 *   • 20 jugadores por equipo (320 total), con RUT ficticio
 *   • 2 recintos deportivos con 4 canchas cada uno
 *   • 10 árbitros y 10 planilleros inventados
 *   • Fixture completo (15 jornadas × 8 partidos), miércoles/jueves/viernes
 *   • Resultados simulados con goles, tarjetas amarillas/rojas
 *   • 1 bracket de play-offs Copa Principal (posiciones 1–16) con todas las fases:
 *       - Octavos de final  (8 partidos, 1°vs16°, 2°vs15°, …, 8°vs9°)
 *       - Cuartos de final  (4 partidos, ganadores de octavos)
 *       - Semifinales       (2 partidos, ganadores de cuartos)
 *       - Tercer puesto     (1 partido)
 *       - Final             (1 partido)
 *   • Play-offs simulados hasta la final
 *
 * ─── USO ──────────────────────────────────────────────────────────────────────
 * INSERTAR:  wp eval-file wp-content/plugins/soccertrack/scripts/demo-seeder.php
 * ELIMINAR:  wp eval-file wp-content/plugins/soccertrack/scripts/demo-seeder.php cleanup
 * ─────────────────────────────────────────────────────────────────────────────
 *
 * Los IDs creados se guardan en la opción WP `soccertrack_demo_seed_ids`
 * para que cleanup pueda eliminar solo los datos demo sin tocar otros registros.
 */

if ( ! defined( 'ABSPATH' ) ) {
	die( "Ejecutar con: wp eval-file wp-content/plugins/soccertrack/scripts/demo-seeder.php [cleanup]\n" );
}

// ── Ejecución directa desde WP-CLI ───────────────────────────────────────────
if ( defined( 'WP_CLI' ) && WP_CLI ) {
	global $wpdb;

	$action = isset( $args[0] ) ? trim( (string) $args[0] ) : 'seed';

	if ( 'cleanup' === $action ) {
		demo_cleanup( $wpdb );
	} else {
		demo_seed( $wpdb );
	}
}

// ═══════════════════════════════════════════════════════════════════════════════
// SEED PRINCIPAL
// ═══════════════════════════════════════════════════════════════════════════════

function demo_seed( wpdb $wpdb ): void {

	if ( get_option( 'soccertrack_demo_seed_ids' ) ) {
		demo_log( '⚠️  Ya existen datos demo. Ejecuta con "cleanup" primero.' );
		return;
	}

	demo_log( '🌱 Iniciando seeder de datos demo...' );

	$tracked = [];

	// 1. Torneo
	$tid                      = demo_insert_tournament( $wpdb );
	$tracked['tournament_id'] = $tid;
	demo_log( "✅ Torneo creado (ID: {$tid})" );

	// 2. Recintos y canchas
	[ $venue_ids, $court_map ] = demo_insert_venues( $wpdb, $tid );
	$tracked['venue_ids']      = $venue_ids;
	$tracked['court_ids']      = array_merge( ...array_values( $court_map ) );
	demo_log( '✅ Recintos: ' . count( $venue_ids ) . ' | Canchas: ' . count( $tracked['court_ids'] ) );

	// 3. Equipos
	$team_data              = demo_insert_teams( $wpdb, $tid );
	$team_ids               = array_column( $team_data, 'id' );
	$tracked['team_ids']    = $team_ids;
	demo_log( '✅ Equipos: ' . count( $team_ids ) );

	// 4. Jugadores
	[ $player_ids, $team_player_map ] = demo_insert_players( $wpdb, $team_data );
	$tracked['player_ids']            = $player_ids;
	demo_log( '✅ Jugadores: ' . count( $player_ids ) );

	// 5. Staff (árbitros / planilleros)
	$staff_ids              = demo_insert_staff( $wpdb );
	$tracked['staff_ids']   = $staff_ids;
	demo_log( '✅ Staff: ' . count( $staff_ids ) );

	// 6. Brackets de play-offs
	$bracket_ids             = demo_insert_brackets( $wpdb, $tid );
	$tracked['bracket_ids']  = $bracket_ids;
	demo_log( '✅ Brackets de play-offs: Copa Principal (octavos → cuartos → semis → final)' );

	// 7. Fixture de fase regular
	$match_ids                    = demo_generate_fixture( $wpdb, $tid, $team_ids, $venue_ids, $court_map );
	$tracked['regular_match_ids'] = $match_ids;
	demo_log( '✅ Fixture regular: ' . count( $match_ids ) . ' partidos (15 jornadas)' );

	// 8. Simular resultados de fase regular
	$event_ids            = demo_simulate_regular( $wpdb, $tid, $match_ids, $team_player_map );
	$tracked['event_ids'] = $event_ids;
	demo_log( '✅ Resultados simulados: ' . count( $event_ids ) . ' eventos (goles + tarjetas)' );

	// 9. Play-offs: generar y simular
	[ $po_match_ids, $po_event_ids ] = demo_simulate_playoffs( $wpdb, $tid, $bracket_ids, $venue_ids, $court_map, $team_player_map );
	$tracked['playoff_match_ids']    = $po_match_ids;
	$tracked['playoff_event_ids']    = $po_event_ids;
	demo_log( '✅ Play-offs: ' . count( $po_match_ids ) . ' partidos (octavos + cuartos + semis + final)' );

	// 10. Sanciones del tribunal
	$sanction_count = demo_seed_sanctions( $wpdb, $tid, $team_player_map );
	demo_log( "✅ Tribunal: {$sanction_count} sanciones generadas (acumulación amarillas + rojas + tribunal)" );

	update_option( 'soccertrack_demo_seed_ids', $tracked, false );

	demo_log( '' );
	demo_log( '🏆 ¡Datos demo insertados correctamente!' );
	demo_log( "   Torneo ID: {$tid}" );
	demo_log( '   Para eliminar: wp eval-file scripts/demo-seeder.php cleanup' );
}

// ═══════════════════════════════════════════════════════════════════════════════
// CLEANUP
// ═══════════════════════════════════════════════════════════════════════════════

function demo_cleanup( wpdb $wpdb ): void {
	$tracked = get_option( 'soccertrack_demo_seed_ids', null );

	if ( ! $tracked ) {
		demo_log( '⚠️  No se encontraron datos demo. Nada que eliminar.' );
		return;
	}

	demo_log( '🗑️  Eliminando datos demo...' );

	$tid    = (int) $tracked['tournament_id'];
	$p      = $wpdb->prefix;

	// Hijos primero (integridad referencial)
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$p}ds_disciplinary_sanctions WHERE tournament_id = %d", $tid ) );
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$p}ds_match_events            WHERE tournament_id = %d", $tid ) );
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$p}ds_matches                 WHERE tournament_id = %d", $tid ) );
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$p}ds_playoff_brackets        WHERE tournament_id = %d", $tid ) );

	if ( ! empty( $tracked['team_ids'] ) ) {
		$in = implode( ',', array_map( 'intval', $tracked['team_ids'] ) );
		$wpdb->query( "DELETE FROM {$p}ds_team_players WHERE team_id IN ({$in})" ); // phpcs:ignore
	}

	$wpdb->query( $wpdb->prepare( "DELETE FROM {$p}ds_teams                  WHERE tournament_id = %d", $tid ) );

	if ( ! empty( $tracked['player_ids'] ) ) {
		$in = implode( ',', array_map( 'intval', $tracked['player_ids'] ) );
		$wpdb->query( "DELETE FROM {$p}ds_players WHERE id IN ({$in})" ); // phpcs:ignore
	}

	if ( ! empty( $tracked['court_ids'] ) ) {
		$in = implode( ',', array_map( 'intval', $tracked['court_ids'] ) );
		$wpdb->query( "DELETE FROM {$p}ds_courts WHERE id IN ({$in})" ); // phpcs:ignore
	}

	$wpdb->query( $wpdb->prepare( "DELETE FROM {$p}ds_tournament_venues      WHERE tournament_id = %d", $tid ) );

	if ( ! empty( $tracked['venue_ids'] ) ) {
		$in = implode( ',', array_map( 'intval', $tracked['venue_ids'] ) );
		$wpdb->query( "DELETE FROM {$p}ds_venues WHERE id IN ({$in})" ); // phpcs:ignore
	}

	if ( ! empty( $tracked['staff_ids'] ) ) {
		$in = implode( ',', array_map( 'intval', $tracked['staff_ids'] ) );
		$wpdb->query( "DELETE FROM {$p}ds_staff WHERE id IN ({$in})" ); // phpcs:ignore
	}

	$wpdb->query( $wpdb->prepare( "DELETE FROM {$p}ds_tournaments WHERE id = %d", $tid ) );

	delete_option( 'soccertrack_demo_seed_ids' );

	demo_log( '✅ Datos demo eliminados correctamente.' );
}

// ═══════════════════════════════════════════════════════════════════════════════
// INSERCIONES
// ═══════════════════════════════════════════════════════════════════════════════

function demo_insert_tournament( wpdb $wpdb ): int {
	// Inicio: primer miércoles de septiembre 2026 = 2026-09-02
	$wpdb->insert(
		"{$wpdb->prefix}ds_tournaments",
		[
			'name'                   => '[DEMO] Copa Corporativa 2026',
			'season'                 => '2026',
			'start_date'             => '2026-09-01',
			'end_date'               => '2026-11-30',
			'format'                 => 'round_robin_playoffs',
			'status'                 => 'active',
			'match_weekday'          => 3,          // miércoles (legado)
			'match_weekdays'         => '[3,4,5]',  // mié/jue/vie
			'match_time'             => '19:00:00',
			'match_time_weekend'     => '10:00:00',
			'match_duration'         => 60,
			'registration_mode'      => 'realtime',
			'fixture_release_days'   => 0,
			'yellows_per_suspension' => 3,
			'group_count'            => 1,
			'teams_advancing_per_group' => 16,
			'has_third_place'        => 1,
		],
		[ '%s','%s','%s','%s','%s','%s','%d','%s','%s','%s','%d','%s','%d','%d','%d','%d','%d' ]
	);
	return (int) $wpdb->insert_id;
}

/**
 * Crea 2 recintos con 4 canchas cada uno.
 * Retorna [$venue_ids, $court_map] donde $court_map[$vid] = [court_id, ...].
 */
function demo_insert_venues( wpdb $wpdb, int $tid ): array {
	$venues = [
		[ 'name' => 'Complejo Deportivo Norte', 'address' => 'Av. Independencia 1200, Santiago' ],
		[ 'name' => 'Complejo Deportivo Sur',   'address' => 'Camino Lo Espejo 450, Lo Espejo' ],
	];

	$venue_ids = [];
	$court_map = [];

	foreach ( $venues as $v ) {
		$wpdb->insert(
			"{$wpdb->prefix}ds_venues",
			[ 'name' => $v['name'], 'address' => $v['address'], 'total_courts' => 4 ],
			[ '%s', '%s', '%d' ]
		);
		$vid = (int) $wpdb->insert_id;
		$venue_ids[] = $vid;

		// Vincular al torneo
		$wpdb->query( $wpdb->prepare(
			"INSERT IGNORE INTO {$wpdb->prefix}ds_tournament_venues (tournament_id, venue_id) VALUES (%d, %d)",
			$tid, $vid
		) );

		// 4 canchas por recinto
		$court_ids = [];
		for ( $c = 1; $c <= 4; $c++ ) {
			$wpdb->insert(
				"{$wpdb->prefix}ds_courts",
				[ 'venue_id' => $vid, 'court_name' => "Cancha {$c}" ],
				[ '%d', '%s' ]
			);
			$court_ids[] = (int) $wpdb->insert_id;
		}
		$court_map[ $vid ] = $court_ids;
	}

	return [ $venue_ids, $court_map ];
}

/**
 * Crea 12 equipos empresariales con datos de delegado y logo.
 * Retorna array de ['id', 'name', 'strength'] donde strength guía la simulación.
 */
function demo_insert_teams( wpdb $wpdb, int $tid ): array {
	$teams = [
		// [name, empresa, rut_empresa, delegado, rut_delegado, correo, celular, color_logo, abrev, strength]
		[ 'Copec United FC',      'Copec S.A.',               '90.690.000-9', 'Rodrigo Vásquez',    '9.876.543-2', 'r.vasquez@copec.cl',        '+56912345001', 'FF6B00', 'CU', 95 ],
		[ 'Minera Escondida CF',  'Escondida Ltda.',           '76.840.000-1', 'Francisco Araya',    '8.765.432-1', 'f.araya@escondida.cl',       '+56912345002', '8B4513', 'ME', 88 ],
		[ 'ENTEL Pro FC',         'ENTEL S.A.',                '92.580.000-7', 'Alejandro Silva',    '7.654.321-0', 'a.silva@entel.cl',           '+56912345003', '1E90FF', 'EP', 81 ],
		[ 'LATAM Cargo CF',       'LATAM Airlines Group',      '89.862.200-2', 'Gonzalo Muñoz',      '6.543.210-9', 'g.munoz@latam.com',          '+56912345004', 'CC0000', 'LC', 74 ],
		[ 'BCI Banco SC',         'Banco de Crédito e Inv.',   '97.006.000-6', 'Pablo Rojas',        '5.432.109-8', 'p.rojas@bci.cl',             '+56912345005', '003399', 'BB', 67 ],
		[ 'Falabella Athletic',   'S.A.C.I. Falabella',        '76.034.800-0', 'Diego Contreras',    '4.321.098-7', 'd.contreras@falabella.cl',   '+56912345006', '006400', 'FA', 60 ],
		[ 'UC Clínica FC',        'Clínica UC San Carlos',     '70.523.000-0', 'Patricio Herrera',   '3.210.987-6', 'p.herrera@clinicauc.cl',     '+56912345007', '4B0082', 'UC', 53 ],
		[ 'Viña Partners FC',     'Viña San Pedro Tarapacá',   '96.805.000-8', 'Cristián Flores',    '2.109.876-5', 'c.flores@vspt.cl',           '+56912345008', '800080', 'VP', 46 ],
		[ 'CMPC Forestal CF',     'CMPC S.A.',                 '91.172.000-K', 'Eduardo Morales',    '1.098.765-4', 'e.morales@cmpc.cl',          '+56912345009', '228B22', 'CF', 39 ],
		[ 'Codelco Gremial FC',   'Corporación del Cobre',     '61.704.000-K', 'Marcelo Torres',     '9.087.654-3', 'm.torres@codelco.cl',        '+56912345010', 'B8860B', 'CG', 32 ],
		[ 'AFP Habitat United',   'AFP Habitat S.A.',          '99.590.000-4', 'Ricardo Vega',       '8.076.543-2', 'r.vega@afphabitat.cl',       '+56912345011', '2F4F4F', 'AH', 25 ],
		[ 'Sodimac Pro CF',       'Sodimac S.A.',              '96.792.430-0', 'Nicolás Castro',     '7.065.432-1', 'n.castro@sodimac.cl',        '+56912345012', '8B0000', 'SP', 18 ],
		[ 'Metro Mobility FC',    'Metro de Santiago S.A.',    '61.202.000-0', 'Esteban Robles',     '6.054.321-0', 'e.robles@metro.cl',          '+56912345013', '006080', 'MM', 14 ],
		[ 'GNL Quintero SC',      'GNL Quintero S.A.',         '76.532.100-2', 'Álvaro Peña',        '5.043.210-9', 'a.pena@gnlquintero.cl',      '+56912345014', '4682C4', 'GQ', 10 ],
		[ 'Mutual Seguridad CF',  'Mutual de Seguridad CChC', '70.285.000-9', 'Luis Arancibia',     '4.032.109-8', 'l.arancibia@mutual.cl',      '+56912345015', '8B4513', 'MS',  6 ],
		[ 'Carozzi Athletic CF',  'Carozzi S.A.',              '91.343.000-5', 'Sergio Figueroa',    '3.021.098-7', 's.figueroa@carozzi.cl',      '+56912345016', 'C0392B', 'CA',  3 ],
	];

	$result = [];
	foreach ( $teams as $idx => $t ) {
		$color   = $t[7];
		$abrev   = rawurlencode( $t[8] );
		$logo    = "https://ui-avatars.com/api/?name={$abrev}&background={$color}&color=fff&size=200&bold=true";

		$wpdb->insert(
			"{$wpdb->prefix}ds_teams",
			[
				'tournament_id'   => $tid,
				'name'            => $t[0],
				'logo_url'        => $logo,
				'empresa_nombre'  => $t[1],
				'empresa_rut'     => $t[2],
				'delegado_nombre' => $t[3],
				'delegado_rut'    => $t[4],
				'delegado_correo' => $t[5],
				'delegado_celular'=> $t[6],
			],
			[ '%d','%s','%s','%s','%s','%s','%s','%s','%s' ]
		);

		$result[] = [ 'id' => (int) $wpdb->insert_id, 'name' => $t[0], 'strength' => $t[9] ];
	}

	return $result;
}

/**
 * Crea 20 jugadores por equipo, los vincula con dorsal.
 * Retorna [$player_ids_global, $team_player_map[$team_id] => [['player_id'=>..., 'tp_id'=>...], ...]].
 */
function demo_insert_players( wpdb $wpdb, array $team_data ): array {
	$first_names = [
		'Carlos','Juan','Miguel','Francisco','Pablo','Diego','Andrés','Felipe',
		'Sebastián','Rodrigo','Matías','Cristián','Gonzalo','Alejandro','Eduardo',
		'Ricardo','Daniel','Marcelo','Patricio','José',
	];
	$last_names  = [
		'González','Muñoz','Rojas','Díaz','Pérez','Soto','Contreras','Silva',
		'Martínez','Flores','Vargas','Morales','Vega','Castro','Torres','Herrera',
		'Gutiérrez','Ramos','Ríos','Espinoza',
	];
	$last_names_m = [
		'Lagos','Sepúlveda','Bravo','Jara','Valenzuela','Palma','Vera','Cárdenas',
		'Pizarro','Aguilera','Salinas','Núñez','Araya','Molina','Navarro','Reyes',
		'Rivera','Medina','Correa','Fuentes',
	];
	$areas  = [ 'Comercial','Finanzas','Operaciones','RRHH','TI','Logística','Legal','Marketing' ];
	$cargos = [ 'Analista','Jefe de Área','Supervisor','Coordinador','Ejecutivo','Gerente de Proyecto','Técnico' ];

	$all_player_ids  = [];
	$team_player_map = [];

	foreach ( $team_data as $ti => $team ) {
		$team_id     = (int) $team['id'];
		$tp_list     = [];
		$team_idx    = $ti + 1; // 1-based para RUT

		for ( $p = 0; $p < 20; $p++ ) {
			$fn_idx  = $p % 20;
			$ln_idx  = ( $p + $ti * 3 ) % 20;
			$lnm_idx = ( $p + $ti * 7 + 5 ) % 20;

			$fn  = $first_names[ $fn_idx ];
			$ln  = $last_names[ $ln_idx ];
			$lnm = $last_names_m[ $lnm_idx ];

			// RUT ficticio único: TTTNN00P-K (TTT=team, P=player)
			$rut = sprintf( '%03d%02d%04d-0', $team_idx, $p + 1, $team_idx * 100 + $p );

			$wpdb->insert(
				"{$wpdb->prefix}ds_players",
				[
					'rut_id'     => $rut,
					'first_name' => $fn,
					'last_name'  => $ln,
					'last_name_m'=> $lnm,
					'email'      => strtolower( "{$fn}.{$ln}@demo.cl" ),
					'phone'      => sprintf( '+5699%07d', $team_idx * 100 + $p ),
				],
				[ '%s','%s','%s','%s','%s','%s' ]
			);
			$player_id        = (int) $wpdb->insert_id;
			$all_player_ids[] = $player_id;

			$area  = $areas[ ( $p + $ti ) % count( $areas ) ];
			$cargo = $cargos[ $p % count( $cargos ) ];

			$wpdb->insert(
				"{$wpdb->prefix}ds_team_players",
				[
					'team_id'      => $team_id,
					'player_id'    => $player_id,
					'dorsal'       => $p + 1,
					'is_suspended' => 0,
					'area'         => $area,
					'cargo'        => $cargo,
				],
				[ '%d','%d','%d','%d','%s','%s' ]
			);
			$tp_list[] = [ 'player_id' => $player_id, 'tp_id' => (int) $wpdb->insert_id ];
		}

		$team_player_map[ $team_id ] = $tp_list;
	}

	return [ $all_player_ids, $team_player_map ];
}

/**
 * Crea 10 árbitros y 10 planilleros en el catálogo de staff.
 */
function demo_insert_staff( wpdb $wpdb ): array {
	$arbitros = [
		'Hugo Salas Vera', 'Bastián Leal Moya', 'Claudio Núñez Pinto', 'Iván Riquelme Torres',
		'Roberto Jélvez Caro', 'Marco Lara Bustos', 'Oscar Pérez Leiva', 'Julio Medel Acevedo',
		'Fernando Yáñez Parra', 'René Cabrera Soto',
	];
	$planilleros = [
		'Andrea Fuentes Díaz', 'Camila Rojas Llanos', 'Valentina Sepúlveda Lagos',
		'Sofía Vargas Ibáñez', 'Paula Castro Figueroa', 'Javiera Herrera Ojeda',
		'Francisca Mora Torres', 'Carla Espinoza Ríos', 'Daniela Vega Muñoz',
		'Natalia Contreras Oliva',
	];

	$ids = [];
	foreach ( $arbitros as $nombre ) {
		$wpdb->insert( "{$wpdb->prefix}ds_staff", [ 'nombre' => $nombre, 'tipo' => 'arbitro' ], [ '%s','%s' ] );
		$ids[] = (int) $wpdb->insert_id;
	}
	foreach ( $planilleros as $nombre ) {
		$wpdb->insert( "{$wpdb->prefix}ds_staff", [ 'nombre' => $nombre, 'tipo' => 'planillero' ], [ '%s','%s' ] );
		$ids[] = (int) $wpdb->insert_id;
	}
	return $ids;
}

/**
 * Crea los 3 brackets de play-offs del torneo.
 * Retorna ['oro' => id, 'plata' => id, 'cobre' => id].
 */
function demo_insert_brackets( wpdb $wpdb, int $tid ): array {
	$brackets = [
		'principal' => [ 'name' => 'Copa Principal', 'rank_from' => 1, 'rank_to' => 16, 'sort_order' => 1 ],
	];
	$ids = [];
	foreach ( $brackets as $key => $b ) {
		$wpdb->insert(
			"{$wpdb->prefix}ds_playoff_brackets",
			[
				'tournament_id' => $tid,
				'name'          => $b['name'],
				'rank_from'     => $b['rank_from'],
				'rank_to'       => $b['rank_to'],
				'sort_order'    => $b['sort_order'],
			],
			[ '%d','%s','%d','%d','%d' ]
		);
		$ids[ $key ] = (int) $wpdb->insert_id;
	}
	return $ids;
}

// ═══════════════════════════════════════════════════════════════════════════════
// FIXTURE (FASE REGULAR)
// ═══════════════════════════════════════════════════════════════════════════════

/**
 * Genera 120 partidos de fase regular (round-robin, 16 equipos, 15 jornadas).
 * Fechas: mié/jue/vie desde el 2026-09-02.
 * Distribuye 4 partidos por venue (8 por jornada), usando las 4 canchas de cada recinto.
 */
function demo_generate_fixture( wpdb $wpdb, int $tid, array $team_ids, array $venue_ids, array $court_map ): array {
	// Fechas de las 15 jornadas (mié/jue/vie × 5 semanas)
	$dates = [
		'2026-09-02', '2026-09-03', '2026-09-04', // Semana 1
		'2026-09-09', '2026-09-10', '2026-09-11', // Semana 2
		'2026-09-16', '2026-09-17', '2026-09-18', // Semana 3
		'2026-09-23', '2026-09-24', '2026-09-25', // Semana 4
		'2026-09-30', '2026-10-01', '2026-10-02', // Semana 5
	];

	$teams  = $team_ids;
	$n      = count( $teams ); // 16
	$rounds = $n - 1;          // 15
	$ids    = [];

	// Usar las 4 canchas de cada venue para la fase regular
	$courts_v1 = array_slice( $court_map[ $venue_ids[0] ], 0, 4 ); // canchas 1-4 del Norte
	$courts_v2 = array_slice( $court_map[ $venue_ids[1] ], 0, 4 ); // canchas 1-4 del Sur

	for ( $round = 1; $round <= $rounds; $round++ ) {
		$pairs = [];
		for ( $i = 0; $i < $n / 2; $i++ ) {
			$home = $teams[ $i ];
			$away = $teams[ $n - 1 - $i ];
			// Alternar local/visitante en rondas pares
			if ( $round % 2 === 0 ) {
				[ $home, $away ] = [ $away, $home ];
			}
			$pairs[] = [ 'home' => $home, 'away' => $away ];
		}

		$date = $dates[ $round - 1 ];

		// Primeras 4 parejas → Venue Norte, canchas 1-4
		// Segundas 4 parejas → Venue Sur, canchas 1-4
		foreach ( $pairs as $idx => $pair ) {
			if ( $idx < 4 ) {
				$vid      = $venue_ids[0];
				$court_id = $courts_v1[ $idx ];
			} else {
				$vid      = $venue_ids[1];
				$court_id = $courts_v2[ $idx - 4 ];
			}

			$wpdb->insert(
				"{$wpdb->prefix}ds_matches",
				[
					'tournament_id'  => $tid,
					'round_number'   => $round,
					'home_team_id'   => $pair['home'],
					'away_team_id'   => $pair['away'],
					'venue_id'       => $vid,
					'court_id'       => $court_id,
					'match_datetime' => "{$date} 19:00:00",
					'status'         => 'scheduled',
					'phase'          => 'regular',
				],
				[ '%d','%d','%d','%d','%d','%d','%s','%s','%s' ]
			);
			$ids[] = (int) $wpdb->insert_id;
		}

		// Rotación circular: fijo equipo 0, rotar los demás
		$fixed  = array_shift( $teams );
		$last   = array_pop( $teams );
		array_unshift( $teams, $last );
		array_unshift( $teams, $fixed );
	}

	return $ids;
}

// ═══════════════════════════════════════════════════════════════════════════════
// SIMULACIÓN DE RESULTADOS (FASE REGULAR)
// ═══════════════════════════════════════════════════════════════════════════════

/**
 * Simula resultados para todos los partidos de fase regular.
 * Actualiza status='finished', home_score, away_score.
 * Crea eventos de goles y tarjetas.
 */
function demo_simulate_regular( wpdb $wpdb, int $tid, array $match_ids, array $team_player_map ): array {
	// Árbitros y planilleros disponibles
	$arbitros    = demo_get_staff_names( $wpdb, 'arbitro' );
	$planilleros = demo_get_staff_names( $wpdb, 'planillero' );

	// Leer todos los partidos regulares
	$matches = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT id, home_team_id, away_team_id FROM {$wpdb->prefix}ds_matches
			 WHERE tournament_id = %d AND phase = 'regular'
			 ORDER BY round_number, id",
			$tid
		),
		ARRAY_A
	);

	// Ratings de equipo para simulación (por team_id)
	$team_ratings = demo_get_team_ratings( $wpdb, $tid );
	$all_event_ids = [];

	foreach ( $matches as $match ) {
		$mid       = (int) $match['id'];
		$home_id   = (int) $match['home_team_id'];
		$away_id   = (int) $match['away_team_id'];

		$r_home    = $team_ratings[ $home_id ] ?? 50;
		$r_away    = $team_ratings[ $away_id ] ?? 50;

		$home_goals = demo_poisson( 0.4 + ( $r_home / 100 ) * 2.0 );
		$away_goals = demo_poisson( 0.3 + ( $r_away / 100 ) * 1.5 );

		// Árbitro y planillero aleatorios
		$arbitro   = $arbitros[ array_rand( $arbitros ) ];
		$planillero = $planilleros[ array_rand( $planilleros ) ];

		// Actualizar partido
		$wpdb->update(
			"{$wpdb->prefix}ds_matches",
			[
				'status'         => 'finished',
				'home_score'     => $home_goals,
				'away_score'     => $away_goals,
				'referee_name'   => $arbitro,
				'planillero_name'=> $planillero,
			],
			[ 'id' => $mid ],
			[ '%s','%d','%d','%s','%s' ],
			[ '%d' ]
		);

		// Insertar eventos de gol
		$events = demo_create_goal_events( $wpdb, $mid, $tid, $home_id, $home_goals, $team_player_map );
		$all_event_ids = array_merge( $all_event_ids, $events );

		$events = demo_create_goal_events( $wpdb, $mid, $tid, $away_id, $away_goals, $team_player_map );
		$all_event_ids = array_merge( $all_event_ids, $events );

		// Tarjetas amarillas (0-2 por equipo)
		$events = demo_create_card_events( $wpdb, $mid, $tid, $home_id, $away_id, $team_player_map );
		$all_event_ids = array_merge( $all_event_ids, $events );
	}

	return $all_event_ids;
}

function demo_get_staff_names( wpdb $wpdb, string $tipo ): array {
	return $wpdb->get_col(
		$wpdb->prepare(
			"SELECT nombre FROM {$wpdb->prefix}ds_staff WHERE tipo = %s ORDER BY id",
			$tipo
		)
	) ?: [ 'Por asignar' ];
}

/**
 * Leer strength/rating de los equipos desde la tabla de equipos
 * (el "strength" está implícito en el orden de inserción para la demo).
 * Retorna [team_id => rating].
 */
function demo_get_team_ratings( wpdb $wpdb, int $tid ): array {
	// Los equipos se insertaron en orden: team 0 = más fuerte (strength 95)
	$teams = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT id FROM {$wpdb->prefix}ds_teams WHERE tournament_id = %d ORDER BY id ASC",
			$tid
		),
		ARRAY_A
	);

	$ratings_list = [ 95, 88, 81, 74, 67, 60, 53, 46, 39, 32, 25, 18, 14, 10, 6, 3 ];
	$map = [];
	foreach ( $teams as $i => $t ) {
		$map[ (int) $t['id'] ] = $ratings_list[ $i ] ?? 30;
	}
	return $map;
}

/**
 * Aproximación de distribución de Poisson para simular goles.
 */
function demo_poisson( float $lambda ): int {
	$lambda = max( 0.1, min( 4.0, $lambda ) );
	$L      = exp( -$lambda );
	$k      = 0;
	$p      = 1.0;
	do {
		++$k;
		$p *= mt_rand( 1, 1000000 ) / 1000000.0;
	} while ( $p > $L );
	return min( $k - 1, 5 );
}

/**
 * Crea eventos de tipo 'goal' para un equipo en un partido.
 */
function demo_create_goal_events( wpdb $wpdb, int $mid, int $tid, int $team_id, int $goals, array $team_player_map ): array {
	if ( $goals === 0 || empty( $team_player_map[ $team_id ] ) ) {
		return [];
	}

	$players = $team_player_map[ $team_id ];
	$ids     = [];
	$minutes_used = [];

	for ( $g = 0; $g < $goals; $g++ ) {
		$scorer  = $players[ mt_rand( 0, count( $players ) - 1 ) ];
		$minute  = mt_rand( 1, 90 );

		// Evitar dos eventos en el mismo minuto exacto
		while ( in_array( $minute, $minutes_used, true ) ) {
			$minute = mt_rand( 1, 90 );
		}
		$minutes_used[] = $minute;

		// 10% de probabilidad de autogol del equipo contrario
		$tipo = ( mt_rand( 1, 10 ) === 1 ) ? 'own_goal' : 'goal';

		$wpdb->insert(
			"{$wpdb->prefix}ds_match_events",
			[
				'match_id'      => $mid,
				'tournament_id' => $tid,
				'player_id'     => $scorer['player_id'],
				'team_id'       => $team_id,
				'event_type'    => $tipo,
				'minute'        => $minute,
				'created_by'    => 1,
			],
			[ '%d','%d','%d','%d','%s','%d','%d' ]
		);
		$ids[] = (int) $wpdb->insert_id;
	}

	return $ids;
}

/**
 * Crea eventos de tarjetas amarillas y rojas para un partido.
 */
function demo_create_card_events( wpdb $wpdb, int $mid, int $tid, int $home_id, int $away_id, array $team_player_map ): array {
	$ids = [];

	foreach ( [ $home_id, $away_id ] as $team_id ) {
		if ( empty( $team_player_map[ $team_id ] ) ) {
			continue;
		}
		$players = $team_player_map[ $team_id ];

		// 0-2 tarjetas amarillas por equipo
		$yellows = mt_rand( 0, 2 );
		for ( $y = 0; $y < $yellows; $y++ ) {
			$player = $players[ mt_rand( 0, count( $players ) - 1 ) ];
			$wpdb->insert(
				"{$wpdb->prefix}ds_match_events",
				[
					'match_id'      => $mid,
					'tournament_id' => $tid,
					'player_id'     => $player['player_id'],
					'team_id'       => $team_id,
					'event_type'    => 'yellow_card',
					'minute'        => mt_rand( 1, 90 ),
					'created_by'    => 1,
				],
				[ '%d','%d','%d','%d','%s','%d','%d' ]
			);
			$ids[] = (int) $wpdb->insert_id;
		}

		// 8% de probabilidad de tarjeta roja por equipo
		if ( mt_rand( 1, 100 ) <= 8 ) {
			$player = $players[ mt_rand( 0, count( $players ) - 1 ) ];
			$wpdb->insert(
				"{$wpdb->prefix}ds_match_events",
				[
					'match_id'      => $mid,
					'tournament_id' => $tid,
					'player_id'     => $player['player_id'],
					'team_id'       => $team_id,
					'event_type'    => 'red_card',
					'minute'        => mt_rand( 45, 90 ),
					'created_by'    => 1,
				],
				[ '%d','%d','%d','%d','%s','%d','%d' ]
			);
			$ids[] = (int) $wpdb->insert_id;
		}
	}

	return $ids;
}

// ═══════════════════════════════════════════════════════════════════════════════
// PLAY-OFFS
// ═══════════════════════════════════════════════════════════════════════════════

/**
 * Genera y simula el bracket Copa Principal (16 equipos) con todas las fases:
 * octavos → cuartos → semifinales → 3er puesto + final.
 *
 * Seeding en octavos: 1°vs16°, 2°vs15°, …, 8°vs9°
 * Cuartos:  ganadores enfrentados cruzados (w0vsw7, w1vsw6, w2vsw5, w3vsw4)
 * Semis:    ganadores de cuartos (qw0vsqw3, qw1vsqw2)
 *
 * Retorna [match_ids[], event_ids[]].
 */
function demo_simulate_playoffs( wpdb $wpdb, int $tid, array $bracket_ids, array $venue_ids, array $court_map, array $team_player_map ): array {
	$standings = demo_calculate_standings( $wpdb, $tid );

	if ( count( $standings ) < 16 ) {
		demo_log( '⚠️  Menos de 16 equipos en tabla de posiciones; abortando play-offs.' );
		return [ [], [] ];
	}

	$bid          = $bracket_ids['principal'];
	$team_ratings = demo_get_team_ratings( $wpdb, $tid );
	$arbitros     = demo_get_staff_names( $wpdb, 'arbitro' );
	$planilleros  = demo_get_staff_names( $wpdb, 'planillero' );

	// Equipos en orden de posición (índice 0 = 1° lugar, 15 = 16° lugar)
	$t = array_map( static fn( $r ) => (int) $r['team_id'], array_slice( $standings, 0, 16 ) );

	// Venues y canchas reutilizables
	$courts_norte = $court_map[ $venue_ids[0] ]; // 4 canchas Norte
	$courts_sur   = $court_map[ $venue_ids[1] ]; // 4 canchas Sur

	// Fechas
	$date_oct   = '2026-10-07'; // Octavos de final
	$date_qf    = '2026-10-14'; // Cuartos de final
	$date_sf    = '2026-10-21'; // Semifinales
	$date_final = '2026-10-28'; // Final + 3er puesto

	$all_match_ids = [];
	$all_event_ids = [];

	// ── OCTAVOS DE FINAL (8 partidos) ────────────────────────────────────────
	// Seeding clásico: 1vs16, 2vs15, 3vs14, 4vs13 | 5vs12, 6vs11, 7vs10, 8vs9
	$oct_pairs = [
		[ $t[0], $t[15] ], [ $t[1], $t[14] ], [ $t[2], $t[13] ], [ $t[3], $t[12] ],
		[ $t[4], $t[11] ], [ $t[5], $t[10] ], [ $t[6], $t[9]  ], [ $t[7], $t[8]  ],
	];
	// Partidos 0-3 → Norte, 4-7 → Sur; horarios intercalados
	$oct_venues  = [ $venue_ids[0], $venue_ids[0], $venue_ids[0], $venue_ids[0], $venue_ids[1], $venue_ids[1], $venue_ids[1], $venue_ids[1] ];
	$oct_courts  = array_merge( $courts_norte, $courts_sur );
	$oct_times   = [ '19:00:00', '19:00:00', '20:00:00', '20:00:00', '19:00:00', '19:00:00', '20:00:00', '20:00:00' ];

	$oct_winners = [];
	foreach ( $oct_pairs as $i => [ $home, $away ] ) {
		[ $mid, $winner, $evs ] = demo_playoff_match(
			$wpdb, $tid, $bid, $home, $away,
			$oct_venues[ $i ], $oct_courts[ $i ], $date_oct, $oct_times[ $i ], 'octavos',
			$team_ratings, $team_player_map, $arbitros, $planilleros
		);
		$all_match_ids[] = $mid;
		$all_event_ids   = array_merge( $all_event_ids, $evs );
		$oct_winners[]   = $winner;
	}

	// ── CUARTOS DE FINAL (4 partidos) ────────────────────────────────────────
	// Cruce: ganador 0 vs ganador 7, 1vs6, 2vs5, 3vs4
	$qf_pairs = [
		[ $oct_winners[0], $oct_winners[7] ],
		[ $oct_winners[1], $oct_winners[6] ],
		[ $oct_winners[2], $oct_winners[5] ],
		[ $oct_winners[3], $oct_winners[4] ],
	];
	$qf_times = [ '19:00:00', '19:00:00', '20:00:00', '20:00:00' ];

	$qf_winners = [];
	$qf_losers  = [];
	foreach ( $qf_pairs as $i => [ $home, $away ] ) {
		[ $mid, $winner, $evs, $loser ] = demo_playoff_match(
			$wpdb, $tid, $bid, $home, $away,
			$venue_ids[0], $courts_norte[ $i ], $date_qf, $qf_times[ $i ], 'quarterfinal',
			$team_ratings, $team_player_map, $arbitros, $planilleros
		);
		$all_match_ids[] = $mid;
		$all_event_ids   = array_merge( $all_event_ids, $evs );
		$qf_winners[]    = $winner;
		$qf_losers[]     = $loser;
	}

	// ── SEMIFINALES (2 partidos) ──────────────────────────────────────────────
	// Cruce: ganador QF0 vs QF3, QF1 vs QF2
	$sf_pairs = [
		[ $qf_winners[0], $qf_winners[3] ],
		[ $qf_winners[1], $qf_winners[2] ],
	];

	$sf_winners = [];
	$sf_losers  = [];
	foreach ( $sf_pairs as $i => [ $home, $away ] ) {
		[ $mid, $winner, $evs, $loser ] = demo_playoff_match(
			$wpdb, $tid, $bid, $home, $away,
			$venue_ids[0], $courts_norte[ $i ], $date_sf, '19:00:00', 'semifinal',
			$team_ratings, $team_player_map, $arbitros, $planilleros
		);
		$all_match_ids[] = $mid;
		$all_event_ids   = array_merge( $all_event_ids, $evs );
		$sf_winners[]    = $winner;
		$sf_losers[]     = $loser;
	}

	// ── TERCER PUESTO ────────────────────────────────────────────────────────
	[ $mid, , $evs ] = demo_playoff_match(
		$wpdb, $tid, $bid, $sf_losers[0], $sf_losers[1],
		$venue_ids[0], $courts_norte[0], $date_final, '19:00:00', 'third_place',
		$team_ratings, $team_player_map, $arbitros, $planilleros
	);
	$all_match_ids[] = $mid;
	$all_event_ids   = array_merge( $all_event_ids, $evs );

	// ── FINAL ────────────────────────────────────────────────────────────────
	[ $mid, , $evs ] = demo_playoff_match(
		$wpdb, $tid, $bid, $sf_winners[0], $sf_winners[1],
		$venue_ids[0], $courts_norte[1], $date_final, '20:00:00', 'final',
		$team_ratings, $team_player_map, $arbitros, $planilleros
	);
	$all_match_ids[] = $mid;
	$all_event_ids   = array_merge( $all_event_ids, $evs );

	return [ $all_match_ids, $all_event_ids ];
}

/**
 * Inserta un partido de play-offs, simula el resultado (sin empate) y crea eventos.
 * Retorna [match_id, winner_team_id, event_ids[], loser_team_id].
 */
function demo_playoff_match(
	wpdb $wpdb,
	int $tid,
	int $bracket_id,
	int $home_id,
	int $away_id,
	int $venue_id,
	int $court_id,
	string $date,
	string $time,
	string $phase,
	array $team_ratings,
	array $team_player_map,
	array $arbitros,
	array $planilleros
): array {
	$r_home = $team_ratings[ $home_id ] ?? 50;
	$r_away = $team_ratings[ $away_id ] ?? 50;

	$home_goals = demo_poisson( 0.4 + ( $r_home / 100 ) * 2.0 );
	$away_goals = demo_poisson( 0.3 + ( $r_away / 100 ) * 1.5 );

	// Evitar empates en play-offs
	$attempts = 0;
	while ( $home_goals === $away_goals && $attempts < 10 ) {
		$home_goals = demo_poisson( 0.4 + ( $r_home / 100 ) * 2.0 );
		$away_goals = demo_poisson( 0.3 + ( $r_away / 100 ) * 1.5 );
		++$attempts;
	}
	if ( $home_goals === $away_goals ) {
		$r_home >= $r_away ? $home_goals++ : $away_goals++;
	}

	$wpdb->insert(
		"{$wpdb->prefix}ds_matches",
		[
			'tournament_id'   => $tid,
			'round_number'    => 0,
			'home_team_id'    => $home_id,
			'away_team_id'    => $away_id,
			'venue_id'        => $venue_id,
			'court_id'        => $court_id,
			'match_datetime'  => "{$date} {$time}",
			'status'          => 'finished',
			'phase'           => $phase,
			'bracket_id'      => $bracket_id,
			'home_score'      => $home_goals,
			'away_score'      => $away_goals,
			'referee_name'    => $arbitros[ array_rand( $arbitros ) ],
			'planillero_name' => $planilleros[ array_rand( $planilleros ) ],
		],
		[ '%d','%d','%d','%d','%d','%d','%s','%s','%s','%d','%d','%d','%s','%s' ]
	);
	$mid = (int) $wpdb->insert_id;

	$winner = $home_goals > $away_goals ? $home_id : $away_id;
	$loser  = $home_goals > $away_goals ? $away_id : $home_id;

	$event_ids = demo_create_goal_events( $wpdb, $mid, $tid, $home_id, $home_goals, $team_player_map );
	$event_ids = array_merge( $event_ids, demo_create_goal_events( $wpdb, $mid, $tid, $away_id, $away_goals, $team_player_map ) );
	$event_ids = array_merge( $event_ids, demo_create_card_events( $wpdb, $mid, $tid, $home_id, $away_id, $team_player_map ) );

	return [ $mid, $winner, $event_ids, $loser ];
}

/**
 * Calcula la tabla de posiciones de la fase regular.
 * Retorna array ordenado por puntos DESC, diferencia de goles DESC, goles a favor DESC.
 */
function demo_calculate_standings( wpdb $wpdb, int $tid ): array {
	return $wpdb->get_results(
		$wpdb->prepare(
			"SELECT team_id,
			        SUM(pts) AS points,
			        SUM(gf)  AS gf,
			        SUM(ga)  AS ga,
			        SUM(gf) - SUM(ga) AS gd,
			        COUNT(*) AS played
			 FROM (
			     SELECT home_team_id AS team_id,
			            home_score   AS gf,
			            away_score   AS ga,
			            CASE WHEN home_score > away_score THEN 3
			                 WHEN home_score = away_score THEN 1
			                 ELSE 0 END AS pts
			     FROM {$wpdb->prefix}ds_matches
			     WHERE tournament_id = %d AND phase = 'regular' AND status = 'finished'
			     UNION ALL
			     SELECT away_team_id AS team_id,
			            away_score   AS gf,
			            home_score   AS ga,
			            CASE WHEN away_score > home_score THEN 3
			                 WHEN away_score = home_score THEN 1
			                 ELSE 0 END AS pts
			     FROM {$wpdb->prefix}ds_matches
			     WHERE tournament_id = %d AND phase = 'regular' AND status = 'finished'
			 ) calc
			 GROUP BY team_id
			 ORDER BY points DESC, gd DESC, gf DESC",
			$tid,
			$tid
		),
		ARRAY_A
	) ?: [];
}

// ═══════════════════════════════════════════════════════════════════════════════
// TRIBUNAL DE DISCIPLINA
// ═══════════════════════════════════════════════════════════════════════════════

/**
 * Genera sanciones de ejemplo en ds_disciplinary_sanctions:
 *  1. Acumulación de amarillas: jugadores con ≥3 amarillas → 1 fecha suspendido
 *  2. Tarjetas rojas directas → 2 fechas suspendido
 *  3. Sanciones manuales del tribunal (conducta violenta, insultos, etc.)
 *
 * Retorna la cantidad de sanciones insertadas.
 */
function demo_seed_sanctions( wpdb $wpdb, int $tid, array $team_player_map ): int {
	$count = 0;
	$p     = $wpdb->prefix;

	// ── 1. Acumulación de amarillas (≥3 → 1 fecha) ───────────────────────────
	$yellow_counts = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->prepare(
			"SELECT player_id, team_id, COUNT(*) AS yellows,
			        MAX(match_id) AS last_match_id
			 FROM {$p}ds_match_events
			 WHERE tournament_id = %d AND event_type = 'yellow_card'
			 GROUP BY player_id, team_id
			 HAVING yellows >= 3",
			$tid
		),
		ARRAY_A
	) ?: [];

	foreach ( $yellow_counts as $row ) {
		$yellows    = (int) $row['yellows'];
		$ban        = (int) floor( $yellows / 3 ); // 3 amarillas = 1 fecha
		$served     = mt_rand( 0, 1 ) === 1;        // ~50% ya cumplida
		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			"{$p}ds_disciplinary_sanctions",
			[
				'player_id'          => (int) $row['player_id'],
				'tournament_id'      => $tid,
				'match_id'           => (int) $row['last_match_id'],
				'team_id'            => (int) $row['team_id'],
				'reason'             => "Acumulación de {$yellows} tarjetas amarillas",
				'observaciones'      => 'Sanción automática por acumulación reglamentaria.',
				'ban_days_or_matches'=> $ban,
				'remaining_matches'  => $served ? 0 : $ban,
				'status'             => $served ? 'served' : 'active',
				'resolved_at'        => $served ? gmdate( 'Y-m-d H:i:s' ) : null,
			],
			[ '%d', '%d', '%d', '%d', '%s', '%s', '%d', '%d', '%s', '%s' ]
		);
		++$count;
	}

	// ── 2. Tarjetas rojas → 2 fechas de suspensión ───────────────────────────
	$red_cards = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->prepare(
			"SELECT player_id, team_id, match_id
			 FROM {$p}ds_match_events
			 WHERE tournament_id = %d AND event_type = 'red_card'
			 ORDER BY match_id ASC",
			$tid
		),
		ARRAY_A
	) ?: [];

	foreach ( $red_cards as $row ) {
		$served = mt_rand( 0, 1 ) === 1;
		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			"{$p}ds_disciplinary_sanctions",
			[
				'player_id'          => (int) $row['player_id'],
				'tournament_id'      => $tid,
				'match_id'           => (int) $row['match_id'],
				'team_id'            => (int) $row['team_id'],
				'reason'             => 'Tarjeta roja directa',
				'observaciones'      => 'Expulsión registrada por el árbitro del partido.',
				'ban_days_or_matches'=> 2,
				'remaining_matches'  => $served ? 0 : 2,
				'status'             => $served ? 'served' : 'active',
				'resolved_at'        => $served ? gmdate( 'Y-m-d H:i:s' ) : null,
			],
			[ '%d', '%d', '%d', '%d', '%s', '%s', '%d', '%d', '%s', '%s' ]
		);
		++$count;
	}

	// ── 3. Sanciones manuales del tribunal (3 casos variados) ────────────────
	$tribunal_reasons = [
		[ 'reason' => 'Conducta violenta fuera del campo',    'obs' => 'Incidente en el estacionamiento al finalizar el partido. Testigos presenciales.',       'ban' => 3 ],
		[ 'reason' => 'Insultos al árbitro',                  'obs' => 'Jugador profirió insultos al árbitro tras el pitido final. Consta en el acta.',           'ban' => 1 ],
		[ 'reason' => 'Juego brusco reiterado',               'obs' => 'Tercer partido consecutivo con entrada antideportiva. Tribunal aplica sanción ejemplar.', 'ban' => 2 ],
	];

	// Seleccionar 3 jugadores al azar de distintos equipos
	$all_players = [];
	foreach ( $team_player_map as $team_id => $players ) {
		if ( ! empty( $players ) ) {
			$p_pick        = $players[ mt_rand( 0, count( $players ) - 1 ) ];
			$all_players[] = [ 'player_id' => $p_pick['player_id'], 'team_id' => $team_id ];
		}
	}
	shuffle( $all_players );
	$tribunal_players = array_slice( $all_players, 0, count( $tribunal_reasons ) );

	// Obtener un match_id cualquiera del torneo para referenciar
	$any_match_id = (int) ( $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->prepare(
			"SELECT id FROM {$p}ds_matches WHERE tournament_id = %d AND status = 'finished' LIMIT 1",
			$tid
		)
	) ?? 0 );

	foreach ( $tribunal_reasons as $i => $case ) {
		if ( ! isset( $tribunal_players[ $i ] ) || ! $any_match_id ) {
			break;
		}
		$served = $i === 0; // Solo la primera ya fue cumplida
		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			"{$p}ds_disciplinary_sanctions",
			[
				'player_id'          => (int) $tribunal_players[ $i ]['player_id'],
				'tournament_id'      => $tid,
				'match_id'           => $any_match_id,
				'team_id'            => (int) $tribunal_players[ $i ]['team_id'],
				'reason'             => $case['reason'],
				'observaciones'      => $case['obs'],
				'ban_days_or_matches'=> $case['ban'],
				'remaining_matches'  => $served ? 0 : $case['ban'],
				'status'             => $served ? 'served' : 'active',
				'resolved_at'        => $served ? gmdate( 'Y-m-d H:i:s' ) : null,
			],
			[ '%d', '%d', '%d', '%d', '%s', '%s', '%d', '%d', '%s', '%s' ]
		);
		++$count;
	}

	return $count;
}

// ═══════════════════════════════════════════════════════════════════════════════
// UTILIDADES
// ═══════════════════════════════════════════════════════════════════════════════

function demo_log( string $msg ): void {
	if ( class_exists( 'WP_CLI' ) ) {
		WP_CLI::log( $msg );
	} else {
		echo $msg . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput
	}
}
