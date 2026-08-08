<?php defined( 'ABSPATH' ) || exit; ?>

<?php if ( ( $notice ?? '' ) === 'bases_saved' ) : ?>
	<div class="st-alert st-alert--success">✅ <?php esc_html_e( 'URL de bases guardada correctamente.', 'soccertrack' ); ?></div>
<?php endif; ?>
<?php if ( ( $notice ?? '' ) === 'referee_updated' ) : ?>
	<div class="st-alert st-alert--success">✅ <?php esc_html_e( 'Árbitro asignado correctamente.', 'soccertrack' ); ?></div>
<?php endif; ?>
<?php if ( ( $notice ?? '' ) === 'match_status_updated' ) : ?>
	<div class="st-alert st-alert--success">✅ <?php esc_html_e( 'Estado del partido actualizado.', 'soccertrack' ); ?></div>
<?php endif; ?>
<?php if ( ( $notice ?? '' ) === 'planillero_updated' ) : ?>
	<div class="st-alert st-alert--success">✅ <?php esc_html_e( 'Planillero asignado correctamente.', 'soccertrack' ); ?></div>
<?php endif; ?>
<?php if ( ( $notice ?? '' ) === 'auto_assigned' ) : ?>
	<div class="st-alert st-alert--success">🔄 <?php esc_html_e( 'Árbitros y planilleros auto-asignados. Puedes ajustar manualmente si es necesario.', 'soccertrack' ); ?></div>
<?php endif; ?>
<?php if ( ( $notice ?? '' ) === 'schedule_updated' ) : ?>
	<div class="st-alert st-alert--success">✅ <?php esc_html_e( 'Horario del torneo actualizado.', 'soccertrack' ); ?></div>
<?php endif; ?>
<?php if ( ( $notice ?? '' ) === 'reg_mode_updated' ) : ?>
	<div class="st-alert st-alert--success">✅ <?php esc_html_e( 'Modo de registro actualizado.', 'soccertrack' ); ?></div>
<?php endif; ?>
<?php if ( ( $notice ?? '' ) === 'release_days_updated' ) : ?>
	<div class="st-alert st-alert--success">✅ <?php esc_html_e( 'Liberación de fixture actualizada.', 'soccertrack' ); ?></div>
<?php endif; ?>
<?php if ( ( $notice ?? '' ) === 'venues_updated' ) : ?>
	<div class="st-alert st-alert--success">✅ <?php esc_html_e( 'Recintos del torneo actualizados.', 'soccertrack' ); ?></div>
<?php endif; ?>
<?php if ( ! empty( $error ?? '' ) ) : ?>
	<div class="st-alert st-alert--error">⚠️ <?php echo esc_html( $error ); ?></div>
<?php endif; ?>

<div class="st-page-header">
	<a href="<?php echo esc_url( home_url( '/panel/torneos/' ) ); ?>" class="st-back-link">← <?php esc_html_e( 'Torneos', 'soccertrack' ); ?></a>
	<h1 class="st-page-title"><?php echo esc_html( $tournament['name'] ); ?></h1>
	<a href="<?php echo esc_url( home_url( '/torneo/' . $tournament['id'] . '/' ) ); ?>" class="st-btn st-btn--secondary" target="_blank">
		🌐 <?php esc_html_e( 'Ver portal público', 'soccertrack' ); ?>
	</a>
</div>

<?php /* ── Equipos inscritos ─────────────────────────────────────────── */ ?>
<div class="st-card">
	<div class="st-card-header">
		<h2 class="st-card-title"><?php esc_html_e( 'Equipos', 'soccertrack' ); ?></h2>
		<a href="<?php echo esc_url( home_url( '/panel/importar/?tournament_id=' . $tournament['id'] ) ); ?>" class="st-btn st-btn--primary st-btn--sm">
			📥 <?php esc_html_e( 'Importar equipos', 'soccertrack' ); ?>
		</a>
	</div>

	<?php if ( empty( $teams ) ) : ?>
		<p class="st-empty-msg"><?php esc_html_e( 'Sin equipos inscritos. Usa el importador para cargar equipos.', 'soccertrack' ); ?></p>
	<?php else : ?>
	<div class="st-table-wrap">
		<table class="st-table">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Equipo', 'soccertrack' ); ?></th>
					<th><?php esc_html_e( 'Jugadores', 'soccertrack' ); ?></th>
					<th><?php esc_html_e( 'Acciones', 'soccertrack' ); ?></th>
				</tr>
			</thead>
			<tbody>
			<?php foreach ( $teams as $team ) : ?>
				<tr>
					<td>
						<?php if ( ! empty( $team['logo_url'] ) ) : ?>
							<img src="<?php echo esc_url( $team['logo_url'] ); ?>" alt="" style="width:24px;height:24px;object-fit:contain;vertical-align:middle;margin-right:6px">
						<?php endif; ?>
						<?php echo esc_html( $team['name'] ); ?>
					</td>
					<td style="text-align:center"><?php echo esc_html( (string) $team['player_count'] ); ?></td>
					<td style="display:flex;gap:8px;flex-wrap:wrap">
						<a href="<?php echo esc_url( home_url( '/panel/equipo/' . $team['id'] . '/' ) ); ?>" class="st-btn st-btn--sm st-btn--primary">
							👥 <?php esc_html_e( 'Gestionar nómina', 'soccertrack' ); ?>
						</a>
						<a href="<?php echo esc_url( home_url( '/panel/importar/?tournament_id=' . $tournament['id'] . '&team_id=' . $team['id'] ) ); ?>" class="st-btn st-btn--sm st-btn--secondary">
							📥 <?php esc_html_e( 'Importar masivo', 'soccertrack' ); ?>
						</a>
					</td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
	</div>
	<?php endif; ?>
