# Especificación técnica
## Sistema de trazabilidad y verificación pública de calibraciones y mantenciones — equipos de fibra óptica

**Versión:** 1.1 — modelo de datos ajustado según certificado de mantenimiento y OT reales (cliente con RUT, evidencia fotográfica, detalle de costos, garantía)
**Fecha:** julio 2026
**Plataforma base:** WordPress (plugin propio)

---

## 1. Contexto y problema actual

La empresa presta servicios de mantenimiento y calibración a equipos de fibra óptica (OTDR, medidores de potencia, fuentes de luz, empalmadoras de fusión, certificadores de red, etc.). Actualmente:

- El ingreso de cada equipo se registra manualmente en Word.
- El certificado de calibración y la orden de trabajo (OT) se envían al cliente por correo, sin forma de verificar su autenticidad.
- No existe un historial centralizado por equipo (serie), lo que dificulta saber cuándo fue la última calibración, cuál es la próxima fecha, o qué técnico intervino.

## 2. Objetivo

Construir un plugin de WordPress que permita:

1. **Registrar** equipos y sus eventos de servicio (calibración/mantenimiento), reemplazando el flujo manual en Word.
2. **Generar un código QR / URL único** por equipo, que el cliente o un tercero pueda escanear o ingresar (por número de serie) para verificar la autenticidad de un certificado u OT.
3. **Mantener trazabilidad histórica** completa por equipo: fechas de servicio, próximas fechas de calibración, técnico responsable, documentos adjuntos.
4. Permitir que el **técnico suba directamente** los reportes/certificados/OT desde el panel, evitando el reingreso de datos.

## 3. Alcance

### Incluido
- CPT (Custom Post Type) de Equipos y de Eventos de servicio.
- Página pública de verificación por serie y por QR.
- Panel interno para ingreso y gestión (roles: administrador, técnico).
- Generación de QR por equipo.
- Repositorio de documentos (certificados, OT) vinculados a cada evento.
- Alertas de vencimiento de próxima calibración (interno, por correo).

### Fuera de alcance (fase 1)
- App móvil nativa para técnicos (se usa el panel web, responsive).
- Integración con ERP/facturación.
- Firma digital avanzada de documentos (se evalúa en fase 2, ver §11).

## 4. Actores

| Actor | Descripción | Acceso |
|---|---|---|
| Cliente / tercero | Escanea el QR o ingresa la serie en la web pública | Sin login, solo lectura |
| Técnico | Ingresa equipos, registra eventos, sube documentos | Login, panel restringido a sus registros/equipos asignados |
| Administrador | Gestión completa, usuarios, reportes | Login, acceso total |

## 5. Flujo funcional

### 5.1 Ingreso de equipo (reemplaza el Word actual)
1. El técnico o administrador crea el registro del equipo: serie, marca, modelo, tipo de equipo, cliente/propietario.
2. El sistema genera automáticamente un identificador de verificación y el QR asociado (puede imprimirse en una etiqueta física para pegar en el equipo).

### 5.2 Registro de un evento de servicio (calibración o mantenimiento)
1. El técnico selecciona el equipo (por serie o desde su lista de OT asignadas), y el cliente asociado (o crea uno nuevo si es primera vez).
2. Completa los datos de la orden de trabajo: N° de OT, fecha, falla reportada o defecto informado por el cliente, descripción del trabajo/servicio realizado, próxima fecha de control, garantía (sí/no y días), técnico responsable.
3. Adjunta la evidencia fotográfica del servicio (una o más imágenes).
4. Completa el detalle de costos si aplica (ítems, precio unitario; el sistema calcula subtotal, IVA y total).
5. Sube el o los documentos finales: certificado de mantenimiento/calibración (PDF) y/o reporte de la orden de trabajo (PDF) — estos pueden generarse automáticamente a partir de los datos ingresados (ver §10) o subirse ya elaborados, según se defina en el diseño final.
6. Al guardar, el evento queda anexado al historial del equipo y disponible en la página pública de verificación.

### 5.3 Verificación pública
1. El cliente escanea el QR (o entra a `tudominio.cl/verificar` e ingresa la serie manualmente).
2. El sistema muestra:
   - Datos del equipo (marca, modelo, serie, cliente si aplica).
   - Estado vigente/vencido según próxima fecha de control.
   - Historial de eventos (fecha, tipo, técnico).
   - Enlace para ver/descargar el certificado y la OT correspondientes.
3. Si la serie no existe en el sistema, se muestra un mensaje explícito de "no encontrado" — esto es en sí mismo parte del mecanismo antifraude: un documento apócrifo no tendrá contraparte en el sistema.

## 6. Modelo de datos

Los documentos reales que la empresa usa hoy (certificado de mantenimiento y OT) confirman que el cliente y los costos son datos estructurados, no solo texto libre, y que el respaldo fotográfico es parte estándar del servicio. El modelo se ajusta a eso:

