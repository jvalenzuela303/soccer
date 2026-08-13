# Banner Publicitario por Torneo — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Permitir asociar una imagen de banner (JPG/PNG/WebP, máx 5 MB) a cada torneo, gestionable desde el panel de administración y visible en la página pública del torneo.

**Architecture:** Se añade la columna `banner_url VARCHAR(500) NULL` a `ds_tournaments` mediante la migración idempotente existente en `DatabaseInstaller.php`. El upload sigue el patrón establecido por `st_save_bases` (wp_handle_upload, MIME real validado con mime_content_type, nombre único). El banner se muestra en `tournament-page.php` antes del header si la columna no es NULL.

**Tech Stack:** PHP 8.2, WordPress `wp_handle_upload()`, `dbDelta()`, vanilla JS (FileReader preview), CSS responsive.

## Global Constraints

- PHP 8.2 — usar `str_ends_with`, match, nullsafe operator donde aplique
- WordPress Coding Standards (PHPCS): nonces en todo POST, `esc_*` en todo output, `$wpdb->prepare()` en queries con variables
- `dbDelta()` para schema; alteraciones adicionales via `apply_index_migrations()` (idempotente con `SHOW COLUMNS LIKE`)
- Prefijo de tablas: `{$wpdb->prefix}ds_`; columnas del torneo en `ds_tournaments`
- Formatos permitidos: JPG, JPEG, PNG, WebP — MIME validado con `mime_content_type()` en tmp_name
- Tamaño máximo: 5 MB (5 × 1024 × 1024 bytes)
- Nombre de archivo seguro: `banner_{id}_{Ymd}_{6chars}.{ext}`
- Banner es opcional — torneos sin banner siguen funcionando sin cambio visible
- No modificar la lógica de fixtures, posiciones ni tribunales

---

## File Map

| Archivo | Acción | Responsabilidad |
|---------|--------|-----------------|
| `soccertrack/soccertrack.php` | Modificar | Bump `SOCCERTRACK_DB_VERSION` a `'2.2.0'` |
| `soccertrack/includes/Core/DatabaseInstaller.php` | Modificar | Añadir migración `banner_url` en `apply_index_migrations()` |
| `soccertrack/includes/Public/TournamentPage.php` | Modificar | Handlers `st_save_banner` y `st_delete_banner` en `view_torneo()`; banner upload opcional en `view_torneos()` create handler |
| `soccertrack/templates/panel/torneo-detalle.php` | Modificar | Card de banner con preview, upload y delete |
| `soccertrack/templates/panel/torneos.php` | Modificar | Campo banner opcional en formulario crear torneo |
| `soccertrack/templates/public/tournament-page.php` | Modificar | Mostrar banner antes del header si existe |
| `soccertrack/assets/css/tournament-page.css` | Modificar | Estilos `.st-tournament-banner` responsive |

---

## Task 1: DB Migration — columna `banner_url`

**Files:**
- Modify: `soccertrack/soccertrack.php` (línea 39)
- Modify: `soccertrack/includes/Core/DatabaseInstaller.php` (al final de `apply_index_migrations()`, antes de la llave de cierre, línea ~648)

**Interfaces:**
- Produces: columna `banner_url VARCHAR(500) NULL DEFAULT NULL` en `wp_ds_tournaments`; constante `SOCCERTRACK_DB_VERSION = '2.2.0'`; los SELECTs con `SELECT *` existentes ya retornan el campo automáticamente

- [ ] **Step 1: Bump DB version**

En `soccertrack/soccertrack.php` línea 39, cambiar:
```php
define( 'SOCCERTRACK_DB_VERSION', '2.1.0' );
```
por:
```php
define( 'SOCCERTRACK_DB_VERSION', '2.2.0' );
```

- [ ] **Step 2: Añadir migración idempotente al final de `apply_index_migrations()`**

En `soccertrack/includes/Core/DatabaseInstaller.php`, al final del método `apply_index_migrations()`, antes de la llave de cierre `}` (línea 649):

```php
		// v2.2.0 — ds_tournaments: banner publicitario por torneo.
		$has_banner = $wpdb->get_var( "SHOW COLUMNS FROM {$prefix}ds_tournaments LIKE 'banner_url'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		if ( ! $has_banner ) {
			$wpdb->query( "ALTER TABLE {$prefix}ds_tournaments ADD COLUMN banner_url VARCHAR(500) NULL DEFAULT NULL AFTER bases_pdf_url" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}
```

