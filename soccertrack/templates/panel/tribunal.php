<?php
/**
 * Tribunal Disciplinario — vista del coordinador.
 *
 * Variables: $tournaments, $sanctions, $tournament_id, $round_number,
 *            $available_rounds, $round_events, $teams_with_players,
 *            $available_teams, $team_filter,
 *            $player_history, $yellow_accumulation, $yellows_per_suspension,
 *            $notice, $error, $page_title
 */
defined( 'ABSPATH' ) || exit;

/**
 * Muestra las observaciones de forma compacta:
 *  - Sin texto   → '—'
 *  - ≤ 90 chars  → texto completo en cursiva
 *  - > 90 chars  → primeros 90 chars + '…' + botón popup
 */
$render_obs = static function ( string $obs, string $player, string $reason ): string {
	if ( $obs === '' ) {
		return '—';
	}
	$preview_limit = 90;
	if ( mb_strlen( $obs ) <= $preview_limit ) {
		return '<span style="font-size:.78rem;color:#555;font-style:italic">'
			. nl2br( esc_html( $obs ) )
			. '</span>';
	}
	$preview = esc_html( mb_substr( $obs, 0, $preview_limit ) ) . '…';
	$btn_label = esc_html__( 'Ver detalle', 'soccertrack' );
	return '<span style="font-size:.78rem;color:#555;font-style:italic">'
		. $preview
		. '</span> '
		. '<button type="button" class="st-btn st-btn--sm st-obs-btn"'
		. ' data-player="' . esc_attr( $player ) . '"'
		. ' data-reason="' . esc_attr( $reason ) . '"'
		. ' data-obs="' . esc_attr( $obs ) . '">'
		. '📋 ' . $btn_label
		. '</button>';
};
?>

<div class="st-page-header">
	<h1 class="st-page-title">🟥 <?php esc_html_e( 'Tribunal Disciplinario', 'soccertrack' ); ?></h1>
</div>

<?php if ( $notice === 'added' ) : ?>
	<div class="st-alert st-alert--success">✅ <?php esc_html_e( 'Sanción registrada correctamente.', 'soccertrack' ); ?></div>
<?php elseif ( $notice === 'resolved' ) : ?>
	<div class="st-alert st-alert--success">✅ <?php esc_html_e( 'Sanción marcada como cumplida.', 'soccertrack' ); ?></div>
<?php elseif ( $notice === 'edited' ) : ?>
	<div class="st-alert st-alert--success">✅ <?php esc_html_e( 'Sanción actualizada correctamente.', 'soccertrack' ); ?></div>
<?php endif; ?>

<?php if ( $error ) : ?>
	<div class="st-alert st-alert--error">⚠️ <?php echo esc_html( $error ); ?></div>
<?php endif; ?>

<?php /* ── Selectores de torneo y fecha ─────────────────────────────── */ ?>
<form method="get" class="st-filter-bar" style="margin-bottom:20px">
	<input type="hidden" name="st_panel_page" value="1">
	<input type="hidden" name="st_panel_vista" value="tribunal">

	<label class="st-label"><?php esc_html_e( 'Torneo:', 'soccertrack' ); ?></label>
	<select name="tournament_id" class="st-input" onchange="this.form.submit()">
		<option value=""><?php esc_html_e( '— Seleccionar torneo —', 'soccertrack' ); ?></option>
		<?php foreach ( $tournaments as $t ) : ?>
			<option value="<?php echo esc_attr( (string) $t['id'] ); ?>" <?php selected( (int) $t['id'], $tournament_id ); ?>>
				<?php echo esc_html( $t['name'] ); ?>
			</option>
		<?php endforeach; ?>
	</select>

	<?php if ( $tournament_id && ! empty( $available_rounds ) ) : ?>
	<label class="st-label"><?php esc_html_e( 'Fecha:', 'soccertrack' ); ?></label>
	<select name="round_number" class="st-input" onchange="this.form.submit()">
		<option value=""><?php esc_html_e( '— Seleccionar fecha —', 'soccertrack' ); ?></option>
		<?php foreach ( $available_rounds as $r ) : ?>
			<option value="<?php echo esc_attr( (string) $r ); ?>" <?php selected( (int) $r, $round_number ); ?>>
				<?php printf( esc_html__( 'Fecha %d', 'soccertrack' ), (int) $r ); ?>
			</option>
		<?php endforeach; ?>
	</select>
	<?php endif; ?>

	<?php if ( $tournament_id && ! empty( $available_teams ) ) : ?>
	<label class="st-label"><?php esc_html_e( 'Equipo:', 'soccertrack' ); ?></label>
	<select name="team_filter" class="st-input" onchange="this.form.submit()">
		<option value=""><?php esc_html_e( '— Todos los equipos —', 'soccertrack' ); ?></option>
		<?php foreach ( $available_teams as $team ) : ?>
			<option value="<?php echo esc_attr( (string) $team['id'] ); ?>" <?php selected( (int) $team['id'], $team_filter ); ?>>
				<?php echo esc_html( $team['name'] ); ?>
			</option>
		<?php endforeach; ?>
	</select>
	<?php endif; ?>
