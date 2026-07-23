# CalibraTrack — Registro de decisiones de arquitectura

Fuente de verdad para decisiones que no están explícitamente cubiertas en la especificación técnica.
Cada entrada incluye la decisión tomada, la alternativa descartada, la justificación y lo que queda
pendiente para el siguiente agente.

---

## D-01 — Almacenamiento de campos: postmeta nativo vs. ACF Pro

**Decisión:** `register_post_meta()` nativo de WordPress para todos los campos escalares y de galería.

**Alternativa descartada:** ACF Pro (Advanced Custom Fields).

**Justificación:**
- Evita una dependencia de plugin de terceros en el núcleo del sistema de trazabilidad.
  Si ACF Pro deja de mantenerse o cambia de licencia, el plugin quedaría bloqueado.
- `register_post_meta()` con `show_in_rest => true` integra los campos con la REST API
  de WP de forma nativa, sin capas adicionales.
- Para el volumen esperado (miles de equipos/eventos), postmeta con el índice estándar
  en `meta_key` es suficiente. No se requieren queries complejas sobre los valores de
  metafields (las búsquedas son por `serie` del equipo, no por valores de metafields cruzados).
- ACF Pro puede agregarse en el futuro para mejorar la UX de los metaboxes sin conflicto
  (puede convivir con `register_post_meta()` si se configura correctamente).

**Fuente única de meta keys:** `includes/class-calibratrack-meta-keys.php`. Ningún otro
archivo del plugin debe definir o repetir strings de meta keys.

**Pendiente para agente admin-agent:** Al implementar los metaboxes, referenciar siempre
las constantes de `CalibraTrack_Meta_Keys`, nunca strings literales.

---

## D-02 — Almacenamiento de items_costo: tabla custom vs. postmeta

**Decisión:** Tabla custom `{$wpdb->prefix}calibratrack_items_costo`, una fila por ítem,
relacionada por `evento_id` (post ID). Definida en `includes/class-calibratrack-db.php`.

**Alternativas descartadas:**

1. **Postmeta serializado** (un meta key con JSON de toda la lista):
   - No indexable por ítem individual.
   - Actualizaciones parciales requieren deserializar y reserializar todo el array.
   - Puede superar el límite práctico de `wp_postmeta` (columna `meta_value` es LONGTEXT,
     pero queries sobre él escalan mal).

2. **Múltiples filas postmeta con índice numérico** (`calibratrack_item_0_detalle`, etc.):
   - Explosión de filas en `wp_postmeta`.
   - Sin beneficio de indexación para sumas/agrupaciones.
   - Borrado y reorden complicados.

**Justificación:**
- Una tabla SQL dedicada permite `SELECT SUM(cantidad * precio_unitario)` en una query.
- Compatible con `dbDelta()` y con el entorno MariaDB 10.6.25/cPanel sin privilegio SUPER.
- El patrón de escritura es delete-then-insert (borrar todos los ítems del evento y
  reinsertar), lo que simplifica las actualizaciones cuando el usuario reordena o agrega ítems.
- Los totales (subtotal, IVA 19%, total) se calculan en PHP (`CalibraTrack_Helpers::calcular_totales_costo()`),
  no se almacenan en DB para evitar inconsistencias si la tasa de IVA cambia en el futuro.

**Versionado:** La opción `calibratrack_db_version` rastrea la versión del esquema.
`CalibraTrack_DB::maybe_upgrade()` se llama en `plugins_loaded` para aplicar migraciones automáticas.

**Pendiente para agente database-schema-agent:**
- Verificar que el esquema de la tabla definido en `CalibraTrack_DB::create_tables()` es
  correcto para los tipos de datos esperados (DECIMAL vs. FLOAT para precios/cantidades).
- Definir si se necesita índice adicional para queries de totales por período (ej. reportes).
- Los totales NO van en la tabla — si el agente de reportes los necesita persistidos,
  documentar la decisión aquí antes de agregar columnas.

---

## D-03 — Almacenamiento de evidencia_fotografica: postmeta JSON vs. tabla custom

**Decisión:** Postmeta único con JSON array de attachment IDs de WordPress Media Library.
Meta key: `calibratrack_evidencia_fotografica`. Valor: string JSON, ej. `[123, 456, 789]`.

**Alternativa descartada:** Tabla custom `calibratrack_evidencia_fotografica` (una fila por imagen).

