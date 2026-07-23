# Nuevo Flujo OI→OT con Notificaciones y Separación de Roles — Plan de Implementación

> **Para agentes agentic:** SUB-SKILL REQUERIDA: Usar superpowers:subagent-driven-development (recomendado) o superpowers:executing-plans para ejecutar este plan tarea por tarea. Los pasos usan sintaxis checkbox (`- [ ]`) para seguimiento.

**Goal:** Implementar el nuevo flujo administrativo donde el admin crea Órdenes de Ingreso (que notifican al técnico) y luego Órdenes de Trabajo (que notifican al cliente con montos), separar completamente las vistas del técnico (sin montos), agregar recordatorios de vencimiento al cliente, y simplificar el certificado PDF.

**Architecture:** El CPT `evento_servicio` existente se extiende con una nueva meta key `calibratrack_tipo_documento` ('ingreso' | 'ot') para distinguir los dos tipos de documento sin crear un segundo CPT. Las OTs se vinculan a su OI mediante `calibratrack_ingreso_relacionado_id`. El admin gestiona OIs y OTs desde wp-admin (actualmente bloqueado para todos los usuarios en evento_servicio — se habilita solo para admins). El técnico sigue usando el panel /tecnico/ pero únicamente para ver sus OTs asignadas y marcar trabajo como completado, sin visibilidad de montos.

**Tech Stack:** PHP 7.4, WordPress 6.8.5, MariaDB 10.6.25, wp_mail() (SMTP), WP Cron, DomPDF (ya instalado)

## Restricciones Globales

- PHP 7.4 estricto: sin `enum`, `match`, `?->`, constructor promotion, union types, named args.
- Todo texto visible al usuario usa `__()` / `esc_html__()` con text domain `calibratrack`.
- Meta keys SOLO a través de `CalibraTrack_Meta_Keys::CONSTANTE` — nunca strings literales.
- Seguridad: verificar `check_admin_referer()` / `wp_verify_nonce()` antes de procesar POST.
- El técnico NUNCA ve montos, subtotales, IVA, ni ítems de costo en ninguna pantalla.
- No exponer RUT, teléfono ni correo del cliente en la página pública de verificación.
- La generación de PDFs y envío de correos son no-críticos: si fallan, el post igual se guarda.
- WordPress Coding Standards (WPCS): sanitizar inputs, escapar outputs.

---

## Mapa de Archivos

### Archivos a MODIFICAR:
- `calibratrack/includes/class-calibratrack-meta-keys.php` — Agregar 2 nuevas constantes
- `calibratrack/includes/class-calibratrack-cpt-evento-servicio.php` — Cambiar `show_in_menu` para admins
- `calibratrack/admin/class-calibratrack-admin.php` — Habilitar evento_servicio en wp-admin para admin, agregar metaboxes OI/OT, disparar correos correctos, registrar nueva opción de ajustes
- `calibratrack/includes/class-calibratrack-mailer.php` — Agregar `send_oi_a_tecnico()`, modificar `build_email_ot()` para incluir montos
- `calibratrack/includes/class-calibratrack-cron.php` — Agregar recordatorio al cliente (días configurables)
- `calibratrack/templates/tecnico/lista-eventos.php` — Quitar botón "Nuevo evento", filtrar solo OTs del técnico
- `calibratrack/templates/tecnico/_partials/form-evento-fields.php` — Ocultar sección ítems de costo
- `calibratrack/templates/pdf/certificado.php` — Eliminar secciones Garantía y Defectos encontrados

### Archivos a CREAR:
- `calibratrack/templates/email/oi-a-tecnico.php` — Template HTML del correo al técnico cuando llega una OI

---

## Task 1: Extender Meta Keys y Registrar Nueva Opción de Ajuste

**Files:**
- Modify: `calibratrack/includes/class-calibratrack-meta-keys.php`
- Modify: `calibratrack/admin/class-calibratrack-admin.php` (solo la función `register_settings`)

**Interfaces:**
- Produces: `CalibraTrack_Meta_Keys::EVENTO_TIPO_DOCUMENTO` → string `'ingreso'|'ot'`
- Produces: `CalibraTrack_Meta_Keys::EVENTO_INGRESO_RELACIONADO_ID` → int (post ID de la OI relacionada)
- Produces: opción WP `calibratrack_dias_recordatorio_cliente` → int (default `30`)
- Produces: opción WP `calibratrack_recordatorio_cliente_enabled` → bool (default `true`)

- [ ] **Paso 1.1: Agregar las dos constantes en `class-calibratrack-meta-keys.php`**

  Abrir `calibratrack/includes/class-calibratrack-meta-keys.php`. Al final de la sección `// ─── CPT: evento_servicio`, antes del comentario `// NOTA sobre items_costo:`, agregar:

  ```php
  /**
   * Tipo de documento del evento: 'ingreso' (Orden de Ingreso) o 'ot' (Orden de Trabajo).
   * Valor por defecto: 'ot' — garantiza compatibilidad con eventos existentes.
   * Tipo: string.
   */
  const EVENTO_TIPO_DOCUMENTO = 'calibratrack_tipo_documento';

  /**
   * ID del post (evento_servicio tipo 'ingreso') al que está vinculada esta OT.
   * Solo aplica cuando EVENTO_TIPO_DOCUMENTO = 'ot'.
   * Tipo: integer (post ID). 0 si no tiene OI vinculada.
   */
  const EVENTO_INGRESO_RELACIONADO_ID = 'calibratrack_ingreso_relacionado_id';
  ```

- [ ] **Paso 1.2: Registrar las dos opciones nuevas en `register_settings`**

  En `calibratrack/admin/class-calibratrack-admin.php`, dentro del método `register_settings()`, agregar al final (antes del cierre `}`):

  ```php
  register_setting(
      'calibratrack_settings',
      'calibratrack_recordatorio_cliente_enabled',
      array(
          'type'              => 'boolean',
          'default'           => true,
          'sanitize_callback' => 'rest_sanitize_boolean',
      )
  );

  register_setting(
      'calibratrack_settings',
      'calibratrack_dias_recordatorio_cliente',
      array(
          'type'              => 'integer',
          'default'           => 30,
          'sanitize_callback' => 'absint',
      )
  );
  ```

