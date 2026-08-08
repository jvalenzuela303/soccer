<?php
/**
 * Creación y migración del schema de base de datos de SoccerTrack.
 *
 * 8 tablas wp_ds_*: tournaments, venues, courts, teams, players,
 * team_players, matches, disciplinary_sanctions.
 *
 * Usa dbDelta() — sin privilegio SUPER requerido.
 * Motor InnoDB, collation utf8mb4_unicode_ci.
 *
 * @package SoccerTrack
 */

declare(strict_types=1);

namespace SportsLeague\Core;

final class DatabaseInstaller {

	/**
	 * Crea o actualiza las tablas del plugin y aplica migraciones de índices.
	 * Seguro de llamar múltiples veces (dbDelta solo aplica diferencias).
	 */
	public static function create_tables(): void {
		global $wpdb;

		$c = $wpdb->get_charset_collate(); // utf8mb4_unicode_ci

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		// 1. Torneos.
		dbDelta( "CREATE TABLE {$wpdb->prefix}ds_tournaments (
			id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			name                VARCHAR(150)    NOT NULL,
			season              VARCHAR(50)     NOT NULL,
			start_date          DATE            NULL,
			end_date            DATE            NULL,
			format              ENUM('round_robin','round_robin_playoffs','group_stage','knockout') NOT NULL DEFAULT 'round_robin',
			status              ENUM('draft','active','completed')           NOT NULL DEFAULT 'draft',
			bases_pdf_url       VARCHAR(255)    NULL,
			match_weekday       TINYINT(1)      NOT NULL DEFAULT 6,
			match_time          TIME            NOT NULL DEFAULT '19:00:00',
			registration_mode   ENUM('realtime','deferred') NOT NULL DEFAULT 'deferred',
			fixture_release_days TINYINT(1)     NOT NULL DEFAULT 0,
			created_at          DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY       (id),
			KEY idx_status (status)
		) ENGINE=InnoDB {$c};" );

		// 2. Recintos.
		dbDelta( "CREATE TABLE {$wpdb->prefix}ds_venues (
			id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			name         VARCHAR(150)   NOT NULL,
			address      VARCHAR(255)   NULL,
			total_courts INT UNSIGNED   NOT NULL DEFAULT 4,
			PRIMARY KEY  (id)
		) ENGINE=InnoDB {$c};" );

		// 3. Canchas (relación con recinto).
		dbDelta( "CREATE TABLE {$wpdb->prefix}ds_courts (
			id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			venue_id   BIGINT UNSIGNED NOT NULL,
			court_name VARCHAR(50)     NOT NULL,
			PRIMARY KEY (id),
			KEY idx_venue (venue_id)
		) ENGINE=InnoDB {$c};" );

		// 4. Equipos (por torneo).
		// idx_tournament eliminado: prefijo redundante de idx_tournament_name (superset).
		dbDelta( "CREATE TABLE {$wpdb->prefix}ds_teams (
			id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			tournament_id    BIGINT UNSIGNED NOT NULL,
			name             VARCHAR(100)    NOT NULL,
			logo_url         VARCHAR(255)    NULL,
			delegate_user_id BIGINT UNSIGNED NULL,
			PRIMARY KEY (id),
			KEY idx_tournament_name (tournament_id, name)
		) ENGINE=InnoDB {$c};" );

		// 5. Jugadores (tabla global — un jugador puede estar en varios equipos/torneos).
		dbDelta( "CREATE TABLE {$wpdb->prefix}ds_players (
			id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			rut_id     VARCHAR(20)     NOT NULL,
			first_name VARCHAR(100)    NOT NULL,
			last_name  VARCHAR(100)    NOT NULL,
			photo_url  VARCHAR(255)    NULL,
			created_at DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			UNIQUE KEY uniq_rut (rut_id)
		) ENGINE=InnoDB {$c};" );

		// 6. Relación equipo-jugador (planilla por equipo).
		// idx_suspended eliminado: cardinalidad 0/1, nunca se filtra solo — cubierto por idx_team.
		dbDelta( "CREATE TABLE {$wpdb->prefix}ds_team_players (
			id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			team_id      BIGINT UNSIGNED NOT NULL,
			player_id    BIGINT UNSIGNED NOT NULL,
			dorsal       INT UNSIGNED    NOT NULL,
			is_suspended TINYINT(1)      NOT NULL DEFAULT 0,
			PRIMARY KEY (id),
			UNIQUE KEY team_player_dorsal   (team_id, dorsal),
			KEY idx_team                    (team_id),
			KEY idx_player                  (player_id),
			KEY idx_team_player_cover       (team_id, player_id)
		) ENGINE=InnoDB {$c};" );

		// 7. Partidos (programación y control anti-colisión).
		// idx_tournament_round eliminado: prefijo de idx_fixture_order (superset).
		// idx_tournament_phase_status agregado: cubre filtros por fase (play-offs).
		// idx_venue agregado: cubre JOINs y subqueries con ds_venues.
		// planillero_user_id: asignado en v1.5.0 (incluido aquí para dbDelta en fresh install).
		// referee_name, planillero_name: nombres de árbitro y planillero en modo deferred (v1.7.0).
		dbDelta( "CREATE TABLE {$wpdb->prefix}ds_matches (
			id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			tournament_id       BIGINT UNSIGNED NOT NULL,
			round_number        INT UNSIGNED    NOT NULL,
			home_team_id        BIGINT UNSIGNED NOT NULL,
			away_team_id        BIGINT UNSIGNED NOT NULL,
			venue_id            BIGINT UNSIGNED NOT NULL,
			court_id            BIGINT UNSIGNED NOT NULL DEFAULT 0,
			referee_user_id     BIGINT UNSIGNED NULL,
			planillero_user_id  BIGINT UNSIGNED NULL,
			referee_name        VARCHAR(120)    NULL,
			planillero_name     VARCHAR(120)    NULL,
			match_datetime      DATETIME        NOT NULL,
			home_score          INT UNSIGNED    NOT NULL DEFAULT 0,
			away_score          INT UNSIGNED    NOT NULL DEFAULT 0,
			status              ENUM('scheduled','in_progress','finished','suspended') NOT NULL DEFAULT 'scheduled',
			phase               ENUM('regular','semifinal','third_place','final')      NOT NULL DEFAULT 'regular',
			PRIMARY KEY (id),
			KEY idx_tournament_status       (tournament_id, status),
			KEY idx_fixture_order           (tournament_id, round_number, match_datetime),
			KEY idx_tournament_phase_status (tournament_id, phase, status),
			KEY idx_venue                   (venue_id),
			KEY idx_court_datetime          (court_id, status, match_datetime),
			KEY idx_referee_datetime        (referee_user_id, status, match_datetime),
			KEY idx_planillero_datetime     (planillero_user_id, match_datetime),
			KEY idx_home_team               (home_team_id),
			KEY idx_away_team               (away_team_id),
			KEY idx_status                  (status)
		) ENGINE=InnoDB {$c};" );

		// 8. Eventos de partido (goles, tarjetas por minuto).
		// idx_tournament eliminado: prefijo de idx_scorers (tournament_id, player_id, team_id).
		// description/created_by/updated_by/updated_at: auditoría añadida en v1.5.0
		// (incluidos aquí para dbDelta en fresh install).
		dbDelta( "CREATE TABLE {$wpdb->prefix}ds_match_events (
			id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			match_id      BIGINT UNSIGNED NOT NULL,
			tournament_id BIGINT UNSIGNED NOT NULL,
			player_id     BIGINT UNSIGNED NOT NULL,
			team_id       BIGINT UNSIGNED NOT NULL,
			event_type    ENUM('goal','own_goal','yellow_card','red_card') NOT NULL,
			minute        INT UNSIGNED    NOT NULL DEFAULT 1,
			description   TEXT            NULL,
			created_by    BIGINT UNSIGNED NOT NULL DEFAULT 0,
			updated_by    BIGINT UNSIGNED NULL,
			updated_at    DATETIME        NULL,
			created_at    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			KEY idx_match_minute (match_id, minute),
			KEY idx_scorers      (tournament_id, player_id, team_id)
		) ENGINE=InnoDB {$c};" );

		// 9. Sanciones disciplinarias.
		dbDelta( "CREATE TABLE {$wpdb->prefix}ds_disciplinary_sanctions (
			id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			player_id           BIGINT UNSIGNED NOT NULL,
			tournament_id       BIGINT UNSIGNED NOT NULL,
			match_id            BIGINT UNSIGNED NOT NULL,
			reason              VARCHAR(255)    NOT NULL,
			ban_days_or_matches INT UNSIGNED    NOT NULL,
			remaining_matches   INT UNSIGNED    NOT NULL,
			status              ENUM('active','served') NOT NULL DEFAULT 'active',
			created_at          DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			KEY idx_player_tournament     (player_id, tournament_id),
			KEY idx_tournament_status     (tournament_id, status),
			KEY idx_tourn_status_player   (tournament_id, status, player_id)
		) ENGINE=InnoDB {$c};" );

		update_option( 'soccertrack_db_version', SOCCERTRACK_DB_VERSION );

		// Aplicar migraciones de índices que dbDelta no puede modificar (drop+add).
		self::apply_index_migrations();
	}

