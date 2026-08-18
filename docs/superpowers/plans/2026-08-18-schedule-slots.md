# Schedule Slots — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Distribuir automáticamente los partidos de cada ronda en bloques horarios con capacidad máxima por slot (ej. 19:00×8 + 20:00×5), avanzando al siguiente día disponible cuando todos los slots están llenos.

**Architecture:** Se agrega una columna `schedule_slots JSON` a `ds_tournaments`. Una nueva clase `SlotPacker` implementa el algoritmo de distribución. `FixtureGenerator::assign_courts()` delega a `SlotPacker` cuando el torneo tiene slots configurados; si no, mantiene el comportamiento actual.

**Tech Stack:** PHP 8.2, WordPress (`$wpdb`), sin dependencias externas.

## Global Constraints

- PHP 8.2 — usar `readonly`, `match`, named args, return types explícitos
- WordPress Coding Standards (WPCS) — `phpcs:ignore` solo donde `$wpdb->prepare` no aplica
- Prefijo de tablas: `$wpdb->prefix . 'ds_'`
- Text domain i18n: `'soccertrack'`
- Convención de días: PHP `date('w')` — 0=domingo, 1=lunes, 2=martes … 6=sábado
- `match_weekdays` en DB es JSON array de ints, ej. `[2]` para martes
- `schedule_slots` en DB es JSON array ordenado por `time`, ej. `[{"time":"19:00","max_matches":8},{"time":"20:00","max_matches":5}]`
- Retrocompatibilidad: si `schedule_slots` es `NULL`, comportamiento actual sin cambios

---

## Archivos que se tocan

| Acción | Archivo |
|--------|---------|
| Modify | `soccertrack/includes/Core/DatabaseInstaller.php` |
| Create | `soccertrack/includes/Core/SlotPacker.php` |
| Modify | `soccertrack/includes/Core/FixtureGenerator.php` |
| Modify | `soccertrack/templates/panel/torneo-detalle.php` |
| Modify | `soccertrack/includes/Public/TournamentPage.php` |

---

## Task 1: DB — columna `schedule_slots` en `ds_tournaments`

**Files:**
- Modify: `soccertrack/includes/Core/DatabaseInstaller.php`

**Interfaces:**
- Produces: columna `schedule_slots JSON NULL` disponible en `{prefix}ds_tournaments`

Contexto: El CREATE TABLE de `ds_tournaments` está en `DatabaseInstaller.php` líneas 32–50 (en `dbDelta()`). La columna se inserta después de `match_time`. `dbDelta()` agrega columnas faltantes en instalaciones existentes al activar el plugin.

- [ ] **Step 1: Leer el CREATE TABLE actual**

```bash
grep -n "match_time\|schedule_slots\|ds_tournaments" soccertrack/includes/Core/DatabaseInstaller.php | head -20
```

- [ ] **Step 2: Agregar `schedule_slots` al CREATE TABLE**

Busca la línea con `match_time_weekend TIME NOT NULL DEFAULT '10:00:00',` y agrega después:

```php
    match_time_weekend  TIME            NOT NULL DEFAULT '10:00:00',
    schedule_slots      JSON            NULL,
```

La línea a agregar es exactamente `    schedule_slots      JSON            NULL,` (con cuatro espacios de indentación, igual que las demás columnas).

- [ ] **Step 3: Verificar que dbDelta agrega la columna**

Desactiva y reactiva el plugin en WP Admin → Plugins, o ejecuta:

```bash
wp plugin deactivate soccertrack && wp plugin activate soccertrack
wp db query "DESCRIBE $(wp db prefix)ds_tournaments" | grep schedule_slots
```

Expected output: `| schedule_slots | json | YES | | NULL | |`

- [ ] **Step 4: Commit**

```bash
git add soccertrack/includes/Core/DatabaseInstaller.php
git commit -m "feat(db): agregar columna schedule_slots JSON a ds_tournaments"
```

---

## Task 2: SlotPacker — algoritmo puro de distribución

**Files:**
- Create: `soccertrack/includes/Core/SlotPacker.php`

