<?php
/**
 * Template del Portal Público de SoccerTrack.
 *
 * Shortcode: [soccertrack_portal tournament_id="123"]
 *
 * Variables disponibles (pasadas desde el shortcode):
 *   $tournament_id int
 *   $bases_url     string  URL del PDF de bases (puede ser vacía)
 *   $tabs          string[] Lista de tabs habilitadas
 *
 * @package SoccerTrack
 */

// Protección directa.
defined( 'ABSPATH' ) || exit;

$allowed_tabs = [
	'teams'     => __( 'Equipos', 'soccertrack' ),
	'standings' => __( 'Posiciones', 'soccertrack' ),
	'fixture'   => __( 'Fixture', 'soccertrack' ),
	'tribunal'  => __( 'Tribunal', 'soccertrack' ),
];

$active_tabs = array_filter(
	$allowed_tabs,
	static fn( string $key ): bool => in_array( $key, $tabs, true ),
	ARRAY_FILTER_USE_KEY
);

if ( empty( $active_tabs ) ) {
	$active_tabs = $allowed_tabs;
}

$tab_icons = [
	'teams'     => '🏟',
	'standings' => '📊',
	'fixture'   => '📅',
	'tribunal'  => '🟥',
];
?>

<div
	class="st-portal"
	id="st-portal-<?php echo esc_attr( (string) $tournament_id ); ?>"
	data-tournament="<?php echo esc_attr( (string) $tournament_id ); ?>"
>
	<?php /* ── Navegación de pestañas ─────────────────────────────────── */ ?>
	<nav aria-label="<?php esc_attr_e( 'Secciones del torneo', 'soccertrack' ); ?>">
		<ul class="st-tabs-nav" role="tablist">
			<?php foreach ( $active_tabs as $tab_id => $tab_label ) : ?>
				<li role="presentation">
					<button
						class="st-tab-btn"
						role="tab"
						id="st-tab-<?php echo esc_attr( $tab_id ); ?>"
						data-tab="<?php echo esc_attr( $tab_id ); ?>"
						aria-selected="false"
						aria-controls="st-panel-<?php echo esc_attr( $tab_id ); ?>"
						tabindex="-1"
					>
						<?php echo esc_html( ( $tab_icons[ $tab_id ] ?? '' ) . ' ' . $tab_label ); ?>
					</button>
				</li>
			<?php endforeach; ?>

			<?php if ( ! empty( $bases_url ) ) : ?>
				<li role="presentation">
					<a
						href="<?php echo esc_url( $bases_url ); ?>"
						class="st-tab-btn"
						download
						aria-label="<?php esc_attr_e( 'Descargar bases del torneo (PDF)', 'soccertrack' ); ?>"
					>
						📄 <?php esc_html_e( 'Bases', 'soccertrack' ); ?>
					</a>
				</li>
			<?php endif; ?>
		</ul>
	</nav>

	<?php /* ── Paneles de contenido ────────────────────────────────────── */ ?>
	<?php foreach ( $active_tabs as $tab_id => $tab_label ) : ?>
		<section
			id="st-panel-<?php echo esc_attr( $tab_id ); ?>"
			class="st-tab-panel"
			role="tabpanel"
			aria-labelledby="st-tab-<?php echo esc_attr( $tab_id ); ?>"
			aria-hidden="true"
		>
			<?php /* JS renderiza aquí el contenido dinámicamente */ ?>
		</section>
	<?php endforeach; ?>

</div>

<?php
// Enqueue assets si no están ya en cola (permite usar el shortcode varias veces).
if ( ! wp_script_is( 'soccertrack-public-tabs', 'enqueued' ) ) {
	wp_enqueue_style(
		'soccertrack-public-tabs',
		SOCCERTRACK_URL . 'assets/css/public-tabs.css',
		[],
		SOCCERTRACK_VERSION
	);

	wp_enqueue_script(
		'soccertrack-public-tabs',
		SOCCERTRACK_URL . 'assets/js/live-standings.js',
		[],
		SOCCERTRACK_VERSION,
		true
	);

	wp_localize_script(
		'soccertrack-public-tabs',
		'stPublic',
		[
			'apiBase'      => esc_url_raw( get_rest_url() ),
			'tournamentId' => (int) $tournament_id,
			'basesUrl'     => esc_url_raw( $bases_url ?? '' ),
			'i18n'         => [
				'loading'          => __( 'Cargando…', 'soccertrack' ),
				'error_load'       => __( 'Error al cargar los datos.', 'soccertrack' ),
				'no_standings'     => __( 'Aún no hay partidos jugados.', 'soccertrack' ),
				'no_fixture'       => __( 'El fixture aún no ha sido generado.', 'soccertrack' ),
				'no_teams'         => __( 'No hay equipos inscritos.', 'soccertrack' ),
				'no_sanctions'          => __( 'No hay sanciones registradas.', 'soccertrack' ),
				'pending_review_title'  => __( 'Casos a Resolver', 'soccertrack' ),
				'pending_review_notice' => __( 'El tribunal revisará estos incidentes y determinará la sanción correspondiente.', 'soccertrack' ),
				'sanctions_title'       => __( 'Sanciones Resueltas', 'soccertrack' ),
				'last_round'            => __( 'Última Fecha', 'soccertrack' ),
				'incident'              => __( 'Incidente', 'soccertrack' ),
				'pending_decision'      => __( 'Pendiente resolución', 'soccertrack' ),
				'event_red_card'        => __( 'Tarjeta Roja', 'soccertrack' ),
				'event_yellow_card'     => __( 'Tarjeta Amarilla', 'soccertrack' ),
				'standings_title'  => __( 'Tabla de Posiciones', 'soccertrack' ),
				'fixture_title'    => __( 'Fixture', 'soccertrack' ),
				'teams_title'      => __( 'Equipos', 'soccertrack' ),
				'tribunal_title'   => __( 'Tribunal Disciplinario', 'soccertrack' ),
				'round'            => __( 'Fecha', 'soccertrack' ),
				'team'             => __( 'Equipo', 'soccertrack' ),
				'player'           => __( 'Jugador', 'soccertrack' ),
				'reason'           => __( 'Motivo', 'soccertrack' ),
				'ban'              => __( 'Fechas', 'soccertrack' ),
				'remaining'        => __( 'Restantes', 'soccertrack' ),
				'status'           => __( 'Estado', 'soccertrack' ),
				'status_finished'  => __( 'Finalizado', 'soccertrack' ),
				'status_scheduled' => __( 'Programado', 'soccertrack' ),
				'status_live'      => __( 'En curso', 'soccertrack' ),
				'status_active'    => __( 'Activa', 'soccertrack' ),
				'status_served'    => __( 'Cumplida', 'soccertrack' ),
			],
		]
	);
}
