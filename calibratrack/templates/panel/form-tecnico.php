<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

$page_title = $es_nuevo
	? __( 'Nuevo Técnico', 'calibratrack' )
	: __( 'Editar Técnico', 'calibratrack' );

$action_url = $es_nuevo
	? home_url( '/panel/tecnico/nuevo/' )
	: home_url( '/panel/tecnico/' . $tecnico_id . '/' );

include __DIR__ . '/_partials/header.php';
?>
<div class="ct-container" style="max-width:680px;">
	<div class="ct-page-header" style="display:flex;align-items:center;gap:12px;flex-wrap:wrap;">
		<a href="<?php echo esc_url( home_url( '/panel/tecnicos/' ) ); ?>" class="ct-btn ct-btn--sm ct-btn--secondary">← <?php esc_html_e( 'Volver', 'calibratrack' ); ?></a>
		<h1 class="ct-page-title" style="margin:0;"><?php echo esc_html( $page_title ); ?></h1>
	</div>

	<?php if ( ! empty( $errors['general'] ) ) : ?>
		<div style="background:#fee2e2;border:1px solid #fca5a5;border-radius:6px;padding:12px 16px;margin-bottom:20px;color:#991b1b;">
			<?php echo esc_html( $errors['general'] ); ?>
		</div>
	<?php endif; ?>

	<form method="post" action="<?php echo esc_url( $action_url ); ?>" enctype="multipart/form-data"
		style="background:#fff;border-radius:8px;padding:24px 28px;box-shadow:0 1px 4px rgba(0,0,0,.08);">
		<?php wp_nonce_field( 'calibratrack_tecnico_form_' . $tecnico_id, '_wpnonce' ); ?>

		<?php if ( $es_nuevo ) : ?>
		<div style="margin-bottom:18px;">
			<label style="display:block;font-size:13px;font-weight:600;color:#374151;margin-bottom:6px;">
				<?php esc_html_e( 'Nombre de usuario *', 'calibratrack' ); ?>
			</label>
			<input type="text" name="user_login"
				value="<?php echo esc_attr( isset( $valores['user_login'] ) ? $valores['user_login'] : '' ); ?>"
				class="ct-input" style="width:100%;" autocomplete="off" required>
			<?php if ( ! empty( $errors['user_login'] ) ) : ?>
				<p style="color:#dc2626;font-size:12px;margin:4px 0 0;"><?php echo esc_html( $errors['user_login'] ); ?></p>
			<?php endif; ?>
		</div>
		<?php else : ?>
		<div style="margin-bottom:18px;">
			<label style="display:block;font-size:13px;font-weight:600;color:#374151;margin-bottom:6px;">
				<?php esc_html_e( 'Nombre de usuario', 'calibratrack' ); ?>
			</label>
			<input type="text" value="<?php echo esc_attr( isset( $valores['user_login'] ) ? $valores['user_login'] : '' ); ?>"
				disabled class="ct-input" style="width:100%;background:#f9fafb;color:#6b7280;">
			<p style="color:#6b7280;font-size:12px;margin:4px 0 0;"><?php esc_html_e( 'El nombre de usuario no puede cambiarse.', 'calibratrack' ); ?></p>
		</div>
		<?php endif; ?>

		<div style="margin-bottom:18px;">
			<label style="display:block;font-size:13px;font-weight:600;color:#374151;margin-bottom:6px;">
				<?php esc_html_e( 'Nombre completo *', 'calibratrack' ); ?>
			</label>
			<input type="text" name="display_name"
				value="<?php echo esc_attr( isset( $valores['display_name'] ) ? $valores['display_name'] : '' ); ?>"
				class="ct-input" style="width:100%;" required>
			<?php if ( ! empty( $errors['display_name'] ) ) : ?>
				<p style="color:#dc2626;font-size:12px;margin:4px 0 0;"><?php echo esc_html( $errors['display_name'] ); ?></p>
			<?php endif; ?>
		</div>

		<div style="margin-bottom:18px;">
			<label style="display:block;font-size:13px;font-weight:600;color:#374151;margin-bottom:6px;">
				<?php esc_html_e( 'Correo electrónico *', 'calibratrack' ); ?>
			</label>
			<input type="email" name="user_email"
				value="<?php echo esc_attr( isset( $valores['user_email'] ) ? $valores['user_email'] : '' ); ?>"
				class="ct-input" style="width:100%;" required>
			<?php if ( ! empty( $errors['user_email'] ) ) : ?>
				<p style="color:#dc2626;font-size:12px;margin:4px 0 0;"><?php echo esc_html( $errors['user_email'] ); ?></p>
			<?php endif; ?>
		</div>

		<div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:18px;">
			<div>
				<label style="display:block;font-size:13px;font-weight:600;color:#374151;margin-bottom:6px;">
					<?php echo $es_nuevo ? esc_html__( 'Contraseña *', 'calibratrack' ) : esc_html__( 'Nueva contraseña', 'calibratrack' ); ?>
				</label>
				<input type="password" name="password" class="ct-input" style="width:100%;"
					<?php echo $es_nuevo ? 'required' : ''; ?> autocomplete="new-password">
				<?php if ( ! empty( $errors['password'] ) ) : ?>
					<p style="color:#dc2626;font-size:12px;margin:4px 0 0;"><?php echo esc_html( $errors['password'] ); ?></p>
				<?php endif; ?>
				<?php if ( ! $es_nuevo ) : ?>
					<p style="color:#6b7280;font-size:12px;margin:4px 0 0;"><?php esc_html_e( 'Dejar vacío para no cambiar.', 'calibratrack' ); ?></p>
				<?php endif; ?>
			</div>
			<div>
				<label style="display:block;font-size:13px;font-weight:600;color:#374151;margin-bottom:6px;">
					<?php echo $es_nuevo ? esc_html__( 'Confirmar contraseña *', 'calibratrack' ) : esc_html__( 'Confirmar nueva contraseña', 'calibratrack' ); ?>
				</label>
				<input type="password" name="password2" class="ct-input" style="width:100%;"
					<?php echo $es_nuevo ? 'required' : ''; ?> autocomplete="new-password">
				<?php if ( ! empty( $errors['password2'] ) ) : ?>
					<p style="color:#dc2626;font-size:12px;margin:4px 0 0;"><?php echo esc_html( $errors['password2'] ); ?></p>
				<?php endif; ?>
			</div>
		</div>

		<div style="margin-bottom:18px;">
			<label style="display:block;font-size:13px;font-weight:600;color:#374151;margin-bottom:6px;">
				<?php esc_html_e( 'Cargo / título profesional', 'calibratrack' ); ?>
			</label>
			<input type="text" name="calibratrack_cargo"
				value="<?php echo esc_attr( isset( $valores['calibratrack_cargo'] ) ? $valores['calibratrack_cargo'] : '' ); ?>"
				placeholder="<?php esc_attr_e( 'Ej: Técnico en Telecomunicaciones', 'calibratrack' ); ?>"
				class="ct-input" style="width:100%;">
			<p style="color:#6b7280;font-size:12px;margin:4px 0 0;"><?php esc_html_e( 'Aparece en los certificados PDF.', 'calibratrack' ); ?></p>
		</div>

		<div style="margin-bottom:24px;">
			<label style="display:block;font-size:13px;font-weight:600;color:#374151;margin-bottom:6px;">
				<?php esc_html_e( 'Firma digital', 'calibratrack' ); ?>
			</label>
			<?php if ( ! empty( $firma_url ) ) : ?>
				<div style="margin-bottom:8px;">
					<img src="<?php echo esc_url( $firma_url ); ?>" alt="<?php esc_attr_e( 'Firma actual', 'calibratrack' ); ?>"
						style="max-height:70px;border:1px solid #e5e7eb;border-radius:4px;padding:4px;background:#fff;display:block;">
					<p style="color:#6b7280;font-size:12px;margin:4px 0 0;"><?php esc_html_e( 'Firma actual. Sube una nueva para reemplazarla.', 'calibratrack' ); ?></p>
				</div>
			<?php endif; ?>
			<input type="file" name="calibratrack_firma_upload" accept="image/jpeg,image/png,image/webp"
				style="font-size:13px;">
			<p style="color:#6b7280;font-size:12px;margin:4px 0 0;"><?php esc_html_e( 'PNG con fondo transparente recomendado. Máx. 1 MB.', 'calibratrack' ); ?></p>
		</div>

		<div style="display:flex;gap:12px;">
			<button type="submit" class="ct-btn ct-btn--primary">
				<?php echo $es_nuevo ? esc_html__( 'Crear técnico', 'calibratrack' ) : esc_html__( 'Guardar cambios', 'calibratrack' ); ?>
			</button>
			<a href="<?php echo esc_url( home_url( '/panel/tecnicos/' ) ); ?>" class="ct-btn ct-btn--secondary">
				<?php esc_html_e( 'Cancelar', 'calibratrack' ); ?>
			</a>
		</div>
	</form>
</div>
<?php include __DIR__ . '/_partials/footer.php'; ?>
