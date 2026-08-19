# Spec: Bracket Visual de Playoffs + Panel Swiss

**Fecha:** 2026-08-19  
**Estado:** Aprobado por usuario  

---

## Contexto

El plugin SoccerTrack ya tiene backend completo para brackets de playoffs de 8 equipos:
- `generate_bracket_playoffs()` en `FixtureGenerator.php` maneja cuartos → semis → final con siembra 1°vs8°, 2°vs7°, 3°vs6°, 4°vs5°.
- Tabla `ds_playoff_brackets` almacena configuración de brackets por torneo.
- Endpoints REST para CRUD de brackets y generación de fases ya funcionan.

Lo que falta:
1. El formulario de brackets en el panel está oculto para formato Swiss (restricción innecesaria).
2. El tab Playoffs en el portal público muestra los partidos como lista; no hay cuadro visual tipo árbol.

---

## Parte 1 — Panel: habilitar brackets para Swiss

### Cambio

**Archivo:** `soccertrack/templates/panel/torneo-detalle.php`

Eliminar la condición que oculta el formulario de brackets para el formato Swiss:

```php
// ANTES (línea ~721):
<?php if ( ( $tournament['format'] ?? '' ) !== 'swiss' ) : ?>
<div id="st-bracket-form-wrap" ...>
  ...
</div>
<?php endif; ?>

// DESPUÉS: eliminar el if/endif — el formulario aparece para todos los formatos
<div id="st-bracket-form-wrap" ...>
  ...
</div>
```

### Resultado

El coordinador de un torneo Swiss puede:
- Crear brackets (nombre, pos. desde, pos. hasta, tipo de sorteo)
- Editar y eliminar brackets no bloqueados
- Generar cuartos → semis → final por bracket (ya funcional en backend)

No se requiere ningún cambio en PHP/REST — el backend ya soporta Swiss sin restricción de formato.

---

## Parte 2 — Portal público: cuadro visual bracket (CSS tree)

### Archivos afectados

| Archivo | Tipo de cambio |
|---------|---------------|
| `soccertrack/assets/js/live-standings.js` | Reemplazar `renderPlayoffs()` con versión de árbol visual |
| `soccertrack/assets/css/tournament-page.css` | Agregar estilos `.st-bracket-tree`, `.st-bracket-col`, `.st-bracket-match` y líneas CSS |

### Estructura HTML generada

```html
<!-- Por cada bracket -->
<h3 class="st-subsection-title">Copa de Oro</h3>  <!-- solo si hay múltiples brackets -->
<div class="st-bracket-tree st-bracket-tree--8">   <!-- --4 si no hay QF -->

  <!-- Columna Cuartos (solo si existen partidos quarterfinal) -->
  <div class="st-bracket-col" data-round="qf">
    <h4 class="st-bracket-col-title">Cuartos de Final</h4>
    <div class="st-bracket-match" data-match-id="...">...</div>
    <div class="st-bracket-match" data-match-id="...">...</div>
    <div class="st-bracket-match" data-match-id="...">...</div>
    <div class="st-bracket-match" data-match-id="...">...</div>
  </div>

  <!-- Columna Semis -->
  <div class="st-bracket-col" data-round="sf">
    <h4 class="st-bracket-col-title">Semifinal</h4>
    <div class="st-bracket-match">...</div>
    <div class="st-bracket-match">...</div>
  </div>

  <!-- Columna Final + 3er Puesto -->
  <div class="st-bracket-col" data-round="final">
    <h4 class="st-bracket-col-title">Final</h4>
    <div class="st-bracket-match">...</div>
    <div class="st-bracket-match st-bracket-match--third">
      <span class="st-bracket-phase-label">3.er Puesto</span>
      ...
    </div>
  </div>

</div>
```

### Tarjeta de partido `.st-bracket-match`

```html
<div class="st-bracket-match [st-bracket-match--winner-home|away]">
  <div class="st-bracket-team [st-bracket-team--winner]">
    <span class="st-bracket-team-name">Nombre Equipo Local</span>
    <span class="st-bracket-score">2</span>
  </div>
  <div class="st-bracket-team [st-bracket-team--winner]">
    <span class="st-bracket-team-name">Nombre Equipo Visita</span>
    <span class="st-bracket-score">1</span>
  </div>
  <div class="st-bracket-match-meta">Finalizado · 14 ago</div>
</div>
```