</form>

<?php if ( $tournament_id ) : ?>

<?php /* ── Incidentes de la fecha seleccionada (revisión tribunal) ─── */ ?>
<?php if ( $round_number ) : ?>
<div class="st-card" style="border-left:4px solid #c0392b;margin-bottom:20px">
	<div class="st-card-header">
		<h2 class="st-card-title">
			🟥 <?php printf( esc_html__( 'Incidentes — Fecha %d', 'soccertrack' ), $round_number ); ?>
		</h2>
		<span style="font-size:.82rem;color:#888">
			<?php esc_html_e( 'Tarjetas para resolución del tribunal', 'soccertrack' ); ?>
		</span>
	</div>

	<?php if ( empty( $round_events ) ) : ?>
		<p class="st-empty-msg"><?php esc_html_e( 'Sin incidentes de tarjetas en esta fecha.', 'soccertrack' ); ?></p>
	<?php else : ?>
	<div class="st-table-wrap">
		<table class="st-table">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Tipo', 'soccertrack' ); ?></th>
					<th><?php esc_html_e( 'Jugador', 'soccertrack' ); ?></th>
					<th><?php esc_html_e( 'Equipo', 'soccertrack' ); ?></th>
					<th><?php esc_html_e( 'Partido', 'soccertrack' ); ?></th>
					<th style="text-align:center"><?php esc_html_e( 'Min.', 'soccertrack' ); ?></th>
				</tr>
			</thead>
			<tbody>
			<?php foreach ( $round_events as $ev ) : ?>
				<tr>
					<td>
						<?php if ( $ev['event_type'] === 'red_card' ) : ?>
							<span class="st-badge st-badge--danger">🟥 <?php esc_html_e( 'Roja', 'soccertrack' ); ?></span>
						<?php else : ?>
							<span class="st-badge st-badge--warning">🟨 <?php esc_html_e( 'Amarilla', 'soccertrack' ); ?></span>
						<?php endif; ?>
					</td>
					<td><strong><?php echo esc_html( $ev['first_name'] . ' ' . $ev['last_name'] ); ?></strong></td>
					<td><?php echo esc_html( $ev['team_name'] ); ?></td>
					<td style="font-size:.84rem;color:#666">
						<?php echo esc_html( $ev['home_team'] . ' ' . (string) $ev['home_score'] . ' - ' . (string) $ev['away_score'] . ' ' . $ev['away_team'] ); ?>
					</td>
					<td style="text-align:center"><?php echo esc_html( (string) $ev['minute'] ); ?>'</td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
	</div>
	<?php endif; ?>
</div>
<?php endif; ?>

