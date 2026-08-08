# Eliminación del Rol ds_delegado — Plan de Implementación

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Eliminar completamente el rol `ds_delegado` y sus capabilities asociadas (`ds_manage_club`, `ds_view_club_panel`) del plugin SoccerTrack, sin afectar ningún otro rol ni flujo existente.

**Architecture:** Eliminación limpia en tres capas: (1) definición del rol en RolesManager, (2) referencias en TournamentPage y header, (3) limpieza en uninstall/seed. La columna `delegate_user_id` en `ds_teams` se conserva como campo histórico (nullable, no causa problemas).

**Tech Stack:** PHP 8.2, WordPress 7.0.2, MariaDB 10.6, WordPress Coding Standards.

## Global Constraints

- PHP 8.2, WordPress 7.0.2, MariaDB 10.6
- Prefijo de tablas: `$wpdb->prefix . 'ds_'`
- Text domain i18n: `soccertrack`
- Namespace: `SportsLeague\Core` (archivos en `includes/Core/`)
- No tocar migraciones de base de datos — el rol se elimina con `remove_role()` en uninstall, no requiere ALTER TABLE

---

## Mapa de archivos

| Archivo | Acción | Qué cambia |
|---------|--------|-----------|
| `soccertrack/includes/Core/RolesManager.php` | Modificar | Eliminar `CAPS_DELEGADO` y entrada `ds_delegado` en `ROLE_DEFINITIONS` |
| `soccertrack/includes/Core/UserManager.php` | Modificar | Eliminar `ds_delegado` de `ALLOWED_ROLES` |
| `soccertrack/includes/Public/TournamentPage.php` | Modificar | Eliminar `ds_manage_club` de `$panel_caps` en `user_has_panel_access()` |
| `soccertrack/templates/panel/_partials/header.php` | Modificar | Eliminar rama `elseif ds_manage_club` del switch de rol |
| `soccertrack/uninstall.php` | Modificar | Eliminar `ds_delegado` del loop de roles y `ds_manage_club`/`ds_view_club_panel` de caps |
| `soccertrack/dev-seed.php` | Modificar | Eliminar array `$delegados` y foreach de creación |

---

### Task 1: Eliminar el rol de RolesManager y UserManager

**Files:**
- Modify: `soccertrack/includes/Core/RolesManager.php:96-127`
- Modify: `soccertrack/includes/Core/UserManager.php:19-21`

**Interfaces:**
- Produce: `RolesManager` sin `CAPS_DELEGADO` ni entrada `ds_delegado`, `UserManager::ALLOWED_ROLES` sin `ds_delegado`

- [ ] **Step 1: Eliminar CAPS_DELEGADO de RolesManager**

En `includes/Core/RolesManager.php`, eliminar el bloque completo (líneas ~96–108):

```php
// ELIMINAR este bloque completo:
/** @var array<string, bool> */
private const CAPS_DELEGADO = [
    // Capabilities personalizadas del plugin.
    'read'                        => true,
    'ds_manage_club'              => true,
    'ds_view_club_panel'          => true,
    'ds_view_match_sheet'         => true,
    // Capabilities de CPT: gestión del propio equipo y jugadores (sin edit_others_*).
    'edit_st_equipos'             => true,
    'edit_others_st_equipos'      => false,
    'edit_st_jugadores'           => true,
    'edit_others_st_jugadores'    => false,
];
```

- [ ] **Step 2: Eliminar ds_delegado de ROLE_DEFINITIONS**

En el mismo archivo, en la constante `ROLE_DEFINITIONS`, eliminar la entrada:

```php
// ELIMINAR estas 4 líneas:
'ds_delegado' => [
    'label' => 'Delegado de Club',
    'caps'  => self::CAPS_DELEGADO,
],
```

- [ ] **Step 3: Limpiar el docblock del archivo**

Al inicio de `RolesManager.php`, eliminar las líneas del docblock que mencionan `ds_delegado`:

