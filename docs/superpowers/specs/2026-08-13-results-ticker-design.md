# Spec: Ticker vertical de resultados sobre el banner del torneo

**Fecha:** 2026-08-13  
**Rama objetivo:** `feat/results-ticker`  
**Estado:** Aprobado — pendiente de implementación

---

## Objetivo

Mostrar los últimos partidos finalizados del torneo como un ticker de texto que sube en loop continuo sobre la imagen del banner publicitario, enriqueciendo el portal público sin agregar carga extra al servidor.

---

## Alcance

- Solo aplica cuando el torneo tiene banner configurado (`banner_url` no vacío).
- Solo muestra partidos con `status === 'finished'`.
- Si no hay partidos finalizados, el ticker no se renderiza.
- El ticker no interfiere con el resto del portal (tabs, posiciones, fixture).

---

## Arquitectura

### Enfoque elegido: JS reutilizando datos ya cargados (Opción B)

`live-standings.js` ya realiza fetch a `/fixture` y tiene todos los partidos en memoria. La nueva función `injectResultsTicker()` opera sobre esa data sin consultas extra al servidor.

### Flujo de datos

```
/wp-json/soccertrack/v1/torneo/{id}/fixture
        ↓
live-standings.js — carga partidos al iniciar
        ↓
injectResultsTicker(allMatches)
  1. Filtra status === 'finished'
  2. Ordena por round_number DESC
  3. Toma hasta 10 partidos
  4. Construye ítems HTML
  5. Duplica la lista (loop seamless)
  6. Inyecta en #st-results-ticker
  7. Activa animación CSS
```

---

## Cambios por archivo

### 1. `soccertrack/templates/public/tournament-page.php`

Agregar dentro de `.st-tournament-banner`, después del `<img>`, un contenedor vacío que el JS populará:

```html
<div id="st-results-ticker" class="st-results-ticker" aria-hidden="true">
  <div class="st-results-ticker__track"></div>
</div>
```

El contenedor se renderiza siempre que haya banner (el JS decide si mostrarlo o no según haya partidos finalizados).

### 2. `soccertrack/assets/css/tournament-page.css`

**`.st-tournament-banner`** — agregar `position: relative` (ya tiene `overflow: hidden`).

**`.st-results-ticker`:**
- `position: absolute; top: 0; right: 0`
- `width: 220px; height: 100%`
- `overflow: hidden`
- `background: rgba(0, 0, 0, 0.55)`
- `display: none` por defecto; el JS agrega clase `is-active` para mostrarlo

**`.st-results-ticker.is-active`:** `display: block`

**`.st-results-ticker__track`:**
- `display: flex; flex-direction: column`
- `animation: st-ticker-scroll var(--st-ticker-duration, 25s) linear infinite`

**`@keyframes st-ticker-scroll`:**
```css
from { transform: translateY(0); }
to   { transform: translateY(-50%); }
```

**`.st-results-ticker__item`:**
- `padding: 0.6rem 0.75rem`
- `font-family: var(--st-font-body); font-size: 0.72rem; color: #fff`
- `border-bottom: 1px solid rgba(255,255,255,0.1)`
- `white-space: nowrap; overflow: hidden; text-overflow: ellipsis`

**Responsive:** ocultar el ticker en pantallas menores a 480px (el banner ya es muy pequeño).

```css
@media (max-width: 480px) {
  .st-results-ticker { display: none !important; }
}
```

### 3. `soccertrack/assets/js/live-standings.js`

Nueva función `injectResultsTicker(matches)`:

```js
function injectResultsTicker(matches) {
  const ticker = document.getElementById('st-results-ticker');
  if (!ticker) return;

  const finished = matches
    .filter(m => m.status === 'finished')
    .sort((a, b) => b.round_number - a.round_number)
    .slice(0, 10);

  if (finished.length === 0) return;

  const track = ticker.querySelector('.st-results-ticker__track');
  const items = finished.map(m =>
    `<div class="st-results-ticker__item">
      <span class="st-results-ticker__round">${escHtml(i18n.round ?? 'Jornada')} ${m.round_number}</span>
      <span class="st-results-ticker__score"> · ${escHtml(m.home_team)} ${m.home_score} – ${m.away_score} ${escHtml(m.away_team)}</span>
    </div>`
  ).join('');

  // Duplicar para loop seamless
  track.innerHTML = items + items;
  ticker.classList.add('is-active');
}
```

**Punto de llamada:** al final de la función que procesa los datos del fixture, después de que `allMatches` esté disponible.

---

## Formato de ítem

```
Jornada 3 · Cóndores 2 – 1 Halcones
```

- Truncado con `text-overflow: ellipsis` si el nombre es muy largo.
- Sin íconos ni escudos (solo texto, máxima compatibilidad).

---

## Casos borde

| Caso | Comportamiento |
|------|----------------|
| Sin banner | El div `#st-results-ticker` no existe en el DOM → `injectResultsTicker` retorna inmediatamente |
| 0 partidos finalizados | `injectResultsTicker` retorna sin inyectar nada → ticker permanece oculto |
| 1 partido finalizado | Se duplica igual (el loop con 1 ítem funciona, simplemente repite más seguido) |
| Pantalla < 480px | Ticker oculto vía CSS (banner demasiado pequeño para ser legible) |
| Nombre de equipo muy largo | `text-overflow: ellipsis` trunca el ítem |

---

## Lo que NO incluye este spec

- Actualización en tiempo real del ticker (los datos se cargan una vez al abrir la página).
- Configuración de velocidad desde el panel admin.
- Pausa del ticker al hacer hover.
- Partidos en curso (en_progress) — solo finalizados.
