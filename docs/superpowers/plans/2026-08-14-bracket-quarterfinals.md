# Bracket Quarterfinals Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Agregar cuartos de final a brackets de 8 equipos en formato `round_robin_playoffs`, sin afectar brackets de 4 equipos ni otros formatos.

**Architecture:** El endpoint REST `playoffs` existente sigue sin cambios. `generate_bracket_playoffs()` detecta el estado del bracket y genera la ronda correcta (cuartos si hay 8 equipos y no existen cuartos aún, semis si los cuartos están done). TournamentPage.php enriquece cada bracket con `has_quarterfinals`, `quarterfinals_done` y `num_teams`. El template consume esos campos para mostrar 4 estados UI.

**Tech Stack:** PHP 8.2, WordPress, MariaDB 10.6+. Sin dependencias nuevas.

## Global Constraints

- PHP 8.2 — usar `match`, nullsafe operator, named args donde corresponda
- WordPress Coding Standards (WPCS): `// phpcs:ignore` en queries directas de `$wpdb`
- i18n: todo texto visible envuelto en `esc_html__( '...', 'soccertrack' )`
- No tocar: `generate_bracket_finals()`, endpoints REST, formatos `knockout` / `group_stage`
- DB schema: sin cambios (ENUM `phase` ya tiene `quarterfinal`)
- Seeding cuartos: 1º vs 8º, 2º vs 7º, 3º vs 6º, 4º vs 5º

---

## Mapa de archivos

| Archivo | Qué cambia |
|---|---|
| `soccertrack/includes/Core/FixtureGenerator.php` | Reescribir cuerpo de `generate_bracket_playoffs()` (líneas 591–660) |
| `soccertrack/includes/Public/TournamentPage.php` | Extender enriquecimiento de bracket (líneas 1276–1290) |
| `soccertrack/templates/panel/torneo-detalle.php` | Reemplazar bloque UI por bracket (líneas 1022–1071) + agregar label en `$phase_labels` (línea 850) |

**No se modifican:** `live-standings.js`, `tournament-page.php`, `AdminEndpoints.php` (ya tienen `quarterfinal` o no necesitan cambios).

---

### Task 1: `generate_bracket_playoffs()` — detección inteligente de ronda

**Files:**
- Modify: `soccertrack/includes/Core/FixtureGenerator.php:591-660`

**Interfaces:**
- Consumes: `StandingsCalculator::recalculate(int $tournament_id): array` — retorna array con `['team_id' => int, ...]` ordenado por posición
- Produces: `array{match_ids: int[], error?: string}` — sin cambio de firma

- [ ] **Step 1: Reemplazar el bloque de verificación y generación (líneas 591–660)**

Reemplazar desde `// 3. Verificar que no existan ya semi-finales...` hasta el final del método (antes del cierre `}`):