- [ ] **Paso 1.3: Agregar los campos en la página de ajustes del plugin (sección de correo)**

  En `class-calibratrack-admin.php`, localizar el método `render_settings_page()` (o equivalente que renderiza los ajustes del plugin). En la sección de configuración de correo, agregar:

  ```php
  <tr>
      <th scope="row">
          <label for="calibratrack-recordatorio-cliente-enabled">
              <?php esc_html_e( 'Recordatorio de vencimiento al cliente', 'calibratrack' ); ?>
          </label>
      </th>
      <td>
          <input type="checkbox" id="calibratrack-recordatorio-cliente-enabled"
              name="calibratrack_recordatorio_cliente_enabled" value="1"
              <?php checked( (bool) get_option( 'calibratrack_recordatorio_cliente_enabled', true ) ); ?>>
          <label for="calibratrack-recordatorio-cliente-enabled">
              <?php esc_html_e( 'Enviar correo automático al cliente antes del vencimiento', 'calibratrack' ); ?>
          </label>
      </td>
  </tr>
  <tr>
      <th scope="row">
          <label for="calibratrack-dias-recordatorio">
              <?php esc_html_e( 'Días de anticipación del recordatorio', 'calibratrack' ); ?>
          </label>
      </th>
      <td>
          <input type="number" id="calibratrack-dias-recordatorio"
              name="calibratrack_dias_recordatorio_cliente"
              value="<?php echo esc_attr( (int) get_option( 'calibratrack_dias_recordatorio_cliente', 30 ) ); ?>"
              min="1" max="365" step="1" class="small-text">
          <p class="description">
              <?php esc_html_e( 'Número de días antes del vencimiento para enviar el recordatorio. Por defecto: 30.', 'calibratrack' ); ?>
          </p>
      </td>
  </tr>
  ```

- [ ] **Paso 1.4: Verificar que las constantes existen y son únicas**

  ```bash
  grep -r "EVENTO_TIPO_DOCUMENTO\|EVENTO_INGRESO_RELACIONADO_ID\|calibratrack_tipo_documento\|calibratrack_ingreso_relacionado_id" \
    /home/jvalenzuela/Desarollo/truetech/calibratrack/includes/class-calibratrack-meta-keys.php
  ```

  Expected: exactamente 2 bloques de comentario + 2 constantes `const`.

---

## Task 2: Habilitar wp-admin para Eventos de Servicio (solo Admins)

**Files:**
- Modify: `calibratrack/includes/class-calibratrack-cpt-evento-servicio.php`
- Modify: `calibratrack/admin/class-calibratrack-admin.php`

**Interfaces:**
- Consumes: `current_user_can('manage_options')` para distinguir admin de técnico
- Produces: Admins pueden acceder a `/wp-admin/edit.php?post_type=evento_servicio`
- Produces: Técnicos siguen siendo redirigidos a /tecnico/

- [ ] **Paso 2.1: Permitir que el CPT evento_servicio aparezca en el menú de wp-admin**

  En `calibratrack/includes/class-calibratrack-cpt-evento-servicio.php`, cambiar en `$args`:

  ```php
  // ANTES:
  'show_in_menu'        => false,   // Gestionado desde /tecnico/ — no se expone en wp-admin.

  // DESPUÉS:
  'show_in_menu'        => 'calibratrack',  // Aparece bajo el menú CalibraTrack en wp-admin.
  ```

- [ ] **Paso 2.2: Modificar `redirect_evento_admin_screens` para permitir acceso a admins**

  En `calibratrack/admin/class-calibratrack-admin.php`, localizar el método `redirect_evento_admin_screens()` y reemplazarlo por:

  ```php
  /**
   * Redirige a /tecnico/ si un técnico intenta acceder a las pantallas
   * de evento_servicio en wp-admin. Los administradores pueden acceder libremente.
   *
   * @return void
   */
  public static function redirect_evento_admin_screens() {
      // Administradores pueden gestionar eventos desde wp-admin.
      if ( current_user_can( 'manage_options' ) ) {
          return;
      }

      $screen = get_current_screen();
      if ( $screen && 'evento_servicio' === $screen->post_type ) {
          wp_redirect( home_url( '/tecnico/' ) );
          exit;
      }
  }
  ```

- [ ] **Paso 2.3: Verificar que el método existe y fue modificado**

  ```bash
  grep -A 15 "redirect_evento_admin_screens" \
    /home/jvalenzuela/Desarollo/truetech/calibratrack/admin/class-calibratrack-admin.php
  ```

  Expected: el método retorna early si `current_user_can('manage_options')`.

---

## Task 3: Metaboxes para Tipo de Documento (OI / OT) y Vinculación OI→OT

**Files:**
- Modify: `calibratrack/admin/class-calibratrack-admin.php`

**Interfaces:**
- Consumes: `CalibraTrack_Meta_Keys::EVENTO_TIPO_DOCUMENTO`, `CalibraTrack_Meta_Keys::EVENTO_INGRESO_RELACIONADO_ID`
- Produces: Metabox "Tipo de documento" visible en wp-admin al editar `evento_servicio`
- Produces: Metabox "Ingreso relacionado" visible en wp-admin solo cuando tipo = 'ot'
- Produces: Al guardar OI → llama a `CalibraTrack_Mailer::send_oi_a_tecnico($evento_id)` si es nuevo
- Produces: Al guardar OT → llama a `CalibraTrack_Mailer::send_ot_a_cliente($evento_id)` si es nueva

- [ ] **Paso 3.1: Registrar los dos metaboxes nuevos**

  En `class-calibratrack-admin.php`, dentro del método `register_metaboxes()`, agregar:

  ```php
  add_meta_box(
      'calibratrack_tipo_documento',
      __( 'Tipo de documento', 'calibratrack' ),
      array( __CLASS__, 'render_metabox_tipo_documento' ),
      'evento_servicio',
      'side',
      'high'
  );

  add_meta_box(
      'calibratrack_ingreso_relacionado',
      __( 'Orden de Ingreso relacionada', 'calibratrack' ),
      array( __CLASS__, 'render_metabox_ingreso_relacionado' ),
      'evento_servicio',
      'side',
      'default'
  );
  ```

- [ ] **Paso 3.2: Implementar `render_metabox_tipo_documento()`**

  Agregar el método en `class-calibratrack-admin.php`:

  ```php
  /**
   * Renderiza el metabox para seleccionar tipo de documento: OI o OT.
   *
   * @param WP_Post $post Post actual.
   * @return void
   */
  public static function render_metabox_tipo_documento( $post ) {
      wp_nonce_field( 'calibratrack_tipo_doc_' . $post->ID, 'calibratrack_tipo_doc_nonce' );
      $tipo = (string) get_post_meta( $post->ID, CalibraTrack_Meta_Keys::EVENTO_TIPO_DOCUMENTO, true );
      if ( empty( $tipo ) ) {
          $tipo = 'ot'; // Valor por defecto para compatibilidad con eventos existentes.
      }
      ?>
      <p>
          <label>
              <input type="radio" name="calibratrack_tipo_documento" value="ingreso"
                  <?php checked( $tipo, 'ingreso' ); ?>>
              <?php esc_html_e( 'Orden de Ingreso (OI) — notifica al técnico', 'calibratrack' ); ?>
          </label>
      </p>
      <p>
          <label>
              <input type="radio" name="calibratrack_tipo_documento" value="ot"
                  <?php checked( $tipo, 'ot' ); ?>>
              <?php esc_html_e( 'Orden de Trabajo (OT) — notifica al cliente', 'calibratrack' ); ?>
          </label>
      </p>
      <p style="margin-top:8px;font-size:11px;color:#666;">
          <?php esc_html_e( 'OI: aviso automático al técnico. OT: aviso automático al cliente con montos.', 'calibratrack' ); ?>
      </p>
      <?php
  }
  ```

