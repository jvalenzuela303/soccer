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
	<div class="st-alert st-alert--success">📅 <?php esc_html_e( 'Fechas y canchas asignadas según horario y recintos del torneo. Puedes ajustar individualmente en el fixture.', 'soccertrack' ); ?></div>
<?php endif; ?>
<?php if ( ( $notice ?? '' ) === 'schedule_updated' ) : ?>
	<div class="st-alert st-alert--success">✅ <?php esc_html_e( 'Horario del torneo actualizado.', 'soccertrack' ); ?></div>
<?php endif; ?>
<?php if ( ( $notice ?? '' ) === 'dates_updated' ) : ?>
	<div class="st-alert st-alert--success">✅ <?php esc_html_e( 'Fechas del torneo actualizadas.', 'soccertrack' ); ?></div>
<?php endif; ?>
<?php if ( ( $notice ?? '' ) === 'reg_mode_updated' ) : ?>
	<div class="st-alert st-alert--success">✅ <?php esc_html_e( 'Modo de registro actualizado.', 'soccertrack' ); ?></div>
<?php endif; ?>
<?php if ( ( $notice ?? '' ) === 'tournament_config_updated' ) : ?>
	<div class="st-alert st-alert--success">✅ <?php esc_html_e( 'Configuración del torneo actualizada.', 'soccertrack' ); ?></div>
<?php endif; ?>
<?php if ( ( $notice ?? '' ) === 'tournament_renamed' ) : ?>
	<div class="st-alert st-alert--success">✅ <?php esc_html_e( 'Nombre del torneo actualizado.', 'soccertrack' ); ?></div>
<?php endif; ?>
<?php if ( ( $notice ?? '' ) === 'venues_updated' ) : ?>
	<div class="st-alert st-alert--success">✅ <?php esc_html_e( 'Recintos del torneo actualizados.', 'soccertrack' ); ?></div>
<?php endif; ?>
<?php if ( ( $notice ?? '' ) === 'banner_saved' ) : ?>
	<div class="st-alert st-alert--success">✅ <?php esc_html_e( 'Banner guardado correctamente.', 'soccertrack' ); ?></div>
<?php endif; ?>
<?php if ( ( $notice ?? '' ) === 'banner_deleted' ) : ?>
	<div class="st-alert st-alert--success">🗑️ <?php esc_html_e( 'Banner eliminado.', 'soccertrack' ); ?></div>
