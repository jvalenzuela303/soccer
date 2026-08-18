# Formato Swiss — Diseño Técnico

**Fecha:** 2026-08-18
**Feature:** Nuevo formato `swiss` para torneos tipo "Champions" con fase liga parcial y playoffs multi-bracket
**Estado:** Aprobado

---

## Contexto y motivación

El sistema soporta torneos de 28 equipos donde todos los equipos compiten en una **fase liga** única (sin subgrupos), cada equipo juega ~8 partidos determinados por sorteo ponderado por clasificación, y al terminar los 24 primeros clasifican a 3 brackets de playoffs (Oro, Plata, Bronce) de 8 equipos cada uno.

Este formato no es viable con los formatos existentes:
- `round_robin` genera todos contra todos (378 partidos para 28 equipos).
- `group_stage` divide equipos en subgrupos, pierde la tabla general única.
- `knockout` es eliminación directa.

El formato **Swiss** resuelve esto: genera rondas donde cada equipo enfrenta a otro de clasificación similar, ningún par se repite, y la cantidad de rondas es configurable.

---

## Decisiones de diseño

| Pregunta | Decisión |
|---|---|
| ¿Cómo genera el coordinador la siguiente ronda? | Híbrido: botón con confirmación aparece automáticamente cuando todos los resultados de la ronda actual están ingresados |
| ¿Qué pasa con número impar de equipos? | Equipo fantasma `"LIBRE"` (`is_ghost=1`) visible en fixture como "Descanso", sin efecto en tabla de posiciones |
| ¿Rondas fijas o configurables? | Configurables por torneo via columna `swiss_rounds` (default 8) |
| ¿Criterio de emparejamiento dentro del mismo grupo de puntos? | Diferencia de goles (DG) DESC |

---

## Sección 1: Schema de base de datos

**Versión de migración:** `v2.3.0`

### 1.1 Extender ENUM `format` en `ds_tournaments`

```sql
ALTER TABLE ds_tournaments
  MODIFY COLUMN format
    ENUM('round_robin','round_robin_playoffs','group_stage','knockout','swiss')
    NOT NULL DEFAULT 'round_robin';
```

### 1.2 Nueva columna `swiss_rounds` en `ds_tournaments`

```sql
ALTER TABLE ds_tournaments
  ADD COLUMN swiss_rounds TINYINT UNSIGNED NOT NULL DEFAULT 8
  AFTER format;
```

Solo se usa cuando `format = 'swiss'`. Rango válido: 1–20. El formulario de creación/edición de torneo expone este campo únicamente cuando se selecciona el formato Swiss.

### 1.3 Nueva columna `is_ghost` en `ds_teams`

```sql
ALTER TABLE ds_teams
  ADD COLUMN is_ghost TINYINT(1) NOT NULL DEFAULT 0;
```

Cuando un torneo Swiss tiene número impar de equipos al generar la primera ronda, el sistema crea automáticamente un equipo `"LIBRE"` con `is_ghost = 1`. Este equipo nunca aparece en la tabla de posiciones.

**No se crean tablas nuevas.** El historial de emparejamientos se consulta desde `ds_matches` existente.

### 1.4 Idempotencia de migración

Ambas columnas usan guard `SHOW COLUMNS FROM ... LIKE '...'` antes del `ALTER TABLE`, patrón existente en `DatabaseInstaller::apply_index_migrations()`.

---

## Sección 2: Algoritmo de emparejamiento Swiss

Implementado en `FixtureGenerator::generate_swiss_round()`.

### Paso 1 — Standings actuales

```php
$standings = (new StandingsCalculator())->recalculate($tournament_id);
// Orden: pts DESC, dg DESC, gf DESC
// Equipos con is_ghost=1 excluidos por StandingsCalculator
```

### Paso 2 — Historial de pares jugados

```sql
SELECT home_team_id, away_team_id
FROM ds_matches
WHERE tournament_id = %d AND phase = 'regular' AND status != 'suspended'
```

Construye un `Set` de claves `"min(a,b):max(a,b)"` para lookup O(1).

### Paso 3 — Agrupamiento por puntos (buckets)

```
bucket[pts] = [teams ordenados por DG DESC, GF DESC]
```

### Paso 4 — Emparejamiento greedy top-down con backtracking