- [ ] **Paso 3.3: Implementar `render_metabox_ingreso_relacionado()`**

  Agregar el método en `class-calibratrack-admin.php`:

  ```php
  /**
   * Renderiza el metabox para vincular una OT a su Orden de Ingreso.
   *
   * @param WP_Post $post Post actual.
   * @return void
   */
  public static function render_metabox_ingreso_relacionado( $post ) {
      $ingreso_id = (int) get_post_meta( $post->ID, CalibraTrack_Meta_Keys::EVENTO_INGRESO_RELACIONADO_ID, true );

      // Obtener todas las OIs existentes para el select.
      $ois = get_posts( array(
          'post_type'      => 'evento_servicio',
          'post_status'    => 'publish',
          'posts_per_page' => -1,
          'orderby'        => 'date',
          'order'          => 'DESC',
          'meta_query'     => array(
              array(
                  'key'   => CalibraTrack_Meta_Keys::EVENTO_TIPO_DOCUMENTO,
                  'value' => 'ingreso',
              ),
          ),
      ) );
      ?>
      <p>
          <select name="calibratrack_ingreso_relacionado_id" style="width:100%;">
              <option value="0"><?php esc_html_e( '— Sin OI vinculada —', 'calibratrack' ); ?></option>
              <?php foreach ( $ois as $oi ) :
                  $numero_oi = get_post_meta( $oi->ID, CalibraTrack_Meta_Keys::EVENTO_NUMERO_OT, true );
                  $serie_oi  = '';
                  $eq_id = (int) get_post_meta( $oi->ID, CalibraTrack_Meta_Keys::EVENTO_EQUIPO_ID, true );
                  if ( $eq_id ) {
                      $serie_oi = get_post_meta( $eq_id, CalibraTrack_Meta_Keys::EQUIPO_SERIE, true );
                  }
              ?>
              <option value="<?php echo esc_attr( $oi->ID ); ?>"
                  <?php selected( $ingreso_id, $oi->ID ); ?>>
                  <?php echo esc_html( $numero_oi . ( $serie_oi ? ' — ' . $serie_oi : '' ) . ' (#' . $oi->ID . ')' ); ?>
              </option>
              <?php endforeach; ?>
          </select>
      </p>
      <p style="font-size:11px;color:#666;">
          <?php esc_html_e( 'Vincula esta OT a la Orden de Ingreso correspondiente.', 'calibratrack' ); ?>
      </p>
      <?php
  }
  ```

- [ ] **Paso 3.4: Guardar los nuevos campos en `save_evento_meta()` y disparar correos correctos**

  En `class-calibratrack-admin.php`, localizar el método `save_evento_meta()`. Dentro de él, ANTES de la línea donde se guardan los metadatos del evento (buscar la sección donde se hace `update_post_meta( $post_id, ...)`), agregar la lógica de tipo_documento:

  ```php
  // Verificar nonce del tipo de documento.
  $tipo_doc_nonce = isset( $_POST['calibratrack_tipo_doc_nonce'] )
      ? sanitize_text_field( wp_unslash( $_POST['calibratrack_tipo_doc_nonce'] ) )
      : '';
  if ( ! wp_verify_nonce( $tipo_doc_nonce, 'calibratrack_tipo_doc_' . $post_id ) ) {
      // Si no hay nonce, no actualizar tipo_doc (compatibilidad con guardados programáticos).
      return;
  }

  $tipo_doc_raw = isset( $_POST['calibratrack_tipo_documento'] )
      ? sanitize_key( wp_unslash( $_POST['calibratrack_tipo_documento'] ) )
      : 'ot';
  $tipo_doc = in_array( $tipo_doc_raw, array( 'ingreso', 'ot' ), true ) ? $tipo_doc_raw : 'ot';

  // Detectar si es la primera vez que se guarda este tipo_doc (post nuevo).
  $tipo_doc_anterior = (string) get_post_meta( $post_id, CalibraTrack_Meta_Keys::EVENTO_TIPO_DOCUMENTO, true );
  $es_primera_vez    = empty( $tipo_doc_anterior );

  update_post_meta( $post_id, CalibraTrack_Meta_Keys::EVENTO_TIPO_DOCUMENTO, $tipo_doc );

  // Ingreso relacionado (solo relevante para OTs).
  $ingreso_id = absint( isset( $_POST['calibratrack_ingreso_relacionado_id'] ) ? $_POST['calibratrack_ingreso_relacionado_id'] : 0 );
  update_post_meta( $post_id, CalibraTrack_Meta_Keys::EVENTO_INGRESO_RELACIONADO_ID, $ingreso_id );

  // Disparar correos según el tipo de documento (solo en la primera vez que se guarda).
  if ( $es_primera_vez ) {
      if ( 'ingreso' === $tipo_doc && class_exists( 'CalibraTrack_Mailer' ) ) {
          CalibraTrack_Mailer::send_oi_a_tecnico( $post_id );
      } elseif ( 'ot' === $tipo_doc && class_exists( 'CalibraTrack_Mailer' ) ) {
          // El correo al cliente se dispara desde el hook existente en procesar_guardar_evento.
          // Aquí lo disparamos cuando se crea desde wp-admin.
          CalibraTrack_Mailer::send_ot_a_cliente( $post_id );
      }
  }
  ```

  > **Nota**: Verificar el orden de ejecución de la función. El bloque anterior debe ir DESPUÉS del nonce del evento principal (ya existente) para no cortarlo prematuramente.

- [ ] **Paso 3.5: Verificar en WP local que el metabox aparece al editar un evento**

  Navegar a wp-admin → CalibraTrack → Eventos de servicio → Nuevo. Verificar que aparecen los dos metaboxes "Tipo de documento" y "Orden de Ingreso relacionada" en el panel lateral derecho.

---

## Task 4: Mailer — Email al Técnico cuando llega una OI

**Files:**
- Create: `calibratrack/templates/email/oi-a-tecnico.php`
- Modify: `calibratrack/includes/class-calibratrack-mailer.php`

**Interfaces:**
- Consumes: `$evento_id` (int, post ID del evento_servicio tipo 'ingreso')
- Consumes: `CalibraTrack_Meta_Keys::EVENTO_TECNICO_RESPONSABLE` → user ID del técnico
- Produces: `CalibraTrack_Mailer::send_oi_a_tecnico(int $evento_id): bool`

- [ ] **Paso 4.1: Crear el template HTML del correo al técnico**

  Crear `calibratrack/templates/email/oi-a-tecnico.php` con el siguiente contenido:

  > **Nota**: Este archivo NO es un template cargado con `include`. Es solo referencia visual. El HTML se construye directamente en `CalibraTrack_Mailer::build_email_oi_tecnico()`. No crear el archivo — el HTML va en el método PHP.

