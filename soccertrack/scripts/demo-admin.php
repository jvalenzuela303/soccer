<?php
/**
 * SoccerTrack — Página de administración para datos demo.
 *
 * Registra una subpágina en WP Admin que permite:
 *   - Ver el estado actual de los datos demo
 *   - Ejecutar el seeder para generar el torneo de prueba
 *   - Limpiar todos los datos demo con un clic
 *
 * Solo visible para administradores.
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registra la subpágina "Demo" bajo el menú de Herramientas de WP.
 */
function soccertrack_demo_register_page(): void {
	add_management_page(
		__( 'SoccerTrack — Datos Demo', 'soccertrack' ),
		__( 'ST Demo', 'soccertrack' ),
		'manage_options',
		'soccertrack-demo',
		'soccertrack_demo_render_page'
	);
}

/**
 * Procesa y renderiza la página de demo.
 */
function soccertrack_demo_render_page(): void {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'Sin permiso.', 'soccertrack' ) );
	}

	require_once SOCCERTRACK_DIR . 'scripts/demo-seeder.php';

	global $wpdb;
	$notice = '';
	$type   = 'info';

	// ── Acción: Insertar datos demo ───────────────────────────────────────────
	if ( isset( $_POST['st_demo_action'] ) && $_POST['st_demo_action'] === 'seed' ) {
		check_admin_referer( 'st_demo_action' );
		ob_start();
		demo_seed( $wpdb );
		$log    = ob_get_clean();
		$notice = $log;
		$type   = 'success';
	}

	// ── Acción: Eliminar datos demo ───────────────────────────────────────────
	if ( isset( $_POST['st_demo_action'] ) && $_POST['st_demo_action'] === 'cleanup' ) {
		check_admin_referer( 'st_demo_action' );
		ob_start();
		demo_cleanup( $wpdb );
		$log    = ob_get_clean();
		$notice = $log;
		$type   = 'warning';
	}

	$tracked     = get_option( 'soccertrack_demo_seed_ids', null );
	$has_demo    = ! empty( $tracked );
	$torneo_id   = $has_demo ? (int) ( $tracked['tournament_id'] ?? 0 ) : 0;
	$portal_url  = $torneo_id ? home_url( "/torneo/{$torneo_id}/" ) : '';
	$panel_url   = $torneo_id ? home_url( "/panel/torneo/{$torneo_id}/" ) : '';

	?>
	<div class="wrap">
		<h1>🧪 <?php esc_html_e( 'SoccerTrack — Datos Demo', 'soccertrack' ); ?></h1>
		<p style="color:#555;max-width:680px">
			<?php esc_html_e( 'Genera un torneo de demo completo con 12 equipos, 240 jugadores, fixture de 11 jornadas, play-offs y sanciones de tribunal. Los datos pueden eliminarse en cualquier momento.', 'soccertrack' ); ?>
		</p>

		<?php if ( $notice ) : ?>
		<div style="background:#f0f0f0;border-left:4px solid <?php echo $type === 'success' ? '#3CBC20' : ( $type === 'warning' ? '#d63638' : '#2271b1' ); ?>;padding:12px 16px;margin:16px 0;border-radius:2px;font-family:monospace;font-size:13px;white-space:pre-wrap;max-width:680px">
			<?php echo esc_html( $notice ); ?>
		</div>
		<?php endif; ?>

		<?php if ( $has_demo ) : ?>
		<div style="background:#edfaed;border:1px solid #3CBC20;border-radius:6px;padding:16px 20px;max-width:680px;margin-bottom:24px">
			<p style="margin:0 0 8px;font-weight:600;color:#1a6b1a">
				✅ <?php esc_html_e( 'Datos demo activos', 'soccertrack' ); ?> — Torneo ID: <?php echo esc_html( (string) $torneo_id ); ?>
			</p>
			<ul style="margin:0 0 12px;padding-left:20px;color:#333">
				<li><?php echo esc_html( (string) count( $tracked['team_ids'] ?? [] ) ); ?> <?php esc_html_e( 'equipos', 'soccertrack' ); ?></li>
				<li><?php echo esc_html( (string) count( $tracked['regular_match_ids'] ?? [] ) ); ?> <?php esc_html_e( 'partidos de fase regular', 'soccertrack' ); ?></li>
				<li><?php echo esc_html( (string) count( $tracked['playoff_match_ids'] ?? [] ) ); ?> <?php esc_html_e( 'partidos de play-offs', 'soccertrack' ); ?></li>
				<li><?php echo esc_html( (string) count( $tracked['event_ids'] ?? [] ) ); ?> <?php esc_html_e( 'eventos (goles + tarjetas)', 'soccertrack' ); ?></li>
			</ul>
			<p style="margin:0">
				<a href="<?php echo esc_url( $portal_url ); ?>" target="_blank" class="button button-primary">
					🌐 <?php esc_html_e( 'Ver portal público', 'soccertrack' ); ?>
				</a>
				&nbsp;
				<a href="<?php echo esc_url( $panel_url ); ?>" target="_blank" class="button">
					⚙️ <?php esc_html_e( 'Abrir panel del torneo', 'soccertrack' ); ?>
				</a>
			</p>
		</div>

		<form method="post" onsubmit="return confirm('<?php esc_attr_e( '¿Eliminar todos los datos demo? Esta acción no se puede deshacer.', 'soccertrack' ); ?>')">
			<?php wp_nonce_field( 'st_demo_action' ); ?>
			<input type="hidden" name="st_demo_action" value="cleanup">
			<button type="submit" class="button button-link-delete" style="font-size:14px">
				🗑️ <?php esc_html_e( 'Eliminar datos demo', 'soccertrack' ); ?>
			</button>
		</form>

		<?php else : ?>
		<div style="background:#f6f7f7;border:1px solid #c3c4c7;border-radius:6px;padding:16px 20px;max-width:680px;margin-bottom:24px">
			<p style="margin:0 0 4px;font-weight:600">📭 <?php esc_html_e( 'No hay datos demo activos', 'soccertrack' ); ?></p>
			<p style="margin:0;color:#666;font-size:13px">
				<?php esc_html_e( 'El seeder generará automáticamente: torneo, recintos, equipos, jugadores, fixture completo, play-offs y sanciones de tribunal.', 'soccertrack' ); ?>
			</p>
		</div>

		<form method="post">
			<?php wp_nonce_field( 'st_demo_action' ); ?>
			<input type="hidden" name="st_demo_action" value="seed">
			<button type="submit" class="button button-primary" style="font-size:15px;height:38px;padding:0 20px">
				🌱 <?php esc_html_e( 'Generar datos demo', 'soccertrack' ); ?>
			</button>
			<p class="description" style="margin-top:8px">
				<?php esc_html_e( 'Puede tardar 10–20 segundos. No cierres la página.', 'soccertrack' ); ?>
			</p>
		</form>
		<?php endif; ?>
	</div>
	<?php
}
