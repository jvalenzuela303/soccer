<?php
/**
 * Partial: hilo de mensajes internos de una OT.
 *
 * Variables esperadas (deben pasarse mediante extract() antes del include):
 *   $evento_id     int    Post ID de la OT.
 *   $mensajes      array  Array de stdClass devuelto por CalibraTrack_Mensajes::get_by_evento().
 *   $es_tecnico    bool   True si el usuario actual es un técnico (no admin).
 *   $es_completado bool   (opcional) True si la OT ya está finalizada.
 *
 * El partial es de solo lectura cuando $es_completado === true.
 *
 * @package CalibraTrack
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$evento_id     = isset( $evento_id ) ? (int) $evento_id : 0;
$mensajes      = ( isset( $mensajes ) && is_array( $mensajes ) ) ? $mensajes : array();
$es_tecnico    = ! empty( $es_tecnico );
$es_completado = ! empty( $es_completado );

if ( $evento_id <= 0 ) {
	return;
}

$usuario_id    = get_current_user_id();
$nonce_mensaje = wp_create_nonce( 'calibratrack_mensaje_' . $evento_id );
$ajax_url      = admin_url( 'admin-ajax.php' );
?>

<div class="ct-mensajes-seccion" id="ct-mensajes-seccion" style="margin-top:32px;border-top:2px solid #e5e7eb;padding-top:24px;">
	<h2 style="font-size:16px;font-weight:700;color:#1e293b;margin:0 0 16px;">
		<?php esc_html_e( 'Mensajes internos', 'calibratrack' ); ?>
	</h2>

	<!-- Hilo de mensajes -->
	<div id="ct-mensajes-hilo" style="display:flex;flex-direction:column;gap:12px;margin-bottom:20px;max-height:480px;overflow-y:auto;padding-right:4px;">
		<?php if ( empty( $mensajes ) ) : ?>
			<p id="ct-mensajes-vacio" style="font-size:13px;color:#9ca3af;text-align:center;padding:20px 0;">
				<?php esc_html_e( 'No hay mensajes aún. Puedes escribir el primero abajo.', 'calibratrack' ); ?>
			</p>
		<?php else : ?>
			<?php
			// Ocultar el párrafo de vacío si existen mensajes.
			// Se renderiza directamente sin él.
			foreach ( $mensajes as $msj ) :
				$autor_id     = (int) $msj->autor_id;
				$es_propio    = ( $autor_id === $usuario_id );
				$autor_nombre = esc_html( $msj->autor_nombre );
				$autor_rol    = (string) $msj->autor_rol; // 'admin' | 'tecnico'
				$texto_msj    = esc_html( $msj->mensaje );

				// Formatear fecha.
				$fecha_obj = DateTime::createFromFormat( 'Y-m-d H:i:s', $msj->fecha );
				$fecha_fmt = $fecha_obj ? $fecha_obj->format( 'd/m/Y H:i' ) : esc_html( $msj->fecha );

				// Colores de burbuja: admin = azul, técnico = verde.
				if ( 'admin' === $autor_rol ) {
					$bg_color     = '#dbeafe';
					$border_color = '#93c5fd';
					$text_color   = '#1e3a5f';
					$rol_label    = __( 'Admin', 'calibratrack' );
				} else {
					$bg_color     = '#dcfce7';
					$border_color = '#86efac';
					$text_color   = '#14532d';
					$rol_label    = __( 'Técnico', 'calibratrack' );
				}

				// Posición: propios a la derecha, ajenos a la izquierda.
				$alinear = $es_propio ? 'flex-end' : 'flex-start';
			?>
			<div class="ct-msj-burbuja-wrap" data-msj-id="<?php echo (int) $msj->id; ?>"
				style="display:flex;justify-content:<?php echo esc_attr( $alinear ); ?>;">
				<div style="max-width:75%;min-width:200px;">
					<div style="background:<?php echo esc_attr( $bg_color ); ?>;border:1px solid <?php echo esc_attr( $border_color ); ?>;border-radius:10px;padding:10px 14px;">
						<div style="display:flex;justify-content:space-between;align-items:baseline;gap:12px;margin-bottom:6px;">
							<span style="font-size:12px;font-weight:700;color:<?php echo esc_attr( $text_color ); ?>;">
								<?php echo $autor_nombre; ?>
								<span style="font-weight:400;opacity:.75;">(<?php echo esc_html( $rol_label ); ?>)</span>
							</span>
							<span style="font-size:11px;color:#6b7280;white-space:nowrap;">
								<?php echo esc_html( $fecha_fmt ); ?>
							</span>
						</div>
						<p style="margin:0;font-size:14px;color:#1f2937;white-space:pre-wrap;word-break:break-word;"><?php echo $texto_msj; ?></p>
					</div>
				</div>
			</div>
			<?php endforeach; ?>
		<?php endif; ?>
	</div>

	<!-- Formulario de envío -->
	<?php if ( $es_completado ) : ?>
		<div style="background:#f3f4f6;border:1px solid #e5e7eb;border-radius:8px;padding:14px 16px;text-align:center;">
			<p style="margin:0;font-size:13px;color:#6b7280;">
				<?php esc_html_e( 'Esta OT está finalizada. No se pueden enviar nuevos mensajes.', 'calibratrack' ); ?>
			</p>
		</div>
	<?php else : ?>
		<div class="ct-mensajes-form" style="display:flex;flex-direction:column;gap:8px;">
			<textarea id="ct-mensaje-texto" name="ct_mensaje" class="ct-textarea"
				rows="3"
				maxlength="1000"
				placeholder="<?php esc_attr_e( 'Escribe un mensaje…', 'calibratrack' ); ?>"
				style="resize:vertical;font-size:14px;"></textarea>
			<div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px;">
				<span id="ct-mensaje-contador" style="font-size:12px;color:#9ca3af;">0 / 1000</span>
				<button type="button" id="ct-mensaje-enviar" class="ct-btn ct-btn--primary">
					<?php esc_html_e( 'Enviar', 'calibratrack' ); ?>
				</button>
			</div>
			<p id="ct-mensaje-error" style="display:none;font-size:13px;color:#dc2626;margin:0;"></p>
		</div>
	<?php endif; ?>
</div>

<script>
(function () {
	'use strict';

	// Variables emitidas server-side (reemplazo de wp_localize_script para inline).
	var CT_MSJ = {
		ajaxUrl:   <?php echo wp_json_encode( $ajax_url ); ?>,
		nonce:     <?php echo wp_json_encode( $nonce_mensaje ); ?>,
		eventoId:  <?php echo (int) $evento_id; ?>,
		usuarioId: <?php echo (int) $usuario_id; ?>,
		esTecnico: <?php echo $es_tecnico ? 'true' : 'false'; ?>
	};

	var hilo       = document.getElementById( 'ct-mensajes-hilo' );
	var textarea   = document.getElementById( 'ct-mensaje-texto' );
	var btnEnviar  = document.getElementById( 'ct-mensaje-enviar' );
	var errorEl    = document.getElementById( 'ct-mensaje-error' );
	var contadorEl = document.getElementById( 'ct-mensaje-contador' );

	// Último ID conocido: el mayor data-msj-id del hilo al cargar la página.
	var ultimoId = 0;
	var burbujas = hilo ? hilo.querySelectorAll( '[data-msj-id]' ) : [];
	for ( var bi = 0; bi < burbujas.length; bi++ ) {
		var mid = parseInt( burbujas[ bi ].getAttribute( 'data-msj-id' ), 10 );
		if ( mid > ultimoId ) { ultimoId = mid; }
	}

	// Intervalo de polling (ms). Se pausa si la pestaña está oculta.
	var POLL_INTERVAL = 5000;
	var pollTimer     = null;

	// Contador de caracteres.
	textarea.addEventListener( 'input', function () {
		var len = this.value.length;
		if ( contadorEl ) {
			contadorEl.textContent = len + ' / 1000';
		}
	} );

	// Hacer scroll al final del hilo al cargar.
	if ( hilo ) {
		hilo.scrollTop = hilo.scrollHeight;
	}

	/**
	 * Construye el HTML de una burbuja de mensaje nueva y la agrega al hilo.
	 *
	 * @param {Object}  msj      Objeto con autor_nombre, autor_rol, mensaje, fecha_formateada.
	 * @param {boolean} esPropio True si el mensaje pertenece al usuario actual.
	 * @param {number}  msjId    ID del mensaje (para data-msj-id).
	 */
	function agregarBurbuja( msj, esPropio, msjId ) {
		// Eliminar párrafo de "sin mensajes" si está presente.
		var vacio = document.getElementById( 'ct-mensajes-vacio' );
		if ( vacio ) {
			vacio.parentNode.removeChild( vacio );
		}

		var alinear   = esPropio ? 'flex-end' : 'flex-start';
		var bgColor, borderColor, textColor, rolLabel;

		if ( 'admin' === msj.autor_rol ) {
			bgColor     = '#dbeafe';
			borderColor = '#93c5fd';
			textColor   = '#1e3a5f';
			rolLabel    = <?php echo wp_json_encode( __( 'Admin', 'calibratrack' ) ); ?>;
		} else {
			bgColor     = '#dcfce7';
			borderColor = '#86efac';
			textColor   = '#14532d';
			rolLabel    = <?php echo wp_json_encode( __( 'Técnico', 'calibratrack' ) ); ?>;
		}

		var wrap = document.createElement( 'div' );
		wrap.className = 'ct-msj-burbuja-wrap';
		wrap.style.cssText = 'display:flex;justify-content:' + alinear + ';';
		if ( msjId ) { wrap.setAttribute( 'data-msj-id', msjId ); }

		// Escapar texto antes de insertar al DOM (se usa textContent, no innerHTML).
		var inner = document.createElement( 'div' );
		inner.style.cssText = 'max-width:75%;min-width:200px;';

		var burbuja = document.createElement( 'div' );
		burbuja.style.cssText = 'background:' + bgColor + ';border:1px solid ' + borderColor + ';border-radius:10px;padding:10px 14px;';

		var cabecera = document.createElement( 'div' );
		cabecera.style.cssText = 'display:flex;justify-content:space-between;align-items:baseline;gap:12px;margin-bottom:6px;';

		var nombreSpan = document.createElement( 'span' );
		nombreSpan.style.cssText = 'font-size:12px;font-weight:700;color:' + textColor + ';';
		nombreSpan.textContent = msj.autor_nombre + ' ';
		var rolSpan = document.createElement( 'span' );
		rolSpan.style.cssText = 'font-weight:400;opacity:.75;';
		rolSpan.textContent = '(' + rolLabel + ')';
		nombreSpan.appendChild( rolSpan );

		var fechaSpan = document.createElement( 'span' );
		fechaSpan.style.cssText = 'font-size:11px;color:#6b7280;white-space:nowrap;';
		fechaSpan.textContent = msj.fecha_formateada;

		cabecera.appendChild( nombreSpan );
		cabecera.appendChild( fechaSpan );

		var texto = document.createElement( 'p' );
		texto.style.cssText = 'margin:0;font-size:14px;color:#1f2937;white-space:pre-wrap;word-break:break-word;';
		texto.textContent = msj.mensaje;

		burbuja.appendChild( cabecera );
		burbuja.appendChild( texto );
		inner.appendChild( burbuja );
		wrap.appendChild( inner );

		if ( hilo ) {
			hilo.appendChild( wrap );
			hilo.scrollTop = hilo.scrollHeight;
		}
	}

	/**
	 * Envía el mensaje al endpoint AJAX y actualiza el hilo.
	 */
	function enviarMensaje() {
		var texto = textarea.value.trim();
		if ( '' === texto ) {
			return;
		}

		// Deshabilitar botón y textarea durante el envío.
		btnEnviar.disabled = true;
		textarea.disabled  = true;
		if ( errorEl ) { errorEl.style.display = 'none'; }

		var body = new FormData();
		body.append( 'action',       'calibratrack_enviar_mensaje' );
		body.append( '_nonce_mensaje', CT_MSJ.nonce );
		body.append( 'evento_id',    CT_MSJ.eventoId );
		body.append( 'ct_mensaje',   texto );

		fetch( CT_MSJ.ajaxUrl, {
			method:      'POST',
			credentials: 'same-origin',
			body:        body
		} )
		.then( function ( response ) {
			return response.json();
		} )
		.then( function ( data ) {
			if ( data.success && data.data && data.data.mensaje ) {
				var m = data.data.mensaje;
				agregarBurbuja( m, true, m.id );
				if ( m.id && m.id > ultimoId ) { ultimoId = m.id; }
				textarea.value = '';
				if ( contadorEl ) { contadorEl.textContent = '0 / 1000'; }
			} else {
				var msg = ( data.data && data.data.message )
					? data.data.message
					: <?php echo wp_json_encode( __( 'Error al enviar el mensaje.', 'calibratrack' ) ); ?>;
				if ( errorEl ) {
					errorEl.textContent    = msg;
					errorEl.style.display  = 'block';
				}
			}
		} )
		.catch( function () {
			if ( errorEl ) {
				errorEl.textContent   = <?php echo wp_json_encode( __( 'Error de conexión. Intenta de nuevo.', 'calibratrack' ) ); ?>;
				errorEl.style.display = 'block';
			}
		} )
		.finally( function () {
			btnEnviar.disabled = false;
			textarea.disabled  = false;
			textarea.focus();
		} );
	}

	// Enviar con el botón.
	btnEnviar.addEventListener( 'click', enviarMensaje );

	// Enviar con Ctrl+Enter / Cmd+Enter.
	textarea.addEventListener( 'keydown', function ( e ) {
		if ( ( e.ctrlKey || e.metaKey ) && 13 === e.keyCode ) {
			e.preventDefault();
			enviarMensaje();
		}
	} );

	/* ── Polling: busca mensajes nuevos cada 5 seg ── */

	function pollMensajes() {
		var params = 'action=calibratrack_obtener_mensajes'
			+ '&evento_id=' + CT_MSJ.eventoId
			+ '&ultimo_id=' + ultimoId
			+ '&_nonce_mensaje=' + encodeURIComponent( CT_MSJ.nonce );

		fetch( CT_MSJ.ajaxUrl + '?' + params, {
			method:      'GET',
			credentials: 'same-origin'
		} )
		.then( function ( r ) { return r.json(); } )
		.then( function ( data ) {
			if ( ! data.success || ! data.data || ! data.data.mensajes ) { return; }
			var nuevos = data.data.mensajes;
			for ( var i = 0; i < nuevos.length; i++ ) {
				var m       = nuevos[ i ];
				var esPropio = ( parseInt( m.autor_id, 10 ) === CT_MSJ.usuarioId );
				agregarBurbuja( m, esPropio, m.id );
				if ( m.id > ultimoId ) { ultimoId = m.id; }
			}
		} )
		.catch( function () { /* silencioso: no interrumpir al usuario */ } );
	}

	function iniciarPoll() {
		if ( pollTimer ) { return; }
		pollTimer = setInterval( pollMensajes, <?php echo (int) 5000; ?> );
	}

	function detenerPoll() {
		if ( pollTimer ) {
			clearInterval( pollTimer );
			pollTimer = null;
		}
	}

	// Pausar cuando la pestaña está oculta, reanudar al volver.
	if ( typeof document.hidden !== 'undefined' ) {
		document.addEventListener( 'visibilitychange', function () {
			if ( document.hidden ) {
				detenerPoll();
			} else {
				pollMensajes(); // Traer inmediatamente al volver.
				iniciarPoll();
			}
		} );
	}

	iniciarPoll();

}());
</script>
