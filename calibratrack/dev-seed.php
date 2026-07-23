<?php
/**
 * Script de datos de prueba para CalibraTrack.
 * SOLO para desarrollo local — NO subir a producción.
 *
 * Uso: acceder a http://localhost:8088/wp-content/plugins/calibratrack/dev-seed.php
 * O ejecutar: docker exec calibratrack_wp php /var/www/html/wp-content/plugins/calibratrack/dev-seed.php
 */

// Bloquear ejecución en producción.
if ( ! defined( 'ABSPATH' ) ) {
	// Cargado directamente (CLI o browser).
	$_SERVER['HTTP_HOST']   = 'localhost:8088';
	$_SERVER['REQUEST_URI'] = '/';
	require_once dirname( __DIR__, 3 ) . '/wp-load.php';
}

if ( ! current_user_can( 'manage_options' ) && php_sapi_name() !== 'cli' ) {
	wp_die( 'Acceso denegado.' );
}

require_once __DIR__ . '/includes/class-calibratrack-meta-keys.php';
require_once __DIR__ . '/includes/class-calibratrack-helpers.php';
require_once __DIR__ . '/includes/class-calibratrack-cpt-equipo.php';
require_once __DIR__ . '/includes/class-calibratrack-cpt-cliente.php';
require_once __DIR__ . '/includes/class-calibratrack-cpt-evento-servicio.php';
require_once __DIR__ . '/includes/class-calibratrack-db.php';
require_once __DIR__ . '/includes/class-calibratrack-qr.php';

$log = array();

function seed_log( $msg ) {
	global $log;
	$log[] = $msg;
	if ( php_sapi_name() === 'cli' ) {
		echo $msg . PHP_EOL;
	}
}

// ─── 1. CLIENTES ─────────────────────────────────────────────────────────────

$clientes_data = array(
	array(
		'nombre_empresa'  => 'PROINTEL S.A.',
		'rut'             => '76.123.456-7',
		'contacto_nombre' => 'Carlos Muñoz',
		'telefono'        => '+56 9 8765 4321',
		'correo'          => 'cmunoz@prointel.cl',
		'direccion'       => 'Av. Providencia 1234, Santiago',
	),
	array(
		'nombre_empresa'  => 'Telecom Chile Ltda.',
		'rut'             => '77.654.321-K',
		'contacto_nombre' => 'Ana Torres',
		'telefono'        => '+56 9 9123 4567',
		'correo'          => 'atorres@telecomchile.cl',
		'direccion'       => 'Av. Libertador 456, Viña del Mar',
	),
	array(
		'nombre_empresa'  => 'Fibernet SpA',
		'rut'             => '78.001.234-5',
		'contacto_nombre' => 'Roberto Lagos',
		'telefono'        => '+56 9 7654 3210',
		'correo'          => 'rlagos@fibernet.cl',
		'direccion'       => 'San Martín 789, Concepción',
	),
);

$cliente_ids = array();
foreach ( $clientes_data as $c ) {
	$post_id = wp_insert_post( array(
		'post_title'  => $c['nombre_empresa'],
		'post_type'   => 'cliente',
		'post_status' => 'publish',
	) );
	if ( is_wp_error( $post_id ) ) {
		seed_log( 'ERROR cliente: ' . $post_id->get_error_message() );
		continue;
	}
	update_post_meta( $post_id, CalibraTrack_Meta_Keys::CLIENTE_NOMBRE_EMPRESA,  $c['nombre_empresa'] );
	update_post_meta( $post_id, CalibraTrack_Meta_Keys::CLIENTE_RUT,             $c['rut'] );
	update_post_meta( $post_id, CalibraTrack_Meta_Keys::CLIENTE_CONTACTO_NOMBRE, $c['contacto_nombre'] );
	update_post_meta( $post_id, CalibraTrack_Meta_Keys::CLIENTE_TELEFONO,        $c['telefono'] );
	update_post_meta( $post_id, CalibraTrack_Meta_Keys::CLIENTE_CORREO,          $c['correo'] );
	update_post_meta( $post_id, CalibraTrack_Meta_Keys::CLIENTE_DIRECCION,       $c['direccion'] );
	$cliente_ids[] = $post_id;
	seed_log( "✓ Cliente creado: {$c['nombre_empresa']} (ID $post_id)" );
}