**Justificación:**
- Las fotos ya son attachments de WP (gestionados por la Media Library). El postmeta
  almacena solo los IDs, no duplica metadatos.
- El array JSON es suficiente porque: (a) el orden se preserva en el array,
  (b) no se necesita filtrar eventos por foto individual, (c) el volumen por evento
  es bajo (pocas fotos por servicio).
- Evita una tabla extra para un dato que es esencialmente una lista de referencias a Media Library.
- Si el volumen de fotos por evento crece significativamente (fase 3), se puede migrar
  a tabla custom sin cambiar la interfaz pública, solo la capa de persistencia.

**Sanitización:** `CalibraTrack_Meta_Registration::sanitize_json_array_integers()` valida
que el valor sea un array de enteros positivos antes de guardar.

**Pendiente para agente admin-agent:**
- Usar la API `wp.media` de WordPress para el selector de galería en el admin.
- Al guardar, serializar los IDs seleccionados como JSON antes de llamar `update_post_meta()`.

---

## D-04 — CPT equipo: public => true y capability_type propio

**Decisión:**
- `public => true`, `publicly_queryable => true`: requerido para la URL `/verificar/{serie}`.
- `capability_type => array('equipo', 'equipos')`: capabilities propias del CPT.
- `map_meta_cap => true`: WP resuelve meta-caps automáticamente.
- `show_in_rest => true`: requerido para Gutenberg y REST API interna.
- `supports => ['title', 'author', 'revisions']`: sin editor (el contenido va en metafields).

**Alternativa descartada:** `capability_type => 'post'` (capabilities genéricas).

**Justificación:**
- Las capabilities propias permiten asignar al técnico permisos de solo lectura sobre
  equipos (`read_equipo`, `read_private_equipos`) sin darle capacidad de edición.
- Con `capability_type => 'post'`, el técnico tendría `edit_posts` y podría editar cualquier post.
- `has_archive => false`: no hay página de lista pública de equipos (seguridad).

---

## D-05 — CPT cliente: public => false, sin URL pública

**Decisión:**
- `public => false`, `publicly_queryable => false`, `rewrite => false`.
- `show_in_rest => true` pero con capabilities propias (`cliente`, `clientes`).
- Los metafields de cliente tienen `show_in_rest => false` para proteger datos personales.

**Justificación:**
- El cliente (empresa) contiene datos privados: RUT, teléfono, correo (§9 especificación).
- Nunca debe tener una URL pública accesible.
- El acceso REST está protegido por capabilities: técnico puede leer `nombre_empresa`
  para selects en formularios, pero no los campos privados.
- `show_in_rest => false` en metafields privados es la primera línea de defensa;
  la segunda es el `auth_callback` que requiere usuario autenticado.

**Pendiente para agente admin-agent:**
- Implementar endpoint REST privado para búsqueda de clientes (con autenticación)
  que devuelva solo `id` y `nombre_empresa`, para el selector en el formulario de equipo.
- Definir si el técnico puede crear clientes nuevos (actualmente `create_clientes => false`).

---

## D-06 — CPT evento_servicio: public => false, revisions habilitados

**Decisión:**
- `public => false`, `publicly_queryable => false`.
- `supports => ['title', 'author', 'revisions']`.
- `capability_type => array('evento_servicio', 'eventos_servicio')`.

**Justificación:**
- Los eventos son registros de auditoría de trazabilidad. `revisions` habilita el
  historial de quién cambió qué y cuándo, cubierto por WP core sin código adicional.
- No tiene URL pública directa: la información se expone solo a través del endpoint
  controlado `/verificar/{serie}` que filtra los campos a mostrar.
- La restricción "técnico solo edita sus propios eventos" requiere dos mecanismos:
  (a) `edit_others_eventos_servicio => false` en el rol, y
  (b) un filtro `user_has_cap` que verifique `post_author == current_user_id`.
  El punto (b) es pendiente para el agente admin-agent.

---

## D-07 — Rol tecnico_calibracion: capabilities exactas

**Decisión:** Rol con capabilities mínimas definidas en `CalibraTrack_Roles::get_tecnico_capabilities()`.

**Resumen de capacidades:**

| Dominio | Capability | Técnico |
|---------|-----------|---------|
| WP base | `read` | sí |
| equipo | crear/editar/borrar | NO |
| equipo | leer (cualquier estado) | sí |
| cliente | crear/editar/borrar | NO |
| cliente | leer (cualquier estado) | sí |
| evento_servicio | crear | sí |
| evento_servicio | editar propios | sí |
| evento_servicio | editar de otros | NO |
| evento_servicio | borrar | NO (ningún técnico) |
| evento_servicio | publicar (guardar) | sí |