<?php endif; ?>
<?php if ( ( $notice ?? '' ) === 'courts_reassigned' ) : ?>
	<div class="st-alert st-alert--success">✅ <?php
	/* translators: %d: número de fecha/ronda */
	printf( esc_html__( 'Canchas reasignadas para la fecha %d.', 'soccertrack' ), absint( $_GET['round'] ?? 0 ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	?></div>
<?php endif; ?>
<?php if ( ! empty( $error ?? '' ) ) : ?>
	<div class="st-alert st-alert--error">⚠️ <?php echo esc_html( $error ); ?></div>
<?php endif; ?>

<div class="st-page-header">
	<a href="<?php echo esc_url( home_url( '/panel/torneos/' ) ); ?>" class="st-back-link">← <?php esc_html_e( 'Torneos', 'soccertrack' ); ?></a>

	<?php /* ── Nombre con edición inline ── */ ?>
	<div id="st-tournament-name-display" style="display:flex;align-items:center;gap:8px;flex:1;min-width:0">
		<h1 class="st-page-title" style="margin:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?php echo esc_html( $tournament['name'] ); ?></h1>
		<?php if ( empty( $is_locked ) ) : ?>
		<button type="button" id="st-rename-toggle-btn" title="<?php esc_attr_e( 'Editar nombre', 'soccertrack' ); ?>"
				style="background:none;border:none;cursor:pointer;padding:4px;color:#6b7280;font-size:1rem;line-height:1;flex-shrink:0">✏️</button>
		<?php endif; ?>
	</div>

	<?php if ( empty( $is_locked ) ) : ?>
	<form id="st-rename-form" method="post" style="display:none;flex:1;align-items:center;gap:8px;min-width:0"
		  onsubmit="return stValidateRename()">
		<?php wp_nonce_field( 'st_rename_tournament_' . $tournament['id'] ); ?>
		<input type="hidden" name="st_rename_tournament" value="1">
		<input type="text" name="tournament_name" id="st-rename-input" class="st-input"
			   value="<?php echo esc_attr( $tournament['name'] ); ?>"
			   required maxlength="150"
			   style="flex:1;min-width:0;font-size:1.1rem;font-weight:600">
		<button type="submit" class="st-btn st-btn--sm st-btn--primary"><?php esc_html_e( 'Guardar', 'soccertrack' ); ?></button>
		<button type="button" id="st-rename-cancel-btn" class="st-btn st-btn--sm st-btn--secondary"><?php esc_html_e( 'Cancelar', 'soccertrack' ); ?></button>
	</form>
	<script>
	(function () {
		var display    = document.getElementById('st-tournament-name-display');
		var form       = document.getElementById('st-rename-form');
		var toggleBtn  = document.getElementById('st-rename-toggle-btn');
		var cancelBtn  = document.getElementById('st-rename-cancel-btn');
		var input      = document.getElementById('st-rename-input');
		if ( toggleBtn ) {
			toggleBtn.addEventListener('click', function () {
				display.style.display = 'none';
				form.style.display    = 'flex';
				input.focus();
				input.select();
			});
		}
		if ( cancelBtn ) {
			cancelBtn.addEventListener('click', function () {
				form.style.display    = 'none';
				display.style.display = 'flex';
			});
		}
	}());
	function stValidateRename() {
		var v = document.getElementById('st-rename-input').value.trim();
		if ( ! v ) { alert('<?php echo esc_js( __( 'El nombre no puede estar vacío.', 'soccertrack' ) ); ?>'); return false; }
		return true;
	}
	</script>
	<?php endif; ?>

	<?php if ( ( $tournament['status'] ?? 'draft' ) !== 'draft' ) : ?>
	<a href="<?php echo esc_url( home_url( '/torneo/' . $tournament['id'] . '/' ) ); ?>" class="st-btn st-btn--secondary" target="_blank">
		🌐 <?php esc_html_e( 'Ver portal público', 'soccertrack' ); ?>
	</a>
	<?php endif; ?>
</div>

<?php if ( ! empty( $is_locked ) ) : ?>
<div class="st-alert" style="background:#fef3c7;border:1px solid #f59e0b;color:#92400e;border-radius:8px;padding:12px 16px;margin-bottom:20px;display:flex;align-items:center;gap:10px">
	🔒 <strong><?php esc_html_e( 'Torneo finalizado — solo lectura.', 'soccertrack' ); ?></strong>
	<?php esc_html_e( 'Para realizar cambios, reactiva el torneo desde la lista de torneos.', 'soccertrack' ); ?>
</div>
<style>
/* Bloquear todos los controles del panel cuando el torneo está finalizado */
.st-card form input:not([type="hidden"]),
.st-card form select,
.st-card form textarea,
.st-card form button,
.st-card .st-btn:not([href*="portal"]):not([href*="torneo/"]),
#st-gen-playoffs-btn,
#st-reset-playoffs-btn,
#st-gen-knockout-btn,
.st-bracket-gen-btn,
#st-gen-fixture-btn {
	pointer-events: none;
	opacity: 0.45;
	cursor: not-allowed;
}
</style>
<?php endif; ?>

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

<?php /* ── Fechas del torneo ──────────────────────────────────────────── */ ?>
<div class="st-card" style="margin-bottom:20px">
	<div class="st-card-header">
		<h2 class="st-card-title">📆 <?php esc_html_e( 'Fechas del torneo', 'soccertrack' ); ?></h2>
	</div>
	<form method="post" action="" class="st-form-inline" style="align-items:flex-end;gap:16px;flex-wrap:wrap">
		<?php wp_nonce_field( 'st_update_dates_' . $tournament['id'] ); ?>
		<input type="hidden" name="st_update_dates" value="1">

		<div class="st-field">
			<label class="st-label"><?php esc_html_e( 'Inicio', 'soccertrack' ); ?></label>
			<input
				type="date"
				name="start_date"
				class="st-input"
				value="<?php echo esc_attr( $tournament['start_date'] ?? '' ); ?>"
			>
		</div>

		<div class="st-field">
			<label class="st-label" style="display:flex;align-items:center;gap:4px">
				<?php esc_html_e( 'Fin estimado', 'soccertrack' ); ?>
				<span
					title="<?php esc_attr_e( 'Puede extenderse por eventos climáticos u otras contingencias. Actualiza esta fecha cuando sea necesario.', 'soccertrack' ); ?>"
					style="font-size:.75rem;color:#999;cursor:default"
				>ℹ</span>
			</label>
			<input
				type="date"
				name="end_date"
				class="st-input"
				value="<?php echo esc_attr( $tournament['end_date'] ?? '' ); ?>"
			>
		</div>

		<div class="st-field" style="align-self:flex-end">
			<button type="submit" class="st-btn st-btn--secondary st-btn--sm">
				💾 <?php esc_html_e( 'Guardar', 'soccertrack' ); ?>
			</button>
		</div>
	</form>
	<p style="margin:8px 0 0;font-size:.8rem;color:#888">
		<?php esc_html_e( 'La fecha de fin es referencial. Puedes modificarla en cualquier momento si el torneo se extiende por clima, suspensiones u otras contingencias.', 'soccertrack' ); ?>
	</p>
</div>

<?php /* ── Configuración de horario del torneo ─────────────────────────── */ ?>
<div class="st-card" style="margin-bottom:20px">
	<div class="st-card-header">
		<h2 class="st-card-title">🕖 <?php esc_html_e( 'Horario habitual de partidos', 'soccertrack' ); ?></h2>
	</div>

	<form method="post" action="">
		<?php wp_nonce_field( 'st_update_schedule_' . $tournament['id'] ); ?>
		<input type="hidden" name="st_update_schedule" value="1">

		<?php
		$day_labels = [
			1 => [ 'short' => 'Lun', 'full' => 'Lunes' ],
			2 => [ 'short' => 'Mar', 'full' => 'Martes' ],
			3 => [ 'short' => 'Mié', 'full' => 'Miércoles' ],
			4 => [ 'short' => 'Jue', 'full' => 'Jueves' ],
			5 => [ 'short' => 'Vie', 'full' => 'Viernes' ],
			6 => [ 'short' => 'Sáb', 'full' => 'Sábado' ],
			0 => [ 'short' => 'Dom', 'full' => 'Domingo' ],
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

		$start_hm     = substr( (string) ( $tournament['match_time'] ?? '19:00:00' ), 0, 5 );
		$duration_min = max( 30, (int) ( $tournament['match_duration'] ?? 60 ) );
		[ $sh, $sm ]  = array_map( 'intval', explode( ':', $start_hm ) );
		$end_total    = $sh * 60 + $sm + $duration_min;
		$end_hm       = sprintf( '%02d:%02d', intdiv( $end_total, 60 ) % 24, $end_total % 60 );
		?>

		<?php /* ── Días ── */ ?>
		<div style="margin-bottom:20px">
			<p class="st-label" style="margin:0 0 10px"><?php esc_html_e( '¿Qué días se juega?', 'soccertrack' ); ?></p>
			<div style="display:flex;gap:6px;flex-wrap:wrap">
				<?php foreach ( $day_labels as $val => $day ) :
					$active = in_array( $val, $saved_days, true );
				?>
				<label
					title="<?php echo esc_attr( $day['full'] ); ?>"
					style="
						cursor:pointer;
						display:flex;align-items:center;justify-content:center;
						width:52px;height:42px;border-radius:6px;font-size:.9rem;font-weight:600;
						border:2px solid <?php echo $active ? 'var(--st-green-primary,#3CBC20)' : '#ddd'; ?>;
						background:<?php echo $active ? 'var(--st-green-primary,#3CBC20)' : '#f5f5f5'; ?>;
						color:<?php echo $active ? '#fff' : '#555'; ?>;
						transition:all .15s
					"
					onmouseover="this.style.borderColor='var(--st-green-primary,#3CBC20)'"
					onmouseout="if(!this.querySelector('input').checked){this.style.borderColor='#ddd'}"
				>
					<input
						type="checkbox"
						name="match_weekdays[]"
						value="<?php echo esc_attr( (string) $val ); ?>"
						<?php checked( $active ); ?>
						style="display:none"
						onchange="
							this.parentElement.style.background = this.checked ? 'var(--st-green-primary,#3CBC20)' : '#f5f5f5';
							this.parentElement.style.color      = this.checked ? '#fff' : '#555';
							this.parentElement.style.borderColor= this.checked ? 'var(--st-green-primary,#3CBC20)' : '#ddd';
						"
					>
					<?php echo esc_html( $day['short'] ); ?>
				</label>
				<?php endforeach; ?>
			</div>
			<p style="margin:8px 0 0;font-size:.78rem;color:#999">
				<?php esc_html_e( 'Selecciona uno o más días. El fixture se distribuirá entre los días marcados.', 'soccertrack' ); ?>
			</p>
		</div>

		<?php
		// Leer slots existentes para pre-popular la UI y condicionar visibilidad de "Hora de inicio".
		$existing_slots = [];
		if ( ! empty( $tournament['schedule_slots'] ) ) {
			$decoded = json_decode( $tournament['schedule_slots'], true );
			if ( is_array( $decoded ) ) {
				$existing_slots = $decoded;
			}
		}
		?>
		<?php /* ── Hora y duración ── */ ?>
		<div style="display:flex;align-items:flex-end;gap:20px;flex-wrap:wrap;margin-bottom:20px">
			<div id="st-match-time-wrap"<?php echo empty( $existing_slots ) ? '' : ' style="display:none"'; ?>>
				<label class="st-label" style="display:block;margin-bottom:4px">
					<?php esc_html_e( 'Hora de inicio', 'soccertrack' ); ?>
				</label>
				<input
					type="time"
					name="match_time"
					id="st-match-time"
					class="st-input"
					value="<?php echo esc_attr( $start_hm ); ?>"
					style="max-width:120px"
				>
			</div>

			<div>
				<label class="st-label" style="display:block;margin-bottom:4px">
					<?php esc_html_e( 'Duración', 'soccertrack' ); ?>
				</label>
				<div style="display:flex;align-items:center;gap:6px">
					<input
						type="number"
						name="match_duration"
						id="st-duration"
						class="st-input"
						value="<?php echo esc_attr( (string) $duration_min ); ?>"
						min="30" max="120" step="5"
						style="max-width:75px"
					>
					<span style="font-size:.85rem;color:#666">min</span>
				</div>
			</div>

			<div style="padding-bottom:2px;font-size:.9rem;color:#444;white-space:nowrap">
				<?php esc_html_e( 'Término estimado:', 'soccertrack' ); ?>
				<strong id="st-end-time" style="font-size:1rem"><?php echo esc_html( $end_hm ); ?></strong>
			</div>
		</div>

		<div style="margin-bottom:20px">
			<label class="st-label" style="display:block;margin-bottom:8px">
				<?php esc_html_e( 'Bloques horarios', 'soccertrack' ); ?>
				<span style="font-size:.75rem;color:#999;font-weight:400;cursor:default"
					title="<?php esc_attr_e( 'Define cuántos partidos simultáneos se juegan en cada horario. Si no configuras bloques, se usa la "Hora de inicio" para todos los partidos.', 'soccertrack' ); ?>"> ℹ</span>
			</label>
			<div id="st-slots-container">
				<?php if ( ! empty( $existing_slots ) ) : ?>
					<?php foreach ( $existing_slots as $slot ) : ?>
					<div class="st-slot-row" style="display:flex;align-items:center;gap:10px;margin-bottom:8px">
						<input type="time" name="slot_time[]" class="st-input" value="<?php echo esc_attr( $slot['time'] ); ?>" style="max-width:110px" required>
						<span style="color:#555;font-size:.85rem">×</span>
						<input type="number" name="slot_count[]" class="st-input" value="<?php echo esc_attr( (string) $slot['max_matches'] ); ?>" min="1" max="50" style="max-width:70px;text-align:center" required>
						<span style="color:#555;font-size:.85rem"><?php esc_html_e( 'partidos', 'soccertrack' ); ?></span>
						<button type="button" class="st-btn st-btn--sm" onclick="this.closest('.st-slot-row').remove()"
								style="color:#d63638;border-color:#d63638;padding:2px 8px">−</button>
					</div>
					<?php endforeach; ?>
				<?php endif; ?>
			</div>
			<button type="button" id="st-add-slot-btn" class="st-btn st-btn--sm" style="margin-top:4px">
				+ <?php esc_html_e( 'Agregar bloque', 'soccertrack' ); ?>
			</button>
		</div>
		<script>
		(function() {
			var container = document.getElementById('st-slots-container');
			var timeWrap  = document.getElementById('st-match-time-wrap');

			function syncTimeWrap() {
				var hasSlots = container.querySelectorAll('.st-slot-row').length > 0;
				timeWrap.style.display = hasSlots ? 'none' : '';
			}

			// Delegated click: captura el − en filas estáticas (PHP) y dinámicas.
			// Se ejecuta tras el remove() del inline onclick con requestAnimationFrame.
			container.addEventListener('click', function(e) {
				if ( e.target.tagName === 'BUTTON' && e.target.textContent.trim() === '−' ) {
					requestAnimationFrame( syncTimeWrap );
				}
			});

			document.getElementById('st-add-slot-btn').addEventListener('click', function() {
				var row = document.createElement('div');
				row.className = 'st-slot-row';
				row.style.cssText = 'display:flex;align-items:center;gap:10px;margin-bottom:8px';
				row.innerHTML = '<input type="time" name="slot_time[]" class="st-input" style="max-width:110px" required>'
					+ '<span style="color:#555;font-size:.85rem">×</span>'
					+ '<input type="number" name="slot_count[]" class="st-input" value="1" min="1" max="50" style="max-width:70px;text-align:center" required>'
					+ '<span style="color:#555;font-size:.85rem"><?php echo esc_js( esc_html__( 'partidos', 'soccertrack' ) ); ?></span>'
					+ '<button type="button" class="st-btn st-btn--sm" onclick="this.closest(\'.st-slot-row\').remove()" style="color:#d63638;border-color:#d63638;padding:2px 8px">−</button>';
				container.appendChild(row);
				syncTimeWrap();
			});
		})();
		</script>

		<?php /* ── Explicación del sistema ── */ ?>
		<div style="background:#f8f8f8;border-left:3px solid var(--st-green-primary,#3CBC20);border-radius:0 6px 6px 0;padding:10px 14px;margin-bottom:16px;font-size:.82rem;color:#555;line-height:1.6">
			<strong style="color:#333;display:block;margin-bottom:4px">
				<?php esc_html_e( '¿Cómo usa el sistema estos datos?', 'soccertrack' ); ?>
			</strong>
			<?php esc_html_e( 'Al generar el fixture, cada jornada se asigna al siguiente día marcado del ciclo semanal. Los partidos de la jornada inician a la hora indicada: si el recinto tiene 2 canchas, se juegan 2 partidos simultáneos y los siguientes inician una hora después (ej: 19:00 y 20:00 con 2 canchas). Los horarios individuales pueden ajustarse en el fixture una vez generado.', 'soccertrack' ); ?>
		</div>

		<button type="submit" class="st-btn st-btn--primary st-btn--sm">
			💾 <?php esc_html_e( 'Guardar', 'soccertrack' ); ?>
		</button>
	</form>

	<script>
	(function () {
		const timeIn = document.getElementById( 'st-match-time' );
		const durIn  = document.getElementById( 'st-duration' );
		const endOut = document.getElementById( 'st-end-time' );
		if ( ! timeIn || ! durIn || ! endOut ) return;
		function update() {
			const [ h, m ] = timeIn.value.split( ':' ).map( Number );
			const dur      = Math.max( 30, parseInt( durIn.value, 10 ) || 60 );
			const total    = h * 60 + m + dur;
			endOut.textContent =
				String( Math.floor( total / 60 ) % 24 ).padStart( 2, '0' ) + ':' +
				String( total % 60 ).padStart( 2, '0' );
		}
		timeIn.addEventListener( 'input', update );
		durIn.addEventListener( 'input', update );
	} )();
	</script>
</div>

<?php /* ── Configuración del torneo ────────────────────────────────── */ ?>
<div class="st-card" style="margin-bottom:20px">
	<div class="st-card-header">
		<h2 class="st-card-title">⚙️ <?php esc_html_e( 'Configuración del torneo', 'soccertrack' ); ?></h2>
	</div>
	<form method="post" action="">
		<?php wp_nonce_field( 'st_update_tournament_config_' . $tournament['id'] ); ?>
		<input type="hidden" name="st_update_tournament_config" value="1">

		<table style="width:100%;border-collapse:collapse">
			<thead>
				<tr style="border-bottom:2px solid #e5e7eb">
					<th style="text-align:left;padding:8px 12px;font-size:.8rem;color:#888;font-weight:600;text-transform:uppercase;letter-spacing:.04em">
						<?php esc_html_e( 'Parámetro', 'soccertrack' ); ?>
					</th>
					<th style="text-align:center;padding:8px 12px;font-size:.8rem;color:#888;font-weight:600;text-transform:uppercase;letter-spacing:.04em;width:120px">
						<?php esc_html_e( 'Valor', 'soccertrack' ); ?>
					</th>
					<th style="text-align:left;padding:8px 12px;font-size:.8rem;color:#888;font-weight:600;text-transform:uppercase;letter-spacing:.04em">
						<?php esc_html_e( 'Descripción', 'soccertrack' ); ?>
					</th>
				</tr>
			</thead>
			<tbody>
				<tr style="border-bottom:1px solid #f0f0f0">
					<td style="padding:12px;font-weight:600;white-space:nowrap">
						🏆 <?php esc_html_e( 'Formato', 'soccertrack' ); ?>
					</td>
					<td style="padding:12px">
						<?php
						global $wpdb; // phpcs:ignore WordPress.WP.GlobalVariablesOverride
						$has_matches    = (int) $wpdb->get_var( $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
							"SELECT COUNT(*) FROM {$wpdb->prefix}ds_matches WHERE tournament_id = %d",
							$tournament['id']
						) );
						$current_format = $tournament['format'] ?? '';
						$format_opts    = [
							'round_robin'          => __( 'Todos contra todos', 'soccertrack' ),
							'round_robin_playoffs' => __( 'Todos contra todos + Play-offs', 'soccertrack' ),
							'group_stage'          => __( 'Fase de grupos', 'soccertrack' ),
							'knockout'             => __( 'Eliminación directa', 'soccertrack' ),
							'swiss'                => __( 'Liga Swiss + Play-offs (tipo Champions)', 'soccertrack' ),
						];
						// Editable si: no hay partidos O el formato está vacío (nunca se configuró).
						$can_edit = ( $has_matches === 0 ) || ! $current_format;
						if ( $can_edit ) :
						?>
						<select name="format" class="st-input" style="max-width:320px"
							<?php echo $current_format ? '' : 'required'; ?>>
							<?php if ( ! $current_format ) : ?>
								<option value=""><?php esc_html_e( '— Selecciona un formato —', 'soccertrack' ); ?></option>
							<?php endif; ?>
							<?php foreach ( $format_opts as $val => $label ) : ?>
								<option value="<?php echo esc_attr( $val ); ?>"
									<?php selected( $current_format, $val ); ?>>
									<?php echo esc_html( $label ); ?>
								</option>
							<?php endforeach; ?>
						</select>
						<?php if ( $has_matches > 0 && ! $current_format ) : ?>
							<br><small style="color:#e67e22">
								⚠️ <?php esc_html_e( 'El torneo tiene partidos generados. Cambia el formato solo si es necesario corregirlo.', 'soccertrack' ); ?>
							</small>
						<?php endif; ?>
						<?php else : ?>
						<span style="font-weight:600">
							<?php echo esc_html( $format_opts[ $current_format ] ?? ( $current_format ?: '—' ) ); ?>
						</span>
						<input type="hidden" name="format" value="<?php echo esc_attr( $current_format ); ?>">
						<br><small style="color:#999">
							<?php esc_html_e( 'No editable: el torneo ya tiene partidos generados.', 'soccertrack' ); ?>
						</small>
						<?php endif; ?>
					</td>
					<td style="padding:12px;font-size:.82rem;color:#666">
						<?php esc_html_e( 'Define las reglas del fixture y la tabla de posiciones.', 'soccertrack' ); ?>
					</td>
				</tr>
				<tr style="border-bottom:1px solid #f0f0f0">
					<td style="padding:12px;font-weight:600;white-space:nowrap">
						📅 <?php esc_html_e( 'Liberación del fixture', 'soccertrack' ); ?>
					</td>
					<td style="padding:12px;text-align:center">
						<input
							type="number"
							name="fixture_release_days"
							class="st-input"
							value="<?php echo esc_attr( (string) (int) ( $tournament['fixture_release_days'] ?? 0 ) ); ?>"
							min="-7"
							max="30"
							style="max-width:80px;text-align:center"
						>
					</td>
					<td style="padding:12px;font-size:.82rem;color:#666">
						<?php esc_html_e( 'Días tras la última fecha para publicar la siguiente jornada. 0 = todas visibles de inmediato.', 'soccertrack' ); ?>
					</td>
				</tr>
				<tr>
					<td style="padding:12px;font-weight:600;white-space:nowrap">
						🟨 <?php esc_html_e( 'Amarillas para suspensión', 'soccertrack' ); ?>
					</td>
					<td style="padding:12px;text-align:center">
						<input
							type="number"
							name="yellows_per_suspension"
							class="st-input"
							value="<?php echo esc_attr( (string) (int) ( $tournament['yellows_per_suspension'] ?? 3 ) ); ?>"
							min="2"
							max="10"
							style="max-width:80px;text-align:center"
						>
					</td>
					<td style="padding:12px;font-size:.82rem;color:#666">
						<?php esc_html_e( 'Tarjetas amarillas acumuladas que generan 1 fecha de suspensión automática. Por defecto: 3.', 'soccertrack' ); ?>
					</td>
				</tr>
			</tbody>
		</table>

		<div style="padding:12px;border-top:1px solid #f0f0f0;text-align:right">
			<button type="submit" class="st-btn st-btn--secondary st-btn--sm">
				💾 <?php esc_html_e( 'Guardar configuración', 'soccertrack' ); ?>
			</button>
		</div>
	</form>
</div>

<?php /* ── Fase Eliminatoria (solo formato group_stage) ──────────────── */ ?>
<?php if ( ! empty( $group_stage_status['is_group_stage'] ) ) : ?>
<div class="st-card" style="margin-bottom:20px" id="st-group-stage-card">
	<div class="st-card-header" style="display:flex;justify-content:space-between;align-items:center">
		<h2 class="st-card-title" style="margin:0"><?php esc_html_e( 'Grupos', 'soccertrack' ); ?></h2>
	</div>

	<?php if ( ! $group_stage_status['has_group_label'] ) : ?>
		<p class="st-empty-msg"><?php esc_html_e( 'Genera el fixture primero para ver los grupos.', 'soccertrack' ); ?></p>
	<?php else : ?>
		<?php
		// Agrupar equipos por group_label.
		$teams_by_group = [];
		foreach ( $teams as $t ) {
			if ( ! empty( $t['group_label'] ) ) {
				$teams_by_group[ $t['group_label'] ][] = $t;
			}
		}
		ksort( $teams_by_group );
		?>
		<div style="display:flex;flex-wrap:wrap;gap:16px;margin-top:12px">
		<?php foreach ( $teams_by_group as $label => $group_teams ) : ?>
			<div style="min-width:160px">
				<h3 style="font-size:.9rem;margin:0 0 8px;color:#0E0C19"><?php echo esc_html( __( 'Grupo', 'soccertrack' ) . ' ' . $label ); ?></h3>
				<ul style="margin:0;padding:0;list-style:none">
				<?php foreach ( $group_teams as $gt ) : ?>
					<li style="padding:4px 0;border-bottom:1px solid #eee;font-size:.85rem">
						<?php echo esc_html( $gt['name'] ); ?>
					</li>
				<?php endforeach; ?>
				</ul>
			</div>
		<?php endforeach; ?>
		</div>
	<?php endif; ?>
</div>

<?php /* ── Fase Eliminatoria — botones de generación ─────────────────── */ ?>
<div class="st-card" style="margin-bottom:20px" id="st-knockout-card">
	<div class="st-card-header">
		<h2 class="st-card-title"><?php esc_html_e( 'Fase Eliminatoria', 'soccertrack' ); ?></h2>
	</div>

	<div id="st-knockout-notice"></div>

	<?php
	$venues_for_knockout ??= ! empty( $tournament_venue_ids )
		? array_filter( $venues, static fn( $v ) => in_array( (int) $v['id'], $tournament_venue_ids, true ) )
		: $venues;
	?>

	<?php if ( $group_stage_status['has_finals'] ) : ?>
		<p style="color:#3CBC20;font-weight:600">✅ <?php esc_html_e( 'Fase eliminatoria completa.', 'soccertrack' ); ?></p>

	<?php elseif ( $group_stage_status['has_semifinals'] && ! $group_stage_status['all_sf_done'] ) : ?>
		<p class="st-empty-msg"><?php esc_html_e( 'Semi-finales en curso. Espera a que terminen para generar la Final.', 'soccertrack' ); ?></p>

	<?php elseif ( $group_stage_status['has_quarterfinals'] && ! $group_stage_status['all_qf_done'] ) : ?>
		<p class="st-empty-msg"><?php esc_html_e( 'Cuartos de final en curso. Espera a que terminen.', 'soccertrack' ); ?></p>

	<?php elseif ( ! $group_stage_status['all_regular_done'] ) : ?>
		<p class="st-empty-msg"><?php esc_html_e( 'La eliminatoria estará disponible cuando todos los partidos de grupos estén finalizados.', 'soccertrack' ); ?></p>

	<?php else : ?>
		<?php
		$knockout_btn_label = __( 'Generar Eliminatoria', 'soccertrack' );
		if ( $group_stage_status['all_sf_done'] && ! $group_stage_status['has_finals'] ) {
			$knockout_btn_label = __( 'Generar Final', 'soccertrack' );
		} elseif ( $group_stage_status['all_qf_done'] && ! $group_stage_status['has_semifinals'] ) {
			$knockout_btn_label = __( 'Generar Semi-finales', 'soccertrack' );
		}
		?>
		<?php if ( ! empty( $venues_for_knockout ) ) : ?>
		<div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap">
			<select id="st-knockout-venue-select" class="st-input" style="max-width:220px">
				<option value=""><?php esc_html_e( '— Seleccionar recinto —', 'soccertrack' ); ?></option>
				<?php foreach ( $venues_for_knockout as $v ) : ?>
					<option value="<?php echo esc_attr( (string) $v['id'] ); ?>"><?php echo esc_html( $v['name'] ); ?></option>
				<?php endforeach; ?>
			</select>
			<input type="date" id="st-knockout-date-input" class="st-input" style="max-width:160px"
			       title="<?php esc_attr_e( 'Fecha opcional. Si no se elige, se usará el próximo día hábil.', 'soccertrack' ); ?>">
			<button class="st-btn st-btn--primary" id="st-gen-knockout-btn"
				data-tournament="<?php echo esc_attr( (string) $tournament['id'] ); ?>"
				data-nonce="<?php echo esc_attr( wp_create_nonce( 'wp_rest' ) ); ?>">
				⚡ <?php echo esc_html( $knockout_btn_label ); ?>
			</button>
		</div>
		<?php else : ?>
			<p class="st-alert st-alert--warning"><?php esc_html_e( 'Asigna al menos un recinto al torneo para generar la eliminatoria.', 'soccertrack' ); ?></p>
		<?php endif; ?>
	<?php endif; ?>
</div>

<script>
(function() {
	var escHtml = function( s ) { return String( s ).replace( /&/g, '&amp;' ).replace( /</g, '&lt;' ).replace( />/g, '&gt;' ).replace( /"/g, '&quot;' ); };
	var btn   = document.getElementById('st-gen-knockout-btn');
	if ( ! btn ) return;
	btn.addEventListener('click', function() {
		var venueEl = document.getElementById('st-knockout-venue-select');
		var dateEl  = document.getElementById('st-knockout-date-input');
		var venueId = venueEl ? parseInt(venueEl.value, 10) : 0;
		if ( ! venueId ) { alert('Selecciona un recinto.'); return; }

		var tid        = btn.dataset.tournament;
		var nonce      = btn.dataset.nonce;
		var matchDate  = dateEl && dateEl.value ? dateEl.value : null;
		var body       = { venue_id: venueId };
		if ( matchDate ) body.match_date = matchDate;

		btn.disabled = true;
		btn.textContent = '⏳ Generando…';

		fetch('/wp-json/soccertrack/v1/admin/tournament/' + tid + '/knockout', {
			method:  'POST',
			headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': nonce },
			body:    JSON.stringify(body),
		})
		.then(r => r.json())
		.then(data => {
			var notice = document.getElementById('st-knockout-notice');
			if (data.matches_created > 0) {
				notice.innerHTML = '<div class="st-alert st-alert--success">✅ ' + data.matches_created + ' partido(s) generado(s) — ' + data.phase + '. <a href="">Recargar</a></div>';
			} else {
				notice.innerHTML = '<div class="st-alert st-alert--error">⚠️ ' + escHtml( data.message || data.error || 'Error' ) + '</div>';
				btn.disabled = false;
				btn.textContent = '⚡ Reintentar';
			}
		})
		.catch(err => {
			document.getElementById('st-knockout-notice').innerHTML = '<div class="st-alert st-alert--error">⚠️ ' + escHtml( err.message ) + '</div>';
			btn.disabled = false;
		});
	});
}());
</script>
<?php endif; /* is_group_stage */ ?>

<?php /* ── Eliminación Directa (solo formato knockout) ─────────────── */ ?>
<?php if ( ! empty( $knockout_status['is_knockout'] ) ) : ?>
<div class="st-card" style="margin-bottom:20px" id="st-knockout-status-card">
	<div class="st-card-header" style="display:flex;justify-content:space-between;align-items:center">
		<h2 class="st-card-title" style="margin:0"><?php esc_html_e( 'Eliminación Directa', 'soccertrack' ); ?></h2>
	</div>

	<?php if ( ! $knockout_status['has_fixture'] ) : ?>
		<p class="st-empty-msg"><?php esc_html_e( 'El cuadro aún no ha sido generado. Usa el botón "Generar Fixture" de arriba.', 'soccertrack' ); ?></p>

	<?php elseif ( $knockout_status['is_complete'] ) : ?>
		<p style="color:#3CBC20;font-weight:600">🏆 <?php esc_html_e( 'Torneo finalizado.', 'soccertrack' ); ?></p>

	<?php else : ?>
		<p>
			<strong><?php esc_html_e( 'Fase activa:', 'soccertrack' ); ?></strong>
			<?php echo esc_html( $knockout_status['active_phase_label'] ); ?>
		</p>
		<?php if ( $knockout_status['pending_count'] > 0 ) : ?>
			<p class="st-empty-msg">
				<?php printf(
					// translators: %d number of pending matches.
					esc_html__( '%d partido(s) pendiente(s) en esta fase. La siguiente ronda se generará automáticamente al cerrar todos los partidos.', 'soccertrack' ),
					$knockout_status['pending_count']
				); ?>
			</p>
		<?php else : ?>
			<p class="st-empty-msg"><?php esc_html_e( 'Todos los partidos de esta fase han sido cerrados. La siguiente ronda se generará al guardar el próximo resultado.', 'soccertrack' ); ?></p>
		<?php endif; ?>
	<?php endif; ?>
</div>
<?php endif; /* is_knockout */ ?>

<?php /* ── Brackets de Playoffs (solo formato round_robin_playoffs) ─── */ ?>
<?php if ( ( $tournament['format'] ?? '' ) === 'round_robin_playoffs' ) : ?>
<div class="st-card" style="margin-bottom:20px" id="st-brackets-card">
	<div class="st-card-header">
		<h2 class="st-card-title">🏅 <?php esc_html_e( 'Brackets de Playoffs', 'soccertrack' ); ?></h2>
		<span style="font-size:.78rem;color:#999">
			<?php esc_html_e( 'Ej: Copa de Oro (pos 1–4), Copa de Plata (pos 5–8). Exactamente 4 equipos por bracket.', 'soccertrack' ); ?>
		</span>
	</div>

	<div id="st-brackets-notice"></div>

	<?php if ( ! empty( $brackets ) ) : ?>
	<div class="st-table-wrap" style="margin-bottom:16px">
		<table class="st-table" style="min-width:500px">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Nombre', 'soccertrack' ); ?></th>
					<th style="text-align:center"><?php esc_html_e( 'Posiciones', 'soccertrack' ); ?></th>
					<th style="text-align:center"><?php esc_html_e( 'Sorteo', 'soccertrack' ); ?></th>
					<th style="text-align:center"><?php esc_html_e( 'Estado', 'soccertrack' ); ?></th>
					<th><?php esc_html_e( 'Acciones', 'soccertrack' ); ?></th>
				</tr>
			</thead>
			<tbody id="st-brackets-tbody">
			<?php foreach ( $brackets as $b ) : ?>
				<tr id="st-bracket-row-<?php echo esc_attr( (string) $b['id'] ); ?>">
					<td>
						<strong><?php echo esc_html( $b['name'] ); ?></strong>
					</td>
					<td style="text-align:center">
						<?php echo esc_html( $b['rank_from'] . '° – ' . $b['rank_to'] . '°' ); ?>
					</td>
					<td style="text-align:center">
						<?php if ( ( $b['seeding_mode'] ?? 'seeded' ) === 'random' ) : ?>
							<span class="st-badge" style="background:#e8f0ff;color:#1a4db5" title="<?php esc_attr_e( 'Los equipos se sortean al azar', 'soccertrack' ); ?>">
								🎲 <?php esc_html_e( 'Aleatorio', 'soccertrack' ); ?>
							</span>
						<?php else : ?>
							<span class="st-badge" style="background:#f0fae8;color:#2d6a0a" title="<?php esc_attr_e( 'Cuadro tenis: 1° vs último, 2° vs penúltimo…', 'soccertrack' ); ?>">
								🎾 <?php esc_html_e( 'Sembrado', 'soccertrack' ); ?>
							</span>
						<?php endif; ?>
					</td>
					<td style="text-align:center">
						<?php if ( $b['locked'] ) : ?>
							<span class="st-badge" style="background:#e8f4e8;color:#2a7a2a">
								🔒 <?php esc_html_e( 'Bloqueado', 'soccertrack' ); ?>
							</span>
						<?php else : ?>
							<span class="st-badge" style="background:#f5f5f5;color:#666">
								✏️ <?php esc_html_e( 'Editable', 'soccertrack' ); ?>
							</span>
						<?php endif; ?>
					</td>
					<td style="display:flex;gap:6px;flex-wrap:wrap">
						<?php if ( ! $b['locked'] ) : ?>
						<button
							class="st-btn st-btn--sm st-btn--secondary st-bracket-edit-btn"
							data-id="<?php echo esc_attr( (string) $b['id'] ); ?>"
							data-name="<?php echo esc_attr( $b['name'] ); ?>"
							data-from="<?php echo esc_attr( (string) $b['rank_from'] ); ?>"
							data-to="<?php echo esc_attr( (string) $b['rank_to'] ); ?>"
							data-order="<?php echo esc_attr( (string) $b['sort_order'] ); ?>"
							data-seeding="<?php echo esc_attr( $b['seeding_mode'] ?? 'seeded' ); ?>"
							data-tournament="<?php echo esc_attr( (string) $tournament['id'] ); ?>"
							data-nonce="<?php echo esc_attr( wp_create_nonce( 'wp_rest' ) ); ?>"
						>✏️ <?php esc_html_e( 'Editar', 'soccertrack' ); ?></button>
						<button
							class="st-btn st-btn--sm st-btn--danger st-bracket-delete-btn"
							data-id="<?php echo esc_attr( (string) $b['id'] ); ?>"
							data-name="<?php echo esc_attr( $b['name'] ); ?>"
							data-tournament="<?php echo esc_attr( (string) $tournament['id'] ); ?>"
							data-nonce="<?php echo esc_attr( wp_create_nonce( 'wp_rest' ) ); ?>"
						>🗑 <?php esc_html_e( 'Eliminar', 'soccertrack' ); ?></button>
						<?php else : ?>
						<span style="font-size:.78rem;color:#aaa;padding:4px 6px">
							<?php esc_html_e( 'Con partidos generados', 'soccertrack' ); ?>
						</span>
						<?php endif; ?>
					</td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
	</div>
	<?php endif; ?>

	<?php /* Formulario agregar/editar */ ?>
	<div id="st-bracket-form-wrap" style="background:#f9f9f9;border:1px solid #e5e7eb;border-radius:8px;padding:16px">
		<p class="st-label" style="margin:0 0 10px;font-weight:600" id="st-bracket-form-title">
			➕ <?php esc_html_e( 'Agregar bracket', 'soccertrack' ); ?>
		</p>
		<div style="display:flex;gap:12px;flex-wrap:wrap;align-items:flex-end">
			<input type="hidden" id="st-bracket-edit-id" value="">
			<div class="st-field">
				<label class="st-label" style="font-size:.78rem"><?php esc_html_e( 'Nombre', 'soccertrack' ); ?></label>
				<input type="text" id="st-bracket-name" class="st-input" placeholder="<?php esc_attr_e( 'Ej: Copa de Oro', 'soccertrack' ); ?>" style="max-width:180px">
			</div>
			<div class="st-field">
				<label class="st-label" style="font-size:.78rem"><?php esc_html_e( 'Pos. desde', 'soccertrack' ); ?></label>
				<input type="number" id="st-bracket-from" class="st-input" placeholder="1" min="1" max="50" style="max-width:70px;text-align:center">
			</div>
			<div class="st-field">
				<label class="st-label" style="font-size:.78rem"><?php esc_html_e( 'Pos. hasta', 'soccertrack' ); ?></label>
				<input type="number" id="st-bracket-to" class="st-input" placeholder="4" min="2" max="50" style="max-width:70px;text-align:center">
			</div>
			<div class="st-field">
				<label class="st-label" style="font-size:.78rem"><?php esc_html_e( 'Tipo de sorteo', 'soccertrack' ); ?></label>
				<select id="st-bracket-seeding" class="st-input" style="max-width:180px"
					title="<?php esc_attr_e( 'Sembrado: cuadro tenis (1° vs último). Aleatorio: sorteo libre.', 'soccertrack' ); ?>">
					<option value="seeded">🎾 <?php esc_html_e( 'Sembrado (cuadro tenis)', 'soccertrack' ); ?></option>
					<option value="random">🎲 <?php esc_html_e( 'Aleatorio', 'soccertrack' ); ?></option>
				</select>
			</div>
			<div style="display:flex;gap:6px">
				<button
					class="st-btn st-btn--primary st-btn--sm"
					id="st-bracket-save-btn"
					data-tournament="<?php echo esc_attr( (string) $tournament['id'] ); ?>"
					data-nonce="<?php echo esc_attr( wp_create_nonce( 'wp_rest' ) ); ?>"
				>💾 <?php esc_html_e( 'Guardar', 'soccertrack' ); ?></button>
				<button class="st-btn st-btn--sm" id="st-bracket-cancel-btn" style="display:none">
					<?php esc_html_e( 'Cancelar', 'soccertrack' ); ?>
				</button>
			</div>
		</div>
	</div>
</div>

<?php endif; /* is_playoffs_format — brackets card */ ?>

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
					<span style="font-size:.75rem;color:#999;font-weight:600">#<?php echo esc_html( (string) $v['id'] ); ?></span>
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

<?php if ( ( $tournament['format'] ?? '' ) === 'swiss' && ! empty( $swiss_status ) ) : ?>
<div class="st-card" style="margin-bottom:24px">
	<h2 class="st-card-title"><?php esc_html_e( 'Fase Liga Swiss', 'soccertrack' ); ?></h2>

	<?php if ( $swiss_status['swiss_done'] ) : ?>
		<div class="st-alert st-alert--info" style="background:#dbeafe;border-color:#3b82f6;color:#1e3a5f">
			✅ <?php
			echo esc_html(
				sprintf(
					/* translators: %d: total de rondas */
					__( 'Fase liga completa (%d/%d rondas). Configura los brackets de playoffs.', 'soccertrack' ),
					$swiss_status['total_rounds'],
					$swiss_status['total_rounds']
				)
			);
			?>
		</div>

	<?php elseif ( $swiss_status['current_round'] > 0 && $swiss_status['round_complete'] ) : ?>
		<div class="st-alert st-alert--success">
			✅ <?php
			echo esc_html(
				sprintf(
					/* translators: %1$d: ronda completada, %2$d: total de rondas */
					__( 'Ronda %1$d de %2$d completada — todos los resultados ingresados.', 'soccertrack' ),
					$swiss_status['current_round'],
					$swiss_status['total_rounds']
				)
			);
			?>
		</div>
		<?php if ( empty( $is_locked ) ) : ?>
		<button
			class="st-btn st-btn--primary js-swiss-next-round"
			data-tournament-id="<?php echo esc_attr( (string) $tournament['id'] ); ?>"
			data-next-round="<?php echo esc_attr( (string) ( $swiss_status['current_round'] + 1 ) ); ?>"
			data-total-rounds="<?php echo esc_attr( (string) $swiss_status['total_rounds'] ); ?>"
			data-nonce="<?php echo esc_attr( wp_create_nonce( 'wp_rest' ) ); ?>"
			data-api-base="<?php echo esc_attr( esc_url_raw( get_rest_url() ) ); ?>"
			style="margin-top:12px"
		>
			<?php
			echo esc_html(
				sprintf(
					/* translators: %1$d: número de siguiente ronda, %2$d: total */
					__( 'Generar Ronda %1$d de %2$d', 'soccertrack' ),
					$swiss_status['current_round'] + 1,
					$swiss_status['total_rounds']
				)
			);
			?>
		</button>
		<?php endif; ?>

	<?php elseif ( 0 === $swiss_status['current_round'] ) : ?>
		<p class="st-muted" style="margin-bottom:12px"><?php esc_html_e( 'No se ha generado ninguna ronda aún.', 'soccertrack' ); ?></p>
		<?php if ( empty( $is_locked ) ) : ?>
		<button
			class="st-btn st-btn--primary js-swiss-next-round"
			data-tournament-id="<?php echo esc_attr( (string) $tournament['id'] ); ?>"
			data-next-round="1"
			data-total-rounds="<?php echo esc_attr( (string) $swiss_status['total_rounds'] ); ?>"
			data-nonce="<?php echo esc_attr( wp_create_nonce( 'wp_rest' ) ); ?>"
			data-api-base="<?php echo esc_url_raw( get_rest_url() ); ?>"
		>
			<?php
			echo esc_html(
				sprintf(
					/* translators: %d: total de rondas */
					__( 'Generar Ronda 1 de %d', 'soccertrack' ),
					$swiss_status['total_rounds']
				)
			);
			?>
		</button>
		<?php endif; ?>

	<?php else : ?>
		<div class="st-alert" style="background:#fef9c3;border-color:#f59e0b;color:#92400e">
			⏳ <?php
			echo esc_html(
				sprintf(
					/* translators: %1$d: ronda en curso, %2$d: total de rondas */
					__( 'Ronda %1$d de %2$d en curso. Ingresa los resultados para habilitar la siguiente ronda.', 'soccertrack' ),
					$swiss_status['current_round'],
					$swiss_status['total_rounds']
				)
			);
			?>
		</div>
	<?php endif; ?>
</div>

<script>
( function () {
	'use strict';
	document.querySelectorAll( '.js-swiss-next-round' ).forEach( function ( btn ) {
		btn.addEventListener( 'click', function () {
			const tid         = btn.dataset.tournamentId;
			const nextRound   = btn.dataset.nextRound;
			const totalRounds = btn.dataset.totalRounds;
			const nonce       = btn.dataset.nonce;
			const apiBase     = btn.dataset.apiBase;

			if ( ! confirm(
				'¿Generar ronda ' + nextRound + ' de ' + totalRounds + '? ' +
				'Los emparejamientos se calcularán según la tabla actual.'
			) ) {
				return;
			}

			btn.disabled    = true;
			btn.textContent = 'Generando…';

			const venueSelect = document.getElementById( 'st-venue-select' );
			const venueId     = venueSelect ? parseInt( venueSelect.value || '1', 10 ) : 1;

			fetch(
				apiBase + 'soccertrack/v1/admin/tournament/' + tid + '/swiss-round',
				{
					method:  'POST',
					headers: {
						'Content-Type': 'application/json',
						'X-WP-Nonce':   nonce,
					},
					body: JSON.stringify( {
						venue_id: venueId,
					} ),
				}
			)
				.then( function ( r ) { return r.json(); } )
				.then( function ( data ) {
					if ( data.code ) {
						alert( 'Error: ' + ( data.message ?? data.code ) );
						btn.disabled    = false;
						btn.textContent = 'Reintentar';
					} else {
						window.location.reload();
					}
				} )
				.catch( function ( err ) {
					alert( 'Error de red: ' + err.message );
					btn.disabled    = false;
					btn.textContent = 'Reintentar';
				} );
		} );
	} );
}() );
</script>
<?php endif; ?>

<?php /* ── Fixture ───────────────────────────────────────────────────── */ ?>
<div class="st-card">
	<div class="st-card-header">
		<h2 class="st-card-title"><?php esc_html_e( 'Fixture', 'soccertrack' ); ?></h2>
		<?php if ( ! empty( $matches ) ) : ?>
		<?php
		// ── Validaciones para "Asignar fechas y canchas" ────────────────────
		$assign_errors = [];

		if ( count( $teams ) < 2 ) {
			$assign_errors[] = __( 'Se requieren al menos 2 equipos inscritos.', 'soccertrack' );
		}

		$teams_no_players = array_values( array_filter( $teams, fn( $t ) => (int) $t['player_count'] < 1 ) );
		if ( ! empty( $teams_no_players ) ) {
			$names = implode( ', ', array_column( $teams_no_players, 'name' ) );
			/* translators: %s: comma-separated team names */
			$assign_errors[] = sprintf( __( 'Sin jugadores: %s', 'soccertrack' ), $names );
		}

		if ( empty( $saved_days ) ) {
			$assign_errors[] = __( 'Horario sin días seleccionados.', 'soccertrack' );
		}

		if ( empty( $tournament_venue_ids ) ) {
			$assign_errors[] = __( 'Sin recintos seleccionados para este torneo.', 'soccertrack' );
		}

		$can_assign = empty( $assign_errors );
		?>
		<div style="display:flex;flex-direction:column;align-items:flex-end;gap:6px">
			<form method="post" style="display:inline">
				<?php wp_nonce_field( 'st_auto_assign_' . $tournament['id'] ); ?>
				<input type="hidden" name="st_auto_assign" value="1">
				<button
					type="submit"
					class="st-btn st-btn--sm <?php echo $can_assign ? 'st-btn--secondary' : ''; ?>"
					<?php echo $can_assign ? '' : 'disabled style="opacity:.45;cursor:not-allowed"'; ?>
					title="<?php echo $can_assign
						? esc_attr__( 'Recalcula fechas, horarios y canchas según la configuración del torneo', 'soccertrack' )
						: esc_attr( implode( ' · ', $assign_errors ) ); ?>"
				>
					📅 <?php esc_html_e( 'Asignar fechas y canchas', 'soccertrack' ); ?>
				</button>
			</form>
			<?php if ( ! $can_assign ) : ?>
			<ul style="margin:0;padding:0;list-style:none;font-size:.78rem;color:#c0392b;text-align:right">
				<?php foreach ( $assign_errors as $err ) : ?>
				<li>⚠ <?php echo esc_html( $err ); ?></li>
				<?php endforeach; ?>
			</ul>
			<?php endif; ?>
		</div>
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
	<div class="st-table-wrap" style="overflow-x:auto">
		<table class="st-table st-table--fixture" style="table-layout:fixed;min-width:900px;width:100%">
			<colgroup>
				<col style="width:55px">  <?php /* Fecha */ ?>
				<col style="width:14%">   <?php /* Local */ ?>
				<col style="width:70px">  <?php /* Resultado */ ?>
				<col style="width:14%">   <?php /* Visitante */ ?>
				<col style="width:90px">  <?php /* Estado */ ?>
				<col style="width:150px"> <?php /* Horario */ ?>
				<col style="width:70px">  <?php /* Recinto */ ?>
				<col style="width:125px"> <?php /* Cancha */ ?>
				<col>                     <?php /* Acciones — ocupa el resto */ ?>
			</colgroup>
			<thead>
				<tr>
					<th style="text-align:center;padding:8px 4px"><?php esc_html_e( 'Fecha', 'soccertrack' ); ?></th>
					<th><?php esc_html_e( 'Local', 'soccertrack' ); ?></th>
					<th><?php esc_html_e( 'Resultado', 'soccertrack' ); ?></th>
					<th><?php esc_html_e( 'Visitante', 'soccertrack' ); ?></th>
					<th><?php esc_html_e( 'Estado', 'soccertrack' ); ?></th>
					<th><?php esc_html_e( 'Horario', 'soccertrack' ); ?></th>
					<th><?php esc_html_e( 'Recinto', 'soccertrack' ); ?></th>
					<th><?php esc_html_e( 'Cancha', 'soccertrack' ); ?></th>
					<th><?php esc_html_e( 'Acciones', 'soccertrack' ); ?></th>
				</tr>
			</thead>
			<tbody>
			<?php
			$phase_labels = [
				'regular'      => '',
				'octavos'      => '🔟 ' . __( 'Octavos', 'soccertrack' ),
				'quarterfinal' => '⚽ ' . __( 'Cuartos', 'soccertrack' ),
				'semifinal'    => '⚡ ' . __( 'Semi', 'soccertrack' ),
				'third_place'  => '🥉 ' . __( '3.er Puesto', 'soccertrack' ),
				'final'        => '🏆 ' . __( 'Final', 'soccertrack' ),
			];

			// Separadores de sección: se inserta una fila cabecera al cambiar de bloque (fase + bracket).
			$section_phase_labels = [
				'octavos'      => __( 'Octavos de Final', 'soccertrack' ),
				'quarterfinal' => __( 'Cuartos de Final', 'soccertrack' ),
				'semifinal'    => __( 'Semifinales', 'soccertrack' ),
				'third_place'  => __( 'Tercer Puesto', 'soccertrack' ),
				'final'        => __( 'Final', 'soccertrack' ),
			];
			$section_colors = [
				'regular' => [ 'bg' => '#e8f4e8', 'color' => '#1a5c1a' ],
				'playoff'  => [ 'bg' => '#fff3e0', 'color' => '#7a3800' ],
				'final'    => [ 'bg' => '#fef3c7', 'color' => '#7a4400' ],
			];

			$playoff_phases  = [ 'octavos', 'quarterfinal', 'semifinal', 'third_place', 'final' ];
			$prev_section    = null;
			$prev_round      = null; // Para detectar cambio de ronda en fase regular.
			$cols            = 9; // número de columnas de la tabla

			// Lista plana de canchas del torneo para el panel de reasignación.
			$all_tournament_courts = [];
			foreach ( $courts_by_venue as $v_courts ) {
				foreach ( $v_courts as $c ) {
					$all_tournament_courts[] = $c;
				}
			}
			?>
			<?php foreach ( $matches as $m ) : ?>
				<?php
				$phase_cur    = $m['phase'] ?? 'regular';
				$bracket_id   = $m['bracket_id'] ?? '';
				$bracket_name = $m['bracket_name'] ?? '';
				$is_playoff   = in_array( $phase_cur, $playoff_phases, true );

				// Clave única por fase + bracket para detectar cambio de sección.
				$section_key = $phase_cur . '_' . $bracket_id;

				// Insertar fila separadora cuando cambia la fase o el bracket.
				if ( $section_key !== $prev_section ) :
					if ( $phase_cur === 'regular' ) :
						$sec_label = '📅 ' . __( 'Fase Regular — Todos contra todos', 'soccertrack' );
						$sec_colors = $section_colors['regular'];
					else :
						$phase_label = $section_phase_labels[ $phase_cur ] ?? $phase_cur;
						$prefix = $bracket_name ? strtoupper( $bracket_name ) : 'PLAY-OFFS';
						$sec_label  = '🏆 ' . $prefix . ' — ' . $phase_label;
						$sec_colors = ( $phase_cur === 'final' && ! $bracket_name ) ? $section_colors['final'] : $section_colors['playoff'];
					endif;
				?>
				<tr>
					<td colspan="<?php echo (int) $cols; ?>"
						style="padding:10px 14px;font-weight:700;font-size:.82rem;letter-spacing:.05em;text-transform:uppercase;
						       background:<?php echo esc_attr( $sec_colors['bg'] ); ?>;
						       color:<?php echo esc_attr( $sec_colors['color'] ); ?>;
						       border-top:2px solid <?php echo esc_attr( $sec_colors['color'] ); ?>30;
						       border-bottom:1px solid <?php echo esc_attr( $sec_colors['color'] ); ?>30">
						<?php echo esc_html( $sec_label ); ?>
					</td>
				</tr>
				<?php
					$prev_section = $section_key;
				endif;
				?>
				<?php
				// ── Fila cabecera de ronda (solo fase regular, al cambiar round_number) ──
				if ( $phase_cur === 'regular' && $m['round_number'] !== $prev_round ) :
					$rn = (int) $m['round_number'];
				?>
				<tr id="st-round-row-<?php echo $rn; ?>">
					<td colspan="<?php echo (int) $cols; ?>"
						style="padding:6px 14px;background:#f0f4f0;border-top:1px solid #c8d8c8;border-bottom:1px solid #c8d8c8">
						<div style="display:flex;align-items:center;gap:10px">
							<span style="font-weight:700;font-size:.82rem;color:#1a5c1a">
								<?php
								/* translators: %d: número de jornada */
								printf( esc_html__( 'Jornada %d', 'soccertrack' ), $rn );
								?>
							</span>
							<?php if ( empty( $is_locked ) && ! empty( $all_tournament_courts ) ) : ?>
							<button
								type="button"
								class="st-btn st-btn--sm st-btn--secondary st-round-courts-toggle"
								data-round="<?php echo $rn; ?>"
								style="padding:2px 8px;font-size:.78rem"
							>⚙ <?php esc_html_e( 'Canchas', 'soccertrack' ); ?></button>
							<?php endif; ?>
						</div>

						<?php if ( empty( $is_locked ) && ! empty( $all_tournament_courts ) ) : ?>
						<div
							id="st-courts-panel-<?php echo $rn; ?>"
							style="display:none;margin-top:10px;padding:12px;background:#fff;border:1px solid #c8d8c8;border-radius:6px"
						>
							<p style="margin:0 0 8px;font-size:.82rem;font-weight:600;color:#1a5c1a">
								<?php esc_html_e( 'Canchas disponibles para esta fecha:', 'soccertrack' ); ?>
							</p>
							<form method="post" style="display:flex;flex-wrap:wrap;gap:10px 20px;align-items:flex-end">
								<?php wp_nonce_field( 'st_reassign_round_courts_' . $tournament['id'] . '_' . $rn ); ?>
								<input type="hidden" name="st_reassign_round_courts" value="1">
								<input type="hidden" name="tournament_id" value="<?php echo esc_attr( (string) $tournament['id'] ); ?>">
								<input type="hidden" name="round_number" value="<?php echo esc_attr( (string) $rn ); ?>">

								<div style="display:flex;flex-wrap:wrap;gap:8px 16px;flex:1">
								<?php foreach ( $all_tournament_courts as $court ) : ?>
									<label style="display:flex;align-items:center;gap:5px;font-size:.82rem;cursor:pointer">
										<input
											type="checkbox"
											name="court_ids[]"
											value="<?php echo esc_attr( (string) $court['id'] ); ?>"
											checked
										>
										<?php echo esc_html( $court['court_name'] ); ?>
									</label>
								<?php endforeach; ?>
								</div>

								<div style="display:flex;gap:8px;flex-shrink:0">
									<button type="submit" class="st-btn st-btn--sm st-btn--primary">
										<?php esc_html_e( 'Reasignar', 'soccertrack' ); ?>
									</button>
									<button
										type="button"
										class="st-btn st-btn--sm st-btn--secondary st-round-courts-cancel"
										data-round="<?php echo $rn; ?>"
									>
										<?php esc_html_e( 'Cancelar', 'soccertrack' ); ?>
									</button>
								</div>
							</form>
						</div>
						<?php endif; ?>
					</td>
				</tr>
				<?php
					$prev_round = $m['round_number'];
				endif;
				?>
				<tr<?php if ( $is_playoff ) : ?> style="background:#fffaf2"<?php endif; ?>>
					<td style="text-align:center;font-weight:700;padding:8px 4px;font-size:.85rem">
						<?php
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
						<span style="font-size:.78rem;color:#555">
							<?php echo $dt ? esc_html( date_i18n( 'd/m H:i', strtotime( $dt ) ) ) : '—'; ?>
						</span>
						<span title="<?php esc_attr_e( 'No se puede modificar con menos de 1 hora de anticipación', 'soccertrack' ); ?>"
							  style="font-size:.8rem;color:#e67e22;margin-left:4px">🔒</span>
						<?php else : ?>
						<form method="post" action="" style="display:flex;align-items:center;gap:4px">
							<?php wp_nonce_field( 'st_update_datetime_' . $tournament['id'] ); ?>
							<input type="hidden" name="st_update_datetime" value="1">
							<input type="hidden" name="match_id" value="<?php echo esc_attr( (string) $m['id'] ); ?>">
							<input
								type="datetime-local"
								name="match_datetime"
								class="st-input st-fixture-dt-input"
								value="<?php echo esc_attr( $dt ? substr( str_replace( ' ', 'T', $dt ), 0, 16 ) : '' ); ?>"
								style="max-width:148px;font-size:.78rem;padding:3px 5px"
							>
							<button type="submit" class="st-btn st-btn--sm st-btn--secondary" title="<?php esc_attr_e( 'Guardar horario', 'soccertrack' ); ?>">✔</button>
						</form>
						<?php endif; ?>
						<?php else :
							echo $dt ? '<span style="font-size:.82rem">' . esc_html( date_i18n( 'd/m H:i', strtotime( $dt ) ) ) . '</span>' : '—';
						endif; ?>
					</td>
					<td style="text-align:center">
						<?php
						$v_id = (int) ( $m['venue_id'] ?? 0 );
						if ( $v_id ) :
							$v_name = '';
							foreach ( $venues as $v ) {
								if ( (int) $v['id'] === $v_id ) { $v_name = $v['name']; break; }
							}
							echo '<span title="' . esc_attr( $v_name ) . '" style="font-size:.82rem;font-weight:600;color:#555;cursor:default">#' . esc_html( (string) $v_id ) . '</span>';
						else :
							echo '<span style="color:#bbb">—</span>';
						endif;
						?>
					</td>
					<td>
						<?php
						$venue_courts = $courts_by_venue[ (int) ( $m['venue_id'] ?? 0 ) ] ?? [];
						if ( $m['status'] !== 'finished' && ! empty( $venue_courts ) ) :
						?>
						<form method="post" action="" style="display:flex;align-items:center;gap:4px">
							<?php wp_nonce_field( 'st_update_court_' . $tournament['id'] ); ?>
							<input type="hidden" name="st_update_court" value="1">
							<input type="hidden" name="match_id" value="<?php echo esc_attr( (string) $m['id'] ); ?>">
							<select name="court_id" class="st-input st-fixture-court-select" style="max-width:110px;font-size:.78rem;padding:3px 5px">
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
					<td style="white-space:nowrap">
						<a href="<?php echo esc_url( home_url( '/panel/partido/' . $m['id'] . '/' ) ); ?>" class="st-btn st-btn--sm st-btn--primary">
							📝 <?php esc_html_e( 'Resultado', 'soccertrack' ); ?>
						</a>
						<?php if ( $m['status'] === 'finished' ) : ?>
						<form method="post" style="display:inline;margin-left:4px">
							<?php wp_nonce_field( 'st_change_match_status_' . $tournament['id'] ); ?>
							<input type="hidden" name="st_change_match_status" value="1">
							<input type="hidden" name="match_id" value="<?php echo esc_attr( (string) $m['id'] ); ?>">
							<input type="hidden" name="new_status" value="scheduled">
							<button type="submit" class="st-btn st-btn--sm st-btn--danger" title="<?php esc_attr_e( 'Reabrir partido', 'soccertrack' ); ?>">
								↩ <?php esc_html_e( 'Reabrir', 'soccertrack' ); ?>
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

<?php /* ── Play-offs ───────────────────────────────────────────────────── */ ?>
<?php if ( ! empty( $playoffs_status['is_playoffs_format'] ) ) : ?>
<?php
$venues_for_select ??= ! empty( $tournament_venue_ids )
	? array_values( array_filter( $venues, fn( $v ) => in_array( (int) $v['id'], $tournament_venue_ids, true ) ) )
	: $venues;
$has_brackets = ! empty( $brackets );
?>
<div class="st-card">
	<div class="st-card-header" style="display:flex;justify-content:space-between;align-items:center">
		<h2 class="st-card-title" style="margin:0">🏆 <?php esc_html_e( 'Play-offs', 'soccertrack' ); ?></h2>
		<?php if ( $playoffs_status['has_semifinals'] || $playoffs_status['has_finals'] ) : ?>
		<button
			class="st-btn"
			id="st-reset-playoffs-btn"
			style="background:#dc2626;color:#fff;font-size:.8rem;padding:5px 12px"
			data-tournament="<?php echo esc_attr( (string) $tournament['id'] ); ?>"
			data-nonce="<?php echo esc_attr( wp_create_nonce( 'wp_rest' ) ); ?>"
		>🗑 <?php esc_html_e( 'Reiniciar Fase Eliminatoria', 'soccertrack' ); ?></button>
		<?php endif; ?>
	</div>

	<div id="st-playoffs-notice"></div>

	<?php if ( ! $playoffs_status['all_regular_done'] ) : ?>
		<p class="st-empty-msg"><?php esc_html_e( 'Las semi-finales estarán disponibles cuando todos los partidos de la fase regular estén finalizados.', 'soccertrack' ); ?></p>

	<?php elseif ( $has_brackets ) : ?>
		<?php /* Modo brackets: un bloque por bracket */ ?>
		<?php if ( empty( $venues_for_select ) ) : ?>
			<p><a href="<?php echo esc_url( home_url( '/panel/recintos/' ) ); ?>"><?php esc_html_e( '→ Crea un recinto primero', 'soccertrack' ); ?></a></p>
		<?php else : ?>
		<p style="margin-bottom:16px;color:#3C3A47">
			<?php esc_html_e( 'Fase regular finalizada. Genera los playoffs por bracket.', 'soccertrack' ); ?>
		</p>
		<div style="display:flex;flex-direction:column;gap:14px">
		<?php foreach ( $brackets as $b ) : ?>
			<div style="background:#f9f9f9;border:1px solid #e5e7eb;border-radius:8px;padding:14px 16px">
				<div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px">
					<div>
						<strong style="font-size:1rem"><?php echo esc_html( $b['name'] ); ?></strong>
						<span style="font-size:.78rem;color:#888;margin-left:8px">
							(<?php echo esc_html( $b['rank_from'] . '° – ' . $b['rank_to'] . '°' ); ?>)
						</span>
					</div>
					<div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap">
						<?php if ( $b['has_finals'] ) : ?>
							<span style="color:#3CBC20;font-weight:600">✅ <?php esc_html_e( 'Completo', 'soccertrack' ); ?></span>

						<?php elseif ( $b['has_semis'] && ! $b['semis_done'] ) : ?>
							<span style="font-size:.85rem;color:#888">
								<?php esc_html_e( 'Semi-finales en curso…', 'soccertrack' ); ?>
							</span>

						<?php elseif ( $b['semis_done'] ) : ?>
							<select class="st-input st-bracket-venue-select" style="max-width:200px" data-bracket="<?php echo esc_attr( (string) $b['id'] ); ?>">
								<option value=""><?php esc_html_e( '— Recinto —', 'soccertrack' ); ?></option>
								<?php foreach ( $venues_for_select as $v ) : ?>
									<option value="<?php echo esc_attr( (string) $v['id'] ); ?>"><?php echo esc_html( $v['name'] ); ?></option>
								<?php endforeach; ?>
							</select>
							<input
								type="date"
								class="st-input st-bracket-date-input"
								data-bracket="<?php echo esc_attr( (string) $b['id'] ); ?>"
								style="max-width:140px"
								title="<?php esc_attr_e( 'Fecha del partido (opcional)', 'soccertrack' ); ?>"
							>
							<button
								class="st-btn st-btn--primary st-bracket-gen-btn"
								data-tournament="<?php echo esc_attr( (string) $tournament['id'] ); ?>"
								data-bracket="<?php echo esc_attr( (string) $b['id'] ); ?>"
								data-nonce="<?php echo esc_attr( wp_create_nonce( 'wp_rest' ) ); ?>"
								data-endpoint="finals"
							>🏆 <?php esc_html_e( 'Generar Final y 3.er Puesto', 'soccertrack' ); ?></button>

						<?php elseif ( $b['has_quarterfinals'] && ! $b['quarterfinals_done'] ) : ?>
							<span style="font-size:.85rem;color:#888">
								<?php esc_html_e( 'Cuartos de final en curso…', 'soccertrack' ); ?>
							</span>

						<?php elseif ( $b['quarterfinals_done'] && ! $b['has_semis'] ) : ?>
							<select class="st-input st-bracket-venue-select" style="max-width:200px" data-bracket="<?php echo esc_attr( (string) $b['id'] ); ?>">
								<option value=""><?php esc_html_e( '— Recinto —', 'soccertrack' ); ?></option>
								<?php foreach ( $venues_for_select as $v ) : ?>
									<option value="<?php echo esc_attr( (string) $v['id'] ); ?>"><?php echo esc_html( $v['name'] ); ?></option>
								<?php endforeach; ?>
							</select>
							<input
								type="date"
								class="st-input st-bracket-date-input"
								data-bracket="<?php echo esc_attr( (string) $b['id'] ); ?>"
								style="max-width:140px"
								title="<?php esc_attr_e( 'Fecha del partido (opcional)', 'soccertrack' ); ?>"
							>
							<button
								class="st-btn st-btn--primary st-bracket-gen-btn"
								data-tournament="<?php echo esc_attr( (string) $tournament['id'] ); ?>"
								data-bracket="<?php echo esc_attr( (string) $b['id'] ); ?>"
								data-nonce="<?php echo esc_attr( wp_create_nonce( 'wp_rest' ) ); ?>"
								data-endpoint="playoffs"
							>⚡ <?php esc_html_e( 'Generar Semi-finales', 'soccertrack' ); ?></button>

						<?php else : ?>
							<select class="st-input st-bracket-venue-select" style="max-width:200px" data-bracket="<?php echo esc_attr( (string) $b['id'] ); ?>">
								<option value=""><?php esc_html_e( '— Recinto —', 'soccertrack' ); ?></option>
								<?php foreach ( $venues_for_select as $v ) : ?>
									<option value="<?php echo esc_attr( (string) $v['id'] ); ?>"><?php echo esc_html( $v['name'] ); ?></option>
								<?php endforeach; ?>
							</select>
							<input
								type="date"
								class="st-input st-bracket-date-input"
								data-bracket="<?php echo esc_attr( (string) $b['id'] ); ?>"
								style="max-width:140px"
								title="<?php esc_attr_e( 'Fecha del partido (opcional)', 'soccertrack' ); ?>"
							>
							<button
								class="st-btn st-btn--primary st-bracket-gen-btn"
								data-tournament="<?php echo esc_attr( (string) $tournament['id'] ); ?>"
								data-bracket="<?php echo esc_attr( (string) $b['id'] ); ?>"
								data-nonce="<?php echo esc_attr( wp_create_nonce( 'wp_rest' ) ); ?>"
								data-endpoint="playoffs"
							><?php
								// Label dinámico según tamaño del bracket.
								echo $b['num_teams'] >= 8
									? '⚽ ' . esc_html__( 'Generar Cuartos', 'soccertrack' )
									: '⚡ ' . esc_html__( 'Generar Semi-finales', 'soccertrack' );
							?></button>
						<?php endif; ?>
					</div>
				</div>
			</div>
		<?php endforeach; ?>
		</div>
		<?php endif; ?>

	<?php else : ?>
		<?php /* Modo clásico (sin brackets) */ ?>
		<?php if ( ! $playoffs_status['has_semifinals'] ) : ?>
			<p style="margin-bottom:12px;color:#3C3A47">
				<?php esc_html_e( 'Fase regular finalizada. Puedes generar las semi-finales con los 4 mejores equipos de la tabla.', 'soccertrack' ); ?>
			</p>
			<?php if ( ! empty( $venues_for_select ) ) : ?>
			<div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap">
				<select id="st-playoff-venue-select" class="st-input" style="max-width:220px">
					<option value=""><?php esc_html_e( '— Seleccionar recinto —', 'soccertrack' ); ?></option>
					<?php foreach ( $venues_for_select as $v ) : ?>
						<option value="<?php echo esc_attr( (string) $v['id'] ); ?>"><?php echo esc_html( $v['name'] ); ?></option>
					<?php endforeach; ?>
				</select>
				<button class="st-btn st-btn--primary" id="st-gen-playoffs-btn"
					data-tournament="<?php echo esc_attr( (string) $tournament['id'] ); ?>"
					data-nonce="<?php echo esc_attr( wp_create_nonce( 'wp_rest' ) ); ?>"
					data-endpoint="playoffs">
					⚡ <?php esc_html_e( 'Generar Semi-finales', 'soccertrack' ); ?>
				</button>
			</div>
			<?php else : ?>
				<p><a href="<?php echo esc_url( home_url( '/panel/recintos/' ) ); ?>"><?php esc_html_e( '→ Crea un recinto primero', 'soccertrack' ); ?></a></p>
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
							<option value="<?php echo esc_attr( (string) $v['id'] ); ?>"><?php echo esc_html( $v['name'] ); ?></option>
						<?php endforeach; ?>
					</select>
					<button class="st-btn st-btn--primary" id="st-gen-playoffs-btn"
						data-tournament="<?php echo esc_attr( (string) $tournament['id'] ); ?>"
						data-nonce="<?php echo esc_attr( wp_create_nonce( 'wp_rest' ) ); ?>"
						data-endpoint="finals">
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
	<?php endif; ?>
</div>

<script>
(function() {
	var escHtml = function( s ) { return String( s ).replace( /&/g, '&amp;' ).replace( /</g, '&lt;' ).replace( />/g, '&gt;' ).replace( /"/g, '&quot;' ); };
	var resetBtn = document.getElementById('st-reset-playoffs-btn');
	if ( ! resetBtn ) return;
	resetBtn.addEventListener('click', function() {
		if ( ! confirm('<?php echo esc_js( __( '¿Eliminar todos los partidos de Play-offs (semi-finales, final, 3.er puesto)? Esta acción no se puede deshacer.', 'soccertrack' ) ); ?>') ) return;

		var tid   = resetBtn.dataset.tournament;
		var nonce = resetBtn.dataset.nonce;
		resetBtn.disabled = true;
		resetBtn.textContent = '⏳ Eliminando…';

		fetch('/wp-json/soccertrack/v1/admin/tournament/' + tid + '/playoffs', {
			method:  'DELETE',
			headers: { 'X-WP-Nonce': nonce },
		})
		.then(function(r) { return r.json(); })
		.then(function(data) {
			var notice = document.getElementById('st-playoffs-notice');
			if ( data.deleted ) {
				notice.innerHTML = '<div class="st-alert st-alert--success">✅ <?php echo esc_js( __( 'Fase eliminatoria reiniciada.', 'soccertrack' ) ); ?> <a href="">Recargar</a></div>';
				resetBtn.style.display = 'none';
			} else {
				notice.innerHTML = '<div class="st-alert st-alert--error">⚠️ ' + escHtml( data.message || data.error || 'Error' ) + '</div>';
				resetBtn.disabled = false;
				resetBtn.textContent = '🗑 <?php echo esc_js( __( 'Reiniciar Fase Eliminatoria', 'soccertrack' ) ); ?>';
			}
		})
		.catch(function(err) {
			document.getElementById('st-playoffs-notice').innerHTML = '<div class="st-alert st-alert--error">⚠️ ' + escHtml( err.message ) + '</div>';
			resetBtn.disabled = false;
		});
	});
}());
</script>
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

<?php /* ── Banner del torneo ─────────────────────────────────────────── */ ?>
<div class="st-card" style="margin-bottom:20px">
	<div class="st-card-header">
		<h2 class="st-card-title">🖼️ <?php esc_html_e( 'Banner del torneo', 'soccertrack' ); ?></h2>
	</div>

	<?php if ( ! empty( $tournament['banner_url'] ) ) : ?>
	<div style="margin-bottom:16px">
		<img
			src="<?php echo esc_url( $tournament['banner_url'] ); ?>"
			alt="<?php esc_attr_e( 'Banner actual', 'soccertrack' ); ?>"
			style="max-width:100%;height:auto;border-radius:6px;border:1px solid #e5e7eb;display:block"
		>
	</div>
	<form method="post" action="" style="margin-bottom:16px">
		<?php wp_nonce_field( 'st_delete_banner_' . $tournament['id'] ); ?>
		<input type="hidden" name="st_delete_banner" value="1">
		<button
			type="submit"
			class="st-btn"
			style="background:#dc2626;color:#fff;border:none"
			onclick="return confirm('<?php esc_attr_e( '¿Eliminar el banner actual?', 'soccertrack' ); ?>')"
		>
			🗑️ <?php esc_html_e( 'Eliminar banner', 'soccertrack' ); ?>
		</button>
	</form>
	<hr style="margin:0 0 16px;border:none;border-top:1px solid #e5e7eb">
	<?php endif; ?>

	<form method="post" action="" enctype="multipart/form-data">
		<?php wp_nonce_field( 'st_save_banner_' . $tournament['id'] ); ?>
		<input type="hidden" name="st_save_banner" value="1">

		<div class="st-field" style="margin-bottom:16px">
			<label for="st-banner-file" class="st-label">
				📤 <?php empty( $tournament['banner_url'] )
					? esc_html_e( 'Seleccionar imagen', 'soccertrack' )
					: esc_html_e( 'Reemplazar imagen', 'soccertrack' ); ?>
			</label>
			<input
				type="file"
				id="st-banner-file"
				name="banner_file"
				class="st-input"
				accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp"
				onchange="stPreviewBanner(this)"
			>
			<small class="st-hint">
				<?php esc_html_e( 'Formatos: JPG, PNG, WebP — Máx. 5 MB — Dimensión recomendada: 1920 × 300 px', 'soccertrack' ); ?>
			</small>
		</div>

		<div id="st-banner-preview-box" style="display:none;margin-bottom:16px">
			<p class="st-label" style="margin-bottom:8px"><?php esc_html_e( 'Vista previa:', 'soccertrack' ); ?></p>
			<img
				id="st-banner-preview-img"
				src=""
				alt=""
				style="max-width:100%;height:auto;border-radius:6px;border:1px solid #e5e7eb;display:block"
			>
		</div>

		<button type="submit" class="st-btn st-btn--primary">
			💾 <?php esc_html_e( 'Guardar banner', 'soccertrack' ); ?>
		</button>
	</form>
</div>

<script>
function stPreviewBanner(input) {
	var box = document.getElementById('st-banner-preview-box');
	var img = document.getElementById('st-banner-preview-img');
	if (input.files && input.files[0]) {
		var reader = new FileReader();
		reader.onload = function(e) {
			img.src = e.target.result;
			box.style.display = '';
		};
		reader.readAsDataURL(input.files[0]);
	} else {
		box.style.display = 'none';
	}
}
</script>

<script>
( () => {
	const escHtml = s => String( s ).replace( /&/g, '&amp;' ).replace( /</g, '&lt;' ).replace( />/g, '&gt;' ).replace( /"/g, '&quot;' );
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
				notice.innerHTML = `<div class="st-alert st-alert--error">${ escHtml( data.message ?? 'Error' ) }</div>`;
				btn.disabled = false;
				btn.textContent = '⚡ <?php esc_html_e( 'Generar fixture', 'soccertrack' ); ?>';
			} else {
				notice.innerHTML = `<div class="st-alert st-alert--success"><?php esc_html_e( 'Fixture generado:', 'soccertrack' ); ?> ${data.matches_created} <?php esc_html_e( 'partidos. Recargando…', 'soccertrack' ); ?></div>`;
				setTimeout( () => location.reload(), 1500 );
			}
		} catch ( e ) {
			notice.innerHTML = `<div class="st-alert st-alert--error">${ escHtml( e.message ) }</div>`;
			btn.disabled = false;
		}
	} );
} )();

( () => {
	const escHtml = s => String( s ).replace( /&/g, '&amp;' ).replace( /</g, '&lt;' ).replace( />/g, '&gt;' ).replace( /"/g, '&quot;' );
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
				notice.innerHTML = `<div class="st-alert st-alert--error">${ escHtml( data.message ?? 'Error' ) }</div>`;
				btn.disabled = false;
				btn.textContent = btn.dataset.endpoint === 'playoffs' ? '⚡ <?php esc_html_e( 'Generar Semi-finales', 'soccertrack' ); ?>' : '🏆 <?php esc_html_e( 'Generar Final y 3.er Puesto', 'soccertrack' ); ?>';
			} else {
				notice.innerHTML = `<div class="st-alert st-alert--success"><?php esc_html_e( 'Partidos generados:', 'soccertrack' ); ?> ${data.matches_created}. <?php esc_html_e( 'Recargando…', 'soccertrack' ); ?></div>`;
				setTimeout( () => location.reload(), 1500 );
			}
		} catch ( e ) {
			notice.innerHTML = `<div class="st-alert st-alert--error">${ escHtml( e.message ) }</div>`;
			btn.disabled = false;
		}
	} );
} )();

