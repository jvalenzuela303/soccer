<?php
/**
 * Vista de partidos del torneo — Ingreso de resultados.
 * Acceso: ds_coordinador y ds_veedor.
 *
 * Variables:
 *   $matches              array[]  Partidos filtrados por torneo y fecha.
 *   $tournaments          array[]  Lista de torneos para el selector.
 *   $rounds               array    Fechas disponibles para el torneo seleccionado.
 *   $selected_tournament  int      ID del torneo seleccionado.
 *   $selected_round       int      Número de fecha seleccionada.
 *   $page_title           string
 */
defined( 'ABSPATH' ) || exit;

/**
 * Devuelve etiqueta y clase CSS según estado del partido.
 */
function st_match_status_badge( string $status ): string {
	$map = [
		'scheduled' => [ '🕐', 'Programado', '' ],
		'live'      => [ '🟢', 'En curso',   'st-badge--success' ],
		'finished'  => [ '✅', 'Finalizado', 'st-badge--secondary' ],
		'postponed' => [ '⏸', 'Aplazado',   '' ],
		'suspended' => [ '🚫', 'Suspendido', 'st-badge--danger' ],
	];
	[ $icon, $label, $class ] = $map[ $status ] ?? [ '❓', $status, '' ];
	return "<span class=\"st-badge $class\">$icon " . esc_html( $label ) . '</span>';
}
?>

<div class="st-page-header">
	<h1 class="st-page-title">📋 <?php esc_html_e( 'Partidos del Torneo', 'soccertrack' ); ?></h1>
</div>

<?php if ( ! empty( $tournaments ) ) : ?>
<div class="st-card" style="margin-bottom:20px">
	<form method="get" action="" id="st-filter-form" style="display:flex;align-items:center;gap:16px;flex-wrap:wrap">
		<input type="hidden" name="st_panel_page" value="1">
		<input type="hidden" name="st_panel_vista" value="mis-partidos">

		<?php /* Filtro torneo */ ?>
		<div style="display:flex;align-items:center;gap:8px">
			<label for="st-tournament-filter" style="font-weight:600;color:var(--st-navy);white-space:nowrap">
				🏆 <?php esc_html_e( 'Torneo', 'soccertrack' ); ?>
			</label>
			<select
				id="st-tournament-filter"
				name="tournament_id"
				onchange="document.getElementById('st-round-filter').value=''; this.form.submit()"
				style="padding:7px 12px;border:1px solid #ddd;border-radius:6px;font-size:.92rem;min-width:200px"
			>
				<?php foreach ( $tournaments as $t ) : ?>
				<option
					value="<?php echo esc_attr( (string) $t['id'] ); ?>"
					<?php selected( (int) $t['id'], $selected_tournament ); ?>
				>
					<?php echo esc_html( $t['name'] ); ?>
				</option>
				<?php endforeach; ?>
			</select>
		</div>

		<?php /* Filtro fecha */ ?>
		<?php if ( ! empty( $rounds ) ) : ?>
		<div style="display:flex;align-items:center;gap:8px">
			<label for="st-round-filter" style="font-weight:600;color:var(--st-navy);white-space:nowrap">
				📅 <?php esc_html_e( 'Fecha', 'soccertrack' ); ?>
			</label>
			<select
				id="st-round-filter"
				name="round_number"
				onchange="this.form.submit()"
				style="padding:7px 12px;border:1px solid #ddd;border-radius:6px;font-size:.92rem;min-width:130px"
			>
				<option value=""><?php esc_html_e( 'Todas', 'soccertrack' ); ?></option>
				<?php foreach ( $rounds as $r ) : ?>
				<option
					value="<?php echo esc_attr( (string) $r ); ?>"
					<?php selected( (int) $r, $selected_round ); ?>
				>
					<?php echo esc_html( sprintf( __( 'Fecha %s', 'soccertrack' ), $r ) ); ?>
				</option>
				<?php endforeach; ?>
			</select>
		</div>
		<?php endif; ?>

		<span style="font-size:.85rem;color:#888;margin-left:auto">
			<?php echo esc_html( (string) count( $matches ) ); ?> <?php esc_html_e( 'partido(s)', 'soccertrack' ); ?>
		</span>
	</form>