// ─── 2. EQUIPOS ──────────────────────────────────────────────────────────────

$equipos_data = array(
	array(
		'serie'      => 'GW-OTDR-2024-001',
		'marca'      => 'Grandway',
		'modelo'     => 'GS-401',
		'tipo'       => 'otdr',
		'cliente_i'  => 0, // índice de $cliente_ids
	),
	array(
		'serie'      => 'EXFO-PM-2024-002',
		'marca'      => 'EXFO',
		'modelo'     => 'MAX-715B',
		'tipo'       => 'power_meter',
		'cliente_i'  => 0,
	),
	array(
		'serie'      => 'FK-EMP-2023-003',
		'marca'      => 'Fujikura',
		'modelo'     => 'FSM-70S',
		'tipo'       => 'empalmadora',
		'cliente_i'  => 1,
	),
	array(
		'serie'      => 'FLUKE-CERT-2024-004',
		'marca'      => 'Fluke Networks',
		'modelo'     => 'DSX2-8000',
		'tipo'       => 'certificador',
		'cliente_i'  => 1,
	),
	array(
		'serie'      => 'GW-FUENTE-2023-005',
		'marca'      => 'Grandway',
		'modelo'     => 'FLS-430',
		'tipo'       => 'fuente_luz',
		'cliente_i'  => 2,
	),
	array(
		'serie'      => 'EXFO-OTDR-2022-006',
		'marca'      => 'EXFO',
		'modelo'     => 'AXS-200',
		'tipo'       => 'otdr',
		'cliente_i'  => 2,
	),
);

$equipo_ids = array();
foreach ( $equipos_data as $e ) {
	$cliente_id = isset( $cliente_ids[ $e['cliente_i'] ] ) ? $cliente_ids[ $e['cliente_i'] ] : 0;
	$post_id = wp_insert_post( array(
		'post_title'  => $e['marca'] . ' ' . $e['modelo'] . ' — ' . $e['serie'],
		'post_type'   => 'equipo',
		'post_status' => 'publish',
	) );
	if ( is_wp_error( $post_id ) ) {
		seed_log( 'ERROR equipo: ' . $post_id->get_error_message() );
		continue;
	}
	update_post_meta( $post_id, CalibraTrack_Meta_Keys::EQUIPO_SERIE,                $e['serie'] );
	update_post_meta( $post_id, CalibraTrack_Meta_Keys::EQUIPO_MARCA,                $e['marca'] );
	update_post_meta( $post_id, CalibraTrack_Meta_Keys::EQUIPO_MODELO,               $e['modelo'] );
	update_post_meta( $post_id, CalibraTrack_Meta_Keys::EQUIPO_TIPO,                 $e['tipo'] );
	update_post_meta( $post_id, CalibraTrack_Meta_Keys::EQUIPO_CLIENTE_PROPIETARIO,  $cliente_id );
	update_post_meta( $post_id, CalibraTrack_Meta_Keys::EQUIPO_FECHA_INGRESO,        gmdate( 'Y-m-d' ) );

	// Generar QR.
	$qr_id = CalibraTrack_QR::generate_for_equipo( $e['serie'], $post_id );
	if ( $qr_id ) {
		seed_log( "  → QR generado (attachment ID: $qr_id)" );
	} else {
		seed_log( "  → QR no generado (verificar composer install)" );
	}

	$equipo_ids[] = $post_id;
	seed_log( "✓ Equipo creado: {$e['marca']} {$e['modelo']} serie={$e['serie']} (ID $post_id)" );
}

// ─── 3. EVENTOS DE SERVICIO ───────────────────────────────────────────────────