- [ ] **Step 3: Verificar migración**

Activar/desactivar el plugin o navegar al panel (dispara `maybe_upgrade()`).
Luego verificar con WP-CLI o phpMyAdmin:
```sql
SHOW COLUMNS FROM wp_ds_tournaments LIKE 'banner_url';
```
Resultado esperado: una fila con `Field=banner_url, Type=varchar(500), Null=YES, Default=NULL`.

- [ ] **Step 4: Verificar idempotencia**

Navegar al panel nuevamente (segunda ejecución de `maybe_upgrade()`).
No debe producirse error de MySQL. La columna sigue existiendo con los mismos atributos.

- [ ] **Step 5: Commit**

```bash
git add soccertrack/soccertrack.php soccertrack/includes/Core/DatabaseInstaller.php
git commit -m "feat(db): add banner_url column to ds_tournaments — v2.2.0"
```

---

## Task 2: Backend — Handlers `st_save_banner` y `st_delete_banner`

**Files:**
- Modify: `soccertrack/includes/Public/TournamentPage.php` — en `view_torneo()`, después del bloque `st_save_bases` (línea ~591)

**Interfaces:**
- Consumes: `$id` (int, tournament ID ya validado), `$wpdb`, patrón `wp_handle_upload` idéntico al de `st_save_bases`
- Produces: `$notice` = `'banner_saved'` | `'banner_deleted'`; `$error` string en fallo; `$tournament['banner_url']` actualizado tras cada operación

- [ ] **Step 1: Añadir handler `st_save_banner` en `view_torneo()`**

En `TournamentPage.php`, inmediatamente después del bloque `st_save_bases` (que termina en la línea que hace `$tournament = $wpdb->get_row(...)`), añadir:

```php
		// ── Guardar / subir banner del torneo ─────────────────────────
		if ( $_SERVER['REQUEST_METHOD'] === 'POST' && isset( $_POST['st_save_banner'] ) ) {
			check_admin_referer( 'st_save_banner_' . $id );

			if ( empty( $_FILES['banner_file']['name'] ) ) {
				$error = __( 'Selecciona una imagen para el banner.', 'soccertrack' );
			} else {
				if ( ! function_exists( 'wp_handle_upload' ) ) {
					require_once ABSPATH . 'wp-admin/includes/file.php';
				}

				$file      = $_FILES['banner_file'];
				$allowed   = [ 'image/jpeg', 'image/png', 'image/webp' ];
				$mime_type = mime_content_type( $file['tmp_name'] );
				$max_bytes = 5 * 1024 * 1024; // 5 MB

				if ( ! in_array( $mime_type, $allowed, true ) ) {
					$error = __( 'Solo se permiten imágenes JPG, PNG o WebP.', 'soccertrack' );
				} elseif ( $file['size'] > $max_bytes ) {
					$error = __( 'El banner no puede superar 5 MB.', 'soccertrack' );
				} else {
					add_filter( 'upload_mimes', static function ( $mimes ) {
						$mimes['jpg|jpeg|jpe'] = 'image/jpeg';
						$mimes['png']          = 'image/png';
						$mimes['webp']         = 'image/webp';
						return $mimes;
					} );

					$ext        = strtolower( pathinfo( $file['name'], PATHINFO_EXTENSION ) );
					$file['name'] = sprintf(
						'banner_%d_%s_%s.%s',
						$id,
						gmdate( 'Ymd' ),
						wp_generate_password( 6, false ),
						$ext
					);

					$uploaded = wp_handle_upload( $file, [ 'test_form' => false ] );

					if ( isset( $uploaded['error'] ) ) {
						$error = $uploaded['error'];
					} elseif ( isset( $uploaded['url'] ) ) {
						$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
							"{$wpdb->prefix}ds_tournaments",
							[ 'banner_url' => esc_url_raw( $uploaded['url'] ) ],
							[ 'id' => $id ],
							[ '%s' ],
							[ '%d' ]
						);
						$notice = 'banner_saved';
					}
				}
			}

			// Refrescar datos del torneo.
			$tournament = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$wpdb->prepare( "SELECT * FROM {$wpdb->prefix}ds_tournaments WHERE id = %d", $id ),
				ARRAY_A
			);
		}

		// ── Eliminar banner del torneo ────────────────────────────────
		if ( $_SERVER['REQUEST_METHOD'] === 'POST' && isset( $_POST['st_delete_banner'] ) ) {
			check_admin_referer( 'st_delete_banner_' . $id );

			$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$wpdb->prepare(
					"UPDATE {$wpdb->prefix}ds_tournaments SET banner_url = NULL WHERE id = %d",
					$id
				)
			);
			$notice = 'banner_deleted';

			$tournament = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$wpdb->prepare( "SELECT * FROM {$wpdb->prefix}ds_tournaments WHERE id = %d", $id ),
				ARRAY_A
			);
		}
```

