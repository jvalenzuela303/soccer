<?php
/**
 * SoccerTrack — Seeder Formato Swiss (Champions 28 equipos)
 *
 * Genera un torneo completo tipo "Copa Champions Corporativa 2026" con formato Swiss:
 *   • 28 equipos empresariales con delegados y logos
 *   • 20 jugadores por equipo (560 total)
 *   • 2 recintos deportivos con 4 canchas cada uno
 *   • 10 árbitros y 10 planilleros
 *   • 8 rondas Swiss: standings evolucionan ronda a ronda (14 partidos × 8 = 112)
 *   • Resultados simulados con distribución de Poisson según fortaleza del equipo
 *   • 3 brackets de playoffs al terminar la liga:
 *       - Copa Oro    (posiciones  1-8):  QF → SF → Final
 *       - Copa Plata  (posiciones  9-16): QF → SF → Final
 *       - Copa Bronce (posiciones 17-24): QF → SF → Final
 *       - Posiciones 25-28: eliminados sin playoffs
 *
 * ─── USO ──────────────────────────────────────────────────────────────────────
 * INSERTAR:  wp eval-file wp-content/plugins/soccertrack/scripts/swiss-seeder.php
 * ELIMINAR:  wp eval-file wp-content/plugins/soccertrack/scripts/swiss-seeder.php cleanup
 * ─────────────────────────────────────────────────────────────────────────────
 */

if ( ! defined( 'ABSPATH' ) ) {
	die( "Ejecutar con: wp eval-file wp-content/plugins/soccertrack/scripts/swiss-seeder.php [cleanup]\n" );
}

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	global $wpdb;
	$action = isset( $args[0] ) ? trim( (string) $args[0] ) : 'seed';
	if ( 'cleanup' === $action ) {
		swiss_cleanup( $wpdb );
	} else {
		swiss_seed( $wpdb );
	}
}

// ═══════════════════════════════════════════════════════════════════════════════
// SEED PRINCIPAL
// ═══════════════════════════════════════════════════════════════════════════════

function swiss_seed( wpdb $wpdb ): void {

	if ( get_option( 'soccertrack_swiss_demo_ids' ) ) {
		swiss_log( '⚠️  Ya existen datos Swiss. Ejecuta con "cleanup" primero.' );
		return;
	}

	swiss_log( '🌱 Iniciando seeder Swiss (Champions 28 equipos)...' );

	$tracked = [];

	// 1. Torneo
	$tid                      = swiss_insert_tournament( $wpdb );
	$tracked['tournament_id'] = $tid;
	swiss_log( "✅ Torneo Swiss creado (ID: {$tid})" );

	// 2. Recintos y canchas
	[ $venue_ids, $court_map ] = swiss_insert_venues( $wpdb, $tid );
	$tracked['venue_ids']      = $venue_ids;
	$tracked['court_ids']      = array_merge( ...array_values( $court_map ) );
	swiss_log( '✅ Recintos: ' . count( $venue_ids ) . ' | Canchas: ' . count( $tracked['court_ids'] ) );

	// 3. Equipos (28 empresariales)
	$team_data            = swiss_insert_teams( $wpdb, $tid );
	$team_ids             = array_column( $team_data, 'id' );
	$tracked['team_ids']  = $team_ids;
	swiss_log( '✅ Equipos: ' . count( $team_ids ) );

	// 4. Jugadores (20 por equipo)
	[ $player_ids, $team_player_map ] = swiss_insert_players( $wpdb, $team_data );
	$tracked['player_ids']            = $player_ids;
	swiss_log( '✅ Jugadores: ' . count( $player_ids ) );

	// 5. Staff
	$staff_ids            = swiss_insert_staff( $wpdb );
	$tracked['staff_ids'] = $staff_ids;
	swiss_log( '✅ Staff: ' . count( $staff_ids ) );

	// 6. Leer fila completa del torneo (FixtureGenerator la necesita)
	$tournament_row = $wpdb->get_row(
		$wpdb->prepare( "SELECT * FROM {$wpdb->prefix}ds_tournaments WHERE id = %d", $tid ),
		ARRAY_A
	);

	// 7. Generar 8 rondas Swiss + simular resultados entre rondas
	swiss_log( '⚽ Generando 8 rondas Swiss (standings evolucionan por ronda)...' );
	$regular_match_ids         = swiss_generate_all_rounds( $wpdb, $tournament_row, $venue_ids, $team_player_map );
	$tracked['regular_match_ids'] = $regular_match_ids;
	swiss_log( '✅ Fase liga Swiss: ' . count( $regular_match_ids ) . ' partidos (8 rondas)' );

	// 8. Playoffs: 3 copas
	swiss_log( '🏆 Generando playoffs (Copa Oro / Plata / Bronce)...' );
	[ $bracket_ids, $po_match_ids, $po_event_ids ] = swiss_generate_playoffs(
		$wpdb, $tid, $venue_ids, $court_map, $team_player_map
	);
	$tracked['bracket_ids']       = $bracket_ids;
	$tracked['playoff_match_ids'] = $po_match_ids;
	$tracked['playoff_event_ids'] = $po_event_ids;
	swiss_log( '✅ Playoffs: ' . count( $po_match_ids ) . ' partidos (QF + SF + Final × 3 copas)' );

	// 9. Sanciones del tribunal
	$sanction_count = swiss_seed_sanctions( $wpdb, $tid, $team_player_map );
	swiss_log( "✅ Tribunal: {$sanction_count} sanciones generadas" );

	update_option( 'soccertrack_swiss_demo_ids', $tracked, false );

	swiss_log( '' );
	swiss_log( '🥇 ¡Datos Swiss insertados correctamente!' );
	swiss_log( "   Torneo ID: {$tid}" );
	swiss_log( '   Para eliminar: wp eval-file scripts/swiss-seeder.php cleanup' );
}