**Pendiente para agente admin-agent:**
- El filtro `user_has_cap` que restringe `edit_evento_servicio` al autor del post.
  Sin este filtro, un técnico con `edit_eventos_servicio` podría editar eventos de
  otros técnicos si conociera la URL directa del post en el admin.
- Decidir si se agrega `delete_eventos_servicio` para el técnico (ej. solo en borrador).
  Actualmente está en NO — decisión conservadora.

---

## D-08 — Hooks de activación/desactivación/desinstalación

**Decisión:**

| Evento | Acciones |
|--------|----------|
| Activación | `CalibraTrack_Roles::add_roles()` → registrar CPTs → `CalibraTrack_DB::create_tables()` → `flush_rewrite_rules()` |
| Desactivación | Solo `flush_rewrite_rules()` — no se tocan datos |
| Desinstalación (`uninstall.php`) | Borrar posts de los 3 CPTs, borrar tabla custom, borrar opciones WP, eliminar rol |

**Sobre los attachments en desinstalación:**
Los archivos adjuntos (PDFs, QRs, fotos) NO se eliminan automáticamente.
Son attachments de la Media Library de WP y pueden estar referenciados desde otros
lugares del sitio. En un hosting compartido, un borrado masivo accidental no tiene
vuelta atrás. El administrador debe limpiarlos manualmente.

**Sobre el rol en desinstalación:**
`remove_role()` elimina el rol pero los usuarios que lo tenían quedan sin rol asignado
(comportamiento estándar de WP). Si se quiere asignar un rol por defecto a esos usuarios,
el agente de desinstalación debe implementarlo explícitamente.

---

## D-09 — Seguridad de archivos PDF

**Decisión:** Los PDFs se suben como attachments de WP con nombre de archivo generado
por `CalibraTrack_Helpers::generar_token_archivo()` (UUID v4 sin guiones, 32 caracteres hex).
El postmeta almacena el attachment ID, no la URL directa.

**Acceso a PDFs:**
- No se expone la URL de `wp-content/uploads/` en ninguna respuesta pública.
- El endpoint `/wp-json/calibratrack/v1/eventos/{id}/documentos` verifica capabilities
  antes de servir el archivo (pendiente para agente rest-api-agent).
- Para la verificación pública, se muestra si el PDF existe (booleano `tiene_certificado`),
  no su URL. El acceso al PDF requiere un token temporal o autenticación.

**Pendiente para agente rest-api-agent:**
- Implementar el endpoint de documentos con proxy PHP o URLs firmadas con TTL.
- Agregar `.htaccess` o `nginx.conf` para bloquear acceso directo a la carpeta de uploads
  del plugin (si se usa subdirectorio propio dentro de uploads/).

---

## D-10 — Página pública de verificación: template PHP (Opción A) para MVP

**Decisión:** Template PHP renderizado en servidor (`templates/public/verificar.php`)
para el MVP (Fase 1). No SPA con fetch al endpoint REST.

**Alternativa descartada para Fase 1:** JavaScript con fetch a REST API (Opción B).

**Justificación:**
- Más simple de implementar y depurar en un hosting compartido sin acceso SSH.
- Carga más rápida (sin round-trip extra de JavaScript): cumple el requisito < 2s.
- No agrega dependencia de JavaScript para la funcionalidad core de verificación.
- Compatible con cualquier tema de WordPress sin conflictos.
- La Opción B se deja documentada para Fase 2 si se quiere mejorar la UX.

**Pendiente para agente public-agent:**
- Registrar la rewrite rule custom `/verificar/{serie}`.
- Implementar el template PHP.
- El template NO debe usar PHP 8+ (restricción del proyecto).

---

## D-11 — Fuente única de listas de opciones (tipo_equipo, tipo_evento)

**Decisión:** Todas las listas de opciones para campos de selección están definidas
**únicamente** en `CalibraTrack_Helpers` (métodos `get_tipos_equipo()`, `get_tipos_evento()`,
`get_estados_equipo()`). No se usan `enum` de PHP (incompatibles con PHP 7.4).

**Regla:** Cualquier formulario, validación, API o template que necesite las opciones
válidas de `tipo_equipo` o `tipo_evento` debe llamar al método correspondiente de
`CalibraTrack_Helpers`, no definir el array localmente.