- [ ] **Paso 4.2: Agregar método `get_datos_tecnico()` en CalibraTrack_Mailer**

  En `calibratrack/includes/class-calibratrack-mailer.php`, agregar un método helper privado:

  ```php
  /**
   * Obtiene datos del técnico responsable de un evento.
   *
   * @param int $evento_id Post ID del evento_servicio.
   * @return array|false Array con correo, nombre del técnico o false si no tiene técnico asignado.
   */
  private static function get_datos_tecnico( $evento_id ) {
      $tecnico_id = (int) get_post_meta( $evento_id, CalibraTrack_Meta_Keys::EVENTO_TECNICO_RESPONSABLE, true );
      if ( ! $tecnico_id ) {
          return false;
      }

      $tecnico_user = get_user_by( 'id', $tecnico_id );
      if ( ! $tecnico_user || ! is_email( $tecnico_user->user_email ) ) {
          return false;
      }

      return array(
          'correo'  => $tecnico_user->user_email,
          'nombre'  => $tecnico_user->display_name,
      );
  }
  ```

- [ ] **Paso 4.3: Agregar método público `send_oi_a_tecnico()` en CalibraTrack_Mailer**

  En `calibratrack/includes/class-calibratrack-mailer.php`, agregar después del método `send_ot_a_cliente()`:

  ```php
  /**
   * EMAIL — Aviso al técnico de nueva Orden de Ingreso asignada.
   * Se envía cuando el administrador crea una OI y le asigna un técnico.
   * NO incluye montos. El técnico solo recibe información técnica del equipo.
   *
   * @param int $evento_id Post ID del evento_servicio (tipo 'ingreso').
   * @return bool
   */
  public static function send_oi_a_tecnico( $evento_id ) {
      if ( ! (bool) get_option( 'calibratrack_email_enabled', true ) ) {
          return false;
      }

      $datos_tecnico = self::get_datos_tecnico( $evento_id );
      if ( ! $datos_tecnico ) {
          return false; // Sin técnico asignado — no enviar.
      }

      $datos = self::get_datos_correo( $evento_id );
      if ( ! $datos ) {
          return false;
      }

      $asunto = sprintf(
          /* translators: 1: número OI 2: tipo servicio 3: marca modelo */
          __( '[Nueva asignación] %1$s — %2$s de equipo %3$s', 'calibratrack' ),
          $datos['numero_ot'],
          $datos['tipo_label'],
          $datos['marca'] . ' ' . $datos['modelo']
      );

      $empresa  = (string) get_option( 'calibratrack_empresa_nombre', 'TrueTech SpA' );
      $from     = (string) get_option( 'calibratrack_email_from', get_option( 'admin_email' ) );
      $color    = esc_attr( $datos['color_primario'] );

      // Datos adicionales del evento.
      $falla      = (string) get_post_meta( $evento_id, CalibraTrack_Meta_Keys::EVENTO_FALLA_REPORTADA, true );
      $fecha_raw  = (string) get_post_meta( $evento_id, CalibraTrack_Meta_Keys::EVENTO_FECHA_EJECUCION, true );
      $fecha_dt   = $fecha_raw ? DateTime::createFromFormat( 'Y-m-d', $fecha_raw ) : null;
      $fecha_fmt  = $fecha_dt ? $fecha_dt->format( 'd/m/Y' ) : '—';

      $nombre_tecnico = esc_html( $datos_tecnico['nombre'] );
      $marca_modelo   = esc_html( $datos['marca'] . ' ' . $datos['modelo'] );
      $serie          = esc_html( $datos['serie'] );
      $tipo_label     = esc_html( $datos['tipo_label'] );
      $numero_ot      = esc_html( $datos['numero_ot'] );

      // Construir HTML del correo.
      $html  = '<!DOCTYPE html><html lang="es"><head><meta charset="UTF-8">';
      $html .= '<meta name="viewport" content="width=device-width,initial-scale=1.0"></head>';
      $html .= '<body style="margin:0;padding:0;font-family:Arial,sans-serif;background:#f3f4f6;">';
      $html .= '<table width="100%" cellpadding="0" cellspacing="0" style="background:#f3f4f6;">';
      $html .= '<tr><td align="center" style="padding:32px 16px;">';
      $html .= '<table width="600" cellpadding="0" cellspacing="0" style="max-width:600px;background:#fff;border-radius:8px;overflow:hidden;">';

      $html .= '<tr><td style="background:' . $color . ';height:6px;"></td></tr>';
      $html .= '<tr><td style="padding:24px 32px 12px;">';
      $html .= '<p style="margin:0;font-size:22px;font-weight:bold;color:' . $color . ';">' . esc_html( $empresa ) . '</p>';
      $html .= '<p style="margin:4px 0 0;font-size:15px;color:#374151;font-weight:bold;">Nueva asignación de trabajo</p>';
      $html .= '</td></tr>';

      $html .= '<tr><td style="padding:8px 32px 24px;">';
      $html .= '<p style="color:#374151;">Estimado/a <strong>' . $nombre_tecnico . '</strong>,</p>';
      $html .= '<p style="color:#374151;">Se te ha asignado una nueva <strong>Orden de Ingreso</strong>. ';
      $html .= 'A continuación encontrarás los antecedentes del trabajo:</p>';

      // Tabla de datos del equipo.
      $html .= '<table width="100%" cellpadding="0" cellspacing="0" style="border:1px solid #e5e7eb;border-radius:6px;margin:16px 0;font-size:14px;">';
      $html .= '<tr style="background:#f9fafb;"><td style="padding:8px 12px;font-weight:bold;width:160px;color:#374151;">N° Ingreso</td>';
      $html .= '<td style="padding:8px 12px;color:#111827;">' . $numero_ot . '</td></tr>';
      $html .= '<tr><td style="padding:8px 12px;font-weight:bold;color:#374151;">Tipo de servicio</td>';
      $html .= '<td style="padding:8px 12px;color:#111827;">' . $tipo_label . '</td></tr>';
      $html .= '<tr style="background:#f9fafb;"><td style="padding:8px 12px;font-weight:bold;color:#374151;">Equipo</td>';
      $html .= '<td style="padding:8px 12px;color:#111827;">' . $marca_modelo . '</td></tr>';
      $html .= '<tr><td style="padding:8px 12px;font-weight:bold;color:#374151;">N° de Serie</td>';
      $html .= '<td style="padding:8px 12px;color:#111827;font-family:monospace;">' . $serie . '</td></tr>';
      $html .= '<tr style="background:#f9fafb;"><td style="padding:8px 12px;font-weight:bold;color:#374151;">Fecha</td>';
      $html .= '<td style="padding:8px 12px;color:#111827;">' . esc_html( $fecha_fmt ) . '</td></tr>';
      $html .= '</table>';

      if ( ! empty( $falla ) ) {
          $html .= '<p style="margin:8px 0 4px;font-weight:bold;color:#374151;">Falla / defecto reportado por el cliente:</p>';
          $html .= '<div style="border:1px solid #e5e7eb;border-radius:4px;padding:10px 14px;background:#fafafa;color:#374151;font-size:13px;white-space:pre-wrap;">';
          $html .= esc_html( $falla );
          $html .= '</div>';
      }

      $html .= '<p style="color:#6b7280;font-size:13px;margin-top:16px;">Ingresa al sistema para más detalles o contacta al administrador con cualquier consulta.</p>';
      $html .= '</td></tr>';
      $html .= '<tr><td style="background:' . $color . ';height:4px;"></td></tr>';
      $html .= '<tr><td style="padding:16px 32px;text-align:center;color:#9ca3af;font-size:12px;">';
      $html .= '© ' . gmdate( 'Y' ) . ' ' . esc_html( $empresa ) . ' — Este correo fue generado automáticamente.';
      $html .= '</td></tr>';
      $html .= '</table></td></tr></table></body></html>';

      $headers = array(
          'Content-Type: text/html; charset=UTF-8',
          'From: ' . $empresa . ' <' . $from . '>',
      );

      return wp_mail( $datos_tecnico['correo'], $asunto, $html, $headers );
  }
  ```

