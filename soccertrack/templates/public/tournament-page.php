<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?php echo esc_html( $tournament['name'] ); ?></title>
<meta name="description" content="<?php echo esc_attr( sprintf( __( 'Posiciones, fixture y resultados del torneo %s', 'soccertrack' ), $tournament['name'] ) ); ?>">

<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="preload" as="style" href="https://fonts.googleapis.com/css2?family=Abril+Fatface&family=Poppins:wght@300;400;600;700&display=swap" onload="this.onload=null;this.rel='stylesheet'">
<noscript><link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Abril+Fatface&family=Poppins:wght@300;400;600;700&display=swap"></noscript>
<link rel="stylesheet" href="<?php echo esc_url( SOCCERTRACK_URL . 'assets/css/public-tabs.css?v=' . filemtime( SOCCERTRACK_DIR . 'assets/css/public-tabs.css' ) ); ?>">
<link rel="stylesheet" href="<?php echo esc_url( SOCCERTRACK_URL . 'assets/css/tournament-page.css?v=' . filemtime( SOCCERTRACK_DIR . 'assets/css/tournament-page.css' ) ); ?>">

<?php wp_head(); ?>
</head>
<body class="st-public-body">

<?php /* ── Banner + Header del torneo ─────────────────────────────────── */ ?>
<?php if ( ! empty( $tournament['banner_url'] ) ) : ?>
<div class="st-tournament-banner">
	<img
		src="<?php echo esc_url( $tournament['banner_url'] ); ?>"
		alt="<?php echo esc_attr( sprintf(
			/* translators: %s: tournament name */
			__( 'Banner de %s', 'soccertrack' ),
			$tournament['name']
		) ); ?>"
		class="st-tournament-banner__img"
	>
	<div class="st-banner-header-overlay">
		<div class="st-banner-brand">
			<span class="st-banner-logo">⚽</span>
			<span class="st-banner-brand-name"><?php esc_html_e( 'Torneos Corporativos', 'soccertrack' ); ?></span>
		</div>
		<div class="st-banner-tournament">
			<h1 class="st-banner-tournament-name"><?php echo esc_html( $tournament['name'] ); ?></h1>
			<?php if ( ! empty( $tournament['start_date'] ) ) : ?>
			<p class="st-banner-tournament-dates">
				<?php
				echo esc_html( date_i18n( 'd M Y', strtotime( $tournament['start_date'] ) ) );
				if ( ! empty( $tournament['end_date'] ) ) {
					echo ' — ' . esc_html( date_i18n( 'd M Y', strtotime( $tournament['end_date'] ) ) );
				}
				?>
			</p>
			<?php endif; ?>
		</div>
	</div>
	<div id="st-results-ticker" class="st-results-ticker" aria-hidden="true">
		<div class="st-results-ticker__track"></div>
	</div>
</div>
<?php else : ?>
<header class="st-public-header">
	<div class="st-public-header-inner">
		<div class="st-public-brand">
			<span class="st-public-logo">⚽</span>
			<span class="st-public-brand-name"><?php esc_html_e( 'Torneos Corporativos', 'soccertrack' ); ?></span>
		</div>
		<div class="st-public-tournament">
			<h1 class="st-public-tournament-name"><?php echo esc_html( $tournament['name'] ); ?></h1>
			<?php if ( ! empty( $tournament['start_date'] ) ) : ?>
			<p class="st-public-tournament-dates">
				<?php
				echo esc_html( date_i18n( 'd M Y', strtotime( $tournament['start_date'] ) ) );
				if ( ! empty( $tournament['end_date'] ) ) {
					echo ' — ' . esc_html( date_i18n( 'd M Y', strtotime( $tournament['end_date'] ) ) );
				}
				?>
			</p>
			<?php endif; ?>
		</div>
	</div>
</header>
<?php endif; ?>

<?php
// Formatos que incluyen fase de play-offs.
$has_playoffs_tab = in_array( $tournament['format'] ?? '', [ 'round_robin_playoffs', 'group_stage', 'swiss' ], true );
?>

