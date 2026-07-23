# Panel del Técnico — Especificación de diseño

**Fecha:** 2026-07-11
**Proyecto:** CalibraTrack — Plugin de WordPress
**Estado:** Aprobado para implementación

---

## 1. Contexto y objetivo

El plugin CalibraTrack gestiona calibraciones y mantenciones de equipos de fibra óptica. Hoy los técnicos solo pueden operar desde `wp-admin`, lo que implica darles acceso al panel de administración de WordPress. El objetivo es construir un panel propio del plugin accesible en `/tecnico/` donde el técnico pueda trabajar sin necesitar credenciales de wp-admin ni ver la interfaz de WordPress.

---

## 2. Alcance

**Incluido en esta especificación:**
- Página de login propia del plugin (`/tecnico/login`)
- Dashboard del técnico (`/tecnico/`)
- Formulario de registro y edición de eventos de servicio
- Lista de los propios eventos del técnico
- Lista de equipos (solo lectura)
- Generación automática del certificado PDF al guardar un evento
- Bloqueo de acceso a wp-admin para el rol `tecnico_calibracion`

**Excluido:**
- Creación de equipos o clientes (solo el administrador)
- Recuperación de contraseña (el admin la resetea desde wp-admin)
- App móvil nativa (el panel es responsive)

---

## 3. Arquitectura

### 3.1 Patrón general

Se replica el patrón de `CalibraTrack_Public`: una clase PHP `CalibraTrack_Tecnico` que registra rewrite rules, intercepta en `template_redirect` y carga templates propios del plugin. No depende del tema activo.

### 3.2 Estructura de archivos

```
calibratrack/
  includes/
    class-calibratrack-tecnico.php        ← routing, auth, lógica de guardado
    class-calibratrack-pdf-generator.php  ← generación de certificados PDF
  templates/tecnico/
    login.php
    dashboard.php
    nuevo-evento.php          ← mismo template que editar-evento.php
    lista-eventos.php
    evento-detalle.php        ← ver/editar evento existente
    lista-equipos.php
    _partials/
      header.php              ← barra de nav del panel técnico
      footer.php
      form-evento-fields.php  ← campos compartidos entre nuevo y editar
  templates/pdf/
    certificado.php           ← template HTML del certificado para Dompdf
  assets/
    css/tecnico.css
    css/certificado-pdf.css   ← estilos del PDF (logo, tablas, bandas de color)
    js/tecnico.js
```

### 3.3 URLs registradas

| URL | Template | Requiere auth |
|---|---|---|
| `/tecnico/login` | `login.php` | No (redirect a `/tecnico/` si ya logueado) |
| `/tecnico/` | `dashboard.php` | Sí |
| `/tecnico/nuevo-evento` | `nuevo-evento.php` | Sí |
| `/tecnico/eventos` | `lista-eventos.php` | Sí |
| `/tecnico/evento/{id}` | `evento-detalle.php` | Sí + autor |
| `/tecnico/equipos` | `lista-equipos.php` | Sí |
| `/tecnico/salir` | ─ (procesa logout) | Sí |
| `/tecnico/evento/{id}/certificado` | ─ (sirve PDF vía proxy) | Sí + autor |

### 3.4 Query vars

```
calibratrack_tecnico_page   = 1  (activa el panel)
calibratrack_tecnico_vista  = login | dashboard | nuevo-evento | eventos | evento | equipos | salir
calibratrack_tecnico_id     = {post_id}  (para /tecnico/evento/{id})
```

---

## 4. Autenticación y seguridad

### 4.1 Login

- Formulario POST a `/tecnico/login` con usuario, contraseña y nonce.
- Procesado con `wp_signon()` → cookie de sesión de WordPress.
- Si `remember_me` → cookie de larga duración.
- Error de credenciales: mensaje genérico "Usuario o contraseña incorrectos" (sin distinguir cuál falla).
- Sin límite de intentos en el MVP — el hosting/servidor puede tener protección a nivel de red.

