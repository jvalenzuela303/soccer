/**
 * JS del panel de administración de CalibraTrack.
 *
 * Responsabilidades (solo UX — los cálculos reales ocurren en servidor):
 *   - Repetidor de items_costo (agregar/quitar filas, preview de subtotal por fila).
 *   - Recalcular subtotal/IVA/total en tiempo real (solo para UX — el servidor recalcula al guardar).
 *   - Selector de Media Library para galería de fotos (wp.media).
 *   - Selector de Media Library para certificado PDF y OT PDF (wp.media, solo PDF).
 *   - Toggle de campo dias_garantia según checkbox garantia.
 *   - Botón "Imprimir etiqueta QR" (RF-09).
 *
 * Variables PHP→JS disponibles via wp_localize_script('calibratrack-admin', 'calibratrackAdmin', {...}):
 *   calibratrackAdmin.i18n.seleccionar_fotos   — texto botón galería
 *   calibratrackAdmin.i18n.seleccionar_cert     — texto botón certificado
 *   calibratrackAdmin.i18n.seleccionar_ot       — texto botón OT
 *   calibratrackAdmin.i18n.sin_archivo          — texto si no hay archivo
 *   calibratrackAdmin.i18n.agregar_item         — texto botón agregar ítem
 *   calibratrackAdmin.i18n.quitar               — texto botón quitar ítem
 *   calibratrackAdmin.qrImgUrl                  — URL de la imagen QR del equipo actual
 *   calibratrackAdmin.equipoSerie               — número de serie del equipo actual
 *
 * Dependencias declaradas en PHP: jquery, wp-mediaelement (vía wp_enqueue_script).
 *
 * @package CalibraTrack
 */