</div>
<?php endif; ?>

<?php if ( empty( $matches ) ) : ?>
<div class="st-card">
	<p class="st-empty-msg">
		<?php esc_html_e( 'No hay partidos para los filtros seleccionados.', 'soccertrack' ); ?>
	</p>
</div>
<?php else : ?>

<div class="st-card">
	<div class="st-table-wrap">
		<table class="st-table">
			<thead>
				<tr>
					<th style="width:40px"><?php esc_html_e( 'Fecha', 'soccertrack' ); ?></th>
					<th style="width:130px"><?php esc_html_e( 'Hora', 'soccertrack' ); ?></th>
					<th><?php esc_html_e( 'Partido', 'soccertrack' ); ?></th>
					<th><?php esc_html_e( 'Estado', 'soccertrack' ); ?></th>
					<th><?php esc_html_e( 'Cancha', 'soccertrack' ); ?></th>
					<th style="width:100px"></th>
				</tr>
			</thead>
			<tbody>
			<?php foreach ( $matches as $m ) : ?>
				<tr>
					<td style="text-align:center;font-weight:700;color:var(--st-green-primary)">
						<?php echo esc_html( (string) $m['round_number'] ); ?>
					</td>
					<td style="font-size:.85rem;white-space:nowrap">
						<?php
						if ( ! empty( $m['match_datetime'] ) ) {
							$ts   = strtotime( $m['match_datetime'] );
							$time = date( 'H:i', $ts );
							echo '<span style="font-weight:700;display:block">' . esc_html( date_i18n( 'd M Y', $ts ) ) . '</span>';
							if ( $time !== '00:00' ) {
								echo '<span style="color:#666">' . esc_html( $time ) . ' h</span>';
							} else {
								echo '<span style="color:#bbb;font-size:.75rem">Sin hora</span>';
							}
						} else {
							echo '<span style="color:#aaa">—</span>';
						}
						?>
					</td>
					<td>
						<strong><?php echo esc_html( $m['home_team'] ); ?></strong>
						<span style="color:#aaa;margin:0 6px">vs</span>
						<strong><?php echo esc_html( $m['away_team'] ); ?></strong>
						<?php if ( $m['status'] === 'finished' ) : ?>
							<span style="margin-left:8px;color:#3CBC20;font-weight:700">
								<?php echo esc_html( $m['home_score'] . ' — ' . $m['away_score'] ); ?>
							</span>
						<?php endif; ?>
					</td>
					<td><?php echo wp_kses_post( st_match_status_badge( $m['status'] ) ); ?></td>
					<td style="font-size:.85rem;color:#666">
						<?php
						echo esc_html( $m['venue'] ?? '' );
						if ( ! empty( $m['court_name'] ) ) {
							echo ' · ' . esc_html( $m['court_name'] );
						}
						?>
					</td>
					<td>
						<?php if ( $m['status'] !== 'finished' ) : ?>
						<a href="<?php echo esc_url( home_url( '/panel/partido/' . $m['id'] . '/' ) ); ?>"
						   class="st-btn st-btn--primary st-btn--sm">
							📋 <?php esc_html_e( 'Abrir', 'soccertrack' ); ?>
						</a>
						<?php else : ?>
						<a href="<?php echo esc_url( home_url( '/panel/partido/' . $m['id'] . '/' ) ); ?>"
						   class="st-btn st-btn--sm st-btn--secondary">
							👁 <?php esc_html_e( 'Ver', 'soccertrack' ); ?>
						</a>
						<?php endif; ?>
					</td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
	</div>
</div>

<?php endif; ?>