</div>

<?php /* ── Configuración de horario del torneo ─────────────────────────── */ ?>
<div class="st-card" style="margin-bottom:20px">
	<div class="st-card-header">
		<h2 class="st-card-title">🕖 <?php esc_html_e( 'Horario habitual de partidos', 'soccertrack' ); ?></h2>
	</div>
	<form method="post" action="" class="st-form-inline" style="align-items:flex-end;gap:16px;padding:0 0 4px">
		<?php wp_nonce_field( 'st_update_schedule_' . $tournament['id'] ); ?>
		<input type="hidden" name="st_update_schedule" value="1">

		<?php
		// Días en orden lunes-domingo (0=dom al final para mostrar lun-dom).
		$day_labels = [
			1 => __( 'Lunes', 'soccertrack' ),
			2 => __( 'Martes', 'soccertrack' ),
			3 => __( 'Miércoles', 'soccertrack' ),
			4 => __( 'Jueves', 'soccertrack' ),
			5 => __( 'Viernes', 'soccertrack' ),
			6 => __( 'Sábado', 'soccertrack' ),
			0 => __( 'Domingo', 'soccertrack' ),
		];

		$saved_days_raw = $tournament['match_weekdays'] ?? null;
		$saved_days     = [];
		if ( is_string( $saved_days_raw ) && $saved_days_raw !== '' ) {
			$decoded    = json_decode( $saved_days_raw, true );
			$saved_days = is_array( $decoded ) ? array_map( 'intval', $decoded ) : [];
		}
		if ( empty( $saved_days ) ) {
			$saved_days = [ (int) ( $tournament['match_weekday'] ?? 6 ) ];
		}
		?>

		<div class="st-field">
			<label class="st-label"><?php esc_html_e( 'Días de partido', 'soccertrack' ); ?></label>
			<div style="display:flex;gap:12px;flex-wrap:wrap;padding-top:4px">
				<?php foreach ( $day_labels as $val => $label ) : ?>
					<label style="display:flex;align-items:center;gap:5px;cursor:pointer;font-size:.9rem">
						<input
							type="checkbox"
							name="match_weekdays[]"
							value="<?php echo esc_attr( (string) $val ); ?>"
							<?php checked( in_array( $val, $saved_days, true ) ); ?>
						>
						<?php echo esc_html( $label ); ?>
					</label>
				<?php endforeach; ?>
			</div>
		</div>

		<?php
		$start_hm      = substr( (string) ( $tournament['match_time'] ?? '19:00:00' ), 0, 5 );
		$duration_min  = max( 30, (int) ( $tournament['match_duration'] ?? 60 ) );
		[ $sh, $sm ]   = array_map( 'intval', explode( ':', $start_hm ) );
		$end_total_min = $sh * 60 + $sm + $duration_min;
		$end_hm        = sprintf( '%02d:%02d', intdiv( $end_total_min, 60 ) % 24, $end_total_min % 60 );
		?>

		<div class="st-field">
			<label class="st-label"><?php esc_html_e( 'Hora inicio', 'soccertrack' ); ?></label>
			<input
				type="time"
				name="match_time"
				class="st-input"
				value="<?php echo esc_attr( $start_hm ); ?>"
				style="max-width:120px"
			>
		</div>

		<div class="st-field">
			<label class="st-label"><?php esc_html_e( 'Duración (min)', 'soccertrack' ); ?></label>
			<input
				type="number"
				name="match_duration"
				class="st-input"
				value="<?php echo esc_attr( (string) $duration_min ); ?>"
				min="30"
				max="180"
				step="5"
				style="max-width:90px"
			>
			<span style="font-size:.85rem;color:#555;align-self:center">
				→ <?php esc_html_e( 'Término:', 'soccertrack' ); ?>
				<strong id="st-match-end-time"><?php echo esc_html( $end_hm ); ?></strong>
			</span>
		</div>

		<div class="st-field" style="align-self:flex-end">
			<button type="submit" class="st-btn st-btn--secondary st-btn--sm">
				💾 <?php esc_html_e( 'Guardar', 'soccertrack' ); ?>
			</button>
		</div>
	</form>
	<p style="margin:8px 0 0;font-size:.8rem;color:#888">
		<?php esc_html_e( 'Este horario se usa al generar el fixture. Los partidos ya generados conservan su fecha individual.', 'soccertrack' ); ?>
	</p>
	<script>
	(function () {
		const timeInput = document.querySelector( '[name="match_time"]' );
		const durInput  = document.querySelector( '[name="match_duration"]' );
		const endLabel  = document.getElementById( 'st-match-end-time' );
		if ( ! timeInput || ! durInput || ! endLabel ) return;

		function updateEnd() {
			const [ h, m ] = timeInput.value.split( ':' ).map( Number );
			const dur      = Math.max( 30, parseInt( durInput.value, 10 ) || 60 );
			const total    = h * 60 + m + dur;
			const eh       = String( Math.floor( total / 60 ) % 24 ).padStart( 2, '0' );
			const em       = String( total % 60 ).padStart( 2, '0' );
			endLabel.textContent = eh + ':' + em;
		}

		timeInput.addEventListener( 'input', updateEnd );
		durInput.addEventListener( 'input', updateEnd );
	} )();
	</script>
