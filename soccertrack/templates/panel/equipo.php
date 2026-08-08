<?php
/**
 * Nómina del equipo — alta individual y vista de jugadores inscritos.
 *
 * Variables disponibles:
 *   $team        array   Fila del equipo (id, name, tournament_id, tournament_name)
 *   $roster      array[] Jugadores inscritos
 *   $notice      string  'added' | 'removed' | ''
 *   $error       string  Mensaje de error o ''
 *   $page_title  string
 *   $tournaments array[] Para el link de importar masivo
 */
defined( 'ABSPATH' ) || exit;
?>

<div class="st-page-header">
	<a href="<?php echo esc_url( home_url( '/panel/torneo/' . $team['tournament_id'] . '/' ) ); ?>" class="st-back-link">
		← <?php echo esc_html( $team['tournament_name'] ); ?>
	</a>
	<h1 class="st-page-title">👥 <?php echo esc_html( $team['name'] ); ?></h1>
	<a href="<?php echo esc_url( home_url( '/panel/importar/?tournament_id=' . $team['tournament_id'] . '&team_id=' . $team['id'] ) ); ?>"
	   class="st-btn st-btn--secondary st-btn--sm">
		📥 <?php esc_html_e( 'Importar masivo (CSV/XLSX)', 'soccertrack' ); ?>
	</a>
</div>

<?php if ( $notice === 'added' ) : ?>
	<div class="st-alert st-alert--success" role="alert">
		✅ <?php esc_html_e( 'Jugador agregado correctamente a la nómina.', 'soccertrack' ); ?>
	</div>
<?php elseif ( $notice === 'added_exception' ) : ?>
	<div class="st-alert st-alert--warning" role="alert">
		⚠️ <?php esc_html_e( 'Jugador agregado como inscripción de excepción autorizada por coordinador.', 'soccertrack' ); ?>
	</div>
<?php elseif ( $notice === 'removed' ) : ?>
	<div class="st-alert st-alert--warning" role="alert">
		🗑 <?php esc_html_e( 'Jugador eliminado de la nómina.', 'soccertrack' ); ?>
	</div>
<?php elseif ( $notice === 'logo_saved' ) : ?>
	<div class="st-alert st-alert--success" role="alert">
		✅ <?php esc_html_e( 'Logo actualizado correctamente.', 'soccertrack' ); ?>
	</div>
<?php endif; ?>

<?php if ( $error ) : ?>
	<div class="st-alert st-alert--error" role="alert">
		⚠️ <?php echo esc_html( $error ); ?>
	</div>
<?php endif; ?>

<?php /* ── Logo del equipo ──────────────────────────────────────────── */ ?>
<div class="st-card">
	<div class="st-card-header">
		<h2 class="st-card-title">🛡 <?php esc_html_e( 'Logo del equipo', 'soccertrack' ); ?></h2>
	</div>
	<form method="post" action="" enctype="multipart/form-data">
		<?php wp_nonce_field( 'st_save_logo_' . $team['id'] ); ?>
		<input type="hidden" name="st_save_logo" value="1">
		<div style="display:flex;align-items:flex-start;gap:24px;flex-wrap:wrap">

			<?php /* Preview actual */ ?>
			<div style="flex-shrink:0;text-align:center">
				<?php if ( ! empty( $team['logo_url'] ) ) : ?>
					<img src="<?php echo esc_url( $team['logo_url'] ); ?>"
					     alt="<?php echo esc_attr( $team['name'] ); ?>"
					     style="width:80px;height:80px;object-fit:contain;border:1px solid #ddd;border-radius:6px;padding:4px;background:#fff">
					<br><small style="color:#888"><?php esc_html_e( 'Logo actual', 'soccertrack' ); ?></small>
				<?php else : ?>
					<div style="width:80px;height:80px;border:2px dashed #ccc;border-radius:6px;display:flex;align-items:center;justify-content:center;font-size:2rem">
						🛡
					</div>
					<br><small style="color:#aaa"><?php esc_html_e( 'Sin logo', 'soccertrack' ); ?></small>
				<?php endif; ?>
			</div>

			<div style="flex:1;min-width:240px">
				<div class="st-field" style="margin-bottom:12px">
					<label for="st-logo-file" class="st-label">
						📤 <?php esc_html_e( 'Subir imagen (PNG, JPG, SVG)', 'soccertrack' ); ?>
					</label>
					<input type="file" id="st-logo-file" name="logo_file" class="st-input"
					       accept=".png,.jpg,.jpeg,.svg,image/png,image/jpeg,image/svg+xml">
					<small class="st-hint"><?php esc_html_e( 'Recomendado: cuadrado, mín. 200×200 px.', 'soccertrack' ); ?></small>
				</div>
				<div class="st-field" style="margin-bottom:12px">
					<label for="st-logo-url" class="st-label">
						🔗 <?php esc_html_e( 'O pegar URL de imagen ya publicada', 'soccertrack' ); ?>
					</label>
					<input type="url" id="st-logo-url" name="logo_url" class="st-input"
					       placeholder="https://..." value="<?php echo esc_attr( $team['logo_url'] ?? '' ); ?>">
					<small class="st-hint"><?php esc_html_e( 'Si subes un archivo, este campo se ignora.', 'soccertrack' ); ?></small>
				</div>
				<button type="submit" class="st-btn st-btn--primary">
					💾 <?php esc_html_e( 'Guardar logo', 'soccertrack' ); ?>
				</button>
			</div>
		</div>
	</form>
