<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
$page_title = __( 'Registrar nuevo evento', 'calibratrack' );
$valores    = isset( $valores ) ? $valores : array();
$errors     = isset( $errors ) ? $errors : array();
$equipos    = isset( $equipos ) ? $equipos : array();
include __DIR__ . '/_partials/header.php';
?>

<div class="ct-container">
	<div class="ct-page-header">
		<h1 class="ct-page-title"><?php esc_html_e( 'Registrar nuevo evento', 'calibratrack' ); ?></h1>
		<a href="<?php echo esc_url( home_url( '/tecnico/' ) ); ?>" class="ct-link">← <?php esc_html_e( 'Volver', 'calibratrack' ); ?></a>
	</div>

	<form method="post" action="" enctype="multipart/form-data" class="ct-form" novalidate>
		<?php include __DIR__ . '/_partials/form-evento-fields.php'; ?>
		<div class="ct-form-actions">
			<button type="submit" class="ct-btn ct-btn--primary ct-btn--large">
				<?php esc_html_e( 'Guardar evento', 'calibratrack' ); ?>
			</button>
		</div>
	</form>
</div>

<?php include __DIR__ . '/_partials/footer.php'; ?>