**Justificación:**
- Evita inconsistencias si se agrega un tipo nuevo (ej. "analizador de espectro").
- Compatible con PHP 7.4 (sin `enum` de 8.1).
- Las etiquetas usan `__()` con text domain `calibratrack` para i18n.

---

## D-12 — Estado de vigencia: calculado on-the-fly, no persistido

**Decisión:** El estado de vigencia del equipo (`vigente/por_vencer/vencido/sin_evento`)
se calcula en PHP en el momento de la consulta usando `CalibraTrack_Helpers::calcular_estado_vigencia()`.
No se almacena en postmeta como valor definitivo.

**Excepción:** `EQUIPO_ESTADO_CACHE` existe como meta key pero se usa solo como caché
temporal con TTL de 1 hora máximo (via transients de WP). No es la fuente de verdad.

**Justificación:**
- El estado depende de la fecha actual (`proxima_fecha_control` vs. `hoy`). Si se persiste,
  puede quedar desactualizado hasta la próxima escritura del equipo.
- El cálculo es O(1) — una comparación de fechas — sin costo significativo.
- Los transients de WP son la capa de caché correcta para este tipo de dato volátil
  en un hosting compartido sin Redis.

---

## Estructura de carpetas del plugin

```
calibratrack/
├── calibratrack.php                          # Punto de entrada, header WP, bootstrap
├── uninstall.php                             # Desinstalación definitiva (no desactivación)
├── includes/
│   ├── class-calibratrack-meta-keys.php      # FUENTE ÚNICA de nombres de meta keys
│   ├── class-calibratrack-helpers.php        # Utilidades, listas de opciones, cálculos
│   ├── class-calibratrack-cpt-equipo.php     # Registro del CPT equipo
│   ├── class-calibratrack-cpt-cliente.php    # Registro del CPT cliente
│   ├── class-calibratrack-cpt-evento-servicio.php  # Registro del CPT evento_servicio
│   ├── class-calibratrack-meta-registration.php    # register_post_meta() para los 3 CPTs
│   ├── class-calibratrack-roles.php          # Rol tecnico_calibracion y sus capabilities
│   ├── class-calibratrack-db.php             # Tabla custom items_costo + dbDelta
│   └── class-calibratrack-rest-api.php       # Bootstrap y registro de rutas REST
├── admin/
│   └── class-calibratrack-admin.php          # Bootstrap del área admin (menú, metaboxes)
├── public/
│   └── class-calibratrack-public.php         # Bootstrap del front-end público
├── templates/
│   ├── public/
│   │   └── verificar.php                     # Template de verificación pública
│   ├── admin/                                # Templates de metaboxes (pendiente admin-agent)
│   └── email/
│       └── alerta-vencimiento.php            # Template correo de alerta (Fase 2)
├── assets/
│   ├── css/
│   │   ├── admin.css                         # Estilos admin
│   │   └── public.css                        # Estilos front-end
│   ├── js/
│   │   ├── admin.js                          # JS admin (repetidor, galería, selects)
│   │   └── public.js                         # JS front-end (mínimo en MVP)
│   └── images/                               # Imágenes estáticas del plugin
├── languages/                                # Archivos .pot/.po/.mo para i18n
└── vendor/                                   # Dependencias Composer (QR, PDF — pendiente)
```

---

## D-13 — Tipo DECIMAL para `cantidad` en calibratrack_items_costo

**Decisión:** `DECIMAL(10,2)` para `cantidad` (cambiado desde `DECIMAL(10,4)` de la v1.0.0).

**Alternativa descartada:** `DECIMAL(10,4)` con 4 decimales de escala.

**Justificación:**
- El dominio del negocio es calibración y mantenimiento de instrumentos de medición.
  Las cantidades representan unidades de servicio: horas-técnico, unidades de repuesto,
  servicios de calibración. En ningún caso real se necesitan más de 2 decimales (ej. 1.50 horas, 2.25 unidades).
- `DECIMAL(10,4)` permitiría cantidades como `1.2345`, que no tienen significado en el negocio
  y confunden al usuario final en formularios y reportes.
- `CalibraTrack_Helpers::calcular_totales_costo()` ya aplica `round(..., 2)` al resultado:
  usar 4 decimales en DB pero 2 en PHP genera una inconsistencia conceptual innecesaria.