( function ( $ ) {
	'use strict';

	var i18n = ( window.calibratrackAdmin && window.calibratrackAdmin.i18n ) ? window.calibratrackAdmin.i18n : {};

	// ─── TOGGLE GARANTÍA ────────────────────────────────────────────────────────

	function initGarantiaToggle() {
		var $checkbox  = $( 'input[name="calibratrack_garantia"]' );
		var $diasRow   = $( '.ct-garantia-dias-row' );

		if ( ! $checkbox.length ) {
			return;
		}

		function updateVisibility() {
			if ( $checkbox.is( ':checked' ) ) {
				$diasRow.addClass( 'visible' );
			} else {
				$diasRow.removeClass( 'visible' );
			}
		}

		// Estado inicial.
		updateVisibility();

		$checkbox.on( 'change', updateVisibility );
	}

	// ─── REPETIDOR ÍTEMS DE COSTO ────────────────────────────────────────────────

	/**
	 * Calcula el subtotal de una fila (solo para preview UX).
	 */
	function calcularSubtotalFila( $fila ) {
		var cantidad = parseFloat( $fila.find( '.ct-item-cantidad-input' ).val() ) || 0;
		var precio   = parseFloat( $fila.find( '.ct-item-precio-input' ).val() ) || 0;
		var subtotal = cantidad * precio;
		$fila.find( '.ct-item-subtotal-preview' ).text( formatCLP( subtotal ) );
	}

	/**
	 * Recalcula los totales generales (subtotal, IVA, total) en tiempo real para UX.
	 * Nota: el servidor siempre recalcula al guardar — estos valores son solo orientativos.
	 */
	function recalcularTotales() {
		var subtotal = 0;
		$( '#calibratrack-items-costo tbody tr' ).each( function () {
			var cantidad = parseFloat( $( this ).find( '.ct-item-cantidad-input' ).val() ) || 0;
			var precio   = parseFloat( $( this ).find( '.ct-item-precio-input' ).val() ) || 0;
			subtotal += cantidad * precio;
		} );

		var iva   = Math.round( subtotal * 0.19 * 100 ) / 100;
		var total = Math.round( ( subtotal + iva ) * 100 ) / 100;

		$( '#ct-preview-subtotal' ).text( formatCLP( subtotal ) );
		$( '#ct-preview-iva' ).text( formatCLP( iva ) );
		$( '#ct-preview-total' ).text( formatCLP( total ) );
	}

	/**
	 * Formatea un número como CLP (sin decimales, separador de miles punto).
	 */
	function formatCLP( numero ) {
		return '$' + Math.round( numero ).toString().replace( /\B(?=(\d{3})+(?!\d))/g, '.' );
	}

	/**
	 * Actualiza los índices de las filas para que los name[] sean consecutivos.
	 */
	function reindexarFilas() {
		$( '#calibratrack-items-costo tbody tr' ).each( function ( idx ) {
			$( this ).find( 'input' ).each( function () {
				var name = $( this ).attr( 'name' );
				if ( name ) {
					// Reemplaza el índice numérico dentro de calibratrack_items[N][campo].
					$( this ).attr( 'name', name.replace( /calibratrack_items\[\d+\]/, 'calibratrack_items[' + idx + ']' ) );
				}
			} );
		} );
	}

	/**
	 * Crea una nueva fila de ítem de costo.
	 */
	function crearFilaItem( idx ) {
		var btnLabel = i18n.quitar || '&times;';
		return $( '<tr>' ).html(
			'<td class="ct-item-detalle">' +
				'<input type="text" name="calibratrack_items[' + idx + '][detalle]" value="" class="large-text ct-item-detalle-input" />' +
			'</td>' +
			'<td class="ct-item-cantidad">' +
				'<input type="number" name="calibratrack_items[' + idx + '][cantidad]" value="1" step="0.01" min="0" class="small-text ct-item-cantidad-input" />' +
			'</td>' +
			'<td class="ct-item-precio">' +
				'<input type="number" name="calibratrack_items[' + idx + '][precio_unitario]" value="0" step="0.01" min="0" class="small-text ct-item-precio-input" />' +
			'</td>' +
			'<td class="ct-item-subtotal"><span class="ct-item-subtotal-preview">$0</span></td>' +
			'<td class="ct-item-acciones">' +
				'<button type="button" class="ct-btn-quitar-item" aria-label="' + ( i18n.quitar || 'Quitar fila' ) + '">' + btnLabel + '</button>' +
			'</td>'
		);
	}

	function initItemsCosto() {
		var $tabla = $( '#calibratrack-items-costo' );

		if ( ! $tabla.length ) {
			return;
		}

		// Calcular subtotales iniciales de filas ya existentes.
		$tabla.find( 'tbody tr' ).each( function () {
			calcularSubtotalFila( $( this ) );
		} );
		recalcularTotales();

		// Evento: cambio en cantidad o precio → recalcular fila y totales.
		$tabla.on( 'input change', '.ct-item-cantidad-input, .ct-item-precio-input', function () {
			calcularSubtotalFila( $( this ).closest( 'tr' ) );
			recalcularTotales();
		} );

		// Evento: quitar fila.
		$tabla.on( 'click', '.ct-btn-quitar-item', function () {
			var $fila = $( this ).closest( 'tr' );
			// Mantener al menos una fila.
			if ( $tabla.find( 'tbody tr' ).length > 1 ) {
				$fila.remove();
				reindexarFilas();
				recalcularTotales();
			} else {
				// Limpiar los valores de la única fila en lugar de eliminarla.
				$fila.find( 'input[type="text"]' ).val( '' );
				$fila.find( 'input[type="number"]' ).val( 0 );
				calcularSubtotalFila( $fila );
				recalcularTotales();
			}
		} );

		// Evento: agregar fila nueva.
		$( '#ct-btn-agregar-item' ).on( 'click', function () {
			var idx   = $tabla.find( 'tbody tr' ).length;
			var $fila = crearFilaItem( idx );
			$tabla.find( 'tbody' ).append( $fila );
			calcularSubtotalFila( $fila );
			recalcularTotales();
			// Foco en el campo detalle de la nueva fila.
			$fila.find( '.ct-item-detalle-input' ).focus();
		} );
	}

	// ─── GALERÍA DE EVIDENCIA FOTOGRÁFICA ────────────────────────────────────────

	function initGaleriaFotos() {
		var $btnGaleria   = $( '#ct-btn-galeria' );
		var $preview      = $( '#ct-galeria-preview' );
		var $hiddenInput  = $( '#calibratrack_evidencia_fotografica' );

		if ( ! $btnGaleria.length ) {
			return;
		}

		// Leer IDs actuales del campo hidden (JSON array).
		var idsActuales = [];
		try {
			var raw = $hiddenInput.val();
			if ( raw ) {
				idsActuales = JSON.parse( raw );
			}
		} catch ( e ) {
			idsActuales = [];
		}

		// Renderizar thumbs iniciales.
		renderThumbs( idsActuales );

		function renderThumbs( ids ) {
			$preview.empty();
			$.each( ids, function ( i, attachId ) {
				// No podemos hacer llamadas REST sin wpApiSettings acá; usamos el atributo data
				// para saber el ID. El PHP ya renderizó las thumbs en el metabox al cargar la página.
				// Para thumbs dinámicos (después de seleccionar con wp.media) sí tenemos la URL.
			} );
		}

		// Verificar que wp.media esté disponible.
		if ( typeof wp === 'undefined' || typeof wp.media === 'undefined' ) {
			return;
		}

		var frameGaleria = null;

		$btnGaleria.on( 'click', function ( e ) {
			e.preventDefault();

			if ( frameGaleria ) {
				frameGaleria.open();
				return;
			}

			frameGaleria = wp.media( {
				title:    i18n.seleccionar_fotos || 'Seleccionar fotografías',
				button:   { text: i18n.seleccionar_fotos || 'Usar estas fotos' },
				multiple: true,
				library:  { type: 'image' },
			} );

			frameGaleria.on( 'select', function () {
				var seleccion = frameGaleria.state().get( 'selection' );
				var ids       = [];

				$preview.empty();

				seleccion.each( function ( attachment ) {
					var att  = attachment.toJSON();
					ids.push( att.id );

					// Construir thumb.
					var thumbUrl = att.sizes && att.sizes.thumbnail ? att.sizes.thumbnail.url : att.url;
					var $thumb = $(
						'<div class="ct-galeria-thumb">' +
							'<img src="' + thumbUrl + '" alt="" />' +
							'<button type="button" class="ct-thumb-remove" data-id="' + att.id + '" aria-label="Quitar foto">&times;</button>' +
						'</div>'
					);
					$preview.append( $thumb );
				} );

				$hiddenInput.val( JSON.stringify( ids ) );
			} );

			frameGaleria.open();
		} );

		// Quitar foto individual del preview.
		$preview.on( 'click', '.ct-thumb-remove', function () {
			var removeId = parseInt( $( this ).data( 'id' ), 10 );
			$( this ).closest( '.ct-galeria-thumb' ).remove();

			var ids = [];
			try {
				ids = JSON.parse( $hiddenInput.val() );
			} catch ( e ) {
				ids = [];
			}

			ids = $.grep( ids, function ( id ) {
				return id !== removeId;
			} );

			$hiddenInput.val( JSON.stringify( ids ) );
		} );
	}

	// ─── SELECTOR DE PDF (CERTIFICADO / OT) ─────────────────────────────────────

	/**
	 * Inicializa un selector de Media Library para un archivo PDF.
	 *
	 * @param {string} btnSelector     Selector jQuery del botón.
	 * @param {string} inputSelector   Selector jQuery del input hidden (attachment ID).
	 * @param {string} nombreSelector  Selector jQuery del span que muestra el nombre.
	 * @param {string} titulo          Título de la ventana de Media Library.
	 */
	function initPdfSelector( btnSelector, inputSelector, nombreSelector, titulo ) {
		var $btn    = $( btnSelector );
		var $input  = $( inputSelector );
		var $nombre = $( nombreSelector );

		if ( ! $btn.length ) {
			return;
		}

		if ( typeof wp === 'undefined' || typeof wp.media === 'undefined' ) {
			return;
		}

		var frame = null;

		$btn.on( 'click', function ( e ) {
			e.preventDefault();

			if ( frame ) {
				frame.open();
				return;
			}

			frame = wp.media( {
				title:    titulo,
				button:   { text: titulo },
				multiple: false,
				library:  { type: 'application/pdf' },
			} );

			frame.on( 'select', function () {
				var att = frame.state().get( 'selection' ).first().toJSON();
				$input.val( att.id );
				$nombre.text( att.filename || att.title ).removeClass( 'ct-sin-archivo' );
			} );

			frame.open();
		} );

		// Botón quitar selección.
		$( btnSelector + '-quitar' ).on( 'click', function ( e ) {
			e.preventDefault();
			$input.val( 0 );
			$nombre.text( i18n.sin_archivo || 'Sin archivo seleccionado' ).addClass( 'ct-sin-archivo' );
		} );
	}

	function initPdfSelectors() {
		initPdfSelector(
			'#ct-btn-certificado',
			'#calibratrack_certificado_pdf',
			'#ct-cert-nombre',
			i18n.seleccionar_cert || 'Seleccionar certificado PDF'
		);

		initPdfSelector(
			'#ct-btn-ot',
			'#calibratrack_orden_trabajo_pdf',
			'#ct-ot-nombre',
			i18n.seleccionar_ot || 'Seleccionar orden de trabajo PDF'
		);
	}

	// ─── SELECTS CON BÚSQUEDA (EQUIPO / TÉCNICO / CLIENTE) ──────────────────────

	function initSelects() {
		// El selector de equipo en el metabox de evento: al cargar la página
		// ya tiene el valor correcto; solo añadimos comportamiento de búsqueda
		// si el tema tiene algún plugin de select2 disponible.
		// En el MVP se usa un <select> nativo estilizado.
	}

	// ─── IMPRIMIR ETIQUETA QR (RF-09) ────────────────────────────────────────────

	function initQrPrint() {
		var $btnPrint = $( '#ct-btn-imprimir-qr' );

		if ( ! $btnPrint.length ) {
			return;
		}

		$btnPrint.on( 'click', function ( e ) {
			e.preventDefault();

			var qrUrl  = window.calibratrackAdmin ? window.calibratrackAdmin.qrImgUrl : '';
			var serie  = window.calibratrackAdmin ? window.calibratrackAdmin.equipoSerie : '';
			var marca  = window.calibratrackAdmin ? window.calibratrackAdmin.equipoMarca : '';
			var modelo = window.calibratrackAdmin ? window.calibratrackAdmin.equipoModelo : '';

			if ( ! qrUrl ) {
				alert( 'Este equipo aún no tiene código QR generado. Guarde el equipo primero.' );
				return;
			}

			// Abrir ventana de impresión.
			var ventana = window.open( '', '_blank', 'width=400,height=500' );
			if ( ! ventana ) {
				return;
			}

			ventana.document.write(
				'<!DOCTYPE html>' +
				'<html lang="es">' +
				'<head>' +
				'<meta charset="UTF-8">' +
				'<title>Etiqueta QR — ' + serie + '</title>' +
				'<style>' +
				'  body { margin: 0; padding: 20px; font-family: Arial, sans-serif; text-align: center; }' +
				'  .ct-etiqueta { display: inline-block; border: 2px solid #333; border-radius: 8px; padding: 20px 24px; max-width: 260px; }' +
				'  .ct-etiqueta img { width: 180px; height: 180px; display: block; margin: 0 auto 12px; }' +
				'  .ct-etiqueta .ct-etiqueta-serie { font-size: 15px; font-weight: 700; letter-spacing: 0.05em; margin-bottom: 4px; }' +
				'  .ct-etiqueta .ct-etiqueta-sub { font-size: 11px; color: #555; margin-bottom: 2px; }' +
				'  .ct-etiqueta .ct-etiqueta-marca { font-size: 12px; color: #333; }' +
				'  @media print {' +
				'    body * { visibility: hidden; }' +
				'    .ct-etiqueta, .ct-etiqueta * { visibility: visible; }' +
				'    .ct-etiqueta { position: fixed; left: 50%; top: 50%; transform: translate(-50%, -50%); border: 2px solid #000; }' +
				'  }' +
				'</style>' +
				'</head>' +
				'<body>' +
				'<div class="ct-etiqueta">' +
				'  <img src="' + qrUrl + '" alt="Código QR del equipo ' + serie + '" />' +
				'  <div class="ct-etiqueta-serie">' + serie + '</div>' +
				'  <div class="ct-etiqueta-marca">' + marca + ' ' + modelo + '</div>' +
				'  <div class="ct-etiqueta-sub">Escanee para verificar calibración</div>' +
				'</div>' +
				'<script>window.onload = function() { window.print(); window.close(); };<\/script>' +
				'</body>' +
				'</html>'
			);

			ventana.document.close();
		} );
	}

	// ─── INIT ────────────────────────────────────────────────────────────────────

	$( document ).ready( function () {
		initGarantiaToggle();
		initItemsCosto();
		initGaleriaFotos();
		initPdfSelectors();
		initSelects();
		initQrPrint();
	} );

} )( jQuery );