</div>

<?php /* Modo de registro deshabilitado — por defecto planilla física (deferred). */ ?>

<?php /* ── Liberación del fixture ──────────────────────────────────────── */ ?>
<div class="st-card" style="margin-bottom:20px">
	<div class="st-card-header">
		<h2 class="st-card-title">📅 <?php esc_html_e( 'Liberación del fixture', 'soccertrack' ); ?></h2>
	</div>
	<form method="post" action="" class="st-form-inline" style="align-items:flex-end;gap:16px">
		<?php wp_nonce_field( 'st_update_release_days_' . $tournament['id'] ); ?>
		<input type="hidden" name="st_update_release_days" value="1">

		<div class="st-field">
			<label class="st-label"><?php esc_html_e( 'Días tras última fecha', 'soccertrack' ); ?></label>
			<input
				type="number"
				name="fixture_release_days"
				class="st-input"
				value="<?php echo esc_attr( (string) (int) ( $tournament['fixture_release_days'] ?? 0 ) ); ?>"
				min="-7"
				max="30"
				style="max-width:100px"
			>
		</div>

		<div class="st-field" style="align-self:flex-end">
			<button type="submit" class="st-btn st-btn--secondary st-btn--sm">
				💾 <?php esc_html_e( 'Guardar', 'soccertrack' ); ?>
			</button>
		</div>
	</form>
	<p style="margin:8px 0 0;font-size:.8rem;color:#888">
		<?php esc_html_e( '0 = todas las jornadas visibles de inmediato. 1 = la siguiente jornada se publica al día siguiente de terminada la anterior.', 'soccertrack' ); ?>
	</p>
</div>

<?php /* ── Recintos del torneo ─────────────────────────────────────── */ ?>
<div class="st-card" style="margin-bottom:20px">
	<div class="st-card-header">
		<h2 class="st-card-title">🏟️ <?php esc_html_e( 'Recintos del torneo', 'soccertrack' ); ?></h2>
	</div>

	<?php if ( empty( $venues ) ) : ?>
		<p style="margin:0;font-size:.85rem">
			<a href="<?php echo esc_url( home_url( '/panel/recintos/' ) ); ?>">
				<?php esc_html_e( '→ Crea un recinto primero para asignarlo aquí', 'soccertrack' ); ?>
			</a>
		</p>
	<?php else : ?>
	<form method="post" action="">
		<?php wp_nonce_field( 'st_update_venues_' . $tournament['id'] ); ?>
		<input type="hidden" name="st_update_venues" value="1">

		<div style="display:flex;gap:14px;flex-wrap:wrap;padding-bottom:12px">
			<?php foreach ( $venues as $v ) : ?>
				<label style="display:flex;align-items:center;gap:6px;cursor:pointer;font-size:.9rem">
					<input
						type="checkbox"
						name="tournament_venue_ids[]"
						value="<?php echo esc_attr( (string) $v['id'] ); ?>"
						<?php checked( in_array( (int) $v['id'], $tournament_venue_ids ?? [], true ) ); ?>
					>
					<?php echo esc_html( $v['name'] ); ?>
				</label>
			<?php endforeach; ?>
		</div>

		<button type="submit" class="st-btn st-btn--secondary st-btn--sm">
			💾 <?php esc_html_e( 'Guardar', 'soccertrack' ); ?>
		</button>
	</form>
	<p style="margin:8px 0 0;font-size:.8rem;color:#888">
		<?php esc_html_e( 'Los selectores de recinto al generar el fixture mostrarán solo los recintos marcados aquí. Si no hay ninguno marcado, se muestran todos.', 'soccertrack' ); ?>
	</p>
	<?php endif; ?>
</div>

