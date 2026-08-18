# Schedule Slots — Diseño

> Distribución automática de partidos en bloques horarios con capacidad por slot

## Contexto

SoccerTrack actualmente asigna una única hora (`match_time`) a todos los partidos de una ronda y distribuye canchas en rotación circular. Esto no soporta recintos donde distintos bloques horarios tienen distinta cantidad de canchas disponibles.

Caso concreto: torneo Swiss 28 equipos, recinto disponible solo los martes con 8 canchas a las 19:00 y 5 canchas a las 20:00 (13 slots/martes). Con 14 partidos por ronda, 1 partido desborda al siguiente martes.

---

## Goal

Extender el fixture generator para que distribuya automáticamente los partidos de cada ronda en bloques horarios con capacidad máxima por slot, avanzando al siguiente día disponible cuando todos los slots del día están llenos.

---

## Constraints

- PHP 8.2, WordPress Coding Standards
- Sin cambios en el modelo de `ds_matches` (ya tiene `match_datetime` y `court_id`)
- Compatible hacia atrás: torneos existentes sin `schedule_slots` usan el comportamiento actual
- Aplica a todos los formatos (round-robin, Swiss, group_stage, knockout)
- El `AntiCollisionEngine` existente sigue siendo la red de seguridad contra colisiones

---

## Modelo de datos

### `ds_tournaments` — nuevo campo

```sql
ALTER TABLE {prefix}ds_tournaments
ADD COLUMN schedule_slots JSON NULL
COMMENT 'Bloques horarios ordenados: [{"time":"19:00","max_matches":8},{"time":"20:00","max_matches":5}]'
AFTER match_time;
```

- `time` (string `HH:MM`) — hora de inicio del bloque
- `max_matches` (int ≥ 1) — partidos simultáneos máximos en ese bloque (= canchas disponibles)
- El array se ordena ascendente por `time` antes de procesar
- Si `NULL` o vacío: comportamiento heredado (`match_time` único, sin límite de capacidad)
- El campo `match_time` existente se mantiene para torneos anteriores (no se elimina)

### No cambia

- `ds_matches`: `match_datetime` (DATETIME) y `court_id` (BIGINT) ya soportan el resultado
- `ds_courts`, `ds_venues`: sin cambios
- `AntiCollisionEngine`: sin cambios

---

## Componentes

### `SlotPacker` (nueva clase)

**Archivo:** `soccertrack/includes/Core/SlotPacker.php`

**Responsabilidad única:** dado un conjunto de match IDs y la configuración de slots, asignar `match_datetime` y `court_id` a cada partido.

**Interfaz pública:**

```php
final class SlotPacker {
    /**
     * @param int[]  $match_ids   IDs de los partidos a programar (en orden)
     * @param array  $slots       [['time'=>'19:00','max_matches'=>8], ...]
     * @param string $weekday     'monday'|'tuesday'|...'sunday'
     * @param string $start_from  Fecha mínima ISO (YYYY-MM-DD); el packer busca
     *                            el primer $weekday >= $start_from
     * @param int[]  $court_ids   IDs de canchas del recinto (rotación circular)
     * @return array  Resumen: [['date'=>'2026-09-02','slots'=>[['19:00'=>8],['20:00'=>5]]],...]
     */
    public static function pack(
        array  $match_ids,
        array  $slots,
        string $weekday,
        string $start_from,
        array  $court_ids,
    ): array;
}
```

**Algoritmo:**

```
1. Ordenar $slots por 'time' ascendente
2. current_date = primer $weekday >= $start_from
3. slot_index = 0, used_in_slot = 0

Para cada $match_id:
  a. Si slot_index >= count($slots):
       current_date = current_date + 7 días
       slot_index = 0
       used_in_slot = 0
  b. slot = $slots[slot_index]
  c. match_datetime = current_date + ' ' + slot['time'] + ':00'
  d. court_id = $court_ids[global_match_index % count($court_ids)]
  e. UPDATE ds_matches SET match_datetime, court_id WHERE id = $match_id
  f. used_in_slot++
  g. Si used_in_slot >= slot['max_matches']:
       slot_index++
       used_in_slot = 0
```

