<?php
/**
 * Formulario de Orden de Ingreso (OI) — Panel unificado.
 *
 * Variables esperadas:
 *   $evento_id      int          0 = nueva, >0 = editar.
 *   $errors         array        Errores de validación por campo.
 *   $valores        array        Valores a pre-poblar.
 *   $equipos        WP_Post[]    Equipos para el select.
 *   $clientes       WP_Post[]    Clientes para el select.
 *   $cliente_actual int          Cliente pre-seleccionado (derivado del equipo).
 *   $tecnicos       WP_User[]    Técnicos para el select.
 *   $tipos_ev       array        Tipos de evento slug => label.
 *   $ots_vinculadas WP_Post[]    (opcional) OTs vinculadas a esta OI.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$evento_id      = isset( $evento_id ) ? (int) $evento_id : 0;
$errors         = isset( $errors ) ? $errors : array();
$valores        = isset( $valores ) ? $valores : array();
$equipos        = isset( $equipos ) ? $equipos : array();
$clientes       = isset( $clientes ) ? $clientes : array();
$cliente_actual = isset( $cliente_actual ) ? (int) $cliente_actual : 0;
$tecnicos       = isset( $tecnicos ) ? $tecnicos : array();
$tipos_ev       = isset( $tipos_ev ) ? $tipos_ev : array();
$ots_vinculadas  = isset( $ots_vinculadas ) ? $ots_vinculadas : array();
$es_solo_lectura = isset( $es_solo_lectura ) && $es_solo_lectura;

$es_nueva   = ( 0 === $evento_id );
$page_title = $es_solo_lectura
	? sprintf( __( 'Orden de Ingreso #%d', 'calibratrack' ), $evento_id )
	: ( $es_nueva
		? __( 'Nueva Orden de Ingreso', 'calibratrack' )
		: sprintf( __( 'Editar OI #%d', 'calibratrack' ), $evento_id ) );

$v = function( $key, $default = '' ) use ( $valores ) {
	return isset( $valores[ $key ] ) ? $valores[ $key ] : $default;
};
$e = function( $key ) use ( $errors ) {
	return isset( $errors[ $key ] ) ? $errors[ $key ] : '';
};

// URL del formulario según si es nueva o edición.
$form_action = $es_nueva
	? home_url( '/panel/nueva-oi/' )
	: home_url( '/panel/oi/' . $evento_id . '/' );

include __DIR__ . '/_partials/header.php';
?>

<div class="ct-container">
	<div class="ct-page-header">
		<h1 class="ct-page-title"><?php echo esc_html( $page_title ); ?></h1>
		<a href="<?php echo esc_url( home_url( '/panel/' ) ); ?>" class="ct-link ct-no-print">
			&larr; <?php esc_html_e( 'Volver al panel', 'calibratrack' ); ?>
		</a>
	</div>

	<?php if ( isset( $_GET['guardado'] ) ) : ?>
		<div class="ct-alert ct-alert--success" role="alert">
			<?php esc_html_e( 'Orden de Ingreso guardada correctamente.', 'calibratrack' ); ?>
		</div>
	<?php endif; ?>

	<?php if ( ! empty( $errors['general'] ) ) : ?>
		<div class="ct-alert ct-alert--error" role="alert">
			<?php echo esc_html( $errors['general'] ); ?>
		</div>
	<?php endif; ?>

	<?php if ( ! $es_nueva && ! empty( $ots_vinculadas ) ) : ?>
	<div class="ct-section" style="margin-bottom:24px;">
		<div class="ct-section-header">
			<h2 class="ct-section-title"><?php esc_html_e( 'Órdenes de Trabajo vinculadas', 'calibratrack' ); ?></h2>
		</div>
		<ul style="list-style:none;margin:0;padding:0;display:flex;flex-wrap:wrap;gap:8px;">
			<?php foreach ( $ots_vinculadas as $ot_v ) :
				$num_ot = get_post_meta( $ot_v->ID, CalibraTrack_Meta_Keys::EVENTO_NUMERO_OT, true );
			?>
			<li>
				<a href="<?php echo esc_url( home_url( '/panel/ot/' . $ot_v->ID . '/' ) ); ?>" class="ct-btn ct-btn--sm">
					<?php echo esc_html( $num_ot ?: 'OT-' . $ot_v->ID ); ?>
				</a>
			</li>
			<?php endforeach; ?>
		</ul>
	</div>
	<?php endif; ?>

	<?php if ( $es_solo_lectura ) : ?>
	<!-- Vista de solo lectura para el técnico -->
	<div class="ct-form">
		<?php $disabled = 'disabled'; ?>
	<?php else : ?>
	<form method="post" action="<?php echo esc_url( $form_action ); ?>" enctype="multipart/form-data" class="ct-form" novalidate>
		<?php wp_nonce_field( 'calibratrack_admin_oi' ); ?>
		<?php $disabled = ''; ?>
	<?php endif; ?>

		<div <?php if ( $es_solo_lectura ) : ?>style="pointer-events:none;opacity:.9;"<?php endif; ?>>

		<!-- Cliente -->
		<div class="ct-field">
			<label class="ct-label"><?php esc_html_e( 'Cliente', 'calibratrack' ); ?></label>
			<div style="display:flex;gap:8px;align-items:center;">
				<select id="ct-cliente-select" class="ct-select" style="flex:1;" <?php echo esc_attr( $disabled ); ?>>
					<option value="0"><?php esc_html_e( '— Todos los equipos —', 'calibratrack' ); ?></option>
					<?php foreach ( $clientes as $cl ) :
						$nombre_cl = (string) get_post_meta( $cl->ID, CalibraTrack_Meta_Keys::CLIENTE_NOMBRE_EMPRESA, true );
					?>
					<option value="<?php echo esc_attr( $cl->ID ); ?>" <?php selected( $cliente_actual, $cl->ID ); ?>>
						<?php echo esc_html( $nombre_cl ?: $cl->post_title ); ?>
					</option>
					<?php endforeach; ?>
				</select>
				<?php if ( ! $es_solo_lectura && current_user_can( 'manage_options' ) ) : ?>
				<button type="button" id="ct-btn-nuevo-cliente" class="ct-btn ct-btn--sm"
					title="<?php esc_attr_e( 'Crear nuevo cliente', 'calibratrack' ); ?>">
					<?php esc_html_e( '+ Cliente', 'calibratrack' ); ?>
				</button>
				<?php endif; ?>
			</div>
			<p style="color:#6b7280;font-size:12px;margin:4px 0 0;">
				<?php esc_html_e( 'Filtra los equipos disponibles. No se guarda en la OI.', 'calibratrack' ); ?>
			</p>
		</div>

		<!-- Equipo -->
		<div class="ct-field">
			<label class="ct-label"><?php esc_html_e( 'Equipo', 'calibratrack' ); ?></label>
			<div style="display:flex;gap:8px;align-items:center;">
				<select name="equipo_id" id="ct-equipo-select" class="ct-select" style="flex:1;" <?php echo esc_attr( $disabled ); ?>>
					<option value="0"><?php esc_html_e( '— Seleccionar equipo —', 'calibratrack' ); ?></option>
					<?php foreach ( $equipos as $eq ) :
						$serie_eq     = (string) get_post_meta( $eq->ID, CalibraTrack_Meta_Keys::EQUIPO_SERIE, true );
						$marca_eq     = (string) get_post_meta( $eq->ID, CalibraTrack_Meta_Keys::EQUIPO_MARCA, true );
						$modelo_eq    = (string) get_post_meta( $eq->ID, CalibraTrack_Meta_Keys::EQUIPO_MODELO, true );
						$cliente_eq   = (int) get_post_meta( $eq->ID, CalibraTrack_Meta_Keys::EQUIPO_CLIENTE_PROPIETARIO, true );
					?>
					<option value="<?php echo esc_attr( $eq->ID ); ?>"
						data-cliente="<?php echo esc_attr( $cliente_eq ); ?>"
						<?php selected( $v( 'equipo_id' ), $eq->ID ); ?>>
						<?php echo esc_html( $serie_eq . ' — ' . $marca_eq . ' ' . $modelo_eq ); ?>
					</option>
					<?php endforeach; ?>
				</select>
				<?php if ( ! $es_solo_lectura && current_user_can( 'manage_options' ) ) : ?>
				<button type="button" id="ct-btn-nuevo-equipo" class="ct-btn ct-btn--sm"
					title="<?php esc_attr_e( 'Crear nuevo equipo', 'calibratrack' ); ?>">
					<?php esc_html_e( '+ Equipo', 'calibratrack' ); ?>
				</button>
				<?php endif; ?>
			</div>
		</div>

		<!-- Técnico responsable -->
		<div class="ct-field">
			<label class="ct-label"><?php esc_html_e( 'Técnico responsable', 'calibratrack' ); ?></label>
			<select name="tecnico_id" class="ct-select" <?php echo esc_attr( $disabled ); ?>>
				<option value="0"><?php esc_html_e( '— Seleccionar técnico —', 'calibratrack' ); ?></option>
				<?php foreach ( $tecnicos as $tec ) : ?>
				<option value="<?php echo esc_attr( $tec->ID ); ?>" <?php selected( $v( 'tecnico_id' ), $tec->ID ); ?>>
					<?php echo esc_html( $tec->display_name ); ?>
				</option>
				<?php endforeach; ?>
			</select>
		</div>

		<!-- Tipo de servicio y fecha -->
		<div class="ct-field-group">
			<div class="ct-field <?php echo ! $es_solo_lectura && $e( 'tipo' ) ? 'ct-field--error' : ''; ?>">
				<label class="ct-label"><?php esc_html_e( 'Tipo de servicio', 'calibratrack' ); ?></label>
				<select name="tipo" class="ct-select" <?php echo esc_attr( $disabled ); ?>>
					<option value=""><?php esc_html_e( '— Seleccionar —', 'calibratrack' ); ?></option>
					<?php foreach ( $tipos_ev as $slug => $label ) : ?>
					<option value="<?php echo esc_attr( $slug ); ?>" <?php selected( $v( 'tipo' ), $slug ); ?>>
						<?php echo esc_html( $label ); ?>
					</option>
					<?php endforeach; ?>
				</select>
				<?php if ( ! $es_solo_lectura && $e( 'tipo' ) ) : ?>
					<span class="ct-field-error"><?php echo esc_html( $e( 'tipo' ) ); ?></span>
				<?php endif; ?>
			</div>

			<div class="ct-field <?php echo ! $es_solo_lectura && $e( 'fecha_ejecucion' ) ? 'ct-field--error' : ''; ?>">
				<label class="ct-label"><?php esc_html_e( 'Fecha de recepción', 'calibratrack' ); ?></label>
				<input type="date" name="fecha_ejecucion" class="ct-input"
					value="<?php echo esc_attr( $v( 'fecha_ejecucion' ) ); ?>" <?php echo esc_attr( $disabled ); ?>>
				<?php if ( ! $es_solo_lectura && $e( 'fecha_ejecucion' ) ) : ?>
					<span class="ct-field-error"><?php echo esc_html( $e( 'fecha_ejecucion' ) ); ?></span>
				<?php endif; ?>
			</div>
		</div>

		<!-- Falla reportada -->
		<div class="ct-field">
			<label class="ct-label"><?php esc_html_e( 'Falla/defecto reportado por el cliente', 'calibratrack' ); ?></label>
			<textarea name="falla_reportada" class="ct-textarea" rows="4" <?php echo esc_attr( $disabled ); ?>><?php echo esc_textarea( $v( 'falla_reportada' ) ); ?></textarea>
		</div>

		</div><!-- /fields readonly wrapper -->

		<?php if ( ! $es_solo_lectura ) : ?>
		<div class="ct-form-actions">
			<button type="submit" class="ct-btn ct-btn--primary ct-btn--large">
				<?php echo $es_nueva ? esc_html__( 'Crear Orden de Ingreso', 'calibratrack' ) : esc_html__( 'Actualizar OI', 'calibratrack' ); ?>
			</button>
			<a href="<?php echo esc_url( home_url( '/panel/' ) ); ?>" class="ct-btn">
				<?php esc_html_e( 'Cancelar', 'calibratrack' ); ?>
			</a>
		</div>
		<?php else : ?>
		<div class="ct-form-actions">
			<a href="<?php echo esc_url( home_url( '/panel/' ) ); ?>" class="ct-btn">
				<?php esc_html_e( '← Volver al panel', 'calibratrack' ); ?>
			</a>
		</div>
		<?php endif; ?>

	<?php if ( $es_solo_lectura ) : ?>
	</div>
	<?php else : ?>
	</form>
	<?php endif; ?>

	<?php if ( ! $es_nueva && ! $es_solo_lectura ) : ?>
	<div class="ct-no-print" style="margin-top:24px;padding-top:20px;border-top:1px solid #e5e7eb;">
		<a href="<?php echo esc_url( add_query_arg( 'ct_oi_id', $evento_id, home_url( '/panel/nueva-ot/' ) ) ); ?>"
			class="ct-btn" style="background:#16a34a;border-color:#16a34a;color:#fff;">
			<?php esc_html_e( 'Crear OT desde esta OI', 'calibratrack' ); ?>
		</a>
	</div>
	<?php endif; ?>

</div>

<?php if ( ! $es_solo_lectura && current_user_can( 'manage_options' ) ) : ?>
<?php
// Variables para JS — no usar wp_localize_script porque el panel no usa wp_enqueue_scripts.
$ct_ajax_url   = admin_url( 'admin-ajax.php' );
$ct_nonce_qc   = wp_create_nonce( 'calibratrack_quick_create' );
$ct_tipos_equipo_json = wp_json_encode( CalibraTrack_Helpers::get_tipos_equipo() );
$ct_clientes_modal = array();
foreach ( $clientes as $cl ) {
	$nc = (string) get_post_meta( $cl->ID, CalibraTrack_Meta_Keys::CLIENTE_NOMBRE_EMPRESA, true );
	$ct_clientes_modal[] = array( 'id' => $cl->ID, 'nombre' => $nc ?: $cl->post_title );
}
$ct_clientes_json = wp_json_encode( $ct_clientes_modal );
?>

<!-- Backdrop -->
<div id="ct-modal-backdrop" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.55);z-index:9998;" aria-hidden="true"></div>

<!-- ── Modal: Crear cliente ─────────────────────────────────────────────── -->
<div id="ct-modal-cliente" role="dialog" aria-modal="true" aria-labelledby="ct-modal-cliente-title"
	style="display:none;position:fixed;top:50%;left:50%;transform:translate(-50%,-50%);
	       width:min(540px,95vw);max-height:90vh;overflow-y:auto;
	       background:#fff;border-radius:10px;padding:28px 32px;z-index:9999;
	       box-shadow:0 25px 60px rgba(0,0,0,.3);">
	<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:20px;">
		<h3 id="ct-modal-cliente-title" style="margin:0;font-size:1.1rem;font-weight:700;color:#1e293b;">
			<?php esc_html_e( 'Nuevo cliente', 'calibratrack' ); ?>
		</h3>
		<button type="button" class="ct-modal-close" data-modal="ct-modal-cliente"
			style="background:none;border:none;cursor:pointer;font-size:1.5rem;line-height:1;color:#6b7280;padding:4px 8px;"
			aria-label="<?php esc_attr_e( 'Cerrar', 'calibratrack' ); ?>">×</button>
	</div>

	<div id="ct-modal-cliente-error" role="alert"
		style="display:none;background:#fee2e2;border:1px solid #fca5a5;border-radius:6px;
		       padding:10px 14px;margin-bottom:16px;color:#991b1b;font-size:.875rem;"></div>

	<div style="margin-bottom:14px;">
		<label style="display:block;font-size:13px;font-weight:600;color:#374151;margin-bottom:5px;">
			<?php esc_html_e( 'Nombre de la empresa *', 'calibratrack' ); ?>
		</label>
		<input type="text" id="ct-qc-empresa" class="ct-input" style="width:100%;box-sizing:border-box;"
			placeholder="<?php esc_attr_e( 'Ej: Fibernet S.A.', 'calibratrack' ); ?>">
	</div>

	<div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:14px;">
		<div>
			<label style="display:block;font-size:13px;font-weight:600;color:#374151;margin-bottom:5px;">
				<?php esc_html_e( 'RUT', 'calibratrack' ); ?>
			</label>
			<input type="text" id="ct-qc-rut" class="ct-input" style="width:100%;box-sizing:border-box;"
				placeholder="76.123.456-7">
		</div>
		<div>
			<label style="display:block;font-size:13px;font-weight:600;color:#374151;margin-bottom:5px;">
				<?php esc_html_e( 'Teléfono', 'calibratrack' ); ?>
			</label>
			<input type="text" id="ct-qc-telefono" class="ct-input" style="width:100%;box-sizing:border-box;"
				placeholder="+56 9 1234 5678">
		</div>
	</div>

	<div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:14px;">
		<div>
			<label style="display:block;font-size:13px;font-weight:600;color:#374151;margin-bottom:5px;">
				<?php esc_html_e( 'Nombre de contacto', 'calibratrack' ); ?>
			</label>
			<input type="text" id="ct-qc-contacto" class="ct-input" style="width:100%;box-sizing:border-box;">
		</div>
		<div>
			<label style="display:block;font-size:13px;font-weight:600;color:#374151;margin-bottom:5px;">
				<?php esc_html_e( 'Correo electrónico', 'calibratrack' ); ?>
			</label>
			<input type="email" id="ct-qc-correo" class="ct-input" style="width:100%;box-sizing:border-box;">
		</div>
	</div>

	<div style="margin-bottom:22px;">
		<label style="display:block;font-size:13px;font-weight:600;color:#374151;margin-bottom:5px;">
			<?php esc_html_e( 'Dirección', 'calibratrack' ); ?>
		</label>
		<input type="text" id="ct-qc-direccion" class="ct-input" style="width:100%;box-sizing:border-box;">
	</div>

	<div style="display:flex;gap:10px;justify-content:flex-end;">
		<button type="button" class="ct-btn ct-btn--secondary ct-modal-close" data-modal="ct-modal-cliente">
			<?php esc_html_e( 'Cancelar', 'calibratrack' ); ?>
		</button>
		<button type="button" id="ct-btn-guardar-cliente" class="ct-btn ct-btn--primary">
			<?php esc_html_e( 'Guardar cliente', 'calibratrack' ); ?>
		</button>
	</div>
</div>

<!-- ── Modal: Crear equipo ──────────────────────────────────────────────── -->
<div id="ct-modal-equipo" role="dialog" aria-modal="true" aria-labelledby="ct-modal-equipo-title"
	style="display:none;position:fixed;top:50%;left:50%;transform:translate(-50%,-50%);
	       width:min(560px,95vw);max-height:90vh;overflow-y:auto;
	       background:#fff;border-radius:10px;padding:28px 32px;z-index:9999;
	       box-shadow:0 25px 60px rgba(0,0,0,.3);">
	<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:20px;">
		<h3 id="ct-modal-equipo-title" style="margin:0;font-size:1.1rem;font-weight:700;color:#1e293b;">
			<?php esc_html_e( 'Nuevo equipo', 'calibratrack' ); ?>
		</h3>
		<button type="button" class="ct-modal-close" data-modal="ct-modal-equipo"
			style="background:none;border:none;cursor:pointer;font-size:1.5rem;line-height:1;color:#6b7280;padding:4px 8px;"
			aria-label="<?php esc_attr_e( 'Cerrar', 'calibratrack' ); ?>">×</button>
	</div>

	<div id="ct-modal-equipo-error" role="alert"
		style="display:none;background:#fee2e2;border:1px solid #fca5a5;border-radius:6px;
		       padding:10px 14px;margin-bottom:16px;color:#991b1b;font-size:.875rem;"></div>

	<div style="margin-bottom:14px;">
		<label style="display:block;font-size:13px;font-weight:600;color:#374151;margin-bottom:5px;">
			<?php esc_html_e( 'Nombre del equipo *', 'calibratrack' ); ?>
		</label>
		<input type="text" id="ct-qe-nombre" class="ct-input" style="width:100%;box-sizing:border-box;"
			placeholder="<?php esc_attr_e( 'Ej: OTDR Grandway GS-401', 'calibratrack' ); ?>">
	</div>

	<div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:14px;">
		<div>
			<label style="display:block;font-size:13px;font-weight:600;color:#374151;margin-bottom:5px;">
				<?php esc_html_e( 'N° de serie *', 'calibratrack' ); ?>
			</label>
			<input type="text" id="ct-qe-serie" class="ct-input" style="width:100%;box-sizing:border-box;">
		</div>
		<div>
			<label style="display:block;font-size:13px;font-weight:600;color:#374151;margin-bottom:5px;">
				<?php esc_html_e( 'Tipo de equipo *', 'calibratrack' ); ?>
			</label>
			<select id="ct-qe-tipo" class="ct-input" style="width:100%;">
				<option value=""><?php esc_html_e( '— Seleccionar —', 'calibratrack' ); ?></option>
				<?php foreach ( CalibraTrack_Helpers::get_tipos_equipo() as $slug => $etiqueta ) : ?>
				<option value="<?php echo esc_attr( $slug ); ?>"><?php echo esc_html( $etiqueta ); ?></option>
				<?php endforeach; ?>
			</select>
		</div>
	</div>

	<div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:14px;">
		<div>
			<label style="display:block;font-size:13px;font-weight:600;color:#374151;margin-bottom:5px;">
				<?php esc_html_e( 'Marca', 'calibratrack' ); ?>
			</label>
			<input type="text" id="ct-qe-marca" class="ct-input" style="width:100%;box-sizing:border-box;"
				placeholder="<?php esc_attr_e( 'Ej: Grandway', 'calibratrack' ); ?>">
		</div>
		<div>
			<label style="display:block;font-size:13px;font-weight:600;color:#374151;margin-bottom:5px;">
				<?php esc_html_e( 'Modelo', 'calibratrack' ); ?>
			</label>
			<input type="text" id="ct-qe-modelo" class="ct-input" style="width:100%;box-sizing:border-box;"
				placeholder="<?php esc_attr_e( 'Ej: GS-401', 'calibratrack' ); ?>">
		</div>
	</div>

	<div style="margin-bottom:22px;">
		<label style="display:block;font-size:13px;font-weight:600;color:#374151;margin-bottom:5px;">
			<?php esc_html_e( 'Cliente propietario', 'calibratrack' ); ?>
		</label>
		<select id="ct-qe-cliente" class="ct-input" style="width:100%;">
			<option value="0"><?php esc_html_e( '— Sin cliente asignado —', 'calibratrack' ); ?></option>
			<?php foreach ( $clientes as $cl ) :
				$nc = (string) get_post_meta( $cl->ID, CalibraTrack_Meta_Keys::CLIENTE_NOMBRE_EMPRESA, true );
			?>
			<option value="<?php echo esc_attr( $cl->ID ); ?>">
				<?php echo esc_html( $nc ?: $cl->post_title ); ?>
			</option>
			<?php endforeach; ?>
		</select>
	</div>

	<div style="display:flex;gap:10px;justify-content:flex-end;">
		<button type="button" class="ct-btn ct-btn--secondary ct-modal-close" data-modal="ct-modal-equipo">
			<?php esc_html_e( 'Cancelar', 'calibratrack' ); ?>
		</button>
		<button type="button" id="ct-btn-guardar-equipo" class="ct-btn ct-btn--primary">
			<?php esc_html_e( 'Guardar equipo', 'calibratrack' ); ?>
		</button>
	</div>
</div>

<script>
/* global XMLHttpRequest */
(function () {
	'use strict';

	var ajaxUrl  = <?php echo wp_json_encode( $ct_ajax_url ); ?>;
	var nonce    = <?php echo wp_json_encode( $ct_nonce_qc ); ?>;
	var backdrop = document.getElementById( 'ct-modal-backdrop' );

	// ── Modal helpers ──────────────────────────────────────────────────────
	function openModal( id ) {
		var m = document.getElementById( id );
		if ( m ) { m.style.display = 'block'; }
		if ( backdrop ) { backdrop.style.display = 'block'; }
		document.body.style.overflow = 'hidden';
	}
	function closeModal( id ) {
		var m = document.getElementById( id );
		if ( m ) { m.style.display = 'none'; }
		if ( backdrop ) { backdrop.style.display = 'none'; }
		document.body.style.overflow = '';
	}
	function clearErrors() {
		document.querySelectorAll( '[id$="-error"]' ).forEach( function ( el ) {
			el.style.display = 'none';
			el.textContent = '';
		} );
	}

	// Cerrar al hacer clic en el backdrop.
	if ( backdrop ) {
		backdrop.addEventListener( 'click', function () {
			document.querySelectorAll( '[id^="ct-modal-"]' ).forEach( function ( m ) {
				m.style.display = 'none';
			} );
			backdrop.style.display = 'none';
			document.body.style.overflow = '';
		} );
	}

	// Botones de cierre.
	document.querySelectorAll( '.ct-modal-close' ).forEach( function ( btn ) {
		btn.addEventListener( 'click', function () {
			closeModal( this.getAttribute( 'data-modal' ) );
		} );
	} );

	// ── Filtro cliente → equipos ───────────────────────────────────────────
	var clienteSelect = document.getElementById( 'ct-cliente-select' );
	var equipoSelect  = document.getElementById( 'ct-equipo-select' );

	function filterEquipos( clienteId ) {
		if ( ! equipoSelect ) { return; }
		var clienteIdStr = String( clienteId );
		var options = equipoSelect.querySelectorAll( 'option' );
		options.forEach( function ( opt ) {
			if ( opt.value === '0' ) { opt.hidden = false; return; }
			var dc = opt.getAttribute( 'data-cliente' ) || '0';
			// Mostrar si no hay filtro, si el equipo no tiene cliente, o si coincide.
			if ( clienteIdStr === '0' || dc === '0' || dc === clienteIdStr ) {
				opt.hidden = false;
			} else {
				opt.hidden = true;
				// Si el equipo actualmente seleccionado queda oculto, limpiar selección.
				if ( opt.selected ) {
					equipoSelect.value = '0';
				}
			}
		} );
	}

	if ( clienteSelect ) {
		clienteSelect.addEventListener( 'change', function () {
			filterEquipos( this.value );
		} );
		// Aplicar filtro inicial si hay cliente pre-seleccionado.
		if ( clienteSelect.value !== '0' ) {
			filterEquipos( clienteSelect.value );
		}
	}

	// ── Abrir modales ──────────────────────────────────────────────────────
	var btnNuevoCliente = document.getElementById( 'ct-btn-nuevo-cliente' );
	if ( btnNuevoCliente ) {
		btnNuevoCliente.addEventListener( 'click', function () {
			clearErrors();
			openModal( 'ct-modal-cliente' );
			var f = document.getElementById( 'ct-qc-empresa' );
			if ( f ) { f.focus(); }
		} );
	}

	var btnNuevoEquipo = document.getElementById( 'ct-btn-nuevo-equipo' );
	if ( btnNuevoEquipo ) {
		btnNuevoEquipo.addEventListener( 'click', function () {
			clearErrors();
			// Pre-llenar cliente en el modal de equipo con el seleccionado.
			var qeCliente = document.getElementById( 'ct-qe-cliente' );
			if ( clienteSelect && qeCliente && clienteSelect.value !== '0' ) {
				qeCliente.value = clienteSelect.value;
			}
			openModal( 'ct-modal-equipo' );
			var f = document.getElementById( 'ct-qe-nombre' );
			if ( f ) { f.focus(); }
		} );
	}

	// ── Utilidad AJAX ──────────────────────────────────────────────────────
	function doPost( data, onSuccess, onError ) {
		var xhr = new XMLHttpRequest();
		xhr.open( 'POST', ajaxUrl );
		xhr.setRequestHeader( 'Content-Type', 'application/x-www-form-urlencoded' );
		xhr.onload = function () {
			var res;
			try { res = JSON.parse( xhr.responseText ); } catch ( ex ) {
				onError( '<?php echo esc_js( __( 'Error de respuesta del servidor.', 'calibratrack' ) ); ?>' );
				return;
			}
			if ( res && res.success ) {
				onSuccess( res.data );
			} else {
				onError( ( res && res.data && res.data.message )
					? res.data.message
					: '<?php echo esc_js( __( 'Error desconocido.', 'calibratrack' ) ); ?>' );
			}
		};
		xhr.onerror = function () {
			onError( '<?php echo esc_js( __( 'Error de conexión.', 'calibratrack' ) ); ?>' );
		};
		var parts = [];
		for ( var k in data ) {
			if ( Object.prototype.hasOwnProperty.call( data, k ) ) {
				parts.push( encodeURIComponent( k ) + '=' + encodeURIComponent( data[ k ] ) );
			}
		}
		xhr.send( parts.join( '&' ) );
	}

	// ── Guardar cliente ────────────────────────────────────────────────────
	var btnGuardarCliente = document.getElementById( 'ct-btn-guardar-cliente' );
	if ( btnGuardarCliente ) {
		btnGuardarCliente.addEventListener( 'click', function () {
			var empresa = ( document.getElementById( 'ct-qc-empresa' ).value || '' ).trim();
			var errDiv  = document.getElementById( 'ct-modal-cliente-error' );

			errDiv.style.display = 'none';
			errDiv.textContent   = '';

			if ( ! empresa ) {
				errDiv.textContent = '<?php echo esc_js( __( 'El nombre de la empresa es obligatorio.', 'calibratrack' ) ); ?>';
				errDiv.style.display = 'block';
				return;
			}

			btnGuardarCliente.disabled = true;

			doPost( {
				action:          'calibratrack_quick_create_cliente',
				_nonce:          nonce,
				nombre_empresa:  empresa,
				rut:             ( document.getElementById( 'ct-qc-rut' ).value || '' ).trim(),
				contacto_nombre: ( document.getElementById( 'ct-qc-contacto' ).value || '' ).trim(),
				telefono:        ( document.getElementById( 'ct-qc-telefono' ).value || '' ).trim(),
				correo:          ( document.getElementById( 'ct-qc-correo' ).value || '' ).trim(),
				direccion:       ( document.getElementById( 'ct-qc-direccion' ).value || '' ).trim(),
			}, function ( data ) {
				// Agregar cliente al selector principal.
				if ( clienteSelect ) {
					var opt1 = document.createElement( 'option' );
					opt1.value       = data.id;
					opt1.textContent = data.nombre;
					opt1.selected    = true;
					clienteSelect.appendChild( opt1 );
					filterEquipos( String( data.id ) );
				}
				// Agregar cliente al selector del modal de equipo.
				var qeCliente = document.getElementById( 'ct-qe-cliente' );
				if ( qeCliente ) {
					var opt2 = document.createElement( 'option' );
					opt2.value       = data.id;
					opt2.textContent = data.nombre;
					qeCliente.appendChild( opt2 );
				}
				// Limpiar campos del modal.
				[ 'ct-qc-empresa', 'ct-qc-rut', 'ct-qc-contacto',
				  'ct-qc-telefono', 'ct-qc-correo', 'ct-qc-direccion' ].forEach( function ( id ) {
					var el = document.getElementById( id );
					if ( el ) { el.value = ''; }
				} );
				closeModal( 'ct-modal-cliente' );
				btnGuardarCliente.disabled = false;
			}, function ( msg ) {
				errDiv.textContent   = msg;
				errDiv.style.display = 'block';
				btnGuardarCliente.disabled = false;
			} );
		} );
	}

	// ── Guardar equipo ─────────────────────────────────────────────────────
	var btnGuardarEquipo = document.getElementById( 'ct-btn-guardar-equipo' );
	if ( btnGuardarEquipo ) {
		btnGuardarEquipo.addEventListener( 'click', function () {
			var nombre = ( document.getElementById( 'ct-qe-nombre' ).value || '' ).trim();
			var serie  = ( document.getElementById( 'ct-qe-serie' ).value  || '' ).trim();
			var tipo   = document.getElementById( 'ct-qe-tipo' ).value;
			var errDiv = document.getElementById( 'ct-modal-equipo-error' );

			errDiv.style.display = 'none';
			errDiv.textContent   = '';

			if ( ! nombre || ! serie || ! tipo ) {
				errDiv.textContent = '<?php echo esc_js( __( 'Nombre, serie y tipo son obligatorios.', 'calibratrack' ) ); ?>';
				errDiv.style.display = 'block';
				return;
			}

			btnGuardarEquipo.disabled = true;

			doPost( {
				action:              'calibratrack_quick_create_equipo',
				_nonce:              nonce,
				nombre:              nombre,
				serie:               serie,
				tipo_equipo:         tipo,
				marca:               ( document.getElementById( 'ct-qe-marca' ).value  || '' ).trim(),
				modelo:              ( document.getElementById( 'ct-qe-modelo' ).value || '' ).trim(),
				cliente_propietario: document.getElementById( 'ct-qe-cliente' ).value,
			}, function ( data ) {
				// Agregar equipo al selector principal.
				if ( equipoSelect ) {
					var opt = document.createElement( 'option' );
					opt.value        = data.id;
					opt.textContent  = data.label;
					opt.setAttribute( 'data-cliente', String( data.cliente_id ) );
					opt.selected     = true;
					equipoSelect.appendChild( opt );
					equipoSelect.value = data.id;

					// Sincronizar el selector de cliente si el equipo tiene uno asignado.
					if ( clienteSelect && data.cliente_id && String( data.cliente_id ) !== '0' ) {
						clienteSelect.value = data.cliente_id;
						filterEquipos( String( data.cliente_id ) );
					}
				}
				// Limpiar campos del modal.
				[ 'ct-qe-nombre', 'ct-qe-serie', 'ct-qe-marca', 'ct-qe-modelo' ].forEach( function ( id ) {
					var el = document.getElementById( id );
					if ( el ) { el.value = ''; }
				} );
				document.getElementById( 'ct-qe-tipo' ).value    = '';
				document.getElementById( 'ct-qe-cliente' ).value = '0';

				closeModal( 'ct-modal-equipo' );
				btnGuardarEquipo.disabled = false;
			}, function ( msg ) {
				errDiv.textContent   = msg;
				errDiv.style.display = 'block';
				btnGuardarEquipo.disabled = false;
			} );
		} );
	}

} )();
</script>
<?php endif; ?>