<?php /* ── Fixture ───────────────────────────────────────────────────── */ ?>
<div class="st-card">
	<div class="st-card-header">
		<h2 class="st-card-title"><?php esc_html_e( 'Fixture', 'soccertrack' ); ?></h2>
		<?php if ( ! empty( $matches ) ) : ?>
		<form method="post" style="display:inline">
			<?php wp_nonce_field( 'st_auto_assign_' . $tournament['id'] ); ?>
			<input type="hidden" name="st_auto_assign" value="1">
			<button type="submit" class="st-btn st-btn--sm st-btn--secondary">
				🔄 <?php esc_html_e( 'Auto-asignar', 'soccertrack' ); ?>
			</button>
		</form>
		<?php endif; ?>
		<?php if ( empty( $matches ) && ! empty( $teams ) ) : ?>
		<?php
		// Filtrar recintos al subconjunto configurado para el torneo (si hay alguno).
		$venues_for_select = ! empty( $tournament_venue_ids )
			? array_values( array_filter( $venues, fn( $v ) => in_array( (int) $v['id'], $tournament_venue_ids, true ) ) )
			: $venues;
		?>
		<?php if ( ! empty( $venues_for_select ) ) : ?>
		<div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap">
			<select id="st-venue-select" class="st-input" style="max-width:220px">
				<option value=""><?php esc_html_e( '— Seleccionar recinto —', 'soccertrack' ); ?></option>
				<?php foreach ( $venues_for_select as $v ) : ?>
					<option value="<?php echo esc_attr( (string) $v['id'] ); ?>">
						<?php echo esc_html( $v['name'] ); ?>
					</option>
				<?php endforeach; ?>
			</select>
			<button
				class="st-btn st-btn--primary st-btn--sm"
				id="st-gen-fixture-btn"
				data-tournament="<?php echo esc_attr( (string) $tournament['id'] ); ?>"
				data-nonce="<?php echo esc_attr( wp_create_nonce( 'wp_rest' ) ); ?>"
			>
				⚡ <?php esc_html_e( 'Generar fixture', 'soccertrack' ); ?>
			</button>
		</div>
		<?php else : ?>
			<p style="margin:0;font-size:.85rem">
				<a href="<?php echo esc_url( home_url( '/panel/recintos/' ) ); ?>">
					<?php esc_html_e( '→ Crea un recinto primero', 'soccertrack' ); ?>
				</a>
			</p>
		<?php endif; ?>
		<?php endif; ?>
	</div>

	<div id="st-fixture-notice"></div>

	<?php if ( empty( $matches ) ) : ?>
		<p class="st-empty-msg"><?php esc_html_e( 'No hay partidos generados. Usa el botón "Generar fixture" cuando tengas los equipos inscritos.', 'soccertrack' ); ?></p>
	<?php else : ?>
	<?php
	$status_labels = [
		'scheduled'   => __( 'Programado', 'soccertrack' ),
		'in_progress' => __( 'En curso', 'soccertrack' ),
		'finished'    => __( 'Finalizado', 'soccertrack' ),
		'suspended'   => __( 'Suspendido', 'soccertrack' ),
		'postponed'   => __( 'Aplazado', 'soccertrack' ),
	];
	?>
	<div class="st-table-wrap">
		<table class="st-table st-table--fixture">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Fecha / Fase', 'soccertrack' ); ?></th>
					<th><?php esc_html_e( 'Local', 'soccertrack' ); ?></th>
					<th><?php esc_html_e( 'Resultado', 'soccertrack' ); ?></th>
					<th><?php esc_html_e( 'Visitante', 'soccertrack' ); ?></th>
					<th><?php esc_html_e( 'Estado', 'soccertrack' ); ?></th>
					<th><?php esc_html_e( 'Horario', 'soccertrack' ); ?></th>
					<th><?php esc_html_e( 'Cancha', 'soccertrack' ); ?></th>
					<th><?php esc_html_e( 'Árbitro', 'soccertrack' ); ?></th>
					<th><?php esc_html_e( 'Planillero', 'soccertrack' ); ?></th>
					<th><?php esc_html_e( 'Planilla', 'soccertrack' ); ?></th>
				</tr>
			</thead>
			<tbody>
			<?php
			$phase_labels = [
				'regular'     => '',
				'semifinal'   => '⚡ ' . __( 'Semi', 'soccertrack' ),
				'third_place' => '🥉 ' . __( '3.er Puesto', 'soccertrack' ),
				'final'       => '🏆 ' . __( 'Final', 'soccertrack' ),
			];
			?>
			<?php $prev_round = -1; ?>
			<?php foreach ( $matches as $m ) : ?>
				<?php /* Agregar header de jornada con botón "Cargar acta" en modo deferred */ ?>
				<?php if ( $prev_round !== (int) $m['round_number'] && ( $tournament['registration_mode'] ?? 'realtime' ) === 'deferred' ) : ?>
					<?php $prev_round = (int) $m['round_number']; ?>
					<tr>
						<td colspan="10" style="background:#f0f7ff;padding:6px 12px">
							<strong><?php printf( esc_html__( 'Jornada %d', 'soccertrack' ), (int) $m['round_number'] ); ?></strong>
							<a href="<?php echo esc_url( home_url( '/panel/carga-fecha/?tournament_id=' . $tournament['id'] . '&round=' . (int) $m['round_number'] ) ); ?>"
							   class="st-btn st-btn--sm st-btn--primary" style="margin-left:12px">
								📋 <?php esc_html_e( 'Cargar acta de esta jornada', 'soccertrack' ); ?>
							</a>
						</td>
					</tr>
				<?php endif; ?>
				<tr>
					<td>
						<?php
						$phase_cur = $m['phase'] ?? 'regular';
						if ( $phase_cur === 'regular' ) {
							echo esc_html( (string) $m['round_number'] );
						} else {
							echo esc_html( $phase_labels[ $phase_cur ] ?? $phase_cur );
						}
						?>
					</td>
					<td><?php echo esc_html( $m['home_team'] ); ?></td>
					<td style="text-align:center;font-weight:700">
						<?php
						if ( $m['status'] === 'finished' ) {
							echo esc_html( $m['home_score'] . ' — ' . $m['away_score'] );
						} else {
							esc_html_e( 'vs', 'soccertrack' );
						}
						?>
					</td>
					<td><?php echo esc_html( $m['away_team'] ); ?></td>
					<td><span class="st-badge"><?php echo esc_html( $status_labels[ $m['status'] ?? '' ] ?? ( $m['status'] ?? '' ) ); ?></span></td>
					<td>
						<?php
						$dt             = $m['match_datetime'] ?? '';
						$locked_by_time = ! empty( $dt ) && ( strtotime( $dt ) - time() ) < HOUR_IN_SECONDS;

						if ( $m['status'] !== 'finished' ) :
					?>
						<?php if ( $locked_by_time ) : ?>
						<span style="font-size:.82rem;color:#555">
							<?php echo $dt ? esc_html( date_i18n( 'd/m/Y H:i', strtotime( $dt ) ) ) : '—'; ?>
						</span>
						<span title="<?php esc_attr_e( 'No se puede modificar con menos de 1 hora de anticipación', 'soccertrack' ); ?>"
							  style="font-size:.8rem;color:#e67e22;margin-left:4px">🔒</span>
						<?php else : ?>
						<form method="post" action="">
							<?php wp_nonce_field( 'st_update_datetime_' . $tournament['id'] ); ?>
							<input type="hidden" name="st_update_datetime" value="1">
							<input type="hidden" name="match_id" value="<?php echo esc_attr( (string) $m['id'] ); ?>">
							<input
								type="datetime-local"
								name="match_datetime"
								class="st-input st-fixture-dt-input"
								value="<?php echo esc_attr( $dt ? substr( str_replace( ' ', 'T', $dt ), 0, 16 ) : '' ); ?>"
							>
							<button type="submit" class="st-btn st-btn--sm st-btn--secondary" title="<?php esc_attr_e( 'Guardar horario', 'soccertrack' ); ?>">✔</button>
						</form>
						<?php endif; ?>
						<?php else :
							echo $dt ? esc_html( date_i18n( 'd/m/Y H:i', strtotime( $dt ) ) ) : '—';
						endif; ?>
					</td>
					<td>
						<?php
						$venue_courts = $courts_by_venue[ (int) ( $m['venue_id'] ?? 0 ) ] ?? [];
						if ( $m['status'] !== 'finished' && ! empty( $venue_courts ) ) :
						?>
						<form method="post" action="">
							<?php wp_nonce_field( 'st_update_court_' . $tournament['id'] ); ?>
							<input type="hidden" name="st_update_court" value="1">
							<input type="hidden" name="match_id" value="<?php echo esc_attr( (string) $m['id'] ); ?>">
							<select name="court_id" class="st-input st-fixture-court-select">
								<?php foreach ( $venue_courts as $c ) : ?>
									<option value="<?php echo esc_attr( (string) $c['id'] ); ?>" <?php selected( (int) ( $m['court_id'] ?? 0 ), (int) $c['id'] ); ?>>
										<?php echo esc_html( $c['court_name'] ); ?>
									</option>
								<?php endforeach; ?>
							</select>
							<button type="submit" class="st-btn st-btn--sm st-btn--secondary" title="<?php esc_attr_e( 'Guardar', 'soccertrack' ); ?>">✔</button>
						</form>
						<?php else :
							$cname = '—';
							foreach ( $venue_courts as $c ) {
								if ( (int) $c['id'] === (int) ( $m['court_id'] ?? 0 ) ) { $cname = $c['court_name']; break; }
							}
							echo esc_html( $cname );
						endif; ?>
					</td>
					<td>
						<?php
						$ref_id   = (int) ( $m['referee_user_id'] ?? 0 );
						if ( $m['status'] !== 'finished' && ! empty( $referees ) ) :
						?>
						<form method="post" action="">
							<?php wp_nonce_field( 'st_update_referee_' . $tournament['id'] ); ?>
							<input type="hidden" name="st_update_referee" value="1">
							<input type="hidden" name="match_id" value="<?php echo esc_attr( (string) $m['id'] ); ?>">
							<select name="referee_user_id" class="st-input st-fixture-referee-select">
								<option value="0"><?php esc_html_e( '— Ninguno —', 'soccertrack' ); ?></option>
								<?php foreach ( $referees as $ref ) : ?>
									<option value="<?php echo esc_attr( (string) $ref->ID ); ?>" <?php selected( $ref_id, (int) $ref->ID ); ?>>
										<?php echo esc_html( $ref->display_name ); ?>
									</option>
								<?php endforeach; ?>
							</select>
							<button type="submit" class="st-btn st-btn--sm st-btn--secondary" title="<?php esc_attr_e( 'Guardar árbitro', 'soccertrack' ); ?>">✔</button>
						</form>
						<?php elseif ( $m['status'] === 'finished' ) :
							$ref_name = $ref_id ? ( get_user_by( 'id', $ref_id )?->display_name ?? '—' ) : '—';
							echo esc_html( $ref_name );
						else :
							echo '—';
						endif; ?>
					</td>
					<td>
					<?php if ( ( $tournament['registration_mode'] ?? 'realtime' ) === 'realtime' ) : ?>
						<?php /* Asignación de planillero — flujo existente */ ?>
						<?php
						$plan_id = (int) ( $m['planillero_user_id'] ?? 0 );
						if ( $m['status'] !== 'finished' && ! empty( $planilleros ) ) :
						?>
						<form method="post" action="">
							<?php wp_nonce_field( 'st_update_planillero_' . $tournament['id'] ); ?>
							<input type="hidden" name="st_update_planillero" value="1">
							<input type="hidden" name="match_id" value="<?php echo esc_attr( (string) $m['id'] ); ?>">
							<select name="planillero_user_id" class="st-input st-fixture-planillero-select">
								<option value="0"><?php esc_html_e( '— Ninguno —', 'soccertrack' ); ?></option>
								<?php foreach ( $planilleros as $plan ) : ?>
									<option value="<?php echo esc_attr( (string) $plan->ID ); ?>" <?php selected( $plan_id, (int) $plan->ID ); ?>>
										<?php echo esc_html( $plan->display_name ); ?>
									</option>
								<?php endforeach; ?>
							</select>
							<button type="submit" class="st-btn st-btn--sm st-btn--secondary" title="<?php esc_attr_e( 'Guardar planillero', 'soccertrack' ); ?>">✔</button>
						</form>
						<?php elseif ( $m['status'] === 'finished' ) :
							$plan_name = $plan_id ? ( get_user_by( 'id', $plan_id )?->display_name ?? '—' ) : '—';
							echo esc_html( $plan_name );
						else :
							echo '—';
						endif; ?>
					<?php else : ?>
						<span style="font-size:.78rem;color:#888">—</span>
					<?php endif; ?>
					</td>
					<td>
						<?php if ( $m['status'] !== 'finished' ) : ?>
						<a href="<?php echo esc_url( home_url( '/panel/partido/' . $m['id'] . '/' ) ); ?>" class="st-btn st-btn--sm st-btn--primary">
							📋 <?php esc_html_e( 'Planilla', 'soccertrack' ); ?>
						</a>
						<?php endif; ?>
						<?php
						$match_transitions = [
							'scheduled'   => [ 'new' => 'in_progress', 'label' => '▶ Iniciar',   'class' => 'st-btn--success' ],
							'in_progress' => [ 'new' => 'finished',    'label' => '✔ Finalizar', 'class' => 'st-btn--warning' ],
							'finished'    => [ 'new' => 'scheduled',   'label' => '↩ Reabrir',   'class' => 'st-btn--danger' ],
							'suspended'   => [ 'new' => 'scheduled',   'label' => '↩ Reprogramar','class' => 'st-btn--secondary' ],
							'postponed'   => [ 'new' => 'scheduled',   'label' => '↩ Reprogramar','class' => 'st-btn--secondary' ],
						];
						$mt = $match_transitions[ $m['status'] ?? 'scheduled' ] ?? null;
						if ( $mt ) :
						?>
						<form method="post" style="display:inline;margin-left:4px">
							<?php wp_nonce_field( 'st_change_match_status_' . $tournament['id'] ); ?>
							<input type="hidden" name="st_change_match_status" value="1">
							<input type="hidden" name="match_id" value="<?php echo esc_attr( (string) $m['id'] ); ?>">
							<input type="hidden" name="new_status" value="<?php echo esc_attr( $mt['new'] ); ?>">
							<button type="submit" class="st-btn st-btn--sm <?php echo esc_attr( $mt['class'] ); ?>">
								<?php echo esc_html( $mt['label'] ); ?>
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