$hoy        = new DateTime( 'now', new DateTimeZone( 'America/Santiago' ) );
$admin_user = get_user_by( 'login', 'admin' ) ?: wp_get_current_user();
$admin_id   = $admin_user ? $admin_user->ID : 1;

$eventos_data = array(
	// Equipo 0 — calibración reciente, vigente
	array(
		'equipo_i'            => 0,
		'numero_ot'           => 'OT-2024-096',
		'tipo'                => 'calibracion',
		'fecha_ejecucion'     => ( clone $hoy )->modify( '-30 days' )->format( 'Y-m-d' ),
		'proxima_fecha'       => ( clone $hoy )->modify( '+335 days' )->format( 'Y-m-d' ),
		'falla_reportada'     => 'Calibración anual de rutina. No se reportan fallas.',
		'descripcion_trabajo' => 'Se realizó calibración completa del OTDR según procedimiento estándar. Verificación de potencia óptica y longitud de onda. Ajuste de parámetros de medición.',
		'observaciones'       => 'Equipo en excelente estado. Se recomienda próxima calibración en 12 meses.',
		'garantia'            => true,
		'dias_garantia'       => 90,
		'items_costo'         => array(
			array( 'detalle' => 'Calibración OTDR', 'cantidad' => '1.00', 'precio_unitario' => '85000.00' ),
			array( 'detalle' => 'Certificado de calibración', 'cantidad' => '1.00', 'precio_unitario' => '15000.00' ),
		),
	),
	// Equipo 1 — mantenimiento, por vencer (20 días)
	array(
		'equipo_i'            => 1,
		'numero_ot'           => 'OT-2024-097',
		'tipo'                => 'mantenimiento',
		'fecha_ejecucion'     => ( clone $hoy )->modify( '-345 days' )->format( 'Y-m-d' ),
		'proxima_fecha'       => ( clone $hoy )->modify( '+20 days' )->format( 'Y-m-d' ),
		'falla_reportada'     => 'Lectura de potencia con desviación de ±0.3 dB respecto a patrón.',
		'descripcion_trabajo' => 'Limpieza de conectores ópticos, ajuste de calibración interna, verificación con patrón de referencia trazable INTI.',
		'observaciones'       => 'Se detectó desgaste leve en conector FC/APC. Se recomienda reemplazo en próxima mantención.',
		'garantia'            => false,
		'dias_garantia'       => 0,
		'items_costo'         => array(
			array( 'detalle' => 'Mantención preventiva Power Meter', 'cantidad' => '1.00', 'precio_unitario' => '65000.00' ),
			array( 'detalle' => 'Limpieza conectores (kit)', 'cantidad' => '2.00', 'precio_unitario' => '8500.00' ),
		),
	),
	// Equipo 2 — vencido (hace 15 días)
	array(
		'equipo_i'            => 2,
		'numero_ot'           => 'OT-2023-058',
		'tipo'                => 'calibracion',
		'fecha_ejecucion'     => ( clone $hoy )->modify( '-380 days' )->format( 'Y-m-d' ),
		'proxima_fecha'       => ( clone $hoy )->modify( '-15 days' )->format( 'Y-m-d' ),
		'falla_reportada'     => 'Calibración de rutina anual.',
		'descripcion_trabajo' => 'Calibración completa de empalmadora de fusión. Verificación de alineación de fibras, ajuste de parámetros de arco.',
		'observaciones'       => 'Equipo operativo. Empalmes con pérdida promedio 0.02 dB.',
		'garantia'            => true,
		'dias_garantia'       => 60,
		'items_costo'         => array(
			array( 'detalle' => 'Calibración empalmadora', 'cantidad' => '1.00', 'precio_unitario' => '95000.00' ),
		),
	),
	// Equipo 3 — dos eventos (historial)
	array(
		'equipo_i'            => 3,
		'numero_ot'           => 'OT-2023-041',
		'tipo'                => 'mantenimiento',
		'fecha_ejecucion'     => ( clone $hoy )->modify( '-720 days' )->format( 'Y-m-d' ),
		'proxima_fecha'       => ( clone $hoy )->modify( '-355 days' )->format( 'Y-m-d' ),
		'falla_reportada'     => 'Certificación de red LAN cat.6A edificio corporativo.',
		'descripcion_trabajo' => 'Certificación de 48 puntos de red. Todos los puntos aprobados según estándar TIA-568.',
		'observaciones'       => '',
		'garantia'            => false,
		'dias_garantia'       => 0,
		'items_costo'         => array(
			array( 'detalle' => 'Certificación por punto', 'cantidad' => '48.00', 'precio_unitario' => '3500.00' ),
		),
	),
	array(
		'equipo_i'            => 3,
		'numero_ot'           => 'OT-2024-082',
		'tipo'                => 'calibracion',
		'fecha_ejecucion'     => ( clone $hoy )->modify( '-10 days' )->format( 'Y-m-d' ),
		'proxima_fecha'       => ( clone $hoy )->modify( '+355 days' )->format( 'Y-m-d' ),
		'falla_reportada'     => 'Calibración anual certificador de red.',
		'descripcion_trabajo' => 'Calibración completa con patrón de referencia trazable. Verificación de medición de pérdida de inserción, retorno y NEXT.',
		'observaciones'       => 'Equipo en óptimas condiciones.',
		'garantia'            => true,
		'dias_garantia'       => 90,
		'items_costo'         => array(
			array( 'detalle' => 'Calibración certificador', 'cantidad' => '1.00', 'precio_unitario' => '110000.00' ),
			array( 'detalle' => 'Informe técnico', 'cantidad' => '1.00', 'precio_unitario' => '20000.00' ),
		),
	),
	// Equipo 4 — sin evento (estado: sin_evento)
	// (no se agrega evento intencionalmente)

	// Equipo 5 — vencido hace mucho
	array(
		'equipo_i'            => 5,
		'numero_ot'           => 'OT-2022-019',
		'tipo'                => 'calibracion',
		'fecha_ejecucion'     => ( clone $hoy )->modify( '-730 days' )->format( 'Y-m-d' ),
		'proxima_fecha'       => ( clone $hoy )->modify( '-365 days' )->format( 'Y-m-d' ),
		'falla_reportada'     => 'Primera calibración del equipo recién adquirido.',
		'descripcion_trabajo' => 'Calibración de fábrica verificada. Ajuste de parámetros de medición.',
		'observaciones'       => 'Equipo nuevo. Calibración de puesta en marcha.',
		'garantia'            => true,
		'dias_garantia'       => 365,
		'items_costo'         => array(
			array( 'detalle' => 'Calibración inicial OTDR', 'cantidad' => '1.00', 'precio_unitario' => '90000.00' ),
		),
	),
);