<?php /* ── Navegación de pestañas (pegada al banner) ─────────────────── */ ?>
<nav class="st-tabs-nav-wrap" aria-label="<?php esc_attr_e( 'Secciones del torneo', 'soccertrack' ); ?>">
	<ul class="st-tabs-nav" role="tablist">
		<li role="presentation">
			<button class="st-tab-btn" role="tab" data-tab="standings"
				id="st-tab-standings" aria-selected="false" aria-controls="st-panel-standings" tabindex="-1">
				📊 <?php esc_html_e( 'Posiciones', 'soccertrack' ); ?>
			</button>
		</li>
		<li role="presentation">
			<button class="st-tab-btn" role="tab" data-tab="fixture"
				id="st-tab-fixture" aria-selected="false" aria-controls="st-panel-fixture" tabindex="-1">
				📅 <?php esc_html_e( 'Fixture', 'soccertrack' ); ?>
			</button>
		</li>
		<?php if ( $has_playoffs_tab ) : ?>
		<li role="presentation">
			<button class="st-tab-btn" role="tab" data-tab="playoffs"
				id="st-tab-playoffs" aria-selected="false" aria-controls="st-panel-playoffs" tabindex="-1">
				🏆 <?php esc_html_e( 'Playoffs', 'soccertrack' ); ?>
			</button>
		</li>
		<?php endif; ?>
		<li role="presentation">
			<button class="st-tab-btn" role="tab" data-tab="teams"
				id="st-tab-teams" aria-selected="false" aria-controls="st-panel-teams" tabindex="-1">
				🏟 <?php esc_html_e( 'Equipos', 'soccertrack' ); ?>
			</button>
		</li>
		<li role="presentation">
			<button class="st-tab-btn" role="tab" data-tab="scorers"
				id="st-tab-scorers" aria-selected="false" aria-controls="st-panel-scorers" tabindex="-1">
				🥇 <?php esc_html_e( 'Goleadores', 'soccertrack' ); ?>
			</button>
		</li>
		<li role="presentation">
			<button class="st-tab-btn" role="tab" data-tab="tribunal"
				id="st-tab-tribunal" aria-selected="false" aria-controls="st-panel-tribunal" tabindex="-1">
				🟥 <?php esc_html_e( 'Tribunal', 'soccertrack' ); ?>
			</button>
		</li>
		<?php if ( ! empty( $tournament['bases_pdf_url'] ) ) : ?>
		<li role="presentation">
			<button class="st-tab-btn" role="tab" data-tab="bases"
				id="st-tab-bases" aria-selected="false" aria-controls="st-panel-bases" tabindex="-1">
				📄 <?php esc_html_e( 'Bases', 'soccertrack' ); ?>
			</button>
		</li>
		<?php endif; ?>
	</ul>
</nav>

<?php /* ── Portal de pestañas ────────────────────────────────────────── */ ?>
<div class="st-portal" id="st-portal-<?php echo esc_attr( (string) $tournament['id'] ); ?>">

	<section id="st-panel-standings" class="st-tab-panel" role="tabpanel" aria-labelledby="st-tab-standings" aria-hidden="true"></section>
	<section id="st-panel-fixture"   class="st-tab-panel" role="tabpanel" aria-labelledby="st-tab-fixture"   aria-hidden="true"></section>
	<?php if ( $has_playoffs_tab ) : ?>
	<section id="st-panel-playoffs"  class="st-tab-panel" role="tabpanel" aria-labelledby="st-tab-playoffs"  aria-hidden="true"></section>
	<?php endif; ?>
	<section id="st-panel-teams"     class="st-tab-panel" role="tabpanel" aria-labelledby="st-tab-teams"     aria-hidden="true"></section>
	<section id="st-panel-scorers"   class="st-tab-panel" role="tabpanel" aria-labelledby="st-tab-scorers"   aria-hidden="true"></section>
	<section id="st-panel-tribunal"  class="st-tab-panel" role="tabpanel" aria-labelledby="st-tab-tribunal"  aria-hidden="true"></section>
	<?php if ( ! empty( $tournament['bases_pdf_url'] ) ) : ?>
	<section id="st-panel-bases"     class="st-tab-panel" role="tabpanel" aria-labelledby="st-tab-bases"     aria-hidden="true"></section>
	<?php endif; ?>

