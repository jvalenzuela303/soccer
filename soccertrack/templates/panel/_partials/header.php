<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?php echo isset( $page_title ) ? esc_html( $page_title ) . ' — Gestión de Torneos' : 'Gestión de Torneos Panel'; ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="preload" as="style" href="https://fonts.googleapis.com/css2?family=Abril+Fatface&family=Poppins:wght@300;400;600;700&display=swap" onload="this.onload=null;this.rel='stylesheet'">
<noscript><link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Abril+Fatface&family=Poppins:wght@300;400;600;700&display=swap"></noscript>
<link rel="icon" type="image/png" href="https://torneoscorporativos.cl/wp-content/uploads/2025/07/Diseno-sin-titulo-1-scaled.png">
<link rel="stylesheet" href="<?php echo esc_url( SOCCERTRACK_URL . 'assets/css/panel.css?v=' . filemtime( SOCCERTRACK_DIR . 'assets/css/panel.css' ) ); ?>">
<link rel="stylesheet" href="<?php echo esc_url( SOCCERTRACK_URL . 'assets/css/admin-panel.css?v=' . filemtime( SOCCERTRACK_DIR . 'assets/css/admin-panel.css' ) ); ?>">
</head>
<body class="st-panel-body">

<header class="st-panel-header">
	<div class="st-panel-header-inner">
		<a href="<?php echo esc_url( home_url( '/panel/' ) ); ?>" class="st-panel-brand">
			<span class="st-panel-logo">⚽</span>
			<span class="st-panel-brand-name">Gestión de Torneos</span>
			<span class="st-panel-brand-sub">
				<?php
				if ( current_user_can( 'manage_options' ) ) {
					esc_html_e( 'Administrador', 'soccertrack' );
				} elseif ( current_user_can( 'ds_manage_tournaments' ) ) {
					esc_html_e( 'Coordinador', 'soccertrack' );
				} elseif ( current_user_can( 'ds_close_match' ) ) {
					esc_html_e( 'Veedor de Resultados', 'soccertrack' );
				}
				?>
			</span>
		</a>

		<?php if ( is_user_logged_in() ) : ?>
		<nav class="st-panel-nav" aria-label="<?php esc_attr_e( 'Menú principal', 'soccertrack' ); ?>">

			<?php if ( current_user_can( 'ds_manage_tournaments' ) || current_user_can( 'manage_options' ) ) : ?>
			<a href="<?php echo esc_url( home_url( '/panel/torneos/' ) ); ?>" class="st-nav-link
				<?php echo ( get_query_var( 'st_panel_vista' ) === 'torneos' || get_query_var( 'st_panel_vista' ) === 'torneo' ) ? 'st-nav-link--active' : ''; ?>">
				🏆 <?php esc_html_e( 'Torneos', 'soccertrack' ); ?>
			</a>
			<a href="<?php echo esc_url( home_url( '/panel/importar/' ) ); ?>" class="st-nav-link
				<?php echo get_query_var( 'st_panel_vista' ) === 'importar' ? 'st-nav-link--active' : ''; ?>">
				📥 <?php esc_html_e( 'Importar', 'soccertrack' ); ?>
			</a>
			<a href="<?php echo esc_url( home_url( '/panel/tribunal/' ) ); ?>" class="st-nav-link
				<?php echo get_query_var( 'st_panel_vista' ) === 'tribunal' ? 'st-nav-link--active' : ''; ?>">
				🟥 <?php esc_html_e( 'Tribunal', 'soccertrack' ); ?>
			</a>
			<a href="<?php echo esc_url( home_url( '/panel/recintos/' ) ); ?>" class="st-nav-link
				<?php echo ( get_query_var( 'st_panel_vista' ) === 'recintos' || get_query_var( 'st_panel_vista' ) === 'recinto' ) ? 'st-nav-link--active' : ''; ?>">
				🏟 <?php esc_html_e( 'Recintos', 'soccertrack' ); ?>
			</a>
			<?php if ( current_user_can( 'manage_options' ) ) : ?>
			<a href="<?php echo esc_url( home_url( '/panel/usuarios/' ) ); ?>" class="st-nav-link
				<?php echo get_query_var( 'st_panel_vista' ) === 'usuarios' ? 'st-nav-link--active' : ''; ?>">
				👤 <?php esc_html_e( 'Usuarios', 'soccertrack' ); ?>
			</a>
			<?php endif; ?>
			<?php endif; ?>

			<?php if ( current_user_can( 'ds_enter_match_incidents' ) || current_user_can( 'ds_close_match' ) ) : ?>
			<a href="<?php echo esc_url( home_url( '/panel/' ) ); ?>" class="st-nav-link
				<?php echo get_query_var( 'st_panel_vista' ) === 'dashboard' ? 'st-nav-link--active' : ''; ?>">
				📋 <?php esc_html_e( 'Mis Partidos', 'soccertrack' ); ?>
			</a>
			<?php endif; ?>

			<a href="<?php echo esc_url( home_url( '/panel/salir/' ) ); ?>" class="st-nav-link st-nav-link--salir">
				↩ <?php esc_html_e( 'Salir', 'soccertrack' ); ?>
			</a>
		</nav>

		<button class="st-nav-toggle" aria-label="Menú" aria-expanded="false">☰</button>
		<?php endif; ?>
	</div>
</header>

<main class="st-panel-main">