<?php if ( ! $es_nueva && isset( $_GET['imprimir'] ) && '1' === $_GET['imprimir'] ) : ?>
<script>window.addEventListener('load', function(){ window.print(); });</script>
<?php endif; ?>

<?php if ( ! $es_nueva ) :
	// ── Preparar datos para la vista de impresión ──────────────────────────
	$print_numero_oi = (string) get_post_meta( $evento_id, CalibraTrack_Meta_Keys::EVENTO_NUMERO_OI, true );
	if ( ! $print_numero_oi ) {
		$print_numero_oi = 'OI-' . $evento_id;
	}

	$print_equipo = '';
	foreach ( $equipos as $eq ) {
		if ( (int) $eq->ID === (int) $v( 'equipo_id' ) ) {
			$s  = (string) get_post_meta( $eq->ID, CalibraTrack_Meta_Keys::EQUIPO_SERIE,  true );
			$m  = (string) get_post_meta( $eq->ID, CalibraTrack_Meta_Keys::EQUIPO_MARCA,  true );
			$mo = (string) get_post_meta( $eq->ID, CalibraTrack_Meta_Keys::EQUIPO_MODELO, true );
			$print_equipo = trim( $s . ' — ' . $m . ' ' . $mo );
			break;
		}
	}

	$print_cliente = '';
	foreach ( $clientes as $cl ) {
		if ( (int) $cl->ID === $cliente_actual ) {
			$nc = (string) get_post_meta( $cl->ID, CalibraTrack_Meta_Keys::CLIENTE_NOMBRE_EMPRESA, true );
			$print_cliente = $nc ? $nc : $cl->post_title;
			break;
		}
	}

	$print_tecnico = '';
	foreach ( $tecnicos as $tec ) {
		if ( (int) $tec->ID === (int) $v( 'tecnico_id' ) ) {
			$print_tecnico = $tec->display_name;
			break;
		}
	}

	$print_tipo  = isset( $tipos_ev[ $v( 'tipo' ) ] ) ? $tipos_ev[ $v( 'tipo' ) ] : $v( 'tipo' );
	$print_fecha = $v( 'fecha_ejecucion' );
	if ( $print_fecha ) {
		$dt = date_create( $print_fecha );
		if ( $dt ) {
			$print_fecha = date_format( $dt, 'd/m/Y' );
		}
	}
	$print_fecha_impresion = date_i18n( 'd/m/Y', current_time( 'timestamp' ) );