// ═══════════════════════════════════════════════════════════════════════════════
// CLEANUP
// ═══════════════════════════════════════════════════════════════════════════════

function swiss_cleanup( wpdb $wpdb ): void {
	$tracked = get_option( 'soccertrack_swiss_demo_ids', null );

	if ( ! $tracked ) {
		swiss_log( '⚠️  No se encontraron datos Swiss demo. Nada que eliminar.' );
		return;
	}

	swiss_log( '🗑️  Eliminando datos Swiss demo...' );

	$tid = (int) $tracked['tournament_id'];
	$p   = $wpdb->prefix;

	$wpdb->query( $wpdb->prepare( "DELETE FROM {$p}ds_disciplinary_sanctions WHERE tournament_id = %d", $tid ) );
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$p}ds_match_events            WHERE tournament_id = %d", $tid ) );
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$p}ds_matches                 WHERE tournament_id = %d", $tid ) );
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$p}ds_playoff_brackets        WHERE tournament_id = %d", $tid ) );

	if ( ! empty( $tracked['team_ids'] ) ) {
		$in = implode( ',', array_map( 'intval', $tracked['team_ids'] ) );
		$wpdb->query( "DELETE FROM {$p}ds_team_players WHERE team_id IN ({$in})" ); // phpcs:ignore
	}

	// Incluye equipo LIBRE (is_ghost=1) si fue creado.
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$p}ds_teams WHERE tournament_id = %d", $tid ) );

	if ( ! empty( $tracked['player_ids'] ) ) {
		$in = implode( ',', array_map( 'intval', $tracked['player_ids'] ) );
		$wpdb->query( "DELETE FROM {$p}ds_players WHERE id IN ({$in})" ); // phpcs:ignore
	}

	if ( ! empty( $tracked['court_ids'] ) ) {
		$in = implode( ',', array_map( 'intval', $tracked['court_ids'] ) );
		$wpdb->query( "DELETE FROM {$p}ds_courts WHERE id IN ({$in})" ); // phpcs:ignore
	}

	$wpdb->query( $wpdb->prepare( "DELETE FROM {$p}ds_tournament_venues WHERE tournament_id = %d", $tid ) );

	if ( ! empty( $tracked['venue_ids'] ) ) {
		$in = implode( ',', array_map( 'intval', $tracked['venue_ids'] ) );
		$wpdb->query( "DELETE FROM {$p}ds_venues WHERE id IN ({$in})" ); // phpcs:ignore
	}

	if ( ! empty( $tracked['staff_ids'] ) ) {
		$in = implode( ',', array_map( 'intval', $tracked['staff_ids'] ) );
		$wpdb->query( "DELETE FROM {$p}ds_staff WHERE id IN ({$in})" ); // phpcs:ignore
	}

	$wpdb->query( $wpdb->prepare( "DELETE FROM {$p}ds_tournaments WHERE id = %d", $tid ) );

	delete_option( 'soccertrack_swiss_demo_ids' );

	swiss_log( '✅ Datos Swiss demo eliminados correctamente.' );
}

// ═══════════════════════════════════════════════════════════════════════════════
// INSERCIÓN: TORNEO
// ═══════════════════════════════════════════════════════════════════════════════

function swiss_insert_tournament( wpdb $wpdb ): int {
	$wpdb->insert(
		"{$wpdb->prefix}ds_tournaments",
		[
			'name'                      => '[SWISS] Copa Champions Corporativa 2026',
			'season'                    => '2026',
			'start_date'                => '2026-09-05',
			'end_date'                  => '2026-11-28',
			'format'                    => 'swiss',
			'swiss_rounds'              => 8,
			'status'                    => 'active',
			'match_weekday'             => 6,           // sábado (legado)
			'match_weekdays'            => '[6]',        // sábado
			'match_time'                => '09:00:00',
			'match_time_weekend'        => '09:00:00',
			'match_duration'            => 60,
			'registration_mode'         => 'realtime',
			'fixture_release_days'      => 0,
			'yellows_per_suspension'    => 3,
			'group_count'               => 1,
			'teams_advancing_per_group' => 28,
			'has_third_place'           => 1,
		],
		[ '%s','%s','%s','%s','%s','%d','%s','%d','%s','%s','%s','%d','%s','%d','%d','%d','%d','%d' ]
	);
	return (int) $wpdb->insert_id;
}

// ═══════════════════════════════════════════════════════════════════════════════
// INSERCIÓN: RECINTOS Y CANCHAS
// ═══════════════════════════════════════════════════════════════════════════════