- [ ] **Paso 4.4: Verificar que el método `send_oi_a_tecnico` está en la clase**

  ```bash
  grep -n "send_oi_a_tecnico" \
    /home/jvalenzuela/Desarollo/truetech/calibratrack/includes/class-calibratrack-mailer.php
  ```

  Expected: al menos 2 líneas (declaración + doc block).

---

## Task 5: Mailer — Agregar Montos al Email de OT al Cliente

**Files:**
- Modify: `calibratrack/includes/class-calibratrack-mailer.php`

**Interfaces:**
- Consumes: `$evento_id` (int)
- Consumes: `get_post_meta($evento_id, 'calibratrack_subtotal', true)` → float
- Consumes: `get_post_meta($evento_id, 'calibratrack_iva', true)` → float
- Consumes: `get_post_meta($evento_id, 'calibratrack_total', true)` → float
- Produces: `build_email_ot()` — ahora incluye tabla de ítems y totales

- [ ] **Paso 5.1: Modificar `get_datos_correo()` para incluir montos**

  En `class-calibratrack-mailer.php`, en el método `get_datos_correo()`, agregar al array de retorno:

  ```php
  // Al final del array que retorna get_datos_correo(), agregar:
  'subtotal' => (float) get_post_meta( $evento_id, 'calibratrack_subtotal', true ),
  'iva'      => (float) get_post_meta( $evento_id, 'calibratrack_iva', true ),
  'total'    => (float) get_post_meta( $evento_id, 'calibratrack_total', true ),
  ```

- [ ] **Paso 5.2: Modificar `build_email_ot()` para mostrar montos en la tabla de datos**

  En `build_email_ot()`, DESPUÉS de la tabla del equipo (después de la última fila `</table>`) y ANTES de la línea `$html .= '<p style="color:#374151;">Una vez finalizado el servicio...`:

  ```php
  // Sección de montos del servicio (solo si hay total > 0).
  if ( isset( $d['total'] ) && $d['total'] > 0 ) {
      $html .= '<p style="margin:16px 0 6px;font-weight:bold;color:#374151;">Detalle de costos del servicio:</p>';
      $html .= '<table width="100%" cellpadding="0" cellspacing="0" style="border:1px solid #e5e7eb;border-radius:6px;font-size:14px;">';
      $html .= '<tr style="background:#f9fafb;"><td style="padding:8px 12px;font-weight:bold;color:#374151;">Subtotal</td>';
      $html .= '<td style="padding:8px 12px;color:#111827;text-align:right;">$' . number_format( (float) $d['subtotal'], 0, ',', '.' ) . '</td></tr>';
      $html .= '<tr><td style="padding:8px 12px;font-weight:bold;color:#374151;">IVA (19%)</td>';
      $html .= '<td style="padding:8px 12px;color:#111827;text-align:right;">$' . number_format( (float) $d['iva'], 0, ',', '.' ) . '</td></tr>';
      $html .= '<tr style="background:#f9fafb;"><td style="padding:8px 12px;font-weight:bold;color:#374151;font-size:15px;">Total</td>';
      $html .= '<td style="padding:8px 12px;color:#111827;text-align:right;font-weight:bold;font-size:15px;">$' . number_format( (float) $d['total'], 0, ',', '.' ) . '</td></tr>';
      $html .= '</table>';
  }
  ```

- [ ] **Paso 5.3: Verificar que el método build_email_ot contiene la sección de montos**

  ```bash
  grep -n "Detalle de costos\|calibratrack_subtotal\|calibratrack_total" \
    /home/jvalenzuela/Desarollo/truetech/calibratrack/includes/class-calibratrack-mailer.php
  ```

  Expected: al menos 2 coincidencias.

---

## Task 6: Panel del Técnico — Ocultar Montos y Quitar Creación de Eventos

**Files:**
- Modify: `calibratrack/templates/tecnico/lista-eventos.php`
- Modify: `calibratrack/templates/tecnico/_partials/form-evento-fields.php`
- Modify: `calibratrack/includes/class-calibratrack-tecnico.php`

**Interfaces:**
- Consumes: `CalibraTrack_Meta_Keys::EVENTO_TIPO_DOCUMENTO`
- Produces: La lista de eventos del técnico solo muestra OTs asignadas a él (no OIs)
- Produces: El técnico NO puede crear nuevos eventos desde /tecnico/nuevo-evento/
- Produces: El formulario de edición de evento NO muestra sección de ítems de costo, garantía ni totales

- [ ] **Paso 6.1: Bloquear la ruta /tecnico/nuevo-evento/ para técnicos**

  En `calibratrack/includes/class-calibratrack-tecnico.php`, en el método `handle_panel()`, en el `switch ($vista)`, modificar el case 'nuevo-evento':

  ```php
  case 'nuevo-evento':
      // NUEVO FLUJO: Solo los administradores crean eventos (OIs y OTs).
      // Los técnicos son redirigidos al dashboard con un aviso.
      if ( ! current_user_can( 'manage_options' ) ) {
          wp_redirect( home_url( '/tecnico/?aviso=sin_permiso_crear' ) );
          exit;
      }
      self::handle_nuevo_evento();
      break;
  ```

- [ ] **Paso 6.2: Quitar el botón "+ Nuevo evento" de la lista de eventos**

  En `calibratrack/templates/tecnico/lista-eventos.php`, eliminar el enlace "+ Nuevo evento":

  ```php
  // ELIMINAR estas líneas (aproximadamente líneas 21-23):
  <a href="<?php echo esc_url( home_url( '/tecnico/nuevo-evento/' ) ); ?>" class="ct-btn ct-btn--primary">
      <?php esc_html_e( '+ Nuevo evento', 'calibratrack' ); ?>
  </a>
  ```

  Y cambiar el título de la página:

  ```php
  // ANTES:
  <h1 class="ct-page-title"><?php esc_html_e( 'Mis eventos de servicio', 'calibratrack' ); ?></h1>

  // DESPUÉS:
  <h1 class="ct-page-title"><?php esc_html_e( 'Mis órdenes de trabajo', 'calibratrack' ); ?></h1>
  ```