<?php /* ── Acumulación de tarjetas amarillas ──────────────────────── */ ?>
<?php if ( $tournament_id && ! empty( $yellow_accumulation ) ) : ?>
<div class="st-card" style="border-left:4px solid #f39c12;margin-bottom:20px">
	<div class="st-card-header">
		<h2 class="st-card-title">
			🟨 <?php esc_html_e( 'Acumulación de tarjetas amarillas', 'soccertrack' ); ?>
		</h2>
		<span style="font-size:.82rem;color:#888">
			<?php printf(
				/* translators: %d: número de amarillas configurado */
				esc_html__( 'Cada %d amarillas = 1 fecha de suspensión automática', 'soccertrack' ),
				(int) ( $yellows_per_suspension ?? 3 )
			); ?>
		</span>
	</div>
	<div class="st-table-wrap">
		<table class="st-table">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Jugador', 'soccertrack' ); ?></th>
					<th><?php esc_html_e( 'Equipo', 'soccertrack' ); ?></th>
					<th style="text-align:center"><?php esc_html_e( 'Total amarillas', 'soccertrack' ); ?></th>
					<th><?php esc_html_e( 'Ciclo actual', 'soccertrack' ); ?></th>
				</tr>
			</thead>
			<tbody>
			<?php
			$threshold = max( 2, (int) ( $yellows_per_suspension ?? 3 ) );
			foreach ( $yellow_accumulation as $ya ) :
				$in_cycle = (int) $ya['in_cycle'];
				// 0 in_cycle after at least 1 full cycle means they just completed one
				$display_cycle = ( $in_cycle === 0 && (int) $ya['yellow_count'] >= $threshold ) ? $threshold : $in_cycle;
				$pips      = str_repeat( '🟨', $display_cycle ) . str_repeat( '⬜', $threshold - $display_cycle );
				$danger    = ( $display_cycle >= $threshold - 1 );
				$row_style = $danger ? 'background:rgba(243,156,18,.06)' : '';
			?>
				<tr style="<?php echo esc_attr( $row_style ); ?>">
					<td>
						<strong><?php echo esc_html( $ya['first_name'] . ' ' . $ya['last_name'] ); ?></strong>
					</td>
					<td><?php echo esc_html( $ya['team_name'] ); ?></td>
					<td style="text-align:center;font-weight:700">
						<?php echo esc_html( (string) $ya['yellow_count'] ); ?>
					</td>
					<td>
						<span style="font-size:1.15rem;letter-spacing:2px"><?php echo $pips; // phpcs:ignore ?></span>
						<span style="font-size:.78rem;color:<?php echo $danger ? '#c0392b' : '#888'; ?>;margin-left:6px;font-weight:<?php echo $danger ? '700' : 'normal'; ?>">
							<?php echo esc_html( (string) $display_cycle ); ?> / <?php echo esc_html( (string) $threshold ); ?>
							<?php if ( $display_cycle === $threshold ) : ?>
							— <em><?php esc_html_e( 'suspensión generada', 'soccertrack' ); ?></em>
							<?php elseif ( $display_cycle === $threshold - 1 ) : ?>
							— <em><?php esc_html_e( 'próxima amarilla suspende', 'soccertrack' ); ?></em>
							<?php endif; ?>
						</span>
					</td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
	</div>
</div>
<?php elseif ( $tournament_id ) : ?>
<div class="st-card" style="border-left:4px solid #f39c12;margin-bottom:20px">
	<div class="st-card-header">
		<h2 class="st-card-title">🟨 <?php esc_html_e( 'Acumulación de tarjetas amarillas', 'soccertrack' ); ?></h2>
	</div>
	<p class="st-empty-msg"><?php esc_html_e( 'Sin tarjetas amarillas registradas en este torneo.', 'soccertrack' ); ?></p>
</div>
<?php endif; ?>

<?php /* ── Registrar sanción ──────────────────────────────────────── */ ?>
<div class="st-card">
	<div class="st-card-header">
		<h2 class="st-card-title">➕ <?php esc_html_e( 'Registrar sanción', 'soccertrack' ); ?></h2>
	</div>

	<?php if ( empty( $teams_with_players ) ) : ?>
		<p class="st-empty-msg"><?php esc_html_e( 'No hay jugadores inscritos en este torneo.', 'soccertrack' ); ?></p>
	<?php else : ?>
