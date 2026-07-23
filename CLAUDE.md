# CalibraTrack — plugin de WordPress

Contexto del proyecto para todos los subagentes. Léelo antes de tocar código.

## Qué es esto

Plugin de WordPress para trazabilidad y verificación pública de calibraciones/mantenciones
de equipos de fibra óptica (OTDR, power meters, fuentes de luz, empalmadoras de fusión,
certificadores de red, etc.). Reemplaza un flujo manual en Word.

Documento de referencia completo: `docs/especificacion-tecnica-trazabilidad.md` (en la raíz
del proyecto). Ese documento es la fuente de verdad del modelo de datos y los requisitos —
si algo en este archivo y la especificación no calzan, gana la especificación y hay que
avisarle al usuario.

## Entorno objetivo — restricciones duras

- **WordPress 6.8.5**
- **PHP 7.4.33**
- **MariaDB 10.6.25** (identificador de servidor `10.6.25-MariaDB-cll-lve`, típico de hosting
  compartido cPanel/CloudLinux)

El sufijo `cll-lve` indica hosting compartido con CloudLinux (LVE = límites de CPU/memoria/
procesos por cuenta). Esto tiene implicancias prácticas, no solo de sintaxis:
- Es probable que **no haya privilegio `SUPER`** ni acceso para cambiar variables globales
  del servidor — no diseñar nada que dependa de eso.
- Queries pesadas o transacciones largas pueden gatillar los límites del LVE y matar el
  proceso — preferir queries indexadas y paginadas sobre escaneos completos de tabla.
- El acceso normalmente es vía phpMyAdmin/cPanel, no SSH directo a MySQL — cualquier
  migración debe poder ejecutarse desde el propio plugin (`dbDelta()` en activación), no
  asumir que alguien va a correr un script a mano en el servidor.

PHP 7.4 significa que **no se puede usar sintaxis de PHP 8+**. Antes de escribir o aceptar
código, verificar que no aparezca:
- `enum` (8.1)
- `match` (8.0) — usar `switch` o arrays de mapeo
- Nullsafe operator `?->` (8.0) — usar `isset()` / comprobaciones explícitas
- Constructor property promotion (8.0) — declarar propiedades y asignarlas en el constructor
- Argumentos con nombre `funcion(nombre: valor)` (8.0)
- Union types explícitos en firmas (`int|string`) (8.0) — usar PHPDoc para documentar tipos mixtos
- Readonly properties (8.1)

Sí está disponible en PHP 7.4 y se puede usar libremente: typed properties, arrow functions
(`fn() => ...`), null coalescing assignment (`??=`), spread operator en arrays.

Cualquier librería de Composer (QR, PDF, etc.) debe declarar compatibilidad con PHP `^7.4`
en su `composer.json` — verificarlo antes de proponerla.

**MariaDB 10.6.25** ya soporta CTEs, window functions y JSON (como `LONGTEXT` con `CHECK`,
no un tipo nativo real como en MySQL 8) — se pueden usar si hacen falta, pero no asumir
sintaxis exclusiva de MySQL 8 (por ejemplo `INTERSECT`/`EXCEPT` sí existen en MariaDB 10.6,
pero conviene no depender de features muy nuevas sin verificarlas primero contra esta
versión exacta).

## Convenciones del plugin

- Slug del plugin: `calibratrack`
- Prefijo de funciones/hooks/clases: `calibratrack_` o `CalibraTrack_` (namespace si se decide
  usar autoload PSR-4, ver agente arquitecto)
- Estándar de código: WordPress Coding Standards (WPCS), vía PHPCS
- Todo el texto visible al usuario (labels, mensajes) usa funciones de i18n de WP (`__()`,
  `esc_html__()`, etc.) con el text domain `calibratrack`, aunque el sitio hoy solo esté en
  español — cuesta poco y evita rehacer trabajo después.

## Modelo de datos (resumen — ver especificación completa para detalle de campos)

- CPT `equipo`: serie (única), marca, modelo, tipo_equipo, cliente_propietario (relación),
  estado (calculado)
- CPT `cliente`: nombre_empresa, rut, contacto_nombre, telefono, correo, direccion
- CPT `evento_servicio` (relacionado a `equipo`): numero_ot, tipo (calibración/mantenimiento),
  fecha_ejecucion, proxima_fecha_control, tecnico_responsable, falla_reportada,
  descripcion_trabajo, observaciones, evidencia_fotografica (galería), garantia, dias_garantia,
  items_costo (repetidor), certificado_pdf, orden_trabajo_pdf

## No negociable: seguridad y antifraude

Este plugin existe para que un tercero pueda **confiar** en que un certificado es real. Esto
no es un detalle opcional:

- El PDF nunca es la fuente de verdad — lo es el registro en el sistema.
- Los archivos subidos (PDF, fotos) no deben quedar en URLs adivinables ni indexables
  públicamente.
- La página pública de verificación es de solo lectura, sin excepción.
- Rate limiting en el buscador público.
- No exponer RUT, teléfono ni correo del cliente en la página pública.
- Capacidades por rol: un técnico no edita/borra registros de otros técnicos salvo que sea
  administrador.

Cualquier subagente que toque uploads, permisos, o el endpoint de verificación debe repasar
esta lista antes de dar por terminado su trabajo.

## Cómo trabajar en este repo

- Cambios pequeños y revisables, no reescrituras masivas.
- Si una decisión de arquitectura no está en la especificación (ej. postmeta vs tabla custom
  para `items_costo`), documentar la decisión y el porqué en `docs/decisiones.md` antes de
  implementar.
- No inventar requisitos nuevos — si algo no está claro, preguntar antes de asumir.
