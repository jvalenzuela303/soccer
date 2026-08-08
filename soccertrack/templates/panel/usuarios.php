<?php
/**
 * Vista: Gestión de Usuarios — SoccerTrack Panel.
 *
 * Variables recibidas: $notice, $error, $users, $role_opts.
 *
 * @package SoccerTrack
 */

defined( 'ABSPATH' ) || exit;

$page_title = __( 'Usuarios', 'soccertrack' );
include SOCCERTRACK_DIR . 'templates/panel/_partials/header.php';
?>

<div class="st-page-header">
	<h1 class="st-page-title"><?php esc_html_e( 'Gestión de Usuarios', 'soccertrack' ); ?></h1>
</div>

<?php if ( $notice === 'created' ) : ?>
<div class="st-alert st-alert--success">
	✅ <?php esc_html_e( 'Usuario creado correctamente.', 'soccertrack' ); ?>
	<?php if ( ! empty( $created_password ) ) : ?>
	<br><strong><?php esc_html_e( 'Contraseña temporal:', 'soccertrack' ); ?></strong>
	<code style="background:#f0f0f0;padding:2px 8px;border-radius:4px;font-size:1em;user-select:all"><?php echo esc_html( $created_password ); ?></code>
	<small style="display:block;margin-top:4px;opacity:.8"><?php esc_html_e( 'Compártela con el usuario — desaparecerá al recargar la página.', 'soccertrack' ); ?></small>
	<?php endif; ?>
</div>
<?php elseif ( $notice === 'password_reset' ) : ?>
<div class="st-alert st-alert--success">
	🔑 <?php printf( esc_html__( 'Contraseña restablecida para %s.', 'soccertrack' ), '<strong>' . esc_html( $reset_user_name ?? '' ) . '</strong>' ); ?>
	<?php if ( ! empty( $created_password ) ) : ?>
	<br><strong><?php esc_html_e( 'Nueva contraseña:', 'soccertrack' ); ?></strong>
	<code style="background:#f0f0f0;padding:2px 8px;border-radius:4px;font-size:1em;user-select:all"><?php echo esc_html( $created_password ); ?></code>
	<small style="display:block;margin-top:4px;opacity:.8"><?php esc_html_e( 'Se ha enviado por correo al usuario. Desaparecerá al recargar la página.', 'soccertrack' ); ?></small>
	<?php endif; ?>
</div>
<?php elseif ( $notice === 'deleted' ) : ?>
<div class="st-alert st-alert--success">✅ <?php esc_html_e( 'Usuario eliminado correctamente.', 'soccertrack' ); ?></div>
<?php endif; ?>

<?php if ( $error ) : ?>
<div class="st-alert st-alert--error"><?php echo esc_html( $error ); ?></div>
<?php endif; ?>

<?php /* ── Formulario nuevo usuario ─────────────────────────────────── */ ?>
<div class="st-card">
	<h2 class="st-card-title"><?php esc_html_e( 'Agregar usuario', 'soccertrack' ); ?></h2>
	<form method="post" class="st-form-inline">
		<?php wp_nonce_field( 'st_user_action' ); ?>
		<input type="hidden" name="st_create_user" value="1">

		<div class="st-field">
			<label class="st-label"><?php esc_html_e( 'Nombre completo', 'soccertrack' ); ?> *</label>
			<input
				type="text"
				name="display_name"
				class="st-input"
				required
				placeholder="<?php esc_attr_e( 'Ej: Carlos González', 'soccertrack' ); ?>"
			>
		</div>

		<div class="st-field">
			<label class="st-label"><?php esc_html_e( 'Correo electrónico', 'soccertrack' ); ?> *</label>
			<input
				type="email"
				name="email"
				class="st-input"
				required
				placeholder="usuario@correo.com"
			>
		</div>

		<div class="st-field">
			<label class="st-label"><?php esc_html_e( 'Rol', 'soccertrack' ); ?> *</label>
			<select name="role" class="st-input" required>
				<option value=""><?php esc_html_e( '— Seleccionar rol —', 'soccertrack' ); ?></option>
				<?php foreach ( $role_opts as $slug => $label ) : ?>
				<option value="<?php echo esc_attr( $slug ); ?>"><?php echo esc_html( $label ); ?></option>
				<?php endforeach; ?>
			</select>
		</div>

		<p class="st-field-hint">
			<?php esc_html_e( 'Se generará una contraseña automática y se enviará por correo al usuario.', 'soccertrack' ); ?>
		</p>

		<button type="submit" class="st-btn st-btn--primary">
			<?php esc_html_e( '+ Agregar usuario', 'soccertrack' ); ?>
		</button>
	</form>
</div>

