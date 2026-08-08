<?php
/**
 * Vista de partidos del árbitro — acceso rápido a planillas.
 *
 * Variables:
 *   $matches_assigned  array[]  Partidos con referee_user_id = usuario actual.
 *   $matches_pending   array[]  Partidos pendientes (fallback si no hay asignados).
 *   $page_title        string
 */
defined( 'ABSPATH' ) || exit;

$has_assigned = ! empty( $matches_assigned );
$matches      = $has_assigned ? $matches_assigned : $matches_pending;

/**
 * Devuelve etiqueta y clase CSS según estado del partido.
 */
function st_match_status_badge( string $status ): string {
	$map = [
		'scheduled' => [ '🕐', 'programado', '' ],
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
	<h1 class="st-page-title">📋 <?php esc_html_e( 'Mis Partidos', 'soccertrack' ); ?></h1>
</div>

<?php if ( ! $has_assigned ) : ?>
<div class="st-alert st-alert--info" style="margin-bottom:20px">
	ℹ️ <?php esc_html_e( 'No tienes partidos asignados aún. Se muestran todos los partidos pendientes del sistema.', 'soccertrack' ); ?>
</div>
<?php endif; ?>

<?php if ( empty( $matches ) ) : ?>
<div class="st-card">
	<p class="st-empty-msg">
		<?php esc_html_e( 'No hay partidos pendientes en el sistema.', 'soccertrack' ); ?>
	</p>
</div>
<?php else : ?>

<?php
// Agrupar por torneo.
$by_tournament = [];
foreach ( $matches as $m ) {
	$by_tournament[ $m['tournament_name'] ][] = $m;
}
?>

<?php foreach ( $by_tournament as $tournament_name => $group ) : ?>
<div class="st-card" style="margin-bottom:20px">
	<div class="st-card-header">
		<h2 class="st-card-title">🏆 <?php echo esc_html( $tournament_name ); ?></h2>
		<span class="st-badge"><?php echo esc_html( (string) count( $group ) ); ?> <?php esc_html_e( 'partido(s)', 'soccertrack' ); ?></span>
	</div>
	<div class="st-table-wrap">
		<table class="st-table">
			<thead>
				<tr>
					<th style="width:130px"><?php esc_html_e( 'Fecha / Hora', 'soccertrack' ); ?></th>
					<th><?php esc_html_e( 'Partido', 'soccertrack' ); ?></th>
					<th><?php esc_html_e( 'Estado', 'soccertrack' ); ?></th>
					<th><?php esc_html_e( 'Cancha', 'soccertrack' ); ?></th>
					<th style="width:100px"></th>
				</tr>
			</thead>
			<tbody>
			<?php foreach ( $group as $m ) : ?>
				<tr>
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
						if ( ! empty( $m['round_number'] ) ) {
							echo '<span style="font-size:.75rem;color:#999;display:block">Fecha ' . esc_html( (string) $m['round_number'] ) . '</span>';
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
<?php endforeach; ?>

<?php endif; ?>
