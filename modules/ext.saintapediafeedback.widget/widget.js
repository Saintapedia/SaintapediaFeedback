/**
 * SaintapediaFeedback — floating action button + slide-up feedback panel.
 * Public mode: open to anonymous readers; hCaptcha when required server-side.
 */
( function () {
	'use strict';

	var api = new mw.Api();
	var config = {
		mode: mw.config.get( 'spfMode' ) || 'public',
		pageId: mw.config.get( 'spfPageId' ),
		enableEmail: mw.config.get( 'spfEnableEmail' ) || false,
		requireCaptcha: mw.config.get( 'spfRequireCaptcha' ) || false,
		captchaMisconfigured: mw.config.get( 'spfCaptchaMisconfigured' ) || false,
		hCaptchaSiteKey: mw.config.get( 'spfHCaptchaSiteKey' ) || '',
		showPublicCounts: mw.config.get( 'spfShowPublicCounts' ) || false,
		countOpen: mw.config.get( 'spfCountOpen' ) || 0,
		countResolved: mw.config.get( 'spfCountResolved' ) || 0
	};

// Ensure public-count message is available when config is on
	// (registered on the ResourceLoader module).

	var CATEGORIES = [
		'inaccurate',
		'outdated',
		'needs-detail',
		'confusing',
		'missing-sources',
		'broken-links',
		'other'
	];

	var hcaptchaScriptPromise = null;
	var hcaptchaWidgetId = null;

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

	function loadHCaptchaScript() {
		if ( window.hcaptcha ) {
			return Promise.resolve();
		}
		if ( hcaptchaScriptPromise ) {
			return hcaptchaScriptPromise;
		}
		hcaptchaScriptPromise = new Promise( function ( resolve, reject ) {
			var s = document.createElement( 'script' );
			s.src = 'https://hcaptcha.com/1/api.js?render=explicit';
			s.async = true;
			s.defer = true;
			s.onload = function () {
				resolve();
			};
			s.onerror = function () {
				hcaptchaScriptPromise = null;
				reject( new Error( 'hcaptcha-load-failed' ) );
			};
			document.head.appendChild( s );
		} );
		return hcaptchaScriptPromise;
	}

	function getCaptchaToken() {
		if ( !config.requireCaptcha ) {
			return '';
		}
		if ( !window.hcaptcha || hcaptchaWidgetId === null ) {
			return '';
		}
		try {
			return window.hcaptcha.getResponse( hcaptchaWidgetId ) || '';
		} catch ( e ) {
			return '';
		}
	}

	function resetCaptcha() {
		if ( window.hcaptcha && hcaptchaWidgetId !== null ) {
			try {
				window.hcaptcha.reset( hcaptchaWidgetId );
			} catch ( e ) {
				// ignore
			}
		}
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

		// hCaptcha mount point (public / when required)
		var captchaMount = null;
		var captchaRow = null;
		if ( config.requireCaptcha || config.captchaMisconfigured ) {
			captchaMount = el( 'div', { class: 'spf-hcaptcha', id: 'spf-hcaptcha' } );
			captchaRow = el( 'div', { class: 'spf-field spf-field-captcha' }, [ captchaMount ] );
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
			captchaRow,
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

		var captchaRendered = false;

		function ensureCaptcha() {
			if ( !config.requireCaptcha || !captchaMount || captchaRendered ) {
				return Promise.resolve();
			}
			if ( config.captchaMisconfigured || !config.hCaptchaSiteKey ) {
				errorMsg.textContent = mw.msg( 'saintapediafeedback-error-captcha-unavailable' );
				return Promise.reject( new Error( 'captcha-misconfigured' ) );
			}
			return loadHCaptchaScript().then( function () {
				if ( captchaRendered || !window.hcaptcha ) {
					return;
				}
				hcaptchaWidgetId = window.hcaptcha.render( captchaMount, {
					sitekey: config.hCaptchaSiteKey,
					size: 'normal'
				} );
				captchaRendered = true;
			} );
		}

		/* ── Panel open/close ─────────────────────────────────────── */

		function openPanel() {
			backdrop.classList.add( 'spf-backdrop--visible' );
			panel.classList.add( 'spf-panel--open' );
			fab.setAttribute( 'aria-expanded', 'true' );
			document.body.classList.add( 'spf-panel-open' );
			ensureCaptcha().catch( function () {
				// error already shown when misconfigured
			} );
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

		function apiErrorCode( code, data ) {
			// mw.Api rejects with (code, detail); code may be 'http' or error key
			if ( typeof code === 'string' && code.indexOf( 'saintapediafeedback-' ) === 0 ) {
				return code;
			}
			if ( data && data.error && data.error.code ) {
				return data.error.code;
			}
			if ( data && data.errors && data.errors[ 0 ] && data.errors[ 0 ].code ) {
				return data.errors[ 0 ].code;
			}
			return code;
		}

		function submitFeedback() {
			errorMsg.textContent = '';

			if ( config.captchaMisconfigured ) {
				errorMsg.textContent = mw.msg( 'saintapediafeedback-error-captcha-unavailable' );
				return;
			}

			var selectedCats = categoryChips
				.filter( function ( c ) { return c.getAttribute( 'aria-pressed' ) === 'true'; } )
				.map( function ( c ) { return c.getAttribute( 'data-category' ); } );

			if ( !selectedCats.length ) {
				errorMsg.textContent = mw.msg( 'saintapediafeedback-error-nocategory' );
				return;
			}

			var captchaToken = getCaptchaToken();
			if ( config.requireCaptcha && !captchaToken ) {
				errorMsg.textContent = mw.msg( 'saintapediafeedback-error-captcha' );
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
			if ( captchaToken ) {
				// ConfirmEdit HCaptcha accepts captchaWord (and h-captcha-response)
				params.captchaWord = captchaToken;
			}

			api.postWithToken( 'csrf', params ).then( function () {
				formBody.hidden = true;
				successEl.hidden = false;
				successEl.innerHTML =
					'<div class="spf-success-icon">✓</div>' +
					'<h3>' + mw.html.escape( mw.msg( 'saintapediafeedback-success-title' ) ) + '</h3>' +
					'<p>' + mw.html.escape( mw.msg( 'saintapediafeedback-success-body' ) ) + '</p>';
				setTimeout( closePanel, 3000 );
			} ).catch( function ( code, data ) {
				submitBtn.disabled = false;
				submitBtn.textContent = mw.msg( 'saintapediafeedback-submit' );
				resetCaptcha();
				var err = apiErrorCode( code, data );
				if ( err === 'saintapediafeedback-error-ratelimit' || err === 'spf-ratelimit' ) {
					errorMsg.textContent = mw.msg( 'saintapediafeedback-error-ratelimit' );
				} else if (
					err === 'saintapediafeedback-error-captcha' ||
					err === 'spf-captcha' ||
					err === 'captcha'
				) {
					errorMsg.textContent = mw.msg( 'saintapediafeedback-error-captcha' );
				} else if (
					err === 'saintapediafeedback-error-captcha-unavailable' ||
					err === 'spf-captcha-unavailable'
				) {
					errorMsg.textContent = mw.msg( 'saintapediafeedback-error-captcha-unavailable' );
				} else if (
					err === 'saintapediafeedback-error-namespace' ||
					err === 'spf-namespace'
				) {
					errorMsg.textContent = mw.msg( 'saintapediafeedback-error-namespace' );
				} else if ( err === 'blocked' || err === 'autoblocked' ) {
					errorMsg.textContent = mw.msg( 'saintapediafeedback-error-generic' );
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

		return { fab: fab, backdrop: backdrop, panel: panel };
	}

	/* ── Init ──────────────────────────────────────────────────────────── */

	function buildPublicCountChip() {
		if ( !config.showPublicCounts ) {
			return null;
		}
		var open = parseInt( config.countOpen, 10 ) || 0;
		var resolved = parseInt( config.countResolved, 10 ) || 0;
		if ( !open && !resolved ) {
			return null;
		}
		var label = mw.msg( 'saintapediafeedback-public-counts', open, resolved );
		return el( 'div', {
			class: 'spf-public-counts',
			title: label
		}, [ label ] );
	}

	function init() {
		if ( !config.pageId ) {
			return;
		}
		// Avoid double-inject if content hook fires more than once
		if ( document.querySelector( '.spf-container' ) ) {
			return;
		}

		var widgets = buildWidget();
		var container = el( 'div', { class: 'spf-container spf-mode-' + config.mode } );
		var chip = buildPublicCountChip();
		if ( chip ) {
			container.appendChild( chip );
		}
		container.appendChild( widgets.backdrop );
		container.appendChild( widgets.fab );
		container.appendChild( widgets.panel );
		document.body.appendChild( container );
	}

	mw.hook( 'wikipage.content' ).add( init );

}() );
