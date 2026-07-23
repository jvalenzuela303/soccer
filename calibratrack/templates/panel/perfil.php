<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
$page_title   = __( 'Mi perfil', 'calibratrack' );
$user         = wp_get_current_user();
$cargo_actual = get_user_meta( $user->ID, 'calibratrack_cargo', true );
include __DIR__ . '/_partials/header.php';
?>

<div class="ct-container">
	<div class="ct-page-header">
		<h1 class="ct-page-title"><?php esc_html_e( 'Mi perfil', 'calibratrack' ); ?></h1>
	</div>

	<?php if ( ! empty( $success ) ) : ?>
		<div class="ct-alert ct-alert--success" role="alert"><?php echo esc_html( $success ); ?></div>
	<?php endif; ?>
	<?php if ( ! empty( $error ) ) : ?>
		<div class="ct-alert ct-alert--error" role="alert"><?php echo esc_html( $error ); ?></div>
	<?php endif; ?>

	<div class="ct-section">
		<form method="post" class="ct-form">
			<?php wp_nonce_field( 'calibratrack_tecnico_perfil' ); ?>

			<!-- Información de la cuenta (solo lectura) -->
			<div class="ct-field-group">
				<div class="ct-field">
					<label class="ct-label"><?php esc_html_e( 'Nombre', 'calibratrack' ); ?></label>
					<input type="text" class="ct-input" value="<?php echo esc_attr( $user->display_name ); ?>" disabled>
				</div>
				<div class="ct-field">
					<label class="ct-label"><?php esc_html_e( 'Correo', 'calibratrack' ); ?></label>
					<input type="email" class="ct-input" value="<?php echo esc_attr( $user->user_email ); ?>" disabled>
				</div>
			</div>

			<!-- Cargo -->
			<div class="ct-field">
				<label for="ct-cargo" class="ct-label"><?php esc_html_e( 'Cargo / título profesional', 'calibratrack' ); ?></label>
				<input type="text" id="ct-cargo" name="calibratrack_cargo" class="ct-input"
					value="<?php echo esc_attr( $cargo_actual ); ?>"
					placeholder="<?php esc_attr_e( 'Ej. Técnico en fibra óptica', 'calibratrack' ); ?>">
				<span class="ct-field-help"><?php esc_html_e( 'Aparece debajo del nombre en los certificados PDF.', 'calibratrack' ); ?></span>
			</div>

			<div class="ct-form-actions">
				<button type="submit" name="ct_perfil_submit" value="1" class="ct-btn ct-btn--primary">
					<?php esc_html_e( 'Guardar cambios', 'calibratrack' ); ?>
				</button>
			</div>
		</form>
	</div>
</div>

<?php include __DIR__ . '/_partials/footer.php'; ?>
