<?php
/**
 * Plugin Name:       CalibraTrack
 * Plugin URI:        https://github.com/truetech/calibratrack
 * Description:       Sistema de trazabilidad y verificación pública de calibraciones y Mantenciones para (OI &amp; OT).
 * Version:           1.1.0
 * Requires at least: 6.1
 * Requires PHP:      7.4
 * Author:            Jvalenzuela
 * Author URI:        https://truetech.cl
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       calibratrack
 * Domain Path:       /languages
 *
 * @package CalibraTrack
 */

// Seguridad: bloquear acceso directo.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Constantes del plugin.
define( 'CALIBRATRACK_VERSION', '1.1.0' );
define( 'CALIBRATRACK_DB_VERSION', '1.0.0' );
define( 'CALIBRATRACK_PLUGIN_FILE', __FILE__ );
define( 'CALIBRATRACK_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'CALIBRATRACK_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'CALIBRATRACK_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

/**
 * Carga los archivos del plugin una sola vez.
 * Orden: constantes de meta keys → clases de soporte → CPTs → roles → activación.
 */
function calibratrack_load_dependencies() {
	// Fuente única de meta keys (ningún otro archivo las repite).
	require_once CALIBRATRACK_PLUGIN_DIR . 'includes/class-calibratrack-meta-keys.php';

	// Helpers y utilidades compartidas.
	require_once CALIBRATRACK_PLUGIN_DIR . 'includes/class-calibratrack-helpers.php';

	// Registro de CPTs.
	require_once CALIBRATRACK_PLUGIN_DIR . 'includes/class-calibratrack-cpt-equipo.php';
	require_once CALIBRATRACK_PLUGIN_DIR . 'includes/class-calibratrack-cpt-cliente.php';
	require_once CALIBRATRACK_PLUGIN_DIR . 'includes/class-calibratrack-cpt-evento-servicio.php';

	// Registro de meta fields.
	require_once CALIBRATRACK_PLUGIN_DIR . 'includes/class-calibratrack-meta-registration.php';

	// Roles y capabilities.
	require_once CALIBRATRACK_PLUGIN_DIR . 'includes/class-calibratrack-roles.php';

	// Tabla custom para items_costo y mensajes.
	require_once CALIBRATRACK_PLUGIN_DIR . 'includes/class-calibratrack-db.php';

	// Mensajería interna por OT (tabla custom calibratrack_mensajes).
	require_once CALIBRATRACK_PLUGIN_DIR . 'includes/class-calibratrack-mensajes.php';

	// Generación de códigos QR para equipos (requiere composer install).
	// Se carga siempre (no solo en admin) por si se genera el QR desde WP-CLI
	// o desde un proceso en segundo plano. La clase verifica disponibilidad
	// de la librería internamente antes de operar.
	require_once CALIBRATRACK_PLUGIN_DIR . 'includes/class-calibratrack-qr.php';

	// Admin.
	if ( is_admin() ) {
		require_once CALIBRATRACK_PLUGIN_DIR . 'admin/class-calibratrack-admin.php';
	}

	// Frente público.
	require_once CALIBRATRACK_PLUGIN_DIR . 'public/class-calibratrack-public.php';

	// Panel unificado en /panel/ (absorbe /tecnico/ y /administrar/).
	require_once CALIBRATRACK_PLUGIN_DIR . 'includes/class-calibratrack-panel.php';

	// Clase técnico: se carga para que sus métodos públicos (helpers)
	// puedan ser llamados por CalibraTrack_Panel. Ya no registra sus propios hooks.
	require_once CALIBRATRACK_PLUGIN_DIR . 'includes/class-calibratrack-tecnico.php';

	// Generador de certificados PDF (Dompdf).
	require_once CALIBRATRACK_PLUGIN_DIR . 'includes/class-calibratrack-pdf-generator.php';

	// Envío de correos transaccionales.
	require_once CALIBRATRACK_PLUGIN_DIR . 'includes/class-calibratrack-mailer.php';

	// API REST interna.
	require_once CALIBRATRACK_PLUGIN_DIR . 'includes/class-calibratrack-rest-api.php';

	// Cron de alertas de vencimiento (RF-08).
	require_once CALIBRATRACK_PLUGIN_DIR . 'includes/class-calibratrack-cron.php';
}

/**
 * Carga dependencias y registra hooks no relacionados a CPTs.
 * Se engancha a 'plugins_loaded' (antes de 'init').
 */
function calibratrack_init() {
	calibratrack_load_dependencies();

	// Cargar traducciones.
	load_plugin_textdomain(
		'calibratrack',
		false,
		dirname( CALIBRATRACK_PLUGIN_BASENAME ) . '/languages'
	);

	// Verificar y aplicar migraciones de DB si la versión cambió.
	CalibraTrack_DB::maybe_upgrade();

	// Inicializar módulo público.
	CalibraTrack_Public::init();

	// Inicializar panel unificado (incluye rewrite rules de /panel/,
	// /tecnico/* y /administrar/* como aliases). No llamar init() de las
	// clases antiguas para evitar hooks duplicados.
	CalibraTrack_Panel::init();

	// Inicializar mailer (SMTP + AJAX test email).
	CalibraTrack_Mailer::init();

	// Inicializar REST API.
	CalibraTrack_REST_API::init();

	// Inicializar cron de alertas.
	CalibraTrack_Cron::init();

	// Inicializar admin.
	if ( is_admin() ) {
		CalibraTrack_Admin::init();
	}
}
add_action( 'plugins_loaded', 'calibratrack_init' );

/**
 * Registra los CPTs y meta fields en el hook 'init'.
 * DEBE ir en 'init' (no en 'plugins_loaded') porque register_post_type()
 * llama internamente a $wp_rewrite->add_rewrite_tag(), que no existe aún
 * en 'plugins_loaded'.
 */
function calibratrack_register_post_types() {
	// Las clases ya fueron cargadas por calibratrack_init() en plugins_loaded.
	// Si por algún motivo init corre antes (improbable), cargamos aquí también.
	if ( ! class_exists( 'CalibraTrack_CPT_Equipo' ) ) {
		calibratrack_load_dependencies();
	}

	CalibraTrack_CPT_Equipo::register();
	CalibraTrack_CPT_Cliente::register();
	CalibraTrack_CPT_EventoServicio::register();

	// Los meta fields también deben registrarse en 'init'.
	CalibraTrack_Meta_Registration::register_all();

	// Auto-flush: si la versión de rewrite rules registrada en BD no coincide
	// con la versión actual del plugin, forzar un flush. Esto garantiza que
	// las rutas /tecnico/* y /verificar/* siempre estén activas sin intervención
	// manual en el panel de WordPress.
	calibratrack_maybe_flush_rewrite_rules();
}
add_action( 'init', 'calibratrack_register_post_types' );

/**
 * Compara la versión de rewrite rules guardada contra la actual y hace flush
 * una sola vez cuando detecta que están desactualizadas.
 *
 * Se ejecuta en el hook 'init', después de registrar CPTs y rewrite rules.
 *
 * @return void
 */
function calibratrack_maybe_flush_rewrite_rules() {
	// Versión que identifica el conjunto actual de rewrite rules.
	// Incrementar cuando se añadan o modifiquen rutas en el plugin.
	$rules_version = '1.7.0'; // 1.7.0: /panel/cliente/{id}/, /panel/equipo/{id}/, /panel/equipo/nuevo/, /panel/cliente/nuevo/

	if ( get_option( 'calibratrack_rewrite_version' ) !== $rules_version ) {
		flush_rewrite_rules( false );
		update_option( 'calibratrack_rewrite_version', $rules_version );
	}
}

// ─── Activación ────────────────────────────────────────────────────────────────

/**
 * Hook de activación del plugin.
 * Se ejecuta una sola vez cuando el administrador activa el plugin.
 */
function calibratrack_activate() {
	// Cargar dependencias mínimas para la activación (roles y DB).
	require_once CALIBRATRACK_PLUGIN_DIR . 'includes/class-calibratrack-meta-keys.php';
	require_once CALIBRATRACK_PLUGIN_DIR . 'includes/class-calibratrack-helpers.php';
	require_once CALIBRATRACK_PLUGIN_DIR . 'includes/class-calibratrack-roles.php';
	require_once CALIBRATRACK_PLUGIN_DIR . 'includes/class-calibratrack-db.php';
	require_once CALIBRATRACK_PLUGIN_DIR . 'includes/class-calibratrack-cpt-equipo.php';
	require_once CALIBRATRACK_PLUGIN_DIR . 'includes/class-calibratrack-cpt-cliente.php';
	require_once CALIBRATRACK_PLUGIN_DIR . 'includes/class-calibratrack-cpt-evento-servicio.php';
	require_once CALIBRATRACK_PLUGIN_DIR . 'includes/class-calibratrack-cron.php';

	// 1. Crear/actualizar rol tecnico_calibracion.
	CalibraTrack_Roles::add_roles();

	// 2. Registrar CPTs antes del flush (necesario para la rewrite rule de /verificar/{serie}).
	CalibraTrack_CPT_Equipo::register();
	CalibraTrack_CPT_Cliente::register();
	CalibraTrack_CPT_EventoServicio::register();

	// 3. Crear/actualizar tablas custom (items_costo).
	CalibraTrack_DB::create_tables();

	// 4. Programar el cron de alertas de vencimiento (RF-08).
	CalibraTrack_Cron::schedule();

	// 5. Registrar rewrite rules del panel unificado antes del flush.
	require_once CALIBRATRACK_PLUGIN_DIR . 'includes/class-calibratrack-tecnico.php';
	require_once CALIBRATRACK_PLUGIN_DIR . 'includes/class-calibratrack-panel.php';
	CalibraTrack_Panel::register_rewrite_rules();

	// 6. Flush de rewrite rules para que /verificar/{serie}, /tecnico/* y /administrar/* funcionen de inmediato.
	flush_rewrite_rules();

	// Marcar versión instalada.
	update_option( 'calibratrack_version', CALIBRATRACK_VERSION );
}
register_activation_hook( CALIBRATRACK_PLUGIN_FILE, 'calibratrack_activate' );

// ─── Desactivación ─────────────────────────────────────────────────────────────

/**
 * Hook de desactivación del plugin.
 * No elimina datos — solo limpia rewrite rules.
 * La eliminación permanente de datos ocurre en uninstall.php.
 */
function calibratrack_deactivate() {
	// Cancelar el cron de alertas.
	if ( class_exists( 'CalibraTrack_Cron' ) ) {
		CalibraTrack_Cron::unschedule();
	} else {
		require_once CALIBRATRACK_PLUGIN_DIR . 'includes/class-calibratrack-cron.php';
		CalibraTrack_Cron::unschedule();
	}

	flush_rewrite_rules();
}
register_deactivation_hook( CALIBRATRACK_PLUGIN_FILE, 'calibratrack_deactivate' );
