# Spec: Cuartos de Final en Brackets de Playoffs

**Fecha:** 2026-08-14  
**Formato afectado:** `round_robin_playoffs`  
**Enfoque elegido:** Opción A — endpoint `playoffs` inteligente

---

## Contexto

Los brackets de playoffs (`ds_playoff_brackets`) actualmente soportan solo 2 rondas:
semis → final/3.er puesto. Si un bracket tiene 8 equipos (ej. pos 1–8), el sistema
generaba 2 semis dejando 4 equipos sin jugar cuartos.

El ENUM `phase` en `ds_matches` ya incluye `quarterfinal` (migración v2.0.0) y
`octavos` (v2.1.0) — no se requieren cambios de schema.

---

## Objetivo

Habilitar cuartos de final en brackets de 8 equipos para el formato
`round_robin_playoffs`, sin afectar brackets de 4 equipos ni otros formatos
(`knockout`, `group_stage`).

---

## Archivos afectados

| Archivo | Tipo de cambio |
|---|---|
| `includes/Core/FixtureGenerator.php` | Modificar `generate_bracket_playoffs()` |
| `includes/Public/TournamentPage.php` | Agregar campos `has_quarterfinals` / `quarterfinals_done` |
| `templates/panel/torneo-detalle.php` | Agregar estados UI para cuartos |
| `templates/public/tournament-page.php` | Agregar label `quarterfinal` en mapa de fases |
| `assets/js/live-standings.js` | Agregar label `quarterfinal` en mapa de fases JS |

`includes/RestApi/AdminEndpoints.php`: sin cambios (el endpoint `playoffs` ya apunta
a `generate_bracket_playoffs()`).

---

## Diseño

### 1. `FixtureGenerator::generate_bracket_playoffs()`

**Lógica de detección de ronda** (antes de insertar partidos):

```
num_teams = rank_to - rank_from + 1

¿Existen partidos phase='quarterfinal' para este bracket?
  SÍ, en curso (alguno no finished/suspended) → error: "Cuartos en curso, espera a que terminen."
  SÍ, todos done                              → generar SEMIFINAL (ganadores de cuartos)
  NO, num_teams >= 8                          → generar QUARTERFINAL (seeding estándar)
  NO, num_teams < 8                           → generar SEMIFINAL (comportamiento actual)
```

**Emparejamiento de cuartos (seeding 1v8, 2v7, 3v6, 4v5):**

```php
// bracket_teams = equipos ordenados por posición en tabla (rank_from .. rank_to)
// Para 8 equipos: índices 0..7
$pairs = [
    [ 'home' => $bracket_teams[0], 'away' => $bracket_teams[7] ],  // 1º vs 8º
    [ 'home' => $bracket_teams[1], 'away' => $bracket_teams[6] ],  // 2º vs 7º
    [ 'home' => $bracket_teams[2], 'away' => $bracket_teams[5] ],  // 3º vs 6º
    [ 'home' => $bracket_teams[3], 'away' => $bracket_teams[4] ],  // 4º vs 5º
];
```

**Generación de semis desde cuartos** (rama "cuartos done"):

```php
// Leer los 4 partidos de cuartos del bracket, ordenados por id ASC
// Ganador de QF1 (idx 0) vs ganador de QF4 (idx 3)  → SF1
// Ganador de QF2 (idx 1) vs ganador de QF3 (idx 2)  → SF2
// Empate → gana local (misma regla que generate_bracket_finals)
```

`generate_bracket_finals()` **no cambia** — sigue leyendo 2 semis terminadas
del bracket y genera final + 3.er puesto.

### 2. `TournamentPage.php` — estado por bracket

Extender el cálculo de estado por bracket para incluir cuartos:

```php
$b_qf      = array_filter($matches, fn($m) =>
    (int)($m['bracket_id'] ?? 0) === $bid && ($m['phase'] ?? '') === 'quarterfinal'
);
$b_has_qf  = !empty($b_qf);
$b_qf_done = $b_has_qf && count(array_filter($b_qf,
    fn($m) => !in_array($m['status'], ['finished','suspended','postponed'], true)
)) === 0;

// Añadir a $brackets[]:
'has_quarterfinals'  => $b_has_qf,
'quarterfinals_done' => $b_qf_done,
'num_teams'          => (int)$b['rank_to'] - (int)$b['rank_from'] + 1,
```

