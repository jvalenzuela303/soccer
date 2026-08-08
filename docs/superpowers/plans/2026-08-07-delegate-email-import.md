# Correo del Delegado en Carga Masiva de Equipos

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** El importador CSV simple de equipos (`import_teams()`) acepta tres columnas adicionales — nombre, correo y celular del delegado — y los persiste en `ds_teams` para que puedan usarse como destinatarios de notificaciones.

**Architecture:** La columna `delegado_correo` (y `delegado_nombre`, `delegado_celular`) ya existe en `ds_teams` y el importador Excel (`import_team_roster()`) ya la lee. Solo hay que extender el CSV simple `import_teams()` en `SpreadsheetImporter.php` para aceptar las mismas columnas delegado. No se toca la lógica de notificaciones existente — se asegura que el campo esté disponible.

**Tech Stack:** PHP 8.2, WordPress 7.0.2, MariaDB 10.6, WordPress Coding Standards

## Global Constraints

- PHP 8.2, WordPress 7.0.2, MariaDB 10.6
- Prefijo de tablas: `$wpdb->prefix . 'ds_'`
- Text domain i18n: `soccertrack`
- WordPress Coding Standards (WPCS)
- `$wpdb->prepare()` en todas las queries directas
- `sanitize_text_field()` / `sanitize_email()` en todos los campos POST/CSV
- No se cambia la lógica de deduplicación (skip si ya existe el equipo por nombre+torneo)

---

## Mapa de archivos

| Archivo | Acción | Qué cambia |
|---------|--------|-----------|
| `soccertrack/includes/Importers/SpreadsheetImporter.php` | Modificar | Extender `import_teams()` para leer columnas D (delegado_nombre), E (delegado_correo), F (delegado_celular) y persistirlas |

---

### Task 1: Extender import_teams() con campos del delegado

**Files:**
- Modify: `soccertrack/includes/Importers/SpreadsheetImporter.php` (método `import_teams()`, ~línea 352)

**Interfaces:**
- Consume: CSV con hasta 6 columnas: `name, city, colors, director_name, delegado_nombre, delegado_correo, delegado_celular`
- Produce: equipos creados con `delegado_nombre`, `delegado_correo`, `delegado_celular` persistidos cuando se proporcionan

- [ ] **Step 1: Leer el método import_teams() completo**

Abrir `soccertrack/includes/Importers/SpreadsheetImporter.php` y leer el método `import_teams()` para verificar el estado actual exacto antes de editar.

El método actual desempaqueta 4 columnas:
```php
[ $name, $city, $colors, $dt_name ] = array_pad( $data, 4, '' );
```

Y hace un INSERT con solo `tournament_id` y `name`:
```php
$wpdb->insert(
    "{$wpdb->prefix}ds_teams",
    [
        'tournament_id' => $tournament_id,
        'name'          => sanitize_text_field( $name ),
    ],
    [ '%d', '%s' ]
);
```

- [ ] **Step 2: Extender el desempaquetado de columnas**

Reemplazar la línea de desempaquetado:

```php
// ANTES:
[ $name, $city, $colors, $dt_name ] = array_pad( $data, 4, '' );

// DESPUÉS:
[ $name, $city, $colors, $dt_name, $del_nombre, $del_correo, $del_celular ] = array_pad( $data, 7, '' );
```

- [ ] **Step 3: Actualizar el INSERT con los campos del delegado**

Reemplazar el `$wpdb->insert()`:

```php
// ANTES:
$wpdb->insert(
    "{$wpdb->prefix}ds_teams",
    [
        'tournament_id' => $tournament_id,
        'name'          => sanitize_text_field( $name ),
    ],
    [ '%d', '%s' ]
);

// DESPUÉS:
$del_correo_clean = sanitize_email( $del_correo );

$insert_data    = [
    'tournament_id'  => $tournament_id,
    'name'           => sanitize_text_field( $name ),
];
$insert_formats = [ '%d', '%s' ];

if ( '' !== trim( $del_nombre ) ) {
    $insert_data['delegado_nombre'] = sanitize_text_field( $del_nombre );
    $insert_formats[]               = '%s';
}
if ( '' !== $del_correo_clean ) {
    $insert_data['delegado_correo'] = $del_correo_clean;
    $insert_formats[]               = '%s';
}
if ( '' !== trim( $del_celular ) ) {
    $insert_data['delegado_celular'] = sanitize_text_field( $del_celular );
    $insert_formats[]                = '%s';
}

$wpdb->insert(
    "{$wpdb->prefix}ds_teams",
    $insert_data,
    $insert_formats
);
```

- [ ] **Step 4: Preparar un CSV de prueba**

Crear un archivo `docs/equipos-con-delegado.csv`:
```csv
Nombre Equipo,Ciudad,Colores,Director,Delegado Nombre,Delegado Correo,Delegado Celular
Equipo Alpha,Santiago,Azul-Blanco,Carlos Ruiz,María González,m.gonzalez@empresa.cl,+56912345678
Equipo Beta,Valparaíso,Rojo-Negro,Luis Pérez,Ana Torres,a.torres@empresa.cl,+56987654321
Equipo Gamma,Concepción,Verde,,,,
```

- [ ] **Step 5: Prueba manual**

1. Ir al panel de administración → importar equipos CSV con el archivo creado.
2. Verificar en BD:
```sql
SELECT name, delegado_nombre, delegado_correo, delegado_celular
FROM wp_ds_teams
ORDER BY id DESC LIMIT 3;
```
Resultado esperado:
- Equipo Alpha: delegado_nombre='María González', delegado_correo='m.gonzalez@empresa.cl'
- Equipo Beta: similar con Ana Torres
- Equipo Gamma: todos los campos delegado = NULL

- [ ] **Step 6: Commit**

```bash
git add soccertrack/includes/Importers/SpreadsheetImporter.php \
        docs/equipos-con-delegado.csv
git commit -m "feat: add delegado_nombre, delegado_correo, delegado_celular to CSV team import"
```

---

## Self-Review

**Spec coverage:**
- ✅ Correo del delegado en carga masiva CSV → columnas E del CSV → `delegado_correo` en BD
- ✅ Nombre y celular también incluidos → columnas D, F → coherente con el importador Excel existente
- ✅ Campo opcional → insert condicional (solo si no está vacío)
- ✅ `sanitize_email()` en correo → validación correcta
- ✅ No rompe importaciones existentes → `array_pad` con 7 → las primeras 4 columnas siguen igual
- ✅ No toca `import_team_roster()` → ya funciona correctamente para Excel
- ✅ Correo disponible como receptor de notificaciones → en `ds_teams.delegado_correo`

**Sin placeholders:** Todo el código está completo.