- [ ] **Step 2: Verificar handler save — subir banner válido**

1. Ir a `/panel/torneos/{id}/` de cualquier torneo.
2. Usar DevTools → Network para confirmar que el POST incluye `banner_file` (form con `enctype` — se añadirá en Task 3).
3. Verificar en la BD: `SELECT banner_url FROM wp_ds_tournaments WHERE id = {id};` debe mostrar la URL del archivo subido.
4. Verificar que el archivo existe en `wp-content/uploads/`.

- [ ] **Step 3: Verificar handler delete**

1. Con un torneo que tenga `banner_url` no nulo, enviar POST con `st_delete_banner`.
2. Verificar en la BD: `SELECT banner_url FROM wp_ds_tournaments WHERE id = {id};` debe mostrar `NULL`.

- [ ] **Step 4: Verificar rechazo de archivos inválidos**

1. Intentar subir un `.gif` → debe mostrar error "Solo se permiten imágenes JPG, PNG o WebP."
2. Intentar subir un archivo > 5 MB → debe mostrar error "El banner no puede superar 5 MB."
3. Intentar subir un `.php` renombrado como `.jpg` → `mime_content_type()` detecta el MIME real y rechaza.

- [ ] **Step 5: Commit**

```bash
git add soccertrack/includes/Public/TournamentPage.php
git commit -m "feat(backend): add st_save_banner and st_delete_banner handlers in view_torneo"
```

---

## Task 3: Admin Panel — Card de banner en `torneo-detalle.php`

**Files:**
- Modify: `soccertrack/templates/panel/torneo-detalle.php`

**Interfaces:**
- Consumes: `$tournament['banner_url']` (string|null), `$tournament['id']` (int), `$notice`, `$is_locked`
- Produces: formulario `st_save_banner` (POST multipart) y formulario `st_delete_banner`; preview JS inline

- [ ] **Step 1: Añadir notices de banner al bloque de notices (inicio del archivo)**

En `torneo-detalle.php`, después de la línea `<?php if ( ( $notice ?? '' ) === 'reg_mode_updated' )` (antes de la línea 33 con `$error`), añadir:

```php
<?php if ( ( $notice ?? '' ) === 'banner_saved' ) : ?>
	<div class="st-alert st-alert--success">✅ <?php esc_html_e( 'Banner guardado correctamente.', 'soccertrack' ); ?></div>
<?php endif; ?>
<?php if ( ( $notice ?? '' ) === 'banner_deleted' ) : ?>
	<div class="st-alert st-alert--success">🗑️ <?php esc_html_e( 'Banner eliminado.', 'soccertrack' ); ?></div>
<?php endif; ?>
```

- [ ] **Step 2: Añadir card de banner al final del template (después del card de Bases, línea 1224)**

Después de `</div>` que cierra el card de Bases (línea 1224) y antes de `<script>` (línea 1226), insertar:

