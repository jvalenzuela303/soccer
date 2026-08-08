<?php
/**
 * Vista de importación del panel (reutiliza import-upload.php).
 */
defined( 'ABSPATH' ) || exit;

// El template original usa $tournament_id, $tournaments, $teams → ya están en scope.
include SOCCERTRACK_DIR . 'templates/admin/import-upload.php';