```
unpaired = copia de standings (sin fantasma)
pairs    = []

para cada equipo A en unpaired (de mayor a menor pts):
    si A ya tiene pareja: continuar
    para cada equipo B en unpaired[siguiente..] ordenado por |pts_A - pts_B| ASC, DG DESC:
        si (A, B) no están en historial:
            pairs.add((A, B))
            marcar A y B como emparejados
            break
    si A no encontró pareja: queda pendiente para Paso 5
```

Backtracking simple: si un equipo queda sin pareja por colisión de historial, intenta reasignar el par anterior para liberar un candidato. Máximo 1 nivel de backtracking (suficiente para 28 equipos con 8 rondas).

### Paso 5 — Manejo de número impar / Bye

Si queda 1 equipo sin pareja al final:
1. Buscar el equipo de menor ranking que **no haya tenido Bye** en rondas anteriores.
2. Si todos tuvieron Bye: asignar al de menor ranking esta ronda.
3. El equipo seleccionado se empareja con el equipo `LIBRE` (`is_ghost=1`).
4. El partido vs LIBRE se inserta con `status = 'finished'`, `home_score = 0`, `away_score = 0` (no suma puntos).

Historial de Byes: se deriva consultando partidos donde `away_team_id = ghost_team_id`.

### Paso 6 — Inserción de partidos

Por cada par `(A, B)`:
- `tournament_id`, `round_number = N`, `phase = 'regular'`
- `home_team_id`, `away_team_id` — alternancia local/visitante respetando historial previo
- Fecha y cancha: misma lógica de `next_match_datetime()` y asignación rotativa de canchas existente
- `status = 'scheduled'`

### Guardia de idempotencia

```sql
SELECT COUNT(*) FROM ds_matches
WHERE tournament_id = %d AND round_number = %d AND phase = 'regular'
```
Si > 0: retorna `['match_ids' => [], 'error' => 'La ronda N ya fue generada.']`

---

## Sección 3: Componentes PHP

### 3.1 `FixtureGenerator` — métodos nuevos

```php
/**
 * Genera los emparejamientos de la ronda N del formato Swiss.
 *
 * @param array $tournament  Fila de ds_tournaments (incluye swiss_rounds).
 * @param int   $round_number  Número de ronda 1-based.
 * @param int   $venue_id
 * @param string|null $match_date  Fecha override 'Y-m-d'; si null usa next_match_datetime().
 * @return array{ match_ids: int[], error?: string }
 */
public function generate_swiss_round(
    array $tournament,
    int $round_number,
    int $venue_id,
    ?string $match_date = null
): array

/**
 * Retorna el estado completo de la fase Swiss de un torneo.
 *
 * @return array{
 *   current_round: int,       // última ronda generada (0 si ninguna)
 *   total_rounds: int,        // swiss_rounds configuradas
 *   round_complete: bool,     // todos los partidos de current_round finalizados
 *   swiss_done: bool,         // current_round === total_rounds && round_complete
 *   bye_history: int[],       // team_ids que ya tuvieron Bye
 * }
 */
public function get_swiss_status(int $tournament_id): array
```

### 3.2 `StandingsCalculator` — cambio mínimo

El método `recalculate()` agrega filtro para excluir equipos fantasma:

```sql
WHERE tournament_id = %d
  AND status = 'finished'
  AND home_team_id NOT IN (
      SELECT id FROM {prefix}ds_teams WHERE tournament_id = %d AND is_ghost = 1
  )
  AND away_team_id NOT IN (
      SELECT id FROM {prefix}ds_teams WHERE tournament_id = %d AND is_ghost = 1
  )
```

La lista de equipos también filtra `is_ghost = 0` para no mostrar LIBRE en la tabla.

### 3.3 `AdminEndpoints` — endpoint nuevo

```
POST /wp-json/soccertrack/v1/torneo/{id}/swiss-round
```

- Requiere capability `ds_manage_tournaments`
- Body JSON: `{ venue_id: int, match_date?: string }`
- Lógica:
  1. Leer torneo, validar `format = 'swiss'`
  2. Llamar `get_swiss_status()` — si `round_complete = false` y `current_round > 0`: error `'La ronda actual aún no está completa.'`
  3. Si `swiss_done = true`: error `'La fase liga ya está completa. Activa los playoffs.'`
  4. `next_round = current_round + 1`
  5. Llamar `generate_swiss_round($tournament, $next_round, $venue_id, $match_date)`
  6. Retornar `{ round: int, match_ids: int[], error?: string }`

