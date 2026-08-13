<?php defined( 'ABSPATH' ) || exit; ?>

<div class="st-page-header">
	<h1 class="st-page-title"><?php esc_html_e( 'Torneos', 'soccertrack' ); ?></h1>
</div>

<?php if ( $notice === 'created' ) : ?>
<div class="st-alert st-alert--success"><?php esc_html_e( 'Torneo creado correctamente.', 'soccertrack' ); ?></div>
<?php elseif ( $notice === 'status_updated' ) : ?>
<div class="st-alert st-alert--success"><?php esc_html_e( 'Estado del torneo actualizado.', 'soccertrack' ); ?></div>
<?php endif; ?>

<?php if ( ! empty( $status_error ?? '' ) ) : ?>
<div class="st-alert st-alert--error">⚠️ <?php echo esc_html( $status_error ); ?></div>
<?php endif; ?>

<?php if ( ! empty( $error ?? '' ) ) : ?>
<div class="st-alert st-alert--error">⚠️ <?php echo esc_html( $error ); ?></div>
<?php endif; ?>

<?php /* ── Formulario crear torneo ─────────────────────────────────── */ ?>
<div class="st-card">
	<h2 class="st-card-title"><?php esc_html_e( 'Nuevo torneo', 'soccertrack' ); ?></h2>
	<form method="post" class="st-form-inline">
		<?php wp_nonce_field( 'st_create_tournament' ); ?>
		<input type="hidden" name="st_create_tournament" value="1">

		<div class="st-field">
			<label class="st-label"><?php esc_html_e( 'Nombre', 'soccertrack' ); ?> *</label>
			<input type="text" name="name" class="st-input" required placeholder="<?php esc_attr_e( 'Ej: Liga Corporativa 2026', 'soccertrack' ); ?>">
		</div>
		<div class="st-field">
			<label class="st-label"><?php esc_html_e( 'Inicio', 'soccertrack' ); ?> *</label>
			<input type="date" name="start_date" class="st-input" required>
		</div>
		<div class="st-field">
			<label class="st-label">
				<?php esc_html_e( 'Fin estimado', 'soccertrack' ); ?>
				<span title="<?php esc_attr_e( 'Puede ajustarse más adelante según el desarrollo del torneo (clima, suspensiones, etc.)', 'soccertrack' ); ?>"
					  style="font-size:.75rem;color:#999;font-weight:400;cursor:default"> ℹ</span>
			</label>
			<input type="date" name="end_date" class="st-input">
		</div>
		<div class="st-field">
			<label class="st-label"><?php esc_html_e( 'Formato', 'soccertrack' ); ?></label>
			<select name="format" class="st-input">
				<option value="round_robin"><?php esc_html_e( 'Todos contra todos', 'soccertrack' ); ?></option>
				<option value="round_robin_playoffs" selected="selected"><?php esc_html_e( 'Todos contra todos + Play-offs', 'soccertrack' ); ?></option>
				<option value="group_stage"><?php esc_html_e( 'Fase de grupos', 'soccertrack' ); ?></option>
				<option value="knockout"><?php esc_html_e( 'Eliminación directa', 'soccertrack' ); ?></option>
			</select>
		</div>

		<div id="st-group-stage-options" style="display:none">
			<div class="st-field">
				<label class="st-label"><?php esc_html_e( 'Número de grupos', 'soccertrack' ); ?></label>
				<input type="number" name="group_count" class="st-input" value="2" min="2" max="8" style="max-width:80px">
			</div>
			<div class="st-field">
				<label class="st-label"><?php esc_html_e( 'Equipos que clasifican por grupo', 'soccertrack' ); ?></label>
				<input type="number" name="teams_advancing_per_group" class="st-input" value="2" min="1" max="4" style="max-width:80px">
			</div>
		</div>

		<div id="st-third-place-options" style="display:none">
			<div class="st-field">
				<label class="st-label" style="display:flex;align-items:center;gap:8px">
					<input type="checkbox" name="has_third_place" value="1" checked>
					<?php esc_html_e( 'Partido por 3.er puesto', 'soccertrack' ); ?>
				</label>
			</div>
		</div>

		<input type="hidden" name="registration_mode" value="realtime">

		<button type="submit" class="st-btn st-btn--primary">
			<?php esc_html_e( '+ Crear torneo', 'soccertrack' ); ?>
		</button>
	</form>
	<script>
	(function() {
		var fmt      = document.querySelector('select[name="format"]');
		var gsOpts   = document.getElementById('st-group-stage-options');
		var thirdOpt = document.getElementById('st-third-place-options');
		function toggle() {
			gsOpts.style.display   = fmt.value === 'group_stage' ? '' : 'none';
			thirdOpt.style.display = ( fmt.value === 'group_stage' || fmt.value === 'knockout' ) ? '' : 'none';
		}
		fmt.addEventListener('change', toggle);
		toggle();
	}());
	</script>