**Interfaces:**
- Consumes: nada de tareas anteriores
- Produces:
  ```php
  SlotPacker::calculate(
      array  $match_ids,   // IDs ordenados a programar
      array  $slots,       // [['time'=>'19:00','max_matches'=>8], ...]
      int    $weekday,     // 0–6 según date('w')
      string $start_from,  // YYYY-MM-DD — primer día disponible
  ): array   // [['match_id'=>N,'date'=>'YYYY-MM-DD','time'=>'HH:MM:SS','slot_index'=>0], ...]
  ```

- [ ] **Step 1: Crear el archivo con el método `calculate()` y verificar que falla por no existir**

```bash
php -r "require 'soccertrack/includes/Core/SlotPacker.php'; echo SlotPacker::calculate([], [], 2, '2026-09-01') ? 'ok' : 'fail';" 2>&1
```
Expected: error de archivo no encontrado.

- [ ] **Step 2: Implementar `SlotPacker`**

Crea `soccertrack/includes/Core/SlotPacker.php`:

```php
<?php
/**
 * SlotPacker — distribuye partidos en bloques horarios con capacidad máxima.
 *
 * Sin dependencias de WordPress. Algoritmo puro, testeable en aislamiento.
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class SlotPacker {

	/**
	 * Calcula la asignación de fecha/hora para cada partido.
	 *
	 * @param int[]  $match_ids   IDs de partidos a programar, en orden de juego.
	 * @param array  $slots       [['time'=>'19:00','max_matches'=>8], ...] ordenado por time asc.
	 * @param int    $weekday     Día de juego según date('w'): 0=dom, 1=lun, 2=mar … 6=sáb.
	 * @param string $start_from  Fecha mínima YYYY-MM-DD desde la cual programar.
	 * @return array  [['match_id'=>N,'date'=>'YYYY-MM-DD','time'=>'HH:MM:SS','slot_index'=>0], ...]
	 */
	public static function calculate(
		array  $match_ids,
		array  $slots,
		int    $weekday,
		string $start_from,
	): array {
		if ( empty( $match_ids ) || empty( $slots ) ) {
			return [];
		}

		// Ordenar slots por hora ascendente.
		usort( $slots, static fn( array $a, array $b ) => $a['time'] <=> $b['time'] );

		$current_date = self::next_weekday_from( $start_from, $weekday );
		$slot_index   = 0;
		$used_in_slot = 0;
		$result       = [];

		foreach ( $match_ids as $match_id ) {
			// Si agotamos todos los slots del día → avanzar al próximo día disponible.
			if ( $slot_index >= count( $slots ) ) {
				$current_date = self::next_weekday_from(
					date( 'Y-m-d', strtotime( $current_date . ' +7 days' ) ),
					$weekday
				);
				$slot_index   = 0;
				$used_in_slot = 0;
			}

			$slot = $slots[ $slot_index ];

			$result[] = [
				'match_id'   => (int) $match_id,
				'date'       => $current_date,
				'time'       => self::normalize_time( $slot['time'] ),
				'slot_index' => $slot_index,
			];

			++$used_in_slot;

			if ( $used_in_slot >= (int) $slot['max_matches'] ) {
				++$slot_index;
				$used_in_slot = 0;
			}
		}

		return $result;
	}

	/**
	 * Devuelve la fecha YYYY-MM-DD del próximo $weekday >= $from.
	 *
	 * @param string $from     YYYY-MM-DD
	 * @param int    $weekday  0=dom … 6=sáb (date('w'))
	 */
	private static function next_weekday_from( string $from, int $weekday ): string {
		$ts         = strtotime( $from );
		$current_dw = (int) date( 'w', $ts );
		$days_ahead = ( $weekday - $current_dw + 7 ) % 7;
		return date( 'Y-m-d', strtotime( "+{$days_ahead} days", $ts ) );
	}

	/**
	 * Normaliza 'HH:MM' o 'HH:MM:SS' a 'HH:MM:SS'.
	 */
	private static function normalize_time( string $time ): string {
		return preg_match( '/^\d{2}:\d{2}$/', $time ) ? $time . ':00' : $time;
	}
}
```

- [ ] **Step 3: Verificar el algoritmo con un script de prueba inline**

```bash
php -r "
define('ABSPATH', '/');
require 'soccertrack/includes/Core/SlotPacker.php';

\$ids    = range(1, 14);
\$slots  = [['time'=>'19:00','max_matches'=>8], ['time'=>'20:00','max_matches'=>5]];
\$result = SlotPacker::calculate(\$ids, \$slots, 2, '2026-09-01');

foreach (\$result as \$r) {
    echo \"Partido {\$r['match_id']}: {\$r['date']} {\$r['time']}\\n\";
}
"
```