- `DECIMAL(10,2)` sigue soportando hasta `99,999,999.99` unidades, más que suficiente.
- `DECIMAL(12,2)` para `precio_unitario` se mantiene sin cambios: es correcto para CLP
  (Peso Chileno, sin decimales en la práctica, pero 2 decimales garantizan compatibilidad
  si el sistema se adapta a otra moneda). Rango: hasta `9,999,999,999.99`.

**Migración:** La función `CalibraTrack_DB::maybe_upgrade()` ejecuta un `ALTER TABLE`
para cambiar la columna de `DECIMAL(10,4)` a `DECIMAL(10,2)` en instalaciones v1.0.0.
`dbDelta()` solo no sería suficiente porque no modifica tipos de columnas existentes.

---

## D-14 — Formato `%s` con `number_format()` para DECIMAL en `$wpdb->insert`

**Decisión:** Usar `'%s'` (string) con `number_format($valor, 2, '.', '')` para los
campos `cantidad` y `precio_unitario` al hacer `$wpdb->insert()`.

**Alternativa descartada:** Usar `'%f'` (float).

**Justificación:**
- `$wpdb->prepare()` con `%f` internamente hace `sprintf('%f', $valor)`, que:
  1. Produce notación con 6 decimales por defecto (`1.230000`), lo que no es un
     problema para MariaDB pero es redundante.
  2. En locales con separador decimal de coma (`,`), `sprintf` puede producir `1,23`
     en algunos entornos PHP, causando un error silencioso en MariaDB que guarda `1`
     (solo hasta el separador).
  3. Valores con ruido de punto flotante pueden producir strings como `1.2300000000001`
     que luego MariaDB redondea, pero que pueden causar bugs sutiles en comparaciones.
- `number_format($valor, 2, '.', '')` garantiza siempre `"1.23"` (punto decimal, sin
  separadores de miles, exactamente 2 decimales) independiente del locale de PHP.
- El string `"1.23"` es siempre un literal válido para `DECIMAL(10,2)` en MariaDB.
- Esta práctica es la recomendada por la comunidad WordPress para valores DECIMAL
  (ver WP Coding Standards y documentación de `$wpdb->prepare`).

---

## D-15 — Índices de la tabla `calibratrack_items_costo`

**Decisión:** Dos índices definidos en el esquema v1.1.0:

| Índice | Columnas | Propósito |
|--------|----------|-----------|
| `idx_evento_id` | `(evento_id)` | Filtros `WHERE evento_id = ?` en CRUD básico |
| `idx_evento_orden` | `(evento_id, orden)` | ORDER BY en `get_items_costo()` y queries de historial |

**Justificación del índice compuesto `idx_evento_orden`:**
- La query más frecuente sobre la tabla es:
  `SELECT ... WHERE evento_id = ? ORDER BY orden ASC`
- Con solo `idx_evento_id`, MariaDB hace un index scan + filesort para el ORDER BY.
- Con el índice compuesto `(evento_id, orden)`, el ORDER BY se resuelve desde el propio
  índice (index-ordered scan), eliminando el filesort. Esto es especialmente útil cuando
  un evento tiene muchos ítems (ej. > 20 líneas de presupuesto).
- El índice compuesto también cubre al índice simple `(evento_id)` en MariaDB/InnoDB
  (leftmost prefix rule), por lo que `idx_evento_id` es estrictamente redundante.
  Sin embargo, se mantiene `idx_evento_id` por legibilidad y compatibilidad con
  herramientas que inspeccionan índices por nombre.

**Índices que NO se agregan (y por qué):**
- Índice sobre `detalle` (FULLTEXT): no se necesita en el MVP. Si en el futuro el
  módulo de reportes requiere búsqueda de texto en descripciones de ítems, se puede
  agregar como migración: MariaDB 10.6 soporta FULLTEXT en InnoDB.
- Índice sobre `precio_unitario` o `cantidad`: no hay queries que filtren por valor
  de precio individual. Los totales se calculan con `SUM()` por `evento_id`.

---

## D-16 — Queries de negocio en `CalibraTrack_DB`

Se agregaron 4 métodos de query a `CalibraTrack_DB`. Documentados aquí para el
agente backend que los consume:

### `get_ultimo_evento( $equipo_id )`
- Devuelve el evento más reciente (por `calibratrack_fecha_ejecucion`) de un equipo.
- Solo considera eventos en estado `publish` (los borradores no afectan vigencia).
- Retorna: objeto con `post_id`, `proxima_fecha_control`, `fecha_ejecucion`. O `null`.
- Índices usados: índice estándar de WP sobre `wp_postmeta (meta_key, meta_value)`.
- LIMIT 1: seguro porque siempre devuelve una fila.