</div>

<?php /* ── Lista de torneos ─────────────────────────────────────────── */ ?>
<div class="st-card">
	<h2 class="st-card-title"><?php esc_html_e( 'Todos los torneos', 'soccertrack' ); ?></h2>

	<?php if ( empty( $tournaments ) ) : ?>
		<p class="st-empty-msg"><?php esc_html_e( 'Aún no hay torneos. Crea el primero arriba.', 'soccertrack' ); ?></p>
	<?php else : ?>
	<div class="st-table-wrap">
		<table class="st-table">
			<thead>
				<tr>
					<th>#</th>
					<th><?php esc_html_e( 'Nombre', 'soccertrack' ); ?></th>
					<th><?php esc_html_e( 'Formato', 'soccertrack' ); ?></th>
					<th><?php esc_html_e( 'Inicio', 'soccertrack' ); ?></th>
					<th><?php esc_html_e( 'Equipos', 'soccertrack' ); ?></th>
					<th><?php esc_html_e( 'Partidos', 'soccertrack' ); ?></th>
					<th><?php esc_html_e( 'Estado', 'soccertrack' ); ?></th>
					<th><?php esc_html_e( 'Acciones', 'soccertrack' ); ?></th>
				</tr>
			</thead>
			<tbody>
			<?php foreach ( $tournaments as $t ) : ?>
				<tr>
					<td><?php echo esc_html( (string) $t['id'] ); ?></td>
					<td><strong><?php echo esc_html( $t['name'] ); ?></strong></td>
					<td><?php
					$format_labels = [
						'round_robin'          => __( 'Todos contra todos', 'soccertrack' ),
						'round_robin_playoffs' => __( 'Todos contra todos + Play-offs', 'soccertrack' ),
						'group_stage'          => __( 'Fase de grupos', 'soccertrack' ),
						'knockout'             => __( 'Eliminación directa', 'soccertrack' ),
					];
					echo esc_html( $format_labels[ $t['format'] ?? '' ] ?? ( $t['format'] ?? '—' ) );
					?></td>
					<td><?php echo $t['start_date'] ? esc_html( date_i18n( 'd/m/Y', strtotime( $t['start_date'] ) ) ) : '—'; ?></td>
					<td style="text-align:center"><?php echo esc_html( (string) $t['team_count'] ); ?></td>
					<td style="text-align:center"><?php echo esc_html( (string) $t['match_count'] ); ?></td>
					<td><?php
					$status_map = [
						'draft'     => [ 'label' => __( 'Borrador', 'soccertrack' ),  'class' => '' ],
						'active'    => [ 'label' => __( 'Activo', 'soccertrack' ),    'class' => 'st-badge--success' ],
						'completed' => [ 'label' => __( 'Finalizado', 'soccertrack' ),'class' => 'st-badge--secondary' ],
					];
					$st = $status_map[ $t['status'] ?? 'draft' ] ?? [ 'label' => $t['status'], 'class' => '' ];
					echo '<span class="st-badge ' . esc_attr( $st['class'] ) . '">' . esc_html( $st['label'] ) . '</span>';
					?></td>
					<td style="white-space: nowrap">
						<a href="<?php echo esc_url( home_url( '/panel/torneo/' . $t['id'] . '/' ) ); ?>" class="st-btn st-btn--sm st-btn--secondary">
							<?php esc_html_e( 'Gestionar', 'soccertrack' ); ?>
						</a>
						<a href="<?php echo esc_url( home_url( '/torneo/' . $t['id'] . '/' ) ); ?>" class="st-btn st-btn--sm st-btn--secondary" target="_blank">
							<?php esc_html_e( 'Portal ↗', 'soccertrack' ); ?>
						</a>
						<?php
						$status_transitions = [
							'draft'  => [ 'new' => 'active',    'label' => __( '▶ Activar', 'soccertrack' ),   'class' => 'st-btn--success' ],
							'active' => [ 'new' => 'completed', 'label' => __( '✔ Finalizar', 'soccertrack' ), 'class' => 'st-btn--warning' ],
							// completed: sin transición — un torneo finalizado no retrocede.
						];
						$transition = $status_transitions[ $t['status'] ?? 'draft' ] ?? null;
						if ( $transition ) :
						?>
						<form method="post" style="display:inline">
							<?php wp_nonce_field( 'st_change_status' ); ?>
							<input type="hidden" name="st_change_status" value="1">
							<input type="hidden" name="tournament_id" value="<?php echo esc_attr( (string) $t['id'] ); ?>">
							<input type="hidden" name="new_status" value="<?php echo esc_attr( $transition['new'] ); ?>">
							<button type="submit" class="st-btn st-btn--sm <?php echo esc_attr( $transition['class'] ); ?>">
								<?php echo esc_html( $transition['label'] ); ?>
							</button>
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
