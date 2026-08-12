# Diseño: Brackets de Playoffs Configurables

**Fecha:** 2026-08-12  
**Estado:** Aprobado  
**Contexto:** Implementar soporte para múltiples brackets de playoffs (ej: Copa de Oro + Copa de Plata) configurables por el coordinador al crear el torneo.

---

## Problema

El sistema actual solo soporta un único bracket de playoffs (top 4: 1.° vs 4.°, 2.° vs 3.°). El formato real de los torneos requiere dividir los equipos en múltiples brackets al terminar la fase regular (ej: primeros 4 a Copa de Oro, siguientes 4 a Copa de Plata), cada uno con su propia Semifinal → Final + 3.° Puesto.

---

## Decisiones de diseño

| Decisión | Elección | Razón |
|---|---|---|
| Cuándo configurar brackets | Al crear el torneo | El formato se conoce de antemano; permite mostrar estructura en portal desde el día 1 |
| Nombres de brackets | Libres (VARCHAR) | El coordinador los define (ej: "Copa de Oro", "Grupo A Playoffs") |
| Tabla de posiciones | Compartida, una sola | Ambas copas usan el mismo ranking de fase regular |
| Formato por bracket | Semifinal → Final + 3.° puesto | Único formato soportado en esta implementación (4 equipos por bracket) |
| Estrategia de migración | Opción C: nueva tabla + `bracket_id` nullable | No rompe ENUM existente; dbDelta seguro en hosting compartido |
| Compatibilidad hacia atrás | Preservada | Torneos sin brackets configurados siguen funcionando igual |

---

## Sección 1: Modelo de Datos

### Nueva tabla `wp_ds_playoff_brackets`

```sql
CREATE TABLE wp_ds_playoff_brackets (
    id            BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
    tournament_id BIGINT UNSIGNED  NOT NULL,
    name          VARCHAR(100)     NOT NULL,
    rank_from     TINYINT UNSIGNED NOT NULL,
    rank_to       TINYINT UNSIGNED NOT NULL,
    sort_order    TINYINT UNSIGNED NOT NULL DEFAULT 0,
    created_at    DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_tournament (tournament_id)
) ENGINE=InnoDB;
```

### Columna nueva en `wp_ds_matches`

```sql
-- Agregada vía dbDelta() en DatabaseInstaller::create_tables()
bracket_id BIGINT UNSIGNED NULL DEFAULT NULL
KEY idx_bracket (bracket_id)
```

### Reglas de negocio del modelo

- `bracket_id = NULL` en `ds_matches` → partido de fase regular (comportamiento actual sin cambio)
- Los rangos `rank_from`/`rank_to` de un torneo no deben solaparse — validado en PHP al guardar
- Un bracket se **bloquea** (no editable ni eliminable) una vez que existe al menos un partido en `ds_matches` con ese `bracket_id`
- No se requiere que los rangos cubran todos los equipos del torneo (pueden existir equipos sin bracket asignado)

---

## Sección 2: `FixtureGenerator`

### Métodos existentes — sin cambios

`generate_playoffs()` y `generate_finals()` se mantienen intactos para torneos sin brackets configurados. No se eliminan.

### Métodos nuevos

#### `generate_bracket_playoffs(array $tournament, int $bracket_id, int $venue_id): array`

**Flujo:**
1. Lee el bracket por `$bracket_id` → obtiene `rank_from`, `rank_to`, `tournament_id`
2. Verifica que el `tournament_id` del bracket coincida con el del torneo
3. Verifica que todos los partidos `phase = 'regular'` del torneo estén `status = 'finished'`
4. Verifica que no existan ya partidos con `bracket_id = $bracket_id` y `phase = 'semifinal'`
5. Llama a `StandingsCalculator::recalculate($tournament_id)`
6. Extrae los equipos en posiciones `rank_from..rank_to` (índices 0-based: `rank_from-1` a `rank_to-1`)
7. Empareja: primero del bracket vs último, segundo vs penúltimo (ej: pos 1 vs pos 4, pos 2 vs pos 3)
8. Inserta 2 partidos con `phase = 'semifinal'`, `bracket_id = $bracket_id`
9. Retorna `['match_ids' => [...]]` o `['match_ids' => [], 'error' => '...']`

#### `generate_bracket_finals(array $tournament, int $bracket_id, int $venue_id): array`

**Flujo:**
1. Lee las 2 semifinales `finished` del bracket (`bracket_id = $bracket_id`, `phase = 'semifinal'`)
2. Verifica que no existan ya partidos con `bracket_id = $bracket_id` y `phase IN ('final','third_place')`
3. Determina ganadores/perdedores de cada semi (mayor score = ganador; empate → equipo local gana)
4. Inserta partido por 3.° (`phase = 'third_place'`) y Final (`phase = 'final'`), ambos con `bracket_id`
5. Retorna `['match_ids' => [...]]` o `['match_ids' => [], 'error' => '...']`

### Seeding interno del bracket

El ranking dentro del bracket es relativo a la tabla general del torneo. El equipo en posición `rank_from` actúa como "1.° interno". Emparejamiento: `rank_from` vs `rank_to`, `rank_from+1` vs `rank_to-1`.

---

## Sección 3: REST API

### Endpoints nuevos — gestión de brackets (admin)

