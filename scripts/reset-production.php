<?php
/**
 * SoccerTrack — Reset de Producción
 *
 * Vacía todas las tablas del plugin y reinicia AUTO_INCREMENT.
 * Conserva: wp_users, wp_usermeta y todas las tablas core de WordPress.
 *
 * USO:
 *   1. Subir este archivo a /wp-content/ (o cualquier carpeta fuera de plugins/).
 *   2. Abrir en el navegador: https://tudominio.cl/wp-content/reset-production.php
 *   3. ELIMINAR el archivo inmediatamente después de ejecutarlo.
 *
 * ⚠ IRREVERSIBLE — hacer respaldo antes de ejecutar:
 *   Herramientas → Exportar → Todo el contenido   (o mysqldump desde cPanel)
 */

// ── Seguridad: solo ejecutar en WordPress ──────────────────────────────────
define( 'ABSPATH_CHECK', true );

$wp_load = __DIR__ . '/../wp-load.php';
if ( ! file_exists( $wp_load ) ) {
    // Intentar un nivel más arriba (si está en wp-content/)
    $wp_load = __DIR__ . '/../../wp-load.php';
}
if ( ! file_exists( $wp_load ) ) {
    die( 'Error: no se encontró wp-load.php. Coloca este archivo dentro de wp-content/.' );
}

require_once $wp_load;

// Solo administradores pueden ejecutar esto.
if ( ! current_user_can( 'manage_options' ) ) {
    wp_die( 'Acceso denegado. Debes estar autenticado como administrador.', 403 );
}

global $wpdb;
$p = $wpdb->prefix; // normalmente 'wp_'

// ── Confirmación por parámetro GET ─────────────────────────────────────────
$confirmed = isset( $_GET['confirmar'] ) && $_GET['confirmar'] === 'SI_BORRAR_TODO';

