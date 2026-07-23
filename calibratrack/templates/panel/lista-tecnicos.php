<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
$page_title = __( 'Técnicos', 'calibratrack' );

$success_msg = '';
if ( isset( $success ) ) {
	if ( 1 === (int) $success ) {
		$success_msg = __( 'Técnico creado correctamente.', 'calibratrack' );
	} elseif ( 2 === (int) $success ) {
		$success_msg = __( 'Técnico actualizado correctamente.', 'calibratrack' );
	}
}

include __DIR__ . '/_partials/header.php';
?>
<div class="ct-container">
	<div class="ct-page-header" style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px;">
		<h1 class="ct-page-title"><?php esc_html_e( 'Técnicos', 'calibratrack' ); ?></h1>
		<a href="<?php echo esc_url( home_url( '/panel/tecnico/nuevo/' ) ); ?>" class="ct-btn ct-btn--primary">
			<?php esc_html_e( '+ Nuevo Técnico', 'calibratrack' ); ?>
		</a>
	</div>

	<?php if ( $success_msg ) : ?>
		<div style="background:#d1fae5;border:1px solid #6ee7b7;border-radius:6px;padding:12px 16px;margin-bottom:20px;color:#065f46;font-weight:600;">
			<?php echo esc_html( $success_msg ); ?>
		</div>
	<?php endif; ?>

	<?php if ( isset( $deleted ) && $deleted ) : ?>
		<div style="background:#fef9c3;border:1px solid #fde047;border-radius:6px;padding:12px 16px;margin-bottom:20px;color:#713f12;font-weight:600;">
			<?php esc_html_e( 'Técnico eliminado correctamente.', 'calibratrack' ); ?>
		</div>
	<?php endif; ?>

	<?php if ( empty( $tecnicos ) ) : ?>
		<p class="ct-empty-msg"><?php esc_html_e( 'No hay técnicos registrados en el sistema.', 'calibratrack' ); ?></p>
	<?php else : ?>
	<div class="ct-table-wrap">
		<table class="ct-table">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Nombre', 'calibratrack' ); ?></th>
					<th><?php esc_html_e( 'Usuario', 'calibratrack' ); ?></th>
					<th><?php esc_html_e( 'Correo', 'calibratrack' ); ?></th>
					<th><?php esc_html_e( 'Cargo', 'calibratrack' ); ?></th>
					<th><?php esc_html_e( 'Firma', 'calibratrack' ); ?></th>
					<th></th>
				</tr>
			</thead>
			<tbody>
			<?php foreach ( $tecnicos as $tec ) :
				$cargo    = (string) get_user_meta( $tec->ID, 'calibratrack_cargo', true );
				$firma_id = (int) get_user_meta( $tec->ID, 'calibratrack_firma_id', true );
				$firma_url = $firma_id ? wp_get_attachment_image_url( $firma_id, 'thumbnail' ) : '';
			?>
			<tr>
				<td data-label="<?php esc_attr_e( 'Nombre', 'calibratrack' ); ?>">
					<strong><?php echo esc_html( $tec->display_name ); ?></strong>
				</td>
				<td data-label="<?php esc_attr_e( 'Usuario', 'calibratrack' ); ?>">
					<code><?php echo esc_html( $tec->user_login ); ?></code>
				</td>
				<td data-label="<?php esc_attr_e( 'Correo', 'calibratrack' ); ?>">
					<a href="mailto:<?php echo esc_attr( $tec->user_email ); ?>" style="color:inherit;"><?php echo esc_html( $tec->user_email ); ?></a>
				</td>
				<td data-label="<?php esc_attr_e( 'Cargo', 'calibratrack' ); ?>">
					<?php echo esc_html( $cargo ?: '—' ); ?>
				</td>
				<td data-label="<?php esc_attr_e( 'Firma', 'calibratrack' ); ?>">
					<?php if ( $firma_url ) : ?>
						<img src="<?php echo esc_url( $firma_url ); ?>" alt="<?php esc_attr_e( 'Firma', 'calibratrack' ); ?>"
							style="max-height:40px;border:1px solid #e5e7eb;border-radius:4px;padding:2px;background:#fff;">
					<?php else : ?>
						<span style="color:#9ca3af;font-size:12px;"><?php esc_html_e( 'Sin firma', 'calibratrack' ); ?></span>
					<?php endif; ?>
				</td>
				<td>
					<?php if ( in_array( 'administrator', (array) $tec->roles, true ) ) : ?>
					<a href="<?php echo esc_url( admin_url( 'user-edit.php?user_id=' . $tec->ID ) ); ?>" class="ct-btn ct-btn--sm">
						<?php esc_html_e( 'wp-admin', 'calibratrack' ); ?>
					</a>
					<?php else : ?>
					<a href="<?php echo esc_url( home_url( '/panel/tecnico/' . $tec->ID . '/' ) ); ?>" class="ct-btn ct-btn--sm">
						<?php esc_html_e( 'Editar', 'calibratrack' ); ?>
					</a>
					<?php endif; ?>
				</td>
			</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
	</div>
	<?php endif; ?>
</div>
<?php include __DIR__ . '/_partials/footer.php'; ?>