```
POST   /wp-json/soccertrack/v1/admin/tournament/{id}/brackets
GET    /wp-json/soccertrack/v1/admin/tournament/{id}/brackets
PATCH  /wp-json/soccertrack/v1/admin/tournament/{id}/brackets/{bid}
DELETE /wp-json/soccertrack/v1/admin/tournament/{id}/brackets/{bid}
```

**Permission:** `ds_generate_fixture`

**Body POST / PATCH:**
```json
{
  "name":      "Copa de Oro",
  "rank_from": 1,
  "rank_to":   4,
  "sort_order": 0
}
```

**Validaciones al crear/editar:**
- `rank_from >= 1`, `rank_from < rank_to`
- No solapamiento con otros brackets del mismo torneo
- Bracket no bloqueado (sin partidos generados) — solo para PATCH/DELETE
- Permission: `ds_generate_fixture`

### Endpoints existentes — modificados

| Endpoint | Cambio |
|---|---|
| `POST /admin/tournament/{id}/playoffs` | Nuevo parámetro opcional `bracket_id`. Si el torneo tiene brackets y no se envía `bracket_id` → `400`. Si no tiene brackets → comportamiento actual. |
| `POST /admin/tournament/{id}/finals` | Mismo criterio que playoffs. |

**Compatibilidad hacia atrás:** si el torneo no tiene ningún bracket en `ds_playoff_brackets`, los endpoints funcionan exactamente igual que hoy.

### Endpoint nuevo — público

```
GET /wp-json/soccertrack/v1/torneo/{id}/brackets
```

Sin autenticación. Retorna:

```json
[
  {
    "id": 1,
    "name": "Copa de Oro",
    "rank_from": 1,
    "rank_to": 4,
    "sort_order": 0,
    "teams": [
      { "rank": 1, "team_id": 5, "team_name": "Empresa A", "points": 18 }
    ],
    "matches": {
      "semifinal":   [...],
      "final":       [...],
      "third_place": [...]
    }
  }
]
```

`teams` solo se puebla si la fase regular ha terminado (todos los partidos `regular` en `finished`). `matches` solo incluye los partidos ya generados.

---

## Sección 4: StandingsCalculator y Portal Público

### StandingsCalculator

`recalculate()` agrega dos campos por equipo cuando el torneo tiene brackets:

```php
[
  'team_id'      => 3,
  'points'       => 12,
  'rank'         => 2,
  'bracket_id'   => 1,           // null si fase regular no terminó o sin brackets
  'bracket_name' => 'Copa de Oro', // null en mismos casos
]
```

La asignación se hace en PHP post-cálculo: se itera la tabla ordenada por `rank` y se busca en qué bracket cae cada posición. No requiere query adicional — los brackets se cargan una sola vez junto con el cálculo de posiciones.

### Portal público — Tab "Posiciones"

Cuando el torneo tiene brackets **y** la fase regular terminó, la tabla muestra una columna "Copa":

| Pos | Equipo | PJ | Pts | Copa |
|---|---|---|---|---|
| 1 | Empresa A | 7 | 18 | Copa de Oro |
| 5 | Empresa E | 7 | 8 | Copa de Plata |

Si la fase regular no ha terminado, la columna Copa no se muestra.

### Portal público — Tab "Fixture"

Los partidos de playoffs se agrupan visualmente bajo el nombre del bracket:

```
── Copa de Oro ──────────────────────────────
  Semifinal: Empresa A vs Empresa D  (fecha/hora)
  Semifinal: Empresa B vs Empresa C  (fecha/hora)
  Final:     por definir
  3.° Puesto: por definir

── Copa de Plata ────────────────────────────
  Semifinal: Empresa E vs Empresa H  (fecha/hora)
  ...
```

Si el torneo no tiene brackets configurados, el fixture muestra playoffs como hoy (sin agrupación).

---

## Archivos afectados

| Archivo | Tipo de cambio |
|---|---|
| `includes/Core/DatabaseInstaller.php` | Nueva tabla `ds_playoff_brackets` + columna `bracket_id` en `ds_matches` |
| `includes/Core/FixtureGenerator.php` | 2 métodos nuevos: `generate_bracket_playoffs()`, `generate_bracket_finals()` |
| `includes/Core/StandingsCalculator.php` | Agregar `bracket_id` / `bracket_name` al resultado por equipo |
| `includes/RestApi/AdminEndpoints.php` | 4 endpoints nuevos de brackets + modificar `playoffs` y `finals` |
| `includes/RestApi/PublicEndpoints.php` | 1 endpoint público `GET /torneo/{id}/brackets` |
| `includes/Public/TournamentPage.php` | Agrupación por bracket en fixture + columna Copa en posiciones |
| `includes/enum-fase-torneo.php` | **Sin cambios** |
| Schema `ds_matches` ENUM `phase` | **Sin cambios** |

---

## Fuera de alcance (esta iteración)

- Formatos de bracket distintos a Semifinal → Final + 3.° Puesto
- Bracket con más o menos de 4 equipos
- Mecanismos complejos de desempate (penales, tiempo extra, golden goal) — la regla aplicada es la misma que hoy: en caso de empate gana el equipo local, idéntico al comportamiento de `generate_finals()` existente
- UI de administración en el panel WordPress (se gestiona vía REST API)