```php
		// 3. Detectar estado de cuartos para este bracket.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$qf_matches = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, home_team_id, away_team_id, home_score, away_score, status
				 FROM {$wpdb->prefix}ds_matches
				 WHERE tournament_id = %d AND bracket_id = %d AND phase = 'quarterfinal'
				 ORDER BY id ASC",
				$tournament_id,
				$bracket_id
			),
			ARRAY_A
		) ?: [];

		$has_qf   = ! empty( $qf_matches );
		$qf_done  = $has_qf && count( array_filter( $qf_matches, static fn( $m ) => ! in_array( $m['status'], [ 'finished', 'suspended', 'postponed' ], true ) ) ) === 0;
		$qf_in_progress = $has_qf && ! $qf_done;

		if ( $qf_in_progress ) {
			return [ 'match_ids' => [], 'error' => __( 'Los cuartos de final de este bracket están en curso. Espera a que terminen.', 'soccertrack' ) ];
		}

		// 4. Extraer equipos del rango del bracket de la tabla de posiciones.
		$standings     = ( new StandingsCalculator() )->recalculate( $tournament_id );
		$bracket_teams = array_slice( $standings, $rank_from - 1, $rank_to - $rank_from + 1 );
		$num_teams     = count( $bracket_teams );

		if ( ! $has_qf && $num_teams < 4 ) {
			return [ 'match_ids' => [], 'error' => __( 'Se necesitan al menos 4 equipos en el rango del bracket.', 'soccertrack' ) ];
		}

		// 5a. Generar SEMI-FINALES desde ganadores de cuartos (cuando cuartos done).
		if ( $qf_done ) {
			// Verificar que no existan ya semi-finales.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$existing_sf = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$wpdb->prefix}ds_matches
					 WHERE tournament_id = %d AND bracket_id = %d AND phase = 'semifinal'",
					$tournament_id,
					$bracket_id
				)
			);
			if ( $existing_sf > 0 ) {
				return [ 'match_ids' => [], 'error' => __( 'Las semi-finales de este bracket ya fueron generadas.', 'soccertrack' ) ];
			}

			// Resolver ganadores de cuartos (empate → gana local).
			$resolve = static function ( array $m ): int {
				return (int) $m['home_score'] >= (int) $m['away_score']
					? (int) $m['home_team_id']
					: (int) $m['away_team_id'];
			};

			// QF1 ganador vs QF4 ganador → SF1; QF2 ganador vs QF3 ganador → SF2.
			$sf_pairs = [
				[ 'home' => $resolve( $qf_matches[0] ), 'away' => $resolve( $qf_matches[3] ) ],
				[ 'home' => $resolve( $qf_matches[1] ), 'away' => $resolve( $qf_matches[2] ) ],
			];

			if ( $match_date ) {
				$sf_pairs[0]['dt'] = $this->datetime_from_date( $match_date, $time, 0, $duration );
				$sf_pairs[1]['dt'] = $this->datetime_from_date( $match_date, $time, 1, $duration );
			} else {
				$sf_pairs[0]['dt'] = $this->next_match_datetime( $weekdays, $time, 0, 0, $duration );
				$sf_pairs[1]['dt'] = $this->next_match_datetime( $weekdays, $time, 1, 0, $duration );
			}

			$ids = [];
			foreach ( $sf_pairs as $pair ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$wpdb->insert(
					"{$wpdb->prefix}ds_matches",
					[
						'tournament_id'  => $tournament_id,
						'round_number'   => 0,
						'home_team_id'   => $pair['home'],
						'away_team_id'   => $pair['away'],
						'venue_id'       => $venue_id,
						'court_id'       => 0,
						'match_datetime' => $pair['dt'],
						'status'         => 'scheduled',
						'phase'          => 'semifinal',
						'bracket_id'     => $bracket_id,
					],
					[ '%d', '%d', '%d', '%d', '%d', '%d', '%s', '%s', '%s', '%d' ]
				);
				if ( $wpdb->insert_id ) {
					$ids[] = (int) $wpdb->insert_id;
				}
			}
			$this->assign_courts( $ids, $venue_id );
			return [ 'match_ids' => $ids ];
		}

		// 5b. Generar CUARTOS DE FINAL (bracket de 8 equipos, seeding estándar).
		if ( $num_teams >= 8 ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$existing_qf = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$wpdb->prefix}ds_matches
					 WHERE tournament_id = %d AND bracket_id = %d AND phase = 'quarterfinal'",
					$tournament_id,
					$bracket_id
				)
			);
			if ( $existing_qf > 0 ) {
				return [ 'match_ids' => [], 'error' => __( 'Los cuartos de final de este bracket ya fueron generados.', 'soccertrack' ) ];
			}

			// Seeding: 1º vs 8º, 2º vs 7º, 3º vs 6º, 4º vs 5º.
			$qf_pairs = [
				[ 'home' => (int) $bracket_teams[0]['team_id'], 'away' => (int) $bracket_teams[7]['team_id'] ],
				[ 'home' => (int) $bracket_teams[1]['team_id'], 'away' => (int) $bracket_teams[6]['team_id'] ],
				[ 'home' => (int) $bracket_teams[2]['team_id'], 'away' => (int) $bracket_teams[5]['team_id'] ],
				[ 'home' => (int) $bracket_teams[3]['team_id'], 'away' => (int) $bracket_teams[4]['team_id'] ],
			];

			$ids = [];
			foreach ( $qf_pairs as $i => $pair ) {
				$dt = $match_date
					? $this->datetime_from_date( $match_date, $time, $i, $duration )
					: $this->next_match_datetime( $weekdays, $time, $i, 0, $duration );

				// phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$wpdb->insert(
					"{$wpdb->prefix}ds_matches",
					[
						'tournament_id'  => $tournament_id,
						'round_number'   => 0,
						'home_team_id'   => $pair['home'],
						'away_team_id'   => $pair['away'],
						'venue_id'       => $venue_id,
						'court_id'       => 0,
						'match_datetime' => $dt,
						'status'         => 'scheduled',
						'phase'          => 'quarterfinal',
						'bracket_id'     => $bracket_id,
					],
					[ '%d', '%d', '%d', '%d', '%d', '%d', '%s', '%s', '%s', '%d' ]
				);
				if ( $wpdb->insert_id ) {
					$ids[] = (int) $wpdb->insert_id;
				}
			}
			$this->assign_courts( $ids, $venue_id );
			return [ 'match_ids' => $ids ];
		}

		// 5c. Generar SEMI-FINALES directamente (bracket de 4 equipos — comportamiento actual).
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$existing_sf = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}ds_matches
				 WHERE tournament_id = %d AND bracket_id = %d AND phase = 'semifinal'",
				$tournament_id,
				$bracket_id
			)
		);
		if ( $existing_sf > 0 ) {
			return [ 'match_ids' => [], 'error' => __( 'Las semi-finales de este bracket ya fueron generadas.', 'soccertrack' ) ];
		}

		$first  = (int) $bracket_teams[0]['team_id'];
		$second = (int) $bracket_teams[1]['team_id'];
		$third  = (int) $bracket_teams[ $num_teams - 2 ]['team_id'];
		$last   = (int) $bracket_teams[ $num_teams - 1 ]['team_id'];

		if ( $match_date ) {
			$dt_sf1 = $this->datetime_from_date( $match_date, $time, 0, $duration );
			$dt_sf2 = $this->datetime_from_date( $match_date, $time, 1, $duration );
		} else {
			$dt_sf1 = $this->next_match_datetime( $weekdays, $time, 0, 0, $duration );
			$dt_sf2 = $this->next_match_datetime( $weekdays, $time, 1, 0, $duration );
		}

		$ids = [];
		foreach ( [
			[ 'home' => $first,  'away' => $last,  'dt' => $dt_sf1 ],
			[ 'home' => $second, 'away' => $third, 'dt' => $dt_sf2 ],
		] as $pair ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->insert(
				"{$wpdb->prefix}ds_matches",
				[
					'tournament_id'  => $tournament_id,
					'round_number'   => 0,
					'home_team_id'   => $pair['home'],
					'away_team_id'   => $pair['away'],
					'venue_id'       => $venue_id,
					'court_id'       => 0,
					'match_datetime' => $pair['dt'],
					'status'         => 'scheduled',
					'phase'          => 'semifinal',
					'bracket_id'     => $bracket_id,
				],
				[ '%d', '%d', '%d', '%d', '%d', '%d', '%s', '%s', '%s', '%d' ]
			);
			if ( $wpdb->insert_id ) {
				$ids[] = (int) $wpdb->insert_id;
			}
		}
		$this->assign_courts( $ids, $venue_id );
		return [ 'match_ids' => $ids ];
	}
```