### 6.1 Equipo (CPT `equipo`)
| Campo | Tipo | Notas |
|---|---|---|
| serie | texto, único | identificador principal, usado en la URL de verificación |
| marca | texto | ej. Grandway, EXFO, Fluke Networks, Fujikura |
| modelo | texto | ej. GS-401 |
| tipo_equipo | selección | OTDR, power meter, fuente de luz, empalmadora de fusión, certificador, otro |
| cliente_propietario | relación a CPT `cliente` | ver §6.2 |
| fecha_ingreso_sistema | fecha | |
| codigo_qr | imagen generada | se genera al crear el equipo |
| estado | calculado | vigente / por vencer / vencido, según el evento más reciente |

### 6.2 Cliente (CPT `cliente`)
| Campo | Tipo | Notas |
|---|---|---|
| nombre_empresa | texto | ej. PROINTEL |
| rut | texto | validado con formato/dígito verificador chileno |
| contacto_nombre | texto | persona de contacto |
| telefono | texto | |
| correo | texto | |
| direccion | texto | |

Un cliente puede tener varios equipos; un equipo pertenece a un solo cliente propietario a la vez (si cambia de dueño, se deja registro del cambio como parte del historial, fase 2).

### 6.3 Evento de servicio (CPT `evento_servicio`, relacionado a `equipo`)
| Campo | Tipo | Notas |
|---|---|---|
| equipo_id | relación | equipo al que pertenece |
| numero_ot | texto | folio de la orden de trabajo (ej. "OT96") |
| tipo | selección | calibración / mantenimiento |
| fecha_ejecucion | fecha | |
| proxima_fecha_control | fecha | usada para calcular vigencia |
| tecnico_responsable | relación a usuario WP | |
| falla_reportada | texto largo | defecto informado por el cliente al ingresar el equipo |
| descripcion_trabajo | texto largo | servicio realizado / solución aplicada |
| observaciones | texto largo | hallazgos adicionales, recomendaciones al operador |
| evidencia_fotografica | galería de imágenes | una o más fotos del equipo/servicio |
| garantia | booleano (sí/no) | |
| dias_garantia | número | solo si garantía = sí |
| items_costo | repetidor: detalle, cantidad, precio_unitario | subtotal, IVA (19%) y total se calculan automáticamente |
| certificado_pdf | archivo | certificado de mantenimiento/calibración |
| orden_trabajo_pdf | archivo | reporte de la OT |

### 6.4 Usuarios
- Se reutiliza el sistema de usuarios de WordPress.
- Rol nuevo `tecnico_calibracion` con capacidades restringidas (crear/editar sus propios eventos, no borrar equipos, no administrar usuarios).

## 7. Requisitos funcionales (RF)

| ID | Requisito |
|---|---|
| RF-01 | El sistema debe generar un QR único por equipo al momento de su creación. |
| RF-02 | El QR debe apuntar a una URL pública con el formato `/verificar/{serie}`. |
| RF-03 | La página pública debe funcionar también con ingreso manual de la serie (sin necesidad de escanear). |
| RF-04 | El sistema debe mostrar el estado de vigencia (vigente/por vencer/vencido) calculado automáticamente desde la próxima fecha de control. |
| RF-05 | El técnico debe poder subir el certificado y la OT en formato PDF al registrar un evento. |
| RF-06 | El historial de un equipo debe listar todos sus eventos en orden cronológico. |
| RF-07 | El sistema debe permitir búsqueda y filtrado interno de equipos (por serie, cliente, estado, próxima fecha). |
| RF-08 | El sistema debe notificar (correo interno) cuando un equipo esté por vencer su próxima calibración (ej. 30 y 7 días antes). |
| RF-09 | El administrador debe poder generar una hoja/etiqueta imprimible con el QR y la serie del equipo. |
| RF-10 | Los documentos no deben quedar indexados públicamente ni ser adivinables por URL directa (ver §9). |
| RF-11 | El sistema debe permitir registrar los datos del cliente (empresa, RUT, contacto, teléfono, correo, dirección) y asociarlos al equipo. |
| RF-12 | El técnico debe poder adjuntar una o más fotografías como evidencia de cada evento de servicio. |
| RF-13 | El sistema debe permitir registrar un detalle de costos por ítem y calcular automáticamente subtotal, IVA (19%) y total. |
| RF-14 | El sistema debe registrar si el servicio tiene garantía y, de tenerla, la cantidad de días. |
| RF-15 | La página pública de verificación no debe mostrar datos de contacto del cliente (RUT, teléfono, correo) por defecto, solo lo necesario para confirmar autenticidad (ver §9). |

## 8. Requisitos no funcionales