### `get_historial_eventos( $equipo_id, $limit = 50 )`
- Devuelve todos los eventos de un equipo, ordenados por fecha de ejecución DESC.
- `$limit = 0` desactiva el límite (usar solo para exportaciones, no para UI).
- El parámetro `$limit` se inyecta directamente en el SQL (como entero validado
  con `(int)`, no vía placeholder) porque MariaDB en algunos contextos rechaza
  `LIMIT %d` vía PDO prepared statements.
- Retorna campos suficientes para el historial sin hacer N+1 queries.

### `get_equipos_proximos_a_vencer( $dias_aviso = 30 )`
- Busca equipos con `proxima_fecha_control` entre hoy y hoy + N días.
- Las fechas se calculan en PHP (con `America/Santiago`) para evitar depender del
  timezone del servidor de BD, que en hosting compartido puede ser UTC o local.
- Agrupa por `equipo_id` para no devolver el mismo equipo varias veces si tiene
  múltiples eventos próximos a vencer simultáneamente.
- LIMIT 500 protege contra tabla postmeta crecida sin bound en el cron.
- Esta query se ejecuta vía `wp_cron`, no en cada request HTTP.

### `get_subtotal_evento( $evento_id )`
- Retorna `SUM(cantidad * precio_unitario)` directamente en MariaDB.
- Usa `COALESCE(..., 0)` para devolver `0.0` si el evento no tiene ítems.
- El cálculo de IVA y total se hace en PHP con `CalibraTrack_Helpers::calcular_totales_costo()`
  pasando el subtotal (ver D-02 sobre por qué los totales no van en DB).
- Alternativa descartada: traer todos los ítems con `get_items_costo()` y sumar en PHP.
  La query SQL es más eficiente para el caso de solo necesitar el total (ej. listados).

---

## D-17 — Caché de estado de vigencia con transients de WP

**Decisión:** Confirmar D-12: transients de WP con TTL de 1 hora son suficientes
para el volumen esperado (miles de equipos, requests de verificación esporádicos).

**Razonamiento técnico:**
- La operación de cálculo de vigencia es `get_ultimo_evento()` (una query con JOINs
  sobre postmeta) + comparación de fechas en PHP. Costo estimado: < 5ms por equipo.
- Con transients, la segunda consulta al mismo equipo en la misma hora es O(1)
  (lectura de `wp_options` o Redis si está disponible en el hosting).
- Para el MVP no se requiere Redis. Si el hosting compartido (cPanel/CloudLinux)
  tiene un Object Cache plugin disponible, los transients se redirigen automáticamente.
- **TTL recomendado:** 3600 segundos (1 hora). La clave debe ser `calibratrack_vigencia_{equipo_id}`.
- **Invalidación:** el hook `save_post_evento_servicio` debe llamar
  `delete_transient("calibratrack_vigencia_{$equipo_id}")` al guardar un evento
  nuevo o actualizar uno existente. Esto garantiza que el estado se recalcule
  inmediatamente después de un servicio, sin esperar a que expire el transient.

**Pendiente para agente php-backend-developer:** Implementar la lógica de caché
descrita arriba en el controlador de guardado de eventos.

---

---

## D-18 — Módulo de cron en archivo separado (class-calibratrack-cron.php)

**Decisión:** El sistema de alertas de vencimiento (RF-08) se implementa en
`includes/class-calibratrack-cron.php` como clase independiente `CalibraTrack_Cron`.

**Alternativas descartadas:**
- Incluirlo en `class-calibratrack-rest-api.php`: el cron no tiene relación funcional
  con la REST API; mezclarlos viola el principio de responsabilidad única.
- Incluirlo en `class-calibratrack-admin.php`: el cron se ejecuta siempre (`plugins_loaded`),
  no solo en `is_admin()`. Cargarlo solo en admin impediría que se ejecute en peticiones
  de front-end o de WP CLI que disparan el cron.

**Justificación:**
- El cron es un subsistema independiente: no depende de la REST API ni del admin,
  solo de `CalibraTrack_DB` y `wp_mail()`.
- Separarlo facilita la auditoría de seguridad (el security-auditor puede revisar el
  módulo de correos de forma aislada).
