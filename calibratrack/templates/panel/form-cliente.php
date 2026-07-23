<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

$page_title = $es_nuevo
	? __( 'Nuevo Cliente', 'calibratrack' )
	: __( 'Editar Cliente', 'calibratrack' );

$action_url = $es_nuevo
	? home_url( '/panel/cliente/nuevo/' )
	: home_url( '/panel/cliente/' . $cliente_id . '/' );

include __DIR__ . '/_partials/header.php';
?>
<div class="ct-container" style="max-width:680px;">
	<div class="ct-page-header" style="display:flex;align-items:center;gap:12px;flex-wrap:wrap;">
		<a href="<?php echo esc_url( home_url( '/panel/clientes/' ) ); ?>" class="ct-btn ct-btn--sm ct-btn--secondary">← <?php esc_html_e( 'Volver', 'calibratrack' ); ?></a>
		<h1 class="ct-page-title" style="margin:0;"><?php echo esc_html( $page_title ); ?></h1>
	</div>

	<?php if ( ! empty( $errors['general'] ) ) : ?>
		<div style="background:#fee2e2;border:1px solid #fca5a5;border-radius:6px;padding:12px 16px;margin-bottom:20px;color:#991b1b;">
			<?php echo esc_html( $errors['general'] ); ?>
		</div>
	<?php endif; ?>

	<form method="post" action="<?php echo esc_url( $action_url ); ?>"
		style="background:#fff;border-radius:8px;padding:24px 28px;box-shadow:0 1px 4px rgba(0,0,0,.08);">
		<?php wp_nonce_field( 'calibratrack_cliente_form_' . $cliente_id, '_wpnonce' ); ?>

		<div style="margin-bottom:18px;">
			<label style="display:block;font-size:13px;font-weight:600;color:#374151;margin-bottom:6px;">
				<?php esc_html_e( 'Nombre de la empresa *', 'calibratrack' ); ?>
			</label>
			<input type="text" name="nombre_empresa"
				value="<?php echo esc_attr( isset( $valores['nombre_empresa'] ) ? $valores['nombre_empresa'] : '' ); ?>"
				class="ct-input" style="width:100%;" required>
			<?php if ( ! empty( $errors['nombre_empresa'] ) ) : ?>
				<p style="color:#dc2626;font-size:12px;margin:4px 0 0;"><?php echo esc_html( $errors['nombre_empresa'] ); ?></p>
			<?php endif; ?>
		</div>

		<div style="margin-bottom:18px;">
			<label style="display:block;font-size:13px;font-weight:600;color:#374151;margin-bottom:6px;">
				<?php esc_html_e( 'RUT', 'calibratrack' ); ?>
			</label>
			<input type="text" name="rut"
				value="<?php echo esc_attr( isset( $valores['rut'] ) ? $valores['rut'] : '' ); ?>"
				placeholder="Ej: 76.123.456-7"
				class="ct-input" style="width:100%;">
		</div>

		<div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:18px;">
			<div>
				<label style="display:block;font-size:13px;font-weight:600;color:#374151;margin-bottom:6px;">
					<?php esc_html_e( 'Nombre de contacto', 'calibratrack' ); ?>
				</label>
				<input type="text" name="contacto_nombre"
					value="<?php echo esc_attr( isset( $valores['contacto_nombre'] ) ? $valores['contacto_nombre'] : '' ); ?>"
					class="ct-input" style="width:100%;">
			</div>
			<div>
				<label style="display:block;font-size:13px;font-weight:600;color:#374151;margin-bottom:6px;">
					<?php esc_html_e( 'Teléfono', 'calibratrack' ); ?>
				</label>
				<input type="text" name="telefono"
					value="<?php echo esc_attr( isset( $valores['telefono'] ) ? $valores['telefono'] : '' ); ?>"
					placeholder="+56 9 1234 5678"
					class="ct-input" style="width:100%;">
			</div>
		</div>

		<div style="margin-bottom:18px;">
			<label style="display:block;font-size:13px;font-weight:600;color:#374151;margin-bottom:6px;">
				<?php esc_html_e( 'Correo electrónico', 'calibratrack' ); ?>
			</label>
			<input type="email" name="correo"
				value="<?php echo esc_attr( isset( $valores['correo'] ) ? $valores['correo'] : '' ); ?>"
				class="ct-input" style="width:100%;">
			<?php if ( ! empty( $errors['correo'] ) ) : ?>
				<p style="color:#dc2626;font-size:12px;margin:4px 0 0;"><?php echo esc_html( $errors['correo'] ); ?></p>
			<?php endif; ?>
		</div>

		<div style="margin-bottom:24px;">
			<label style="display:block;font-size:13px;font-weight:600;color:#374151;margin-bottom:6px;">
				<?php esc_html_e( 'Dirección', 'calibratrack' ); ?>
			</label>
			<input type="text" name="direccion"
				value="<?php echo esc_attr( isset( $valores['direccion'] ) ? $valores['direccion'] : '' ); ?>"
				class="ct-input" style="width:100%;">
		</div>

		<div style="display:flex;gap:12px;">
			<button type="submit" class="ct-btn ct-btn--primary">
				<?php echo $es_nuevo ? esc_html__( 'Crear cliente', 'calibratrack' ) : esc_html__( 'Guardar cambios', 'calibratrack' ); ?>
			</button>
			<a href="<?php echo esc_url( home_url( '/panel/clientes/' ) ); ?>" class="ct-btn ct-btn--secondary">
				<?php esc_html_e( 'Cancelar', 'calibratrack' ); ?>
			</a>
		</div>
	</form>
</div>
<?php include __DIR__ . '/_partials/footer.php'; ?>
