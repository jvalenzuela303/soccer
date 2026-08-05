# Estadísticas de Rendimiento y Métricas Deportivas — Spec

**Fecha:** 2026-08-05
**Proyecto:** SoccerTrack — Plugin WordPress
**URL de referencia:** `https://dev.torneoscorporativos.cl/torneo/1/?tab=standings`

---

## Objetivo

Agregar estadísticas de rendimiento y métricas deportivas al portal público del torneo para motivar a jugadores y equipos. Incluye: enriquecer la pestaña Posiciones, mejorar la pestaña Goleadores, y agregar una nueva pestaña "Estadísticas" con records del torneo y podio de goleadores.

---

## Enfoque elegido

**Opción A:** Extender `StandingsCalculator::recalculate()` con campos nuevos + nuevo endpoint REST `/stats`. Sin duplicación de lógica.

---

## Backend

### 1. `StandingsCalculator::recalculate()` — campos nuevos

La query de partidos existente se extiende agregando `match_datetime` para poder ordenar cronológicamente por equipo.

Con los mismos resultados ya cargados (sin queries adicionales) se calcula por equipo:

| Campo | Tipo | Descripción |
|---|---|---|
| `form` | `string[]` | Últimos ≤5 partidos finalizados: `'W'`, `'D'`, `'L'`. Índice 0 = más antiguo, último = más reciente. |
| `clean_sheets` | `int` | Partidos donde el equipo no recibió goles. |
| `win_streak` | `int` | Victorias consecutivas actuales desde el partido más reciente hacia atrás. 0 si el último no fue victoria. |

**Implementación:** Después de procesar todos los partidos, se construye para cada equipo un array de eventos ordenados por `match_datetime ASC`, se toman los últimos 5 y se calculan los tres campos.

La query existente pasa de:
```sql
SELECT home_team_id, away_team_id, home_score, away_score FROM …
```
a:
```sql
SELECT home_team_id, away_team_id, home_score, away_score, match_datetime FROM … ORDER BY match_datetime ASC
```

### 2. Nuevo endpoint REST `/stats`

**Ruta:** `GET /wp-json/soccertrack/v1/public/tournament/{id}/stats`
**Autenticación:** ninguna (público)
**Caché:** transient de 60s (`st_pub_{id}_stats`)

**Respuesta:**
```json
{
  "records": {
    "best_attack":        { "team": "Nombre Equipo", "gf": 18 },
    "best_defense":       { "team": "Nombre Equipo", "gc": 3 },
    "most_clean_sheets":  { "team": "Nombre Equipo", "count": 4 },
    "longest_streak":     { "team": "Nombre Equipo", "wins": 4 }
  },
  "top_scorers": [
    {
      "rank": 1,
      "first_name": "Luis",
      "last_name": "Vidal",
      "team_name": "Corporativo FC",
      "goals": 7,
      "goals_per_match": 1.4
    }
  ]
}
```

**Lógica:**
- `records` se deriva del resultado de `StandingsCalculator::recalculate()` (ya en caché). Sin queries adicionales.
- `top_scorers` reutiliza la query de goleadores existente. `goals_per_match` = `goals / team_pj`, donde `team_pj` se obtiene cruzando el resultado de standings por `team_name`.
- Solo los top 10 goleadores (con al menos 1 gol) se incluyen en `top_scorers`.

**Registro de ruta:** Se agrega `'stats'` al array de rutas en `PublicEndpoints::register_routes()`.

---

## Frontend

### Pestaña Posiciones — enriquecida

**Tarjetas de líderes** (bloque encima de la tabla):
- 3 tarjetas en fila: ⚔️ Mejor Ataque · 🛡 Mejor Defensa · 🔥 Racha Actual
- Datos derivados del endpoint `/standings` ya cargado (sin fetch adicional)
- Se muestran solo si hay ≥ 1 partido jugado

**Columnas nuevas en la tabla** (al final):
- `%` — Win rate: `Math.round(pg / pj * 100) + '%'`. Muestra `—` si `pj === 0`.
- `Forma` — Burbujas coloreadas: verde (W), gris (D), rojo (L). Se renderizan como `<span>` con clase CSS. Solo muestra las que haya (0 a 5).

