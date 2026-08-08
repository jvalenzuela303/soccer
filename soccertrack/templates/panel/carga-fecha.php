<?php
/**
 * Carga de acta para el coordinador — modo planilla física.
 *
 * Variables: $tournament, $round, $matches, $page_title
 * $matches: array de partidos de la jornada
 */
defined( 'ABSPATH' ) || exit;
?>

<div class="st-page-header">
    <a href="<?php echo esc_url( home_url( '/panel/torneo/' . $tournament['id'] . '/' ) ); ?>" class="st-back-link">
        ← <?php echo esc_html( $tournament['name'] ); ?>
    </a>
    <h1 class="st-page-title">
        📋 <?php printf( esc_html__( 'Carga de Acta — Jornada %d', 'soccertrack' ), $round ); ?>
    </h1>
</div>

<div class="st-alert" style="background:#fff8e1;border-left:4px solid #f9a825;margin-bottom:20px">
    <?php esc_html_e( 'Ingresa los resultados y eventos de cada partido según la planilla física. Una vez cerrado un partido no se pueden agregar más eventos.', 'soccertrack' ); ?>
</div>

<?php if ( empty( $matches ) ) : ?>
    <p class="st-empty-msg"><?php esc_html_e( 'No hay partidos en esta jornada.', 'soccertrack' ); ?></p>
<?php else : ?>

<?php foreach ( $matches as $idx => $match ) :
    $match_id    = (int) $match['id'];
    $is_finished = $match['status'] === 'finished';
?>
<div class="st-card" style="margin-bottom:24px;<?php echo esc_attr( $is_finished ? 'opacity:.8' : '' ); ?>">
    <div class="st-card-header">
        <h2 class="st-card-title">
            <?php echo esc_html( $match['home_team_name'] . ' vs ' . $match['away_team_name'] ); ?>
            <?php if ( $is_finished ) : ?>
                <span class="st-badge st-badge--success" style="margin-left:8px">
                    ✅ <?php esc_html_e( 'Cerrado', 'soccertrack' ); ?>
                    — <?php echo esc_html( $match['home_score'] . ' - ' . $match['away_score'] ); ?>
                </span>
            <?php endif; ?>
        </h2>
        <?php if ( ! $is_finished ) : ?>
        <a href="<?php echo esc_url( home_url( '/panel/partido/' . $match_id . '/' ) ); ?>"
           class="st-btn st-btn--primary st-btn--sm">
            ✏️ <?php esc_html_e( 'Abrir planilla completa', 'soccertrack' ); ?>
        </a>
        <?php endif; ?>
    </div>

    <?php if ( ! $is_finished ) : ?>
    <div style="padding:12px 0 0;font-size:.85rem;color:#555">
        <?php if ( $match['venue_name'] ) : ?>
            🏟 <?php echo esc_html( $match['venue_name'] ); ?>
            <?php if ( $match['court_name'] ) : ?>
                — <?php echo esc_html( $match['court_name'] ); ?>
            <?php endif; ?>
        <?php endif; ?>
        <?php if ( $match['match_datetime'] ) : ?>
            · 🕐 <?php echo esc_html( date_i18n( 'd/m/Y H:i', strtotime( $match['match_datetime'] ) ) ); ?>
        <?php endif; ?>
    </div>
    <p style="margin:8px 0 0;font-size:.82rem;color:#888">
        <?php esc_html_e( 'Usa "Abrir planilla completa" para ingresar goles, tarjetas y cerrar el partido.', 'soccertrack' ); ?>
    </p>
    <?php else : ?>
    <?php /* Resumen de eventos del partido cerrado */ ?>
    <?php
    global $wpdb;
    $events = $wpdb->get_results( // phpcs:ignore
        $wpdb->prepare(
            "SELECT e.event_type, e.minute, p.first_name, p.last_name, t.name AS team_name
             FROM {$wpdb->prefix}ds_match_events e
             JOIN {$wpdb->prefix}ds_players p ON p.id = e.player_id
             JOIN {$wpdb->prefix}ds_teams t ON t.id = e.team_id
             WHERE e.match_id = %d ORDER BY e.minute ASC",
            $match_id
        ),
        ARRAY_A
    ) ?: [];
    ?>
    <?php if ( ! empty( $events ) ) : ?>
    <div class="st-table-wrap" style="margin-top:8px">
        <table class="st-table st-table--compact">
            <thead><tr>
                <th><?php esc_html_e( 'Min.', 'soccertrack' ); ?></th>
                <th><?php esc_html_e( 'Tipo', 'soccertrack' ); ?></th>
                <th><?php esc_html_e( 'Jugador', 'soccertrack' ); ?></th>
                <th><?php esc_html_e( 'Equipo', 'soccertrack' ); ?></th>
            </tr></thead>
            <tbody>
            <?php foreach ( $events as $ev ) : ?>
            <tr>
                <td><?php echo esc_html( (string) $ev['minute'] ); ?>'</td>
                <td>
                    <?php
                    $type_icons = [
                        'goal'        => '⚽',
                        'own_goal'    => '⚽🔴',
                        'yellow_card' => '🟨',
                        'red_card'    => '🟥',
                    ];
                    echo esc_html( $type_icons[ $ev['event_type'] ] ?? $ev['event_type'] );
                    ?>
                </td>
                <td><?php echo esc_html( $ev['first_name'] . ' ' . $ev['last_name'] ); ?></td>
                <td><?php echo esc_html( $ev['team_name'] ); ?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php else : ?>
        <p style="font-size:.82rem;color:#888;margin-top:8px"><?php esc_html_e( 'Sin eventos registrados.', 'soccertrack' ); ?></p>
    <?php endif; ?>
    <?php endif; ?>
</div>
<?php endforeach; ?>

<?php endif; ?>