**Ejemplo — 14 partidos, martes, slots [{19:00,8},{20:00,5}], start_from 2026-09-01:**

| Partidos | Fecha | Hora |
|----------|-------|------|
| 1–8 | Mar 02/09/2026 | 19:00 |
| 9–13 | Mar 02/09/2026 | 20:00 |
| 14 | Mar 09/09/2026 | 19:00 |

### Modificaciones a `FixtureGenerator`

**Archivo:** `soccertrack/includes/Core/FixtureGenerator.php`

- El método `assign_courts()` actual se reemplaza internamente por `SlotPacker::pack()` cuando el torneo tiene `schedule_slots` configurado
- Si `schedule_slots` es NULL: comportamiento actual sin cambios
- `start_from` se calcula como `MAX(match_datetime)` de los partidos existentes del torneo + 7 días; si no hay partidos previos, se usa `start_date` del torneo
- Los `court_ids` se obtienen igual que hoy: canchas del recinto asignado al torneo

### Modificaciones al formulario de torneos

**Archivo:** `soccertrack/templates/panel/torneos.php`

Nuevo bloque en el formulario de creación (visible para todos los formatos):

```
Día de juego: [select: lunes/martes/.../domingo]

Bloques horarios:
  [19:00] × [8] partidos   [−]
  [20:00] × [5] partidos   [−]
  [+ Agregar bloque]
```

- El botón [+ Agregar bloque] añade una nueva fila de inputs vía JS
- El botón [−] elimina la fila correspondiente
- Si no se agrega ningún bloque, se muestra el campo `match_time` heredado (retrocompatibilidad)
- Al submit, los bloques se serializan como JSON y se envían en `schedule_slots`

**En `TournamentPage.php` (handler del form):**

```php
$schedule_slots = [];
$slot_times  = $_POST['slot_time']  ?? [];   // array
$slot_counts = $_POST['slot_count'] ?? [];   // array

foreach ( $slot_times as $i => $time ) {
    $max = max( 1, (int) ( $slot_counts[ $i ] ?? 1 ) );
    if ( preg_match( '/^\d{2}:\d{2}$/', $time ) ) {
        $schedule_slots[] = [ 'time' => $time, 'max_matches' => $max ];
    }
}
usort( $schedule_slots, fn( $a, $b ) => $a['time'] <=> $b['time'] );

// INSERT: schedule_slots = empty($schedule_slots) ? null : wp_json_encode($schedule_slots)
```

### Migration / dbDelta

En `DatabaseInstaller.php`, agregar `schedule_slots JSON NULL` al `CREATE TABLE` de `ds_tournaments`. `dbDelta()` en activación/actualización añade la columna a instalaciones existentes.

---

## Flujo completo

```
Coordinador crea torneo
  → define día (martes) + bloques (19:00×8, 20:00×5)
  → schedule_slots guardado en ds_tournaments

Coordinador genera Ronda N
  → FixtureGenerator crea pares de equipos (sin fechas)
  → FixtureGenerator detecta schedule_slots != null
  → SlotPacker::pack($match_ids, $slots, $weekday, $start_from, $court_ids)
  → partidos actualizados con match_datetime + court_id
  → AntiCollisionEngine valida (red de seguridad)

Coordinador ve fixture
  → partidos agrupados por fecha/hora como hoy (sin cambios en la vista)
```

---

## Compatibilidad hacia atrás

| Torneo | schedule_slots | Comportamiento |
|--------|---------------|----------------|
| Existente (torneo 17, 18) | NULL | `match_time` único, rotación circular actual |
| Nuevo con bloques | JSON array | SlotPacker distribuye en slots |
| Nuevo sin bloques | NULL | igual que torneo existente |

---

## Lo que NO incluye este spec

- UI para editar los slots de un torneo ya creado (puede agregarse después)
- Reagendamiento masivo de partidos existentes
- Soporte para múltiples recintos con slots distintos en el mismo torneo
- Validación de disponibilidad de árbitros por slot