<?php /* ── Play-offs (solo para formato round_robin_playoffs) ───────── */ ?>
<?php if ( ! empty( $playoffs_status['is_playoffs_format'] ) ) : ?>
<div class="st-card">
	<div class="st-card-header">
		<h2 class="st-card-title">🏆 <?php esc_html_e( 'Play-offs', 'soccertrack' ); ?></h2>
	</div>

	<div id="st-playoffs-notice"></div>

	<?php if ( ! $playoffs_status['has_semifinals'] ) : ?>
		<?php if ( $playoffs_status['all_regular_done'] ) : ?>
			<p style="margin-bottom:12px;color:#3C3A47">
				<?php esc_html_e( 'Fase regular finalizada. Puedes generar las semi-finales con los 4 mejores equipos de la tabla.', 'soccertrack' ); ?>
			</p>
			<?php
			$venues_for_select ??= ! empty( $tournament_venue_ids )
				? array_values( array_filter( $venues, fn( $v ) => in_array( (int) $v['id'], $tournament_venue_ids, true ) ) )
				: $venues;
			?>
			<?php if ( ! empty( $venues_for_select ) ) : ?>
			<div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap">
				<select id="st-playoff-venue-select" class="st-input" style="max-width:220px">
					<option value=""><?php esc_html_e( '— Seleccionar recinto —', 'soccertrack' ); ?></option>
					<?php foreach ( $venues_for_select as $v ) : ?>
						<option value="<?php echo esc_attr( (string) $v['id'] ); ?>">
							<?php echo esc_html( $v['name'] ); ?>
						</option>
					<?php endforeach; ?>
				</select>
				<button
					class="st-btn st-btn--primary"
					id="st-gen-playoffs-btn"
					data-tournament="<?php echo esc_attr( (string) $tournament['id'] ); ?>"
					data-nonce="<?php echo esc_attr( wp_create_nonce( 'wp_rest' ) ); ?>"
					data-endpoint="playoffs"
				>
					⚡ <?php esc_html_e( 'Generar Semi-finales', 'soccertrack' ); ?>
				</button>
			</div>
			<?php else : ?>
				<p><a href="<?php echo esc_url( home_url( '/panel/recintos/' ) ); ?>"><?php esc_html_e( '→ Crea un recinto primero', 'soccertrack' ); ?></a></p>
			<?php endif; ?>
		<?php else : ?>
			<p class="st-empty-msg"><?php esc_html_e( 'Las semi-finales estarán disponibles cuando todos los partidos de la fase regular estén finalizados.', 'soccertrack' ); ?></p>
		<?php endif; ?>

	<?php elseif ( ! $playoffs_status['has_finals'] ) : ?>
		<?php if ( $playoffs_status['both_sf_done'] ) : ?>
			<p style="margin-bottom:12px;color:#3C3A47">
				<?php esc_html_e( 'Semi-finales finalizadas. Puedes generar la Final y el partido por el 3.er puesto.', 'soccertrack' ); ?>
			</p>
			<?php if ( ! empty( $venues_for_select ) ) : ?>
			<div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap">
				<select id="st-playoff-venue-select" class="st-input" style="max-width:220px">
					<option value=""><?php esc_html_e( '— Seleccionar recinto —', 'soccertrack' ); ?></option>
					<?php foreach ( $venues_for_select as $v ) : ?>
						<option value="<?php echo esc_attr( (string) $v['id'] ); ?>">
							<?php echo esc_html( $v['name'] ); ?>
						</option>
					<?php endforeach; ?>
				</select>
				<button
					class="st-btn st-btn--primary"
					id="st-gen-playoffs-btn"
					data-tournament="<?php echo esc_attr( (string) $tournament['id'] ); ?>"
					data-nonce="<?php echo esc_attr( wp_create_nonce( 'wp_rest' ) ); ?>"
					data-endpoint="finals"
				>
					🏆 <?php esc_html_e( 'Generar Final y 3.er Puesto', 'soccertrack' ); ?>
				</button>
			</div>
			<?php endif; ?>
		<?php else : ?>
			<p class="st-empty-msg"><?php esc_html_e( 'La Final estará disponible cuando ambas semi-finales estén finalizadas.', 'soccertrack' ); ?></p>
		<?php endif; ?>

	<?php else : ?>
		<p style="color:#3CBC20;font-weight:600">✅ <?php esc_html_e( 'Play-offs completos. Final y 3.er puesto generados.', 'soccertrack' ); ?></p>
	<?php endif; ?>