</div>

<?php /* ── Alta individual ──────────────────────────────────────────── */ ?>
<div class="st-card">
	<div class="st-card-header">
		<h2 class="st-card-title">➕ <?php esc_html_e( 'Agregar jugador', 'soccertrack' ); ?></h2>
	</div>

	<form method="post" action="" novalidate id="st-add-player-form">
		<?php wp_nonce_field( 'st_add_player_' . $team['id'] ); ?>
		<input type="hidden" name="st_add_player" value="1">

		<div class="st-form-inline">
			<div class="st-field">
				<label for="st-rut" class="st-label">
					<?php esc_html_e( 'RUT / ID *', 'soccertrack' ); ?>
				</label>
				<input
					type="text"
					id="st-rut"
					name="rut_id"
					class="st-input"
					placeholder="12345678-9"
					maxlength="20"
					required
					autocomplete="off"
					value="<?php echo isset( $_POST['rut_id'] ) ? esc_attr( sanitize_text_field( wp_unslash( $_POST['rut_id'] ) ) ) : ''; ?>"
				>
			</div>

			<div class="st-field">
				<label for="st-firstname" class="st-label">
					<?php esc_html_e( 'Nombre *', 'soccertrack' ); ?>
				</label>
				<input
					type="text"
					id="st-firstname"
					name="first_name"
					class="st-input"
					placeholder="<?php esc_attr_e( 'Nombre', 'soccertrack' ); ?>"
					maxlength="100"
					required
					value="<?php echo isset( $_POST['first_name'] ) ? esc_attr( sanitize_text_field( wp_unslash( $_POST['first_name'] ) ) ) : ''; ?>"
				>
			</div>

			<div class="st-field">
				<label for="st-lastname" class="st-label">
					<?php esc_html_e( 'Apellido *', 'soccertrack' ); ?>
				</label>
				<input
					type="text"
					id="st-lastname"
					name="last_name"
					class="st-input"
					placeholder="<?php esc_attr_e( 'Apellido', 'soccertrack' ); ?>"
					maxlength="100"
					required
					value="<?php echo isset( $_POST['last_name'] ) ? esc_attr( sanitize_text_field( wp_unslash( $_POST['last_name'] ) ) ) : ''; ?>"
				>
			</div>

			<div class="st-field" style="max-width:100px">
				<label for="st-dorsal" class="st-label">
					<?php esc_html_e( 'Dorsal *', 'soccertrack' ); ?>
				</label>
				<input
					type="number"
					id="st-dorsal"
					name="dorsal"
					class="st-input"
					min="1"
					max="99"
					required
					value="<?php echo isset( $_POST['dorsal'] ) ? esc_attr( absint( $_POST['dorsal'] ) ) : ''; ?>"
				>
			</div>

			<div class="st-field" style="justify-content:flex-end">
				<button type="submit" class="st-btn st-btn--primary">
					➕ <?php esc_html_e( 'Agregar', 'soccertrack' ); ?>
				</button>
			</div>
		</div>

		<?php /* ── Excepción de inscripción (solo coordinadores) ── */ ?>
		<?php if ( $notice === 'conflict' && current_user_can( 'ds_manage_tournaments' ) ) : ?>
		<div class="st-alert st-alert--warning" role="alert" style="margin-top:12px;padding:12px 16px">
			<strong>⚠️ <?php esc_html_e( 'Conflicto de inscripción detectado', 'soccertrack' ); ?></strong><br>
			<?php esc_html_e( 'Como coordinador puedes autorizar una inscripción de excepción. Marca la casilla y envía de nuevo:', 'soccertrack' ); ?>
			<div style="margin-top:10px;display:flex;align-items:center;gap:10px">
				<label style="display:flex;align-items:center;gap:8px;font-weight:600;cursor:pointer">
					<input type="checkbox" name="st_force_inscription" value="1" form="st-add-player-form">
					<?php esc_html_e( 'Autorizo inscripción de excepción', 'soccertrack' ); ?>
				</label>
			</div>
		</div>
		<?php endif; ?>

		<p style="font-size:.78rem;color:#888;margin-top:8px">
			<?php esc_html_e( '* Si el RUT ya existe en el sistema, se reutiliza el jugador sin duplicarlo.', 'soccertrack' ); ?>
		</p>
	</form>
