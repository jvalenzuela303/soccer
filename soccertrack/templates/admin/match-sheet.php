<?php
/**
 * Planilla Digital del Árbitro.
 *
 * Acceso: árbitros (ds_arbitro) y coordinadores (ds_coordinador).
 *
 * Recibe:
 *   $match        array{id, home_team_id, away_team_id, round_number, match_datetime,
 *                        home_score, away_score, status, venue, court_name}
 *   $home_team    array{id, name, logo_url}
 *   $away_team    array{id, name, logo_url}
 *   $home_players array<array{id, first_name, last_name, dorsal, is_suspended}>
 *   $away_players array<array{id, first_name, last_name, dorsal, is_suspended}>
 *
 * @package SoccerTrack
 */

defined( 'ABSPATH' ) || exit;

$is_finished         = 'finished' === ( $match['status'] ?? '' );
$tournament_id       = (int) ( $match['tournament_id'] ?? 0 );
$can_enter_incidents = current_user_can( 'ds_enter_match_incidents' );
$can_close           = current_user_can( 'ds_close_match' );
$can_edit_incidents  = current_user_can( 'ds_edit_incidents' );
// Administrador y coordinador pueden editar la planilla aunque ya esté cerrada.
$can_reopen = current_user_can( 'manage_options' ) || current_user_can( 'ds_manage_tournaments' );
// $is_locked = bloqueado para edición (cerrado Y sin permiso de reapertura).
$is_locked = $is_finished && ! $can_reopen;
// Compatibilidad: si se pasa $can_edit explícitamente desde el contexto (modo embed), usarlo.
if ( isset( $can_edit ) && false === $can_edit ) {
	$can_enter_incidents = false;
	$can_close           = false;
	$can_edit_incidents  = false;
	$can_reopen          = false;
	$is_locked           = $is_finished;
}

function st_player_option( array $player ): string {
	$dorsal_prefix = ! empty( $player['dorsal'] ) ? $player['dorsal'] . ' — ' : '';
	$name          = esc_html( $dorsal_prefix . "{$player['first_name']} {$player['last_name']}" );
	$susp = (int) $player['is_suspended'] ? ' [SUSPENDIDO]' : '';
	return "<option value=\"{$player['id']}\" " . disabled( (bool) $player['is_suspended'], true, false ) . ">{$name}{$susp}</option>";
}
?>