### 4.2 Verificación en cada request

```php
if ( ! is_user_logged_in() || ! current_user_can( 'create_eventos_servicio' ) ) {
    wp_redirect( home_url( '/tecnico/login?redirect_to=' . rawurlencode( $current_url ) ) );
    exit;
}
```

### 4.3 Bloqueo de wp-admin

Hook `admin_init`: si el usuario tiene rol `tecnico_calibracion` → `wp_redirect( home_url('/tecnico/') )` + exit. Se aplica a todas las páginas de wp-admin.

### 4.4 Barra de administración de WordPress

`add_filter( 'show_admin_bar', '__return_false' )` para el rol técnico.

### 4.5 Autorización por recurso

- Editar evento ajeno: verifica `get_post_field('post_author', $evento_id) === get_current_user_id()`. Si no coincide → redirect a `/tecnico/eventos` con mensaje de error.
- Las capabilities de WP (`map_meta_cap`) son la segunda línea de defensa.

### 4.6 Formularios

- Nonce de WordPress en cada formulario (`wp_nonce_field` / `check_admin_referer`).
- Sanitización: `sanitize_text_field`, `absint`, `sanitize_email`, `wp_kses_post` según el campo.
- Subida de archivos: validación de MIME type real con `wp_check_filetype_and_ext()`. Permitidos: `image/jpeg`, `image/png`, `image/webp` (fotos); `application/pdf` (certificado, OT).

### 4.7 Validación de formularios

- Servidor: campos obligatorios (equipo, N° OT, tipo, fecha ejecución). Si falla → recarga con valores previos + mensaje por campo.
- Cliente (JS): solo UX, nunca como única barrera.

---

## 5. Pantallas y formularios

### 5.1 Login (`/tecnico/login`)

- Logo de TrueTech / CalibraTrack.
- Campos: usuario (`<input type="text">`), contraseña (`<input type="password">`).
- Checkbox "Recordarme".
- Botón "Entrar".
- Mensaje de error si credenciales incorrectas.
- Sin link a recuperar contraseña.

### 5.2 Dashboard (`/tecnico/`)

- Saludo con nombre del técnico y fecha actual.
- Botón prominente "Registrar nuevo evento".
- Tabla de los últimos 10 eventos propios: Fecha | N° OT | Equipo (serie + marca) | Tipo | Estado de vigencia | Acciones (editar).
- Link a "Ver todos los eventos" y "Ver equipos".

### 5.3 Formulario de evento (nuevo y editar)

Misma vista para crear y editar. En editar, los campos vienen pre-cargados.

| Campo | Control | Obligatorio |
|---|---|---|
| Equipo | `<select>` con búsqueda JS (filtra por serie/marca/modelo) | Sí |
| N° de OT | `<input text>` | Sí |
| Tipo de servicio | `<select>`: Calibración / Mantenimiento | Sí |
| Fecha de ejecución | `<input date>` | Sí |
| Próxima fecha de control | `<input date>` | No |
| Defectos/falla reportada por el cliente | `<textarea>` | No |
| Servicio realizado / descripción del trabajo | `<textarea>` | No |
| Observaciones | `<textarea>` | No |
| Garantía | `<checkbox>` | No |
| Días de garantía | `<input number>` (visible solo si garantía activa) | Condicional |
| Evidencia fotográfica | `<input file multiple>` (solo imágenes) | No |
| Certificado PDF | `<input file>` (solo PDF) — alternativa a generación automática | No |
| OT PDF | `<input file>` (solo PDF) | No |
| Ítems de costo | Repetidor: detalle + cantidad + precio unitario | No |

Al guardar: subtotal, IVA (19%) y total se calculan en servidor. El certificado PDF se genera automáticamente (ver §6). El evento queda en estado `publish`.

### 5.4 Lista de eventos (`/tecnico/eventos`)