<?php
	$fp_player_id = (int) ( $form_player_id ?? 0 );
	$fp_reason    = $form_reason ?? '';
	$fp_matches   = $form_matches ?? '';

	$reason_options = [
		__( 'Acumulación de tarjetas amarillas', 'soccertrack' ) => '🟡 ' . __( 'Acumulación de tarjetas amarillas', 'soccertrack' ),
		__( 'Tarjeta roja directa', 'soccertrack' )              => '🔴 ' . __( 'Tarjeta roja directa', 'soccertrack' ),
		__( 'Conducta violenta — expulsión directa', 'soccertrack' ) => '🔴 ' . __( 'Conducta violenta — expulsión directa', 'soccertrack' ),
		__( 'Lenguaje ofensivo al árbitro', 'soccertrack' )      => '🗣 ' . __( 'Lenguaje ofensivo al árbitro', 'soccertrack' ),
		__( 'Resolución Tribunal Disciplinario', 'soccertrack' ) => '⚖️ ' . __( 'Resolución Tribunal Disciplinario', 'soccertrack' ),
		'otro' => __( 'Otro (ver observaciones)', 'soccertrack' ),
	];
	?>
	<form method="post" action="" novalidate id="st-sanction-form">
		<?php wp_nonce_field( 'st_add_sanction' ); ?>
		<input type="hidden" name="st_add_sanction" value="1">
		<input type="hidden" name="tournament_id" value="<?php echo esc_attr( (string) $tournament_id ); ?>">
		<input type="hidden" name="team_id" id="st-sanction-team-id" value="">

		<div class="st-form-inline">

			<div class="st-field" style="flex:2;min-width:200px">
				<label for="st-sanction-player" class="st-label">
					<?php esc_html_e( 'Jugador *', 'soccertrack' ); ?>
				</label>
				<select id="st-sanction-player" name="player_id" class="st-input" required>
					<option value=""><?php esc_html_e( '— Seleccionar jugador —', 'soccertrack' ); ?></option>
					<?php foreach ( $teams_with_players as $group ) : ?>
						<optgroup label="<?php echo esc_attr( $group['team']['name'] ); ?>">
							<?php foreach ( $group['players'] as $p ) : ?>
								<option
									value="<?php echo esc_attr( (string) $p['id'] ); ?>"
									data-team-id="<?php echo esc_attr( (string) $group['team']['id'] ); ?>"
									<?php selected( $fp_player_id, (int) $p['id'] ); ?>>
									<?php
									$dorsal_lbl = ! empty( $p['dorsal'] ) ? $p['dorsal'] . ' — ' : '';
									echo esc_html( $dorsal_lbl . "{$p['first_name']} {$p['last_name']}" );
									?>
								</option>
							<?php endforeach; ?>
						</optgroup>
					<?php endforeach; ?>
				</select>
			</div>

			<div class="st-field" style="flex:2;min-width:180px">
				<label for="st-sanction-reason" class="st-label">
					<?php esc_html_e( 'Motivo *', 'soccertrack' ); ?>
				</label>
				<select id="st-sanction-reason" name="reason" class="st-input" required>
					<option value=""><?php esc_html_e( '— Seleccionar motivo —', 'soccertrack' ); ?></option>
					<?php foreach ( $reason_options as $val => $label ) : ?>
						<option value="<?php echo esc_attr( $val ); ?>" <?php selected( $fp_reason, $val ); ?>>
							<?php echo esc_html( $label ); ?>
						</option>
					<?php endforeach; ?>
				</select>
			</div>

			<div class="st-field" style="max-width:110px">
				<label for="st-sanction-matches" class="st-label">
					<?php esc_html_e( 'Fechas *', 'soccertrack' ); ?>
				</label>
				<input
					type="number"
					id="st-sanction-matches"
					name="ban_matches"
					class="st-input"
					min="1"
					max="20"
					placeholder="1"
					required
					value="<?php echo esc_attr( (string) $fp_matches ); ?>"
				>
			</div>

			<div class="st-field" style="justify-content:flex-end;align-self:flex-end">
				<button type="submit" class="st-btn st-btn--primary">
					🟥 <?php esc_html_e( 'Registrar', 'soccertrack' ); ?>
				</button>
			</div>
		</div>

		<div class="st-field" style="margin-top:8px">
			<label for="st-sanction-obs" class="st-label">
				<?php esc_html_e( 'Observaciones / Resolución del Tribunal', 'soccertrack' ); ?>
				<span style="font-weight:400;color:#888"> (<?php esc_html_e( 'opcional', 'soccertrack' ); ?>)</span>
			</label>
			<textarea
				id="st-sanction-obs"
				name="observaciones"
				class="st-input"
				rows="3"
				placeholder="<?php esc_attr_e( 'Descripción del incidente, testigos, resolución adoptada…', 'soccertrack' ); ?>"
				style="resize:vertical"
			></textarea>
		</div>
	</form>
	<?php endif; ?>
</div>

