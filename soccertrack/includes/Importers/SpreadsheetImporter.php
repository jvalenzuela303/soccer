<?php
/**
 * Importador masivo de equipos y jugadores desde CSV/XLSX.
 *
 * Usa PhpSpreadsheet para leer archivos Excel y CSV.
 * Requiere: composer require phpoffice/phpspreadsheet
 *
 * Formato esperado para equipos (fila 1 = cabecera ignorada):
 *   A: Nombre del equipo
 *   B: Ciudad (opcional)
 *   C: Colores (opcional)
 *   D: Director Técnico (opcional)
 *
 * Formato esperado para jugadores:
 *   A: RUT (12345678-9 o con puntos)
 *   B: Nombre
 *   C: Apellido
 *   D: Dorsal (1-99)
 *
 * @package SoccerTrack
 */

declare(strict_types=1);

namespace SportsLeague\Importers;

// Cargar Composer vendor solo cuando se usa el importador (carga lazy).
if ( ! class_exists( \PhpOffice\PhpSpreadsheet\IOFactory::class ) ) {
	$vendor_autoload = dirname( __DIR__, 2 ) . '/vendor/autoload.php';
	if ( file_exists( $vendor_autoload ) ) {
		require_once $vendor_autoload;
	}
}

use PhpOffice\PhpSpreadsheet\IOFactory;

final class SpreadsheetImporter {

	public function __construct(
		private readonly ImportValidator $validator = new ImportValidator()
	) {}