- [ ] **Paso 6.3: Filtrar la lista para mostrar solo OTs (no OIs)**

  En `calibratrack/templates/tecnico/lista-eventos.php`, modificar el `WP_Query` para filtrar por tipo_documento = 'ot'. Cambiar el bloque `$query = new WP_Query(...)`:

  ```php
  $query = new WP_Query( array(
      'post_type'      => 'evento_servicio',
      'post_status'    => 'publish',
      'author'         => get_current_user_id(),
      'posts_per_page' => $per_page,
      'paged'          => $paged,
      'orderby'        => 'date',
      'order'          => 'DESC',
      'meta_query'     => array(
          'relation' => 'OR',
          // Mostrar OTs explícitas O eventos sin tipo_documento (compatibilidad con existentes).
          array(
              'key'   => CalibraTrack_Meta_Keys::EVENTO_TIPO_DOCUMENTO,
              'value' => 'ot',
          ),
          array(
              'key'     => CalibraTrack_Meta_Keys::EVENTO_TIPO_DOCUMENTO,
              'compare' => 'NOT EXISTS',
          ),
      ),
  ) );
  ```

  > **Nota sobre compatibilidad**: Los eventos creados antes de esta actualización no tienen el meta `calibratrack_tipo_documento`. El filtro `NOT EXISTS` los incluye para que el técnico siga viendo sus eventos históricos.

- [ ] **Paso 6.4: Eliminar la sección de ítems de costo del formulario del técnico**

  En `calibratrack/templates/tecnico/_partials/form-evento-fields.php`, eliminar COMPLETAMENTE el bloque `<!-- Ítems de costo -->` (líneas 124-165 aproximadamente):

  ```php
  // ELIMINAR desde:
  <!-- Ítems de costo -->
  <div class="ct-field">
      <label class="ct-label"><?php esc_html_e( 'Ítems de costo', 'calibratrack' ); ?></label>
  // ... hasta el cierre:
  </div>  <!-- cierre de ct-items-wrap -->
  </div>  <!-- cierre de ct-field -->
  ```

  Y también eliminar la sección `<!-- Garantía -->` (líneas 111-122):

  ```php
  // ELIMINAR desde:
  <!-- Garantía -->
  <div class="ct-field">
      <label class="ct-checkbox-label">
  // ... hasta:
  </div>  <!-- cierre del campo días garantía -->
  ```

- [ ] **Paso 6.5: Verificar que procesar_guardar_evento ignora ítems si el usuario es técnico**

  En `calibratrack/includes/class-calibratrack-tecnico.php`, en el método `procesar_guardar_evento()`, la sección de ítems de costo ya procesa `$_POST['calibratrack_items']`. Como el formulario ya no enviará estos campos, el array estará vacío y `CalibraTrack_DB::save_items_costo($evento_id, [])` no hará nada destructivo — verificar que esto es correcto (las filas existentes no se borran con un array vacío).

  ```bash
  grep -A 10 "save_items_costo" \
    /home/jvalenzuela/Desarollo/truetech/calibratrack/includes/class-calibratrack-db.php
  ```

  Expected: Si `save_items_costo` borra todas las filas y vuelve a insertar (DELETE + INSERT), un array vacío borraría los ítems existentes. En ese caso, agregar condicional en `procesar_guardar_evento()`:

  ```php
  // SOLO guardar ítems si fueron enviados en el POST (admin, no técnico).
  if ( ! empty( $_POST['calibratrack_items'] ) && is_array( $_POST['calibratrack_items'] ) ) {
      $items_raw = $_POST['calibratrack_items'];
      foreach ( $items_raw as $item ) {
          // ... código existente de procesamiento de ítems ...
      }
      CalibraTrack_DB::save_items_costo( $evento_id, $valores['items'] );
      $totales = CalibraTrack_Helpers::calcular_totales_costo( $valores['items'] );
      update_post_meta( $evento_id, 'calibratrack_subtotal', $totales['subtotal'] );
      update_post_meta( $evento_id, 'calibratrack_iva',      $totales['iva'] );
      update_post_meta( $evento_id, 'calibratrack_total',    $totales['total'] );
  }
  ```

- [ ] **Paso 6.6: Verificar en browser que el técnico no ve montos**

  Navegar a http://localhost:8088/tecnico/nuevo-evento/ → debe redirigir al dashboard.
  Navegar a http://localhost:8088/tecnico/evento/{id}/ → formulario sin sección de ítems de costo ni garantía.
  Navegar a http://localhost:8088/tecnico/eventos/ → lista sin botón "+ Nuevo evento".

---

## Task 7: Cron — Recordatorios de Vencimiento al Cliente (Configurable)

**Files:**
- Modify: `calibratrack/includes/class-calibratrack-cron.php`
- Modify: `calibratrack/includes/class-calibratrack-mailer.php`

**Interfaces:**
- Consumes: `get_option('calibratrack_recordatorio_cliente_enabled', true)` → bool
- Consumes: `get_option('calibratrack_dias_recordatorio_cliente', 30)` → int
- Consumes: `CalibraTrack_DB::get_equipos_proximos_a_vencer(int $dias)` → array de objetos con `equipo_id`, `proxima_fecha_control`
- Produces: `CalibraTrack_Mailer::send_recordatorio_a_cliente(int $equipo_id, string $proxima_fecha, int $dias_restantes): bool`
- Produces: Transient key para evitar duplicados: `calibratrack_recordatorio_cliente_{equipo_id}_{dias}`