### 3.4 `DatabaseInstaller` — migración v2.3.0

```php
// En run() — bump versión:
define('SOCCERTRACK_DB_VERSION', '2.3.0');

// En apply_index_migrations() — nuevas migraciones:
// 1. swiss_rounds en ds_tournaments
// 2. is_ghost en ds_teams
// 3. Extender ENUM format en ds_tournaments
```

Cada migración con guard de existencia (SHOW COLUMNS / SHOW CREATE TABLE) para idempotencia.

---

## Sección 4: UI del panel de administración

### 4.1 Bloque "Fase Liga Swiss" en `torneo-detalle.php`

Se muestra solo cuando `format = 'swiss'`. Cuatro estados:

| Estado | Condición | UI |
|---|---|---|
| **Sin inicio** | `current_round = 0` | Botón "Generar Ronda 1" |
| **Ronda en curso** | `round_complete = false` | Lista de partidos de la ronda actual. Sin botón de avance. |
| **Ronda completa** | `round_complete = true && !swiss_done` | Banner verde + botón **"Generar Ronda N+1"** |
| **Swiss finalizado** | `swiss_done = true` | Banner azul + botón **"Activar Playoffs"** |

### 4.2 Botón "Generar Ronda N+1"

```js
// Confirmación antes de llamar el endpoint
confirm(`¿Generar ronda ${nextRound} de ${totalRounds}? Los emparejamientos se
calcularán según la tabla actual.`)
// POST /swiss-round → reload panel
```

### 4.3 Botón "Activar Playoffs"

Solo visible cuando `swiss_done = true`. Reutiliza el flujo existente de `ds_playoff_brackets`:
- El coordinador configura los 3 brackets (Oro: 1-8, Plata: 9-16, Bronce: 17-24)
- Cada bracket de 8 equipos usa el flujo existente QF → SF → Final

### 4.4 Fixture público (`tournament-page.php` / `live-standings.js`)

- Rondas Swiss se muestran como **"Fecha 1"**, **"Fecha 2"**… (igual que round-robin)
- Partidos donde `away_team` tiene `is_ghost = 1` se muestran como **"LIBRE (Descanso)"**
- Tabla de posiciones filtra automáticamente equipos fantasma (via StandingsCalculator)

---

## Flujo completo de un torneo tipo Champions

```
1. Coordinador crea torneo: format=swiss, swiss_rounds=8, 28 equipos
2. Carga equipos via importador masivo (sin cambios)
3. Click "Generar Ronda 1" → sistema empareja por standings (todos en 0 pts → aleatorio por DG)
4. Árbitros ingresan resultados de los 14 partidos
5. Sistema detecta ronda completa → muestra botón "Generar Ronda 2"
6. Coordinador confirma → sistema recalcula standings y empareja ronda 2
7. ... repite 8 veces ...
8. Al completar ronda 8 → botón "Activar Playoffs"
9. Coordinador configura brackets: Oro(1-8), Plata(9-16), Bronce(17-24)
10. Sistema genera QF/SF/Final para cada copa con flujo existente
```

---

## Archivos afectados

| Archivo | Cambio |
|---|---|
| `includes/Core/DatabaseInstaller.php` | Migración v2.3.0: 3 ALTER TABLE |
| `includes/Core/FixtureGenerator.php` | +2 métodos: `generate_swiss_round()`, `get_swiss_status()` |
| `includes/Core/StandingsCalculator.php` | Filtrar `is_ghost=1` en `recalculate()` |
| `includes/RestApi/AdminEndpoints.php` | +1 endpoint: `POST /swiss-round` |
| `templates/panel/torneo-detalle.php` | Bloque UI Swiss con 4 estados |
| `soccertrack.php` | Bump `SOCCERTRACK_DB_VERSION` a `2.3.0` |
| `assets/js/live-standings.js` | Renderizar "LIBRE (Descanso)" para equipos fantasma |

---

## Lo que NO cambia

- `SpreadsheetImporter` — sin cambios (importa equipos igual)
- `AntiCollisionEngine` — sin cambios
- `ds_playoff_brackets` — sin cambios (los 3 brackets usan flujo existente)
- Formatos existentes (`round_robin`, `group_stage`, etc.) — sin cambios