- [ ] **Step 2: Verificar que el archivo compila sin errores de PHP**

```bash
php -l soccertrack/includes/Core/FixtureGenerator.php
```
Esperado: `No syntax errors detected`

- [ ] **Step 3: Commit**

```bash
git add soccertrack/includes/Core/FixtureGenerator.php
git commit -m "feat(playoffs): generate_bracket_playoffs detects QF state and routes correctly"
```

---

### Task 2: `TournamentPage.php` — enriquecer estado del bracket

**Files:**
- Modify: `soccertrack/includes/Public/TournamentPage.php:1276-1290`

**Interfaces:**
- Consumes: `$matches` — array de partidos del torneo (ya cargado, incluye `bracket_id` y `phase`)
- Produces: cada item de `$brackets[]` incluye ahora `has_quarterfinals` (bool), `quarterfinals_done` (bool), `num_teams` (int)

- [ ] **Step 1: Reemplazar el bloque de enriquecimiento del bracket (líneas 1276–1290)**

Localizar este bloque exacto:

```php
		foreach ( $brackets_raw ?: [] as $b ) {
			$bid          = (int) $b['id'];
			$b_semis      = array_filter( $matches, static fn( $m ) => (int) ( $m['bracket_id'] ?? 0 ) === $bid && ( $m['phase'] ?? '' ) === 'semifinal' );
			$b_has_semis  = ! empty( $b_semis );
			$b_semis_done = $b_has_semis && count( array_filter( $b_semis, static fn( $m ) => ! in_array( $m['status'], [ 'finished', 'suspended', 'postponed' ], true ) ) ) === 0;
			$b_has_finals = ! empty( array_filter( $matches, static fn( $m ) => (int) ( $m['bracket_id'] ?? 0 ) === $bid && in_array( $m['phase'] ?? '', [ 'final', 'third_place' ], true ) ) );

			$brackets[] = array_merge( $b, [
				'locked'       => (bool) (int) $b['locked'],
				'rank_from'    => (int) $b['rank_from'],
				'rank_to'      => (int) $b['rank_to'],
				'sort_order'   => (int) $b['sort_order'],
				'has_semis'    => $b_has_semis,
				'semis_done'   => $b_semis_done,
				'has_finals'   => $b_has_finals,
			] );
		}
```

