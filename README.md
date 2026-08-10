# SoccerTrack — Plugin de WordPress

Plugin de gestión y seguimiento de torneos de fútbol corporativos para **torneoscorporativos.cl**.

## ¿Qué hace?

Permite registrar torneos, equipos, jugadores y partidos; calcula tabla de posiciones en tiempo real; y expone shortcodes y una REST API para mostrar fixtures y resultados en el sitio público.

### Flujo principal

1. **Coordinador carga equipos y nóminas** vía CSV/Excel
2. **Coordinador genera el fixture** Round Robin con resolución de conflictos de cancha
3. **Coordinador o Veedor registra resultados** al final de cada jornada desde `/panel/mis-partidos/`
4. **Al cerrar el acta**, se recalcula automáticamente la tabla de posiciones
5. **El público consulta** fixture, posiciones, estadísticas y resoluciones del Tribunal vía portal responsive

## Funcionalidades

- Gestión de torneos, equipos, jugadores y partidos desde panel `/panel/`
- Carga masiva de equipos y nóminas desde CSV
- Generador de fixture Round Robin con anti-colisión de canchas
- Planilla digital para veedores/coordinadores: goles, tarjetas, incidentes por minuto
- Bloqueo automático de jugadores suspendidos en el fixture
- Tabla de posiciones en tiempo real con caché optimizado
- Tribunal de Disciplina con suspensiones automáticas por tarjetas
- Catálogo de árbitros y planilleros (informativo, sin acceso al sistema)
- Portal público responsive con pestañas: Equipos, Posiciones, Fixture, Tribunal, Estadísticas, Bases
- REST API pública para integración con el sitio

## Stack técnico

- **WordPress 7.0.2** + **PHP 8.2**
- **MariaDB 10.6+** (hosting compartido cPanel/CloudLinux)
- Enums nativos PHP 8.1, `dbDelta()` para migraciones

## Roles

| Rol | Slug | Responsabilidad |
|-----|------|-----------------|
| **Administrador** | `administrator` | Instalación, configuración global, gestión completa, acceso total |
| **Coordinador de Liga** | `ds_coordinador` | Carga masiva, fixture, resultados, tribunal, gestión de usuarios y staff |
| **Veedor de Resultados** | `ds_veedor` | Ingreso de resultados, goles y tarjetas en planillas de partido |
| **Público / Jugador** | *(anónimo)* | Portal responsive de solo lectura |

> Los roles `ds_arbitro`, `ds_planillero` y `ds_delegado` fueron eliminados en v1.9.
> Los árbitros y planilleros son ahora un catálogo informativo en `wp_st_staff`.

## Flujo de estados de un torneo

```
Borrador ──(≥2 equipos)──▶ Activo ──▶ Finalizado
```

Un torneo finalizado no puede retroceder.

## REST API

```
GET /wp-json/soccertrack/v1/torneo/{id}/tabla
GET /wp-json/soccertrack/v1/torneo/{id}/partidos?fase=xxx
```

## Shortcodes

```
[soccertrack_tabla torneo_id="123" grupo="A"]
[soccertrack_fixture torneo_id="123" fase="fase_grupos"]
```

## Panel de administración

| URL | Acceso | Descripción |
|-----|--------|-------------|
| `/panel/` | Todos los roles | Dashboard principal |
| `/panel/torneos/` | Coordinador, Admin | Crear y gestionar torneos |
| `/panel/torneo/{id}/` | Coordinador, Admin | Ficha del torneo: equipos, fixture, posiciones, tribunal |
| `/panel/mis-partidos/` | Coordinador, Admin, Veedor | Ingreso de resultados filtrado por torneo y fecha |
| `/panel/partido/{id}/` | Coordinador, Admin, Veedor | Planilla individual de partido |
| `/panel/usuarios/` | Coordinador, Admin | Gestión de usuarios y catálogo de árbitros/planilleros |
| `/panel/recintos/` | Coordinador, Admin | Catálogo de canchas y estadios |
| `/panel/importar/` | Coordinador, Admin | Importación masiva de equipos y jugadores |

## Documentación de usuario

Los manuales HTML por rol se encuentran en `docs/manuals/`:

- `docs/manuals/index.html` — Índice general
- `docs/manuals/manual-administrador.html` — Manual del Administrador
- `docs/manuals/manual-coordinador.html` — Manual del Coordinador de Liga
- `docs/manuals/manual-veedor.html` — Manual del Veedor de Resultados

## Instalación local

```bash
docker-compose up -d
```

Luego instalar el plugin desde `/wp-admin/plugins.php` subiendo el zip correspondiente.

## Migraciones de base de datos

Las migraciones se ejecutan automáticamente al actualizar el plugin (via `dbDelta()`). Tablas custom:

| Tabla | Descripción |
|-------|-------------|
| `wp_st_eventos_partido` | Goles, tarjetas y sustituciones por minuto |
| `wp_st_tabla_posiciones` | Caché de posiciones por torneo/grupo |
| `wp_st_staff` | Catálogo de árbitros y planilleros (informativo) |
| `wp_st_recintos` | Canchas y estadios disponibles |
| `wp_st_torneo_recintos` | Asociación torneo ↔ recinto |