function swiss_insert_venues( wpdb $wpdb, int $tid ): array {
	$venues = [
		[ 'name' => 'Centro Deportivo El Belloto', 'address' => 'Av. Alemania 1500, Quilpué' ],
		[ 'name' => 'Parque Deportivo Bicentenario', 'address' => 'Los Militares 6000, Las Condes' ],
	];

	$venue_ids = [];
	$court_map = [];

	foreach ( $venues as $v ) {
		$wpdb->insert(
			"{$wpdb->prefix}ds_venues",
			[ 'name' => $v['name'], 'address' => $v['address'], 'total_courts' => 4 ],
			[ '%s', '%s', '%d' ]
		);
		$vid         = (int) $wpdb->insert_id;
		$venue_ids[] = $vid;

		$wpdb->query( $wpdb->prepare(
			"INSERT IGNORE INTO {$wpdb->prefix}ds_tournament_venues (tournament_id, venue_id) VALUES (%d, %d)",
			$tid, $vid
		) );

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

// ═══════════════════════════════════════════════════════════════════════════════
// INSERCIÓN: EQUIPOS (28)
// ═══════════════════════════════════════════════════════════════════════════════

function swiss_insert_teams( wpdb $wpdb, int $tid ): array {
	// [name, empresa, rut, delegado, rut_delegado, correo, celular, hex_color, abrev, strength]
	$teams = [
		[ 'Copec United FC',        'Copec S.A.',               '90.690.000-9', 'Rodrigo Vásquez',   '9.876.543-2', 'r.vasquez@copec.cl',          '+56912341001', 'FF6B00', 'CU',  95 ],
		[ 'Minera Escondida CF',    'Escondida Ltda.',           '76.840.000-1', 'Francisco Araya',   '8.765.432-1', 'f.araya@escondida.cl',        '+56912341002', '8B4513', 'ME',  89 ],
		[ 'ENTEL Pro FC',           'ENTEL S.A.',                '92.580.000-7', 'Alejandro Silva',   '7.654.321-0', 'a.silva@entel.cl',            '+56912341003', '1E90FF', 'EP',  83 ],
		[ 'LATAM Cargo CF',         'LATAM Airlines Group',      '89.862.200-2', 'Gonzalo Muñoz',     '6.543.210-9', 'g.munoz@latam.com',           '+56912341004', 'CC0000', 'LC',  77 ],
		[ 'BCI Banco SC',           'Banco de Crédito e Inv.',   '97.006.000-6', 'Pablo Rojas',       '5.432.109-8', 'p.rojas@bci.cl',             '+56912341005', '003399', 'BB',  71 ],
		[ 'Falabella Athletic',     'S.A.C.I. Falabella',        '76.034.800-0', 'Diego Contreras',   '4.321.098-7', 'd.contreras@falabella.cl',    '+56912341006', '006400', 'FA',  65 ],
		[ 'UC Clínica FC',          'Clínica UC San Carlos',     '70.523.000-0', 'Patricio Herrera',  '3.210.987-6', 'p.herrera@clinicauc.cl',      '+56912341007', '4B0082', 'UC',  60 ],
		[ 'Viña Partners FC',       'Viña San Pedro Tarapacá',   '96.805.000-8', 'Cristián Flores',   '2.109.876-5', 'c.flores@vspt.cl',            '+56912341008', '800080', 'VP',  55 ],
		[ 'CMPC Forestal CF',       'CMPC S.A.',                 '91.172.000-K', 'Eduardo Morales',   '1.098.765-4', 'e.morales@cmpc.cl',           '+56912341009', '228B22', 'CF',  50 ],
		[ 'Codelco Gremial FC',     'Corporación del Cobre',     '61.704.000-K', 'Marcelo Torres',    '9.087.654-3', 'm.torres@codelco.cl',         '+56912341010', 'B8860B', 'CG',  46 ],
		[ 'AFP Habitat United',     'AFP Habitat S.A.',          '99.590.000-4', 'Ricardo Vega',      '8.076.543-2', 'r.vega@afphabitat.cl',        '+56912341011', '2F4F4F', 'AH',  42 ],
		[ 'Sodimac Pro CF',         'Sodimac S.A.',              '96.792.430-0', 'Nicolás Castro',    '7.065.432-1', 'n.castro@sodimac.cl',         '+56912341012', '8B0000', 'SP',  38 ],
		[ 'Metro Mobility FC',      'Metro de Santiago S.A.',    '61.202.000-0', 'Esteban Robles',    '6.054.321-0', 'e.robles@metro.cl',           '+56912341013', '006080', 'MM',  34 ],
		[ 'GNL Quintero SC',        'GNL Quintero S.A.',         '76.532.100-2', 'Álvaro Peña',       '5.043.210-9', 'a.pena@gnlquintero.cl',       '+56912341014', '4682C4', 'GQ',  30 ],
		[ 'Mutual Seguridad CF',    'Mutual de Seguridad CChC', '70.285.000-9', 'Luis Arancibia',    '4.032.109-8', 'l.arancibia@mutual.cl',       '+56912341015', '8B4513', 'MS',  27 ],
		[ 'Carozzi Athletic CF',    'Carozzi S.A.',              '91.343.000-5', 'Sergio Figueroa',   '3.021.098-7', 's.figueroa@carozzi.cl',       '+56912341016', 'C0392B', 'CA',  24 ],
		[ 'Walmart Chile FC',       'Walmart Chile S.A.',        '96.928.030-0', 'Hernán Bravo',      '2.010.987-6', 'h.bravo@walmart.cl',          '+56912341017', '007DC5', 'WC',  21 ],
		[ 'Cencosud Racing CF',     'Cencosud S.A.',             '93.834.000-1', 'Mauricio Jara',     '1.000.876-5', 'm.jara@cencosud.cl',          '+56912341018', '2E7D32', 'CR',  18 ],
		[ 'Banco Estado SC',        'Banco del Estado de Chile', '97.030.000-7', 'Fernando Pizarro',  '9.990.765-4', 'f.pizarro@bancoestado.cl',    '+56912341019', 'C62828', 'BE',  16 ],
		[ 'Transbank United FC',    'Transbank Ltda.',           '96.510.950-5', 'Jaime Salinas',     '8.980.654-3', 'j.salinas@transbank.cl',      '+56912341020', '37474F', 'TU',  14 ],
		[ 'CAP Acero FC',           'CAP S.A.',                  '90.160.000-0', 'Manuel Núñez',      '7.970.543-2', 'm.nunez@cap.cl',              '+56912341021', '546E7A', 'CA',  12 ],
		[ 'Arauco Industries CF',   'Arauco S.A.',               '99.017.000-9', 'Claudio Molina',    '6.960.432-1', 'c.molina@arauco.cl',          '+56912341022', '4E342E', 'AI',  10 ],
		[ 'Quiñenco Athletic SC',   'Quiñenco S.A.',             '96.506.000-7', 'Álvaro Navarro',    '5.950.321-0', 'a.navarro@quinenco.cl',       '+56912341023', '1A237E', 'QA',   8 ],
		[ 'Engie Chile FC',         'Engie Chile S.A.',          '76.090.160-K', 'Bastián Reyes',     '4.940.210-9', 'b.reyes@engie.cl',            '+56912341024', '00695C', 'EC',   6 ],
		[ 'ISA Interchile SC',      'ISA Interchile S.A.',       '76.038.800-7', 'Rodrigo Rivera',    '3.930.109-8', 'r.rivera@isachile.cl',        '+56912341025', '33691E', 'IS',   5 ],
		[ 'Molymet United FC',      'Molibdenos y Metales',      '90.023.000-K', 'Ignacio Medina',    '2.920.098-7', 'i.medina@molymet.cl',         '+56912341026', '827717', 'MU',   3 ],
		[ 'EFE Ferroviaria CF',     'Empresa FF.CC. del Estado', '61.202.000-0', 'Gonzalo Correa',    '1.910.987-6', 'g.correa@efe.cl',             '+56912341027', '4A148C', 'EF',   2 ],
		[ 'Aguas Andinas FC',       'Aguas Andinas S.A.',        '97.009.000-3', 'Diego Fuentes',     '9.900.876-5', 'd.fuentes@aguasandinas.cl',   '+56912341028', '01579B', 'AA',   1 ],
	];

	$result = [];
	foreach ( $teams as $idx => $t ) {
		$color = $t[7];
		$abrev = rawurlencode( $t[8] );
		$logo  = "https://ui-avatars.com/api/?name={$abrev}&background={$color}&color=fff&size=200&bold=true";

		$wpdb->insert(
			"{$wpdb->prefix}ds_teams",
			[
				'tournament_id'    => $tid,
				'name'             => $t[0],
				'logo_url'         => $logo,
				'empresa_nombre'   => $t[1],
				'empresa_rut'      => $t[2],
				'delegado_nombre'  => $t[3],
				'delegado_rut'     => $t[4],
				'delegado_correo'  => $t[5],
				'delegado_celular' => $t[6],
				'is_ghost'         => 0,
			],
			[ '%d','%s','%s','%s','%s','%s','%s','%s','%s','%d' ]
		);

		$result[] = [ 'id' => (int) $wpdb->insert_id, 'name' => $t[0], 'strength' => $t[9] ];
	}

	return $result;
}

// ═══════════════════════════════════════════════════════════════════════════════
// INSERCIÓN: JUGADORES
// ═══════════════════════════════════════════════════════════════════════════════

function swiss_insert_players( wpdb $wpdb, array $team_data ): array {
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
		$team_id  = (int) $team['id'];
		$tp_list  = [];
		$team_idx = $ti + 1;

		for ( $p = 0; $p < 20; $p++ ) {
			$fn  = $first_names[ $p % 20 ];
			$ln  = $last_names[ ( $p + $ti * 3 ) % 20 ];
			$lnm = $last_names_m[ ( $p + $ti * 7 + 5 ) % 20 ];

			// RUT ficticio único: basado en equipo + jugador
			$rut = sprintf( '%02d%02d%05d-%d', $team_idx, $p + 1, $team_idx * 1000 + $p, $p % 10 );

			$wpdb->insert(
				"{$wpdb->prefix}ds_players",
				[
					'rut_id'      => $rut,
					'first_name'  => $fn,
					'last_name'   => $ln,
					'last_name_m' => $lnm,
					'email'       => strtolower( "{$fn}.{$ln}.{$team_idx}@demo.cl" ),
					'phone'       => sprintf( '+5699%07d', $team_idx * 100 + $p ),
				],
				[ '%s','%s','%s','%s','%s','%s' ]
			);
			$player_id        = (int) $wpdb->insert_id;
			$all_player_ids[] = $player_id;

			$wpdb->insert(
				"{$wpdb->prefix}ds_team_players",
				[
					'team_id'      => $team_id,
					'player_id'    => $player_id,
					'dorsal'       => $p + 1,
					'is_suspended' => 0,
					'area'         => $areas[ ( $p + $ti ) % count( $areas ) ],
					'cargo'        => $cargos[ $p % count( $cargos ) ],
				],
				[ '%d','%d','%d','%d','%s','%s' ]
			);
			$tp_list[] = [ 'player_id' => $player_id, 'tp_id' => (int) $wpdb->insert_id ];
		}

		$team_player_map[ $team_id ] = $tp_list;
	}

	return [ $all_player_ids, $team_player_map ];
}

// ═══════════════════════════════════════════════════════════════════════════════
// INSERCIÓN: STAFF
// ═══════════════════════════════════════════════════════════════════════════════

function swiss_insert_staff( wpdb $wpdb ): array {
	$arbitros = [
		'Héctor Zumaeta Vera', 'Bastián Leal Moya', 'Claudio Núñez Pinto', 'Iván Riquelme Torres',
		'Roberto Jélvez Caro', 'Marco Lara Bustos', 'Oscar Pérez Leiva', 'Julio Medel Acevedo',
		'Fernando Yáñez Parra', 'René Cabrera Soto',
	];
	$planilleros = [
		'Tamara Ibáñez Díaz', 'Camila Rojas Llanos', 'Valentina Sepúlveda Lagos',
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

// ═══════════════════════════════════════════════════════════════════════════════
// GENERACIÓN DE RONDAS SWISS
// ═══════════════════════════════════════════════════════════════════════════════

/**
 * Llama a FixtureGenerator::generate_swiss_round() para cada ronda y
 * simula resultados antes de la siguiente para que los standings evolucionen.
 */
function swiss_generate_all_rounds( wpdb $wpdb, array $tournament, array $venue_ids, array $team_player_map ): array {
	$generator    = new \SportsLeague\Core\FixtureGenerator();
	$tid          = (int) $tournament['id'];
	$total_rounds = (int) ( $tournament['swiss_rounds'] ?? 8 );
	$all_ids      = [];

	for ( $round = 1; $round <= $total_rounds; $round++ ) {
		// Alternar recinto por ronda para distribuir carga de partidos.
		$venue_id = $venue_ids[ ( $round - 1 ) % count( $venue_ids ) ];

		$result = $generator->generate_swiss_round( $tournament, $round, $venue_id );

		if ( ! empty( $result['error'] ) ) {
			swiss_log( "  ⚠️  Ronda {$round}: " . $result['error'] );
			continue;
		}

		$match_ids = $result['match_ids'];
		swiss_log( "  Ronda {$round}: " . count( $match_ids ) . ' partidos generados — simulando resultados...' );

		// Simular resultados INMEDIATAMENTE para que la siguiente ronda
		// calcule standings correctamente (StandingsCalculator lee status='finished').
		swiss_simulate_round( $wpdb, $tid, $match_ids, $team_player_map );

		$all_ids = array_merge( $all_ids, $match_ids );
	}

	return $all_ids;
}

/**
 * Simula resultados de todos los partidos de una ronda.
 * Los partidos vs el equipo LIBRE (is_ghost=1) ya tienen status='finished' → se omiten.
 */
function swiss_simulate_round( wpdb $wpdb, int $tid, array $match_ids, array $team_player_map ): void {
	if ( empty( $match_ids ) ) {
		return;
	}

	$arbitros    = swiss_get_staff_names( $wpdb, 'arbitro' );
	$planilleros = swiss_get_staff_names( $wpdb, 'planillero' );
	$ratings     = swiss_get_team_ratings( $wpdb, $tid );

	$in_list = implode( ',', array_map( 'intval', $match_ids ) );

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$matches = $wpdb->get_results(
		"SELECT id, home_team_id, away_team_id, status
		 FROM {$wpdb->prefix}ds_matches
		 WHERE id IN ({$in_list})",
		ARRAY_A
	) ?: [];

	foreach ( $matches as $match ) {
		// Bye ya insertado como finished 0-0 → saltar.
		if ( 'finished' === $match['status'] ) {
			continue;
		}

		$mid     = (int) $match['id'];
		$home_id = (int) $match['home_team_id'];
		$away_id = (int) $match['away_team_id'];

		$r_home     = $ratings[ $home_id ] ?? 50;
		$r_away     = $ratings[ $away_id ] ?? 50;
		$home_goals = swiss_poisson( 0.4 + ( $r_home / 100 ) * 2.0 );
		$away_goals = swiss_poisson( 0.3 + ( $r_away / 100 ) * 1.5 );

		$wpdb->update(
			"{$wpdb->prefix}ds_matches",
			[
				'status'          => 'finished',
				'home_score'      => $home_goals,
				'away_score'      => $away_goals,
				'referee_name'    => $arbitros[ array_rand( $arbitros ) ],
				'planillero_name' => $planilleros[ array_rand( $planilleros ) ],
			],
			[ 'id' => $mid ],
			[ '%s','%d','%d','%s','%s' ],
			[ '%d' ]
		);

		swiss_create_goal_events( $wpdb, $mid, $tid, $home_id, $home_goals, $team_player_map );
		swiss_create_goal_events( $wpdb, $mid, $tid, $away_id, $away_goals, $team_player_map );
		swiss_create_card_events( $wpdb, $mid, $tid, $home_id, $away_id, $team_player_map );
	}
}

// ═══════════════════════════════════════════════════════════════════════════════
// PLAYOFFS: 3 COPAS
// ═══════════════════════════════════════════════════════════════════════════════

/**
 * Calcula standings finales, crea 3 brackets y simula QF→SF→Final para cada copa.
 * Copa Oro: 1-8 | Copa Plata: 9-16 | Copa Bronce: 17-24 | Posiciones 25-28: eliminados.
 *
 * @return array{ array<string,int>, int[], int[] }  [bracket_ids, match_ids, event_ids]
 */
function swiss_generate_playoffs( wpdb $wpdb, int $tid, array $venue_ids, array $court_map, array $team_player_map ): array {
	$standings = swiss_calculate_standings( $wpdb, $tid );

	if ( count( $standings ) < 24 ) {
		swiss_log( '  ⚠️  Menos de 24 equipos en tabla; abortando playoffs.' );
		return [ [], [], [] ];
	}

	$team_ratings = swiss_get_team_ratings( $wpdb, $tid );
	$arbitros     = swiss_get_staff_names( $wpdb, 'arbitro' );
	$planilleros  = swiss_get_staff_names( $wpdb, 'planillero' );

	// Equipos en orden de posición (índice 0 = 1°, 27 = 28°)
	$ranked = array_map( static fn( $r ) => (int) $r['team_id'], $standings );

	// Fechas de playoffs (domingos de noviembre)
	$date_qf    = '2026-11-01';
	$date_sf    = '2026-11-08';
	$date_final = '2026-11-15';

	// Canchas de cada recinto
	$courts_v1 = $court_map[ $venue_ids[0] ];
	$courts_v2 = $court_map[ $venue_ids[1] ];

	$bracket_ids   = [];
	$all_match_ids = [];
	$all_event_ids = [];

	$cups = [
		'oro'    => [ 'name' => 'Copa Oro',    'rank_from' => 1,  'rank_to' => 8,  'sort' => 1, 'teams' => array_slice( $ranked, 0,  8 ), 'venue' => $venue_ids[0], 'courts' => $courts_v1 ],
		'plata'  => [ 'name' => 'Copa Plata',  'rank_from' => 9,  'rank_to' => 16, 'sort' => 2, 'teams' => array_slice( $ranked, 8,  8 ), 'venue' => $venue_ids[1], 'courts' => $courts_v2 ],
		'bronce' => [ 'name' => 'Copa Bronce', 'rank_from' => 17, 'rank_to' => 24, 'sort' => 3, 'teams' => array_slice( $ranked, 16, 8 ), 'venue' => $venue_ids[0], 'courts' => $courts_v1 ],
	];

	foreach ( $cups as $key => $cup ) {
		// Crear bracket
		$wpdb->insert(
			"{$wpdb->prefix}ds_playoff_brackets",
			[
				'tournament_id' => $tid,
				'name'          => $cup['name'],
				'rank_from'     => $cup['rank_from'],
				'rank_to'       => $cup['rank_to'],
				'sort_order'    => $cup['sort'],
			],
			[ '%d','%s','%d','%d','%d' ]
		);
		$bid              = (int) $wpdb->insert_id;
		$bracket_ids[$key] = $bid;

		$t = $cup['teams']; // [t0=1°, t1=2°, ..., t7=8°] dentro de su copa

		// ── Cuartos de final: clásico 1vs8, 2vs7, 3vs6, 4vs5
		$qf_pairs = [
			[ $t[0], $t[7] ], [ $t[1], $t[6] ], [ $t[2], $t[5] ], [ $t[3], $t[4] ],
		];
		$qf_times   = [ '09:00:00', '10:00:00', '11:00:00', '12:00:00' ];
		$qf_winners = [];
		$qf_losers  = [];

		foreach ( $qf_pairs as $i => [ $home, $away ] ) {
			[ $mid, $winner, $evs, $loser ] = swiss_playoff_match(
				$wpdb, $tid, $bid, $home, $away,
				$cup['venue'], $cup['courts'][ $i ], $date_qf, $qf_times[ $i ], 'quarterfinal',
				$team_ratings, $team_player_map, $arbitros, $planilleros
			);
			$all_match_ids[] = $mid;
			$all_event_ids   = array_merge( $all_event_ids, $evs );
			$qf_winners[]    = $winner;
			$qf_losers[]     = $loser;
		}

		// ── Semifinales: w0vsw3, w1vsw2
		$sf_pairs   = [ [ $qf_winners[0], $qf_winners[3] ], [ $qf_winners[1], $qf_winners[2] ] ];
		$sf_times   = [ '09:00:00', '10:00:00' ];
		$sf_winners = [];
		$sf_losers  = [];

		foreach ( $sf_pairs as $i => [ $home, $away ] ) {
			[ $mid, $winner, $evs, $loser ] = swiss_playoff_match(
				$wpdb, $tid, $bid, $home, $away,
				$cup['venue'], $cup['courts'][ $i ], $date_sf, $sf_times[ $i ], 'semifinal',
				$team_ratings, $team_player_map, $arbitros, $planilleros
			);
			$all_match_ids[] = $mid;
			$all_event_ids   = array_merge( $all_event_ids, $evs );
			$sf_winners[]    = $winner;
			$sf_losers[]     = $loser;
		}

		// ── Tercer puesto
		[ $mid, , $evs ] = swiss_playoff_match(
			$wpdb, $tid, $bid, $sf_losers[0], $sf_losers[1],
			$cup['venue'], $cup['courts'][0], $date_final, '09:00:00', 'third_place',
			$team_ratings, $team_player_map, $arbitros, $planilleros
		);
		$all_match_ids[] = $mid;
		$all_event_ids   = array_merge( $all_event_ids, $evs );

		// ── Final
		[ $mid, , $evs ] = swiss_playoff_match(
			$wpdb, $tid, $bid, $sf_winners[0], $sf_winners[1],
			$cup['venue'], $cup['courts'][1], $date_final, '10:00:00', 'final',
			$team_ratings, $team_player_map, $arbitros, $planilleros
		);
		$all_match_ids[] = $mid;
		$all_event_ids   = array_merge( $all_event_ids, $evs );

		swiss_log( "  {$cup['name']}: QF(4) + SF(2) + 3ro(1) + Final(1) = 8 partidos" );
	}

	return [ $bracket_ids, $all_match_ids, $all_event_ids ];
}

/**
 * Inserta y simula un partido de playoffs (sin empate).
 * Retorna [match_id, winner_id, event_ids[], loser_id].
 */
function swiss_playoff_match(
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
	$r_home     = $team_ratings[ $home_id ] ?? 50;
	$r_away     = $team_ratings[ $away_id ] ?? 50;
	$home_goals = swiss_poisson( 0.4 + ( $r_home / 100 ) * 2.0 );
	$away_goals = swiss_poisson( 0.3 + ( $r_away / 100 ) * 1.5 );

	// Evitar empate en playoffs (máx 10 intentos)
	for ( $try = 0; $home_goals === $away_goals && $try < 10; $try++ ) {
		$home_goals = swiss_poisson( 0.4 + ( $r_home / 100 ) * 2.0 );
		$away_goals = swiss_poisson( 0.3 + ( $r_away / 100 ) * 1.5 );
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

	$event_ids = swiss_create_goal_events( $wpdb, $mid, $tid, $home_id, $home_goals, $team_player_map );
	$event_ids = array_merge( $event_ids, swiss_create_goal_events( $wpdb, $mid, $tid, $away_id, $away_goals, $team_player_map ) );
	$event_ids = array_merge( $event_ids, swiss_create_card_events( $wpdb, $mid, $tid, $home_id, $away_id, $team_player_map ) );

	return [ $mid, $winner, $event_ids, $loser ];
}

// ═══════════════════════════════════════════════════════════════════════════════
// TRIBUNAL DE DISCIPLINA
// ═══════════════════════════════════════════════════════════════════════════════

function swiss_seed_sanctions( wpdb $wpdb, int $tid, array $team_player_map ): int {
	$count = 0;
	$p     = $wpdb->prefix;

	// 1. Acumulación de amarillas (≥3 → 1 fecha)
	$yellow_counts = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->prepare(
			"SELECT player_id, team_id, COUNT(*) AS yellows, MAX(match_id) AS last_match_id
			 FROM {$p}ds_match_events
			 WHERE tournament_id = %d AND event_type = 'yellow_card'
			 GROUP BY player_id, team_id HAVING yellows >= 3",
			$tid
		),
		ARRAY_A
	) ?: [];

	foreach ( $yellow_counts as $row ) {
		$yellows = (int) $row['yellows'];
		$ban     = (int) floor( $yellows / 3 );
		$served  = mt_rand( 0, 1 ) === 1;
		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			"{$p}ds_disciplinary_sanctions",
			[
				'player_id'           => (int) $row['player_id'],
				'tournament_id'       => $tid,
				'match_id'            => (int) $row['last_match_id'],
				'team_id'             => (int) $row['team_id'],
				'reason'              => "Acumulación de {$yellows} tarjetas amarillas",
				'observaciones'       => 'Sanción automática por acumulación reglamentaria.',
				'ban_days_or_matches' => $ban,
				'remaining_matches'   => $served ? 0 : $ban,
				'status'              => $served ? 'served' : 'active',
				'resolved_at'         => $served ? gmdate( 'Y-m-d H:i:s' ) : null,
			],
			[ '%d','%d','%d','%d','%s','%s','%d','%d','%s','%s' ]
		);
		++$count;
	}

	// 2. Tarjetas rojas → 2 fechas
	$red_cards = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->prepare(
			"SELECT player_id, team_id, match_id FROM {$p}ds_match_events
			 WHERE tournament_id = %d AND event_type = 'red_card' ORDER BY match_id ASC",
			$tid
		),
		ARRAY_A
	) ?: [];

	foreach ( $red_cards as $row ) {
		$served = mt_rand( 0, 1 ) === 1;
		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			"{$p}ds_disciplinary_sanctions",
			[
				'player_id'           => (int) $row['player_id'],
				'tournament_id'       => $tid,
				'match_id'            => (int) $row['match_id'],
				'team_id'             => (int) $row['team_id'],
				'reason'              => 'Tarjeta roja directa',
				'observaciones'       => 'Expulsión registrada por el árbitro del partido.',
				'ban_days_or_matches' => 2,
				'remaining_matches'   => $served ? 0 : 2,
				'status'              => $served ? 'served' : 'active',
				'resolved_at'         => $served ? gmdate( 'Y-m-d H:i:s' ) : null,
			],
			[ '%d','%d','%d','%d','%s','%s','%d','%d','%s','%s' ]
		);
		++$count;
	}

	// 3. Sanciones manuales del tribunal (3 casos)
	$tribunal_cases = [
		[ 'reason' => 'Conducta violenta fuera del campo',  'obs' => 'Incidente en el estacionamiento al finalizar el partido.',        'ban' => 3 ],
		[ 'reason' => 'Insultos al árbitro',                'obs' => 'Jugador profirió insultos al árbitro tras el pitido final.',        'ban' => 1 ],
		[ 'reason' => 'Juego brusco reiterado (3 partidos)','obs' => 'Tribunal aplica sanción ejemplar por conducta antideportiva.',     'ban' => 2 ],
	];

	$candidates = [];
	foreach ( $team_player_map as $team_id => $players ) {
		if ( ! empty( $players ) ) {
			$pick         = $players[ mt_rand( 0, count( $players ) - 1 ) ];
			$candidates[] = [ 'player_id' => $pick['player_id'], 'team_id' => $team_id ];
		}
	}
	shuffle( $candidates );
	$tribunal_players = array_slice( $candidates, 0, count( $tribunal_cases ) );

	$any_match_id = (int) ( $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->prepare(
			"SELECT id FROM {$p}ds_matches WHERE tournament_id = %d AND status = 'finished' LIMIT 1",
			$tid
		)
	) ?? 0 );

	foreach ( $tribunal_cases as $i => $case ) {
		if ( ! isset( $tribunal_players[ $i ] ) || ! $any_match_id ) {
			break;
		}
		$served = ( $i === 0 );
		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			"{$p}ds_disciplinary_sanctions",
			[
				'player_id'           => (int) $tribunal_players[ $i ]['player_id'],
				'tournament_id'       => $tid,
				'match_id'            => $any_match_id,
				'team_id'             => (int) $tribunal_players[ $i ]['team_id'],
				'reason'              => $case['reason'],
				'observaciones'       => $case['obs'],
				'ban_days_or_matches' => $case['ban'],
				'remaining_matches'   => $served ? 0 : $case['ban'],
				'status'              => $served ? 'served' : 'active',
				'resolved_at'         => $served ? gmdate( 'Y-m-d H:i:s' ) : null,
			],
			[ '%d','%d','%d','%d','%s','%s','%d','%d','%s','%s' ]
		);
		++$count;
	}

	return $count;
}

