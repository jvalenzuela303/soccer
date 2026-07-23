/* global calibratrack_tecnico */
/**
 * CalibraTrack — Panel del técnico
 * Handles: guarantee toggle, cost-items repeater + subtotals, equip filter.
 */
( function () {
	'use strict';

	// ─── Garantía toggle ────────────────────────────────────────────────────────

	var garantiaChk = document.getElementById( 'ct-garantia' );
	var garantiaRow = document.querySelector( '.ct-garantia-dias-row' );

	if ( garantiaChk && garantiaRow ) {
		garantiaChk.addEventListener( 'change', function () {
			garantiaRow.classList.toggle( 'visible', this.checked );
			if ( ! this.checked ) {
				var diasInput = document.getElementById( 'ct-dias-garantia' );
				if ( diasInput ) { diasInput.value = 0; }
			}
		} );
	}

	// ─── Cost-items repeater ─────────────────────────────────────────────────────

	var itemsTable = document.getElementById( 'calibratrack-items-costo' );
	var addBtn     = document.getElementById( 'ct-btn-agregar-item' );

	/**
	 * Format a number as CLP currency string (no decimals, dot as thousand sep).
	 *
	 * @param {number} n
	 * @return {string}
	 */
	function formatCLP( n ) {
		return '$' + Math.round( n ).toLocaleString( 'es-CL' );
	}

	/**
	 * Re-index all rows so input names stay sequential after add/remove.
	 */
	function reindexRows() {
		if ( ! itemsTable ) { return; }
		var rows = itemsTable.querySelectorAll( 'tbody tr' );
		rows.forEach( function ( row, idx ) {
			row.querySelectorAll( 'input' ).forEach( function ( input ) {
				// Replace name="calibratrack_items[N][field]" → calibratrack_items[idx][field]
				input.name = input.name.replace( /\[\d+\]/, '[' + idx + ']' );
			} );
		} );
	}

	/**
	 * Recalculate per-row subtotal previews and footer totals.
	 */
	function recalcTotals() {
		if ( ! itemsTable ) { return; }
		var neto = 0;
		var rows = itemsTable.querySelectorAll( 'tbody tr' );

		rows.forEach( function ( row ) {
			var cantInput   = row.querySelector( '.ct-item-cantidad-input' );
			var precioInput = row.querySelector( '.ct-item-precio-input' );
			var subtotalEl  = row.querySelector( '.ct-item-subtotal-preview' );

			var cant   = cantInput   ? parseFloat( cantInput.value )   || 0 : 0;
			var precio = precioInput ? parseFloat( precioInput.value ) || 0 : 0;
			var sub    = cant * precio;

			if ( subtotalEl ) { subtotalEl.textContent = formatCLP( sub ); }
			neto += sub;
		} );

		var iva   = neto * 0.19;
		var total = neto + iva;

		var subEl   = document.getElementById( 'ct-preview-subtotal' );
		var ivaEl   = document.getElementById( 'ct-preview-iva' );
		var totalEl = document.getElementById( 'ct-preview-total' );

		if ( subEl )   { subEl.textContent   = formatCLP( neto ); }
		if ( ivaEl )   { ivaEl.textContent   = formatCLP( iva ); }
		if ( totalEl ) { totalEl.textContent = formatCLP( total ); }
	}

	/**
	 * Build a new blank row and append it to the tbody.
	 */
	function buildNewRow() {
		var idx = itemsTable ? itemsTable.querySelectorAll( 'tbody tr' ).length : 0;

		var tr = document.createElement( 'tr' );
		tr.innerHTML =
			'<td><input type="text" name="calibratrack_items[' + idx + '][detalle]" value="" class="ct-input ct-item-detalle-input"></td>' +
			'<td><input type="number" name="calibratrack_items[' + idx + '][cantidad]" value="1" step="0.01" min="0" class="ct-input ct-input--sm ct-item-cantidad-input"></td>' +
			'<td><input type="number" name="calibratrack_items[' + idx + '][precio_unitario]" value="0" step="0.01" min="0" class="ct-input ct-input--sm ct-item-precio-input"></td>' +
			'<td><span class="ct-item-subtotal-preview">$0</span></td>' +
			'<td><button type="button" class="ct-btn-quitar-item ct-btn ct-btn--sm ct-btn--danger" aria-label="Quitar">×</button></td>';

		bindRowEvents( tr );
		return tr;
	}

	/**
	 * Bind change/remove events to a single table row.
	 *
	 * @param {HTMLTableRowElement} row
	 */
	function bindRowEvents( row ) {
		// Quantity / price change → recalc.
		row.querySelectorAll( '.ct-item-cantidad-input, .ct-item-precio-input' ).forEach( function ( input ) {
			input.addEventListener( 'input', recalcTotals );
		} );

		// Remove button.
		var removeBtn = row.querySelector( '.ct-btn-quitar-item' );
		if ( removeBtn ) {
			removeBtn.addEventListener( 'click', function () {
				var tbody = itemsTable ? itemsTable.querySelector( 'tbody' ) : null;
				// Keep at least one row.
				if ( tbody && tbody.querySelectorAll( 'tr' ).length > 1 ) {
					row.parentNode.removeChild( row );
					reindexRows();
					recalcTotals();
				}
			} );
		}
	}

	// Bind events to pre-rendered rows (PHP-rendered on page load).
	if ( itemsTable ) {
		itemsTable.querySelectorAll( 'tbody tr' ).forEach( function ( row ) {
			bindRowEvents( row );
		} );
		recalcTotals();
	}

	// Add-item button.
	if ( addBtn && itemsTable ) {
		addBtn.addEventListener( 'click', function () {
			var tbody = itemsTable.querySelector( 'tbody' );
			if ( tbody ) {
				var newRow = buildNewRow();
				tbody.appendChild( newRow );
				// Focus the detalle input of the new row.
				var detalleInput = newRow.querySelector( '.ct-item-detalle-input' );
				if ( detalleInput ) { detalleInput.focus(); }
			}
		} );
	}

	// ─── Equipment search/filter ─────────────────────────────────────────────────

	var equipoFilter = document.getElementById( 'ct-equipo-buscar' );
	if ( equipoFilter ) {
		var equipoSelect = document.getElementById( 'ct-equipo-id' );
		if ( equipoSelect ) {
			var allOptions = Array.prototype.slice.call( equipoSelect.options );

			equipoFilter.addEventListener( 'input', function () {
				var query = this.value.toLowerCase().trim();
				allOptions.forEach( function ( opt ) {
					if ( opt.value === '0' ) { return; } // keep placeholder
					var match = opt.textContent.toLowerCase().indexOf( query ) !== -1;
					opt.style.display = match ? '' : 'none';
				} );
			} );
		}
	}

	// ─── Auto-dismiss flash alerts ───────────────────────────────────────────────

	document.querySelectorAll( '.ct-alert--success' ).forEach( function ( el ) {
		setTimeout( function () {
			el.style.transition = 'opacity 0.4s';
			el.style.opacity    = '0';
			setTimeout( function () { el.style.display = 'none'; }, 400 );
		}, 4000 );
	} );

}() );