<div class="st-admin-wrap" id="st-match-sheet-wrap">

	<h1 class="st-page-title">
		<?php esc_html_e( 'Planilla Arbitral', 'soccertrack' ); ?>
		— <?php esc_html_e( 'Fecha', 'soccertrack' ); ?> <?php echo esc_html( (string) $match['round_number'] ); ?>
	</h1>

	<?php if ( $is_finished && ! $can_reopen ) : ?>
		<div class="st-alert st-alert--warning">
			<?php esc_html_e( 'Este partido ya fue cerrado. El resultado no puede modificarse.', 'soccertrack' ); ?>
		</div>
	<?php elseif ( $is_finished && $can_reopen ) : ?>
		<div class="st-alert st-alert--info">
			⚠️ <?php esc_html_e( 'Partido cerrado. Como coordinador/administrador puedes editar y volver a guardar el resultado.', 'soccertrack' ); ?>
		</div>
	<?php endif; ?>

	<?php if ( ! $can_enter_incidents && ! $can_close ) : ?>
		<div class="st-alert st-alert--info" style="background:#e8f4fd;border-color:#90cdf4;color:#2c5282">
			👁 <?php esc_html_e( 'Estás viendo esta planilla en modo lectura. Solo árbitros, planilleros y coordinadores pueden registrar incidentes.', 'soccertrack' ); ?>
		</div>
	<?php elseif ( $can_enter_incidents && ! $can_close ) : ?>
		<div class="st-alert st-alert--info" style="background:#f0fdf4;border-color:#86efac;color:#166534">
			📋 <?php esc_html_e( 'Estás registrando como planillero. El árbitro debe revisar y cerrar el acta.', 'soccertrack' ); ?>
		</div>
	<?php endif; ?>

	<?php if ( empty( $home_players ) || empty( $away_players ) ) : ?>
		<div class="st-alert st-alert--warning">
			⚠️ <?php
			if ( empty( $home_players ) && empty( $away_players ) ) {
				esc_html_e( 'Ningún equipo tiene jugadores inscritos. Carga las nóminas antes de operar la planilla.', 'soccertrack' );
			} elseif ( empty( $home_players ) ) {
				printf(
					/* translators: %s = nombre equipo local */
					esc_html__( '%s (local) no tiene jugadores inscritos.', 'soccertrack' ),
					esc_html( $home_team['name'] )
				);
			} else {
				printf(
					/* translators: %s = nombre equipo visitante */
					esc_html__( '%s (visitante) no tiene jugadores inscritos.', 'soccertrack' ),
					esc_html( $away_team['name'] )
				);
			}
			?>
			<br><small>
				<?php
				if ( empty( $home_players ) ) {
					printf(
						'<a href="%s">%s</a> · ',
						esc_url( home_url( '/panel/equipo/' . $home_team['id'] . '/' ) ),
						esc_html__( 'Gestionar nómina local', 'soccertrack' )
					);
				}
				if ( empty( $away_players ) ) {
					printf(
						'<a href="%s">%s</a>',
						esc_url( home_url( '/panel/equipo/' . $away_team['id'] . '/' ) ),
						esc_html__( 'Gestionar nómina visitante', 'soccertrack' )
					);
				}
				?>
			</small>
		</div>
	<?php endif; ?>

	<?php /* ── Asignación de árbitro ─────────────────────────────────── */ ?>
	<?php if ( current_user_can( 'ds_manage_tournaments' ) ) : ?>
	<?php
	$current_ref_name = (string) ( $match['referee_name'] ?? '' );
	$has_ref          = $current_ref_name !== '';
	?>
	<div class="st-card" style="margin-bottom:16px">
		<div class="st-card-header" style="display:flex;align-items:center;justify-content:space-between;gap:12px">
			<h2 class="st-card-title" style="font-size:1rem;margin:0">⚖️ <?php esc_html_e( 'Árbitro del partido', 'soccertrack' ); ?></h2>
			<?php if ( $has_ref ) : ?>
				<span style="font-weight:600;color:#0E0C19"><?php echo esc_html( $current_ref_name ); ?></span>
				<button
					type="button"
					class="st-btn st-btn--secondary st-btn--sm"
					onclick="document.getElementById('st-referee-form').style.display=document.getElementById('st-referee-form').style.display==='none'?'block':'none'"
				>✏️ <?php esc_html_e( 'Editar', 'soccertrack' ); ?></button>
			<?php else : ?>
				<button
					type="button"
					class="st-btn st-btn--primary st-btn--sm"
					onclick="document.getElementById('st-referee-form').style.display='block';this.style.display='none'"
				>➕ <?php esc_html_e( 'Agregar árbitro', 'soccertrack' ); ?></button>
			<?php endif; ?>
		</div>

		<?php if ( ( $notice_ref ?? '' ) === 'referee_saved' ) : ?>
			<div class="st-alert st-alert--success" style="margin:10px 0 0">✅ <?php esc_html_e( 'Árbitro guardado.', 'soccertrack' ); ?></div>
		<?php endif; ?>
		<?php if ( ! empty( $error_ref ?? '' ) ) : ?>
			<div class="st-alert st-alert--error" style="margin:10px 0 0">⚠️ <?php echo esc_html( $error_ref ); ?></div>
		<?php endif; ?>

		<div id="st-referee-form" style="margin-top:12px;<?php echo $has_ref ? 'display:none' : ''; ?>">
			<form method="post" action="">
				<?php wp_nonce_field( 'st_save_referee_' . $match['id'] ); ?>
				<input type="hidden" name="st_save_referee" value="1">
				<div class="st-form-inline" style="gap:12px;align-items:flex-end">
					<div class="st-field" style="flex:1;min-width:180px">
						<label class="st-label"><?php esc_html_e( 'Seleccionar del catálogo', 'soccertrack' ); ?></label>
						<select name="staff_id" class="st-input">
							<option value="0"><?php esc_html_e( '— Seleccionar —', 'soccertrack' ); ?></option>
							<?php foreach ( $staff_arbitros ?? [] as $arb ) : ?>
								<option value="<?php echo esc_attr( (string) $arb['id'] ); ?>">
									<?php echo esc_html( $arb['nombre'] ); ?>
								</option>
							<?php endforeach; ?>
						</select>
					</div>
					<div class="st-field" style="flex:1;min-width:180px">
						<label class="st-label"><?php esc_html_e( 'O escribe el nombre', 'soccertrack' ); ?></label>
						<input
							type="text"
							name="custom_name"
							class="st-input"
							value="<?php echo esc_attr( $current_ref_name ); ?>"
							placeholder="<?php esc_attr_e( 'Nombre personalizado', 'soccertrack' ); ?>"
						>
					</div>
					<div class="st-field">
						<button type="submit" class="st-btn st-btn--primary st-btn--sm">
							💾 <?php esc_html_e( 'Guardar', 'soccertrack' ); ?>
						</button>
					</div>
				</div>
				<p style="font-size:.8rem;color:#888;margin:6px 0 0">
					<?php esc_html_e( 'Si no está en la lista, escribe el nombre en el campo de texto.', 'soccertrack' ); ?>
				</p>
			</form>
		</div>
	</div>
	<?php else : ?>
		<?php $ref_display = (string) ( $match['referee_name'] ?? '' ); ?>
		<p style="margin-bottom:12px">⚖️ <strong><?php esc_html_e( 'Árbitro:', 'soccertrack' ); ?></strong> <?php echo $ref_display !== '' ? esc_html( $ref_display ) : '—'; ?></p>
	<?php endif; ?>

	<?php /* ── Asignación de planillero ──────────────────────────────── */ ?>
	<?php if ( current_user_can( 'ds_manage_tournaments' ) ) : ?>
	<?php
	$current_plan_name = (string) ( $match['planillero_name'] ?? '' );
	$has_plan          = $current_plan_name !== '';
	?>
	<div class="st-card" style="margin-bottom:16px">
		<div class="st-card-header" style="display:flex;align-items:center;justify-content:space-between;gap:12px">
			<h2 class="st-card-title" style="font-size:1rem;margin:0">📋 <?php esc_html_e( 'Planillero del partido', 'soccertrack' ); ?></h2>
			<?php if ( $has_plan ) : ?>
				<span style="font-weight:600;color:#0E0C19"><?php echo esc_html( $current_plan_name ); ?></span>
				<button
					type="button"
					class="st-btn st-btn--secondary st-btn--sm"
					onclick="document.getElementById('st-planillero-form').style.display=document.getElementById('st-planillero-form').style.display==='none'?'block':'none'"
				>✏️ <?php esc_html_e( 'Editar', 'soccertrack' ); ?></button>
			<?php else : ?>
				<button
					type="button"
					class="st-btn st-btn--primary st-btn--sm"
					onclick="document.getElementById('st-planillero-form').style.display='block';this.style.display='none'"
				>➕ <?php esc_html_e( 'Agregar planillero', 'soccertrack' ); ?></button>
			<?php endif; ?>
		</div>

		<?php if ( ( $notice_plan ?? '' ) === 'planillero_saved' ) : ?>
			<div class="st-alert st-alert--success" style="margin:10px 0 0">✅ <?php esc_html_e( 'Planillero guardado.', 'soccertrack' ); ?></div>
		<?php endif; ?>
		<?php if ( ! empty( $error_plan ?? '' ) ) : ?>
			<div class="st-alert st-alert--error" style="margin:10px 0 0">⚠️ <?php echo esc_html( $error_plan ); ?></div>
		<?php endif; ?>

		<div id="st-planillero-form" style="margin-top:12px;<?php echo $has_plan ? 'display:none' : ''; ?>">
			<form method="post" action="">
				<?php wp_nonce_field( 'st_save_planillero_' . $match['id'] ); ?>
				<input type="hidden" name="st_save_planillero" value="1">
				<div class="st-form-inline" style="gap:12px;align-items:flex-end">
					<div class="st-field" style="flex:1;min-width:180px">
						<label class="st-label"><?php esc_html_e( 'Seleccionar del catálogo', 'soccertrack' ); ?></label>
						<select name="staff_id" class="st-input">
							<option value="0"><?php esc_html_e( '— Seleccionar —', 'soccertrack' ); ?></option>
							<?php foreach ( $staff_planilleros ?? [] as $plan ) : ?>
								<option value="<?php echo esc_attr( (string) $plan['id'] ); ?>">
									<?php echo esc_html( $plan['nombre'] ); ?>
								</option>
							<?php endforeach; ?>
						</select>
					</div>
					<div class="st-field" style="flex:1;min-width:180px">
						<label class="st-label"><?php esc_html_e( 'O escribe el nombre', 'soccertrack' ); ?></label>
						<input
							type="text"
							name="custom_name"
							class="st-input"
							value="<?php echo esc_attr( $current_plan_name ); ?>"
							placeholder="<?php esc_attr_e( 'Nombre personalizado', 'soccertrack' ); ?>"
						>
					</div>
					<div class="st-field">
						<button type="submit" class="st-btn st-btn--primary st-btn--sm">
							💾 <?php esc_html_e( 'Guardar', 'soccertrack' ); ?>
						</button>
					</div>
				</div>
				<p style="font-size:.8rem;color:#888;margin:6px 0 0">
					<?php esc_html_e( 'Si no está en la lista, escribe el nombre en el campo de texto.', 'soccertrack' ); ?>
				</p>
			</form>
		</div>
	</div>
	<?php else : ?>
		<?php $plan_display = (string) ( $match['planillero_name'] ?? '' ); ?>
		<p style="margin-bottom:12px">📋 <strong><?php esc_html_e( 'Planillero:', 'soccertrack' ); ?></strong> <?php echo $plan_display !== '' ? esc_html( $plan_display ) : '—'; ?></p>
	<?php endif; ?>

	<?php /* ── Encabezado del partido ──────────────────────────────────── */ ?>
	<div class="st-match-sheet-header">
		<div class="st-match-sheet-team">
			<?php if ( ! empty( $home_team['logo_url'] ) ) : ?>
				<img
					src="<?php echo esc_url( $home_team['logo_url'] ); ?>"
					alt="<?php echo esc_attr( $home_team['name'] ); ?>"
					class="st-match-sheet-team__logo"
				>
			<?php endif; ?>
			<div class="st-match-sheet-team__name"><?php echo esc_html( $home_team['name'] ); ?></div>
			<div class="st-match-sheet-meta"><?php esc_html_e( 'Local', 'soccertrack' ); ?></div>
		</div>

		<div>
			<div class="st-match-sheet-score">
				<span id="st-score-home"><?php echo esc_html( (string) ( $match['home_score'] ?? 0 ) ); ?></span>
				<span style="color:#555">–</span>
				<span id="st-score-away"><?php echo esc_html( (string) ( $match['away_score'] ?? 0 ) ); ?></span>
			</div>
			<div class="st-match-sheet-meta">
				<?php echo esc_html( $match['venue'] ?? '' ); ?>
				<?php echo ! empty( $match['court_name'] ) ? ' · ' . esc_html( $match['court_name'] ) : ''; ?>
			</div>
		</div>

		<div class="st-match-sheet-team">
			<?php if ( ! empty( $away_team['logo_url'] ) ) : ?>
				<img
					src="<?php echo esc_url( $away_team['logo_url'] ); ?>"
					alt="<?php echo esc_attr( $away_team['name'] ); ?>"
					class="st-match-sheet-team__logo"
				>
			<?php endif; ?>
			<div class="st-match-sheet-team__name"><?php echo esc_html( $away_team['name'] ); ?></div>
			<div class="st-match-sheet-meta"><?php esc_html_e( 'Visitante', 'soccertrack' ); ?></div>
		</div>
	</div>

	<?php /* ── Controles de marcador (planillero + árbitro + coordinador) ── */ ?>
	<?php if ( ! $is_locked && $can_enter_incidents ) : ?>
	<div class="st-card" style="text-align:center">
		<div style="display:flex;justify-content:center;align-items:center;gap:40px">
			<div>
				<p style="margin:0 0 8px;font-weight:600"><?php echo esc_html( $home_team['name'] ); ?></p>
				<button class="st-btn st-btn--secondary" data-score-action="dec" data-score-team="home">−</button>
				<span style="display:inline-block;min-width:40px;text-align:center;font-size:1.6rem;font-weight:700" id="st-score-home-ctrl">0</span>
				<button class="st-btn st-btn--primary" data-score-action="inc" data-score-team="home">+</button>
			</div>
			<div>
				<p style="margin:0 0 8px;font-weight:600"><?php echo esc_html( $away_team['name'] ); ?></p>
				<button class="st-btn st-btn--secondary" data-score-action="dec" data-score-team="away">−</button>
				<span style="display:inline-block;min-width:40px;text-align:center;font-size:1.6rem;font-weight:700" id="st-score-away-ctrl">0</span>
				<button class="st-btn st-btn--primary" data-score-action="inc" data-score-team="away">+</button>
			</div>
		</div>

		<?php /* Botón oculto — usado por el footer "Guardar resultado" vía JS */ ?>
		<?php if ( $can_close ) : ?>
		<div id="st-result-notice" style="margin-top:8px"></div>
		<button id="st-submit-result" style="display:none"></button>
		<?php endif; ?>
	</div>
	<?php endif; ?>

	<?php /* ── Nóminas / Planillas ────────────────────────────────────── */ ?>
	<div class="st-card">
		<div class="st-card__header">
			<h2 class="st-card__title"><?php esc_html_e( 'Nóminas', 'soccertrack' ); ?></h2>
		</div>

		<div class="st-roster-section">
			<?php /* Equipo Local */ ?>
			<div>
				<p class="st-roster-title"><?php echo esc_html( $home_team['name'] ); ?></p>
				<?php foreach ( $home_players as $p ) : ?>
					<div class="st-player-row">
						<span class="st-player-dorsal"><?php echo esc_html( ! empty( $p['dorsal'] ) ? (string) $p['dorsal'] : '—' ); ?></span>
						<span class="st-player-name <?php echo $p['is_suspended'] ? 'st-player-name--suspended' : ''; ?>">
							<?php echo esc_html( "{$p['first_name']} {$p['last_name']}" ); ?>
						</span>
						<?php if ( $p['is_suspended'] ) : ?>
							<span class="st-suspended-badge"><?php esc_html_e( 'Susp.', 'soccertrack' ); ?></span>
						<?php endif; ?>
					</div>
				<?php endforeach; ?>
				<?php if ( empty( $home_players ) ) : ?>
					<p style="color:#aaa;font-size:0.85rem"><?php esc_html_e( 'Sin jugadores inscritos.', 'soccertrack' ); ?></p>
				<?php endif; ?>
			</div>

			<?php /* Equipo Visitante */ ?>
			<div>
				<p class="st-roster-title"><?php echo esc_html( $away_team['name'] ); ?></p>
				<?php foreach ( $away_players as $p ) : ?>
					<div class="st-player-row">
						<span class="st-player-dorsal"><?php echo esc_html( ! empty( $p['dorsal'] ) ? (string) $p['dorsal'] : '—' ); ?></span>
						<span class="st-player-name <?php echo $p['is_suspended'] ? 'st-player-name--suspended' : ''; ?>">
							<?php echo esc_html( "{$p['first_name']} {$p['last_name']}" ); ?>
						</span>
						<?php if ( $p['is_suspended'] ) : ?>
							<span class="st-suspended-badge"><?php esc_html_e( 'Susp.', 'soccertrack' ); ?></span>
						<?php endif; ?>
					</div>
				<?php endforeach; ?>
				<?php if ( empty( $away_players ) ) : ?>
					<p style="color:#aaa;font-size:0.85rem"><?php esc_html_e( 'Sin jugadores inscritos.', 'soccertrack' ); ?></p>
				<?php endif; ?>
			</div>
		</div>
	</div>

	<?php /* ── Incidentes (planillero + árbitro + coordinador) ──────────── */ ?>
	<?php /* ── Tarjetas (Amarilla / Roja) ──────────────────────────────── */ ?>
	<?php if ( ! $is_locked && $can_enter_incidents ) : ?>
	<div class="st-card st-incidents-panel">
		<div class="st-card__header">
			<h2 class="st-card__title"><?php esc_html_e( 'Registrar Tarjeta', 'soccertrack' ); ?></h2>
		</div>

		<?php
		$all_players = [];
		foreach ( $home_players as $p ) {
			$all_players[] = array_merge( $p, [ '_team' => $home_team['name'], '_team_id' => $home_team['id'] ] );
		}
		foreach ( $away_players as $p ) {
			$all_players[] = array_merge( $p, [ '_team' => $away_team['name'], '_team_id' => $away_team['id'] ] );
		}

		$card_types = [
			'yellow' => [ 'label' => __( '🟡 Amarilla', 'soccertrack' ),     'btn_class' => 'st-btn--secondary' ],
			'red'    => [ 'label' => __( '🔴 Roja directa', 'soccertrack' ), 'btn_class' => 'st-btn--danger' ],
		];

		foreach ( $card_types as $type => $def ) :
		?>
		<form
			class="st-incident-form"
			data-incident-type="<?php echo esc_attr( $type ); ?>"
			data-tournament-id="<?php echo esc_attr( (string) $tournament_id ); ?>"
			style="margin-bottom:16px;padding-bottom:16px;border-bottom:1px solid var(--st-border)"
		>
			<h3 style="margin:0 0 12px;font-size:0.95rem;font-weight:700"><?php echo esc_html( $def['label'] ); ?></h3>

			<div class="st-incident-form">
				<div class="st-form-group">
					<label class="st-form-label" for="st-player-<?php echo esc_attr( $type ); ?>">
						<?php esc_html_e( 'Jugador', 'soccertrack' ); ?>
					</label>
					<select
						id="st-player-<?php echo esc_attr( $type ); ?>"
						name="player_id"
						class="st-form-control"
						required
					>
						<option value=""><?php esc_html_e( '— Seleccionar —', 'soccertrack' ); ?></option>
						<?php foreach ( $all_players as $p ) : ?>
							<option
								value="<?php echo esc_attr( (string) $p['id'] ); ?>"
								data-team-id="<?php echo esc_attr( (string) $p['_team_id'] ); ?>"
								<?php disabled( (bool) $p['is_suspended'] ); ?>
							>
								<?php
								$dorsal_lbl = ! empty( $p['dorsal'] ) ? $p['dorsal'] . ' — ' : '';
								echo esc_html( $dorsal_lbl . "{$p['first_name']} {$p['last_name']} ({$p['_team']})" );
								?>
								<?php echo $p['is_suspended'] ? ' [SUSP.]' : ''; ?>
							</option>
						<?php endforeach; ?>
					</select>
				</div>

				<div class="st-form-group">
					<label class="st-form-label" for="st-minute-<?php echo esc_attr( $type ); ?>">
						<?php esc_html_e( 'Minuto', 'soccertrack' ); ?>
					</label>
					<input
						type="number"
						id="st-minute-<?php echo esc_attr( $type ); ?>"
						name="minute"
						class="st-form-control"
						min="1"
						max="120"
						value="45"
						required
					>
				</div>

				<div class="st-form-group" style="justify-content:flex-end">
					<button type="submit" class="st-btn <?php echo esc_attr( $def['btn_class'] ); ?>">
						<?php echo esc_html( $def['label'] ); ?>
					</button>
				</div>
			</div>

			<div class="st-form-group" style="flex-direction:column;align-items:flex-start">
				<label class="st-form-label" for="st-desc-<?php echo esc_attr( $type ); ?>">
					<?php esc_html_e( 'Detalle del incidente', 'soccertrack' ); ?>
					<small style="color:#9ca3af;font-weight:400"> — <?php esc_html_e( 'opcional', 'soccertrack' ); ?></small>
				</label>
				<textarea
					id="st-desc-<?php echo esc_attr( $type ); ?>"
					name="description"
					class="st-form-control"
					rows="2"
					maxlength="500"
					placeholder="<?php esc_attr_e( 'Ej: plancha contra jugador 5 del equipo rival', 'soccertrack' ); ?>"
					style="resize:vertical;font-size:.85rem"
				></textarea>
			</div>

			<div class="st-incident-notice" style="margin-top:8px"></div>
		</form>
		<?php endforeach; ?>
	</div>
	<?php endif; ?>

	<?php /* ── Log de incidentes registrados ──────────────────────────── */ ?>
	<div class="st-card">
		<div class="st-card__header">
			<h2 class="st-card__title"><?php esc_html_e( 'Incidentes del Partido', 'soccertrack' ); ?></h2>
		</div>
		<div class="st-table-wrap">
			<table class="st-table">
				<thead>
					<tr>
						<th style="width:54px"><?php esc_html_e( 'Min', 'soccertrack' ); ?></th>
						<th style="width:44px"></th>
						<th><?php esc_html_e( 'Jugador', 'soccertrack' ); ?></th>
						<th><?php esc_html_e( 'Equipo', 'soccertrack' ); ?></th>
						<th><?php esc_html_e( 'Detalle', 'soccertrack' ); ?></th>
						<th style="font-size:.75rem;color:#9ca3af"><?php esc_html_e( 'Ingresado por', 'soccertrack' ); ?></th>
						<th style="width:80px"></th>
					</tr>
				</thead>
				<tbody id="st-incidents-tbody">
					<tr id="st-incidents-empty">
						<td colspan="4" style="text-align:center;color:#aaa">
							<?php esc_html_e( 'Sin incidentes registrados aún.', 'soccertrack' ); ?>
						</td>
					</tr>
				</tbody>
			</table>
		</div>
		<?php if ( $is_finished ) : ?>
		<p style="font-size:.8rem;color:#999;margin-top:8px">
			⚠️ <?php esc_html_e( 'Partido cerrado. Las tarjetas rojas serán evaluadas por el Tribunal de Disciplina.', 'soccertrack' ); ?>
		</p>
		<?php endif; ?>
	</div>

	<?php /* ── Botón guardar resultado al final de la hoja ────────────── */ ?>
	<?php if ( ! $is_finished && $can_close ) : ?>
	<div style="
		position:sticky;bottom:0;z-index:100;
		background:#fff;border-top:2px solid var(--st-green-primary,#3CBC20);
		padding:14px 20px;margin-top:24px;
		display:flex;align-items:center;justify-content:space-between;gap:16px;
		box-shadow:0 -4px 12px rgba(0,0,0,.08)
	">
		<div style="font-size:.85rem;color:#555">
			<strong><?php echo esc_html( $home_team['name'] ); ?></strong>
			<span id="st-footer-score" style="font-size:1.2rem;font-weight:700;margin:0 12px;color:#0E0C19">
				<span id="st-footer-home"><?php echo esc_html( (string) ( $match['home_score'] ?? 0 ) ); ?></span>
				–
				<span id="st-footer-away"><?php echo esc_html( (string) ( $match['away_score'] ?? 0 ) ); ?></span>
			</span>
			<strong><?php echo esc_html( $away_team['name'] ); ?></strong>
		</div>
		<div style="display:flex;align-items:center;gap:10px">
			<div id="st-footer-result-notice" style="font-size:.85rem"></div>
			<button
				id="st-submit-result-footer"
				class="st-btn st-btn--primary"
				style="font-size:.95rem;padding:10px 28px"
				onclick="document.getElementById('st-submit-result')?.click()"
			>
				✔ <?php esc_html_e( 'Guardar resultado y cerrar partido', 'soccertrack' ); ?>
			</button>
		</div>
	</div>
	<script>
	// Sincronizar marcador del footer con los controles del marcador principal.
	(function () {
		function syncFooter() {
			var h = document.getElementById( 'st-score-home-ctrl' );
			var a = document.getElementById( 'st-score-away-ctrl' );
			var fh = document.getElementById( 'st-footer-home' );
			var fa = document.getElementById( 'st-footer-away' );
			if ( h && fh ) fh.textContent = h.textContent;
			if ( a && fa ) fa.textContent = a.textContent;
		}
		// Observar cambios en los controles del marcador.
		var homeCtrl = document.getElementById( 'st-score-home-ctrl' );
		var awayCtrl = document.getElementById( 'st-score-away-ctrl' );
		if ( homeCtrl && awayCtrl ) {
			new MutationObserver( syncFooter ).observe( homeCtrl, { childList: true, characterData: true, subtree: true } );
			new MutationObserver( syncFooter ).observe( awayCtrl, { childList: true, characterData: true, subtree: true } );
		}
	} )();
	</script>
	<?php elseif ( $is_finished ) : ?>
	<div style="
		background:#f0fdf4;border-top:2px solid #86efac;
		padding:14px 20px;margin-top:24px;
		display:flex;align-items:center;justify-content:center;gap:12px
	">
		<span style="font-size:1.1rem">✅</span>
		<span style="font-size:.9rem;color:#166534;font-weight:600">
			<?php esc_html_e( 'Partido cerrado. Resultado registrado.', 'soccertrack' ); ?>
			<strong>
				<?php echo esc_html( (string) ( $match['home_score'] ?? 0 ) ); ?>
				–
				<?php echo esc_html( (string) ( $match['away_score'] ?? 0 ) ); ?>
			</strong>
		</span>
	</div>
	<?php endif; ?>

</div>