Expected output exacto:
```
Partido 1: 2026-09-02 19:00:00
Partido 2: 2026-09-02 19:00:00
Partido 3: 2026-09-02 19:00:00
Partido 4: 2026-09-02 19:00:00
Partido 5: 2026-09-02 19:00:00
Partido 6: 2026-09-02 19:00:00
Partido 7: 2026-09-02 19:00:00
Partido 8: 2026-09-02 19:00:00
Partido 9: 2026-09-02 20:00:00
Partido 10: 2026-09-02 20:00:00
Partido 11: 2026-09-02 20:00:00
Partido 12: 2026-09-02 20:00:00
Partido 13: 2026-09-02 20:00:00
Partido 14: 2026-09-09 19:00:00
```

Nota: 2026-09-01 es martes; `next_weekday_from('2026-09-01', 2)` devuelve '2026-09-01'. El desborde va al siguiente martes 2026-09-09.

- [ ] **Step 4: Verificar caso borde — start_from no es martes**

```bash
php -r "
define('ABSPATH', '/');
require 'soccertrack/includes/Core/SlotPacker.php';

\$ids    = range(1, 2);
\$slots  = [['time'=>'19:00','max_matches'=>1]];
// Miércoles 2026-09-02 → siguiente martes = 2026-09-08
\$result = SlotPacker::calculate(\$ids, \$slots, 2, '2026-09-02');
echo \$result[0]['date'] . '\\n'; // esperado: 2026-09-08
echo \$result[1]['date'] . '\\n'; // esperado: 2026-09-15
"
```

Expected:
```
2026-09-08
2026-09-15
```

- [ ] **Step 5: Commit**

```bash
git add soccertrack/includes/Core/SlotPacker.php
git commit -m "feat(core): SlotPacker — distribución de partidos en bloques horarios"
```

---

## Task 3: UI + handler — configurar schedule_slots desde el panel

**Files:**
- Modify: `soccertrack/templates/panel/torneo-detalle.php` (sección "Hora de inicio", ~líneas 248–286)
- Modify: `soccertrack/includes/Public/TournamentPage.php` (handler POST match_time, ~líneas 986–1014)

**Interfaces:**
- Consumes: columna `schedule_slots` en DB (Task 1)
- Produces: `$tournament['schedule_slots']` disponible como JSON string para Task 4

Contexto del handler existente (TournamentPage.php ~línea 1002–1014):
```php
$raw_time       = sanitize_text_field( $_POST['match_time'] ?? '19:00' );
$match_time     = preg_match( '/^\d{1,2}:\d{2}$/', $raw_time ) ? $raw_time . ':00' : '19:00:00';
$match_duration = max( 30, min( 120, (int) ( $_POST['match_duration'] ?? 60 ) ) );

$wpdb->update(
    "{$wpdb->prefix}ds_tournaments",
    [
        'match_weekday'  => $match_weekdays[0],
        'match_weekdays' => $match_weekdays_json,
        'match_time'     => $match_time,
        'match_duration' => $match_duration,
    ],
    [ 'id' => $id ],
    ...
);
```

- [ ] **Step 1: Leer las líneas exactas de la sección "Hora de inicio" en torneo-detalle.php**

```bash
grep -n "Hora de inicio\|match_time\|schedule_slot\|Agregar bloque" \
  soccertrack/templates/panel/torneo-detalle.php | head -20
```

- [ ] **Step 2: Agregar bloque UI de slots en `torneo-detalle.php`**

Justo después del cierre del bloque `match_time` (el `</div>` que cierra el div de "Hora de inicio" y "Duración"), agrega:

```php
<?php
// Leer slots existentes para pre-popular la UI.
$existing_slots = [];
if ( ! empty( $tournament['schedule_slots'] ) ) {
    $decoded = json_decode( $tournament['schedule_slots'], true );
    if ( is_array( $decoded ) ) {
        $existing_slots = $decoded;
    }
}
?>
<div style="margin-bottom:20px">
    <label class="st-label" style="display:block;margin-bottom:8px">
        <?php esc_html_e( 'Bloques horarios', 'soccertrack' ); ?>
        <span style="font-size:.75rem;color:#999;font-weight:400;cursor:default"
              title="<?php esc_attr_e( 'Define cuántos partidos simultáneos se juegan en cada horario. Si no configuras bloques, se usa la "Hora de inicio" para todos los partidos.', 'soccertrack' ); ?>"> ℹ</span>
    </label>
    <div id="st-slots-container">
        <?php if ( ! empty( $existing_slots ) ) : ?>
            <?php foreach ( $existing_slots as $slot ) : ?>
            <div class="st-slot-row" style="display:flex;align-items:center;gap:10px;margin-bottom:8px">
                <input type="time" name="slot_time[]" class="st-input" value="<?php echo esc_attr( $slot['time'] ); ?>" style="max-width:110px" required>
                <span style="color:#555;font-size:.85rem">×</span>
                <input type="number" name="slot_count[]" class="st-input" value="<?php echo esc_attr( (string) $slot['max_matches'] ); ?>" min="1" max="50" style="max-width:70px;text-align:center" required>
                <span style="color:#555;font-size:.85rem"><?php esc_html_e( 'partidos', 'soccertrack' ); ?></span>
                <button type="button" class="st-btn st-btn--sm" onclick="this.closest('.st-slot-row').remove()"
                        style="color:#d63638;border-color:#d63638;padding:2px 8px">−</button>
            </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
    <button type="button" id="st-add-slot-btn" class="st-btn st-btn--sm" style="margin-top:4px">
        + <?php esc_html_e( 'Agregar bloque', 'soccertrack' ); ?>
    </button>
</div>
<script>
document.getElementById('st-add-slot-btn').addEventListener('click', function() {
    var row = document.createElement('div');
    row.className = 'st-slot-row';
    row.style.cssText = 'display:flex;align-items:center;gap:10px;margin-bottom:8px';
    row.innerHTML = '<input type="time" name="slot_time[]" class="st-input" style="max-width:110px" required>'
        + '<span style="color:#555;font-size:.85rem">×</span>'
        + '<input type="number" name="slot_count[]" class="st-input" value="1" min="1" max="50" style="max-width:70px;text-align:center" required>'
        + '<span style="color:#555;font-size:.85rem"><?php esc_js( esc_html__( 'partidos', 'soccertrack' ) ); ?></span>'
        + '<button type="button" class="st-btn st-btn--sm" onclick="this.closest(\'.st-slot-row\').remove()" style="color:#d63638;border-color:#d63638;padding:2px 8px">−</button>';
    document.getElementById('st-slots-container').appendChild(row);
});
</script>
```

- [ ] **Step 3: Agregar lectura y guardado de `schedule_slots` en `TournamentPage.php`**

Justo después de la línea que asigna `$match_duration` (~línea 1004), agrega:

```php
// Bloques horarios (schedule_slots).
$schedule_slots     = [];
$raw_slot_times     = isset( $_POST['slot_time'] ) && is_array( $_POST['slot_time'] )
    ? $_POST['slot_time']  // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
    : [];
$raw_slot_counts    = isset( $_POST['slot_count'] ) && is_array( $_POST['slot_count'] )
    ? $_POST['slot_count'] // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
    : [];

foreach ( $raw_slot_times as $i => $raw_slot_time ) {
    $t = sanitize_text_field( $raw_slot_time );
    if ( ! preg_match( '/^\d{2}:\d{2}$/', $t ) ) {
        continue;
    }
    $max = max( 1, min( 50, (int) ( $raw_slot_counts[ $i ] ?? 1 ) ) );
    $schedule_slots[] = [ 'time' => $t, 'max_matches' => $max ];
}
usort( $schedule_slots, static fn( array $a, array $b ) => $a['time'] <=> $b['time'] );
$schedule_slots_json = empty( $schedule_slots ) ? null : wp_json_encode( $schedule_slots );
```

Luego en el `$wpdb->update(...)`, agrega `'schedule_slots' => $schedule_slots_json` al array de datos y `'%s'` al array de formatos:

```php
$wpdb->update(
    "{$wpdb->prefix}ds_tournaments",
    [
        'match_weekday'  => $match_weekdays[0],
        'match_weekdays' => $match_weekdays_json,
        'match_time'     => $match_time,
        'match_duration' => $match_duration,
        'schedule_slots' => $schedule_slots_json,   // ← agregar esta línea
    ],
    [ 'id' => $id ],
    [ '%d', '%s', '%s', '%d', '%s' ],               // ← agregar '%s' al final
    [ '%d' ]
);
```

- [ ] **Step 4: Verificar en el panel**

1. Ir a `/panel/torneo/18/` → sección de configuración de fixture
2. Confirmar que aparece el bloque "Bloques horarios" con botón "+ Agregar bloque"
3. Agregar dos bloques: `19:00 × 8` y `20:00 × 5`
4. Guardar y recargar la página — confirmar que los bloques persisten

```bash
wp db query "SELECT schedule_slots FROM $(wp db prefix)ds_tournaments WHERE id=18"
```
Expected: `[{"time":"19:00","max_matches":8},{"time":"20:00","max_matches":5}]`

- [ ] **Step 5: Commit**

```bash
git add soccertrack/templates/panel/torneo-detalle.php \
        soccertrack/includes/Public/TournamentPage.php
git commit -m "feat(panel): UI y handler para schedule_slots en ficha del torneo"
```

---

## Task 4: FixtureGenerator — integrar SlotPacker

**Files:**
- Modify: `soccertrack/includes/Core/FixtureGenerator.php`

**Interfaces:**
- Consumes:
  - `SlotPacker::calculate(array $match_ids, array $slots, int $weekday, string $start_from): array` (Task 2)
  - `$tournament['schedule_slots']` como JSON string (Task 3)
- Produces: `assign_courts()` usa SlotPacker cuando `schedule_slots` no es null

Contexto: `assign_courts(array $match_ids, int $venue_id): void` está en línea 492. Es llamado desde `generate()` (línea 398) y `generate_swiss_round()` (línea 1489), entre otros. Los dos call sites más importantes para este feature son esos dos. El resto (playoffs, group_stage) continúan con el comportamiento existente.

- [ ] **Step 1: Leer las líneas exactas de `assign_courts()` y los dos call sites**

```bash
sed -n '492,536p' soccertrack/includes/Core/FixtureGenerator.php
sed -n '395,400p' soccertrack/includes/Core/FixtureGenerator.php   # generate()
sed -n '1486,1492p' soccertrack/includes/Core/FixtureGenerator.php # generate_swiss_round()
```

- [ ] **Step 2: Modificar la firma de `assign_courts()` para aceptar tournament opcional**

Cambia la firma en línea 492 de:
```php
public function assign_courts( array $match_ids, int $venue_id ): void {
```
a:
```php
public function assign_courts( array $match_ids, int $venue_id, ?array $tournament = null ): void {
```

- [ ] **Step 3: Agregar la rama SlotPacker al inicio de `assign_courts()`**

Justo después de `if ( empty( $match_ids ) ) { return; }`, agrega:

```php
// Si el torneo tiene bloques horarios configurados → usar SlotPacker.
if ( $tournament !== null && ! empty( $tournament['schedule_slots'] ) ) {
    $slots = json_decode( $tournament['schedule_slots'], true );
    if ( is_array( $slots ) && ! empty( $slots ) ) {
        $weekdays   = json_decode( $tournament['match_weekdays'] ?? '[]', true );
        $weekday    = is_array( $weekdays ) && ! empty( $weekdays )
            ? (int) $weekdays[0]
            : (int) ( $tournament['match_weekday'] ?? 6 );
        $start_from = $this->next_slot_start( (int) $tournament['id'], (string) ( $tournament['start_date'] ?? gmdate( 'Y-m-d' ) ) );

        $schedule = SlotPacker::calculate( $match_ids, $slots, $weekday, $start_from );

        foreach ( $schedule as $entry ) {
            $wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
                "{$wpdb->prefix}ds_matches",
                [ 'match_datetime' => $entry['date'] . ' ' . $entry['time'] ],
                [ 'id' => $entry['match_id'] ],
                [ '%s' ],
                [ '%d' ]
            );
        }

        // Asignar canchas en rotación circular (lógica existente — necesita court_ids).
        $court_ids = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $wpdb->prepare(
                "SELECT id FROM {$wpdb->prefix}ds_courts WHERE venue_id = %d ORDER BY id ASC",
                $venue_id
            )
        );
        if ( ! empty( $court_ids ) ) {
            $count = count( $court_ids );
            foreach ( $match_ids as $i => $match_id ) {
                $wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
                    "{$wpdb->prefix}ds_matches",
                    [ 'court_id' => (int) $court_ids[ $i % $count ] ],
                    [ 'id' => (int) $match_id ],
                    [ '%d' ],
                    [ '%d' ]
                );
            }
        }

        return;
    }
}
```

