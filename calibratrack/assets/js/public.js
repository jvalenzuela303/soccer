/**
 * JS público de CalibraTrack — UX del formulario de verificación.
 *
 * Responsabilidades:
 *   - Validar que el campo no esté vacío antes de enviar el formulario.
 *   - El formulario usa method="get" + action="/verificar/" explícito, por lo que
 *     la redirección a /verificar/?serie=X la gestiona el navegador de forma nativa,
 *     sin necesitar window.location ni JS para el redirect.
 *
 * Dependencias: jquery (declarada en wp_enqueue_script).
 *
 * @package CalibraTrack
 */

( function ( $ ) {
	'use strict';

	function initBusquedaForm() {
		var $form  = $( '#ct-form-busqueda' );
		var $input = $( '#ct-input-serie' );
		var $error = $( '#ct-search-error' );

		if ( ! $form.length ) {
			return;
		}

		$form.on( 'submit', function ( e ) {
			var valor = $.trim( $input.val() );

			// Limpiar estado anterior.
			$input.removeClass( 'ct-input-error' );
			$error.hide();

			if ( valor === '' ) {
				e.preventDefault();
				$input.addClass( 'ct-input-error' );
				$error.show();
				$input.focus();
				return;
			}

			// Campo válido: dejar que el formulario envíe de forma nativa.
			// El action="/verificar/" + method="get" produce /verificar/?serie=VALOR.
		} );

		// Limpiar error al escribir.
		$input.on( 'input', function () {
			if ( $.trim( $( this ).val() ) !== '' ) {
				$( this ).removeClass( 'ct-input-error' );
				$error.hide();
			}
		} );
	}

	$( document ).ready( function () {
		initBusquedaForm();
	} );

} )( jQuery );