	/**
	 * Importa una nómina completa (equipo + jugadores) desde la planilla oficial.
	 *
	 * Estructura esperada (hoja "Nomina"):
	 *   C1  → Nombre de Empresa
	 *   C2  → Rut Empresa
	 *   C3  → Dirección
	 *   C4  → Nombre Delegado
	 *   C5  → Rut Delegado
	 *   C6  → Correo Delegado
	 *   C7  → Celular Delegado
	 *   C8  → Nombre del Equipo
	 *   Fila 10 → Cabecera de jugadores (ignorada)
	 *   Filas 11–35:
	 *     A = N° de inscripción (número secuencial — no se almacena como dorsal)
	 *     B = Nombre
	 *     C = Apellido Paterno
	 *     D = Apellido Materno
	 *     E = RUT
	 *     F = Correo
	 *     G = Edad
	 *     H = Teléfono
	 *     I = Área
	 *     J = Cargo
	 *
	 * @param  string $file_path     Ruta absoluta al archivo subido (tmp_name).
	 * @param  int    $tournament_id ID del torneo destino.
	 * @return array{team_name: string, team_id: int, imported: int, skipped: int, errors: string[]}
	 */
	public function import_team_roster( string $file_path, int $tournament_id ): array {
		global $wpdb;

		$sheet    = IOFactory::load( $file_path )->getActiveSheet();
		$errors   = [];
		$imported = 0;
		$updated  = 0; // Ya inscrito en este equipo — datos personales/área/cargo refrescados.
		$skipped  = 0; // Conflicto real: jugador inscrito en otro equipo del mismo torneo.

		// ── Leer bloque cabecera (filas 1-8, valores en col C = índice 3) ──────
		$get_cell = static fn( int $row ): string =>
			trim( (string) $sheet->getCell( "C{$row}" )->getValue() );

		$empresa_nombre  = $get_cell( 1 );
		$empresa_rut     = $get_cell( 2 );
		$empresa_dir     = $get_cell( 3 );
		$delegado_nombre = $get_cell( 4 );
		$delegado_rut    = $get_cell( 5 );
		$delegado_correo = $get_cell( 6 );
		$delegado_celular = $get_cell( 7 );
		$team_name       = $get_cell( 8 );

		// Fallback: si no hay "Nombre del Equipo", usar "Nombre de Empresa".
		if ( empty( $team_name ) ) {
			$team_name = $empresa_nombre;
		}

		if ( empty( $team_name ) ) {
			return [
				'team_name' => '',
				'team_id'   => 0,
				'imported'  => 0,
				'updated'   => 0,
				'skipped'   => 0,
				'errors'    => [ __( 'No se encontró "Nombre del Equipo" en la planilla (celda C8).', 'soccertrack' ) ],
			];
		}

		$team_name_clean = sanitize_text_field( $team_name );

		// ── Upsert equipo en el torneo ────────────────────────────────────────
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$team_id = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$wpdb->prefix}ds_teams WHERE tournament_id = %d AND name = %s",
				$tournament_id,
				$team_name_clean
			)
		);

		$team_data = [
			'empresa_nombre'   => sanitize_text_field( $empresa_nombre ),
			'empresa_rut'      => sanitize_text_field( $empresa_rut ),
			'empresa_dir'      => sanitize_text_field( $empresa_dir ),
			'delegado_nombre'  => sanitize_text_field( $delegado_nombre ),
			'delegado_rut'     => sanitize_text_field( $delegado_rut ),
			'delegado_correo'  => sanitize_email( $delegado_correo ),
			'delegado_celular' => sanitize_text_field( $delegado_celular ),
		];
		$team_formats = [ '%s', '%s', '%s', '%s', '%s', '%s', '%s' ];

		if ( $team_id ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->update(
				"{$wpdb->prefix}ds_teams",
				$team_data,
				[ 'id' => $team_id ],
				$team_formats,
				[ '%d' ]
			);
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->insert(
				"{$wpdb->prefix}ds_teams",
				array_merge(
					[ 'tournament_id' => $tournament_id, 'name' => $team_name_clean ],
					$team_data
				),
				array_merge( [ '%d', '%s' ], $team_formats )
			);
			$team_id = (int) $wpdb->insert_id;
		}

		if ( ! $team_id ) {
			return [
				'team_name' => $team_name_clean,
				'team_id'   => 0,
				'imported'  => 0,
				'updated'   => 0,
				'skipped'   => 0,
				'errors'    => [ sprintf( __( 'Error al crear/actualizar el equipo "%s".', 'soccertrack' ), $team_name_clean ) ],
			];
		}

		// ── Pre-cargar RUTs existentes en el torneo ──────────────────────────
		// Una sola query antes del loop evita N SELECTs por jugador.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$rut_rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT p.rut_id, p.id
				 FROM {$wpdb->prefix}ds_players p
				 JOIN {$wpdb->prefix}ds_team_players tp ON tp.player_id = p.id
				 JOIN {$wpdb->prefix}ds_teams t ON t.id = tp.team_id
				 WHERE t.tournament_id = %d",
				$tournament_id
			),
			ARRAY_A
		) ?: [];
		// $existing_ruts[ normalized_rut ] = player_id
		$existing_ruts = [];
		foreach ( $rut_rows as $r ) {
			$existing_ruts[ $r['rut_id'] ] = (int) $r['id'];
		}

		// ── Pre-cargar inscripciones en este equipo ───────────────────────────
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$tp_rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, player_id FROM {$wpdb->prefix}ds_team_players WHERE team_id = %d",
				$team_id
			),
			ARRAY_A
		) ?: [];
		// $enrolled_in_team[ player_id ] = team_players.id
		$enrolled_in_team = [];
		foreach ( $tp_rows as $r ) {
			$enrolled_in_team[ (int) $r['player_id'] ] = (int) $r['id'];
		}

		// ── Pre-cargar inscripciones del torneo para detectar doble inscripción sin N+1 ───
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$tournament_inscriptions_raw = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT tp.player_id, t.name AS team_name
				 FROM {$wpdb->prefix}ds_team_players tp
				 JOIN {$wpdb->prefix}ds_teams t ON t.id = tp.team_id
				 WHERE t.tournament_id = %d AND tp.team_id != %d",
				$tournament_id,
				$team_id
			),
			ARRAY_A
		) ?: [];
		// Construir mapa player_id → nombre del equipo conflictivo.
		$inscribed_elsewhere = [];
		foreach ( $tournament_inscriptions_raw as $row ) {
			$inscribed_elsewhere[ (int) $row['player_id'] ] = $row['team_name'];
		}
		unset( $tournament_inscriptions_raw );

		// ── Leer jugadores (fila 11 en adelante) ─────────────────────────────
		foreach ( $sheet->getRowIterator( 11 ) as $row ) {
			$cells = $row->getCellIterator();
			$cells->setIterateOnlyExistingCells( false );
			$data = [];
			foreach ( $cells as $cell ) {
				$data[] = trim( (string) $cell->getValue() );
			}

			// Col índices (0-based): A=0 (N° inscripción, ignorado), B=1, C=2, D=3, E=4, F=5, G=6, H=7, I=8, J=9
			$first_name   = $data[1] ?? '';
			$last_name    = $data[2] ?? '';
			$last_name_m  = $data[3] ?? '';
			$rut          = $data[4] ?? '';
			$email        = $data[5] ?? '';
			$phone        = $data[7] ?? '';
			$area         = $data[8] ?? '';
			$cargo        = $data[9] ?? '';

			// Fila vacía → saltar.
			if ( empty( $first_name ) && empty( $rut ) ) {
				continue;
			}

			if ( empty( $first_name ) ) {
				$errors[] = sprintf( __( 'Fila %d: falta el nombre del jugador.', 'soccertrack' ), $row->getRowIndex() );
				continue;
			}

			if ( empty( $rut ) ) {
				$errors[] = sprintf( __( 'Fila %d: falta el RUT (%s %s).', 'soccertrack' ), $row->getRowIndex(), $first_name, $last_name );
				continue;
			}

			if ( ! $this->validator->is_valid_rut( $rut ) ) {
				$errors[] = sprintf( __( 'RUT inválido: %s (%s %s)', 'soccertrack' ), $rut, $first_name, $last_name );
				continue;
			}

			$normalized_rut = $this->validator->normalize_rut( $rut );

			// Upsert jugador global por RUT — usar cache pre-cargado para evitar SELECT por fila.
			$player_id = $existing_ruts[ $normalized_rut ] ?? 0;

			if ( ! $player_id ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$wpdb->insert(
					"{$wpdb->prefix}ds_players",
					[
						'rut_id'     => $normalized_rut,
						'first_name' => sanitize_text_field( $first_name ),
						'last_name'  => sanitize_text_field( $last_name ),
						'last_name_m' => sanitize_text_field( $last_name_m ),
						'email'      => sanitize_email( $email ),
						'phone'      => sanitize_text_field( $phone ),
					],
					[ '%s', '%s', '%s', '%s', '%s', '%s' ]
				);
				$player_id = (int) $wpdb->insert_id;
				// Registrar en cache para posibles filas duplicadas en esta misma planilla.
				$existing_ruts[ $normalized_rut ] = $player_id;
			} else {
				// Actualizar datos que pudieron cambiar.
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$wpdb->update(
					"{$wpdb->prefix}ds_players",
					[
						'first_name'  => sanitize_text_field( $first_name ),
						'last_name'   => sanitize_text_field( $last_name ),
						'last_name_m' => sanitize_text_field( $last_name_m ),
						'email'       => sanitize_email( $email ),
						'phone'       => sanitize_text_field( $phone ),
					],
					[ 'id' => $player_id ],
					[ '%s', '%s', '%s', '%s', '%s' ],
					[ '%d' ]
				);
			}

			if ( ! $player_id ) {
				$errors[] = sprintf( __( 'Error al crear jugador con RUT %s.', 'soccertrack' ), $normalized_rut );
				continue;
			}

			// Verificar si ya está en este equipo — usar cache pre-cargado.
			$already = $enrolled_in_team[ $player_id ] ?? null;

			if ( $already ) {
				// Ya inscrito: actualizar área/cargo y contar como "actualizado", no como omitido.
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$wpdb->update(
					"{$wpdb->prefix}ds_team_players",
					[
						'area'  => sanitize_text_field( $area ),
						'cargo' => sanitize_text_field( $cargo ),
					],
					[ 'id' => $already ],
					[ '%s', '%s' ],
					[ '%d' ]
				);
				$updated++;
				continue;
			}

			// Verificar doble inscripción en el mismo torneo — usar mapa pre-cargado.
			$conflict_team = $inscribed_elsewhere[ $player_id ] ?? null;

			if ( $conflict_team ) {
				// Conflicto real: el jugador ya pertenece a otro equipo en este torneo.
				// Se cuenta como "omitido" (no se inscribe) y se informa al coordinador.
				$errors[] = sprintf(
					/* translators: 1: RUT, 2: nombre del equipo en conflicto */
					__( 'Conflicto: RUT %1$s ya inscrito en "%2$s" (mismo torneo) — requiere aprobación de coordinador.', 'soccertrack' ),
					$normalized_rut,
					$conflict_team
				);
				$skipped++;
				continue;
			}

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->insert(
				"{$wpdb->prefix}ds_team_players",
				[
					'team_id'   => $team_id,
					'player_id' => $player_id,
					'dorsal'    => null,
					'area'      => sanitize_text_field( $area ),
					'cargo'     => sanitize_text_field( $cargo ),
				],
				[ '%d', '%d', null, '%s', '%s' ]
			);

			if ( $wpdb->last_error ) {
				$errors[] = sprintf(
					__( 'Error al inscribir RUT %s en el equipo: %s', 'soccertrack' ),
					$normalized_rut,
					$wpdb->last_error
				);
			} else {
				$enrolled_in_team[ $player_id ] = (int) $wpdb->insert_id;
				$imported++;
			}
		}

		return [
			'team_name' => $team_name_clean,
			'team_id'   => $team_id,
			'imported'  => $imported,
			'updated'   => $updated,
			'skipped'   => $skipped,
			'errors'    => $errors,
		];
	}

	/**
	 * Importa veedores desde un archivo CSV/XLSX.
	 * Crea un usuario WordPress con rol ds_veedor por cada fila.
	 *
	 * Columnas esperadas (fila 1 = cabecera ignorada):
	 *   A: Nombre
	 *   B: Apellido
	 *   C: Correo electrónico
	 *
	 * @param  string $file_path Ruta absoluta al archivo.
	 * @return array{imported: int, skipped: int, errors: string[], passwords: array<string, string>}
	 */
	public function import_referees( string $file_path ): array {
		$sheet    = IOFactory::load( $file_path )->getActiveSheet();
		$errors   = [];
		$imported = 0;
		$skipped  = 0;
		$passwords = [];

		foreach ( $sheet->getRowIterator( 2 ) as $row ) {
			$cells = $row->getCellIterator();
			$cells->setIterateOnlyExistingCells( false );
			$data = [];
			foreach ( $cells as $cell ) {
				$data[] = (string) $cell->getValue();
			}

			[ $first_name, $last_name, $email ] = array_pad( $data, 3, '' );
			$first_name = trim( $first_name );
			$last_name  = trim( $last_name );
			$email      = sanitize_email( trim( $email ) );

			if ( empty( $first_name ) || empty( $email ) ) {
				continue;
			}

			if ( ! is_email( $email ) ) {
				$errors[] = sprintf( __( 'Correo inválido: %s', 'soccertrack' ), $email );
				continue;
			}

			if ( email_exists( $email ) ) {
				$skipped++;
				continue;
			}

			$display_name = sanitize_text_field( $first_name . ' ' . $last_name );
			$password     = wp_generate_password( 12, false );
			$result       = \SportsLeague\Core\UserManager::create( $email, $display_name, 'ds_veedor', $password );

			if ( is_wp_error( $result ) ) {
				$errors[] = sprintf( '%s — %s', $email, $result->get_error_message() );
			} else {
				$imported++;
				$passwords[ $email ] = $password;
			}
		}

		return compact( 'imported', 'skipped', 'errors', 'passwords' );
	}

	/**
	 * Importa jugadores desde un archivo CSV/XLSX al equipo indicado.
	 * Si el jugador (por RUT) ya existe globalmente, solo lo asocia al equipo.
	 *
	 * @param  string $file_path Ruta absoluta al archivo.
	 * @param  int    $team_id   ID del equipo destino.
	 * @return array{imported: int, skipped: int, errors: string[]}
	 */
	public function import_players( string $file_path, int $team_id ): array {
		global $wpdb;

		$sheet    = IOFactory::load( $file_path )->getActiveSheet();
		$errors   = [];
		$imported = 0;
		$skipped  = 0;

		// Resolver tournament_id una sola vez — es constante para todo el equipo.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$tournament_id = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT tournament_id FROM {$wpdb->prefix}ds_teams WHERE id = %d",
				$team_id
			)
		);

		foreach ( $sheet->getRowIterator( 2 ) as $row ) {
			$cells = $row->getCellIterator();
			$cells->setIterateOnlyExistingCells( false );
			$data = [];
			foreach ( $cells as $cell ) {
				$data[] = (string) $cell->getValue();
			}

			[ $rut, $first_name, $last_name, $dorsal_raw ] = array_pad( $data, 4, '' );
			$rut    = trim( $rut );
			$dorsal = (int) trim( $dorsal_raw );

			if ( empty( $rut ) || empty( $first_name ) ) {
				continue;
			}

			if ( ! $this->validator->is_valid_rut( $rut ) ) {
				$errors[] = sprintf( __( 'RUT inválido: %s', 'soccertrack' ), $rut );
				continue;
			}

			if ( ! $this->validator->is_valid_dorsal( $dorsal ) ) {
				$errors[] = sprintf( __( 'Dorsal inválido (%d) para RUT %s', 'soccertrack' ), $dorsal, $rut );
				continue;
			}

			$normalized_rut = $this->validator->normalize_rut( $rut );

			// Upsert jugador global (por RUT único).
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$player_id = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT id FROM {$wpdb->prefix}ds_players WHERE rut_id = %s",
					$normalized_rut
				)
			);

			if ( ! $player_id ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$wpdb->insert(
					"{$wpdb->prefix}ds_players",
					[
						'rut_id'     => $normalized_rut,
						'first_name' => sanitize_text_field( $first_name ),
						'last_name'  => sanitize_text_field( $last_name ),
					],
					[ '%s', '%s', '%s' ]
				);
				$player_id = (int) $wpdb->insert_id;
			}

			if ( ! $player_id ) {
				$errors[] = sprintf( __( 'Error al crear jugador con RUT %s', 'soccertrack' ), $normalized_rut );
				continue;
			}

			// Verificar si ya está en este equipo.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$already = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT id FROM {$wpdb->prefix}ds_team_players WHERE team_id = %d AND player_id = %d",
					$team_id,
					$player_id
				)
			);

			if ( $already ) {
				$skipped++;
				continue;
			}

			// Bloquear inscripción en otro equipo del mismo torneo.
			if ( $tournament_id ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$conflict_team = $wpdb->get_var(
					$wpdb->prepare(
						"SELECT t.name
						 FROM {$wpdb->prefix}ds_team_players tp
						 JOIN {$wpdb->prefix}ds_teams t ON t.id = tp.team_id
						 WHERE tp.player_id = %d AND t.tournament_id = %d AND tp.team_id != %d
						 LIMIT 1",
						$player_id,
						$tournament_id,
						$team_id
					)
				);

				if ( $conflict_team ) {
					$errors[] = sprintf(
						/* translators: 1: RUT, 2: nombre del equipo en conflicto */
						__( 'RUT %1$s ya inscrito en "%2$s" (mismo torneo) — omitido. Requiere aprobación de coordinador.', 'soccertrack' ),
						$normalized_rut,
						$conflict_team
					);
					$skipped++;
					continue;
				}
			}

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->insert(
				"{$wpdb->prefix}ds_team_players",
				[
					'team_id'   => $team_id,
					'player_id' => $player_id,
					'dorsal'    => $dorsal,
				],
				[ '%d', '%d', '%d' ]
			);

			if ( $wpdb->last_error ) {
				$errors[] = sprintf(
					__( 'Error al asociar jugador RUT %s (dorsal %d): %s', 'soccertrack' ),
					$normalized_rut,
					$dorsal,
					$wpdb->last_error
				);
			} else {
				$imported++;
			}
		}

		return [
			'imported' => $imported,
			'skipped'  => $skipped,
			'errors'   => $errors,
		];
	}
}
