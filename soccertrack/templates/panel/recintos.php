<?php defined( 'ABSPATH' ) || exit; ?>

<div class="st-page-header">
	<h1 class="st-page-title">🏟 <?php esc_html_e( 'Recintos y Canchas', 'soccertrack' ); ?></h1>
</div>

<?php if ( ( $notice ?? '' ) === 'created' ) : ?>
	<div class="st-alert st-alert--success">✅ <?php esc_html_e( 'Recinto creado correctamente.', 'soccertrack' ); ?></div>
<?php elseif ( ( $notice ?? '' ) === 'deleted' ) : ?>
	<div class="st-alert st-alert--success">✅ <?php esc_html_e( 'Recinto eliminado.', 'soccertrack' ); ?></div>
<?php endif; ?>

<?php if ( ! empty( $error ) ) : ?>
	<div class="st-alert st-alert--error">⚠️ <?php echo esc_html( $error ); ?></div>
<?php endif; ?>

<div class="st-card">
	<div class="st-card-header">
		<h2 class="st-card-title">➕ <?php esc_html_e( 'Nuevo recinto', 'soccertrack' ); ?></h2>
	</div>
	<form method="post" action="">
		<?php wp_nonce_field( 'st_create_venue' ); ?>
		<input type="hidden" name="st_create_venue" value="1">
		<div class="st-form-inline">
			<div class="st-field" style="flex:2;min-width:200px">
				<label class="st-label"><?php esc_html_e( 'Nombre *', 'soccertrack' ); ?></label>
				<input type="text" name="name" class="st-input" required maxlength="150" placeholder="<?php esc_attr_e( 'Ej: Complejo Deportivo Norte', 'soccertrack' ); ?>">
			</div>
			<div class="st-field" style="flex:2;min-width:180px">
				<label class="st-label"><?php esc_html_e( 'Dirección', 'soccertrack' ); ?></label>
				<input type="text" name="address" class="st-input" maxlength="255" placeholder="<?php esc_attr_e( 'Opcional', 'soccertrack' ); ?>">
			</div>
			<div class="st-field" style="max-width:120px">
				<label class="st-label"><?php esc_html_e( 'N° Canchas *', 'soccertrack' ); ?></label>
				<input type="number" name="total_courts" class="st-input" min="1" max="20" value="4" required>
			</div>
			<div class="st-field" style="justify-content:flex-end">
				<button type="submit" class="st-btn st-btn--primary">🏟 <?php esc_html_e( 'Crear', 'soccertrack' ); ?></button>
			</div>
		</div>
		<small class="st-hint"><?php esc_html_e( 'Las canchas se crean automáticamente como "Cancha 1", "Cancha 2", etc.', 'soccertrack' ); ?></small>
	</form>
</div>

<div class="st-card">
	<div class="st-card-header">
		<h2 class="st-card-title"><?php esc_html_e( 'Recintos registrados', 'soccertrack' ); ?></h2>
	</div>
	<?php if ( empty( $venues ) ) : ?>
		<p class="st-empty-msg"><?php esc_html_e( 'No hay recintos registrados. Crea el primero.', 'soccertrack' ); ?></p>
	<?php else : ?>
	<div class="st-table-wrap">
		<table class="st-table">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Recinto', 'soccertrack' ); ?></th>
					<th><?php esc_html_e( 'Dirección', 'soccertrack' ); ?></th>
					<th style="text-align:center"><?php esc_html_e( 'Canchas', 'soccertrack' ); ?></th>
					<th style="text-align:center"><?php esc_html_e( 'Partidos', 'soccertrack' ); ?></th>
					<th></th>
				</tr>
			</thead>
			<tbody>
			<?php foreach ( $venues as $v ) : ?>
				<tr>
					<td><strong><?php echo esc_html( $v['name'] ); ?></strong></td>
					<td><?php echo esc_html( $v['address'] ?? '—' ); ?></td>
					<td style="text-align:center"><?php echo esc_html( (string) $v['court_count'] ); ?></td>
					<td style="text-align:center"><?php echo esc_html( (string) $v['match_count'] ); ?></td>
					<td style="display:flex;gap:6px">
						<a href="<?php echo esc_url( home_url( '/panel/recinto/' . $v['id'] . '/' ) ); ?>" class="st-btn st-btn--sm st-btn--secondary">
							✏️ <?php esc_html_e( 'Canchas', 'soccertrack' ); ?>
						</a>
						<?php if ( (int) $v['match_count'] === 0 ) : ?>
						<form method="post" action="" onsubmit="return confirm('<?php esc_attr_e( '¿Eliminar este recinto?', 'soccertrack' ); ?>')">
							<?php wp_nonce_field( 'st_delete_venue' ); ?>
							<input type="hidden" name="st_delete_venue" value="1">
							<input type="hidden" name="venue_id" value="<?php echo esc_attr( (string) $v['id'] ); ?>">
							<button type="submit" class="st-btn st-btn--sm st-btn--danger">🗑</button>
						</form>
						<?php endif; ?>
					</td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
	</div>
	<?php endif; ?>
</div>
