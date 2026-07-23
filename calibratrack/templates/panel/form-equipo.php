<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

$page_title = $es_nuevo
	? __( 'Nuevo Equipo', 'calibratrack' )
	: __( 'Editar Equipo', 'calibratrack' );

$action_url = $es_nuevo
	? home_url( '/panel/equipo/nuevo/' )
	: home_url( '/panel/equipo/' . $equipo_id . '/' );

include __DIR__ . '/_partials/header.php';
?>
<div class="ct-container" style="max-width:680px;">
	<div class="ct-page-header" style="display:flex;align-items:center;gap:12px;flex-wrap:wrap;">
		<a href="<?php echo esc_url( home_url( '/panel/equipos/' ) ); ?>" class="ct-btn ct-btn--sm ct-btn--secondary">← <?php esc_html_e( 'Volver', 'calibratrack' ); ?></a>
		<h1 class="ct-page-title" style="margin:0;"><?php echo esc_html( $page_title ); ?></h1>
	</div>

	<?php if ( ! empty( $errors['general'] ) ) : ?>
		<div style="background:#fee2e2;border:1px solid #fca5a5;border-radius:6px;padding:12px 16px;margin-bottom:20px;color:#991b1b;">
			<?php echo esc_html( $errors['general'] ); ?>
		</div>
	<?php endif; ?>

	<form method="post" action="<?php echo esc_url( $action_url ); ?>"
		style="background:#fff;border-radius:8px;padding:24px 28px;box-shadow:0 1px 4px rgba(0,0,0,.08);">
		<?php wp_nonce_field( 'calibratrack_equipo_form_' . $equipo_id, '_wpnonce' ); ?>

		<div style="margin-bottom:18px;">
			<label style="display:block;font-size:13px;font-weight:600;color:#374151;margin-bottom:6px;">
				<?php esc_html_e( 'Nombre del equipo *', 'calibratrack' ); ?>
			</label>
			<input type="text" name="nombre"
				value="<?php echo esc_attr( isset( $valores['nombre'] ) ? $valores['nombre'] : '' ); ?>"
				placeholder="<?php esc_attr_e( 'Ej: OTDR Grandway GS-401', 'calibratrack' ); ?>"
				class="ct-input" style="width:100%;" required>
			<?php if ( ! empty( $errors['nombre'] ) ) : ?>
				<p style="color:#dc2626;font-size:12px;margin:4px 0 0;"><?php echo esc_html( $errors['nombre'] ); ?></p>
			<?php endif; ?>
		</div>

		<div style="margin-bottom:18px;">
			<label style="display:block;font-size:13px;font-weight:600;color:#374151;margin-bottom:6px;">
				<?php esc_html_e( 'Número de serie *', 'calibratrack' ); ?>
			</label>
			<input type="text" name="serie"
				value="<?php echo esc_attr( isset( $valores['serie'] ) ? $valores['serie'] : '' ); ?>"
				class="ct-input" style="width:100%;" <?php echo $es_nuevo ? '' : ''; ?> required>
			<?php if ( ! empty( $errors['serie'] ) ) : ?>
				<p style="color:#dc2626;font-size:12px;margin:4px 0 0;"><?php echo esc_html( $errors['serie'] ); ?></p>
			<?php endif; ?>
			<p style="color:#6b7280;font-size:12px;margin:4px 0 0;"><?php esc_html_e( 'Debe ser único en el sistema.', 'calibratrack' ); ?></p>
		</div>

		<div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:18px;">
			<div>
				<label style="display:block;font-size:13px;font-weight:600;color:#374151;margin-bottom:6px;">
					<?php esc_html_e( 'Marca', 'calibratrack' ); ?>
				</label>
				<input type="text" name="marca"
					value="<?php echo esc_attr( isset( $valores['marca'] ) ? $valores['marca'] : '' ); ?>"
					placeholder="<?php esc_attr_e( 'Ej: Grandway', 'calibratrack' ); ?>"
					class="ct-input" style="width:100%;">
			</div>
			<div>
				<label style="display:block;font-size:13px;font-weight:600;color:#374151;margin-bottom:6px;">
					<?php esc_html_e( 'Modelo', 'calibratrack' ); ?>
				</label>
				<input type="text" name="modelo"
					value="<?php echo esc_attr( isset( $valores['modelo'] ) ? $valores['modelo'] : '' ); ?>"
					placeholder="<?php esc_attr_e( 'Ej: GS-401', 'calibratrack' ); ?>"
					class="ct-input" style="width:100%;">
			</div>
		</div>

		<div style="margin-bottom:18px;">
			<label style="display:block;font-size:13px;font-weight:600;color:#374151;margin-bottom:6px;">
				<?php esc_html_e( 'Tipo de equipo *', 'calibratrack' ); ?>
			</label>
			<select name="tipo_equipo" class="ct-input" style="width:100%;" required>
				<option value=""><?php esc_html_e( '— Seleccionar —', 'calibratrack' ); ?></option>
				<?php foreach ( $tipos_equipo as $slug => $etiqueta ) : ?>
					<option value="<?php echo esc_attr( $slug ); ?>"
						<?php selected( isset( $valores['tipo_equipo'] ) ? $valores['tipo_equipo'] : '', $slug ); ?>>
						<?php echo esc_html( $etiqueta ); ?>
					</option>
				<?php endforeach; ?>
			</select>
			<?php if ( ! empty( $errors['tipo_equipo'] ) ) : ?>
				<p style="color:#dc2626;font-size:12px;margin:4px 0 0;"><?php echo esc_html( $errors['tipo_equipo'] ); ?></p>
			<?php endif; ?>
		</div>

		<div style="margin-bottom:18px;">
			<label style="display:block;font-size:13px;font-weight:600;color:#374151;margin-bottom:6px;">
				<?php esc_html_e( 'Cliente propietario', 'calibratrack' ); ?>
			</label>
			<select name="cliente_propietario" class="ct-input" style="width:100%;">
				<option value="0"><?php esc_html_e( '— Sin cliente asignado —', 'calibratrack' ); ?></option>
				<?php foreach ( $clientes_lista as $cid => $cnombre ) : ?>
					<option value="<?php echo esc_attr( $cid ); ?>"
						<?php selected( isset( $valores['cliente_propietario'] ) ? (int) $valores['cliente_propietario'] : 0, (int) $cid ); ?>>
						<?php echo esc_html( $cnombre ); ?>
					</option>
				<?php endforeach; ?>
			</select>
		</div>

		<div style="margin-bottom:24px;">
			<label style="display:block;font-size:13px;font-weight:600;color:#374151;margin-bottom:6px;">
				<?php esc_html_e( 'Fecha de ingreso al sistema', 'calibratrack' ); ?>
			</label>
			<input type="date" name="fecha_ingreso"
				value="<?php echo esc_attr( isset( $valores['fecha_ingreso'] ) ? $valores['fecha_ingreso'] : '' ); ?>"
				class="ct-input" style="width:200px;">
		</div>

		<div style="display:flex;gap:12px;">
			<button type="submit" class="ct-btn ct-btn--primary">
				<?php echo $es_nuevo ? esc_html__( 'Crear equipo', 'calibratrack' ) : esc_html__( 'Guardar cambios', 'calibratrack' ); ?>
			</button>
			<a href="<?php echo esc_url( home_url( '/panel/equipos/' ) ); ?>" class="ct-btn ct-btn--secondary">
				<?php esc_html_e( 'Cancelar', 'calibratrack' ); ?>
			</a>
		</div>
	</form>
</div>
<?php include __DIR__ . '/_partials/footer.php'; ?>
