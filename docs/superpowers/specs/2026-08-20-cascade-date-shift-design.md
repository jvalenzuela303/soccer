# Spec: Desplazamiento en cascada de fechas del fixture

**Fecha:** 2026-08-20  
**Estado:** Aprobado

## Problema

Cuando el coordinador cambia la fecha de un partido en el fixture (ej: el viernes no hay cancha disponible, se mueve al siguiente viernes), el resto de las jornadas quedan con sus fechas originales. Ajustarlas una a una es tedioso. Se necesita una forma de propagar el desplazamiento a todos los partidos siguientes en un solo paso.

## Solución

Al editar una fecha en el fixture y guardar, un modal de confirmación ofrece desplazar también todos los partidos posteriores por el mismo delta de días. El usuario elige si aplica la cascada o no. El backend aplica el desplazamiento en un único request.

---

## UI — Frontend (`templates/panel/torneo-detalle.php`)

### Atributo `data-original`

Cada `<input type="datetime-local">` del fixture recibe el valor original de la DB como atributo:

```html
<input type="datetime-local" name="match_datetime"
       value="2026-09-05T19:00"
       data-original="2026-09-05T19:00">
```

Esto permite al JS calcular el delta en el momento del submit sin queries adicionales.

### Interceptación del submit

Un listener `submit` delegado (un solo listener en el `document`) captura cualquier form con `[name="st_update_datetime"]`. Antes de dejar que el form se envíe:

1. Leer `input[name="match_datetime"]` del form
2. Leer `data-original` del mismo input
3. Calcular `deltaMinutes = (newDate - originalDate) / 60000`
4. Si `deltaMinutes === 0` → dejar pasar el submit sin modal (sin cambio)
5. Si `deltaMinutes !== 0` → prevenir el submit, mostrar el modal

### Modal de confirmación

`<dialog id="st-cascade-modal">` (elemento nativo HTML, sin librerías). Contenido dinámico:

```
¿Desplazar también los partidos siguientes?

El partido se moverá +7 días (viernes → viernes siguiente).
¿Aplicar el mismo desplazamiento a todos los partidos programados después de esta fecha?

[Sí, desplazar todos]   [No, solo este partido]
```

- El texto indica el delta en días con signo ("+7 días", "−3 días")
- "Sí" → agrega `<input type="hidden" name="cascade" value="1">` y `<input type="hidden" name="cascade_delta_minutes" value="10080">` al form, luego submit
- "No" → submit sin campos adicionales (comportamiento original)
- Click fuera del modal o tecla Escape → equivale a "No"

### Texto del delta para el modal

| Delta (minutos) | Texto mostrado |
|---|---|
| +10080 | "+7 días (siguiente semana)" |
| −10080 | "−7 días (semana anterior)" |
| +1440 | "+1 día" |
| Cualquier otro | "+N días" / "−N días" |

---

## Backend — Handler `st_update_datetime` (`includes/Public/TournamentPage.php`)

### Modificación al handler existente

Después de que el handler guarda el partido actual (comportamiento sin cambios), se agrega el bloque de cascada:

```
si POST['cascade'] === '1':
    delta_minutes = (int) POST['cascade_delta_minutes']
    si abs(delta_minutes) > 525600 (> 1 año): ignorar — valor inválido
    si delta_minutes === 0: ignorar

    old_datetime = valor previo del partido (antes de la actualización)
    
    matches = SELECT id, match_datetime
              FROM ds_matches
              WHERE tournament_id = $id
                AND match_datetime > '$old_datetime'
              ORDER BY match_datetime ASC

    para cada match:
        new_dt = DateTime(match.match_datetime) + delta_minutes minutos
        UPDATE ds_matches SET match_datetime = new_dt WHERE id = match.id

    $cascade_count = count(matches)
```

### Redirect y notice

- Sin cascada: comportamiento actual (redirect con `?notice=datetime_updated` o equivalente)
- Con cascada exitosa: redirect con `?notice=datetime_cascade&count=N` donde N = partidos desplazados

### Notice en el template

```
✅ Fecha actualizada. Se desplazaron también N partidos siguientes.
```

---

## Validaciones y casos edge

| Caso | Comportamiento |
|---|---|
| Delta = 0 (mismo valor) | Sin modal, submit directo, sin cascada |
| Delta > 1 año (valor manipulado) | Cascada ignorada, solo se guarda el partido actual |
| No hay partidos posteriores | Modal no aparece (no tiene sentido ofrecer cascada) |
| Partido con `status = finished` | Se incluye en la cascada igualmente — la fecha ya pasó pero se registra el nuevo horario |
| Torneo bloqueado (`is_locked`) | No aplica — los inputs ya están deshabilitados por CSS existente |

### ¿Cómo sabe el JS si hay partidos posteriores?

El JS calcula `deltaMinutes` y revisa si hay algún `<input[data-original]>` en el DOM con valor posterior al partido editado. Si ninguno tiene fecha mayor, el modal no se muestra y el submit procede directo.

---

## Archivos afectados

| Archivo | Cambio |
|---|---|
| `templates/panel/torneo-detalle.php` | 1) Agregar `data-original` a inputs de datetime. 2) Agregar `<dialog id="st-cascade-modal">`. 3) Agregar JS de interceptación y lógica de modal. 4) Agregar notice `datetime_cascade`. |
| `includes/Public/TournamentPage.php` | Extender handler `st_update_datetime` para procesar cascada cuando `cascade=1`. |

Sin nuevas tablas. Sin migración de base de datos.
