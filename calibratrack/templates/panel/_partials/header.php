<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?php echo isset( $page_title ) ? esc_html( $page_title ) . ' — TrueTech' : 'Panel — TrueTech'; ?></title>
<link rel="stylesheet" href="<?php echo esc_url( CALIBRATRACK_PLUGIN_URL . 'assets/css/tecnico.css?v=' . CALIBRATRACK_VERSION ); ?>">
</head>
<body class="ct-tecnico-body">
<header class="ct-panel-header">
	<div class="ct-panel-header-inner">
		<div class="ct-panel-brand">
			<span class="ct-panel-logo-text">TrueTech</span>
			<span class="ct-panel-subtitle">
				<?php echo current_user_can( 'manage_options' ) ? esc_html__( 'Panel de Gestión', 'calibratrack' ) : esc_html__( 'Panel Técnico', 'calibratrack' ); ?>
			</span>
		</div>
		<?php if ( is_user_logged_in() ) : ?>
		<nav class="ct-panel-nav">
			<a href="<?php echo esc_url( home_url( '/panel/' ) ); ?>" class="ct-nav-link"><?php esc_html_e( 'Inicio', 'calibratrack' ); ?></a>

			<?php if ( current_user_can( 'manage_options' ) ) : ?>
			<a href="<?php echo esc_url( home_url( '/panel/nueva-oi/' ) ); ?>" class="ct-nav-link ct-nav-link--primary" style="background:#2563eb;border-color:#2563eb;"><?php esc_html_e( '+ Nueva OI', 'calibratrack' ); ?></a>
			<a href="<?php echo esc_url( home_url( '/panel/nueva-ot/' ) ); ?>" class="ct-nav-link ct-nav-link--primary" style="background:#16a34a;border-color:#16a34a;"><?php esc_html_e( '+ Nueva OT', 'calibratrack' ); ?></a>
			<a href="<?php echo esc_url( add_query_arg( 'filtro', 'oi', home_url( '/panel/' ) ) ); ?>" class="ct-nav-link"><?php esc_html_e( 'OI', 'calibratrack' ); ?></a>
			<a href="<?php echo esc_url( add_query_arg( 'filtro', 'ot', home_url( '/panel/' ) ) ); ?>" class="ct-nav-link"><?php esc_html_e( 'OT', 'calibratrack' ); ?></a>
			<?php endif; ?>

			<?php if ( current_user_can( 'manage_options' ) ) : ?>
			<a href="<?php echo esc_url( home_url( '/panel/tecnicos/' ) ); ?>" class="ct-nav-link"><?php esc_html_e( 'Técnicos', 'calibratrack' ); ?></a>
			<?php endif; ?>
			<a href="<?php echo esc_url( home_url( '/panel/salir/' ) ); ?>" class="ct-nav-link ct-nav-link--salir"><?php esc_html_e( 'Salir', 'calibratrack' ); ?></a>
		</nav>
		<?php endif; ?>
	</div>
</header>
<main class="ct-panel-main">