- [ ] **Paso 7.1: Agregar el método `send_recordatorio_a_cliente` en CalibraTrack_Mailer**

  En `calibratrack/includes/class-calibratrack-mailer.php`, agregar al final de la clase (antes del cierre `}`):

  ```php
  /**
   * Recordatorio de vencimiento enviado directamente al cliente.
   * Se llama desde CalibraTrack_Cron para alertar de próximas calibraciones.
   *
   * @param int    $equipo_id      Post ID del equipo.
   * @param string $proxima_fecha  Próxima fecha de control (YYYY-MM-DD).
   * @param int    $dias_restantes Días aproximados hasta el vencimiento.
   * @return bool True si wp_mail tuvo éxito.
   */
  public static function send_recordatorio_a_cliente( $equipo_id, $proxima_fecha, $dias_restantes ) {
      if ( ! (bool) get_option( 'calibratrack_email_enabled', true ) ) {
          return false;
      }

      $cliente_id = (int) get_post_meta( $equipo_id, CalibraTrack_Meta_Keys::EQUIPO_CLIENTE_PROPIETARIO, true );
      if ( ! $cliente_id ) {
          return false;
      }

      $correo_cliente = (string) get_post_meta( $cliente_id, CalibraTrack_Meta_Keys::CLIENTE_CORREO, true );
      if ( empty( $correo_cliente ) || ! is_email( $correo_cliente ) ) {
          return false;
      }

      $empresa       = (string) get_option( 'calibratrack_empresa_nombre', 'TrueTech SpA' );
      $from          = (string) get_option( 'calibratrack_email_from', get_option( 'admin_email' ) );
      $color         = esc_attr( (string) get_option( 'calibratrack_pdf_color_primario', '#00AEEF' ) );

      $nombre_cliente = (string) get_post_meta( $cliente_id, CalibraTrack_Meta_Keys::CLIENTE_NOMBRE_EMPRESA, true );
      $serie          = (string) get_post_meta( $equipo_id, CalibraTrack_Meta_Keys::EQUIPO_SERIE, true );
      $marca          = (string) get_post_meta( $equipo_id, CalibraTrack_Meta_Keys::EQUIPO_MARCA, true );
      $modelo         = (string) get_post_meta( $equipo_id, CalibraTrack_Meta_Keys::EQUIPO_MODELO, true );

      $fecha_dt  = DateTime::createFromFormat( 'Y-m-d', $proxima_fecha );
      $fecha_fmt = $fecha_dt ? $fecha_dt->format( 'd/m/Y' ) : esc_html( $proxima_fecha );

      if ( (int) $dias_restantes <= 7 ) {
          $asunto = sprintf(
              /* translators: 1: empresa 2: marca modelo */
              __( '[URGENTE] %1$s — Vencimiento próximo de calibración: %2$s', 'calibratrack' ),
              $empresa,
              $marca . ' ' . $modelo
          );
          $urgencia_label = __( 'URGENTE:', 'calibratrack' );
          $urgencia_color = '#dc2626';
      } else {
          $asunto = sprintf(
              /* translators: 1: empresa 2: marca modelo */
              __( '[Aviso] %1$s — Recordatorio de calibración próxima: %2$s', 'calibratrack' ),
              $empresa,
              $marca . ' ' . $modelo
          );
          $urgencia_label = __( 'Recordatorio:', 'calibratrack' );
          $urgencia_color = '#0369a1';
      }

      $html  = '<!DOCTYPE html><html lang="es"><head><meta charset="UTF-8">';
      $html .= '<meta name="viewport" content="width=device-width,initial-scale=1.0"></head>';
      $html .= '<body style="margin:0;padding:0;font-family:Arial,sans-serif;background:#f3f4f6;">';
      $html .= '<table width="100%" cellpadding="0" cellspacing="0" style="background:#f3f4f6;">';
      $html .= '<tr><td align="center" style="padding:32px 16px;">';
      $html .= '<table width="600" cellpadding="0" cellspacing="0" style="max-width:600px;background:#fff;border-radius:8px;overflow:hidden;">';
      $html .= '<tr><td style="background:' . $color . ';height:6px;"></td></tr>';
      $html .= '<tr><td style="padding:24px 32px 12px;">';
      $html .= '<p style="margin:0;font-size:22px;font-weight:bold;color:' . $color . ';">' . esc_html( $empresa ) . '</p>';
      $html .= '<p style="margin:4px 0 0;font-size:15px;color:' . esc_attr( $urgencia_color ) . ';font-weight:bold;">';
      $html .= esc_html( $urgencia_label ) . ' ' . __( 'Calibración / mantención próxima', 'calibratrack' );
      $html .= '</p></td></tr>';
      $html .= '<tr><td style="padding:8px 32px 24px;">';
      $html .= '<p style="color:#374151;">Estimado/a <strong>' . esc_html( $nombre_cliente ) . '</strong>,</p>';
      $html .= '<p style="color:#374151;">Le recordamos que el siguiente equipo tiene su próxima ';
      $html .= 'fecha de calibración o mantención en aproximadamente <strong>' . (int) $dias_restantes . ' días</strong>:</p>';
      $html .= '<table width="100%" cellpadding="0" cellspacing="0" style="border:1px solid #e5e7eb;border-radius:6px;margin:16px 0;font-size:14px;">';
      $html .= '<tr style="background:#f9fafb;"><td style="padding:8px 12px;font-weight:bold;width:160px;color:#374151;">Equipo</td>';
      $html .= '<td style="padding:8px 12px;color:#111827;">' . esc_html( $marca . ' ' . $modelo ) . '</td></tr>';
      $html .= '<tr><td style="padding:8px 12px;font-weight:bold;color:#374151;">N° de Serie</td>';
      $html .= '<td style="padding:8px 12px;color:#111827;font-family:monospace;">' . esc_html( $serie ) . '</td></tr>';
      $html .= '<tr style="background:#f9fafb;"><td style="padding:8px 12px;font-weight:bold;color:#374151;">Fecha límite</td>';
      $html .= '<td style="padding:8px 12px;color:#111827;">' . esc_html( $fecha_fmt ) . '</td></tr>';
      $html .= '</table>';
      $html .= '<p style="color:#374151;">Le recomendamos contactarnos para programar el servicio con anticipación.</p>';
      $html .= '<p style="color:#6b7280;font-size:13px;">Para coordinar su servicio, responda este correo o contáctenos directamente.</p>';
      $html .= '</td></tr>';
      $html .= '<tr><td style="background:' . $color . ';height:4px;"></td></tr>';
      $html .= '<tr><td style="padding:16px 32px;text-align:center;color:#9ca3af;font-size:12px;">';
      $html .= '© ' . gmdate( 'Y' ) . ' ' . esc_html( $empresa ) . ' — Este correo fue generado automáticamente.';
      $html .= '</td></tr></table></td></tr></table></body></html>';

      $headers = array(
          'Content-Type: text/html; charset=UTF-8',
          'From: ' . $empresa . ' <' . $from . '>',
      );

      return wp_mail( $correo_cliente, $asunto, $html, $headers );
  }
  ```

- [ ] **Paso 7.2: Modificar `check_vencimientos()` en CalibraTrack_Cron para incluir recordatorio al cliente**

  En `calibratrack/includes/class-calibratrack-cron.php`, reemplazar el método `check_vencimientos()`:

  ```php
  /**
   * Callback del cron: detecta equipos próximos a vencer y envía alertas.
   *
   * Evalúa dos ventanas de alerta: los días configurables y 7 días (urgente).
   * El recordatorio al cliente es opcional (opción calibratrack_recordatorio_cliente_enabled).
   * Las alertas al administrador siguen funcionando siempre.
   *
   * @return void
   */
  public static function check_vencimientos() {
      $dias_recordatorio = (int) get_option( 'calibratrack_dias_recordatorio_cliente', 30 );
      $dias_recordatorio = max( 1, min( 365, $dias_recordatorio ) ); // Clamp 1-365.

      // Las dos ventanas de alerta: la configurable y 7 días de urgencia.
      $ventanas = array_unique( array( $dias_recordatorio, 7 ) );

      foreach ( $ventanas as $dias ) {
          $equipos = CalibraTrack_DB::get_equipos_proximos_a_vencer( $dias );

          if ( empty( $equipos ) ) {
              continue;
          }

          foreach ( $equipos as $equipo ) {
              // Alerta al administrador (comportamiento existente, siempre activo).
              self::enviar_alerta_si_no_enviada( $equipo, $dias );

              // Recordatorio al cliente (nuevo, configurable).
              if ( (bool) get_option( 'calibratrack_recordatorio_cliente_enabled', true ) ) {
                  self::enviar_recordatorio_cliente_si_no_enviado( $equipo, $dias );
              }
          }
      }
  }
  ```