```php
<?php /* ── Banner del torneo ─────────────────────────────────────────── */ ?>
<div class="st-card" style="margin-bottom:20px">
	<div class="st-card-header">
		<h2 class="st-card-title">🖼️ <?php esc_html_e( 'Banner del torneo', 'soccertrack' ); ?></h2>
	</div>

	<?php if ( ! empty( $tournament['banner_url'] ) ) : ?>
	<div style="margin-bottom:16px">
		<img
			src="<?php echo esc_url( $tournament['banner_url'] ); ?>"
			alt="<?php esc_attr_e( 'Banner actual', 'soccertrack' ); ?>"
			style="max-width:100%;height:auto;border-radius:6px;border:1px solid #e5e7eb;display:block"
		>
	</div>
	<form method="post" action="" style="margin-bottom:16px">
		<?php wp_nonce_field( 'st_delete_banner_' . $tournament['id'] ); ?>
		<input type="hidden" name="st_delete_banner" value="1">
		<button
			type="submit"
			class="st-btn"
			style="background:#dc2626;color:#fff;border:none"
			onclick="return confirm('<?php esc_attr_e( '¿Eliminar el banner actual?', 'soccertrack' ); ?>')"
		>
			🗑️ <?php esc_html_e( 'Eliminar banner', 'soccertrack' ); ?>
		</button>
	</form>
	<hr style="margin:0 0 16px;border:none;border-top:1px solid #e5e7eb">
	<?php endif; ?>

	<form method="post" action="" enctype="multipart/form-data">
		<?php wp_nonce_field( 'st_save_banner_' . $tournament['id'] ); ?>
		<input type="hidden" name="st_save_banner" value="1">

		<div class="st-field" style="margin-bottom:16px">
			<label for="st-banner-file" class="st-label">
				📤 <?php empty( $tournament['banner_url'] )
					? esc_html_e( 'Seleccionar imagen', 'soccertrack' )
					: esc_html_e( 'Reemplazar imagen', 'soccertrack' ); ?>
			</label>
			<input
				type="file"
				id="st-banner-file"
				name="banner_file"
				class="st-input"
				accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp"
				onchange="stPreviewBanner(this)"
			>
			<small class="st-hint">
				<?php esc_html_e( 'Formatos: JPG, PNG, WebP — Máx. 5 MB — Dimensión recomendada: 1920 × 500 px', 'soccertrack' ); ?>
			</small>
		</div>

		<div id="st-banner-preview-box" style="display:none;margin-bottom:16px">
			<p class="st-label" style="margin-bottom:8px"><?php esc_html_e( 'Vista previa:', 'soccertrack' ); ?></p>
			<img
				id="st-banner-preview-img"
				src=""
				alt=""
				style="max-width:100%;height:auto;border-radius:6px;border:1px solid #e5e7eb;display:block"
			>
		</div>

		<button type="submit" class="st-btn st-btn--primary">
			💾 <?php esc_html_e( 'Guardar banner', 'soccertrack' ); ?>
		</button>
	</form>
</div>

<script>
function stPreviewBanner(input) {
	var box = document.getElementById('st-banner-preview-box');
	var img = document.getElementById('st-banner-preview-img');
	if (input.files && input.files[0]) {
		var reader = new FileReader();
		reader.onload = function(e) {
			img.src = e.target.result;
			box.style.display = '';
		};
		reader.readAsDataURL(input.files[0]);
	} else {
		box.style.display = 'none';
	}
}
</script>
```

- [ ] **Step 3: Verificar UI en torneo sin banner**

1. Ir a `/panel/torneos/{id}/` de un torneo sin banner.
2. Debe mostrarse el card con input file, hint de dimensiones, sin sección de preview ni botón eliminar.
3. Seleccionar una imagen válida → debe aparecer la vista previa inmediatamente (sin guardar).

- [ ] **Step 4: Verificar UI en torneo con banner**

1. Guardar un banner válido (formulario del paso anterior).
2. Recargar la página → debe mostrarse la imagen actual + botón "Eliminar banner" + formulario de reemplazo.
3. Hacer clic en "Eliminar banner" → confirmar → banner_url = NULL → la imagen desaparece del card.

- [ ] **Step 5: Verificar bloqueo cuando torneo está finalizado**

El CSS de `is_locked` ya afecta a `form button` dentro de `.st-card`. Verificar que en un torneo finalizado ambos botones (Guardar y Eliminar) aparecen en `opacity:0.45` y no responden al clic.

- [ ] **Step 6: Commit**

```bash
git add soccertrack/templates/panel/torneo-detalle.php
git commit -m "feat(panel): banner management card in tournament detail — upload, preview, delete"
```

---

## Task 4: Admin Panel — Campo banner en formulario crear torneo

**Files:**
- Modify: `soccertrack/templates/panel/torneos.php` (form crear torneo)
- Modify: `soccertrack/includes/Public/TournamentPage.php` — create handler en `view_torneos()` (líneas 449–465)

**Interfaces:**
- Consumes: `$_FILES['banner_file']`, `$wpdb->insert_id` después de crear torneo
- Produces: torneo creado con `banner_url` ya guardado si se subió imagen; `$notice = 'created'` igual que antes

- [ ] **Step 1: Añadir `enctype` y campo banner al form de crear torneo en `torneos.php`**

Cambiar la etiqueta del form (línea 24):
```php
	<form method="post" class="st-form-inline">
```
por:
```php
	<form method="post" class="st-form-inline" enctype="multipart/form-data">
```