<?php /* ── Tabla de sanciones ───────────────────────────────────────── */ ?>
<div class="st-card">
	<div class="st-card-header">
		<h2 class="st-card-title"><?php esc_html_e( 'Sanciones del torneo', 'soccertrack' ); ?></h2>
	</div>

	<?php if ( empty( $sanctions ) ) : ?>
		<p class="st-empty-msg"><?php esc_html_e( 'Sin sanciones registradas en este torneo.', 'soccertrack' ); ?></p>
	<?php else : ?>
	<div class="st-table-wrap">
		<table class="st-table">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Jugador', 'soccertrack' ); ?></th>
					<th><?php esc_html_e( 'Equipo', 'soccertrack' ); ?></th>
					<th><?php esc_html_e( 'Motivo', 'soccertrack' ); ?></th>
					<th><?php esc_html_e( 'Observaciones', 'soccertrack' ); ?></th>
					<th style="text-align:center"><?php esc_html_e( 'Fechas', 'soccertrack' ); ?></th>
					<th style="text-align:center"><?php esc_html_e( 'Rest.', 'soccertrack' ); ?></th>
					<th><?php esc_html_e( 'Estado', 'soccertrack' ); ?></th>
					<th><?php esc_html_e( 'Registrada', 'soccertrack' ); ?></th>
					<th></th>
				</tr>
			</thead>
			<tbody>
			<?php foreach ( $sanctions as $s ) : ?>
				<tr>
					<td><strong><?php echo esc_html( $s['first_name'] . ' ' . $s['last_name'] ); ?></strong></td>
					<td><?php echo esc_html( $s['team_name'] ?? '—' ); ?></td>
					<td><?php echo esc_html( $s['reason'] ); ?></td>
					<td><?php echo $render_obs( (string) ( $s['observaciones'] ?? '' ), $s['first_name'] . ' ' . $s['last_name'], $s['reason'] ); // phpcs:ignore ?></td>
					<td style="text-align:center"><?php echo esc_html( (string) $s['ban_days_or_matches'] ); ?></td>
					<td style="text-align:center;font-weight:<?php echo $s['remaining_matches'] > 0 ? '700' : 'normal'; ?>;color:<?php echo $s['remaining_matches'] > 0 ? '#c0392b' : 'inherit'; ?>">
						<?php echo esc_html( (string) $s['remaining_matches'] ); ?>
					</td>
					<td>
						<span class="st-badge st-badge--<?php echo $s['status'] === 'active' ? 'danger' : 'success'; ?>">
							<?php echo $s['status'] === 'active'
								? esc_html__( 'Activa', 'soccertrack' )
								: esc_html__( 'Cumplida', 'soccertrack' ); ?>
						</span>
						<?php if ( $s['status'] === 'served' && ! empty( $s['resolved_at'] ) ) : ?>
						<div style="font-size:.72rem;color:#888;margin-top:2px">
							<?php echo esc_html( wp_date( 'd/m/Y', strtotime( $s['resolved_at'] ) ) ); ?>
						</div>
						<?php endif; ?>
					</td>
					<td style="font-size:.82rem;color:#888;white-space:nowrap">
						<?php echo esc_html( wp_date( 'd/m/Y', strtotime( $s['created_at'] ) ) ); ?>
					</td>
					<td>
						<?php if ( $s['status'] === 'active' ) : ?>
					<div style="display:flex;gap:4px;flex-wrap:wrap">
						<button
							type="button"
							class="st-btn st-btn--sm st-btn--secondary st-edit-sanction-btn"
							data-id="<?php echo esc_attr( (string) $s['id'] ); ?>"
							data-matches="<?php echo esc_attr( (string) $s['ban_days_or_matches'] ); ?>"
							data-obs="<?php echo esc_attr( (string) ( $s['observaciones'] ?? '' ) ); ?>"
							data-player="<?php echo esc_attr( $s['first_name'] . ' ' . $s['last_name'] ); ?>"
						>
							✏️ <?php esc_html_e( 'Editar', 'soccertrack' ); ?>
						</button>
						<form method="post" action="?st_panel_page=1&st_panel_vista=tribunal&tournament_id=<?php echo esc_attr( (string) $tournament_id ); ?><?php echo $team_filter ? '&team_filter=' . esc_attr( (string) $team_filter ) : ''; ?>"
							  onsubmit="return confirm('<?php esc_attr_e( '¿Marcar esta sanción como cumplida?', 'soccertrack' ); ?>')">
							<?php wp_nonce_field( 'st_resolve_sanction' ); ?>
							<input type="hidden" name="st_resolve_sanction" value="1">
							<input type="hidden" name="sanction_id" value="<?php echo esc_attr( (string) $s['id'] ); ?>">
							<button type="submit" class="st-btn st-btn--sm st-btn--secondary">
								✅ <?php esc_html_e( 'Cumplida', 'soccertrack' ); ?>
							</button>
						</form>
					</div>
					<?php endif; ?>
					</td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
	</div>
	<?php endif; ?>
