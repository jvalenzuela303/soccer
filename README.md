# SoccerTrack — Plugin de WordPress

Plugin de gestión y seguimiento de torneos de fútbol corporativos para **torneoscorporativos.cl**.

## ¿Qué hace?

Permite registrar torneos, equipos, jugadores y partidos; calcula tabla de posiciones en tiempo real; y expone shortcodes y una REST API para mostrar fixtures y resultados en el sitio público.

### Flujo principal

1. **Coordinador carga equipos y nóminas** vía CSV/Excel
2. **Coordinador genera el fixture** Round Robin con resolución de conflictos de cancha
3. **Árbitro registra incidentes en vivo** (goles, tarjetas, sustituciones) desde la planilla digital
4. **Al cerrar el acta**, se recalcula automáticamente la tabla de posiciones
5. **El público consulta** fixture, posiciones, estadísticas y resoluciones del Tribunal vía portal responsive

## Funcionalidades

- Gestión de torneos, equipos, jugadores y partidos desde `/wp-admin/`
- Carga masiva de equipos y nóminas desde Excel/CSV
- Generador de fixture Round Robin con anti-colisión de canchas
- Planilla digital para árbitros: goles, tarjetas, incidentes por minuto
- Bloqueo automático de jugadores suspendidos en el fixture
- Tabla de posiciones en tiempo real con caché optimizado
- Tribunal de Disciplina con boletines descargables
- Portal público responsive con pestañas: Equipos, Posiciones, Fixture, Tribunal, Estadísticas, Bases
- REST API pública para integración con el sitio

## Stack técnico

- **WordPress 7.0.2** + **PHP 8.2**
- **MariaDB 10.6+** (hosting compartido cPanel/CloudLinux)
- Enums nativos PHP 8.1, `dbDelta()` para migraciones

## Roles

| Rol | Responsabilidad |
|-----|-----------------|
| **Administrador** | Instalación, configuración global, asignación de credenciales |
| **Coordinador** (`ds_coordinador`) | Carga masiva, fixture, Tribunal de Disciplina |
| **Árbitro** (`ds_arbitro`) | Planilla digital, registro de incidentes en vivo |
| **Delegado de Club** (`ds_delegado`) | Panel de club, consulta de sanciones, descarga de reglamentos |
| **Público / Jugador** | Portal responsive de solo lectura |

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

## Instalación local

```bash
docker-compose up -d
```

Luego instalar el plugin desde `/wp-admin/plugins.php` subiendo el zip correspondiente.