// ═══════════════════════════════════════════════════════════════════════════════
// UTILIDADES COMPARTIDAS
// ═══════════════════════════════════════════════════════════════════════════════

/**
 * Calcula standings de la fase regular (excluye partidos vs equipo LIBRE).
 */
function swiss_calculate_standings( wpdb $wpdb, int $tid ): array {
	return $wpdb->get_results(
		$wpdb->prepare(
			"SELECT team_id,
			        SUM(pts)          AS points,
			        SUM(gf)           AS gf,
			        SUM(ga)           AS ga,
			        SUM(gf) - SUM(ga) AS gd,
			        COUNT(*)          AS played
			 FROM (
			     SELECT home_team_id AS team_id,
			            home_score   AS gf,
			            away_score   AS ga,
			            CASE WHEN home_score > away_score THEN 3
			                 WHEN home_score = away_score THEN 1
			                 ELSE 0 END AS pts
			     FROM {$wpdb->prefix}ds_matches
			     WHERE tournament_id = %d AND phase = 'regular' AND status = 'finished'
			       AND away_team_id NOT IN (
			           SELECT id FROM {$wpdb->prefix}ds_teams WHERE tournament_id = %d AND is_ghost = 1
			       )
			     UNION ALL
			     SELECT away_team_id AS team_id,
			            away_score   AS gf,
			            home_score   AS ga,
			            CASE WHEN away_score > home_score THEN 3
			                 WHEN away_score = home_score THEN 1
			                 ELSE 0 END AS pts
			     FROM {$wpdb->prefix}ds_matches
			     WHERE tournament_id = %d AND phase = 'regular' AND status = 'finished'
			       AND home_team_id NOT IN (
			           SELECT id FROM {$wpdb->prefix}ds_teams WHERE tournament_id = %d AND is_ghost = 1
			       )
			 ) calc
			 GROUP BY team_id
			 ORDER BY points DESC, gd DESC, gf DESC",
			$tid, $tid, $tid, $tid
		),
		ARRAY_A
	) ?: [];
}