</div>
<?php endif; ?>

<?php /* ── Bases del torneo (PDF) ─────────────────────────────────────── */ ?>
<div class="st-card">
	<div class="st-card-header">
		<h2 class="st-card-title">📄 <?php esc_html_e( 'Bases del torneo', 'soccertrack' ); ?></h2>
	</div>

	<?php if ( ! empty( $error ?? '' ) ) : ?>
		<div class="st-alert st-alert--error">⚠️ <?php echo esc_html( $error ); ?></div>
	<?php endif; ?>

	<form method="post" action="" enctype="multipart/form-data">
		<?php wp_nonce_field( 'st_save_bases_' . $tournament['id'] ); ?>
		<input type="hidden" name="st_save_bases" value="1">

		<?php /* Opción 1: subir archivo directo */ ?>
		<div class="st-field" style="margin-bottom:16px">
			<label for="st-bases-file" class="st-label">
				📤 <?php esc_html_e( 'Subir PDF desde tu computador', 'soccertrack' ); ?>
			</label>
			<input
				type="file"
				id="st-bases-file"
				name="bases_pdf_file"
				class="st-input"
				accept=".pdf,application/pdf"
			>
			<small class="st-hint"><?php esc_html_e( 'Solo archivos .pdf. Máx. según límite del servidor.', 'soccertrack' ); ?></small>
		</div>

		<?php /* Opción 2: pegar URL externa */ ?>
		<div class="st-field" style="margin-bottom:16px">
			<label for="st-bases-url" class="st-label">
				🔗 <?php esc_html_e( 'O pegar URL de un PDF ya publicado', 'soccertrack' ); ?>
			</label>
			<input
				type="url"
				id="st-bases-url"
				name="bases_pdf_url"
				class="st-input"
				placeholder="https://..."
				value="<?php echo esc_attr( $tournament['bases_pdf_url'] ?? '' ); ?>"
			>
			<small class="st-hint"><?php esc_html_e( 'Si subes un archivo, este campo se ignora.', 'soccertrack' ); ?></small>
		</div>

		<button type="submit" class="st-btn st-btn--primary">
			💾 <?php esc_html_e( 'Guardar bases', 'soccertrack' ); ?>
		</button>

		<?php if ( ! empty( $tournament['bases_pdf_url'] ) ) : ?>
		<p style="margin-top:14px;font-size:.85rem;color:#3C3A47">
			✅ <?php esc_html_e( 'Bases activas:', 'soccertrack' ); ?>
			<a href="<?php echo esc_url( $tournament['bases_pdf_url'] ); ?>" target="_blank" rel="noopener noreferrer">
				<?php echo esc_html( $tournament['bases_pdf_url'] ); ?>
			</a>
		</p>
		<?php endif; ?>
	</form>