**Estado celda vacía** (equipo no definido aún):
```html
<div class="st-bracket-team st-bracket-team--tbd">
  <span class="st-bracket-team-name">?</span>
  <span class="st-bracket-score">-</span>
</div>
```

**Ganador**: clase `st-bracket-team--winner` en la fila ganadora → texto en negrita + color `--st-green-primary`.

### Lógica adaptativa en JS

```javascript
// Dentro de renderPlayoffs():
const hasQF = qfMatches.length > 0;
const treeClass = hasQF ? 'st-bracket-tree--8' : 'st-bracket-tree--4';

// Columnas a renderizar:
const columns = [
  hasQF ? { round: 'qf', title: 'Cuartos de Final', matches: qfMatches } : null,
  { round: 'sf', title: 'Semifinal', matches: sfMatches },
  { round: 'final', title: 'Final', matches: [...finalMatches, ...thirdMatches] },
].filter(Boolean);
```

### Múltiples brackets

Cada bracket del `bracketMap` genera su propio bloque:
```html
<h3>Copa de Oro</h3>
<div class="st-bracket-tree ...">...</div>

<h3>Copa de Plata</h3>
<div class="st-bracket-tree ...">...</div>
```

### CSS — líneas de conexión

Las líneas entre columnas se logran con pseudo-elementos en cada `.st-bracket-match`:

```css
/* Línea horizontal derecha de cada tarjeta */
.st-bracket-col:not([data-round="final"]) .st-bracket-match::after {
  content: '';
  position: absolute;
  right: calc(var(--bracket-gap) * -0.5);
  top: 50%;
  width: calc(var(--bracket-gap) * 0.5);
  border-top: 2px solid var(--st-charcoal);
  opacity: 0.3;
}

/* Línea vertical que conecta pares en QF → SF */
/* Se aplica en pares: nth-child(odd) borde-bottom, nth-child(even) borde-top */
.st-bracket-col[data-round="qf"] .st-bracket-match:nth-child(odd)::before { ... }
.st-bracket-col[data-round="qf"] .st-bracket-match:nth-child(even)::before { ... }
```

Variables CSS a agregar:
```css
--bracket-gap: 24px;
--bracket-gap-half: 12px;
--bracket-match-width: 220px;
```

### Comportamiento responsive

- En pantallas < 768px: el árbol hace scroll horizontal (`overflow-x: auto` en `.st-bracket-tree`)
- Las tarjetas mantienen `min-width: 160px`

---

## Criterios de aceptación

### Panel
- [ ] El formulario de brackets aparece en torneos con formato `swiss`
- [ ] Se puede crear, editar y eliminar brackets para Swiss
- [ ] La generación de cuartos/semis/final funciona igual que otros formatos

### Portal público
- [ ] El tab Playoffs muestra el cuadro visual árbol cuando hay brackets con datos
- [ ] Bracket de 8: muestra 3 columnas (QF → SF → Final)
- [ ] Bracket de 4: muestra 2 columnas (SF → Final)
- [ ] Celdas de equipos no definidos muestran "?" en gris
- [ ] El ganador de cada partido aparece resaltado en verde
- [ ] El partido por el 3.er puesto aparece en la columna Final con etiqueta propia
- [ ] Múltiples brackets se muestran cada uno con su cuadro separado y título
- [ ] En móvil el cuadro hace scroll horizontal sin romper el layout

---

## Archivos a modificar

| Archivo | Cambio |
|---------|--------|
| `soccertrack/templates/panel/torneo-detalle.php` | Eliminar condición `!== 'swiss'` en línea ~721 |
| `soccertrack/assets/js/live-standings.js` | Reescribir `renderPlayoffs()` con árbol visual |
| `soccertrack/assets/css/tournament-page.css` | Agregar estilos del bracket tree |

Sin cambios en PHP de backend, REST API, ni base de datos.