Reemplazarlo con:

```php
		foreach ( $brackets_raw ?: [] as $b ) {
			$bid = (int) $b['id'];

			$b_qf      = array_filter( $matches, static fn( $m ) => (int) ( $m['bracket_id'] ?? 0 ) === $bid && ( $m['phase'] ?? '' ) === 'quarterfinal' );
			$b_has_qf  = ! empty( $b_qf );
			$b_qf_done = $b_has_qf && count( array_filter( $b_qf, static fn( $m ) => ! in_array( $m['status'], [ 'finished', 'suspended', 'postponed' ], true ) ) ) === 0;

			$b_semis      = array_filter( $matches, static fn( $m ) => (int) ( $m['bracket_id'] ?? 0 ) === $bid && ( $m['phase'] ?? '' ) === 'semifinal' );
			$b_has_semis  = ! empty( $b_semis );
			$b_semis_done = $b_has_semis && count( array_filter( $b_semis, static fn( $m ) => ! in_array( $m['status'], [ 'finished', 'suspended', 'postponed' ], true ) ) ) === 0;

			$b_has_finals = ! empty( array_filter( $matches, static fn( $m ) => (int) ( $m['bracket_id'] ?? 0 ) === $bid && in_array( $m['phase'] ?? '', [ 'final', 'third_place' ], true ) ) );

			$brackets[] = array_merge( $b, [
				'locked'               => (bool) (int) $b['locked'],
				'rank_from'            => (int) $b['rank_from'],
				'rank_to'              => (int) $b['rank_to'],
				'sort_order'           => (int) $b['sort_order'],
				'num_teams'            => (int) $b['rank_to'] - (int) $b['rank_from'] + 1,
				'has_quarterfinals'    => $b_has_qf,
				'quarterfinals_done'   => $b_qf_done,
				'has_semis'            => $b_has_semis,
				'semis_done'           => $b_semis_done,
				'has_finals'           => $b_has_finals,
			] );
		}
```

