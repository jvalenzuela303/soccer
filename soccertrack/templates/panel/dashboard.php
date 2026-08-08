<?php defined( 'ABSPATH' ) || exit; ?>

<div class="st-page-header">
	<h1 class="st-page-title">
		<?php
		$user = wp_get_current_user();
		printf( esc_html__( 'Hola, %s', 'soccertrack' ), esc_html( $user->display_name ) );
		?>
	</h1>
</div>

<?php /* ── Estadísticas rápidas ─────────────────────────────────────── */ ?>
<div class="st-stat-grid">
	<div class="st-stat-card">
		<span class="st-stat-icon">🏆</span>
		<span class="st-stat-num"><?php echo esc_html( (string) $stats['tournaments'] ); ?></span>
		<span class="st-stat-label"><?php esc_html_e( 'Torneos', 'soccertrack' ); ?></span>
	</div>
	<div class="st-stat-card">
		<span class="st-stat-icon">👕</span>
		<span class="st-stat-num"><?php echo esc_html( (string) $stats['teams'] ); ?></span>
		<span class="st-stat-label"><?php esc_html_e( 'Equipos', 'soccertrack' ); ?></span>
	</div>
	<div class="st-stat-card">
		<span class="st-stat-icon">👟</span>
		<span class="st-stat-num"><?php echo esc_html( (string) $stats['players'] ); ?></span>
		<span class="st-stat-label"><?php esc_html_e( 'Jugadores inscritos', 'soccertrack' ); ?></span>
	</div>
	<div class="st-stat-card">
		<span class="st-stat-icon">⚽</span>
		<span class="st-stat-num"><?php echo esc_html( (string) $stats['matches'] ); ?></span>
		<span class="st-stat-label"><?php esc_html_e( 'Partidos jugados', 'soccertrack' ); ?></span>
	</div>
	<div class="st-stat-card st-stat-card--warning">
		<span class="st-stat-icon">🟥</span>
		<span class="st-stat-num"><?php echo esc_html( (string) $stats['sanctions'] ); ?></span>
		<span class="st-stat-label"><?php esc_html_e( 'Sanciones activas', 'soccertrack' ); ?></span>
	</div>
</div>

<?php /* ── Torneos recientes ────────────────────────────────────────── */ ?>
<?php if ( ! empty( $tournaments ) ) : ?>
<div class="st-section">
	<h2 class="st-section-title"><?php esc_html_e( 'Torneos recientes', 'soccertrack' ); ?></h2>
	<div class="st-card-grid">
		<?php foreach ( $tournaments as $t ) : ?>
		<a href="<?php echo esc_url( home_url( '/torneo/' . $t['id'] . '/' ) ); ?>" class="st-tournament-card" target="_blank">
			<div class="st-tournament-card__body">
				<h3 class="st-tournament-card__name"><?php echo esc_html( $t['name'] ); ?></h3>
				<?php if ( $t['start_date'] ) : ?>
				<p class="st-tournament-card__date">
					<?php echo esc_html( date_i18n( 'd M Y', strtotime( $t['start_date'] ) ) ); ?>
				</p>
				<?php endif; ?>
				<span class="st-badge st-badge--<?php echo esc_attr( $t['status'] ?? 'draft' ); ?>">
					<?php echo esc_html( $t['status'] ?? 'draft' ); ?>
				</span>
			</div>
			<div class="st-tournament-card__footer">
				<?php esc_html_e( 'Ver portal público →', 'soccertrack' ); ?>
			</div>
		</a>
		<?php endforeach; ?>
	</div>
</div>
<?php endif; ?>

<?php /* ── Accesos rápidos según rol ─────────────────────────────────── */ ?>
<div class="st-section">
	<h2 class="st-section-title"><?php esc_html_e( 'Accesos rápidos', 'soccertrack' ); ?></h2>
	<div class="st-quick-links">
		<?php if ( current_user_can( 'ds_manage_tournaments' ) ) : ?>
		<a href="<?php echo esc_url( home_url( '/panel/torneos/' ) ); ?>" class="st-quick-link">
			<span>🏆</span><?php esc_html_e( 'Gestionar torneos', 'soccertrack' ); ?>
		</a>
		<a href="<?php echo esc_url( home_url( '/panel/importar/' ) ); ?>" class="st-quick-link">
			<span>📥</span><?php esc_html_e( 'Importar equipos / jugadores', 'soccertrack' ); ?>
		</a>
		<a href="<?php echo esc_url( home_url( '/panel/tribunal/' ) ); ?>" class="st-quick-link">
			<span>🟥</span><?php esc_html_e( 'Tribunal disciplinario', 'soccertrack' ); ?>
		</a>
		<?php endif; ?>
		<?php if ( current_user_can( 'ds_enter_match_incidents' ) || current_user_can( 'ds_close_match' ) ) : ?>
		<a href="<?php echo esc_url( home_url( '/panel/mis-partidos/' ) ); ?>" class="st-quick-link st-quick-link--primary">
			<span>📋</span><?php esc_html_e( 'Planilla arbitral', 'soccertrack' ); ?>
		</a>
		<?php endif; ?>
	</div>
</div>