foreach ( $eventos_data as $ev ) {
	$equipo_id = isset( $equipo_ids[ $ev['equipo_i'] ] ) ? $equipo_ids[ $ev['equipo_i'] ] : 0;
	if ( ! $equipo_id ) {
		seed_log( "SKIP evento {$ev['numero_ot']}: equipo no creado" );
		continue;
	}

	$post_id = wp_insert_post( array(
		'post_title'  => $ev['numero_ot'],
		'post_type'   => 'evento_servicio',
		'post_status' => 'publish',
		'post_author' => $admin_id,
	) );
	if ( is_wp_error( $post_id ) ) {
		seed_log( 'ERROR evento: ' . $post_id->get_error_message() );
		continue;
	}

	update_post_meta( $post_id, CalibraTrack_Meta_Keys::EVENTO_EQUIPO_ID,            $equipo_id );
	update_post_meta( $post_id, CalibraTrack_Meta_Keys::EVENTO_NUMERO_OT,            $ev['numero_ot'] );
	update_post_meta( $post_id, CalibraTrack_Meta_Keys::EVENTO_TIPO,                 $ev['tipo'] );
	update_post_meta( $post_id, CalibraTrack_Meta_Keys::EVENTO_FECHA_EJECUCION,      $ev['fecha_ejecucion'] );
	update_post_meta( $post_id, CalibraTrack_Meta_Keys::EVENTO_PROXIMA_FECHA_CONTROL, $ev['proxima_fecha'] );
	update_post_meta( $post_id, CalibraTrack_Meta_Keys::EVENTO_TECNICO_RESPONSABLE,  $admin_id );
	update_post_meta( $post_id, CalibraTrack_Meta_Keys::EVENTO_FALLA_REPORTADA,      $ev['falla_reportada'] );
	update_post_meta( $post_id, CalibraTrack_Meta_Keys::EVENTO_DESCRIPCION_TRABAJO,  $ev['descripcion_trabajo'] );
	update_post_meta( $post_id, CalibraTrack_Meta_Keys::EVENTO_OBSERVACIONES,        $ev['observaciones'] );
	update_post_meta( $post_id, CalibraTrack_Meta_Keys::EVENTO_GARANTIA,             $ev['garantia'] ? '1' : '0' );
	update_post_meta( $post_id, CalibraTrack_Meta_Keys::EVENTO_DIAS_GARANTIA,        $ev['dias_garantia'] );
	update_post_meta( $post_id, CalibraTrack_Meta_Keys::EVENTO_EVIDENCIA_FOTOGRAFICA, '[]' );

	// Guardar items de costo.
	CalibraTrack_DB::save_items_costo( $post_id, $ev['items_costo'] );

	// Calcular y guardar totales.
	$items_obj = CalibraTrack_DB::get_items_costo( $post_id );
	$items_arr = array();
	foreach ( $items_obj as $item ) {
		$items_arr[] = array(
			'cantidad'        => (float) $item->cantidad,
			'precio_unitario' => (float) $item->precio_unitario,
		);
	}
	$totales = CalibraTrack_Helpers::calcular_totales_costo( $items_arr );
	update_post_meta( $post_id, 'calibratrack_subtotal', $totales['subtotal'] );
	update_post_meta( $post_id, 'calibratrack_iva',      $totales['iva'] );
	update_post_meta( $post_id, 'calibratrack_total',    $totales['total'] );

	// Invalidar caché de vigencia del equipo.
	delete_transient( 'calibratrack_vigencia_' . $equipo_id );

	$estado = CalibraTrack_Helpers::calcular_estado_vigencia( $ev['proxima_fecha'] );
	seed_log( "✓ Evento {$ev['numero_ot']} → equipo ID $equipo_id | estado: $estado | total: $" . number_format( $totales['total'], 0, ',', '.' ) );
}

// ─── RESUMEN ─────────────────────────────────────────────────────────────────

seed_log( '' );
seed_log( '=== RESUMEN ===' );
seed_log( count( $cliente_ids ) . ' clientes creados' );
seed_log( count( $equipo_ids ) . ' equipos creados' );
seed_log( 'Series para probar la verificación pública:' );
foreach ( $equipos_data as $e ) {
	$estado_txt = '';
	foreach ( $eventos_data as $ev ) {
		if ( $ev['equipo_i'] === array_search( $e, $equipos_data, true ) ) {
			$est = CalibraTrack_Helpers::calcular_estado_vigencia( $ev['proxima_fecha'] );
			$estado_txt = " → estado: $est";
		}
	}
	seed_log( "  /verificar/?serie={$e['serie']}{$estado_txt}" );
}
seed_log( '  /verificar/?serie=GW-FUENTE-2023-005 → estado: sin_evento' );

if ( php_sapi_name() !== 'cli' ) {
	echo '<pre style="font-family:monospace;padding:20px;">';
	echo implode( PHP_EOL, $log );
	echo PHP_EOL . PHP_EOL . 'Listo. <a href="/wp-admin/edit.php?post_type=equipo">Ver equipos →</a>';
	echo '</pre>';
}
