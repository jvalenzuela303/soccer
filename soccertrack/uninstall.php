<?php
/**
 * Script de desinstalación de SoccerTrack.
 *
 * Se ejecuta cuando un administrador elige "Eliminar" el plugin desde
 * el panel de WordPress. Borra tablas y opciones solo si el admin
 * ha activado el borrado profundo en la configuración del plugin.
 *
 * NUNCA se ejecuta en desactivación — solo en eliminación definitiva.
 * ADVERTENCIA: Esta operación es irreversible.
 *
 * @package SoccerTrack
 */

// Seguridad: debe ser llamado por WordPress en contexto de desinstalación.
defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

// Verificar que el admin eligió purgar los datos.
// Si la opción no existe o no está activa, no borrar nada.
$options = get_option( 'soccertrack_settings', [] );

if ( empty( $options['uninstall_delete_data'] ) ) {
	return;
}

global $wpdb;

// Orden inverso de dependencias (respeta FK lógicas).
$tables = [
	'ds_match_events',
	'ds_disciplinary_sanctions',
	'ds_team_players',
	'ds_matches',
	'ds_players',
	'ds_teams',
	'ds_courts',
	'ds_venues',
	'ds_tournaments',
];

foreach ( $tables as $table ) {
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared
	$wpdb->query( "DROP TABLE IF EXISTS `{$wpdb->prefix}{$table}`" );
}

// Eliminar opciones del plugin.
delete_option( 'soccertrack_db_version' );
delete_option( 'soccertrack_settings' );

// Eliminar roles personalizados.
foreach ( [ 'ds_coordinador', 'ds_arbitro', 'ds_planillero' ] as $role_slug ) {
	remove_role( $role_slug );
}

// Limpiar capabilities del administrator.
$admin_role = get_role( 'administrator' );

if ( $admin_role ) {
	foreach ( [
		'ds_manage_tournaments',
		'ds_load_excel',
		'ds_generate_fixture',
		'ds_manage_discipline',
		'ds_view_admin_panel',
		'ds_view_match_sheet',
		'ds_enter_match_incidents',
		'ds_edit_incidents',
		'ds_close_match',
	] as $cap ) {
		$admin_role->remove_cap( $cap );
	}
}

flush_rewrite_rules();