- Tabla: Fecha | N° OT | Equipo | Tipo | Estado de vigencia | PDF generado (sí/no) | Editar.
- Paginación: 20 por página.

### 5.5 Lista de equipos (`/tecnico/equipos`)

- Solo lectura: Serie | Marca | Modelo | Tipo | Cliente | Estado.
- Link "Ver verificación pública" en cada fila → `/verificar/{serie}`.
- Sin acciones de edición ni creación.

---

## 6. Generación automática de certificados PDF

### 6.1 Referencia

El certificado real de TrueTech SpA (Certificado Mantenimiento FUSIONADORA GRANDWAY 2025TT2107 sn23060017) define la estructura exacta a replicar.

### 6.2 Contenido del certificado

El PDF generado reproduce fielmente el documento real:

**Encabezado:**
- Logo de TrueTech (imagen configurable vía `get_option('calibratrack_logo_id')`)
- Título: "CERTIFICADO DE MANTENIMIENTO" o "CERTIFICADO DE CALIBRACIÓN" según tipo
- Datos de la empresa emisora: TrueTech SpA, RUT, dirección, teléfono (configurable vía opciones del plugin)
- N° de OT y fecha

**Sección: Datos del cliente**
- Nombre/empresa, RUT, contacto (nombre + teléfono), dirección, correo electrónico

**Sección: Características del equipo**
- Tabla: Marca | Modelo | Serie | Detalle (tipo de servicio)

**Sección: Garantía**
- Checkbox marcado SI/NO según el campo del evento. Si aplica, agrega los días.

**Sección: Defectos encontrados por el cliente**
- Texto del campo `falla_reportada`

**Sección: Servicio realizado / Observaciones**
- Texto del campo `descripcion_trabajo` + `observaciones`

**Firma:**
- Nombre del técnico responsable
- Cargo: "Servicio Técnico"
- Empresa: TrueTech SpA
- Imagen de firma del técnico (configurable por usuario: `calibratrack_firma_id` en user meta)
- Logo de TrueTech en el pie

**Barras decorativas:**
- Banda de color TrueTech en la parte superior e inferior (color configurado en opciones)

### 6.3 Librería PDF

Se usa **Dompdf** (versión compatible con PHP ^7.4) instalado vía Composer. Dompdf convierte HTML+CSS a PDF, lo que permite mantener el template del certificado como un archivo PHP/HTML sin lógica de coordenadas.

**Por qué Dompdf:**
- Compatible con PHP 7.4 y MariaDB 10.6 (no tiene dependencias de base de datos)
- El template HTML es fácil de mantener y editar visualmente
- Soporta imágenes embebidas (logo, firma) via base64 o ruta absoluta
- Licencia LGPL, sin restricciones para uso en plugins de WordPress

**Alternativa descartada:** TCPDF (API de coordenadas más compleja para mantener). FPDF (no soporta CSS).

### 6.4 Flujo de generación

1. Al guardar un evento (`wp_insert_post` / `wp_update_post` exitoso):
2. `CalibraTrack_PDF_Generator::generate_certificado( $evento_id )` se llama automáticamente.
3. El generador carga el template `templates/pdf/certificado.php` con los datos del evento.
4. Dompdf renderiza el HTML a PDF en memoria.
5. El PDF se guarda como attachment en la Media Library via `wp_upload_bits` + `wp_insert_attachment`.
6. El attachment ID se guarda en `calibratrack_certificado_pdf` del evento (sobrescribe si ya existía).
7. Si la generación falla → el evento igual se guarda; se registra el error en `error_log` y se muestra aviso al técnico.

### 6.5 Template del certificado

```
calibratrack/templates/pdf/certificado.php
calibratrack/assets/css/certificado-pdf.css
```

El template recibe las variables del evento y las embebe en HTML. Dompdf las convierte a PDF. El CSS define fuentes, bordes de tabla, colores de las bandas decorativas y posición del logo/firma.

