# Diseño: Formato Fase de Grupos (`group_stage`)

**Fecha:** 2026-08-12
**Estado:** Aprobado
**Contexto:** Implementar el formato `group_stage` — equipos divididos en grupos con round-robin interno, seguido de una fase eliminatoria (cuartos/semis/final) con los mejores clasificados de cada grupo.

---

## Decisiones de diseño

| Decisión | Elección | Razón |
|---|---|---|
| Asignación de equipos a grupos | Aleatoria (Fisher-Yates al generar fixture) | Más justo y simple; el coordinador no necesita configurar manualmente |
| Número de grupos | Configurable por torneo | Soporta 2, 3, 4… grupos según número de equipos inscrito |
| Equipos que clasifican por grupo | Configurable por torneo | Permite top-1 o top-2 según el diseño del torneo |
| Cruce eliminatorio | Sorteo aleatorio de clasificados | Simple; no depende de qué grupo viene cada equipo |
| Partido por 3.er puesto | Configurable por torneo (checkbox) | Algunos torneos no lo incluyen |
| Modelo de almacenamiento | Opción B: `group_label` en teams + matches + columnas config en tournaments | Sin tabla nueva; dbDelta seguro; sigue patrón de `bracket_id` existente |
| Compatibilidad hacia atrás | Total — `group_label = NULL` en torneos sin fase de grupos | Torneos existentes no se ven afectados |

---

## Sección 1: Modelo de Datos

### Columnas nuevas en `wp_ds_tournaments` (vía dbDelta)

```sql
group_count               TINYINT UNSIGNED NOT NULL DEFAULT 2
teams_advancing_per_group TINYINT UNSIGNED NOT NULL DEFAULT 2
has_third_place           TINYINT(1)       NOT NULL DEFAULT 1
```

- `group_count`: cuántos grupos tiene el torneo (mínimo 2, máximo 8)
- `teams_advancing_per_group`: cuántos equipos de cada grupo clasifican a eliminatoria (mínimo 1, máximo 4)
- `has_third_place`: si se juega partido por 3.er puesto en la eliminatoria (1 = sí, 0 = no)
- Solo relevantes cuando `format = 'group_stage'`; ignorados para otros formatos

### Columna nueva en `wp_ds_teams` (vía dbDelta)

```sql
group_label VARCHAR(5) NULL DEFAULT NULL
```

- Asignado al momento de generar el fixture (`'A'`, `'B'`, `'C'`, …)
- `NULL` para torneos sin fase de grupos
- Se sobreescribe si se regenera el fixture (solo permitido si no hay partidos generados)

### Columna nueva en `wp_ds_matches` (vía dbDelta)

```sql
group_label VARCHAR(5) NULL DEFAULT NULL
```

- Copia del grupo de los equipos que juegan ese partido
- `NULL` para partidos de eliminatoria (quarterfinal, semifinal, final, third_place)
- Permite filtrar partidos de un grupo sin JOIN a `ds_teams`

### Ampliación del ENUM `phase` en `wp_ds_matches`

```sql
-- Antes:
phase ENUM('regular','semifinal','third_place','final')

-- Después (ALTER idempotente vía dbDelta):
phase ENUM('regular','quarterfinal','semifinal','third_place','final')
```

- `quarterfinal`: usado cuando hay ≥ 8 clasificados (ej: 4 grupos × 2 por grupo)
- Los valores existentes no cambian — compatibilidad total

### Reglas de negocio del modelo

- `group_label = NULL` en `ds_matches` → partido de eliminatoria o torneo sin grupos
- `group_label` en `ds_teams` y `ds_matches` siempre coincide para partidos de fase regular
- Partidos de eliminatoria llevan `group_label = NULL` y `phase` en ('quarterfinal','semifinal','third_place','final')
- Un fixture `group_stage` **no se puede regenerar** si ya existen partidos en `ds_matches` para ese torneo

---

## Sección 2: FixtureGenerator

### Método nuevo: `generate_group_stage(array $tournament, int $venue_id): array`

**Flujo:**
1. Carga todos los equipos del torneo: `SELECT id FROM ds_teams WHERE tournament_id = %d`
2. Valida: `count(teams) >= group_count * 2` (mínimo 2 equipos por grupo)
3. Baraja aleatoriamente: Fisher-Yates sobre el array de IDs
4. Distribuye en grupos: los primeros `ceil(total / group_count)` al Grupo A, los siguientes al B, etc. Si el total no divide exactamente, los últimos grupos tienen un equipo menos (diferencia máxima de 1)
5. Actualiza `ds_teams.group_label` para cada equipo (letras mayúsculas: A, B, C…)
6. Por cada grupo: genera round-robin completo entre los equipos del grupo
   - Cada match lleva `phase = 'regular'`, `group_label = 'X'`
   - Usa `next_match_datetime()` existente con `round_index` continuo por grupo
7. Llama a `assign_courts()` para distribuir canchas
8. Retorna `['match_ids' => [...]]` o `['match_ids' => [], 'error' => '...']`

