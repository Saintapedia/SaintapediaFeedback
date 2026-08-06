/**
 * Dashboard helpers: select-all for bulk process; show publish only for actioned.
 */
( function () {
	'use strict';

	function initSelectAll() {
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

	/**
	 * Bulk toolbar is shared across all process actions; publish only applies
	 * to "actioned". Toggle visibility so the control is not misleading.
	 */
	function initBulkPublicToggle() {
		var select = document.getElementById( 'spf-bulk-action' );
		var wrap = document.getElementById( 'spf-bulk-public-wrap' );
		if ( !select || !wrap ) {
			return;
		}
		function sync() {
			var show = select.value === 'actioned';
			wrap.hidden = !show;
			wrap.setAttribute( 'aria-hidden', show ? 'false' : 'true' );
		}
		select.addEventListener( 'change', sync );
		sync();
	}

	function init() {
		initSelectAll();
		initBulkPublicToggle();
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
}() );