- [ ] **Step 4: Agregar método privado `next_slot_start()`**

Agrega al final de la clase, antes del cierre `}`:

```php
/**
 * Calcula la fecha mínima desde la que el SlotPacker debe programar partidos.
 *
 * Si ya hay partidos en el torneo → MAX(match_datetime) + 7 días.
 * Si no hay partidos → start_date del torneo.
 */
private function next_slot_start( int $tournament_id, string $start_date ): string {
    global $wpdb;

    $max = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $wpdb->prepare(
            "SELECT MAX(match_datetime) FROM {$wpdb->prefix}ds_matches WHERE tournament_id = %d AND phase = 'regular'",
            $tournament_id
        )
    );

    if ( $max ) {
        return date( 'Y-m-d', strtotime( $max . ' +7 days' ) );
    }

    return $start_date;
}
```

- [ ] **Step 5: Pasar `$tournament` desde `generate()` y `generate_swiss_round()`**

En `generate()` línea 398, cambia:
```php
$this->assign_courts( $match_ids, $venue_id );
```
a:
```php
$this->assign_courts( $match_ids, $venue_id, $tournament );
```

En `generate_swiss_round()` línea 1489, cambia:
```php
$this->assign_courts( $match_ids, $venue_id );
```
a:
```php
$this->assign_courts( $match_ids, $venue_id, $tournament );
```

- [ ] **Step 6: Verificar que los torneos existentes sin `schedule_slots` no se rompen**

```bash
# El torneo 17 (round-robin, sin schedule_slots) debe seguir funcionando.
wp db query "SELECT id, match_datetime, court_id FROM $(wp db prefix)ds_matches WHERE tournament_id=17 LIMIT 5"
```
Expected: fechas y court_id existentes sin cambios.

- [ ] **Step 7: Prueba end-to-end con torneo 18**

Genera manualmente una ronda extra vía el panel o WP-CLI y verifica la distribución:

```bash
wp db query "
SELECT match_datetime, court_id, COUNT(*) as count
FROM $(wp db prefix)ds_matches
WHERE tournament_id = 18 AND phase = 'regular'
GROUP BY match_datetime
ORDER BY match_datetime
LIMIT 20
"
```

Para una ronda con schedule_slots configurado debe verse:
- Hasta 8 partidos en el mismo datetime `YYYY-MM-DD 19:00:00`
- Hasta 5 partidos en `YYYY-MM-DD 20:00:00`
- Si hay partido 14, en la fecha siguiente a las `19:00:00`

- [ ] **Step 8: Commit**

```bash
git add soccertrack/includes/Core/FixtureGenerator.php
git commit -m "feat(fixture): integrar SlotPacker en assign_courts para torneos con bloques horarios"
```

---

## Self-Review

### Spec coverage

| Requisito del spec | Tarea |
|--------------------|-------|
| `schedule_slots JSON NULL` en `ds_tournaments` | Task 1 ✓ |
| Retrocompatibilidad — NULL → comportamiento actual | Task 4 (rama if) ✓ |
| `SlotPacker::calculate()` con algoritmo correcto | Task 2 ✓ |
| Borde: start_from no es el día correcto | Task 2 Step 4 ✓ |
| UI bloques en torneo-detalle.php | Task 3 Step 2 ✓ |
| Handler guarda schedule_slots JSON | Task 3 Step 3 ✓ |
| FixtureGenerator pasa tournament a assign_courts | Task 4 Steps 5 ✓ |
| next_slot_start: MAX(match_datetime)+7d o start_date | Task 4 Step 4 ✓ |
| Aplica a round-robin (generate) y Swiss | Task 4 Step 5 ✓ |

### Sin placeholders — verificado ✓
### Tipos consistentes — `calculate()` firma igual en Task 2 y Task 4 ✓