- El archivo se carga en `calibratrack_load_dependencies()` siempre (no solo en admin),
  y la clase se inicializa en `calibratrack_init()` con `CalibraTrack_Cron::init()`.

**Programación:**
- Hook: `calibratrack_check_vencimientos` con recurrencia `'daily'` de WP Cron.
- Se programa en el hook de activación (`CalibraTrack_Cron::schedule()`).
- Se cancela en el hook de desactivación (`CalibraTrack_Cron::unschedule()`).
- El callback evalúa ventanas de 30 días y 7 días como dos pasadas independientes.

**Anti-duplicados:**
- Transient `calibratrack_alerta_enviada_{equipo_id}_{dias}` con TTL 23h.
- Si el cron no se ejecuta exactamente cada 24h (hosting compartido con cron real),
  el margen de 1h evita que se pierda una alerta.

**Privacidad:**
- Los correos de alerta incluyen solo: serie, marca, modelo, nombre_empresa (solo nombre).
- NUNCA se incluyen: RUT, teléfono, correo del cliente (§9 especificación).

---

## D-19 — Guardado de totales calculados como postmeta de solo lectura

**Decisión:** Al guardar un evento de servicio, se calculan subtotal, IVA y total
en servidor (`CalibraTrack_Helpers::calcular_totales_costo()`) y se persisten como
postmeta con keys `calibratrack_subtotal`, `calibratrack_iva`, `calibratrack_total`.

**Por qué se persisten si D-02 dice que no deben ir en DB:**
- D-02 se refiere a los totales en la tabla custom `calibratrack_items_costo`
  (para no duplicar datos ya calculables con `SUM()`).
- Persistirlos como postmeta del evento tiene un propósito diferente: permitir
  consultas rápidas de "total facturado por evento" en listados y reportes sin
  necesidad de hacer `SUM()` cada vez que se carga la lista de eventos.
- La fuente de verdad sigue siendo la tabla de ítems; los totales en postmeta
  son una proyección que se recalcula cada vez que se guarda el evento.

**Regla de integridad:**
- Los tres metas (`calibratrack_subtotal`, `calibratrack_iva`, `calibratrack_total`)
  NO se exponen en formularios ni son editables por el usuario.
- Solo se escriben desde `CalibraTrack_Admin::save_evento_meta()`, nunca desde el
  navegador.

**Pendiente para agente admin-ui-agent:**
- Mostrar los totales como campos de solo lectura en el metabox del evento.
- Registrar estos tres meta keys en `CalibraTrack_Meta_Keys` si se decide
  referenciarlos como constantes en el futuro.

---

## Pendiente para el agente php-backend-developer

Los siguientes puntos quedaron definidos a nivel de DB pero requieren implementación
en la capa de negocio (backend PHP):

1. **Consumir `get_ultimo_evento()`** para calcular el estado de vigencia del equipo
   en la ficha del equipo y en el endpoint REST `/verificar/{serie}`. La secuencia es:
   ```php
   $evento = CalibraTrack_DB::get_ultimo_evento( $equipo_id );
   $proxima = $evento ? $evento->proxima_fecha_control : '';
   $estado  = CalibraTrack_Helpers::calcular_estado_vigencia( $proxima );
   ```

2. **Usar `get_historial_eventos()`** en el controlador REST de `GET /equipos/{id}/historial`.
   Recordar no exponer `tecnico_id` directamente — resolver el nombre del técnico con
   `get_user_by('id', $row->tecnico_id)->display_name` antes de armar la respuesta.

3. **Programar `get_equipos_proximos_a_vencer()`** vía `wp_cron`:
   - Hook sugerido: `calibratrack_alerta_vencimiento` con recurrencia diaria.
   - Registrar la recurrencia en `class-calibratrack-db.php` (activación) y limpiarla
     en desactivación.
   - El evento cron debe leer el valor de días de aviso desde una opción de WP
     configurable en el admin (sugerido: `calibratrack_dias_aviso_vencimiento`, default: 30).

4. **Implementar invalidación de transient de vigencia** en `save_post_evento_servicio`.
   Ver D-17 para el nombre de clave y el TTL.

5. **Verificar el tipo de retorno de `get_items_costo()`:** los campos `cantidad` y
   `precio_unitario` retornan como strings desde `$wpdb->get_results()` (comportamiento
   de PDO). Castear a `float` antes de operar aritméticamente:
   ```php
   $cantidad = (float) $item->cantidad;
   $precio   = (float) $item->precio_unitario;
   ```