	/**
	 * Migraciones de índices que dbDelta no puede aplicar (solo ADD, no DROP/MODIFY).
	 * Reemplaza índices simples por versiones compuestas/cubrientes.
	 * Idempotente: verifica existencia antes de actuar.
	 */
	private static function apply_index_migrations(): void {
		global $wpdb;

		$prefix = $wpdb->prefix;

		// ds_match_events: reemplazar idx_match(match_id) → idx_match_minute(match_id, minute)
		// para eliminar filesort en ORDER BY minute.
		$has_old = $wpdb->get_var( "SHOW INDEX FROM {$prefix}ds_match_events WHERE Key_name = 'idx_match'" ); // phpcs:ignore
		$has_new = $wpdb->get_var( "SHOW INDEX FROM {$prefix}ds_match_events WHERE Key_name = 'idx_match_minute'" ); // phpcs:ignore

		if ( $has_old && ! $has_new ) {
			$wpdb->query( "ALTER TABLE {$prefix}ds_match_events DROP INDEX idx_match, ADD KEY idx_match_minute (match_id, minute)" ); // phpcs:ignore
		} elseif ( $has_old && $has_new ) {
			$wpdb->query( "ALTER TABLE {$prefix}ds_match_events DROP INDEX idx_match" ); // phpcs:ignore
		}

		// ds_match_events: reemplazar idx_player(player_id) → cubierto por idx_scorers(tournament_id, player_id, team_id).
		$has_player_idx  = $wpdb->get_var( "SHOW INDEX FROM {$prefix}ds_match_events WHERE Key_name = 'idx_player'" ); // phpcs:ignore
		$has_scorers_idx = $wpdb->get_var( "SHOW INDEX FROM {$prefix}ds_match_events WHERE Key_name = 'idx_scorers'" ); // phpcs:ignore

		if ( $has_player_idx && $has_scorers_idx ) {
			// idx_player ya está cubierto por idx_scorers; eliminarlo evita mantenimiento doble.
			$wpdb->query( "ALTER TABLE {$prefix}ds_match_events DROP INDEX idx_player" ); // phpcs:ignore
		}

		// ds_disciplinary_sanctions: idx_status(status) → cubierto por idx_tournament_status.
		$has_status     = $wpdb->get_var( "SHOW INDEX FROM {$prefix}ds_disciplinary_sanctions WHERE Key_name = 'idx_status'" ); // phpcs:ignore
		$has_tourn_stat = $wpdb->get_var( "SHOW INDEX FROM {$prefix}ds_disciplinary_sanctions WHERE Key_name = 'idx_tournament_status'" ); // phpcs:ignore

		if ( $has_status && $has_tourn_stat ) {
			$wpdb->query( "ALTER TABLE {$prefix}ds_disciplinary_sanctions DROP INDEX idx_status" ); // phpcs:ignore
		}

		// ds_matches: idx_datetime(match_datetime) → cubierto por idx_fixture_order y idx_court_datetime.
		$has_datetime      = $wpdb->get_var( "SHOW INDEX FROM {$prefix}ds_matches WHERE Key_name = 'idx_datetime'" ); // phpcs:ignore
		$has_fixture_order = $wpdb->get_var( "SHOW INDEX FROM {$prefix}ds_matches WHERE Key_name = 'idx_fixture_order'" ); // phpcs:ignore

		if ( $has_datetime && $has_fixture_order ) {
			$wpdb->query( "ALTER TABLE {$prefix}ds_matches DROP INDEX idx_datetime" ); // phpcs:ignore
		}

		// v1.2.0 — ds_matches: agregar columna phase para play-offs (idempotente).
		$has_phase = $wpdb->get_var( "SHOW COLUMNS FROM {$prefix}ds_matches LIKE 'phase'" ); // phpcs:ignore
		if ( ! $has_phase ) {
			$wpdb->query( // phpcs:ignore
				"ALTER TABLE {$prefix}ds_matches
				 ADD COLUMN phase ENUM('regular','semifinal','third_place','final')
				     NOT NULL DEFAULT 'regular'
				 AFTER status"
			);
		}

		// v1.3.0 — ds_disciplinary_sanctions: agregar observaciones y resolved_at.
		$has_obs = $wpdb->get_var( "SHOW COLUMNS FROM {$prefix}ds_disciplinary_sanctions LIKE 'observaciones'" ); // phpcs:ignore
		if ( ! $has_obs ) {
			$wpdb->query( // phpcs:ignore
				"ALTER TABLE {$prefix}ds_disciplinary_sanctions
				 ADD COLUMN observaciones TEXT NULL AFTER reason,
				 ADD COLUMN resolved_at DATETIME NULL AFTER observaciones"
			);
		}

		// v1.3.1 — Optimización de índices.

		// ds_matches: eliminar idx_tournament_round (prefijo de idx_fixture_order).
		$has_tr = $wpdb->get_var( "SHOW INDEX FROM {$prefix}ds_matches WHERE Key_name = 'idx_tournament_round'" ); // phpcs:ignore
		if ( $has_tr ) {
			$wpdb->query( "ALTER TABLE {$prefix}ds_matches DROP INDEX idx_tournament_round" ); // phpcs:ignore
		}

		// ds_matches: agregar idx_venue (venue_id) para JOINs con ds_venues.
		$has_venue_idx = $wpdb->get_var( "SHOW INDEX FROM {$prefix}ds_matches WHERE Key_name = 'idx_venue'" ); // phpcs:ignore
		if ( ! $has_venue_idx ) {
			$wpdb->query( "ALTER TABLE {$prefix}ds_matches ADD KEY idx_venue (venue_id)" ); // phpcs:ignore
		}

		// ds_matches: agregar idx_tournament_phase_status para filtros de fase (play-offs).
		$has_tps = $wpdb->get_var( "SHOW INDEX FROM {$prefix}ds_matches WHERE Key_name = 'idx_tournament_phase_status'" ); // phpcs:ignore
		if ( ! $has_tps ) {
			$wpdb->query( "ALTER TABLE {$prefix}ds_matches ADD KEY idx_tournament_phase_status (tournament_id, phase, status)" ); // phpcs:ignore
		}

		// ds_match_events: eliminar idx_tournament (prefijo de idx_scorers).
		$has_me_tourn = $wpdb->get_var( "SHOW INDEX FROM {$prefix}ds_match_events WHERE Key_name = 'idx_tournament'" ); // phpcs:ignore
		if ( $has_me_tourn ) {
			$wpdb->query( "ALTER TABLE {$prefix}ds_match_events DROP INDEX idx_tournament" ); // phpcs:ignore
		}

		// ds_team_players: eliminar idx_suspended (cardinalidad 0/1, siempre filtrado junto a team_id).
		$has_susp = $wpdb->get_var( "SHOW INDEX FROM {$prefix}ds_team_players WHERE Key_name = 'idx_suspended'" ); // phpcs:ignore
		if ( $has_susp ) {
			$wpdb->query( "ALTER TABLE {$prefix}ds_team_players DROP INDEX idx_suspended" ); // phpcs:ignore
		}

		// ds_teams: agregar idx_tournament_name para detección de duplicados.
		$has_tn = $wpdb->get_var( "SHOW INDEX FROM {$prefix}ds_teams WHERE Key_name = 'idx_tournament_name'" ); // phpcs:ignore
		if ( ! $has_tn ) {
			$wpdb->query( "ALTER TABLE {$prefix}ds_teams ADD KEY idx_tournament_name (tournament_id, name)" ); // phpcs:ignore
		}

		// v1.5.0 — ds_matches: planillero asignado al partido.
		$has_planillero_col = $wpdb->get_var( "SHOW COLUMNS FROM {$prefix}ds_matches LIKE 'planillero_user_id'" ); // phpcs:ignore
		if ( ! $has_planillero_col ) {
			$wpdb->query( // phpcs:ignore
				"ALTER TABLE {$prefix}ds_matches
				 ADD COLUMN planillero_user_id BIGINT UNSIGNED NULL AFTER referee_user_id,
				 ADD KEY idx_planillero (planillero_user_id)"
			);
		}

		// v1.5.0 — ds_match_events: detalle del incidente + auditoría de quién registró/editó.
		$has_description = $wpdb->get_var( "SHOW COLUMNS FROM {$prefix}ds_match_events LIKE 'description'" ); // phpcs:ignore
		if ( ! $has_description ) {
			$wpdb->query( // phpcs:ignore
				"ALTER TABLE {$prefix}ds_match_events
				 ADD COLUMN description  TEXT            NULL AFTER minute,
				 ADD COLUMN created_by   BIGINT UNSIGNED NOT NULL DEFAULT 0 AFTER description,
				 ADD COLUMN updated_by   BIGINT UNSIGNED NULL AFTER created_by,
				 ADD COLUMN updated_at   DATETIME        NULL AFTER updated_by"
			);
		}

		// v1.3.2 — ds_disciplinary_sanctions: agregar team_id para vincular sanción
		// con el equipo específico (evita duplicados cuando un jugador está en varios equipos).
		$has_team_id = $wpdb->get_var( "SHOW COLUMNS FROM {$prefix}ds_disciplinary_sanctions LIKE 'team_id'" ); // phpcs:ignore
		if ( ! $has_team_id ) {
			$wpdb->query( // phpcs:ignore
				"ALTER TABLE {$prefix}ds_disciplinary_sanctions
				 ADD COLUMN team_id BIGINT UNSIGNED NULL AFTER match_id,
				 ADD KEY idx_team_id (team_id)"
			);
		}

		// v1.4.0 — ds_teams: campos corporativos y de delegado (planilla de inscripción).
		$has_empresa = $wpdb->get_var( "SHOW COLUMNS FROM {$prefix}ds_teams LIKE 'empresa_nombre'" ); // phpcs:ignore
		if ( ! $has_empresa ) {
			$wpdb->query( // phpcs:ignore
				"ALTER TABLE {$prefix}ds_teams
				 ADD COLUMN empresa_nombre   VARCHAR(150) NULL AFTER name,
				 ADD COLUMN empresa_rut      VARCHAR(20)  NULL AFTER empresa_nombre,
				 ADD COLUMN empresa_dir      VARCHAR(255) NULL AFTER empresa_rut,
				 ADD COLUMN delegado_nombre  VARCHAR(150) NULL AFTER empresa_dir,
				 ADD COLUMN delegado_rut     VARCHAR(20)  NULL AFTER delegado_nombre,
				 ADD COLUMN delegado_correo  VARCHAR(150) NULL AFTER delegado_rut,
				 ADD COLUMN delegado_celular VARCHAR(30)  NULL AFTER delegado_correo"
			);
		}

		// v1.4.0 — ds_players: apellido materno, correo y teléfono.
		$has_last_name_m = $wpdb->get_var( "SHOW COLUMNS FROM {$prefix}ds_players LIKE 'last_name_m'" ); // phpcs:ignore
		if ( ! $has_last_name_m ) {
			$wpdb->query( // phpcs:ignore
				"ALTER TABLE {$prefix}ds_players
				 ADD COLUMN last_name_m VARCHAR(100) NULL AFTER last_name,
				 ADD COLUMN email       VARCHAR(150) NULL AFTER last_name_m,
				 ADD COLUMN phone       VARCHAR(30)  NULL AFTER email"
			);
		}

		// v1.4.0 — ds_team_players: área y cargo corporativo del jugador.
		$has_area = $wpdb->get_var( "SHOW COLUMNS FROM {$prefix}ds_team_players LIKE 'area'" ); // phpcs:ignore
		if ( ! $has_area ) {
			$wpdb->query( // phpcs:ignore
				"ALTER TABLE {$prefix}ds_team_players
				 ADD COLUMN area  VARCHAR(100) NULL AFTER dorsal,
				 ADD COLUMN cargo VARCHAR(100) NULL AFTER area"
			);
		}

		// v1.7.0 — ds_teams: eliminar idx_tournament (prefijo redundante de idx_tournament_name).
		$has_idx_tourn = $wpdb->get_var( "SHOW INDEX FROM {$prefix}ds_teams WHERE Key_name = 'idx_tournament'" ); // phpcs:ignore
		if ( $has_idx_tourn ) {
			$wpdb->query( "ALTER TABLE {$prefix}ds_teams DROP INDEX idx_tournament" ); // phpcs:ignore
		}

		// v1.7.0 — ds_matches: reemplazar idx_referee(referee_user_id) → idx_referee_datetime(referee_user_id, match_datetime).
		// Índice compuesto permite range scan en BETWEEN tras el filtro por árbitro.
		$has_ref_simple   = $wpdb->get_var( "SHOW INDEX FROM {$prefix}ds_matches WHERE Key_name = 'idx_referee'" ); // phpcs:ignore
		$has_ref_datetime = $wpdb->get_var( "SHOW INDEX FROM {$prefix}ds_matches WHERE Key_name = 'idx_referee_datetime'" ); // phpcs:ignore

		if ( $has_ref_simple && ! $has_ref_datetime ) {
			$wpdb->query( "ALTER TABLE {$prefix}ds_matches DROP INDEX idx_referee, ADD KEY idx_referee_datetime (referee_user_id, match_datetime)" ); // phpcs:ignore
		} elseif ( $has_ref_simple && $has_ref_datetime ) {
			$wpdb->query( "ALTER TABLE {$prefix}ds_matches DROP INDEX idx_referee" ); // phpcs:ignore
		}

		// v1.7.0 — ds_matches: reemplazar idx_planillero(planillero_user_id) → idx_planillero_datetime(planillero_user_id, match_datetime).
		// Mismo patrón que idx_referee_datetime para las queries de conflicto de horario.
		$has_plan_simple   = $wpdb->get_var( "SHOW INDEX FROM {$prefix}ds_matches WHERE Key_name = 'idx_planillero'" ); // phpcs:ignore
		$has_plan_datetime = $wpdb->get_var( "SHOW INDEX FROM {$prefix}ds_matches WHERE Key_name = 'idx_planillero_datetime'" ); // phpcs:ignore

		if ( $has_plan_simple && ! $has_plan_datetime ) {
			$wpdb->query( "ALTER TABLE {$prefix}ds_matches DROP INDEX idx_planillero, ADD KEY idx_planillero_datetime (planillero_user_id, match_datetime)" ); // phpcs:ignore
		} elseif ( $has_plan_simple && $has_plan_datetime ) {
			$wpdb->query( "ALTER TABLE {$prefix}ds_matches DROP INDEX idx_planillero" ); // phpcs:ignore
		}

		// v1.6.0 — ds_tournaments: parámetros de horario del fixture.
		$has_weekday = $wpdb->get_var( "SHOW COLUMNS FROM {$prefix}ds_tournaments LIKE 'match_weekday'" ); // phpcs:ignore
		if ( ! $has_weekday ) {
			$wpdb->query( // phpcs:ignore
				"ALTER TABLE {$prefix}ds_tournaments
				 ADD COLUMN match_weekday TINYINT(1) NOT NULL DEFAULT 6 AFTER bases_pdf_url,
				 ADD COLUMN match_time    TIME       NOT NULL DEFAULT '19:00:00' AFTER match_weekday"
			);
		}

		// v1.6.1 — ds_tournaments: modo de registro de partidos.
		$has_reg_mode = $wpdb->get_var( "SHOW COLUMNS FROM {$prefix}ds_tournaments LIKE 'registration_mode'" ); // phpcs:ignore
		if ( ! $has_reg_mode ) {
			$wpdb->query( // phpcs:ignore
				"ALTER TABLE {$prefix}ds_tournaments
				 ADD COLUMN registration_mode ENUM('realtime','deferred') NOT NULL DEFAULT 'deferred'
				 AFTER match_time"
			);
		}

		// v1.7.1 — ds_tournaments: días de retraso para liberar el fixture de la siguiente jornada.
		$has_release = $wpdb->get_var( "SHOW COLUMNS FROM {$prefix}ds_tournaments LIKE 'fixture_release_days'" ); // phpcs:ignore
		if ( ! $has_release ) {
			$wpdb->query( // phpcs:ignore
				"ALTER TABLE {$prefix}ds_tournaments
				 ADD COLUMN fixture_release_days TINYINT(1) NOT NULL DEFAULT 0
				 AFTER registration_mode"
			);
		}

		// v1.7.0 — ds_matches: nombres de árbitro y planillero (modo deferred).
		$has_ref_name = $wpdb->get_var( "SHOW COLUMNS FROM {$prefix}ds_matches LIKE 'referee_name'" ); // phpcs:ignore
		if ( ! $has_ref_name ) {
			$wpdb->query( // phpcs:ignore
				"ALTER TABLE {$prefix}ds_matches
				 ADD COLUMN referee_name    VARCHAR(120) NULL AFTER planillero_user_id,
				 ADD COLUMN planillero_name VARCHAR(120) NULL AFTER referee_name"
			);
		}

		// v1.7.2 — ds_matches: añadir status a idx_court_datetime para evitar post-filter en anti-colisión.
		$court_dt_cols = $wpdb->get_var( "SHOW INDEX FROM {$prefix}ds_matches WHERE Key_name = 'idx_court_datetime' AND Column_name = 'status'" ); // phpcs:ignore
		if ( ! $court_dt_cols ) {
			$wpdb->query( // phpcs:ignore
				"ALTER TABLE {$prefix}ds_matches
				 DROP INDEX IF EXISTS idx_court_datetime,
				 ADD KEY idx_court_datetime (court_id, status, match_datetime)"
			);
		}

		// v1.7.2 — ds_matches: añadir status a idx_referee_datetime.
		$ref_dt_cols = $wpdb->get_var( "SHOW INDEX FROM {$prefix}ds_matches WHERE Key_name = 'idx_referee_datetime' AND Column_name = 'status'" ); // phpcs:ignore
		if ( ! $ref_dt_cols ) {
			$wpdb->query( // phpcs:ignore
				"ALTER TABLE {$prefix}ds_matches
				 DROP INDEX IF EXISTS idx_referee_datetime,
				 ADD KEY idx_referee_datetime (referee_user_id, status, match_datetime)"
			);
		}
	}

	/**
	 * Ejecuta create_tables() solo si la versión instalada es anterior a la actual.
	 */
	public static function maybe_upgrade(): void {
		$installed = get_option( 'soccertrack_db_version', '0' );

		if ( version_compare( $installed, SOCCERTRACK_DB_VERSION, '<' ) ) {
			self::create_tables();
		}
	}

	/**
	 * Elimina todas las tablas del plugin.
	 * Solo se llama desde uninstall.php.
	 *
	 * Orden de borrado: primero tablas con FK dependientes.
	 */
	public static function drop_tables(): void {
		global $wpdb;

		$tables = [
			'ds_disciplinary_sanctions',
			'ds_match_events',
			'ds_matches',
			'ds_team_players',
			'ds_players',
			'ds_teams',
			'ds_courts',
			'ds_venues',
			'ds_tournaments',
		];

		foreach ( $tables as $table ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange
			$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}{$table}" );
		}
	}
}