```php
// ELIMINAR estas líneas del docblock:
//  - ds_delegado    : Delegado de Club — gestión de su equipo y jugadores.
//  - ds_manage_club           : Gestionar perfil, escudo y cuerpo técnico del propio club.
//  - ds_view_club_panel       : Acceder al panel del delegado.
```

- [ ] **Step 4: Eliminar ds_delegado de UserManager**

En `includes/Core/UserManager.php`, en la constante `ALLOWED_ROLES` eliminar la línea:

```php
// ELIMINAR:
'ds_delegado'    => 'Delegado de Club',
```

- [ ] **Step 5: Verificar manualmente**

```bash
grep -rn "ds_delegado\|CAPS_DELEGADO\|ds_manage_club\|ds_view_club_panel" \
  soccertrack/includes/Core/RolesManager.php \
  soccertrack/includes/Core/UserManager.php
```

Resultado esperado: **sin output** (cero coincidencias).

- [ ] **Step 6: Commit**

```bash
git add soccertrack/includes/Core/RolesManager.php \
        soccertrack/includes/Core/UserManager.php
git commit -m "feat: remove ds_delegado role definition and ALLOWED_ROLES entry"
```

---

### Task 2: Eliminar referencias en TournamentPage y header

**Files:**
- Modify: `soccertrack/includes/Public/TournamentPage.php:1815-1825`
- Modify: `soccertrack/templates/panel/_partials/header.php:30-31`

**Interfaces:**
- Consume: ninguna
- Produce: `user_has_panel_access()` sin `ds_manage_club`; header sin rama Delegado

- [ ] **Step 1: Eliminar ds_manage_club de user_has_panel_access()**

En `includes/Public/TournamentPage.php`, método `user_has_panel_access()` (~línea 1818):

```php
// ANTES:
$panel_caps = [
    'ds_view_admin_panel',
    'ds_enter_match_incidents',
    'ds_close_match',
    'ds_view_match_sheet',
    'ds_manage_club',
    'manage_options',
];

// DESPUÉS:
$panel_caps = [
    'ds_view_admin_panel',
    'ds_enter_match_incidents',
    'ds_close_match',
    'ds_view_match_sheet',
    'manage_options',
];
```

- [ ] **Step 2: Eliminar la rama Delegado del header**

En `templates/panel/_partials/header.php` (~línea 30):

```php
// ANTES:
} elseif ( current_user_can( 'ds_enter_match_incidents' ) ) {
    esc_html_e( 'Planillero', 'soccertrack' );
} elseif ( current_user_can( 'ds_manage_club' ) ) {
    esc_html_e( 'Delegado', 'soccertrack' );
} elseif ( current_user_can( 'manage_options' ) ) {

// DESPUÉS:
} elseif ( current_user_can( 'ds_enter_match_incidents' ) ) {
    esc_html_e( 'Planillero', 'soccertrack' );
} elseif ( current_user_can( 'manage_options' ) ) {
```

- [ ] **Step 3: Verificar sin referencias restantes en TournamentPage**

```bash
grep -n "ds_manage_club\|ds_view_club_panel\|ds_delegado" \
  soccertrack/includes/Public/TournamentPage.php \
  soccertrack/templates/panel/_partials/header.php
```

Resultado esperado: **sin output**.

- [ ] **Step 4: Commit**

```bash
git add soccertrack/includes/Public/TournamentPage.php \
        soccertrack/templates/panel/_partials/header.php
git commit -m "feat: remove ds_delegado panel access check and header label"
```

---

### Task 3: Limpiar uninstall.php y dev-seed.php

**Files:**
- Modify: `soccertrack/uninstall.php:50-68`
- Modify: `soccertrack/dev-seed.php:94-124`

**Interfaces:**
- Produce: uninstall sin `ds_delegado`; dev-seed sin usuarios delegados

- [ ] **Step 1: Limpiar uninstall.php — loop de roles**

En `uninstall.php` (~línea 50), el foreach de eliminación de roles:

