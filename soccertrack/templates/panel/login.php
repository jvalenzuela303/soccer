<?php defined( 'ABSPATH' ) || exit; ?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?php esc_html_e( 'Iniciar sesión — SoccerTrack', 'soccertrack' ); ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="preload" as="style" href="https://fonts.googleapis.com/css2?family=Abril+Fatface&family=Poppins:wght@400;600;700&display=swap" onload="this.onload=null;this.rel='stylesheet'">
<noscript><link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Abril+Fatface&family=Poppins:wght@400;600;700&display=swap"></noscript>
<link rel="stylesheet" href="<?php echo esc_url( SOCCERTRACK_URL . 'assets/css/panel.css?v=' . filemtime( SOCCERTRACK_DIR . 'assets/css/panel.css' ) ); ?>">
</head>
<body class="st-panel-body st-login-body">

<div class="st-login-wrap">
	<div class="st-login-box">

		<div class="st-login-brand">
			<span class="st-login-icon">⚽</span>
			<h1 class="st-login-title">SoccerTrack</h1>
			<p class="st-login-subtitle"><?php esc_html_e( 'Panel de gestión de torneos', 'soccertrack' ); ?></p>
		</div>

		<?php if ( ! empty( $error ) ) : ?>
			<div class="st-alert st-alert--error" role="alert">
				<?php echo esc_html( $error ); ?>
			</div>
		<?php endif; ?>

		<form method="post" action="<?php echo esc_url( home_url( '/panel/login/' ) ); ?>" class="st-login-form" novalidate>
			<?php wp_nonce_field( 'st_login_action' ); ?>
			<input type="hidden" name="st_login" value="1">
			<input type="hidden" name="redirect_to" value="<?php echo esc_attr( $redirect_to ); ?>">

			<div class="st-field">
				<label for="st-username" class="st-label">
					<?php esc_html_e( 'Usuario', 'soccertrack' ); ?>
				</label>
				<input
					type="text"
					id="st-username"
					name="username"
					class="st-input"
					autocomplete="username"
					required
					autofocus
				>
			</div>

			<div class="st-field">
				<label for="st-password" class="st-label">
					<?php esc_html_e( 'Contraseña', 'soccertrack' ); ?>
				</label>
				<input
					type="password"
					id="st-password"
					name="password"
					class="st-input"
					autocomplete="current-password"
					required
				>
			</div>

			<div class="st-field st-field--checkbox">
				<label class="st-checkbox-label">
					<input type="checkbox" name="remember" value="1">
					<?php esc_html_e( 'Recordarme', 'soccertrack' ); ?>
				</label>
			</div>

			<button type="submit" class="st-btn st-btn--primary st-btn--full">
				<?php esc_html_e( 'Entrar al panel', 'soccertrack' ); ?>
			</button>
		</form>
	</div>
</div>

</body>
</html>
