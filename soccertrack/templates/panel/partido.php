<?php
/**
 * Planilla arbitral del panel (incluye match-sheet.php existente).
 */
defined( 'ABSPATH' ) || exit;

// Datos de localización para match-sheet.js.
// El panel no usa wp_head()/wp_footer(), por lo que se emite el <script> directamente.
$map_player = static function ( array $p, int $team_id ): array {
	return [
		'id'           => (int) $p['id'],
		'name'         => $p['first_name'] . ' ' . $p['last_name'],
		'dorsal'       => (int) $p['dorsal'],
		'is_suspended' => (bool) $p['is_suspended'],
		'team_id'      => $team_id,
	];
};

$ms_config = [
	'apiBase'           => esc_url_raw( get_rest_url() ),
	'matchId'           => (int) $match['id'],
	'nonce'             => wp_create_nonce( 'wp_rest' ),
	'matchStatus'       => (string) ( $match['status'] ?? 'scheduled' ),
	'registrationMode'  => (string) ( $tournament['registration_mode'] ?? 'realtime' ),
	'homeScore'         => (int) ( $match['home_score'] ?? 0 ),
	'awayScore'         => (int) ( $match['away_score'] ?? 0 ),
	'refereeUserId'     => (int) ( $match['referee_user_id'] ?? 0 ),
	'planilleroUserId'  => (int) ( $match['planillero_user_id'] ?? 0 ),
	'currentUserId'     => get_current_user_id(),
	'canEditIncidents'  => current_user_can( 'ds_edit_incidents' ),
	'canClose'          => current_user_can( 'ds_close_match' ),
	'canReopen'         => current_user_can( 'manage_options' ) || current_user_can( 'ds_manage_tournaments' ),
	'homeTeam'          => [
		'id'   => (int) $home_team['id'],
		'name' => $home_team['name'],
	],
	'awayTeam'          => [
		'id'   => (int) $away_team['id'],
		'name' => $away_team['name'],
	],
	'homePlayers'       => array_map( fn( array $p ) => $map_player( $p, (int) $home_team['id'] ), $home_players ),
	'awayPlayers'       => array_map( fn( array $p ) => $map_player( $p, (int) $away_team['id'] ), $away_players ),
	'redirectAfterSave' => home_url( add_query_arg( [
		'tournament_id' => (int) ( $match['tournament_id'] ?? 0 ),
		'round_number'  => (int) ( $match['round_number']  ?? 0 ),
	], '/panel/mis-partidos/' ) ),
	'i18n'              => [
		'saving'                => __( 'Guardando…', 'soccertrack' ),
		'confirm_result'        => __( '¿Confirmar resultado?', 'soccertrack' ),
		'result_saved'          => __( 'Resultado registrado.', 'soccertrack' ),
		'error_save'            => __( 'Error al guardar.', 'soccertrack' ),
		'missing_fields'        => __( 'Selecciona jugador y minuto.', 'soccertrack' ),
		'red_card_tribunal'     => __( '🔴 Tarjeta roja registrada. El Tribunal de Disciplina determinará la sanción.', 'soccertrack' ),
		'no_referee'            => __( '⚠️ Debes asignar un árbitro antes de cerrar el partido.', 'soccertrack' ),
		'confirm_delete_goal'   => __( '¿Eliminar este gol?', 'soccertrack' ),
		'confirm_delete_event'  => __( '¿Eliminar este incidente?', 'soccertrack' ),
		'goal_deleted'          => __( 'Gol eliminado.', 'soccertrack' ),
		'goal_modal_title'      => __( 'Registrar Gol', 'soccertrack' ),
		'goal_edit_title'       => __( 'Editar Gol', 'soccertrack' ),
		'edit_incident_title'   => __( 'Editar Incidente', 'soccertrack' ),
		'player_label'          => __( 'Jugador', 'soccertrack' ),
		'minute_label'          => __( 'Minuto', 'soccertrack' ),
		'description_label'     => __( 'Detalle del incidente', 'soccertrack' ),
		'description_hint'      => __( 'Ej: plancha contra jugador 5 del equipo rival', 'soccertrack' ),
		'cancel'                => __( 'Cancelar', 'soccertrack' ),
		'confirm_goal'          => __( '⚽ Registrar Gol', 'soccertrack' ),
		'save_goal'             => __( '⚽ Guardar Gol', 'soccertrack' ),
		'save_changes'          => __( '💾 Guardar cambios', 'soccertrack' ),
		'search_player'         => __( 'Buscar jugador…', 'soccertrack' ),
		'suspended_label'       => __( '[SUSP.]', 'soccertrack' ),
		'entered_by'            => __( 'Ingresado por', 'soccertrack' ),
		'edited_by'             => __( 'Editado por', 'soccertrack' ),
	],
];
?>
<script>
window.stMatchSheet = <?php echo wp_json_encode( $ms_config ); ?>;
</script>

<?php include SOCCERTRACK_DIR . 'templates/admin/match-sheet.php'; ?>

<script src="<?php echo esc_url( SOCCERTRACK_URL . 'assets/js/match-sheet.js?v=' . SOCCERTRACK_VERSION ); ?>"></script>