### 3. Template `torneo-detalle.php` — estados UI por bracket

Máquina de estados para el bloque de acción de cada bracket:

```
Condición (evaluada en orden)           → UI
────────────────────────────────────────────────────────────────
$b['has_finals']                        → ✅ Completo
$b['has_semis'] && !$b['semis_done']    → "Semi-finales en curso…"
$b['semis_done']                        → [venue] [date] [Generar Final y 3.er Puesto]
$b['has_quarterfinals']
  && !$b['quarterfinals_done']          → "Cuartos de final en curso…"
$b['quarterfinals_done']
  && !$b['has_semis']                   → [venue] [date] [Generar Semi-finales]   ← nuevo
!$b['has_quarterfinals']
  && $b['num_teams'] >= 8              → [venue] [date] [Generar Cuartos]         ← label dinámico
!$b['has_quarterfinals']
  && $b['num_teams'] < 8              → [venue] [date] [Generar Semi-finales]     ← igual que hoy
```

El botón en todos los casos usa `data-endpoint="playoffs"` — el mismo endpoint REST.
Solo cambia el texto del botón.

### 4. Portal público — labels de fase

Agregar `'quarterfinal'` al mapa de etiquetas en:

**`templates/public/tournament-page.php`:**
```php
$phase_labels = [
    'regular'      => '',
    'quarterfinal' => '⚽ Cuartos',   // ← agregar
    'semifinal'    => '⚡ Semi',
    'third_place'  => '🥉 3.er Puesto',
    'final'        => '🏆 Final',
];
```

**`assets/js/live-standings.js`:**
```js
const PHASE_LABELS = {
    regular:      '',
    quarterfinal: '⚽ Cuartos',   // ← agregar
    semifinal:    '⚡ Semi',
    third_place:  '🥉 3.er Puesto',
    final:        '🏆 Final',
};
```

---

## Flujo completo — bracket de 8 equipos

```
Fase regular finalizada
         ↓
[Generar Cuartos]  → 4 partidos phase='quarterfinal', bracket_id=X
         ↓
Cuartos en curso → bloqueado
         ↓
4 cuartos finished
         ↓
[Generar Semi-finales]  → 2 partidos phase='semifinal', bracket_id=X
         ↓
Semis en curso → bloqueado
         ↓
2 semis finished
         ↓
[Generar Final y 3.er Puesto]  → 2 partidos phase='final'/'third_place', bracket_id=X
         ↓
✅ Completo
```

---

## Flujo actual — bracket de 4 equipos (sin cambios)

```
Fase regular finalizada
         ↓
[Generar Semi-finales]  → 2 partidos phase='semifinal'
         ↓
[Generar Final y 3.er Puesto]
         ↓
✅ Completo
```

---

## Lo que NO cambia

- Schema de DB (ENUM ya tiene `quarterfinal`)
- Endpoint REST `/brackets/{id}/playoffs` y `/brackets/{id}/finals`
- Formatos `knockout` y `group_stage`
- Brackets de 4 equipos (rama `num_teams < 8` queda intacta)
- `generate_bracket_finals()` (lee semis → genera final)

---

## Criterios de aceptación

1. Bracket con 8 equipos muestra botón "Generar Cuartos" después de fase regular completa
2. Al generarlos: 4 partidos con `phase='quarterfinal'` y `bracket_id` correcto
3. Emparejamiento: 1º vs 8º, 2º vs 7º, 3º vs 6º, 4º vs 5º (por posición en tabla)
4. Mientras cuartos en curso: botón deshabilitado, mensaje "Cuartos de final en curso…"
5. Con cuartos completos: aparece botón "Generar Semi-finales"
6. Semis y final siguen funcionando igual que hoy
7. Bracket de 4 equipos: comportamiento idéntico al actual
8. Portal público: los cuartos muestran la etiqueta "⚽ Cuartos" en fixture y ticker
