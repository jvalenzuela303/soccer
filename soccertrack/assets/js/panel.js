/**
 * SoccerTrack — Panel interno · JS
 * Toggle del menú móvil.
 */
( () => {
	'use strict';

	const toggle = document.querySelector( '.st-nav-toggle' );
	const nav    = document.querySelector( '.st-panel-nav' );

	if ( toggle && nav ) {
		toggle.addEventListener( 'click', () => {
			const open = nav.classList.toggle( 'is-open' );
			toggle.setAttribute( 'aria-expanded', String( open ) );
		} );
	}
} )();