### 6.6 Descarga desde el panel del técnico

En la lista de eventos: si el evento tiene certificado generado → link "Descargar PDF" que llega a `/tecnico/evento/{id}/certificado`. El plugin verifica que el técnico sea el autor del evento, luego sirve el PDF vía `readfile()` con headers correctos (`Content-Type: application/pdf`, `Content-Disposition: inline`). No se expone la URL directa del archivo en uploads.

---

## 7. Diseño visual

- **Mobile-first** — el técnico usa celular en terreno y computador en la oficina.
- Layout de una columna en móvil, sidebar colapsable en desktop.
- Paleta: colores de TrueTech (azul/cyan sobre fondo blanco), consistente con el certificado.
- Tipografía: sistema (sans-serif nativa del dispositivo).
- Formularios: campos grandes, labels visibles, botones de ancho completo en móvil.
- El archivo `tecnico.css` es independiente del tema activo — el panel no llama a `get_header()` / `get_footer()` del tema.

---

## 8. Integración con el plugin existente

- `CalibraTrack_Tecnico::init()` se llama desde `calibratrack_plugins_loaded()` igual que `CalibraTrack_Public::init()`.
- Reutiliza las mismas meta keys de `CalibraTrack_Meta_Keys`.
- Reutiliza `CalibraTrack_Helpers::calcular_totales_costo()` y `CalibraTrack_DB::save_items_costo()`.
- Reutiliza `CalibraTrack_DB::get_items_costo()` para pre-cargar ítems al editar.
- `CalibraTrack_PDF_Generator` se carga siempre (igual que `CalibraTrack_QR`), verifica disponibilidad de Dompdf internamente.
- El hook `admin_init` para bloquear wp-admin se agrega en `CalibraTrack_Tecnico::init()`.

---

## 9. Opciones de configuración del plugin (nuevas)

Estas opciones se gestionan desde wp-admin (solo administrador):

| Opción | Descripción |
|---|---|
| `calibratrack_logo_id` | Attachment ID del logo de la empresa para el PDF |
| `calibratrack_empresa_nombre` | Nombre de la empresa emisora (ej. TrueTech SpA) |
| `calibratrack_empresa_rut` | RUT de la empresa |
| `calibratrack_empresa_direccion` | Dirección |
| `calibratrack_empresa_telefono` | Teléfono |
| `calibratrack_pdf_color_primario` | Color de las bandas decorativas (hex, default: `#00AEEF`) |

Por usuario técnico (user meta):

| Meta key | Descripción |
|---|---|
| `calibratrack_firma_id` | Attachment ID de la imagen de firma del técnico |
| `calibratrack_cargo` | Cargo a mostrar en el certificado (ej. "Servicio Técnico") |

---

## 10. Decisiones registradas

| # | Decisión | Justificación |
|---|---|---|
| D-11 | Panel técnico implementado como frontend PHP con rewrite rules | Consistente con patrón existente de `/verificar/`, sin build tools, PHP 7.4 |
| D-12 | Login propio en `/tecnico/login` con `wp_signon()` | El técnico nunca toca wp-admin; reutiliza usuarios WP sin sistema de auth propio |
| D-13 | Generación PDF con Dompdf vía template HTML | Template mantenible en HTML/CSS; librería compatible con PHP 7.4 |
| D-14 | PDF se regenera automáticamente al guardar un evento | Garantiza que el PDF siempre refleja los datos actuales del sistema (antifraude) |
| D-15 | PDF se sirve vía proxy PHP, no URL directa | Verifica autoría antes de servir; evita URLs adivinables en uploads |

---

## 11. Fuera de alcance (fases futuras)

- Recuperación de contraseña desde el panel del técnico
- Generación automática de la OT PDF (formato diferente al certificado; se define en fase 2)
- Firma digital del certificado (evaluado en §11 de la especificación técnica principal)
- Notificaciones push o email al técnico cuando le asignan un equipo