- [ ] **Step 2: Verificar sintaxis**

```bash
php -l soccertrack/includes/Public/TournamentPage.php
```
Esperado: `No syntax errors detected`

- [ ] **Step 3: Commit**

```bash
git add soccertrack/includes/Public/TournamentPage.php
git commit -m "feat(playoffs): enrich bracket status with has_quarterfinals, quarterfinals_done, num_teams"
```

---

### Task 3: Template `torneo-detalle.php` — UI de 4 estados por bracket

**Files:**
- Modify: `soccertrack/templates/panel/torneo-detalle.php:850-855` (label)
- Modify: `soccertrack/templates/panel/torneo-detalle.php:1022-1071` (bloque de acción)

**Interfaces:**
- Consumes: `$b['has_quarterfinals']` (bool), `$b['quarterfinals_done']` (bool), `$b['num_teams']` (int) — producidos en Task 2
- Produces: UI dinámica por bracket: 4 estados posibles + label "Cuartos" en tabla de partidos

- [ ] **Step 1: Agregar `quarterfinal` al mapa `$phase_labels` (línea ~850)**

Localizar:

```php
			$phase_labels = [
				'regular'     => '',
				'semifinal'   => '⚡ ' . __( 'Semi', 'soccertrack' ),
				'third_place' => '🥉 ' . __( '3.er Puesto', 'soccertrack' ),
				'final'       => '🏆 ' . __( 'Final', 'soccertrack' ),
			];
```

Reemplazar con:

```php
			$phase_labels = [
				'regular'      => '',
				'quarterfinal' => '⚽ ' . __( 'Cuartos', 'soccertrack' ),
				'semifinal'    => '⚡ ' . __( 'Semi', 'soccertrack' ),
				'third_place'  => '🥉 ' . __( '3.er Puesto', 'soccertrack' ),
				'final'        => '🏆 ' . __( 'Final', 'soccertrack' ),
			];
```

- [ ] **Step 2: Reemplazar el bloque de acción por bracket (líneas 1022–1071)**

Localizar el bloque completo que empieza con:

```php
					<div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap">
						<?php if ( $b['has_finals'] ) : ?>
							<span style="color:#3CBC20;font-weight:600">✅ <?php esc_html_e( 'Completo', 'soccertrack' ); ?></span>
						<?php elseif ( $b['has_semis'] && ! $b['semis_done'] ) : ?>
```

...y termina antes de `</div>` (al cerrar el div de flex). Reemplazar **todo el interior del div** con:

```php
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
```

- [ ] **Step 3: Verificar sintaxis**

```bash
php -l soccertrack/templates/panel/torneo-detalle.php
```
Esperado: `No syntax errors detected`

- [ ] **Step 4: Commit**

```bash
git add soccertrack/templates/panel/torneo-detalle.php
git commit -m "feat(playoffs): 4-state UI for bracket action block + quarterfinal phase label"
```

---

## Criterios de aceptación (verificación manual)

Para verificar con un torneo `round_robin_playoffs` con bracket de 8 equipos:

1. Con fase regular completa → botón muestra "⚽ Generar Cuartos"
2. Al hacer click → 4 partidos `phase='quarterfinal'` con `bracket_id` correcto en DB
3. Emparejamiento: pos 1 vs pos 8, pos 2 vs pos 7, pos 3 vs pos 6, pos 4 vs pos 5
4. Con cuartos en curso → mensaje "Cuartos de final en curso…" (sin botón)
5. Con 4 cuartos `finished` → botón "⚡ Generar Semi-finales"
6. Con semis en curso → mensaje "Semi-finales en curso…"
7. Con 2 semis `finished` → botón "🏆 Generar Final y 3.er Puesto"
8. Bracket de 4 equipos: muestra "⚡ Generar Semi-finales" (sin pasar por cuartos)
9. Tabla de partidos del panel: cuartos muestran etiqueta "⚽ Cuartos"