6. **No agregar columnas de totales** a `calibratrack_items_costo` sin documentar
   la decisión aquí primero. Ver D-02 para la justificación.

---

## D-20 — Librería QR: chillerlan/php-qrcode ^4.3

**Decisión:** `chillerlan/php-qrcode` versión `^4.3` para la generación de códigos QR
de equipos. Implementado en `includes/class-calibratrack-qr.php` (`CalibraTrack_QR`).

**Alternativas evaluadas y descartadas:**

| Librería | Versión evaluada | Constraint PHP | Decisión |
|----------|-----------------|----------------|----------|
| `endroid/qr-code` | v3.x | `^7.2` | Descartada: arrastra `symfony/options-resolver`, `symfony/cache` y otras dependencias Symfony que suman ~8 paquetes extra. Peso excesivo para un hosting compartido. |
| `endroid/qr-code` | v4.x | `^8.0` | Descartada: requiere PHP 8.0+ — incompatible con el servidor (PHP 7.4.33). |
| `bacon/bacon-qr-code` | v2.x | `^7.1` | Descartada: depende de `dasprid/enum` (workaround de enum para PHP < 8.1) y su output PNG requiere configuración adicional de GD. API más verbosa. Menos activamente mantenida. |
| `chillerlan/php-qrcode` | v5.x | `^8.1` | Descartada: requiere PHP 8.1+ — incompatible. |
| `chillerlan/php-qrcode` | **v4.3** | `^7.4 \|\| ^8.0` | **Elegida.** |

**Justificación de la elección:**

1. **Compatibilidad verificada con PHP 7.4:** El `composer.json` de `chillerlan/php-qrcode`
   v4.x declara `"php": "^7.4 || ^8.0"` (confirmado en el historial del repositorio
   GitHub `chillerlan/php-qrcode` y en Packagist, rama `4.x`). Es la única de las tres
   candidatas que anuncia soporte explícito para `^7.4` sin dependencias adicionales.

2. **Dependencias mínimas:** Un único paquete — la librería misma. No arrastra
   componentes de Symfony, PHPUnit ni otros frameworks. Ideal para vendor/ de un plugin
   WordPress en hosting compartido con cuota de inodo limitada.

3. **GD puro, sin ext-imagick:** Genera PNG en memoria con `ext-gd`, que está disponible
   por defecto en cPanel/CloudLinux. No requiere `ext-imagick` (no garantizado en
   hosting compartido).

4. **API simple para el caso de uso:** `new QRCode(new QROptions([...]))->render($url)`
   es suficiente para generar el PNG. Sin overhead de configuración.

**Cómo se verificó la compatibilidad con PHP 7.4:**

- El `composer.json` de la rama `4.x` del repositorio oficial declara
  `"require": { "php": "^7.4 || ^8.0" }`.
- La v4.3 es la última release antes de la v5.0 (que subió el requisito a `^8.1`).
  El constraint `^4.3` en el `composer.json` del plugin garantiza que Composer
  nunca instalará la v5.x automáticamente.
- No usa sintaxis PHP 8+: sin `enum`, sin `match`, sin atributos, sin `readonly`.
  Compatible con el estándar del proyecto (ver restricciones PHP en el README).

**Implementación:**

- `includes/class-calibratrack-qr.php` — clase `CalibraTrack_QR` con método estático
  `generate_for_equipo($serie, $equipo_id)`.
- El QR apunta a `home_url('/verificar/') . urlencode($serie)`.
- Nombre del archivo: UUID v4 sin guiones via `CalibraTrack_Helpers::generar_token_archivo()` + `.png`.
- El attachment anterior se borra con `wp_delete_attachment()` antes de crear el nuevo.
- La generación se dispara en `CalibraTrack_Admin::save_equipo_meta()` solo si la serie
  cambió o no existe QR previo — no en cada guardado.
- Fallo no fatal: si la librería no está instalada, se muestra un admin notice y el
  guardado del equipo continúa normalmente.

**Paso manual requerido en servidor:**

Ejecutar en el directorio raíz del plugin:
```bash
composer install --no-dev --optimize-autoloader
```
Esto instala `chillerlan/php-qrcode` en `vendor/` y genera el autoload.
Sin este paso, `CalibraTrack_QR::generate_for_equipo()` retorna `false` y muestra
el aviso admin correspondiente. Los datos del equipo se guardan igualmente.
