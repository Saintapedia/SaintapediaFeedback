/**
 * Dashboard helpers: select-all for bulk process.
 */
( function () {
	'use strict';

	function init() {
		var master = document.getElementById( 'spf-select-all' );
		if ( !master ) {
			return;
		}
		master.addEventListener( 'change', function () {
			var boxes = document.querySelectorAll( 'input.spf-row-check' );
			for ( var i = 0; i < boxes.length; i++ ) {
				boxes[ i ].checked = master.checked;
			}
		} );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
}() );
