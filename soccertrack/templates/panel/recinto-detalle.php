<?php defined( 'ABSPATH' ) || exit; ?>

<div class="st-page-header">
	<a href="<?php echo esc_url( home_url( '/panel/recintos/' ) ); ?>" class="st-back-link">← <?php esc_html_e( 'Recintos', 'soccertrack' ); ?></a>
	<h1 class="st-page-title">🏟 <?php echo esc_html( $venue['name'] ); ?></h1>
	<?php if ( $venue['address'] ) : ?>
		<span style="color:#666;font-size:.9rem"><?php echo esc_html( $venue['address'] ); ?></span>
	<?php endif; ?>
</div>

<?php if ( ( $notice ?? '' ) === 'court_added' ) : ?>
	<div class="st-alert st-alert--success">✅ <?php esc_html_e( 'Cancha agregada.', 'soccertrack' ); ?></div>
<?php elseif ( ( $notice ?? '' ) === 'court_deleted' ) : ?>
	<div class="st-alert st-alert--success">✅ <?php esc_html_e( 'Cancha eliminada.', 'soccertrack' ); ?></div>
<?php endif; ?>

<?php if ( ! empty( $error ) ) : ?>
	<div class="st-alert st-alert--error">⚠️ <?php echo esc_html( $error ); ?></div>
<?php endif; ?>

<div class="st-card">
	<div class="st-card-header">
		<h2 class="st-card-title">➕ <?php esc_html_e( 'Agregar cancha', 'soccertrack' ); ?></h2>
	</div>
	<form method="post" action="">
		<?php wp_nonce_field( 'st_add_court_' . $venue['id'] ); ?>
		<input type="hidden" name="st_add_court" value="1">
		<div class="st-form-inline">
			<div class="st-field" style="flex:1;min-width:180px">
				<label class="st-label"><?php esc_html_e( 'Nombre de la cancha *', 'soccertrack' ); ?></label>
				<input type="text" name="court_name" class="st-input" required maxlength="50" placeholder="<?php esc_attr_e( 'Ej: Cancha Principal', 'soccertrack' ); ?>">
			</div>
			<div class="st-field" style="justify-content:flex-end">
				<button type="submit" class="st-btn st-btn--primary">➕ <?php esc_html_e( 'Agregar', 'soccertrack' ); ?></button>
			</div>
		</div>
	</form>
</div>

<div class="st-card">
	<div class="st-card-header">
		<h2 class="st-card-title"><?php esc_html_e( 'Canchas', 'soccertrack' ); ?></h2>
	</div>
	<?php if ( empty( $courts ) ) : ?>
		<p class="st-empty-msg"><?php esc_html_e( 'Sin canchas registradas.', 'soccertrack' ); ?></p>
	<?php else : ?>
	<div class="st-table-wrap">
		<table class="st-table">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Cancha', 'soccertrack' ); ?></th>
					<th style="text-align:center"><?php esc_html_e( 'Partidos asignados', 'soccertrack' ); ?></th>
					<th></th>
				</tr>
			</thead>
			<tbody>
			<?php foreach ( $courts as $c ) : ?>
				<tr>
					<td><?php echo esc_html( $c['court_name'] ); ?></td>
					<td style="text-align:center"><?php echo esc_html( (string) $c['match_count'] ); ?></td>
					<td>
						<?php if ( (int) $c['match_count'] === 0 ) : ?>
						<form method="post" action="" onsubmit="return confirm('<?php esc_attr_e( '¿Eliminar esta cancha?', 'soccertrack' ); ?>')">
							<?php wp_nonce_field( 'st_delete_court_' . $venue['id'] ); ?>
							<input type="hidden" name="st_delete_court" value="1">
							<input type="hidden" name="court_id" value="<?php echo esc_attr( (string) $c['id'] ); ?>">
							<button type="submit" class="st-btn st-btn--sm st-btn--danger">🗑</button>
						</form>
						<?php else : ?>
						<span style="color:#aaa;font-size:.8rem"><?php esc_html_e( 'En uso', 'soccertrack' ); ?></span>
						<?php endif; ?>
					</td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
	</div>
	<?php endif; ?>
</div>