</div>

<footer class="st-public-footer">
	<p>
		<?php
		printf(
			/* translators: 1: year, 2: tournament name */
			esc_html__( '© %1$d %2$s', 'soccertrack' ),
			(int) gmdate( 'Y' ),
			esc_html( $tournament['name'] )
		);
		?>
	</p>
</footer>

<script>
window.stPublic = <?php echo wp_json_encode( [
	'apiBase'      => esc_url_raw( get_rest_url() ),
	'tournamentId' => (int) $tournament['id'],
	'basesUrl'     => ! empty( $tournament['bases_pdf_url'] ) ? esc_url_raw( $tournament['bases_pdf_url'] ) : '',
	'tournamentFormat' => $tournament['format'] ?? '',
	'i18n'         => [
		'loading'          => __( 'Cargando…', 'soccertrack' ),
		'error_load'       => __( 'Error al cargar los datos.', 'soccertrack' ),
		'no_standings'     => __( 'Aún no hay partidos jugados.', 'soccertrack' ),
		'no_fixture'       => __( 'El fixture aún no ha sido generado.', 'soccertrack' ),
		'no_teams'         => __( 'No hay equipos inscritos.', 'soccertrack' ),
		'no_sanctions'     => __( 'No hay sanciones registradas.', 'soccertrack' ),
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
		'scorers_title'    => __( 'Goleadores y Estadísticas', 'soccertrack' ),
		'no_scorers'       => __( 'Aún no hay goles registrados.', 'soccertrack' ),
		'stats_title'          => __( 'Estadísticas del Torneo', 'soccertrack' ),
		'records_title'        => __( 'Records del Torneo', 'soccertrack' ),
		'best_attack'          => __( 'Mejor Ataque', 'soccertrack' ),
		'best_defense'         => __( 'Mejor Defensa', 'soccertrack' ),
		'most_clean_sheets'    => __( 'Arco Menos Vencido', 'soccertrack' ),
		'longest_streak'       => __( 'Racha Más Larga', 'soccertrack' ),
		'podium_title'         => __( 'Podio de Goleadores', 'soccertrack' ),
		'goals_label'          => __( 'goles', 'soccertrack' ),
		'gpm_label'            => __( 'G/PJ', 'soccertrack' ),
		'no_stats'             => __( 'Aún no hay partidos finalizados.', 'soccertrack' ),
		'goals_per_match'      => __( 'Goles por partido', 'soccertrack' ),
		'win_streak_label'     => __( 'victorias seguidas', 'soccertrack' ),
		'clean_sheets_label'   => __( 'porterías a 0', 'soccertrack' ),
		'goals_against_label'  => __( 'goles en contra', 'soccertrack' ),
		'goals_for_label'      => __( 'goles a favor', 'soccertrack' ),
		'playoffs_tab'     => __( 'Playoffs', 'soccertrack' ),
		'playoffs_title'   => __( 'Play-offs', 'soccertrack' ),
		'no_playoffs'      => __( 'Los play-offs aún no han comenzado.', 'soccertrack' ),
		'group_label'        => __( 'Grupo', 'soccertrack' ),
		'phase_octavos'      => __( 'Octavos de Final', 'soccertrack' ),
		'phase_quarterfinal' => __( 'Cuartos de Final', 'soccertrack' ),
		'phase_semifinal'    => __( 'Semi-finales', 'soccertrack' ),
		'phase_third_place'  => __( '3.er Puesto', 'soccertrack' ),
		'phase_final'        => __( 'Final', 'soccertrack' ),
		'bases_title'      => __( 'Bases del Torneo', 'soccertrack' ),
		'bases_download'   => __( 'Descargar Bases en PDF', 'soccertrack' ),
		'bases_desc'       => __( 'Descarga el reglamento oficial del torneo en formato PDF.', 'soccertrack' ),
	],
] ); ?>;
</script>
<script src="<?php echo esc_url( SOCCERTRACK_URL . 'assets/js/live-standings.js?v=' . SOCCERTRACK_VERSION ); ?>"></script>

<?php wp_footer(); ?>
</body>
</html>