/**
 * Retorna [team_id => strength_rating] en el orden de inserción.
 * Los primeros 28 equipos se insertaron de mayor a menor fortaleza.
 */
function swiss_get_team_ratings( wpdb $wpdb, int $tid ): array {
	$teams = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT id FROM {$wpdb->prefix}ds_teams WHERE tournament_id = %d AND is_ghost = 0 ORDER BY id ASC",
			$tid
		),
		ARRAY_A
	);

	$ratings_list = [ 95, 89, 83, 77, 71, 65, 60, 55, 50, 46, 42, 38, 34, 30, 27, 24, 21, 18, 16, 14, 12, 10, 8, 6, 5, 3, 2, 1 ];
	$map = [];
	foreach ( $teams as $i => $t ) {
		$map[ (int) $t['id'] ] = $ratings_list[ $i ] ?? 15;
	}
	return $map;
}

function swiss_get_staff_names( wpdb $wpdb, string $tipo ): array {
	return $wpdb->get_col(
		$wpdb->prepare(
			"SELECT nombre FROM {$wpdb->prefix}ds_staff WHERE tipo = %s ORDER BY id",
			$tipo
		)
	) ?: [ 'Por asignar' ];
}

/** Distribución de Poisson para simular goles. */
function swiss_poisson( float $lambda ): int {
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

function swiss_create_goal_events( wpdb $wpdb, int $mid, int $tid, int $team_id, int $goals, array $team_player_map ): array {
	if ( $goals === 0 || empty( $team_player_map[ $team_id ] ) ) {
		return [];
	}

	$players      = $team_player_map[ $team_id ];
	$ids          = [];
	$minutes_used = [];

	for ( $g = 0; $g < $goals; $g++ ) {
		$scorer = $players[ mt_rand( 0, count( $players ) - 1 ) ];
		$minute = mt_rand( 1, 90 );
		while ( in_array( $minute, $minutes_used, true ) ) {
			$minute = mt_rand( 1, 90 );
		}
		$minutes_used[] = $minute;
		$tipo           = ( mt_rand( 1, 10 ) === 1 ) ? 'own_goal' : 'goal';

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

function swiss_create_card_events( wpdb $wpdb, int $mid, int $tid, int $home_id, int $away_id, array $team_player_map ): array {
	$ids = [];

	foreach ( [ $home_id, $away_id ] as $team_id ) {
		if ( empty( $team_player_map[ $team_id ] ) ) {
			continue;
		}
		$players = $team_player_map[ $team_id ];

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

function swiss_log( string $msg ): void {
	if ( class_exists( 'WP_CLI' ) ) {
		WP_CLI::log( $msg );
	} else {
		echo $msg . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput
	}
}