</div>

<?php /* ── Historial completo por jugador ─────────────────────────────── */ ?>
<?php if ( ! empty( $player_history ) ) : ?>
<div class="st-card">
	<div class="st-card-header">
		<h2 class="st-card-title">📋 <?php esc_html_e( 'Historial por jugador', 'soccertrack' ); ?></h2>
		<span style="font-size:.82rem;color:#888">
			<?php esc_html_e( 'Sanciones en todos los torneos', 'soccertrack' ); ?>
		</span>
	</div>

	<div class="st-history-accordion">
	<?php foreach ( $player_history as $pid => $ph ) : ?>
		<?php
		$total_sanctions = count( $ph['sanctions'] );
		$active_count    = count( array_filter( $ph['sanctions'], fn( $s ) => $s['status'] === 'active' ) );
		?>
		<details class="st-history-item" <?php echo ( $active_count > 0 ) ? 'open' : ''; ?>>
			<summary class="st-history-summary">
				<span class="st-history-player">
					👤 <?php echo esc_html( $ph['name'] ); ?>
				</span>
				<span class="st-history-meta">
					<?php echo esc_html( (string) $total_sanctions ); ?>
					<?php esc_html_e( 'sanción(es)', 'soccertrack' ); ?>
					<?php if ( $active_count > 0 ) : ?>
					· <span style="color:#c0392b;font-weight:700">
						<?php echo esc_html( (string) $active_count ); ?> <?php esc_html_e( 'activa(s)', 'soccertrack' ); ?>
					</span>
					<?php endif; ?>
				</span>
			</summary>

			<div class="st-history-body">
				<table class="st-table st-table--compact">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Torneo', 'soccertrack' ); ?></th>
							<th><?php esc_html_e( 'Equipo', 'soccertrack' ); ?></th>
							<th><?php esc_html_e( 'Motivo', 'soccertrack' ); ?></th>
							<th><?php esc_html_e( 'Observaciones', 'soccertrack' ); ?></th>
							<th style="text-align:center"><?php esc_html_e( 'Fechas', 'soccertrack' ); ?></th>
							<th><?php esc_html_e( 'Estado', 'soccertrack' ); ?></th>
							<th><?php esc_html_e( 'Fecha', 'soccertrack' ); ?></th>
						</tr>
					</thead>
					<tbody>
					<?php foreach ( $ph['sanctions'] as $hs ) : ?>
						<tr>
							<td style="font-weight:600"><?php echo esc_html( $hs['tournament_name'] ); ?></td>
							<td><?php echo esc_html( $hs['team_name'] ?? '—' ); ?></td>
							<td><?php echo esc_html( $hs['reason'] ); ?></td>
							<td><?php echo $render_obs( (string) ( $hs['observaciones'] ?? '' ), $ph['name'], $hs['reason'] ); // phpcs:ignore ?></td>
							<td style="text-align:center"><?php echo esc_html( (string) $hs['ban_days_or_matches'] ); ?></td>
							<td>
								<span class="st-badge st-badge--<?php echo $hs['status'] === 'active' ? 'danger' : 'success'; ?>">
									<?php echo $hs['status'] === 'active'
										? esc_html__( 'Activa', 'soccertrack' )
										: esc_html__( 'Cumplida', 'soccertrack' ); ?>
								</span>
							</td>
							<td style="font-size:.8rem;color:#888;white-space:nowrap">
								<?php echo esc_html( wp_date( 'd/m/Y', strtotime( $hs['created_at'] ) ) ); ?>
								<?php if ( $hs['status'] === 'served' && ! empty( $hs['resolved_at'] ) ) : ?>
								<br><span style="color:#27ae60">✓ <?php echo esc_html( wp_date( 'd/m/Y', strtotime( $hs['resolved_at'] ) ) ); ?></span>
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			</div>
		</details>
	<?php endforeach; ?>
	</div>
