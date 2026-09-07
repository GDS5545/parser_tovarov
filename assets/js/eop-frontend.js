/**
 * Elementor Order Popup - frontend logic.
 * Intercepts clicks on "order/buy" buttons anywhere in Elementor widgets,
 * collects product data, lets the visitor accumulate several products,
 * and submits the resulting order via AJAX.
 */
( function () {
	'use strict';

	if ( typeof window.EOP_SETTINGS === 'undefined' ) {
		return;
	}

	var settings = window.EOP_SETTINGS;
	var STORAGE_KEY = 'eop_cart_v1';
	var modalEl = null;
	var cart = loadCart();

	/* ---------- Cart storage ---------- */

	function loadCart() {
		try {
			var raw = window.sessionStorage.getItem( STORAGE_KEY );
			var parsed = raw ? JSON.parse( raw ) : [];
			return Array.isArray( parsed ) ? parsed : [];
		} catch ( e ) {
			return [];
		}
	}

	function saveCart() {
		try {
			window.sessionStorage.setItem( STORAGE_KEY, JSON.stringify( cart ) );
		} catch ( e ) {
			/* storage unavailable (private mode, quota) - keep in-memory only */
		}
	}

	function itemKey( item ) {
		return item.sku ? 'sku:' + item.sku : 'title:' + item.title;
	}

	function addToCart( product ) {
		var key = itemKey( product );
		var existing = cart.filter( function ( it ) {
			return itemKey( it ) === key;
		} )[ 0 ];

		if ( existing ) {
			existing.qty += product.qty;
		} else {
			cart.push( product );
		}
		saveCart();
	}

	function removeFromCart( index ) {
		cart.splice( index, 1 );
		saveCart();
	}

	function updateQty( index, qty ) {
		qty = parseInt( qty, 10 );
		if ( isNaN( qty ) || qty < 1 ) {
			qty = 1;
		}
		cart[ index ].qty = qty;
		saveCart();
	}

	/* ---------- Trigger detection ---------- */

	function textMatchesKeyword( text ) {
		if ( ! text ) {
			return false;
		}
		text = text.trim().toLowerCase();
		if ( ! text ) {
			return false;
		}
		return settings.triggerWords.some( function ( word ) {
			return word && text.indexOf( word ) !== -1;
		} );
	}

	function findTrigger( el ) {
		var node = el;
		var depth = 0;

		while ( node && node !== document.body && depth < 6 ) {
			var isClickable =
				node.tagName === 'A' ||
				node.tagName === 'BUTTON' ||
				( node.classList && node.classList.contains( 'elementor-button' ) ) ||
				( node.getAttribute && node.getAttribute( 'role' ) === 'button' );

			if ( isClickable ) {
				var matchesSelector = false;
				if ( settings.selector ) {
					try {
						matchesSelector = node.matches( settings.selector );
					} catch ( e ) {
						matchesSelector = false;
					}
				}
				var matchesKeyword = textMatchesKeyword( node.textContent );
				var matchesData = node.hasAttribute( 'data-eop-title' );

				if ( matchesSelector || matchesKeyword || matchesData ) {
					return node;
				}
			}
			node = node.parentElement;
			depth++;
		}
		return null;
	}

	/* ---------- Product data extraction ---------- */

	function readData( el, name ) {
		return el && el.getAttribute ? el.getAttribute( 'data-eop-' + name ) : null;
	}

	function findContainer( trigger ) {
		return (
			trigger.closest( '.elementor-widget-wrap' ) ||
			trigger.closest( '.elementor-column' ) ||
			trigger.closest( '.elementor-container' ) ||
			trigger.closest( 'article' ) ||
			trigger.parentElement ||
			document.body
		);
	}

	function guessTitle( container ) {
		var headingSelectors = [
			'.elementor-heading-title',
			'.elementor-price-table__heading',
			'.product_title',
			'.woocommerce-loop-product__title',
			'h1',
			'h2',
			'h3',
			'h4',
			'h5',
		];
		for ( var i = 0; i < headingSelectors.length; i++ ) {
			var found = container.querySelector( headingSelectors[ i ] );
			if ( found && found.textContent.trim() ) {
				return found.textContent.trim();
			}
		}
		return document.title;
	}

	function guessPrice( container ) {
		var priceSelectors = [ '.elementor-price-table__price', '.price', '.woocommerce-Price-amount' ];
		for ( var i = 0; i < priceSelectors.length; i++ ) {
			var found = container.querySelector( priceSelectors[ i ] );
			if ( found && found.textContent ) {
				var num = parsePrice( found.textContent );
				if ( num !== null ) {
					return num;
				}
			}
		}
		var match = container.textContent.match( /(\d[\d\s]{1,12}[.,]?\d{0,2})\s?(₸|₽|\$|€|kzt|руб|тг)/i );
		if ( match ) {
			return parsePrice( match[ 0 ] );
		}
		return 0;
	}

	function parsePrice( text ) {
		var cleaned = text.replace( /[^\d.,]/g, '' ).replace( /\s/g, '' );
		if ( ! cleaned ) {
			return null;
		}
		cleaned = cleaned.replace( ',', '.' );
		var parts = cleaned.split( '.' );
		if ( parts.length > 2 ) {
			cleaned = parts.slice( 0, -1 ).join( '' ) + '.' + parts[ parts.length - 1 ];
		}
		var num = parseFloat( cleaned );
		return isNaN( num ) ? null : num;
	}

	function guessImage( container ) {
		var img = container.querySelector( 'img' );
		return img ? img.src : '';
	}

	function slugify( text ) {
		return text
			.toLowerCase()
			.trim()
			.replace( /[^a-z0-9а-яё]+/gi, '-' )
			.replace( /(^-|-$)/g, '' );
	}

	function extractProduct( trigger ) {
		var container = findContainer( trigger );

		var title = readData( trigger, 'title' ) || guessTitle( container );
		var priceAttr = readData( trigger, 'price' );
		var price = priceAttr !== null ? parsePrice( priceAttr ) : guessPrice( container );
		var sku = readData( trigger, 'sku' ) || slugify( title );
		var image = readData( trigger, 'image' ) || guessImage( container );
		var url = readData( trigger, 'url' ) || window.location.href;

		return {
			title: title || settings.i18n.product,
			price: price || 0,
			qty: 1,
			sku: sku,
			image: image,
			url: url,
		};
	}

	/* ---------- Modal ---------- */

	function ensureModal() {
		if ( modalEl ) {
			return modalEl;
		}

		modalEl = document.createElement( 'div' );
		modalEl.className = 'eop-overlay';
		modalEl.setAttribute( 'aria-hidden', 'true' );
		modalEl.innerHTML =
			'<div class="eop-modal" role="dialog" aria-modal="true" aria-labelledby="eop-title">' +
			'<button type="button" class="eop-close" aria-label="' + escapeHtml( settings.i18n.close ) + '">&times;</button>' +
			'<h2 id="eop-title" class="eop-title">' + escapeHtml( settings.i18n.popupTitle ) + '</h2>' +
			'<div class="eop-items"></div>' +
			'<button type="button" class="eop-add-more">' + escapeHtml( settings.i18n.addMore ) + '</button>' +
			'<form class="eop-form" novalidate>' +
			'<div class="eop-field"><label>' + escapeHtml( settings.i18n.name ) + ' *</label><input type="text" name="name" required></div>' +
			'<div class="eop-field"><label>' + escapeHtml( settings.i18n.phone ) + ' *</label><input type="tel" name="phone" required></div>' +
			'<div class="eop-field"><label>' + escapeHtml( settings.i18n.email ) + ( settings.requireEmail ? ' *' : '' ) + '</label><input type="email" name="email"' + ( settings.requireEmail ? ' required' : '' ) + '></div>' +
			'<div class="eop-field"><label>' + escapeHtml( settings.i18n.comment ) + '</label><textarea name="comment" rows="3"></textarea></div>' +
			'<input type="text" name="eop_website" class="eop-hp" tabindex="-1" autocomplete="off">' +
			'<div class="eop-notice" hidden></div>' +
			'<button type="submit" class="eop-submit">' + escapeHtml( settings.i18n.submitButton ) + '</button>' +
			'</form>' +
			'</div>';

		document.body.appendChild( modalEl );

		modalEl.addEventListener( 'click', function ( e ) {
			if ( e.target === modalEl ) {
				closeModal();
			}
		} );
		modalEl.querySelector( '.eop-close' ).addEventListener( 'click', closeModal );
		modalEl.querySelector( '.eop-add-more' ).addEventListener( 'click', closeModal );
		modalEl.querySelector( '.eop-form' ).addEventListener( 'submit', handleSubmit );

		document.addEventListener( 'keydown', function ( e ) {
			if ( 'Escape' === e.key && modalEl.classList.contains( 'eop-open' ) ) {
				closeModal();
			}
		} );

		return modalEl;
	}

	function escapeHtml( str ) {
		var div = document.createElement( 'div' );
		div.textContent = String( str == null ? '' : str );
		return div.innerHTML;
	}

	function renderItems() {
		var el = ensureModal();
		var wrap = el.querySelector( '.eop-items' );

		if ( ! cart.length ) {
			wrap.innerHTML = '<p class="eop-empty">' + escapeHtml( settings.i18n.emptyCart ) + '</p>';
			return;
		}

		var total = 0;
		var rows = cart
			.map( function ( item, index ) {
				var lineTotal = ( item.price || 0 ) * item.qty;
				total += lineTotal;
				return (
					'<div class="eop-item" data-index="' + index + '">' +
					( item.image ? '<img class="eop-item-img" src="' + escapeHtml( item.image ) + '" alt="">' : '' ) +
					'<div class="eop-item-info">' +
					'<div class="eop-item-title">' + escapeHtml( item.title ) + '</div>' +
					( item.price ? '<div class="eop-item-price">' + formatMoney( lineTotal ) + '</div>' : '' ) +
					'</div>' +
					'<input type="number" min="1" class="eop-item-qty" value="' + item.qty + '">' +
					'<button type="button" class="eop-item-remove" aria-label="' + escapeHtml( settings.i18n.remove ) + '">&times;</button>' +
					'</div>'
				);
			} )
			.join( '' );

		wrap.innerHTML =
			rows +
			'<div class="eop-total">' + escapeHtml( settings.i18n.total ) + ': <strong>' + formatMoney( total ) + '</strong></div>';

		wrap.querySelectorAll( '.eop-item-remove' ).forEach( function ( btn ) {
			btn.addEventListener( 'click', function () {
				var index = parseInt( btn.closest( '.eop-item' ).getAttribute( 'data-index' ), 10 );
				removeFromCart( index );
				renderItems();
			} );
		} );

		wrap.querySelectorAll( '.eop-item-qty' ).forEach( function ( input ) {
			input.addEventListener( 'change', function () {
				var index = parseInt( input.closest( '.eop-item' ).getAttribute( 'data-index' ), 10 );
				updateQty( index, input.value );
				renderItems();
			} );
		} );
	}

	function formatMoney( amount ) {
		if ( ! amount ) {
			return '—';
		}
		return Math.round( amount ).toLocaleString( 'ru-RU' ) + ' ' + settings.currency;
	}

	function openModal() {
		var el = ensureModal();
		renderItems();
		el.classList.add( 'eop-open' );
		el.setAttribute( 'aria-hidden', 'false' );
		document.documentElement.classList.add( 'eop-no-scroll' );
		var firstInput = el.querySelector( 'input[name="name"]' );
		if ( firstInput ) {
			window.setTimeout( function () {
				firstInput.focus();
			}, 50 );
		}
	}

	function closeModal() {
		if ( ! modalEl ) {
			return;
		}
		modalEl.classList.remove( 'eop-open' );
		modalEl.setAttribute( 'aria-hidden', 'true' );
		document.documentElement.classList.remove( 'eop-no-scroll' );
	}

	function showNotice( text, isError ) {
		var el = ensureModal();
		var notice = el.querySelector( '.eop-notice' );
		notice.textContent = text;
		notice.hidden = ! text;
		notice.classList.toggle( 'eop-notice-error', !! isError );
		notice.classList.toggle( 'eop-notice-success', ! isError );
	}

	function handleSubmit( e ) {
		e.preventDefault();

		if ( ! cart.length ) {
			showNotice( settings.i18n.emptyCart, true );
			return;
		}

		var form = e.target;
		if ( ! form.checkValidity() ) {
			form.reportValidity();
			return;
		}

		var submitBtn = form.querySelector( '.eop-submit' );
		var originalLabel = submitBtn.textContent;
		submitBtn.disabled = true;
		submitBtn.textContent = settings.i18n.sending;
		showNotice( '', false );

		var payload = new URLSearchParams();
		payload.set( 'action', 'eop_submit_order' );
		payload.set( 'nonce', settings.nonce );
		payload.set( 'name', form.name.value );
		payload.set( 'phone', form.phone.value );
		payload.set( 'email', form.email.value );
		payload.set( 'comment', form.comment.value );
		payload.set( 'eop_website', form.eop_website.value );
		payload.set( 'page_url', window.location.href );
		payload.set( 'items', JSON.stringify( cart ) );

		fetch( settings.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
			body: payload.toString(),
		} )
			.then( function ( res ) {
				return res.json();
			} )
			.then( function ( json ) {
				submitBtn.disabled = false;
				submitBtn.textContent = originalLabel;

				if ( json && json.success ) {
					cart = [];
					saveCart();
					showNotice( ( json.data && json.data.message ) || settings.i18n.successMsg, false );
					form.reset();
					renderItems();
					window.setTimeout( closeModal, 2500 );
				} else {
					showNotice( ( json && json.data && json.data.message ) || settings.i18n.errorMsg, true );
				}
			} )
			.catch( function () {
				submitBtn.disabled = false;
				submitBtn.textContent = originalLabel;
				showNotice( settings.i18n.errorMsg, true );
			} );
	}

	/* ---------- Wire up ---------- */

	document.addEventListener( 'click', function ( e ) {
		var trigger = findTrigger( e.target );
		if ( ! trigger ) {
			return;
		}
		e.preventDefault();
		e.stopPropagation();

		var product = extractProduct( trigger );
		addToCart( product );
		openModal();
	}, true );
} )();