<?php /* ── Lista de usuarios ──────────────────────────────────────────── */ ?>
<div class="st-card">
	<h2 class="st-card-title"><?php esc_html_e( 'Usuarios del sistema', 'soccertrack' ); ?></h2>

	<?php if ( ! empty( $users ) ) : ?>
	<div class="st-filter-bar">
		<input
			type="search"
			id="st-user-search"
			class="st-input"
			placeholder="<?php esc_attr_e( 'Buscar por nombre o correo…', 'soccertrack' ); ?>"
			autocomplete="off"
		>
		<select id="st-role-filter" class="st-input" style="max-width:200px">
			<option value=""><?php esc_html_e( '— Todos los roles —', 'soccertrack' ); ?></option>
			<?php foreach ( $role_opts as $slug => $label ) : ?>
			<option value="<?php echo esc_attr( $slug ); ?>"><?php echo esc_html( $label ); ?></option>
			<?php endforeach; ?>
		</select>
		<button type="button" id="st-search-btn" class="st-btn st-btn--primary">
			🔍 <?php esc_html_e( 'Buscar', 'soccertrack' ); ?>
		</button>
		<button type="button" id="st-clear-btn" class="st-btn st-btn--secondary" style="display:none">
			✕ <?php esc_html_e( 'Limpiar', 'soccertrack' ); ?>
		</button>
		<span id="st-filter-count" style="font-size:.85rem;color:#888;margin-left:4px"></span>
	</div>
	<?php endif; ?>

	<?php if ( empty( $users ) ) : ?>
	<p class="st-empty-state"><?php esc_html_e( 'No hay usuarios registrados aún.', 'soccertrack' ); ?></p>
	<?php else : ?>

	<div class="st-table-responsive">
		<p id="st-no-results" class="st-empty-msg" style="display:none">
			<?php esc_html_e( 'No se encontraron usuarios con esos criterios.', 'soccertrack' ); ?>
		</p>
		<table class="st-table" id="st-users-table">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Nombre', 'soccertrack' ); ?></th>
					<th><?php esc_html_e( 'Correo', 'soccertrack' ); ?></th>
					<th><?php esc_html_e( 'Rol', 'soccertrack' ); ?></th>
					<th><?php esc_html_e( 'Registrado', 'soccertrack' ); ?></th>
					<th><?php esc_html_e( 'Acciones', 'soccertrack' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $users as $u ) : ?>
				<tr data-name="<?php echo esc_attr( mb_strtolower( $u['name'] ) ); ?>"
					data-email="<?php echo esc_attr( mb_strtolower( $u['email'] ) ); ?>"
					data-role="<?php echo esc_attr( $u['role'] ); ?>">
					<td><?php echo esc_html( $u['name'] ); ?></td>
					<td><?php echo esc_html( $u['email'] ); ?></td>
					<td>
						<span class="st-badge st-badge--<?php echo esc_attr( $u['role'] ); ?>">
							<?php echo esc_html( $u['role_label'] ); ?>
						</span>
					</td>
					<td><?php echo esc_html( wp_date( 'd/m/Y', strtotime( $u['registered'] ) ) ); ?></td>
					<td style="display:flex;gap:6px;flex-wrap:wrap;align-items:center">
						<form method="post" style="display:inline"
							  onsubmit="return confirm('<?php esc_attr_e( '¿Resetear la contraseña de este usuario? Se generará una nueva clave y se enviará por correo.', 'soccertrack' ); ?>')">
							<?php wp_nonce_field( 'st_user_action' ); ?>
							<input type="hidden" name="st_reset_password" value="1">
							<input type="hidden" name="user_id" value="<?php echo esc_attr( (string) $u['id'] ); ?>">
							<button type="submit" class="st-btn st-btn--secondary st-btn--sm">
								🔑 <?php esc_html_e( 'Recuperar clave', 'soccertrack' ); ?>
							</button>
						</form>
						<form method="post" style="display:inline"
							  onsubmit="return confirm('<?php esc_attr_e( '¿Eliminar este usuario?', 'soccertrack' ); ?>')">
							<?php wp_nonce_field( 'st_user_action' ); ?>
							<input type="hidden" name="st_delete_user" value="1">
							<input type="hidden" name="user_id" value="<?php echo esc_attr( (string) $u['id'] ); ?>">
							<button type="submit" class="st-btn st-btn--danger st-btn--sm">
								<?php esc_html_e( 'Eliminar', 'soccertrack' ); ?>
							</button>
						</form>
					</td>
				</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	</div>
	<?php endif; ?>
</div>

<script>
( () => {
	const searchInput  = document.getElementById( 'st-user-search' );
	const roleFilter   = document.getElementById( 'st-role-filter' );
	const searchBtn    = document.getElementById( 'st-search-btn' );
	const clearBtn     = document.getElementById( 'st-clear-btn' );
	const countLabel   = document.getElementById( 'st-filter-count' );
	const noResults    = document.getElementById( 'st-no-results' );
	const table        = document.getElementById( 'st-users-table' );

	if ( ! searchBtn ) return; // no hay usuarios

	const applyFilter = () => {
		const q    = ( searchInput.value ?? '' ).trim().toLowerCase();
		const role = roleFilter.value;
		const rows = table.querySelectorAll( 'tbody tr' );
		let visible = 0;

		rows.forEach( row => {
			const name  = row.dataset.name  ?? '';
			const email = row.dataset.email ?? '';
			const rRole = row.dataset.role  ?? '';

			const matchText = ! q || name.includes( q ) || email.includes( q );
			const matchRole = ! role || rRole === role;

			const show = matchText && matchRole;
			row.style.display = show ? '' : 'none';
			if ( show ) visible++;
		} );

		const total = rows.length;
		if ( q || role ) {
			countLabel.textContent = `${visible} de ${total}`;
			clearBtn.style.display = '';
			if ( noResults ) noResults.style.display = visible === 0 ? '' : 'none';
		} else {
			countLabel.textContent = '';
			clearBtn.style.display = 'none';
			if ( noResults ) noResults.style.display = 'none';
		}
	};

	searchBtn.addEventListener( 'click', applyFilter );

	// También aplicar con Enter en el campo de texto
	searchInput.addEventListener( 'keydown', e => {
		if ( e.key === 'Enter' ) { e.preventDefault(); applyFilter(); }
	} );

	clearBtn.addEventListener( 'click', () => {
		searchInput.value = '';
		roleFilter.value  = '';
		applyFilter();
		searchInput.focus();
	} );
} )();
</script>

<?php include SOCCERTRACK_DIR . 'templates/panel/_partials/footer.php'; ?>
