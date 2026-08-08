<?php defined( 'ABSPATH' ) || exit; ?>

<div class="st-page-header">
	<h1 class="st-page-title"><?php esc_html_e( 'Torneos', 'soccertrack' ); ?></h1>
</div>

<?php if ( $notice === 'created' ) : ?>
<div class="st-alert st-alert--success"><?php esc_html_e( 'Torneo creado correctamente.', 'soccertrack' ); ?></div>
<?php elseif ( $notice === 'status_updated' ) : ?>
<div class="st-alert st-alert--success"><?php esc_html_e( 'Estado del torneo actualizado.', 'soccertrack' ); ?></div>
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
			<label class="st-label"><?php esc_html_e( 'Inicio', 'soccertrack' ); ?></label>
			<input type="date" name="start_date" class="st-input">
		</div>
		<div class="st-field">
			<label class="st-label"><?php esc_html_e( 'Fin', 'soccertrack' ); ?></label>
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

		<div class="st-field">
			<label for="st-match-weekday" class="st-label">
				<?php esc_html_e( 'Día de partidos', 'soccertrack' ); ?>
			</label>
			<select id="st-match-weekday" name="match_weekday" class="st-input">
				<option value="1"><?php esc_html_e( 'Lunes', 'soccertrack' ); ?></option>
				<option value="2"><?php esc_html_e( 'Martes', 'soccertrack' ); ?></option>
				<option value="3"><?php esc_html_e( 'Miércoles', 'soccertrack' ); ?></option>
				<option value="4"><?php esc_html_e( 'Jueves', 'soccertrack' ); ?></option>
				<option value="5"><?php esc_html_e( 'Viernes', 'soccertrack' ); ?></option>
				<option value="6" selected="selected"><?php esc_html_e( 'Sábado', 'soccertrack' ); ?></option>
				<option value="0"><?php esc_html_e( 'Domingo', 'soccertrack' ); ?></option>
			</select>
		</div>

		<div class="st-field">
			<label for="st-match-time" class="st-label">
				<?php esc_html_e( 'Hora de inicio (primer partido)', 'soccertrack' ); ?>
			</label>
			<input
				type="time"
				id="st-match-time"
				name="match_time"
				class="st-input"
				value="19:00"
				min="07:00"
				max="23:00"
				style="max-width:120px"
			>
			<span style="font-size:.8rem;color:#888;margin-left:6px">
				<?php esc_html_e( 'Los siguientes partidos del día se asignan +1 hora c/u', 'soccertrack' ); ?>
			</span>
		</div>

		<input type="hidden" name="registration_mode" value="deferred">

		<div class="st-field">
			<label for="st-release-days" class="st-label">
				<?php esc_html_e( 'Días para liberar siguiente jornada', 'soccertrack' ); ?>
			</label>
			<input
				type="number"
				id="st-release-days"
				name="fixture_release_days"
				class="st-input"
				value="0"
				min="-7"
				max="30"
				style="max-width:100px"
			>
			<span style="font-size:.78rem;color:#888;display:block;margin-top:4px">
				<?php esc_html_e( '0 = visible de inmediato. 1 = al día siguiente de terminada la jornada anterior.', 'soccertrack' ); ?>
			</span>
		</div>

		<button type="submit" class="st-btn st-btn--primary">
			<?php esc_html_e( '+ Crear torneo', 'soccertrack' ); ?>
		</button>
	</form>
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
							'draft'     => [ 'new' => 'active',    'label' => __( '▶ Activar', 'soccertrack' ),    'class' => 'st-btn--success' ],
							'active'    => [ 'new' => 'completed', 'label' => __( '✔ Finalizar', 'soccertrack' ),  'class' => 'st-btn--warning' ],
							'completed' => [ 'new' => 'draft',     'label' => __( '↩ Borrador', 'soccertrack' ),   'class' => 'st-btn--danger' ],
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