</div>

<?php /* ── Nómina actual ────────────────────────────────────────────── */ ?>
<div class="st-card">
	<div class="st-card-header">
		<h2 class="st-card-title">
			<?php
			printf(
				/* translators: %d = número de jugadores */
				esc_html__( 'Nómina — %d jugador(es)', 'soccertrack' ),
				count( $roster )
			);
			?>
		</h2>
	</div>

	<?php if ( empty( $roster ) ) : ?>
		<p class="st-empty-msg">
			<?php esc_html_e( 'Sin jugadores inscritos. Usa el formulario de arriba o importa un CSV/XLSX.', 'soccertrack' ); ?>
		</p>
	<?php else : ?>
	<div class="st-table-wrap">
		<table class="st-table">
			<thead>
				<tr>
					<th style="width:60px"><?php esc_html_e( '#', 'soccertrack' ); ?></th>
					<th><?php esc_html_e( 'Jugador', 'soccertrack' ); ?></th>
					<th><?php esc_html_e( 'RUT / ID', 'soccertrack' ); ?></th>
					<th><?php esc_html_e( 'Estado', 'soccertrack' ); ?></th>
					<th style="width:80px"></th>
				</tr>
			</thead>
			<tbody>
			<?php foreach ( $roster as $p ) : ?>
				<tr>
					<td style="text-align:center;font-weight:700;font-size:1.1rem">
						<?php echo esc_html( (string) $p['dorsal'] ); ?>
					</td>
					<td><?php echo esc_html( $p['first_name'] . ' ' . $p['last_name'] ); ?></td>
					<td style="font-family:monospace;font-size:.85rem;color:#666">
						<?php echo esc_html( $p['rut_id'] ); ?>
					</td>
					<td>
						<?php if ( $p['is_suspended'] ) : ?>
							<span class="st-badge st-badge--danger">🟥 <?php esc_html_e( 'Suspendido', 'soccertrack' ); ?></span>
						<?php else : ?>
							<span class="st-badge st-badge--success">✅ <?php esc_html_e( 'Habilitado', 'soccertrack' ); ?></span>
						<?php endif; ?>
					</td>
					<td>
						<form method="post" action="" onsubmit="return confirm('<?php esc_attr_e( '¿Eliminar este jugador de la nómina?', 'soccertrack' ); ?>')">
							<?php wp_nonce_field( 'st_remove_player_' . $team['id'] ); ?>
							<input type="hidden" name="st_remove_player" value="1">
							<input type="hidden" name="tp_id" value="<?php echo esc_attr( (string) $p['tp_id'] ); ?>">
							<button type="submit" class="st-btn st-btn--sm" style="color:#c0392b;border-color:#c0392b" title="<?php esc_attr_e( 'Quitar del equipo', 'soccertrack' ); ?>">
								🗑
							</button>
						</form>
					</td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
	</div>
	<?php endif; ?>
</div>