/* ── Brackets CRUD ────────────────────────────────────────────────── */
( () => {
	const escHtml = s => String( s ).replace( /&/g, '&amp;' ).replace( /</g, '&lt;' ).replace( />/g, '&gt;' ).replace( /"/g, '&quot;' );
	const REST_BASE  = '<?php echo esc_js( get_rest_url() ); ?>soccertrack/v1/admin/tournament/<?php echo esc_js( (string) $tournament['id'] ); ?>/brackets';
	const notice     = document.getElementById( 'st-brackets-notice' );
	const saveBtn    = document.getElementById( 'st-bracket-save-btn' );
	const cancelBtn  = document.getElementById( 'st-bracket-cancel-btn' );
	const editIdIn   = document.getElementById( 'st-bracket-edit-id' );
	const nameIn     = document.getElementById( 'st-bracket-name' );
	const fromIn     = document.getElementById( 'st-bracket-from' );
	const toIn       = document.getElementById( 'st-bracket-to' );
	const formTitle  = document.getElementById( 'st-bracket-form-title' );
	const seedingIn  = document.getElementById( 'st-bracket-seeding' );

	function showNotice( html ) {
		notice.innerHTML = html;
		notice.scrollIntoView( { behavior: 'smooth', block: 'nearest' } );
	}

	function resetForm() {
		editIdIn.value  = '';
		nameIn.value    = '';
		fromIn.value    = '';
		toIn.value      = '';
		if ( seedingIn ) seedingIn.value = 'seeded';
		formTitle.textContent   = '➕ <?php esc_html_e( 'Agregar bracket', 'soccertrack' ); ?>';
		cancelBtn.style.display = 'none';
	}

	/* Guardar (crear o editar) */
	if ( saveBtn ) {
		saveBtn.addEventListener( 'click', async () => {
			const name    = nameIn.value.trim();
			const from    = parseInt( fromIn.value, 10 );
			const to      = parseInt( toIn.value, 10 );
			const eid     = editIdIn.value;
			const nonce   = saveBtn.dataset.nonce;
			const seeding = seedingIn ? seedingIn.value : 'seeded';

			if ( ! name || ! from || ! to ) {
				showNotice( '<div class="st-alert st-alert--error"><?php esc_html_e( 'Completa nombre y posiciones.', 'soccertrack' ); ?></div>' );
				return;
			}

			saveBtn.disabled = true;
			try {
				const url         = eid ? `${REST_BASE}/${eid}` : REST_BASE;
				const method      = eid ? 'PATCH' : 'POST';
				const resp        = await fetch( url, {
					method,
					credentials: 'include',
					headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': nonce },
					body: JSON.stringify( { name, rank_from: from, rank_to: to, seeding_mode: seeding, sort_order: eid ? undefined : 0 } ),
				} );
				const data = await resp.json();
				if ( ! resp.ok ) {
					showNotice( `<div class="st-alert st-alert--error">${ escHtml( data.message ?? 'Error' ) }</div>` );
				} else {
					showNotice( '<div class="st-alert st-alert--success"><?php esc_html_e( 'Bracket guardado. Recargando…', 'soccertrack' ); ?></div>' );
					setTimeout( () => location.reload(), 900 );
				}
			} catch ( e ) {
				showNotice( `<div class="st-alert st-alert--error">${ escHtml( e.message ) }</div>` );
			}
			saveBtn.disabled = false;
		} );
	}

	/* Cancelar edición */
	if ( cancelBtn ) cancelBtn.addEventListener( 'click', resetForm );

	/* Editar */
	document.querySelectorAll( '.st-bracket-edit-btn' ).forEach( btn => {
		btn.addEventListener( 'click', () => {
			editIdIn.value = btn.dataset.id;
			nameIn.value   = btn.dataset.name;
			fromIn.value   = btn.dataset.from;
			toIn.value     = btn.dataset.to;
			if ( seedingIn ) seedingIn.value = btn.dataset.seeding || 'seeded';
			formTitle.textContent   = '✏️ <?php esc_html_e( 'Editar bracket', 'soccertrack' ); ?>';
			cancelBtn.style.display = '';
			document.getElementById( 'st-bracket-form-wrap' ).scrollIntoView( { behavior: 'smooth', block: 'nearest' } );
		} );
	} );

	/* Eliminar */
	document.querySelectorAll( '.st-bracket-delete-btn' ).forEach( btn => {
		btn.addEventListener( 'click', async () => {
			if ( ! confirm( `<?php esc_html_e( '¿Eliminar el bracket', 'soccertrack' ); ?> "${btn.dataset.name}"?` ) ) return;
			btn.disabled = true;
			try {
				const resp = await fetch( `${REST_BASE}/${btn.dataset.id}`, {
					method: 'DELETE',
					credentials: 'include',
					headers: { 'X-WP-Nonce': btn.dataset.nonce },
				} );
				if ( resp.ok ) {
					document.getElementById( `st-bracket-row-${btn.dataset.id}` )?.remove();
					showNotice( '<div class="st-alert st-alert--success"><?php esc_html_e( 'Bracket eliminado.', 'soccertrack' ); ?></div>' );
				} else {
					const d = await resp.json();
					showNotice( `<div class="st-alert st-alert--error">${ escHtml( d.message ?? 'Error' ) }</div>` );
					btn.disabled = false;
				}
			} catch ( e ) {
				showNotice( `<div class="st-alert st-alert--error">${ escHtml( e.message ) }</div>` );
				btn.disabled = false;
			}
		} );
	} );
} )();

/* ── Generar playoffs por bracket ─────────────────────────────────── */
( () => {
	const escHtml = s => String( s ).replace( /&/g, '&amp;' ).replace( /</g, '&lt;' ).replace( />/g, '&gt;' ).replace( /"/g, '&quot;' );
	const notice = document.getElementById( 'st-playoffs-notice' );
	document.querySelectorAll( '.st-bracket-gen-btn' ).forEach( btn => {
		btn.addEventListener( 'click', async () => {
			const tid      = btn.dataset.tournament;
			const bid      = btn.dataset.bracket;
			const nonce    = btn.dataset.nonce;
			const endpoint = btn.dataset.endpoint;
			const venueEl  = document.querySelector( `.st-bracket-venue-select[data-bracket="${bid}"]` );
			const venueId  = parseInt( venueEl?.value ?? '0', 10 );
			const dateEl   = document.querySelector( `.st-bracket-date-input[data-bracket="${bid}"]` );
			const matchDate = dateEl?.value || null;

			if ( ! venueId ) {
				notice.innerHTML = '<div class="st-alert st-alert--error"><?php esc_html_e( 'Selecciona un recinto.', 'soccertrack' ); ?></div>';
				return;
			}

			btn.disabled = true;
			const orig = btn.textContent;
			btn.textContent = '<?php esc_html_e( 'Generando…', 'soccertrack' ); ?>';

			try {
				const resp = await fetch( `<?php echo esc_js( get_rest_url() ); ?>soccertrack/v1/admin/tournament/${tid}/${endpoint}`, {
					method: 'POST',
					credentials: 'include',
					headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': nonce },
					body: JSON.stringify( Object.assign( { bracket_id: parseInt( bid, 10 ), venue_id: venueId }, matchDate ? { match_date: matchDate } : {} ) ),
				} );
				const data = await resp.json();
				if ( ! resp.ok ) {
					notice.innerHTML = `<div class="st-alert st-alert--error">${ escHtml( data.message ?? 'Error' ) }</div>`;
					btn.disabled = false;
					btn.textContent = orig;
				} else {
					notice.innerHTML = `<div class="st-alert st-alert--success"><?php esc_html_e( 'Partidos generados:', 'soccertrack' ); ?> ${data.matches_created}. <?php esc_html_e( 'Recargando…', 'soccertrack' ); ?></div>`;
					setTimeout( () => location.reload(), 1500 );
				}
			} catch ( e ) {
				notice.innerHTML = `<div class="st-alert st-alert--error">${ escHtml( e.message ) }</div>`;
				btn.disabled = false;
				btn.textContent = orig;
			}
		} );
	} );
} )();
</script>

<script>
/* ── Reasignación de canchas por ronda ───────────────────────── */
(function () {
	document.addEventListener('click', function (e) {
		var toggleBtn = e.target.closest('.st-round-courts-toggle');
		if ( toggleBtn ) {
			var round = toggleBtn.dataset.round;
			var panel = document.getElementById('st-courts-panel-' + round);
			if ( panel ) {
				panel.style.display = panel.style.display === 'none' ? 'block' : 'none';
			}
			return;
		}
		var cancelBtn = e.target.closest('.st-round-courts-cancel');
		if ( cancelBtn ) {
			var round = cancelBtn.dataset.round;
			var panel = document.getElementById('st-courts-panel-' + round);
			if ( panel ) panel.style.display = 'none';
		}
	});
}());
</script>