```php
// ANTES:
foreach ( [ 'ds_coordinador', 'ds_arbitro', 'ds_delegado' ] as $role_slug ) {
    remove_role( $role_slug );
}

// DESPUÉS:
foreach ( [ 'ds_coordinador', 'ds_arbitro' ] as $role_slug ) {
    remove_role( $role_slug );
}
```

- [ ] **Step 2: Limpiar uninstall.php — caps del admin**

En el mismo archivo, en el array de caps a remover del rol `administrator`:

```php
// ELIMINAR estas dos líneas:
'ds_manage_club',
'ds_view_club_panel',
```

- [ ] **Step 3: Limpiar dev-seed.php**

En `dev-seed.php`, eliminar el bloque completo de delegados (~líneas 93–124):

```php
// ELIMINAR todo este bloque:
// ── Delegados de club ────────────────────────────────────────────────────────
$delegados = [
    [
        'name'     => 'Jorge Muñoz',
        'email'    => 'j.munoz.delegado@test.local',
        'password' => 'Test1234!',
    ],
    // ... resto del array ...
];

foreach ( $delegados as $data ) {
    // ...
}
```

- [ ] **Step 4: Escaneo final — cero referencias restantes**

```bash
grep -rn "ds_delegado\|ds_manage_club\|ds_view_club_panel\|CAPS_DELEGADO\|delegado" \
  soccertrack/ --include="*.php" | grep -v "delegado_nombre\|delegado_rut\|delegado_correo\|delegado_celular"
```

Los únicos resultados permitidos son las columnas de la tabla `ds_teams` (`delegado_nombre`, etc.) que son campos de datos, no del rol.

- [ ] **Step 5: Commit**

```bash
git add soccertrack/uninstall.php soccertrack/dev-seed.php
git commit -m "feat: remove ds_delegado from uninstall and dev-seed"
```

---

### Task 4: Prueba de humo en el sitio

- [ ] **Step 1: Flushar rewrite rules** (solo si el sitio está corriendo)

```bash
wp rewrite flush --hard
```

- [ ] **Step 2: Verificar que los roles existentes no se rompen**

Iniciar sesión como `ds_coordinador` → debe acceder a `/panel/torneos/` sin error.  
Iniciar sesión como `ds_arbitro` → debe acceder a `/panel/` sin error.  
Iniciar sesión como `ds_planillero` → debe acceder a `/panel/` sin error.

- [ ] **Step 3: Verificar que un usuario con rol eliminado es redirigido**

Si existe algún usuario con `ds_delegado` en la BD, al intentar iniciar sesión debe recibir el mensaje de "No tienes permisos para acceder al panel de SoccerTrack" (wp_die 403) — porque ya no tiene ninguna capability que pase `user_has_panel_access()`.

- [ ] **Step 4: Commit final de limpieza (si hay ajustes)**

```bash
git add -p  # revisar cualquier cambio adicional
git commit -m "fix: cleanup after ds_delegado removal"
```

---

## Self-Review

**Spec coverage:**
- ✅ Permisos y perfiles de usuario → RolesManager + UserManager
- ✅ Validaciones de acceso → TournamentPage::user_has_panel_access()
- ✅ Interfaces asociadas al rol → header.php
- ✅ Flujos que dependan de este perfil → ninguno existe (el panel del delegado nunca se implementó)
- ✅ Endpoints o servicios relacionados → ningún endpoint chequea `ds_manage_club` o `ds_view_club_panel`
- ✅ Referencia en dev-seed → dev-seed.php
- ✅ Limpieza en uninstall → uninstall.php
- ✅ Columna `delegate_user_id` en ds_teams → conservada intencionalmente (campo de datos, no de rol)
- ✅ No afecta coordinador, árbitro ni planillero → verificado por escaneo de grep

**Posible omisión:** Usuarios existentes con rol `ds_delegado` en la BD no serán eliminados automáticamente (remove_role() solo elimina la definición del rol). Si se quiere una migración completa, agregar en la activación del plugin: `get_users(['role' => 'ds_delegado'])` y asignar rol `subscriber` o eliminar. **Evaluación: fuera del alcance por ahora** — si no hay usuarios delegados en producción, no es necesario.
