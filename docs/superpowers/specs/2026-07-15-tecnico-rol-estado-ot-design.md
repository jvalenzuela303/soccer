# Diseño: Rol técnico restringido + estados intermedios de OT

**Fecha:** 2026-07-15  
**Proyecto:** CalibraTrack (WordPress plugin)  
**Alcance:** Restricciones de acceso para el rol `tecnico_calibracion` y nuevos estados para la Orden de Trabajo.

---

## Contexto

El técnico es un colaborador externo que recibe OTs asignadas por el administrador. Actualmente tiene acceso completo al formulario de la OT, incluidos los montos de costo. Se requiere:

1. Limitar su navegación y los campos que puede modificar.
2. Ocultar toda información de precios.
3. Agregar estados intermedios que el técnico usa para comunicar el avance al admin.

---

## 1. Estados de la OT

Cuatro estados en orden de flujo:

| Slug | Label visible | Asignado por | Color badge |
|---|---|---|---|
| `en_proceso` | En proceso | Admin (al crear OT) | Gris/azul (`ct-badge--por-vencer`) |
| `en_ejecucion` | En ejecución | Técnico (empezó a trabajar) | Amarillo (`ct-badge--en-ejecucion`) |
| `listo_revision` | Listo para revisión | Técnico (terminó su trabajo) | Morado (`ct-badge--listo-revision`) |
| `completado` | Completado | Admin (emite certificado) | Verde (`ct-badge--vigente`) |

### Reglas de transición de estado

- El técnico solo puede asignar: `en_proceso`, `en_ejecucion`, `listo_revision`.
- El técnico **no puede** asignar `completado`. Si llega ese valor por POST, el servidor lo ignora y mantiene el estado anterior.
- El admin puede asignar cualquiera de los 4 estados.
- La emisión del certificado PDF sigue ocurriendo únicamente al pasar a `completado` (comportamiento existente).

### Fuente única de verdad

`CalibraTrack_Helpers::get_estados_servicio()` devuelve el array de los 4 estados con slug, label y clase CSS. Ningún otro archivo define esta lista.

---

## 2. Accesos del técnico

### Navegación

El menú lateral del panel muestra para técnicos únicamente:
- **Mis OTs** → `/panel/` (dashboard filtrado solo a sus OTs)
- **Perfil** → `/panel/perfil/`

Los ítems Equipos, Clientes, OI, y los enlaces de administración quedan ocultos.

### Lista de OTs

- El técnico ve solo las OTs donde el meta `calibratrack_tecnico_responsable` coincide con su `user_id`.  
  **Nota:** El filtro actual usa `post_author`, lo que impide al técnico ver OTs que el admin creó y le asignó. Este filtro debe cambiarse a `meta_query` por `calibratrack_tecnico_responsable`.
- El check de edición en `handle_editar_evento()` también debe cambiar de `post_author` a `calibratrack_tecnico_responsable` para ser consistente.
- Sin columna de montos ni estado financiero.
- Badges de los 4 estados visibles.

### Vista de detalle de OT (`/panel/evento/{id}/`)

La ruta existente se divide en dos comportamientos según rol:

**Campos en solo lectura (tarjeta de información):**
- N° OT
- OI vinculada
- Equipo (serie, marca, modelo, tipo)
- Tipo de servicio
- Fecha de ejecución
- Próxima fecha de control
- Falla reportada por el cliente

**Campos editables por el técnico:**
- Descripción del trabajo / servicio realizado (`descripcion_trabajo`)
- Observaciones (`observaciones`)
- Evidencia fotográfica (upload, `evidencia_fotografica[]`)
- Documentos adjuntos PDF (upload, `documentos_adjuntos[]`)
- Estado del servicio (selector: `en_proceso` / `en_ejecucion` / `listo_revision`)

**Campos completamente ocultos para el técnico:**
- Ítems de costo (detalle, cantidad, precio unitario)
- Subtotal, IVA, Total
- Búsqueda de productos WooCommerce
- N° OT (editable solo en admin)
- Selector de técnico responsable

### Seguridad server-side

Estas reglas se aplican en `class-calibratrack-panel.php` al procesar el POST del técnico:

1. Los campos de costo (`calibratrack_items`, `subtotal`, `iva`, `total`) son ignorados completamente — no se leen ni guardan.
2. Si `estado_servicio = completado` llega en POST y el usuario no tiene `manage_options`, el valor se descarta y se mantiene el estado anterior guardado en base de datos.
3. El check de acceso cambia de `post_author` a meta `calibratrack_tecnico_responsable`: el técnico solo puede editar OTs donde él es el técnico responsable asignado (o donde él es el autor, como fallback).