**Leyenda de zonas** (debajo de la tabla):
- 🟢 Zona playoff · ⚪ Zona media · 🔴 Zona peligro
- Solo visible si hay ≥ 4 equipos

### Pestaña Goleadores — mejorada

- Top 3 filas reciben clase `st-scorer--gold`, `st-scorer--silver`, `st-scorer--bronze` con borde izquierdo de color (oro/plata/bronce)
- Nueva columna `G/PJ` al final: `(goals / team_pj).toFixed(1)`. Muestra `—` si `pj === 0`.
- `team_pj` se obtiene cruzando `/scorers` con `/standings` (ambos ya cargados si se visitaron; si no, se hace fetch de `/standings` en background al cargar `/scorers`).

### Nueva pestaña "Estadísticas" (`tab=stats`)

**Posición en nav:** entre Goleadores y Tribunal.

**Bloque 1 — Records del torneo (4 tarjetas, grid 2×2):**

| Icono | Título | Métrica |
|---|---|---|
| ⚔️ | Mejor Ataque | `{team} · {gf} goles` |
| 🛡 | Mejor Defensa | `{team} · {gc} en contra` |
| 🧤 | Arco Menos Vencido | `{team} · {count} porterías a 0` |
| 🔥 | Racha Más Larga | `{team} · {wins} victorias seguidas` |

Solo se muestra si `records` tiene datos (al menos un partido finalizado).

**Bloque 2 — Podio de Goleadores (top 3):**

- Orden visual: 2do (izquierda, altura media) · 1ro (centro, altura mayor) · 3ro (derecha, altura menor)
- Cada tarjeta: avatar con inicial del nombre, nombre completo, equipo, cantidad de goles, `G/PJ`
- Colores: 🥇 dorado / 🥈 plateado / 🥉 bronce
- Si hay menos de 3 goleadores con goles, se muestran los que haya

**Bloque 3 — Tabla completa de goleadores:**
- Igual que la pestaña Goleadores (sin medallas de borde), solo jugadores con ≥ 1 gol
- Ranking numérico, nombre, equipo, goles, G/PJ

### Navegación y caché JS

- `renderStats()` agregado a `RENDERERS` con clave `'stats'`
- El fetch de `/stats` usa el mismo `apiFetch()` con caché en `Map` en memoria (no refetch si el tab ya se visitó)
- `i18n` en `tournament-page.php` se extiende con las claves nuevas (stats_title, best_attack, best_defense, etc.)

---

## Archivos a modificar

| Archivo | Cambio |
|---|---|
| `includes/Core/StandingsCalculator.php` | Agregar `match_datetime` a query, calcular `form`, `clean_sheets`, `win_streak` |
| `includes/RestApi/PublicEndpoints.php` | Agregar ruta `stats` + método `get_stats()` |
| `assets/js/live-standings.js` | Actualizar `renderStandings()`, `renderScorers()`, agregar `renderStats()`, extender `RENDERERS` y `i18n` |
| `templates/public/tournament-page.php` | Agregar botón pestaña + panel `st-panel-stats`, extender `stPublic.i18n` |
| `assets/css/tournament-page.css` | Estilos para: leader cards, burbujas de forma, medallas scorer, podio, record cards |

**No se crean tablas nuevas ni se modifica el schema.**

---

## Estado de datos vacíos

| Situación | Comportamiento |
|---|---|
| 0 partidos jugados | Leader cards ocultas; forma: sin burbujas; records: mensaje "Aún no hay partidos finalizados" |
| 1–2 goleadores con goles | Podio muestra solo los disponibles (sin posiciones vacías) |
| Empate en GF (mejor ataque) | Se muestra el primero alfabéticamente |

---

## Restricciones

- Sin dependencias JS externas (vanilla ES2021, igual que el código actual)
- Sin queries adicionales al backend más allá de las descritas
- Compatible con PHP 8.2 y WordPress Coding Standards (WPCS)
- Caché de 60s en todos los endpoints (igual que el resto del portal)