- [ ] **Paso 7.3: Agregar método `enviar_recordatorio_cliente_si_no_enviado()` en CalibraTrack_Cron**

  En `calibratrack/includes/class-calibratrack-cron.php`, agregar el nuevo método después de `enviar_alerta_si_no_enviada()`:

  ```php
  /**
   * Envía el recordatorio de vencimiento al cliente si no fue enviado ya en este ciclo.
   *
   * Usa transient 'calibratrack_recordatorio_cliente_{equipo_id}_{dias}' con TTL 23h
   * para no enviar dos veces al mismo cliente en la misma ventana.
   *
   * @param object $equipo Objeto con equipo_id, proxima_fecha_control.
   * @param int    $dias   Ventana de días.
   * @return void
   */
  private static function enviar_recordatorio_cliente_si_no_enviado( $equipo, $dias ) {
      $equipo_id     = (int) $equipo->equipo_id;
      $transient_key = 'calibratrack_recordatorio_cliente_' . $equipo_id . '_' . (int) $dias;

      if ( get_transient( $transient_key ) ) {
          return; // Ya enviado en las últimas 23 horas.
      }

      $proxima_fecha = (string) $equipo->proxima_fecha_control;

      if ( ! class_exists( 'CalibraTrack_Mailer' ) ) {
          return;
      }

      $enviado = CalibraTrack_Mailer::send_recordatorio_a_cliente( $equipo_id, $proxima_fecha, $dias );

      if ( $enviado ) {
          set_transient( $transient_key, 1, self::ALERTA_TTL );
      }
  }
  ```

- [ ] **Paso 7.4: Verificar la lógica del cron (dry run)**

  ```bash
  grep -n "check_vencimientos\|enviar_recordatorio_cliente\|send_recordatorio" \
    /home/jvalenzuela/Desarollo/truetech/calibratrack/includes/class-calibratrack-cron.php \
    /home/jvalenzuela/Desarollo/truetech/calibratrack/includes/class-calibratrack-mailer.php
  ```

  Expected: `check_vencimientos` en cron.php con lógica nueva, `send_recordatorio_a_cliente` en mailer.php.

---

## Task 8: PDF Certificado — Eliminar Secciones Garantía y Defectos Encontrados

**Files:**
- Modify: `calibratrack/templates/pdf/certificado.php`

**Interfaces:**
- Produces: El PDF del certificado ya no incluye sección "GARANTÍA" ni "Defectos encontrados por el cliente"
- Nota: Las variables `$garantia`, `$dias_garantia`, `$falla_reportada` siguen pasándose al template (no romper la firma del método) — simplemente no se renderizan en el HTML.

- [ ] **Paso 8.1: Eliminar la sección de Garantía del template**

  En `calibratrack/templates/pdf/certificado.php`, eliminar el bloque completo de garantía (actualmente alrededor de las líneas 155-172):

  ```php
  // ELIMINAR este bloque completo:
  <!-- Garantía -->
  <div class="seccion">
  	<div class="garantia-row">
  		<div class="garantia-label">GARANTÍA</div>
  		<div class="garantia-opciones">
  			<span class="garantia-opcion">
  				<span class="checkbox-box <?php echo $garantia ? 'checkbox-marcado' : ''; ?>"><?php echo $garantia ? 'X' : ''; ?></span>
  				SI
  			</span>
  			<span class="garantia-opcion">
  				<span class="checkbox-box <?php echo ! $garantia ? 'checkbox-marcado' : ''; ?>"><?php echo ! $garantia ? 'X' : ''; ?></span>
  				NO
  			</span>
  			<?php if ( $garantia && $dias_garantia > 0 ) : ?>
  				<span style="font-size:9pt;color:#555;">(<?php echo (int) $dias_garantia; ?> días)</span>
  			<?php endif; ?>
  		</div>
  	</div>
  </div>
  ```

- [ ] **Paso 8.2: Eliminar la sección de Defectos encontrados del template**

  En `calibratrack/templates/pdf/certificado.php`, eliminar el bloque completo de defectos (actualmente alrededor de las líneas 175-178):

  ```php
  // ELIMINAR este bloque completo:
  <!-- Defectos encontrados -->
  <div class="seccion">
  	<div class="seccion-titulo">Defectos encontrados por el cliente</div>
  	<div class="caja-texto"><?php echo esc_html( $falla_reportada ?: '—' ); ?></div>
  </div>
  ```

- [ ] **Paso 8.3: También eliminar los estilos CSS que solo usaban Garantía**

  En el `<style>` de `certificado.php`, eliminar los estilos de garantía que ya no se usan:

  ```css
  /* ELIMINAR estas reglas CSS: */
  .garantia-row { display: table; margin-top: 6px; }
  .garantia-label { display: table-cell; font-weight: bold; font-size: 9pt; padding-right: 14px; vertical-align: middle; }
  .garantia-opciones { display: table-cell; vertical-align: middle; }
  .garantia-opcion { display: inline-block; margin-right: 14px; font-size: 9pt; }
  .checkbox-box { display: inline-block; width: 12px; height: 12px; border: 1px solid #333; margin-right: 4px; vertical-align: middle; text-align: center; font-size: 8pt; line-height: 12px; }
  .checkbox-marcado { background: #333; color: #fff; }
  ```

- [ ] **Paso 8.4: Verificar que el template quedó sin las secciones eliminadas**

  ```bash
  grep -n "garantia\|garantía\|Defectos\|falla_reportada\|checkbox" \
    /home/jvalenzuela/Desarollo/truetech/calibratrack/templates/pdf/certificado.php
  ```

  Expected: cero coincidencias para estos términos.

- [ ] **Paso 8.5: Regenerar un certificado existente y verificar visualmente**

  Navegar a wp-admin o al panel técnico → abrir un evento con certificado generado → acceder a la URL de descarga del certificado. Verificar que el PDF no muestra "GARANTÍA" ni "Defectos encontrados". Si hay un script de regeneración disponible, ejecutarlo:

  ```bash
  php /tmp/regenerar_ot.php  # o el script equivalente de regeneración
  ```

---

## Auto-Review contra el Spec

Verificación de cobertura de los 5 requisitos del usuario:

| Requisito | Tarea | Estado |
|-----------|-------|--------|
| Admin crea OI → email automático al técnico | Task 3, Task 4 | ✅ Cubierto |
| Admin crea OT vinculada a OI → email al cliente con montos | Task 3, Task 5 | ✅ Cubierto |
| Técnico externo no ve montos | Task 6 | ✅ Cubierto |
| Recordatorios de vencimiento al cliente (configurable, default 30 días) | Task 1, Task 7 | ✅ Cubierto |
| Certificado de Garantía: eliminar columnas Garantía y Defectos | Task 8 | ✅ Cubierto |

**Restricción crítica verificada**: El técnico no puede crear eventos (Task 6.1 bloquea la ruta) ni ve montos (Task 6.4 elimina el formulario de ítems). La separación de vistas admin/técnico está implementada a través del flag `current_user_can('manage_options')`.

**Compatibilidad con eventos existentes**: Los eventos ya creados no tienen `calibratrack_tipo_documento`, lo que se trata como 'ot' para el panel del técnico (ver Task 6.3). El correo al admin por vencimiento sigue funcionando igual que antes (Task 7.2 conserva `enviar_alerta_si_no_enviada`).

---

**Plan guardado en `docs/superpowers/plans/2026-07-15-nuevo-flujo-oi-ot.md`.**
