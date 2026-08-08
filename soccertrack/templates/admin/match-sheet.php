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

$is_finished        = 'finished' === ( $match['status'] ?? '' );
$tournament_id      = (int) ( $match['tournament_id'] ?? 0 );
$can_enter_incidents = current_user_can( 'ds_enter_match_incidents' );
$can_close          = current_user_can( 'ds_close_match' );
$can_edit_incidents = current_user_can( 'ds_edit_incidents' );
// Compatibilidad: si se pasa $can_edit explícitamente desde el contexto (modo embed), usarlo.
if ( isset( $can_edit ) && false === $can_edit ) {
	$can_enter_incidents = false;
	$can_close           = false;
	$can_edit_incidents  = false;
}

function st_player_option( array $player ): string {
	$name = esc_html( "{$player['dorsal']} — {$player['first_name']} {$player['last_name']}" );
	$susp = (int) $player['is_suspended'] ? ' [SUSPENDIDO]' : '';
	return "<option value=\"{$player['id']}\" " . disabled( (bool) $player['is_suspended'], true, false ) . ">{$name}{$susp}</option>";
}
?>

<div class="st-admin-wrap" id="st-match-sheet-wrap">

	<h1 class="st-page-title">
		<?php esc_html_e( 'Planilla Arbitral', 'soccertrack' ); ?>
		— <?php esc_html_e( 'Fecha', 'soccertrack' ); ?> <?php echo esc_html( (string) $match['round_number'] ); ?>
	</h1>

	<?php if ( $is_finished ) : ?>
		<div class="st-alert st-alert--warning">
			<?php esc_html_e( 'Este partido ya fue cerrado. El resultado no puede modificarse.', 'soccertrack' ); ?>
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

	<?php /* ── Asignación de árbitro (solo coordinadores) ──────────── */ ?>
	<?php if ( current_user_can( 'ds_manage_tournaments' ) ) : ?>
	<div class="st-card" style="margin-bottom:16px">
		<div class="st-card-header">
			<h2 class="st-card-title" style="font-size:1rem">⚖️ <?php esc_html_e( 'Árbitro del partido', 'soccertrack' ); ?></h2>
		</div>
		<?php if ( ( $notice_ref ?? '' ) === 'referee_saved' ) : ?>
			<div class="st-alert st-alert--success" style="margin-bottom:10px">✅ <?php esc_html_e( 'Árbitro guardado.', 'soccertrack' ); ?></div>
		<?php endif; ?>
		<?php if ( ! empty( $error_ref ?? '' ) ) : ?>
			<div class="st-alert st-alert--error" style="margin-bottom:10px">⚠️ <?php echo esc_html( $error_ref ); ?></div>
		<?php endif; ?>
		<form method="post" action="">
			<?php wp_nonce_field( 'st_save_referee_' . $match['id'] ); ?>
			<input type="hidden" name="st_save_referee" value="1">
			<div class="st-form-inline">
				<div class="st-field" style="flex:1;min-width:200px">
					<label class="st-label"><?php esc_html_e( 'Árbitro asignado', 'soccertrack' ); ?></label>
					<select name="referee_user_id" class="st-input">
						<option value="0"><?php esc_html_e( '— Sin árbitro —', 'soccertrack' ); ?></option>
						<?php foreach ( $referees ?? [] as $ref ) : ?>
							<option value="<?php echo esc_attr( (string) $ref->ID ); ?>"
								<?php selected( (int) ( $match['referee_user_id'] ?? 0 ), (int) $ref->ID ); ?>>
								<?php echo esc_html( $ref->display_name ); ?>
							</option>
						<?php endforeach; ?>
					</select>
				</div>
				<div class="st-field" style="justify-content:flex-end">
					<button type="submit" class="st-btn st-btn--primary st-btn--sm">
						💾 <?php esc_html_e( 'Guardar', 'soccertrack' ); ?>
					</button>
				</div>
			</div>
		</form>
	</div>
	<?php else : ?>
		<?php
		$ref_id   = (int) ( $match['referee_user_id'] ?? 0 );
		$ref_name = $ref_id ? ( get_user_by( 'id', $ref_id )?->display_name ?? '—' ) : '—';
		?>
		<p style="margin-bottom:12px">⚖️ <strong><?php esc_html_e( 'Árbitro:', 'soccertrack' ); ?></strong> <?php echo esc_html( $ref_name ); ?></p>
	<?php endif; ?>

	<?php /* ── Asignación de planillero (solo coordinadores) ─────────── */ ?>
	<?php if ( current_user_can( 'ds_manage_tournaments' ) ) : ?>
	<div class="st-card" style="margin-bottom:16px">
		<div class="st-card-header">
			<h2 class="st-card-title" style="font-size:1rem">📋 <?php esc_html_e( 'Planillero del partido', 'soccertrack' ); ?></h2>
		</div>
		<?php if ( ( $notice_plan ?? '' ) === 'planillero_saved' ) : ?>
			<div class="st-alert st-alert--success" style="margin-bottom:10px">✅ <?php esc_html_e( 'Planillero guardado.', 'soccertrack' ); ?></div>
		<?php endif; ?>
		<?php if ( ! empty( $error_plan ?? '' ) ) : ?>
			<div class="st-alert st-alert--error" style="margin-bottom:10px">⚠️ <?php echo esc_html( $error_plan ); ?></div>
		<?php endif; ?>
		<form method="post" action="" id="st-planillero-form">
			<?php wp_nonce_field( 'st_save_planillero_' . $match['id'] ); ?>
			<input type="hidden" name="st_save_planillero" value="1">
			<div class="st-form-inline">
				<div class="st-field" style="flex:1;min-width:200px">
					<label class="st-label"><?php esc_html_e( 'Planillero asignado', 'soccertrack' ); ?></label>
					<select name="planillero_user_id" class="st-input">
						<option value="0"><?php esc_html_e( '— Sin planillero —', 'soccertrack' ); ?></option>
						<?php foreach ( $planilleros ?? [] as $plan ) : ?>
							<option value="<?php echo esc_attr( (string) $plan->ID ); ?>"
								<?php selected( (int) ( $match['planillero_user_id'] ?? 0 ), (int) $plan->ID ); ?>>
								<?php echo esc_html( $plan->display_name ); ?>
							</option>
						<?php endforeach; ?>
					</select>
				</div>
				<div class="st-field" style="justify-content:flex-end">
					<button type="submit" class="st-btn st-btn--primary st-btn--sm">
						💾 <?php esc_html_e( 'Guardar', 'soccertrack' ); ?>
					</button>
				</div>
			</div>
		</form>
	</div>
	<?php else : ?>
		<?php
		$plan_id   = (int) ( $match['planillero_user_id'] ?? 0 );
		$plan_name = $plan_id ? ( get_user_by( 'id', $plan_id )?->display_name ?? '—' ) : '—';
		?>
		<p style="margin-bottom:12px">📋 <strong><?php esc_html_e( 'Planillero:', 'soccertrack' ); ?></strong> <?php echo esc_html( $plan_name ); ?></p>
	<?php endif; ?>

	<?php if ( ( $tournament['registration_mode'] ?? 'realtime' ) === 'deferred' ) : ?>
	<div class="st-card" style="margin-bottom:16px">
		<div class="st-card-header">
			<h2 class="st-card-title">👥 <?php esc_html_e( 'Personal del partido', 'soccertrack' ); ?></h2>
		</div>

		<?php if ( ( $notice_staff ?? '' ) === 'staff_saved' ) : ?>
			<div class="st-alert st-alert--success">✅ <?php esc_html_e( 'Nombres guardados.', 'soccertrack' ); ?></div>
		<?php endif; ?>

		<form method="post" action="" class="st-form-inline" style="gap:16px;padding:8px 0">
			<?php wp_nonce_field( 'st_save_staff_names_' . $match['id'] ); ?>
			<input type="hidden" name="st_save_staff_names" value="1">

			<div class="st-field">
				<label class="st-label"><?php esc_html_e( 'Árbitro', 'soccertrack' ); ?></label>
				<input
					type="text"
					name="referee_name"
					class="st-input"
					value="<?php echo esc_attr( (string) ( $match['referee_name'] ?? '' ) ); ?>"
					placeholder="<?php esc_attr_e( 'Nombre del árbitro', 'soccertrack' ); ?>"
					style="max-width:220px"
				>
			</div>

			<div class="st-field">
				<label class="st-label"><?php esc_html_e( 'Planillero', 'soccertrack' ); ?></label>
				<input
					type="text"
					name="planillero_name"
					class="st-input"
					value="<?php echo esc_attr( (string) ( $match['planillero_name'] ?? '' ) ); ?>"
					placeholder="<?php esc_attr_e( 'Nombre del planillero', 'soccertrack' ); ?>"
					style="max-width:220px"
				>
			</div>

			<div class="st-field" style="align-self:flex-end">
				<button type="submit" class="st-btn st-btn--secondary st-btn--sm">
					💾 <?php esc_html_e( 'Guardar', 'soccertrack' ); ?>
				</button>
			</div>
		</form>
		<p style="font-size:.8rem;color:#888;margin:4px 0 0">
			<?php esc_html_e( 'Solo visible en modo planilla física. Los nombres se usan como referencia en el acta.', 'soccertrack' ); ?>
		</p>
	</div>
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
			<?php /* Timer visual */ ?>
			<div style="text-align:center;margin-top:12px">
				<span id="st-match-timer" style="font-size:1.4rem;font-weight:700;color:#3CBC20">00:00</span><br>
				<button id="st-timer-start" class="st-btn st-btn--secondary" style="margin-top:6px;font-size:0.75rem;padding:4px 10px">
					▶ <?php esc_html_e( 'Iniciar', 'soccertrack' ); ?>
				</button>
				<button id="st-timer-stop" class="st-btn st-btn--secondary" style="font-size:0.75rem;padding:4px 10px" disabled>
					⏸ <?php esc_html_e( 'Pausar', 'soccertrack' ); ?>
				</button>
				<button id="st-timer-reset" class="st-btn st-btn--secondary" style="font-size:0.75rem;padding:4px 10px">
					↺ <?php esc_html_e( 'Reset', 'soccertrack' ); ?>
				</button>
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
	<?php if ( ! $is_finished && $can_enter_incidents ) : ?>
	<div class="st-card" style="text-align:center">
		<div style="display:flex;justify-content:center;align-items:center;gap:40px">
			<div>
				<p style="margin:0 0 8px;font-weight:600"><?php echo esc_html( $home_team['name'] ); ?></p>
				<button class="st-btn st-btn--secondary" data-score-action="dec" data-score-team="home" <?php disabled( $is_finished ); ?>>−</button>
				<span style="display:inline-block;min-width:40px;text-align:center;font-size:1.6rem;font-weight:700" id="st-score-home-ctrl">0</span>
				<button class="st-btn st-btn--primary" data-score-action="inc" data-score-team="home" <?php disabled( $is_finished ); ?>>+</button>
			</div>
			<div>
				<p style="margin:0 0 8px;font-weight:600"><?php echo esc_html( $away_team['name'] ); ?></p>
				<button class="st-btn st-btn--secondary" data-score-action="dec" data-score-team="away" <?php disabled( $is_finished ); ?>>−</button>
				<span style="display:inline-block;min-width:40px;text-align:center;font-size:1.6rem;font-weight:700" id="st-score-away-ctrl">0</span>
				<button class="st-btn st-btn--primary" data-score-action="inc" data-score-team="away" <?php disabled( $is_finished ); ?>>+</button>
			</div>
		</div>

		<?php /* Solo el árbitro/coordinador puede cerrar el acta */ ?>
		<div style="margin-top:20px">
			<div id="st-result-notice"></div>
			<?php if ( $can_close ) : ?>
			<button
				id="st-submit-result"
				class="st-btn st-btn--primary"
				style="font-size:1rem;padding:12px 36px"
				<?php disabled( $is_finished ); ?>
			>
				✔ <?php esc_html_e( 'Cerrar partido y registrar resultado', 'soccertrack' ); ?>
			</button>
			<?php else : ?>
			<p style="color:#6b7280;font-size:.85rem;margin:0">
				⏳ <?php esc_html_e( 'Esperando que el árbitro revise y cierre el acta.', 'soccertrack' ); ?>
			</p>
			<?php endif; ?>
		</div>
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
						<span class="st-player-dorsal"><?php echo esc_html( (string) $p['dorsal'] ); ?></span>
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
						<span class="st-player-dorsal"><?php echo esc_html( (string) $p['dorsal'] ); ?></span>
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
	<?php if ( ! $is_finished && $can_enter_incidents ) : ?>
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
								<?php echo esc_html( "{$p['dorsal']} — {$p['first_name']} {$p['last_name']} ({$p['_team']})" ); ?>
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

</div>