</div>

<script>
( () => {
	const btn = document.getElementById( 'st-gen-fixture-btn' );
	if ( ! btn ) return;

	btn.addEventListener( 'click', async () => {
		const notice  = document.getElementById( 'st-fixture-notice' );
		const tid     = btn.dataset.tournament;
		const nonce   = btn.dataset.nonce;
		const venueId = parseInt( document.getElementById( 'st-venue-select' )?.value ?? '0', 10 );

		if ( ! venueId ) {
			notice.innerHTML = `<div class="st-alert st-alert--error"><?php esc_html_e( 'Selecciona un recinto antes de generar el fixture.', 'soccertrack' ); ?></div>`;
			return;
		}

		btn.disabled    = true;
		btn.textContent = '<?php esc_html_e( 'Generando…', 'soccertrack' ); ?>';

		try {
			const resp = await fetch( `<?php echo esc_url( get_rest_url() ); ?>soccertrack/v1/admin/tournament/${tid}/fixture`, {
				method:  'POST',
				headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': nonce },
				body:    JSON.stringify( { venue_id: venueId } ),
			} );

			const data = await resp.json();

			if ( ! resp.ok ) {
				notice.innerHTML = `<div class="st-alert st-alert--error">${data.message ?? 'Error'}</div>`;
				btn.disabled = false;
				btn.textContent = '⚡ <?php esc_html_e( 'Generar fixture', 'soccertrack' ); ?>';
			} else {
				notice.innerHTML = `<div class="st-alert st-alert--success"><?php esc_html_e( 'Fixture generado:', 'soccertrack' ); ?> ${data.matches_created} <?php esc_html_e( 'partidos. Recargando…', 'soccertrack' ); ?></div>`;
				setTimeout( () => location.reload(), 1500 );
			}
		} catch ( e ) {
			notice.innerHTML = `<div class="st-alert st-alert--error">${e.message}</div>`;
			btn.disabled = false;
		}
	} );
} )();