- **Disponibilidad:** la página de verificación debe cargar en menos de 2 segundos en condiciones normales.
- **Responsive:** debe funcionar correctamente en móviles, ya que el flujo típico es escanear un QR desde el celular.
- **Compatibilidad:** debe convivir con el hosting y plugins actuales de WordPress sin conflictos (evaluar constructor de páginas en uso).
- **Escalabilidad:** el modelo debe soportar al menos algunos miles de equipos y eventos sin degradar el rendimiento (uso de índices en `serie` y consultas paginadas).
- **Trazabilidad de cambios:** quién y cuándo registró o modificó cada evento (log básico de autoría, ya cubierto por WordPress a nivel de autor/fecha de post).

## 9. Seguridad y prevención de fraude

Este es el punto central del proyecto, ya que el objetivo es que un tercero pueda *confiar* en que el documento es real:

- **No editable desde el documento:** el PDF nunca es la fuente de verdad por sí solo; la fuente de verdad es el registro en el sistema. El PDF puede alterarse, pero no cambiará lo que muestra la página de verificación.
- **URLs de archivos no adivinables:** los PDF deben servirse con nombres de archivo aleatorios/hasheados y, idealmente, a través de un endpoint controlado (no acceso directo a `/wp-content/uploads/...`) para evitar enumeración.
- **Rate limiting** en el buscador público, para evitar scraping masivo de series.
- **Sin edición pública:** la página de verificación es de solo lectura; ningún dato se modifica desde el lado público.
- **Permisos por rol:** un técnico no debería poder editar ni eliminar eventos de otros técnicos salvo que sea administrador.
- **Opcional (fase 2):** sello/hash de integridad visible en el PDF (ej. un código corto que también se valida en la página de verificación), para reforzar la percepción de autenticidad incluso fuera de línea.
- **Privacidad de datos del cliente:** ahora que el modelo incluye RUT, teléfono y correo del cliente, la página pública de verificación debe mostrar solo lo esencial (equipo, estado, historial de servicios, documentos), sin exponer estos datos de contacto a cualquiera que escanee el QR.

## 10. Arquitectura propuesta (WordPress)

- **Plugin propio** (no depender de page builders para la lógica), usando:
  - Custom Post Types + campos personalizados (ACF Pro o campos nativos con `register_post_meta`).
  - Un shortcode o bloque de Gutenberg para insertar la página/buscador de verificación en cualquier página del sitio.
  - Librería de generación de QR en PHP (ej. `endroid/qr-code`) ejecutada al crear/guardar un equipo.
  - Rol de usuario `tecnico_calibracion` con `capabilities` restringidas.
  - Endpoint REST propio (`/wp-json/calibratrack/v1/verificar/{serie}`) para desacoplar el front de verificación y permitir, a futuro, una app o integración externa.
- **Hosting de archivos:** dentro de `wp-content/uploads` pero fuera de listados públicos, o usando un plugin de gestión de medios privados; evaluar si el hosting actual soporta el volumen de PDFs esperado.
- **Alternativa a evaluar:** si el volumen de equipos/clientes crece rápido, migrar solo el back-end de datos (equipos/eventos/documentos) a una aplicación separada con API propia, dejando en WordPress únicamente la página pública de verificación (iframe o fetch a la API). Esto se deja documentado como ruta de escalamiento, no como parte de la fase 1.

## 11. Roadmap propuesto

| Fase | Contenido |
|---|---|
| Fase 1 (MVP) | CPTs de equipo/evento, ingreso manual, generación de QR, página pública de verificación, subida de PDF, cálculo de vigencia |
| Fase 2 | Notificaciones automáticas de vencimiento, panel de reportes/exportación, roles más granulares, hash de integridad en el PDF |
| Fase 3 | Portal para que el cliente vea todos sus equipos (no solo uno por uno), posible apertura de API para integraciones externas |

## 12. Migración de datos existentes

- Los registros actuales en Word deberán traspasarse manualmente o vía una plantilla de importación (CSV) con los campos mínimos: serie, marca, modelo, cliente (nombre/RUT/contacto), último evento conocido, próxima fecha.
- Los certificados y OT ya emitidos (en PDF) pueden cargarse tal cual al historial del equipo correspondiente, sin necesidad de reescribir su contenido — basta con crear el evento y adjuntar el PDF existente.
- Se recomienda partir con los equipos activos/vigentes primero, e ir incorporando el histórico antiguo de forma progresiva si se requiere.

## 13. Criterios de aceptación (fase 1)

- [ ] Un administrador puede crear un equipo y el sistema genera su QR automáticamente.
- [ ] Un técnico puede registrar un evento de servicio y subir certificado + OT en PDF.
- [ ] Escaneando el QR (o ingresando la serie manualmente) se accede a una página pública que muestra el equipo, su estado y su historial, con acceso a los documentos.
- [ ] Una serie inexistente muestra un mensaje claro de "no encontrado", sin exponer errores técnicos.
- [ ] El estado de vigencia se calcula automáticamente sin intervención manual.
- [ ] Los documentos no son accesibles por URL directa sin pasar por el flujo de verificación.
