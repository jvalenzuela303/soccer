-- =============================================================
-- SoccerTrack — Reset de datos del plugin
-- Borra TODOS los datos del torneo y deja intactos los usuarios WP.
--
-- Uso:
--   wp db query < scripts/reset_data.sql
--   o desde phpMyAdmin / MySQL CLI.
--
-- Ajusta el prefijo si tu instalación no usa "wp_".
-- =============================================================

SET FOREIGN_KEY_CHECKS = 0;

-- Orden: tablas hijo primero para respetar integridad referencial
TRUNCATE TABLE `wpq7_ds_disciplinary_sanctions`;
TRUNCATE TABLE `wpq7_ds_match_events`;
TRUNCATE TABLE `wpq7_ds_matches`;
TRUNCATE TABLE `wpq7_ds_team_players`;
TRUNCATE TABLE `wpq7_ds_teams`;
TRUNCATE TABLE `wpq7_ds_players`;
TRUNCATE TABLE `wpq7_ds_courts`;
TRUNCATE TABLE `wpq7_ds_tournament_venues`;
TRUNCATE TABLE `wpq7_ds_venues`;
TRUNCATE TABLE `wpq7_ds_staff`;
TRUNCATE TABLE `wpq7_ds_tournaments`;

SET FOREIGN_KEY_CHECKS = 1;

-- Confirmar
SELECT 'Reset completado. Usuarios WP intactos.' AS resultado;
