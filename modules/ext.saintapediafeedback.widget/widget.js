/**
 * SaintapediaFeedback — floating action button + slide-up feedback panel.
 */
( function () {
	'use strict';

	var api = new mw.Api();
	var config = {
		mode: mw.config.get( 'spfMode' ) || 'public',
		pageId: mw.config.get( 'spfPageId' ),
		enableEmail: mw.config.get( 'spfEnableEmail' ) || false
	};

	var CATEGORIES = [
		'inaccurate',
		'outdated',
		'needs-detail',
		'confusing',
		'missing-sources',
		'broken-links',
		'other'
	];

	/* ── DOM helpers ───────────────────────────────────────────────────── */

	function el( tag, attrs, children ) {
		var node = document.createElement( tag );
		Object.keys( attrs || {} ).forEach( function ( k ) {
			if ( k === 'class' ) {
				node.className = attrs[ k ];
			} else if ( k.startsWith( 'on' ) ) {
				node.addEventListener( k.slice( 2 ), attrs[ k ] );
			} else {
				node.setAttribute( k, attrs[ k ] );
			}
		} );
		( children || [] ).forEach( function ( c ) {
			if ( typeof c === 'string' ) {
				node.appendChild( document.createTextNode( c ) );
			} else if ( c ) {
				node.appendChild( c );
			}
		} );
		return node;
	}

	/* ── Build widget ──────────────────────────────────────────────────── */

	function buildWidget() {
		// FAB button
		var fab = el( 'button', {
			class: 'spf-fab',
			'aria-label': mw.msg( 'saintapediafeedback-button-label' ),
			'aria-haspopup': 'dialog',
			'aria-expanded': 'false',
			onclick: togglePanel
		}, [
			el( 'span', { class: 'spf-fab-icon', 'aria-hidden': 'true' } ),
			el( 'span', { class: 'spf-fab-label' }, [ mw.msg( 'saintapediafeedback-button-label' ) ] )
		] );

		// Overlay backdrop
		var backdrop = el( 'div', {
			class: 'spf-backdrop',
			onclick: closePanel
		} );

		// Category chips
		var categoryChips = CATEGORIES.map( function ( cat ) {
			var chip = el( 'button', {
				class: 'spf-chip',
				'data-category': cat,
				'aria-pressed': 'false',
				type: 'button',
				onclick: function () {
					var pressed = chip.getAttribute( 'aria-pressed' ) === 'true';
					chip.setAttribute( 'aria-pressed', String( !pressed ) );
					chip.classList.toggle( 'spf-chip--selected', !pressed );
				}
			}, [ mw.msg( 'saintapediafeedback-category-' + cat ) ] );
			return chip;
		} );

		var categoryGroup = el( 'div', { class: 'spf-categories', role: 'group',
			'aria-label': mw.msg( 'saintapediafeedback-panel-subtitle' ) }, categoryChips );

		// Comment textarea
		var placeholderKey = config.mode === 'enterprise'
			? 'saintapediafeedback-comment-placeholder-enterprise'
			: 'saintapediafeedback-comment-placeholder';
		var textarea = el( 'textarea', {
			class: 'spf-comment',
			id: 'spf-comment',
			rows: config.mode === 'enterprise' ? '6' : '3',
			maxlength: config.mode === 'enterprise' ? '5000' : '500',
			placeholder: mw.msg( placeholderKey ),
			'aria-label': mw.msg( 'saintapediafeedback-comment-label' )
		} );

		var commentLabel = el( 'label', { 'for': 'spf-comment', class: 'spf-label' },
			[ mw.msg( 'saintapediafeedback-comment-label' ) ] );

		// Email field (enterprise / enabled)
		var emailRow = null;
		var emailInput = null;
		if ( config.enableEmail ) {
			emailInput = el( 'input', {
				type: 'email',
				class: 'spf-email',
				id: 'spf-email',
				placeholder: mw.msg( 'saintapediafeedback-email-placeholder' ),
				'aria-label': mw.msg( 'saintapediafeedback-email-label' )
			} );
			var emailLabel = el( 'label', { 'for': 'spf-email', class: 'spf-label' },
				[ mw.msg( 'saintapediafeedback-email-label' ) ] );
			emailRow = el( 'div', { class: 'spf-field' }, [ emailLabel, emailInput ] );
		}

		// Submit / cancel buttons
		var submitBtn = el( 'button', {
			type: 'button',
			class: 'spf-submit',
			onclick: submitFeedback
		}, [ mw.msg( 'saintapediafeedback-submit' ) ] );

		var cancelBtn = el( 'button', {
			type: 'button',
			class: 'spf-cancel',
			onclick: closePanel
		}, [ mw.msg( 'saintapediafeedback-cancel' ) ] );

		var errorMsg  = el( 'div', { class: 'spf-error', role: 'alert', 'aria-live': 'assertive' } );
		var successEl = el( 'div', { class: 'spf-success', hidden: true } );

		var formBody = el( 'div', { class: 'spf-form-body' }, [
			el( 'p', { class: 'spf-subtitle' }, [ mw.msg( 'saintapediafeedback-panel-subtitle' ) ] ),
			categoryGroup,
			el( 'div', { class: 'spf-field' }, [ commentLabel, textarea ] ),
			emailRow,
			errorMsg,
			el( 'div', { class: 'spf-actions' }, [ submitBtn, cancelBtn ] )
		] );

		// Panel
		var panel = el( 'div', {
			class: 'spf-panel',
			role: 'dialog',
			'aria-modal': 'true',
			'aria-label': mw.msg( 'saintapediafeedback-panel-title' )
		}, [
			el( 'div', { class: 'spf-panel-header' }, [
				el( 'h2', { class: 'spf-panel-title' }, [ mw.msg( 'saintapediafeedback-panel-title' ) ] ),
				el( 'button', { class: 'spf-close', 'aria-label': mw.msg( 'saintapediafeedback-cancel' ),
					onclick: closePanel }, [ '×' ] )
			] ),
			formBody,
			successEl
		] );

		/* ── Panel open/close ─────────────────────────────────────── */

		function openPanel() {
			backdrop.classList.add( 'spf-backdrop--visible' );
			panel.classList.add( 'spf-panel--open' );
			fab.setAttribute( 'aria-expanded', 'true' );
			document.body.classList.add( 'spf-panel-open' );
			textarea.focus();
		}

		function closePanel() {
			backdrop.classList.remove( 'spf-backdrop--visible' );
			panel.classList.remove( 'spf-panel--open' );
			fab.setAttribute( 'aria-expanded', 'false' );
			document.body.classList.remove( 'spf-panel-open' );
		}

		function togglePanel() {
			if ( panel.classList.contains( 'spf-panel--open' ) ) {
				closePanel();
			} else {
				openPanel();
			}
		}

		/* ── Submit ───────────────────────────────────────────────── */

		function submitFeedback() {
			errorMsg.textContent = '';

			var selectedCats = categoryChips
				.filter( function ( c ) { return c.getAttribute( 'aria-pressed' ) === 'true'; } )
				.map( function ( c ) { return c.getAttribute( 'data-category' ); } );

			if ( !selectedCats.length ) {
				errorMsg.textContent = mw.msg( 'saintapediafeedback-error-nocategory' );
				return;
			}

			submitBtn.disabled = true;
			submitBtn.textContent = '…';

			var params = {
				action: 'saintapediafeedback',
				pageid: config.pageId,
				categories: selectedCats.join( '|' ),
				format: 'json'
			};
			var comment = textarea.value.trim();
			if ( comment ) {
				params.comment = comment;
			}
			if ( emailInput && emailInput.value.trim() ) {
				params.email = emailInput.value.trim();
			}

			api.postWithToken( 'csrf', params ).then( function () {
				formBody.hidden = true;
				successEl.hidden = false;
				successEl.innerHTML =
					'<div class="spf-success-icon">✓</div>' +
					'<h3>' + mw.html.escape( mw.msg( 'saintapediafeedback-success-title' ) ) + '</h3>' +
					'<p>' + mw.html.escape( mw.msg( 'saintapediafeedback-success-body' ) ) + '</p>';
				setTimeout( closePanel, 3000 );
			} ).catch( function ( code ) {
				submitBtn.disabled = false;
				submitBtn.textContent = mw.msg( 'saintapediafeedback-submit' );
				if ( code === 'saintapediafeedback-error-ratelimit' ) {
					errorMsg.textContent = mw.msg( 'saintapediafeedback-error-ratelimit' );
				} else {
					errorMsg.textContent = mw.msg( 'saintapediafeedback-error-generic' );
				}
			} );
		}

		/* ── Keyboard trap inside panel ───────────────────────────── */

		panel.addEventListener( 'keydown', function ( e ) {
			if ( e.key === 'Escape' ) {
				closePanel();
			}
		} );

		return { fab, backdrop, panel };
	}

	/* ── Init ──────────────────────────────────────────────────────────── */

	function init() {
		if ( !config.pageId ) {
			return;
		}

		var widgets = buildWidget();
		var container = el( 'div', { class: 'spf-container spf-mode-' + config.mode } );
		container.appendChild( widgets.backdrop );
		container.appendChild( widgets.fab );
		container.appendChild( widgets.panel );
		document.body.appendChild( container );
	}

	mw.hook( 'wikipage.content' ).add( init );

}() );