if ( ! $confirmed ) {
    ?>
    <!DOCTYPE html>
    <html lang="es">
    <head>
        <meta charset="UTF-8">
        <title>Reset de Producción — SoccerTrack</title>
        <style>
            body { font-family: sans-serif; max-width: 640px; margin: 60px auto; padding: 20px; }
            .warning { background: #fff3cd; border: 2px solid #ffc107; border-radius: 6px; padding: 20px; margin-bottom: 24px; }
            .tables { background: #f8f9fa; border: 1px solid #dee2e6; border-radius: 6px; padding: 16px; margin-bottom: 24px; }
            table { border-collapse: collapse; width: 100%; font-size: .9rem; }
            th, td { padding: 6px 12px; border: 1px solid #dee2e6; text-align: left; }
            th { background: #e9ecef; }
            .btn-danger { background: #dc3545; color: #fff; border: none; padding: 12px 28px;
                          font-size: 1rem; border-radius: 4px; cursor: pointer; text-decoration: none; }
            .btn-cancel { margin-left: 16px; color: #666; font-size: .9rem; }
        </style>
    </head>
    <body>
        <h2>⚠ Reset de Producción — SoccerTrack</h2>

        <div class="warning">
            <strong>Esta acción vaciará TODAS las tablas del plugin:</strong><br>
            torneos, equipos, jugadores, partidos, eventos, sanciones, recintos, canchas y staff.<br><br>
            <strong>NO se tocan:</strong> usuarios de WordPress, configuración del sitio ni otros plugins.
        </div>

        <div class="tables">
            <strong>Estado actual:</strong>
            <?php
            $tables = [
                $p . 'ds_tournaments'            => 'Torneos',
                $p . 'ds_teams'                  => 'Equipos',
                $p . 'ds_players'                => 'Jugadores',
                $p . 'ds_team_players'           => 'Jugadores en equipos',
                $p . 'ds_matches'                => 'Partidos',
                $p . 'ds_match_events'           => 'Eventos de partido',
                $p . 'ds_disciplinary_sanctions' => 'Sanciones',
                $p . 'ds_playoff_brackets'       => 'Brackets de playoffs',
                $p . 'ds_venues'                 => 'Recintos',
                $p . 'ds_courts'                 => 'Canchas',
                $p . 'ds_tournament_venues'      => 'Recintos por torneo',
                $p . 'ds_staff'                  => 'Staff',
            ];
            echo '<table><thead><tr><th>Tabla</th><th>Registros actuales</th></tr></thead><tbody>';
            foreach ( $tables as $table => $label ) {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery
                $count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$table}`" );
                echo "<tr><td>{$label}</td><td>{$count}</td></tr>";
            }
            echo '</tbody></table>';
            ?>
        </div>

        <p>
            <a class="btn-danger"
               href="?confirmar=SI_BORRAR_TODO"
               onclick="return confirm('¿Estás SEGURO? Esta acción no se puede deshacer.')">
                🗑 Vaciar todas las tablas
            </a>
            <a class="btn-cancel" href="<?php echo esc_url( admin_url() ); ?>">Cancelar</a>
        </p>
        <p style="font-size:.8rem;color:#999">
            Recuerda eliminar este archivo del servidor después de ejecutarlo.
        </p>
    </body>
    </html>
    <?php
    exit;
}

// ── Ejecutar reset ─────────────────────────────────────────────────────────
$results = [];

$wpdb->query( 'SET FOREIGN_KEY_CHECKS = 0' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

$tables_to_truncate = [
    $p . 'ds_match_events',
    $p . 'ds_disciplinary_sanctions',
    $p . 'ds_playoff_brackets',
    $p . 'ds_matches',
    $p . 'ds_team_players',
    $p . 'ds_players',
    $p . 'ds_teams',
    $p . 'ds_courts',
    $p . 'ds_tournament_venues',
    $p . 'ds_venues',
    $p . 'ds_staff',
    $p . 'ds_tournaments',
];

foreach ( $tables_to_truncate as $table ) {
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery
    $ok = $wpdb->query( "TRUNCATE TABLE `{$table}`" );
    $results[] = [
        'table'  => $table,
        'status' => ( false !== $ok ) ? '✅ Vaciada' : '❌ Error: ' . $wpdb->last_error,
    ];
}

$wpdb->query( 'SET FOREIGN_KEY_CHECKS = 1' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Reset completado — SoccerTrack</title>
    <style>
        body { font-family: sans-serif; max-width: 640px; margin: 60px auto; padding: 20px; }
        table { border-collapse: collapse; width: 100%; font-size: .9rem; margin-bottom: 24px; }
        th, td { padding: 6px 12px; border: 1px solid #dee2e6; text-align: left; }
        th { background: #e9ecef; }
        .ok { color: #198754; }
        .err { color: #dc3545; }
        .notice { background: #d1ecf1; border: 1px solid #bee5eb; border-radius: 6px; padding: 14px; margin-bottom: 20px; }
    </style>
</head>
<body>
    <h2>✅ Reset completado</h2>
    <div class="notice">
        Todas las tablas del plugin han sido vaciadas. Los usuarios de WordPress y la
        configuración del sitio no fueron modificados.
    </div>

    <table>
        <thead><tr><th>Tabla</th><th>Resultado</th></tr></thead>
        <tbody>
        <?php foreach ( $results as $r ) : ?>
            <tr>
                <td><?php echo esc_html( $r['table'] ); ?></td>
                <td class="<?php echo str_starts_with( $r['status'], '✅' ) ? 'ok' : 'err'; ?>">
                    <?php echo esc_html( $r['status'] ); ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>

    <p style="color:#dc3545;font-weight:600">
        ⚠ Elimina este archivo del servidor ahora:<br>
        <code><?php echo esc_html( __FILE__ ); ?></code>
    </p>

    <p><a href="<?php echo esc_url( admin_url( 'admin.php?page=soccertrack' ) ); ?>">
        → Ir al panel de SoccerTrack
    </a></p>
</body>
</html>
<?php
