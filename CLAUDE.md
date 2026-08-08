# SoccerTrack — plugin de WordPress

Contexto del proyecto para todos los subagentes. Léelo antes de tocar código.

> El plugin anterior de calibración de fibra óptica (CalibraTrack) fue la base estructural.
> Su código fuente queda en `calibratrack-legacy/` como referencia, pero ya no es el plugin activo.

## Qué es esto

Plugin de WordPress para gestión y seguimiento de torneos de fútbol corporativos.
Permite registrar torneos, equipos, jugadores y partidos; calcula tabla de posiciones;
y expone shortcodes y una REST API para mostrar fixtures y resultados en el sitio público.

Sitio de referencia: **https://torneoscorporativos.cl**

## Entorno objetivo — restricciones duras

- **WordPress 7.0.2**
- **PHP 8.2**
- **MariaDB 10.6+** (hosting compartido cPanel/CloudLinux — sin privilegio SUPER)

PHP 8.2 habilita sintaxis moderna. Se puede y se DEBE usar:
- `enum` nativos (8.1) — en lugar de arrays de mapeo
- `match` (8.0) — en lugar de `switch`
- Nullsafe operator `?->` (8.0)
- Constructor property promotion (8.0)
- Named arguments (8.0)
- Union types en firmas (`int|string`, `Response|WP_Error`)
- `readonly` properties (8.1) y readonly classes (8.2)
- Return types explícitos en todos los métodos (`: void`, `: array`, etc.)

Restricciones del hosting compartido (siguen aplicando):
- Sin `SUPER` ni variables globales del servidor — no diseñar nada que dependa de eso.
- Queries pesadas pueden gatillar límites LVE — preferir queries indexadas y paginadas.
- Migraciones siempre vía `dbDelta()` en activación, nunca scripts manuales en MySQL.

## Convenciones del plugin

- Slug del plugin: `soccertrack`
- Prefijo de funciones/hooks: `soccertrack_`
- Prefijo de clases: `SoccerTrack_`
- Prefijo de CPTs y meta keys: `st_`
- Estándar de código: WordPress Coding Standards (WPCS), vía PHPCS
- i18n: text domain `soccertrack` — usar `__()`, `esc_html__()` en todo texto visible.

## Identidad visual (Torneos Corporativos)

Paleta de colores extraída del sitio:
- Verde primario:   `#3CBC20` — botones CTA, links nav, secciones accent
- Verde secundario: `#6DB728` — hover, variante
- Verde claro:      `#7CDA24` — highlights, badges
- Azul marino:      `#0E0C19` — títulos H2, textos sobre fondo claro
- Carbón:           `#3C3A47` — header, body text
- Blanco:           `#FFFFFF`

Tipografía:
- Display/hero: **Abril Fatface**
- UI/body:      **Poppins** (100–900)

Logo PNG: `https://torneoscorporativos.cl/wp-content/uploads/2025/07/Diseno-sin-titulo-1-scaled.png`

Variables CSS del plugin (`soccertrack/public/css/`):
```css
--st-green-primary:   #3CBC20;
--st-green-secondary: #6DB728;
--st-green-light:     #7CDA24;
--st-navy:            #0E0C19;
--st-charcoal:        #3C3A47;
--st-font-display:    'Abril Fatface', serif;
--st-font-body:       'Poppins', Helvetica, Arial, sans-serif;
```

## Modelo de datos

- CPT `st_torneo`:  nombre, fecha_inicio, fecha_fin, formato, grupos, activo
- CPT `st_equipo`:  nombre, ciudad, colores, dt, torneo_id, grupo
- CPT `st_jugador`: nombre, dorsal, posicion, fecha_nac, documento, equipo_id
- CPT `st_partido`: torneo_id, fase, grupo, local_id, visita_id, fecha, cancha, estado, goles_local, goles_visita, arbitro

Tablas custom (vía `dbDelta()`):
- `wp_st_eventos_partido` — goles, tarjetas, sustituciones por minuto
- `wp_st_tabla_posiciones` — caché de posiciones por torneo/grupo

Enums nativos PHP 8.1 (fuente única de verdad):
- `TipoEvento` — gol, asistencia, tarjeta_amarilla, tarjeta_roja, sustitucion, penal_fallado, gol_propio
- `EstadoPartido` — programado, en_curso, finalizado, suspendido, aplazado
- `FaseTorneo` — fase_grupos, octavos, cuartos, semifinal, tercer_puesto, final

## REST API pública

- `GET /wp-json/soccertrack/v1/torneo/{id}/tabla` — tabla de posiciones
- `GET /wp-json/soccertrack/v1/torneo/{id}/partidos?fase=xxx` — fixture

## Shortcodes

- `[soccertrack_tabla torneo_id="123" grupo="A"]`
- `[soccertrack_fixture torneo_id="123" fase="fase_grupos"]`

## Roles y Funciones por Agente

| Rol WP | Slug | Responsabilidad |
|--------|------|-----------------|
| **Super Admin / Lead Developer** | `administrator` | Instalación/activación plugin, dbDelta, SSL, caché WPO, asignación credenciales coordinadores y árbitros |
| **Comisario / Coordinador** | `ds_coordinador` | Carga masiva Excel/CSV (ligas, equipos, nóminas RUT/ID, calendarios), generador fixture Round Robin, resolución anti-colisión, boletines Tribunal Disciplina |
| **Árbitro / Veedor** | `ds_arbitro` | Planilla Digital desde móvil: registra goles, tarjetas, incidentes; jugadores suspendidos bloqueados automáticamente; cierre de acta gatilla recálculo de posiciones |
| **Delegado de Club** | `ds_delegado` | Panel restringido: actualiza escudos, gestiona cuerpo técnico, consulta sanciones de sus jugadores, descarga reglamentos PDF |
| **Jugador / Público** | *(anónimo)* | Portal responsive con pestañas: Equipos, Posiciones en Tiempo Real, Fixture Interactivo, Tribunal, Estadísticas, Descarga Documentos |

### Capabilities por rol

- `ds_coordinador`: `ds_manage_tournaments`, `ds_load_excel`, `ds_generate_fixture`, `ds_manage_discipline`, `ds_view_admin_panel`
- `ds_arbitro`: `ds_enter_match_result`, `ds_view_match_sheet`
- `ds_delegado`: `ds_manage_club`, `ds_view_club_panel`

## Cómo trabajar en este repo

- Cambios pequeños y revisables, no reescrituras masivas.
- Los enums son la fuente de verdad de tipos — nunca duplicar las listas en arrays manuales.
- Si una decisión de arquitectura no está aquí, documentarla en `docs/decisiones.md` antes de implementar.
- No inventar requisitos nuevos — si algo no está claro, preguntar antes de asumir.