?>
<style>
@media screen { .ct-print-view { display:none; } }
@media print {
	header.ct-panel-header,
	.ct-panel-footer,
	.ct-page-header,
	.ct-no-print,
	.ct-form,
	.ct-form-actions,
	.ct-section,
	[id^="ct-modal-"],
	#ct-modal-backdrop,
	script { display:none !important; }
	.ct-container { box-shadow:none !important; padding:0 !important; max-width:100% !important; }
	.ct-print-view { display:block !important; }
	body { background:#fff !important; }
}
</style>
<div class="ct-print-view" style="font-family:Arial,Helvetica,sans-serif;color:#1e293b;max-width:780px;margin:0 auto;padding:20px;">
	<!-- Encabezado -->
	<div style="display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:18px;padding-bottom:14px;border-bottom:2px solid #1e3a5f;">
		<div>
			<div style="font-size:22px;font-weight:800;color:#1e3a5f;letter-spacing:.5px;">TrueTech</div>
			<div style="font-size:11px;color:#64748b;margin-top:2px;"><?php esc_html_e( 'Servicio Técnico — Instrumentos de Fibra Óptica', 'calibratrack' ); ?></div>
		</div>
		<div style="text-align:right;">
			<div style="font-size:16px;font-weight:700;color:#1e3a5f;"><?php esc_html_e( 'ORDEN DE INGRESO', 'calibratrack' ); ?></div>
			<div style="font-size:20px;font-weight:800;color:#2563eb;margin-top:2px;"><?php echo esc_html( $print_numero_oi ); ?></div>
			<div style="font-size:10px;color:#64748b;margin-top:4px;">
				<?php
				/* translators: %s: fecha de impresión */
				printf( esc_html__( 'Impreso: %s', 'calibratrack' ), esc_html( $print_fecha_impresion ) );
				?>
			</div>
		</div>
	</div>

	<!-- Datos principales -->
	<table style="width:100%;border-collapse:collapse;font-size:13px;margin-bottom:20px;">
		<tbody>
			<tr style="border-bottom:1px solid #e5e7eb;">
				<td style="padding:9px 12px;width:35%;font-weight:600;color:#374151;background:#f8fafc;"><?php esc_html_e( 'Equipo', 'calibratrack' ); ?></td>
				<td style="padding:9px 12px;"><?php echo esc_html( $print_equipo ); ?></td>
			</tr>
			<tr style="border-bottom:1px solid #e5e7eb;">
				<td style="padding:9px 12px;font-weight:600;color:#374151;background:#f8fafc;"><?php esc_html_e( 'Cliente', 'calibratrack' ); ?></td>
				<td style="padding:9px 12px;"><?php echo esc_html( $print_cliente ); ?></td>
			</tr>
			<tr style="border-bottom:1px solid #e5e7eb;">
				<td style="padding:9px 12px;font-weight:600;color:#374151;background:#f8fafc;"><?php esc_html_e( 'Técnico responsable', 'calibratrack' ); ?></td>
				<td style="padding:9px 12px;"><?php echo esc_html( $print_tecnico ); ?></td>
			</tr>
			<tr style="border-bottom:1px solid #e5e7eb;">
				<td style="padding:9px 12px;font-weight:600;color:#374151;background:#f8fafc;"><?php esc_html_e( 'Tipo de servicio', 'calibratrack' ); ?></td>
				<td style="padding:9px 12px;"><?php echo esc_html( $print_tipo ); ?></td>
			</tr>
			<tr>
				<td style="padding:9px 12px;font-weight:600;color:#374151;background:#f8fafc;"><?php esc_html_e( 'Fecha de recepción', 'calibratrack' ); ?></td>
				<td style="padding:9px 12px;"><?php echo esc_html( $print_fecha ); ?></td>
			</tr>
		</tbody>
	</table>

	<!-- Falla reportada -->
	<?php if ( $v( 'falla_reportada' ) ) : ?>
	<div style="margin-bottom:20px;">
		<div style="font-weight:700;font-size:12px;text-transform:uppercase;color:#374151;letter-spacing:.5px;margin-bottom:6px;padding-bottom:4px;border-bottom:1px solid #e5e7eb;">
			<?php esc_html_e( 'Falla / defecto reportado por el cliente', 'calibratrack' ); ?>
		</div>
		<div style="font-size:13px;line-height:1.6;white-space:pre-wrap;"><?php echo esc_html( $v( 'falla_reportada' ) ); ?></div>
	</div>
	<?php endif; ?>

	<!-- Pie de página -->
	<div style="margin-top:30px;padding-top:10px;border-top:1px solid #e5e7eb;font-size:10px;color:#94a3b8;text-align:center;">
		&copy; <?php echo esc_html( date_i18n( 'Y' ) ); ?> TrueTech SpA
	</div>
</div>
<?php endif; ?>

<?php include __DIR__ . '/_partials/footer.php'; ?>