---

## 3. Archivos a modificar

### `includes/class-calibratrack-helpers.php`

Agregar método estático:

```php
public static function get_estados_servicio() {
    return array(
        'en_proceso'     => array(
            'label' => __( 'En proceso', 'calibratrack' ),
            'clase' => 'ct-badge--por-vencer',
        ),
        'en_ejecucion'   => array(
            'label' => __( 'En ejecución', 'calibratrack' ),
            'clase' => 'ct-badge--en-ejecucion',
        ),
        'listo_revision' => array(
            'label' => __( 'Listo para revisión', 'calibratrack' ),
            'clase' => 'ct-badge--listo-revision',
        ),
        'completado'     => array(
            'label' => __( 'Completado', 'calibratrack' ),
            'clase' => 'ct-badge--vigente',
        ),
    );
}
```

### `includes/class-calibratrack-panel.php`

**`procesar_guardar_evento()`** (usado por la ruta del técnico):
- Reemplazar validación de `estado_servicio`: si el usuario no es admin y el valor es `completado`, cargar el estado actual desde BD y usarlo en su lugar.
- No procesar ni guardar ítems de costo.

**`handle_editar_evento()`**:
- Detectar si `current_user_can('manage_options')`.
- Si es admin: redirigir a `/panel/ot/{id}/` (vista completa admin).
- Si es técnico: cargar `evento-detalle.php` con variables adicionales (`$es_tecnico = true`).

**`handle_ot()`** (admin):
- Agregar los dos nuevos estados al array de validación: `array('en_proceso', 'en_ejecucion', 'listo_revision', 'completado')`.

### `templates/panel/evento-detalle.php`

Rediseño completo. Cuando `$es_tecnico = true`:
- Renderizar tarjeta de solo lectura con los campos informativos.
- Formulario con solo los 4 campos editables + selector de estado (3 opciones, sin `completado`).
- Sin sección de ítems de costo.

Cuando `$es_tecnico = false` (admin accede por esta ruta — no debería ocurrir tras la redirección, pero como fallback): mostrar vista completa.

### `templates/panel/form-ot.php`

Selector de estado del admin: agregar `en_ejecucion` y `listo_revision` entre `en_proceso` y `completado`.

### `templates/panel/dashboard.php`

Configuración de badges en tabla OT: usar `CalibraTrack_Helpers::get_estados_servicio()` en lugar del array hardcodeado de 2 estados.

### `templates/panel/lista-eventos.php`

Misma actualización de badges que dashboard.

### `templates/panel/_partials/header.php`

Condición `if ( ! current_user_can('manage_options') )`: ocultar nav items de Equipos, Clientes, OI. Mostrar solo "Mis OTs" y "Perfil".

### `assets/css/calibratrack-panel.css`

Agregar dos clases:

```css
.ct-badge--en-ejecucion {
    background: #fef3c7;
    color: #92400e;
    border: 1px solid #fcd34d;
}
.ct-badge--listo-revision {
    background: #ede9fe;
    color: #5b21b6;
    border: 1px solid #c4b5fd;
}
```

---

## 4. Lo que NO cambia

- La lógica de emisión del certificado PDF al pasar a `completado`.
- El envío de correo al cliente al completar.
- Los permisos de capability del rol `tecnico_calibracion` (ya son correctos).
- La ruta `/panel/ot/{id}/` sigue siendo admin-only.
- El técnico sigue sin acceso a crear OTs ni OIs.

---

## 5. Criterios de aceptación

1. El técnico accede a `/panel/` y ve solo sus OTs sin columna de montos.
2. El técnico abre una OT y ve los datos de identificación en solo lectura.
3. El técnico puede guardar cambios en descripción, observaciones, fotos y PDFs.
4. El técnico puede cambiar el estado a `en_ejecucion` o `listo_revision`.
5. Si el técnico manipula el POST para enviar `completado`, el servidor lo ignora.
6. Si el técnico manipula el POST para enviar ítems de costo, el servidor los ignora.
7. El admin ve los 4 estados en el selector de la vista `/panel/ot/{id}/`.
8. Los badges de los 4 estados aparecen con colores correctos en lista y dashboard.
9. El menú del técnico no muestra Equipos, Clientes ni OI.