</div>
<?php endif; ?>

<?php /* ── Modal editar sanción (apelación) ─────────────────────────── */ ?>
<div id="st-edit-sanction-overlay" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:9999;align-items:center;justify-content:center">
	<div style="background:#fff;border-radius:8px;padding:28px 32px;min-width:360px;max-width:480px;width:90%;box-shadow:0 8px 32px rgba(0,0,0,.18)">
		<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:16px">
			<h3 style="margin:0;font-size:1rem">✏️ <?php esc_html_e( 'Editar sanción', 'soccertrack' ); ?></h3>
			<button type="button" id="st-edit-sanction-close" style="background:none;border:none;font-size:1.3rem;cursor:pointer;color:#555">✕</button>
		</div>
		<div id="st-edit-sanction-player" style="font-size:.85rem;color:#666;margin-bottom:16px;font-style:italic"></div>
		<form method="post" action="?st_panel_page=1&st_panel_vista=tribunal&tournament_id=<?php echo esc_attr( (string) $tournament_id ); ?><?php echo $team_filter ? '&team_filter=' . esc_attr( (string) $team_filter ) : ''; ?>" id="st-edit-sanction-form">
			<?php wp_nonce_field( 'st_edit_sanction' ); ?>
			<input type="hidden" name="st_edit_sanction" value="1">
			<input type="hidden" name="sanction_id" id="st-edit-sanction-id" value="">
			<div style="margin-bottom:14px">
				<label style="display:block;font-size:.85rem;font-weight:600;margin-bottom:4px">
					<?php esc_html_e( 'Fechas de sanción *', 'soccertrack' ); ?>
				</label>
				<input
					type="number"
					name="ban_matches"
					id="st-edit-sanction-matches"
					class="st-input"
					min="1"
					max="20"
					required
					style="max-width:100px"
				>
				<small style="display:block;margin-top:4px;color:#888"><?php esc_html_e( 'Nuevo total de fechas (puede ser menor al original por apelación)', 'soccertrack' ); ?></small>
			</div>
			<div style="margin-bottom:18px">
				<label style="display:block;font-size:.85rem;font-weight:600;margin-bottom:4px">
					<?php esc_html_e( 'Resolución / Observaciones', 'soccertrack' ); ?>
				</label>
				<textarea
					name="observaciones"
					id="st-edit-sanction-obs"
					class="st-input"
					rows="3"
					maxlength="1000"
					placeholder="<?php esc_attr_e( 'Ej: Apelación aceptada. Se reduce la sanción a 1 fecha.', 'soccertrack' ); ?>"
					style="resize:vertical;width:100%"
				></textarea>
			</div>
			<div style="display:flex;gap:8px;justify-content:flex-end">
				<button type="button" id="st-edit-sanction-cancel" class="st-btn st-btn--secondary">
					<?php esc_html_e( 'Cancelar', 'soccertrack' ); ?>
				</button>
				<button type="submit" class="st-btn st-btn--primary">
					💾 <?php esc_html_e( 'Guardar cambios', 'soccertrack' ); ?>
				</button>
			</div>
		</form>
	</div>
</div>

<?php /* ── Popup de observaciones ──────────────────────────────────────── */ ?>
<div id="st-obs-overlay" class="st-obs-overlay" aria-hidden="true" role="dialog" aria-modal="true" aria-labelledby="st-obs-title">
	<div class="st-obs-modal">
		<div class="st-obs-modal__header">
			<div>
				<div id="st-obs-title" class="st-obs-modal__player"></div>
				<div id="st-obs-reason" class="st-obs-modal__reason"></div>
			</div>
			<button type="button" class="st-obs-modal__close" id="st-obs-close" aria-label="<?php esc_attr_e( 'Cerrar', 'soccertrack' ); ?>">✕</button>
		</div>
		<div class="st-obs-modal__body">
			<p class="st-obs-modal__label"><?php esc_html_e( 'Observaciones / Resolución del Tribunal', 'soccertrack' ); ?></p>
			<div id="st-obs-text" class="st-obs-modal__text"></div>
		</div>
	</div>