**Errores que retorna:**
- `'Equipos insuficientes para {N} grupos (mínimo {N*2} equipos).'`
- `'Ya existen partidos generados para este torneo.'`

---

### Método nuevo: `generate_group_knockout(array $tournament, int $venue_id, ?string $match_date = null): array`

Genera la **siguiente** ronda eliminatoria. Se llama múltiples veces: una por ronda. Detecta automáticamente qué ronda corresponde según el estado actual del torneo.

**Estado 1 — Sin partidos de eliminatoria (primera llamada):**
1. Verifica que todos los matches `phase = 'regular'` estén `status IN ('finished','suspended','postponed')`
2. Verifica que no existan partidos con `phase IN ('quarterfinal','semifinal','final','third_place')`
3. Carga grupos: `SELECT DISTINCT group_label FROM ds_teams WHERE tournament_id = %d ORDER BY group_label`
4. Por cada grupo: calcula standings via `StandingsCalculator::recalculate_by_group()` → toma los primeros `teams_advancing_per_group`
5. Mezcla aleatoriamente los clasificados (Fisher-Yates)
6. Determina fase según `count(clasificados)`:
   - 2 → `semifinal`
   - 4 → `semifinal`
   - 8 → `quarterfinal`
   - Otro → error `'El número de clasificados ({N}) no soporta un bracket limpio. Deben ser 2, 4 u 8.'`
7. Empareja: clasificado[0] vs clasificado[1], clasificado[2] vs clasificado[3], etc.
8. Inserta partidos con la `phase` determinada, `group_label = NULL`, `bracket_id = NULL`

**Estado 2 — Cuartos finalizados, sin semis:**
1. Verifica que todos los `quarterfinal` estén finalizados
2. Verifica que no existan `semifinal`
3. Lee los 4 partidos de QF → determina ganadores (mayor score; empate → local)
4. Mezcla aleatoriamente los 4 ganadores
5. Inserta 2 partidos con `phase = 'semifinal'`

**Estado 3 — Semis finalizadas, sin final:**
1. Verifica que ambos `semifinal` estén finalizados
2. Verifica que no existan `final` ni `third_place`
3. Lee los 2 semis → ganadores y perdedores
4. Inserta partido `third_place` (si `has_third_place = 1`) y partido `final`

**Retorna siempre:** `['match_ids' => [...], 'phase' => 'quarterfinal'|'semifinal'|'final']` o `['match_ids' => [], 'error' => '...']`

---

## Sección 3: StandingsCalculator

### Método nuevo: `recalculate_by_group(int $tournament_id): array`

**Firma:**
```php
public function recalculate_by_group(int $tournament_id): array
// Retorna: ['A' => [...rows...], 'B' => [...rows...], ...]
// Cada row: misma estructura que recalculate() pero filtrada por grupo
```

**Flujo:**
1. Carga partidos `phase = 'regular'` y `status = 'finished'` con `group_label`
2. Carga equipos con su `group_label`
3. Por cada grupo: aplica la misma lógica de `recalculate()` solo sobre los equipos y partidos de ese grupo
4. Retorna mapa `group_label → standings_array` ordenado por puntos dentro de cada grupo

---

## Sección 4: REST API

### Endpoint modificado: `POST /admin/tournament/{id}/fixture`

Cuando `tournament.format = 'group_stage'` → llama `generate_group_stage()` en lugar de `generate()`. Sin cambio de interfaz externa.

### Endpoint nuevo (admin): `POST /admin/tournament/{id}/knockout`

```
POST /wp-json/soccertrack/v1/admin/tournament/{id}/knockout
```

**Permission:** `ds_generate_fixture`

**Body:**
```json
{
  "venue_id":   1,
  "match_date": "2026-10-15"   // opcional, misma semántica que en /playoffs
}
```

**Comportamiento:**
- Genera la primera ronda eliminatoria post-grupos (cuartos o semis)
- Valida que `format = 'group_stage'`
- Llama a `generate_group_knockout()`
- Retorna `{ matches_created: N, match_ids: [...], phase: 'quarterfinal'|'semifinal' }`

### Endpoint `/knockout` — inteligente para todas las rondas

El endpoint `POST /admin/tournament/{id}/knockout` maneja **todas** las rondas eliminatorias de group_stage, no solo la primera. El coordinador lo llama múltiples veces:

- **Llamada 1** (grupos finalizados, sin knockout) → genera cuartos o semis según clasificados
- **Llamada 2** (cuartos finalizados, sin semis) → genera semis con los 4 ganadores de QF
- **Llamada 3** (semis finalizadas, sin final) → genera final + 3.er puesto (si `has_third_place = 1`)

`generate_group_knockout()` detecta automáticamente qué ronda toca según el estado del torneo. El endpoint `/finals` existente **no se modifica** — group_stage no lo usa.

### Endpoint nuevo (público): `GET /public/tournament/{id}/groups`

Sin autenticación. Retorna:

```json
[
  {
    "label": "A",
    "standings": [
      { "rank": 1, "team_id": 3, "team_name": "Empresa A", "pj": 3, "pts": 9, ... }
    ],
    "matches": [
      { "id": 10, "home_team": "Empresa A", "away_team": "Empresa B", "status": "finished", ... }
    ]
  },
  {
    "label": "B",
    "standings": [...],
    "matches": [...]
  }
]
```

`standings` se puebla con resultados parciales (partidos finalizados hasta el momento). `matches` incluye todos los partidos del grupo independientemente de su estado.

---

## Sección 5: Panel Admin — UI

### Formulario de creación de torneo (`torneos.php`)

Cuando el coordinador selecciona `Fase de grupos` en el selector de formato, aparecen (con JS `show/hide`) tres campos adicionales debajo del selector:

```html
<div id="group-stage-options" style="display:none">
  <label>Número de grupos
    <input type="number" name="group_count" value="2" min="2" max="8">
  </label>
  <label>Equipos que clasifican por grupo
    <input type="number" name="teams_advancing_per_group" value="2" min="1" max="4">
  </label>
  <label>
    <input type="checkbox" name="has_third_place" value="1" checked>
    Partido por 3.er puesto
  </label>
</div>
```

Estos campos se guardan en `wp_ds_tournaments` al crear el torneo.

### Detalle de torneo (`torneo-detalle.php`)

Para `format = 'group_stage'`, la sección existente de "Play-offs" se reemplaza por una sección **"Fase Eliminatoria"** con:

1. **Tabla de grupos** — quién está en cada grupo (visible siempre, incluso antes de generar fixture):
   - Solo se muestra si el fixture ya fue generado (`group_label` asignado en equipos)

2. **Estado de la fase eliminatoria** — similar al diseño actual de brackets:
   - Si no todos los partidos de grupos están finalizados: mensaje informativo
   - Si todos los grupos finalizados: selector de recinto + input de fecha opcional + botón **"Generar [Cuartos|Semis]"**
   - Si la ronda en curso está finalizada: botón **"Generar [Semis|Final]"** (reutiliza endpoint `/finals`)
   - Si todo completado: **✅ Completo**

---

## Sección 6: Portal Público

### Tab "Posiciones"

Para `group_stage`, muestra N tablas en lugar de una:

```
Grupo A
# | Equipo    | PJ | PG | PE | PP | GF | GC | PTS | Forma
1 | Empresa A |  3 |  3 |  0 |  0 |  8 |  2 |  9  | V V V
2 | Empresa B |  3 |  2 |  0 |  1 |  5 |  4 |  6  | V V D
...

Grupo B
...
```

Cada tabla ordena por PTS → DG → GF. Las primeras `teams_advancing_per_group` filas se marcan visualmente como "zona de clasificación" (borde verde a la izquierda, mismo estilo que "zona playoff" actual).

### Tab "Fixture"

Partidos agrupados con cabeceras de sección:

```
── Grupo A ──────────────────────────────
  J1: Empresa A vs Empresa B (Sáb 06/09 19:00)
  J1: Empresa C vs Empresa D (Sáb 06/09 20:00)
  J2: Empresa A vs Empresa C (Sáb 13/09 19:00)
  ...

── Grupo B ──────────────────────────────
  ...

── Cuartos de Final ─────────────────────
  (disponible al terminar fase de grupos)

── Semi-finales ─────────────────────────
  (disponible al terminar cuartos)

── Final / 3.er Puesto ──────────────────
  (disponible al terminar semi-finales)
```

Si el torneo no tiene partidos de eliminatoria aún, las secciones de eliminatoria no se muestran (no texto "disponible…" — simplemente no existen en el DOM).

---

## Archivos afectados

| Archivo | Tipo de cambio |
|---|---|
| `includes/Core/DatabaseInstaller.php` | 3 columnas en `ds_tournaments`, `group_label` en `ds_teams` y `ds_matches`, ampliar ENUM `phase` |
| `includes/Core/FixtureGenerator.php` | 2 métodos nuevos: `generate_group_stage()`, `generate_group_knockout()` |
| `includes/Core/StandingsCalculator.php` | 1 método nuevo: `recalculate_by_group()` |
| `includes/RestApi/AdminEndpoints.php` | Modificar `/fixture` (detectar formato), nuevo `/knockout`, modificar `/finals` (detectar group_stage) |
| `includes/RestApi/PublicEndpoints.php` | Nuevo endpoint `GET /public/tournament/{id}/groups` |
| `includes/Public/TournamentPage.php` | Carga de grupos + standings por grupo para el portal |
| `templates/panel/torneos.php` | Campos adicionales para group_stage en formulario de creación |
| `templates/panel/torneo-detalle.php` | Sección "Fase Eliminatoria" reemplaza/extiende "Play-offs" |

---

## Fuera de alcance (esta iteración)

- Número de clasificados distinto a 2, 4 u 8 (ej: 6 clasificados de 3 grupos)
- Edición manual de asignación de grupos (el sorteo es automático)
- Repesca o equipos "mejor tercero" de grupos
- Formato `knockout` (eliminación directa sin fase de grupos) — spec separado
