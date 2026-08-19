-- ============================================================
--  SoccerTrack — Reset de Producción
--  Vacía todas las tablas del plugin y reinicia AUTO_INCREMENT.
--  NO toca usuarios de WordPress ni configuración del sitio.
--
--  Ejecutar en phpMyAdmin o via CLI:
--    mysql -u wpuser -p soccertrack_wp < reset-production.sql
--
--  ⚠ IRREVERSIBLE — hacer respaldo previo:
--    mysqldump -u wpuser -p soccertrack_wp > backup-YYYYMMDD.sql
-- ============================================================

SET FOREIGN_KEY_CHECKS = 0;

-- Eventos de partidos (depende de ds_matches, ds_players, ds_teams)
TRUNCATE TABLE wp_ds_match_events;

-- Sanciones disciplinarias
TRUNCATE TABLE wp_ds_disciplinary_sanctions;

-- Brackets de playoffs
TRUNCATE TABLE wp_ds_playoff_brackets;

-- Partidos
TRUNCATE TABLE wp_ds_matches;

-- Jugadores asignados a equipos
TRUNCATE TABLE wp_ds_team_players;

-- Jugadores
TRUNCATE TABLE wp_ds_players;

-- Equipos
TRUNCATE TABLE wp_ds_teams;

-- Canchas
TRUNCATE TABLE wp_ds_courts;

-- Recintos asignados a torneos
TRUNCATE TABLE wp_ds_tournament_venues;

-- Recintos
TRUNCATE TABLE wp_ds_venues;

-- Staff (árbitros, planilleros)
TRUNCATE TABLE wp_ds_staff;

-- Torneos (al final, referenciado por todo lo anterior)
TRUNCATE TABLE wp_ds_tournaments;

SET FOREIGN_KEY_CHECKS = 1;

-- Verificar que todo quedó vacío
SELECT
    TABLE_NAME,
    TABLE_ROWS
FROM information_schema.TABLES
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME LIKE '%ds_%'
ORDER BY TABLE_NAME;
