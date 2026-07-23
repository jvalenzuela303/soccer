<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?php echo isset( $page_title ) ? esc_html( $page_title ) . ' — TrueTech' : 'Panel Técnico — TrueTech'; ?></title>
<link rel="stylesheet" href="<?php echo esc_url( CALIBRATRACK_PLUGIN_URL . 'assets/css/tecnico.css?v=' . CALIBRATRACK_VERSION ); ?>">
</head>
<body class="ct-tecnico-body">
<header class="ct-panel-header">
	<div class="ct-panel-header-inner">
		<div class="ct-panel-brand">
			<span class="ct-panel-logo-text">TrueTech</span>
			<span class="ct-panel-subtitle"><?php esc_html_e( 'Panel Técnico', 'calibratrack' ); ?></span>
		</div>
		<?php if ( is_user_logged_in() ) : ?>
		<nav class="ct-panel-nav">
			<a href="<?php echo esc_url( home_url( '/tecnico/' ) ); ?>" class="ct-nav-link"><?php esc_html_e( 'Inicio', 'calibratrack' ); ?></a>
			<a href="<?php echo esc_url( home_url( '/tecnico/nuevo-evento/' ) ); ?>" class="ct-nav-link ct-nav-link--primary"><?php esc_html_e( '+ Nuevo evento', 'calibratrack' ); ?></a>
			<a href="<?php echo esc_url( home_url( '/tecnico/eventos/' ) ); ?>" class="ct-nav-link"><?php esc_html_e( 'Mis eventos', 'calibratrack' ); ?></a>
			<a href="<?php echo esc_url( home_url( '/tecnico/equipos/' ) ); ?>" class="ct-nav-link"><?php esc_html_e( 'Equipos', 'calibratrack' ); ?></a>
			<a href="<?php echo esc_url( home_url( '/tecnico/perfil/' ) ); ?>" class="ct-nav-link"><?php esc_html_e( 'Mi perfil', 'calibratrack' ); ?></a>
			<a href="<?php echo esc_url( home_url( '/tecnico/salir/' ) ); ?>" class="ct-nav-link ct-nav-link--salir"><?php esc_html_e( 'Salir', 'calibratrack' ); ?></a>
		</nav>
		<?php endif; ?>
	</div>
</header>
<main class="ct-panel-main">