Añadir el campo banner antes del `<button type="submit">` (antes de la línea 76):
```php
		<div class="st-field" style="flex-basis:100%">
			<label class="st-label">
				🖼️ <?php esc_html_e( 'Banner (opcional)', 'soccertrack' ); ?>
			</label>
			<input
				type="file"
				name="banner_file"
				class="st-input"
				accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp"
				onchange="stPreviewCreateBanner(this)"
			>
			<small class="st-hint">
				<?php esc_html_e( 'JPG, PNG, WebP — Máx. 5 MB — 1920 × 500 px. Puede agregarse o cambiarse después.', 'soccertrack' ); ?>
			</small>
			<div id="st-create-banner-preview" style="display:none;margin-top:8px">
				<img id="st-create-banner-img" src="" alt="" style="max-width:100%;max-height:120px;border-radius:6px;border:1px solid #e5e7eb;object-fit:cover">
			</div>
		</div>
```

Añadir el script de preview al final del bloque `<script>` existente (dentro de la misma tag `<script>` que ya existe o en una nueva):
```js
function stPreviewCreateBanner(input) {
	var box = document.getElementById('st-create-banner-preview');
	var img = document.getElementById('st-create-banner-img');
	if (input.files && input.files[0]) {
		var reader = new FileReader();
		reader.onload = function(e) { img.src = e.target.result; box.style.display = ''; };
		reader.readAsDataURL(input.files[0]);
	} else {
		box.style.display = 'none';
	}
}
```

- [ ] **Step 2: Manejar banner en create handler de `TournamentPage.php`**

En `view_torneos()`, después de `$wpdb->insert(...)` (línea 465) y antes de `$notice = 'created'` (línea 466), añadir:

```php
				// Banner opcional al crear el torneo.
				$new_id = (int) $wpdb->insert_id;
				if ( $new_id && ! empty( $_FILES['banner_file']['name'] ) ) {
					if ( ! function_exists( 'wp_handle_upload' ) ) {
						require_once ABSPATH . 'wp-admin/includes/file.php';
					}
					$bfile     = $_FILES['banner_file'];
					$allowed   = [ 'image/jpeg', 'image/png', 'image/webp' ];
					$mime_type = mime_content_type( $bfile['tmp_name'] );

					if ( in_array( $mime_type, $allowed, true ) && $bfile['size'] <= 5 * 1024 * 1024 ) {
						add_filter( 'upload_mimes', static function ( $mimes ) {
							$mimes['jpg|jpeg|jpe'] = 'image/jpeg';
							$mimes['png']          = 'image/png';
							$mimes['webp']         = 'image/webp';
							return $mimes;
						} );
						$ext           = strtolower( pathinfo( $bfile['name'], PATHINFO_EXTENSION ) );
						$bfile['name'] = sprintf( 'banner_%d_%s_%s.%s', $new_id, gmdate( 'Ymd' ), wp_generate_password( 6, false ), $ext );
						$uploaded      = wp_handle_upload( $bfile, [ 'test_form' => false ] );
						if ( isset( $uploaded['url'] ) ) {
							$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
								"{$wpdb->prefix}ds_tournaments",
								[ 'banner_url' => esc_url_raw( $uploaded['url'] ) ],
								[ 'id' => $new_id ],
								[ '%s' ],
								[ '%d' ]
							);
						}
					}
					// Errores de banner al crear se ignoran silenciosamente — el torneo ya se creó.
				}
```

- [ ] **Step 3: Verificar crear torneo sin banner**

1. Completar el form de nuevo torneo sin adjuntar imagen.
2. El torneo se crea normalmente con `banner_url = NULL`.
3. Verificar en BD: `SELECT banner_url FROM wp_ds_tournaments ORDER BY id DESC LIMIT 1;` → NULL.

- [ ] **Step 4: Verificar crear torneo con banner**

1. Adjuntar una imagen válida al formulario.
2. El torneo se crea y redirige con `notice=created`.
3. Verificar en BD: `banner_url` contiene la URL del archivo subido.
4. Ir a la ficha del torneo → el card de banner muestra la imagen recién subida.

- [ ] **Step 5: Commit**

```bash
git add soccertrack/templates/panel/torneos.php soccertrack/includes/Public/TournamentPage.php
git commit -m "feat(panel): optional banner upload in tournament create form"
```

---

## Task 5: Portal Público — Mostrar banner en `tournament-page.php`

