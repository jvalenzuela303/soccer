<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
$error       = isset( $error ) ? (string) $error : '';
$redirect_to = isset( $redirect_to ) ? (string) $redirect_to : '';
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?php esc_html_e( 'Iniciar sesión — TrueTech', 'calibratrack' ); ?></title>
<link rel="stylesheet" href="<?php echo esc_url( CALIBRATRACK_PLUGIN_URL . 'assets/css/tecnico.css?v=' . CALIBRATRACK_VERSION ); ?>">
</head>
<body class="ct-tecnico-body ct-login-body">

<div class="ct-login-wrap">
	<div class="ct-login-box">
		<div class="ct-login-brand">
			<span class="ct-login-logo-text">TrueTech</span>
			<p class="ct-login-subtitle"><?php esc_html_e( 'Panel del técnico', 'calibratrack' ); ?></p>
		</div>

		<?php if ( ! empty( $error ) ) : ?>
			<div class="ct-alert ct-alert--error" role="alert">
				<?php echo esc_html( $error ); ?>
			</div>
		<?php endif; ?>

		<form method="post" action="" class="ct-login-form" novalidate>
			<?php wp_nonce_field( 'calibratrack_tecnico_login' ); ?>
			<input type="hidden" name="redirect_to" value="<?php echo esc_attr( $redirect_to ); ?>">

			<div class="ct-field">
				<label for="ct-username" class="ct-label">
					<?php esc_html_e( 'Usuario', 'calibratrack' ); ?>
				</label>
				<input
					type="text"
					id="ct-username"
					name="username"
					class="ct-input"
					autocomplete="username"
					required
					autofocus
				>
			</div>

			<div class="ct-field">
				<label for="ct-password" class="ct-label">
					<?php esc_html_e( 'Contraseña', 'calibratrack' ); ?>
				</label>
				<input
					type="password"
					id="ct-password"
					name="password"
					class="ct-input"
					autocomplete="current-password"
					required
				>
			</div>

			<div class="ct-field ct-field--checkbox">
				<label class="ct-checkbox-label">
					<input type="checkbox" name="remember" value="1">
					<?php esc_html_e( 'Recordarme', 'calibratrack' ); ?>
				</label>
			</div>

			<button type="submit" class="ct-btn ct-btn--primary ct-btn--full">
				<?php esc_html_e( 'Entrar', 'calibratrack' ); ?>
			</button>
		</form>
	</div>
</div>

</body>
</html>
