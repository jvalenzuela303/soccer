<?php
/**
 * Desinstalación de CalibraTrack.
 *
 * Este archivo se ejecuta cuando el administrador elige "Eliminar" el plugin
 * desde el panel de WordPress (después de desactivarlo).
 * NO se ejecuta en la desactivación — solo en la eliminación definitiva.
 *
 * ADVERTENCIA PARA EL ADMINISTRADOR (documentada aquí y en docs/decisiones.md):
 *   La desinstalación elimina PERMANENTEMENTE:
 *   - Todos los posts de los CPTs: equipo, cliente, evento_servicio.
 *   - Toda la postmeta asociada a esos posts.
 *   - La tabla custom calibratrack_items_costo y todos sus datos.
 *   - Las opciones del plugin en wp_options.
 *   - El rol tecnico_calibracion (los usuarios con ese rol quedan sin rol asignado).
 *   - Los archivos adjuntos (PDFs, QRs, fotos) NO se eliminan automáticamente
 *     porque son attachments de WP Media Library que pueden estar en uso. El
 *     administrador debe limpiarlos manualmente desde la biblioteca de medios.
 *
 * DECISIÓN: No eliminar los attachments automáticamente para evitar borrado
 * accidental de archivos en uso por otros elementos del sitio. Esta es la
 * política más segura en un hosting compartido donde un error no tiene vuelta atrás.
 *
 * @package CalibraTrack
 */

// Seguridad: WP define esta constante antes de ejecutar uninstall.php.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// Cargar solo lo mínimo necesario — no calibratrack_init().
require_once plugin_dir_path( __FILE__ ) . 'includes/class-calibratrack-roles.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/class-calibratrack-db.php';

global $wpdb;

// ─── 1. Eliminar posts de los CPTs ─────────────────────────────────────────

$post_types = array( 'equipo', 'cliente', 'evento_servicio' );

foreach ( $post_types as $post_type ) {
	$post_ids = $wpdb->get_col(
		$wpdb->prepare(
			"SELECT ID FROM {$wpdb->posts} WHERE post_type = %s",
			$post_type
		)
	);

	foreach ( $post_ids as $post_id ) {
		// wp_delete_post() elimina también la postmeta asociada.
		// Segundo argumento true = borrar definitivamente (sin papelera).
		wp_delete_post( (int) $post_id, true );
	}
}

// ─── 2. Eliminar la tabla custom de ítems de costo ─────────────────────────

CalibraTrack_DB::drop_tables();

// ─── 3. Eliminar opciones del plugin ───────────────────────────────────────

delete_option( 'calibratrack_version' );
delete_option( 'calibratrack_db_version' );
// Agregar aquí cualquier otra opción que el plugin agregue en el futuro.

// ─── 4. Eliminar el rol tecnico_calibracion ────────────────────────────────
// Los usuarios con este rol quedan sin rol asignado en WP (comportamiento
// estándar de WordPress al eliminar un rol).

CalibraTrack_Roles::remove_roles();

// ─── 5. Limpiar rewrite rules ──────────────────────────────────────────────

flush_rewrite_rules();