( () => {
	const btn = document.getElementById( 'st-gen-playoffs-btn' );
	if ( ! btn ) return;

	btn.addEventListener( 'click', async () => {
		const notice   = document.getElementById( 'st-playoffs-notice' );
		const tid      = btn.dataset.tournament;
		const nonce    = btn.dataset.nonce;
		const endpoint = btn.dataset.endpoint; // 'playoffs' o 'finals'
		const venueId  = parseInt( document.getElementById( 'st-playoff-venue-select' )?.value ?? '0', 10 );

		if ( ! venueId ) {
			notice.innerHTML = `<div class="st-alert st-alert--error"><?php esc_html_e( 'Selecciona un recinto.', 'soccertrack' ); ?></div>`;
			return;
		}

		btn.disabled    = true;
		btn.textContent = '<?php esc_html_e( 'Generando…', 'soccertrack' ); ?>';

		try {
			const resp = await fetch( `<?php echo esc_url( get_rest_url() ); ?>soccertrack/v1/admin/tournament/${tid}/${endpoint}`, {
				method:  'POST',
				headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': nonce },
				body:    JSON.stringify( { venue_id: venueId } ),
			} );

			const data = await resp.json();

			if ( ! resp.ok ) {
				notice.innerHTML = `<div class="st-alert st-alert--error">${data.message ?? 'Error'}</div>`;
				btn.disabled = false;
				btn.textContent = btn.dataset.endpoint === 'playoffs' ? '⚡ <?php esc_html_e( 'Generar Semi-finales', 'soccertrack' ); ?>' : '🏆 <?php esc_html_e( 'Generar Final y 3.er Puesto', 'soccertrack' ); ?>';
			} else {
				notice.innerHTML = `<div class="st-alert st-alert--success"><?php esc_html_e( 'Partidos generados:', 'soccertrack' ); ?> ${data.matches_created}. <?php esc_html_e( 'Recargando…', 'soccertrack' ); ?></div>`;
				setTimeout( () => location.reload(), 1500 );
			}
		} catch ( e ) {
			notice.innerHTML = `<div class="st-alert st-alert--error">${e.message}</div>`;
			btn.disabled = false;
		}
	} );
} )();
</script>