**Files:**
- Modify: `soccertrack/templates/public/tournament-page.php`
- Modify: `soccertrack/assets/css/tournament-page.css`

**Interfaces:**
- Consumes: `$tournament['banner_url']` (string|null) — disponible desde `SELECT *` en `TournamentPage::view_torneo()`
- Produces: bloque `.st-tournament-banner` visible antes del `<header>` si hay banner; invisible si no hay banner

- [ ] **Step 1: Añadir bloque banner en `tournament-page.php` antes del `<header>`**

El `<header class="st-public-header">` empieza en línea 21. Insertar antes de esa línea:

```php
<?php if ( ! empty( $tournament['banner_url'] ) ) : ?>
<div class="st-tournament-banner">
	<img
		src="<?php echo esc_url( $tournament['banner_url'] ); ?>"
		alt="<?php echo esc_attr( sprintf(
			/* translators: %s: tournament name */
			__( 'Banner de %s', 'soccertrack' ),
			$tournament['name']
		) ); ?>"
		class="st-tournament-banner__img"
	>
</div>
<?php endif; ?>
```

- [ ] **Step 2: Añadir CSS en `tournament-page.css`**

Al final del archivo agregar:

```css
/* ── Banner publicitario del torneo ─────────────────────────── */
.st-tournament-banner {
	width: 100%;
	line-height: 0;
	background: #0E0C19;
	overflow: hidden;
}

.st-tournament-banner__img {
	display: block;
	width: 100%;
	max-height: 300px;
	object-fit: cover;
	object-position: center top;
}

@media (max-width: 768px) {
	.st-tournament-banner__img {
		max-height: 160px;
	}
}

@media (max-width: 480px) {
	.st-tournament-banner__img {
		max-height: 120px;
	}
}
```

- [ ] **Step 3: Verificar portal con banner**

1. Ir a `/torneo/{id}/` de un torneo con banner configurado.
2. El banner debe aparecer a full-width antes del header.
3. Cambiar el viewport a 768 px → banner máx 160 px de alto.
4. Cambiar a 375 px (mobile) → banner máx 120 px, sin scroll horizontal.

- [ ] **Step 4: Verificar portal sin banner**

1. Ir a `/torneo/{id}/` de un torneo sin banner.
2. El bloque `.st-tournament-banner` no debe aparecer en el DOM.
3. El header aparece exactamente como antes, sin espacio vacío.

- [ ] **Step 5: Verificar todos los torneos existentes no se rompen**

1. Ir a la lista de torneos en el panel.
2. Abrir el portal público de un torneo sin banner (existente antes de esta feature).
3. Todas las pestañas (Posiciones, Fixture, Playoffs, Equipos, Goleadores, Tribunal) deben funcionar normalmente.

- [ ] **Step 6: Commit**

```bash
git add soccertrack/templates/public/tournament-page.php soccertrack/assets/css/tournament-page.css
git commit -m "feat(portal): display tournament banner above header when configured"
```

---

## Self-Review — Spec Coverage

| Sección spec | Tarea |
|---|---|
| RF-01 — Banner asociado al torneo | Task 1 (columna DB) |
| RF-02 — Banner en creación de torneo | Task 4 |
| RF-03 — Edición: ver/reemplazar/eliminar | Task 3 |
| §4 Visualización pública, ubicación arriba | Task 5 |
| §5 JPG/PNG/WebP, máx 5 MB, 1:1 banner/torneo | Tasks 2, 3, 4 |
| §6 Modelo: banner_url VARCHAR nullable | Task 1 |
| §7 Almacenamiento en filesystem (wp_handle_upload) | Task 2 |
| §8 Backend validación | Task 2 |
| §9 MIME real, tamaño, nombre seguro | Task 2 |
| §10 Frontend admin: preview inmediato | Tasks 3, 4 |
| §11 Frontend público: banner o nada | Task 5 |
| §12 Responsive (desktop/tablet/mobile) | Task 5 (CSS) |
| §13 Seguridad: MIME real, tamaño, nonce, permisos | Tasks 2, 3 |
| §14 Permisos: solo quien puede editar torneo | Heredado del guard de `view_torneo()` existente (`ds_view_admin_panel`) |
| §15 Torneos existentes: banner_url = NULL, sin cambio | Task 1 (NULL DEFAULT), Task 5 (if-not-empty) |
| §16 Criterios de aceptación (14 items) | Cubiertos en steps de verificación Tasks 1–5 |
