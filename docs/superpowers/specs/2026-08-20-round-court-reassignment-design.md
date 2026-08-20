# Spec: Reasignación de canchas por ronda

**Fecha:** 2026-08-20  
**Estado:** Aprobado

## Problema

El sistema actual asigna canchas de forma global y uniforme en todos los partidos de un torneo. No existe concepto de disponibilidad de canchas por ronda. Cuando el recinto avisa que para una fecha específica solo N de las M canchas estarán disponibles (y esas N pueden ser cualquier subconjunto de las M), no hay forma de reflejar esto sin editar partido por partido.

Esto ocurre tanto antes de generar el fixture (aviso anticipado) como con el torneo en curso (aviso de último momento).

## Solución: botón "Canchas" por ronda en el panel del fixture

Sin nueva tabla de base de datos. Se actualiza `ds_matches.court_id` directamente para los partidos de la ronda afectada.

---

## UI — Panel del fixture (`templates/panel/torneo-detalle.php`)

### Trigger

Cada bloque de ronda en el fixture incluye un botón pequeño junto al encabezado de la fecha:

```
Fecha 9  [⚙ Canchas]  (2 partidos · 12 equipos libres)
```

El botón solo aparece si la ronda tiene al menos un partido programado.

### Panel inline (sin modal)

Al hacer clic en "⚙ Canchas", se expande debajo del encabezado de ronda un panel inline con:

- **Título:** "Canchas disponibles para esta fecha"
- **Lista de checkboxes:** una por cada cancha registrada en el torneo, con su nombre (ej. "Cancha 1 — Estadio Municipal"). Todas marcadas por defecto.
- **Botón "Reasignar":** submits el formulario POST.
- **Botón "Cancelar":** colapsa el panel sin cambios.

El coordinador desmarca las canchas NO disponibles y presiona "Reasignar".

### Feedback post-acción

Después del redirect, aparece un alert verde:  
`✅ Canchas reasignadas para la fecha N.`

---

## Backend — Handler POST (`includes/Public/TournamentPage.php`)

### Acción: `st_reassign_round_courts`

**Inputs:**
- `tournament_id` (int)
- `round_number` (int)
- `court_ids[]` (array de int — IDs de canchas disponibles)

**Validaciones:**
- `check_admin_referer('st_reassign_round_courts_' . $id . '_' . $round_number)`
- `current_user_can('ds_manage_tournaments')`
- `$court_ids` no vacío — si no hay ninguna cancha seleccionada, retorna error
- Verificar que los `court_ids` recibidos pertenecen al torneo (prevenir manipulación)

**Lógica:**
1. Traer todos los partidos de esa ronda:  
   `SELECT id FROM ds_matches WHERE tournament_id = X AND round_number = N ORDER BY id ASC`
2. Distribuir court_ids en rotación circular sobre los partidos:  
   `court_id = $court_ids[$i % count($court_ids)]`
3. `UPDATE ds_matches SET court_id = ? WHERE id = ?` por cada partido
4. Redirect con `?notice=courts_reassigned&round=N`

**Restricción:** no modifica `match_datetime` — solo `court_id`. Las fechas/horarios quedan intactos.

---

## Datos necesarios para el template

El template ya recibe `$matches` con todos los partidos. Para construir la lista de checkboxes necesita también las canchas del torneo agrupadas por recinto. Este dato ya existe en `$courts_by_venue` que se pasa al template en `view_torneo()`.

No se requieren nuevas queries ni variables adicionales.

---

## Alcance

- Aplica a partidos de **fase regular** (`round_number` > 0) y también a playoffs si tienen `round_number` asignado.
- No aplica si el torneo está en estado `completed` (bloqueado por `$is_locked`).
- No guarda historial de la reasignación — se sobreescribe directamente.
- Funciona con el torneo en cualquier estado: borrador, activo.

---

## Archivos afectados

| Archivo | Cambio |
|---|---|
| `templates/panel/torneo-detalle.php` | Agregar botón + panel inline por bloque de ronda |
| `includes/Public/TournamentPage.php` | Agregar handler POST `st_reassign_round_courts` |

Sin migraciones de base de datos. Sin nuevas tablas.