</div>

<script>
( () => {
	// Sincronizar team_id oculto cuando el coordinador selecciona un jugador.
	const playerSelect = document.getElementById( 'st-sanction-player' );
	const teamIdInput  = document.getElementById( 'st-sanction-team-id' );
	if ( playerSelect && teamIdInput ) {
		const sync = () => {
			const opt = playerSelect.options[ playerSelect.selectedIndex ];
			teamIdInput.value = opt ? ( opt.dataset.teamId ?? '' ) : '';
		};
		playerSelect.addEventListener( 'change', sync );
		sync(); // Inicializar si hay valor pre-seleccionado.
	}

	// Anti-doble-envío: deshabilitar el botón al primer submit.
	const sanctionForm = document.getElementById( 'st-sanction-form' );
	if ( sanctionForm ) {
		sanctionForm.addEventListener( 'submit', function () {
			const btn = this.querySelector( 'button[type="submit"]' );
			if ( btn ) {
				btn.disabled = true;
				btn.textContent = '⏳ Registrando…';
			}
		} );
	}
} )();
</script>

<script>
( () => {
	const overlay = document.getElementById( 'st-obs-overlay' );
	const title   = document.getElementById( 'st-obs-title' );
	const reason  = document.getElementById( 'st-obs-reason' );
	const text    = document.getElementById( 'st-obs-text' );
	const btnClose = document.getElementById( 'st-obs-close' );

	const open = ( player, reasonTxt, obs ) => {
		title.textContent  = '👤 ' + player;
		reason.textContent = reasonTxt;
		// Convertir saltos de línea a <br>
		text.innerHTML = obs.replace( /&/g, '&amp;' )
		                    .replace( /</g, '&lt;' )
		                    .replace( />/g, '&gt;' )
		                    .replace( /\n/g, '<br>' );
		overlay.removeAttribute( 'aria-hidden' );
		overlay.classList.add( 'is-open' );
		btnClose.focus();
	};

	const close = () => {
		overlay.setAttribute( 'aria-hidden', 'true' );
		overlay.classList.remove( 'is-open' );
	};

	// Delegación de eventos — captura todos los botones .st-obs-btn actuales y futuros.
	document.addEventListener( 'click', e => {
		const btn = e.target.closest( '.st-obs-btn' );
		if ( btn ) {
			open( btn.dataset.player, btn.dataset.reason, btn.dataset.obs );
			return;
		}
		if ( e.target === overlay ) {
			close();
		}
	} );

	btnClose.addEventListener( 'click', close );

	document.addEventListener( 'keydown', e => {
		if ( e.key === 'Escape' && overlay.classList.contains( 'is-open' ) ) {
			close();
		}
	} );
} )();
</script>

<script>
( () => {
	const overlay      = document.getElementById( 'st-edit-sanction-overlay' );
	const idInput      = document.getElementById( 'st-edit-sanction-id' );
	const matchesInput = document.getElementById( 'st-edit-sanction-matches' );
	const obsInput     = document.getElementById( 'st-edit-sanction-obs' );
	const playerEl     = document.getElementById( 'st-edit-sanction-player' );
	const btnClose     = document.getElementById( 'st-edit-sanction-close' );
	const btnCancel    = document.getElementById( 'st-edit-sanction-cancel' );

	const open = ( btn ) => {
		idInput.value        = btn.dataset.id;
		matchesInput.value   = btn.dataset.matches;
		obsInput.value       = btn.dataset.obs ?? '';
		playerEl.textContent = '👤 ' + ( btn.dataset.player ?? '' );
		overlay.style.display = 'flex';
		matchesInput.focus();
	};

	const close = () => { overlay.style.display = 'none'; };

	document.addEventListener( 'click', e => {
		const btn = e.target.closest( '.st-edit-sanction-btn' );
		if ( btn ) { open( btn ); return; }
		if ( e.target === overlay ) { close(); }
	} );

	btnClose?.addEventListener( 'click', close );
	btnCancel?.addEventListener( 'click', close );

	document.addEventListener( 'keydown', e => {
		if ( e.key === 'Escape' && overlay.style.display !== 'none' ) close();
	} );
} )();
</script>

<?php endif; // fin if $tournament_id ?>
