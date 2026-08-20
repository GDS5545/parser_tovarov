/* global PTV_DATA */
( function () {
	'use strict';

	if ( typeof PTV_DATA === 'undefined' ) {
		return;
	}

	/* ------------------------------------------------------------------ *
	 * Small helpers
	 * ------------------------------------------------------------------ */

	function ajax( action, data ) {
		var form = new FormData();
		form.append( 'action', action );
		form.append( 'nonce', PTV_DATA.nonce );
		Object.keys( data || {} ).forEach( function ( key ) {
			var value = data[ key ];
			if ( value instanceof File ) {
				form.append( key, value );
			} else if ( typeof value === 'object' && value !== null ) {
				form.append( key, JSON.stringify( value ) );
			} else if ( value !== undefined && value !== null ) {
				form.append( key, value );
			}
		} );

		return fetch( PTV_DATA.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: form } )
			.then( function ( response ) {
				return response.json();
			} );
	}

	function debounce( fn, wait ) {
		var timer = null;
		return function () {
			var args = arguments;
			var ctx = this;
			clearTimeout( timer );
			timer = setTimeout( function () {
				fn.apply( ctx, args );
			}, wait );
		};
	}

	function pluralRu( n, one, few, many ) {
		n = Math.abs( n ) % 100;
		var n1 = n % 10;
		if ( n > 10 && n < 20 ) {
			return many;
		}
		if ( n1 > 1 && n1 < 5 ) {
			return few;
		}
		if ( n1 === 1 ) {
			return one;
		}
		return many;
	}

	/* ------------------------------------------------------------------ *
	 * Catalog table instances
	 * ------------------------------------------------------------------ */

	function initCatalog( root ) {
		var configEl = root.querySelector( '.ptv-config' );
		if ( ! configEl ) {
			return;
		}
		var config = JSON.parse( configEl.textContent );

		var state = {
			categories: config.categories,
			columns: config.columns,
			perPage: config.perPage,
			page: config.page || 1,
			maxPages: config.maxPages || 1,
			orderby: config.orderby || 'title',
			order: config.order || 'ASC',
			search: '',
			filters: {}, // taxonomy => [slugs]
		};

		var tbody = root.querySelector( '.ptv-tbody' );
		var loading = root.querySelector( '.ptv-loading' );
		var pagination = root.querySelector( '.ptv-pagination' );
		var totalNumber = root.querySelector( '.ptv-total-number' );
		var activeFiltersEl = root.querySelector( '.ptv-active-filters' );
		var searchInput = root.querySelector( '.ptv-search-input' );
		var quickFiltersEl = root.querySelector( '.ptv-quick-filters' );
		var quickTaxonomy = quickFiltersEl ? quickFiltersEl.getAttribute( 'data-taxonomy' ) : null;

		function setLoading( isLoading ) {
			if ( loading ) {
				loading.hidden = ! isLoading;
			}
		}

		function fetchTable() {
			setLoading( true );
			ajax( 'ptv_get_table', {
				instance_id: config.instanceId,
				categories: state.categories,
				columns: state.columns,
				attributes: state.filters,
				search: state.search,
				orderby: state.orderby,
				order: state.order,
				page: state.page,
				per_page: state.perPage,
			} ).then( function ( response ) {
				setLoading( false );
				if ( ! response || ! response.success ) {
					return;
				}
				var data = response.data;
				tbody.innerHTML = data.rows_html;
				totalNumber.textContent = data.total;
				state.maxPages = data.max_pages;
				state.page = data.page;
				renderPagination();
			} ).catch( function () {
				setLoading( false );
			} );
		}

		function renderPagination() {
			pagination.innerHTML = '';
			if ( state.maxPages <= 1 ) {
				return;
			}

			function makeBtn( label, page, disabled, active ) {
				var btn = document.createElement( 'button' );
				btn.type = 'button';
				btn.className = 'ptv-page-btn' + ( active ? ' is-active' : '' );
				btn.textContent = label;
				btn.disabled = !! disabled;
				if ( ! disabled ) {
					btn.addEventListener( 'click', function () {
						state.page = page;
						fetchTable();
						root.scrollIntoView( { behavior: 'smooth', block: 'nearest' } );
					} );
				}
				return btn;
			}

			pagination.appendChild( makeBtn( '‹', state.page - 1, state.page <= 1, false ) );

			var start = Math.max( 1, state.page - 2 );
			var end = Math.min( state.maxPages, start + 4 );
			start = Math.max( 1, end - 4 );

			for ( var p = start; p <= end; p++ ) {
				pagination.appendChild( makeBtn( String( p ), p, false, p === state.page ) );
			}

			pagination.appendChild( makeBtn( '›', state.page + 1, state.page >= state.maxPages, false ) );
		}

		function renderActiveFilters() {
			activeFiltersEl.innerHTML = '';
			var hasAny = false;

			Object.keys( state.filters ).forEach( function ( taxonomy ) {
				( state.filters[ taxonomy ] || [] ).forEach( function ( slug ) {
					hasAny = true;
					var label = findOptionLabel( taxonomy, slug );
					var chip = document.createElement( 'span' );
					chip.className = 'ptv-active-chip';
					chip.innerHTML = '<span></span><button type="button" aria-label="remove">&times;</button>';
					chip.querySelector( 'span' ).textContent = label;
					chip.querySelector( 'button' ).addEventListener( 'click', function () {
						toggleFilter( taxonomy, slug, false );
					} );
					activeFiltersEl.appendChild( chip );
				} );
			} );

			if ( hasAny ) {
				var resetBtn = document.createElement( 'button' );
				resetBtn.type = 'button';
				resetBtn.className = 'ptv-reset-all';
				resetBtn.textContent = 'Сбросить всё';
				resetBtn.addEventListener( 'click', resetAllFilters );
				activeFiltersEl.appendChild( resetBtn );
				activeFiltersEl.hidden = false;
			} else {
				activeFiltersEl.hidden = true;
			}
		}

		function findOptionLabel( taxonomy, slug ) {
			var input = root.querySelector(
				'.ptv-filter-dropdown[data-taxonomy="' + cssEscape( taxonomy ) + '"] .ptv-filter-option input[value="' + cssEscape( slug ) + '"]'
			);
			if ( input && input.parentElement ) {
				var span = input.parentElement.querySelector( 'span' );
				if ( span ) {
					return span.textContent;
				}
			}
			if ( quickFiltersEl && quickTaxonomy === taxonomy ) {
				var chip = quickFiltersEl.querySelector( '.ptv-chip[data-value="' + cssEscape( slug ) + '"]' );
				if ( chip ) {
					return chip.textContent.trim();
				}
			}
			return slug;
		}

		function cssEscape( value ) {
			return String( value ).replace( /["\\]/g, '\\$&' );
		}

		function syncControlsUi() {
			// Checkboxes in dropdowns.
			root.querySelectorAll( '.ptv-filter-dropdown' ).forEach( function ( dropdown ) {
				var taxonomy = dropdown.getAttribute( 'data-taxonomy' );
				var active = state.filters[ taxonomy ] || [];
				dropdown.querySelectorAll( '.ptv-filter-option input' ).forEach( function ( input ) {
					input.checked = active.indexOf( input.value ) !== -1;
				} );
				dropdown.classList.toggle( 'is-active', active.length > 0 );
			} );

			// Quick chips.
			if ( quickFiltersEl && quickTaxonomy ) {
				var activeQuick = state.filters[ quickTaxonomy ] || [];
				quickFiltersEl.querySelectorAll( '.ptv-chip[data-value]' ).forEach( function ( chip ) {
					chip.classList.toggle( 'is-active', activeQuick.indexOf( chip.getAttribute( 'data-value' ) ) !== -1 );
				} );
			}
		}

		function toggleFilter( taxonomy, slug, checked ) {
			var current = state.filters[ taxonomy ] ? state.filters[ taxonomy ].slice() : [];
			var idx = current.indexOf( slug );
			if ( checked && idx === -1 ) {
				current.push( slug );
			} else if ( ! checked && idx !== -1 ) {
				current.splice( idx, 1 );
			}
			if ( current.length ) {
				state.filters[ taxonomy ] = current;
			} else {
				delete state.filters[ taxonomy ];
			}
			state.page = 1;
			syncControlsUi();
			renderActiveFilters();
			fetchTable();
		}

		function resetAllFilters() {
			state.filters = {};
			state.page = 1;
			if ( searchInput ) {
				searchInput.value = '';
				state.search = '';
			}
			syncControlsUi();
			renderActiveFilters();
			fetchTable();
		}

		/* ---- Filter dropdowns ---- */

		root.querySelectorAll( '.ptv-filter-dropdown' ).forEach( function ( dropdown ) {
			var taxonomy = dropdown.getAttribute( 'data-taxonomy' );
			var toggle = dropdown.querySelector( '.ptv-filter-toggle' );
			var search = dropdown.querySelector( '.ptv-filter-search' );

			toggle.addEventListener( 'click', function ( e ) {
				e.stopPropagation();
				var willOpen = ! dropdown.classList.contains( 'is-open' );
				root.querySelectorAll( '.ptv-filter-dropdown.is-open' ).forEach( function ( other ) {
					other.classList.remove( 'is-open' );
				} );
				dropdown.classList.toggle( 'is-open', willOpen );
				if ( willOpen && search ) {
					search.focus();
				}
			} );

			dropdown.querySelectorAll( '.ptv-filter-option input' ).forEach( function ( input ) {
				input.addEventListener( 'change', function () {
					toggleFilter( taxonomy, input.value, input.checked );
				} );
			} );

			if ( search ) {
				search.addEventListener( 'input', function () {
					var term = search.value.trim().toLowerCase();
					dropdown.querySelectorAll( '.ptv-filter-option' ).forEach( function ( option ) {
						var text = option.textContent.trim().toLowerCase();
						option.classList.toggle( 'is-hidden', term.length > 0 && text.indexOf( term ) === -1 );
					} );
				} );
			}
		} );

		document.addEventListener( 'click', function ( e ) {
			if ( ! root.contains( e.target ) ) {
				root.querySelectorAll( '.ptv-filter-dropdown.is-open' ).forEach( function ( d ) {
					d.classList.remove( 'is-open' );
				} );
			}
		} );

		/* ---- Quick filter chips ---- */

		if ( quickFiltersEl && quickTaxonomy ) {
			quickFiltersEl.querySelectorAll( '.ptv-chip[data-value]' ).forEach( function ( chip ) {
				chip.addEventListener( 'click', function () {
					var slug = chip.getAttribute( 'data-value' );
					var isActive = chip.classList.contains( 'is-active' );
					state.filters[ quickTaxonomy ] = isActive ? [] : [ slug ];
					if ( ! state.filters[ quickTaxonomy ].length ) {
						delete state.filters[ quickTaxonomy ];
					}
					state.page = 1;
					syncControlsUi();
					renderActiveFilters();
					fetchTable();
				} );
			} );

			var resetChip = quickFiltersEl.querySelector( '.ptv-chip-reset' );
			if ( resetChip ) {
				resetChip.addEventListener( 'click', function () {
					delete state.filters[ quickTaxonomy ];
					state.page = 1;
					syncControlsUi();
					renderActiveFilters();
					fetchTable();
				} );
			}
		}

		/* ---- Free-text search ---- */

		if ( searchInput ) {
			searchInput.addEventListener(
				'input',
				debounce( function () {
					state.search = searchInput.value.trim();
					state.page = 1;
					fetchTable();
				}, 350 )
			);
		}

		/* ---- Sortable headers ---- */

		root.querySelectorAll( '.ptv-th[data-sort]' ).forEach( function ( th ) {
			th.addEventListener( 'click', function () {
				var column = th.getAttribute( 'data-sort' );
				var orderby = 'name' === column ? 'title' : ( 'price' === column ? 'price' : 'attribute:' + column );

				if ( state.orderby === orderby ) {
					state.order = 'ASC' === state.order ? 'DESC' : 'ASC';
				} else {
					state.orderby = orderby;
					state.order = 'ASC';
				}

				root.querySelectorAll( '.ptv-th' ).forEach( function ( other ) {
					other.classList.remove( 'is-sorted', 'is-sorted-asc', 'is-sorted-desc' );
				} );
				th.classList.add( 'is-sorted', 'ASC' === state.order ? 'is-sorted-asc' : 'is-sorted-desc' );

				fetchTable();
			} );
		} );

		/* ---- Add to cart ---- */

		root.addEventListener( 'click', function ( e ) {
			var btn = e.target.closest( '.ptv-btn-buy' );
			if ( ! btn ) {
				return;
			}
			var productId = btn.getAttribute( 'data-product-id' );
			btn.disabled = true;
			ajax( 'ptv_add_to_cart', { product_id: productId, quantity: 1 } ).then( function ( response ) {
				btn.disabled = false;
				if ( response && response.success ) {
					btn.classList.add( 'is-added' );
					var original = btn.textContent;
					btn.textContent = PTV_DATA.i18n.addedToCart;
					setTimeout( function () {
						btn.classList.remove( 'is-added' );
						btn.textContent = original;
					}, 1600 );
					window.PTVCart.updateCount( response.data.cart_count );
					window.PTVCart.open();
				} else {
					window.alert( ( response && response.data && response.data.message ) || PTV_DATA.i18n.error );
				}
			} ).catch( function () {
				btn.disabled = false;
				window.alert( PTV_DATA.i18n.error );
			} );
		} );
	}

	/* ------------------------------------------------------------------ *
	 * Cart drawer (single instance per page)
	 * ------------------------------------------------------------------ */

	function initCart() {
		var toggle = document.getElementById( 'ptv-cart-toggle' );
		var overlay = document.getElementById( 'ptv-cart-overlay' );
		var drawer = document.getElementById( 'ptv-cart-drawer' );
		if ( ! drawer ) {
			return null;
		}
		var closeBtn = document.getElementById( 'ptv-cart-close' );
		var clearBtn = document.getElementById( 'ptv-cart-clear' );
		var body = document.getElementById( 'ptv-cart-body' );
		var countBadge = document.getElementById( 'ptv-cart-count' );
		var itemsLabel = document.getElementById( 'ptv-cart-items-label' );
		var checkoutBtn = document.getElementById( 'ptv-cart-checkout' );
		var continueBtn = document.getElementById( 'ptv-cart-continue' );
		var minAmount = parseFloat( checkoutBtn ? checkoutBtn.getAttribute( 'data-min-amount' ) : '0' ) || 0;
		var currentTotal = 0;

		function updateCount( count ) {
			countBadge.textContent = count;
			countBadge.setAttribute( 'data-count', count );
		}

		function refresh() {
			body.innerHTML = '<p class="ptv-cart-empty">' + PTV_DATA.i18n.loading + '</p>';
			ajax( 'ptv_get_cart', {} ).then( function ( response ) {
				if ( ! response || ! response.success ) {
					return;
				}
				var data = response.data;
				body.innerHTML = data.items_html;
				updateCount( data.cart_count );
				currentTotal = parseFloat( data.cart_total ) || 0;
				itemsLabel.textContent = data.cart_count
					? data.cart_count + ' ' + pluralRu( data.cart_count, PTV_DATA.i18n.itemsOne, PTV_DATA.i18n.itemsFew, PTV_DATA.i18n.itemsMany )
					: '';
				bindItemEvents();
				updateCheckoutState();
			} );
		}

		function updateCheckoutState() {
			if ( ! checkoutBtn ) {
				return;
			}
			var hasItems = !! body.querySelector( '.ptv-cart-item' );
			var blocked = hasItems && minAmount > 0 && currentTotal < minAmount;
			checkoutBtn.disabled = ! hasItems;
			checkoutBtn.title = blocked ? PTV_DATA.i18n.minOrderNotice : '';
		}

		function bindItemEvents() {
			body.querySelectorAll( '.ptv-qty-plus, .ptv-qty-minus' ).forEach( function ( btn ) {
				btn.addEventListener( 'click', function () {
					var key = btn.getAttribute( 'data-key' );
					var qtyEl = btn.parentElement.querySelector( '.ptv-qty-value' );
					var qty = parseInt( qtyEl.textContent, 10 ) || 1;
					qty = btn.classList.contains( 'ptv-qty-plus' ) ? qty + 1 : qty - 1;
					if ( qty < 1 ) {
						removeItem( key );
						return;
					}
					ajax( 'ptv_update_cart_item', { key: key, quantity: qty } ).then( function ( response ) {
						if ( response && response.success ) {
							body.innerHTML = response.data.items_html;
							updateCount( response.data.cart_count );
							currentTotal = parseFloat( response.data.cart_total ) || 0;
							bindItemEvents();
							updateCheckoutState();
						}
					} );
				} );
			} );

			body.querySelectorAll( '.ptv-cart-item-remove' ).forEach( function ( btn ) {
				btn.addEventListener( 'click', function () {
					removeItem( btn.getAttribute( 'data-key' ) );
				} );
			} );
		}

		function removeItem( key ) {
			ajax( 'ptv_remove_cart_item', { key: key } ).then( function ( response ) {
				if ( response && response.success ) {
					body.innerHTML = response.data.items_html;
					updateCount( response.data.cart_count );
					currentTotal = parseFloat( response.data.cart_total ) || 0;
					bindItemEvents();
					updateCheckoutState();
				}
			} );
		}

		function open() {
			refresh();
			overlay.hidden = false;
			drawer.classList.add( 'is-open' );
			drawer.setAttribute( 'aria-hidden', 'false' );
		}

		function close() {
			overlay.hidden = true;
			drawer.classList.remove( 'is-open' );
			drawer.setAttribute( 'aria-hidden', 'true' );
		}

		if ( toggle ) {
			toggle.addEventListener( 'click', open );
		}
		if ( closeBtn ) {
			closeBtn.addEventListener( 'click', close );
		}
		if ( overlay ) {
			overlay.addEventListener( 'click', close );
		}
		if ( clearBtn ) {
			clearBtn.addEventListener( 'click', function () {
				if ( ! window.confirm( PTV_DATA.i18n.confirmClear ) ) {
					return;
				}
				ajax( 'ptv_clear_cart', {} ).then( function ( response ) {
					if ( response && response.success ) {
						body.innerHTML = response.data.items_html;
						updateCount( response.data.cart_count );
						currentTotal = 0;
						updateCheckoutState();
					}
				} );
			} );
		}
		if ( checkoutBtn ) {
			checkoutBtn.addEventListener( 'click', function () {
				if ( minAmount > 0 && currentTotal < minAmount ) {
					window.alert( PTV_DATA.i18n.minOrderNotice );
					return;
				}
				close();
				window.PTVOrderModal.open();
			} );
		}
		if ( continueBtn ) {
			continueBtn.addEventListener( 'click', close );
		}

		return { open: open, close: close, updateCount: updateCount };
	}

	/* ------------------------------------------------------------------ *
	 * Quick-order modal (single instance per page)
	 * ------------------------------------------------------------------ */

	function initOrderModal( cart ) {
		var overlay = document.getElementById( 'ptv-order-overlay' );
		var modal = document.getElementById( 'ptv-order-modal' );
		if ( ! modal ) {
			return null;
		}
		var closeBtn = document.getElementById( 'ptv-order-close' );
		var form = document.getElementById( 'ptv-order-form' );
		var fileInput = document.getElementById( 'ptv-order-file' );
		var fileNameEl = document.getElementById( 'ptv-file-name' );
		var messageEl = document.getElementById( 'ptv-order-message' );
		var submitBtn = document.getElementById( 'ptv-order-submit' );

		function open() {
			overlay.hidden = false;
			modal.classList.add( 'is-open' );
			modal.setAttribute( 'aria-hidden', 'false' );
		}

		function close() {
			overlay.hidden = true;
			modal.classList.remove( 'is-open' );
			modal.setAttribute( 'aria-hidden', 'true' );
		}

		if ( closeBtn ) {
			closeBtn.addEventListener( 'click', close );
		}
		if ( overlay ) {
			overlay.addEventListener( 'click', close );
		}

		if ( fileInput ) {
			fileNameEl.dataset.default = fileNameEl.textContent;
			fileInput.addEventListener( 'change', function () {
				fileNameEl.textContent = fileInput.files.length ? fileInput.files[ 0 ].name : fileNameEl.dataset.default;
			} );
		}

		function showMessage( text, type ) {
			messageEl.hidden = false;
			messageEl.textContent = text;
			messageEl.className = 'ptv-form-message is-' + type;
		}

		if ( form ) {
			form.addEventListener( 'submit', function ( e ) {
				e.preventDefault();

				var name = document.getElementById( 'ptv-order-name' ).value.trim();
				var phone = document.getElementById( 'ptv-order-phone' ).value.trim();
				var consent = document.getElementById( 'ptv-order-consent' ).checked;

				if ( ! name || ! phone || ! consent ) {
					showMessage( PTV_DATA.i18n.fillRequired, 'error' );
					return;
				}

				submitBtn.disabled = true;
				var payload = {
					name: name,
					phone: phone,
					comment: document.getElementById( 'ptv-order-comment' ).value.trim(),
				};
				if ( fileInput && fileInput.files.length ) {
					payload.attachment = fileInput.files[ 0 ];
				}

				ajax( 'ptv_submit_order', payload ).then( function ( response ) {
					submitBtn.disabled = false;
					if ( response && response.success ) {
						showMessage( PTV_DATA.i18n.orderSuccess, 'success' );
						cart && cart.updateCount( 0 );
						form.reset();
						fileNameEl.textContent = fileNameEl.dataset.default;
						setTimeout( close, 2200 );
					} else {
						showMessage( ( response && response.data && response.data.message ) || PTV_DATA.i18n.error, 'error' );
					}
				} ).catch( function () {
					submitBtn.disabled = false;
					showMessage( PTV_DATA.i18n.error, 'error' );
				} );
			} );
		}

		return { open: open, close: close };
	}

	/* ------------------------------------------------------------------ *
	 * Boot
	 * ------------------------------------------------------------------ */

	document.addEventListener( 'DOMContentLoaded', function () {
		var cart = initCart();
		window.PTVCart = cart || { open: function () {}, close: function () {}, updateCount: function () {} };
		window.PTVOrderModal = initOrderModal( cart ) || { open: function () {}, close: function () {} };

		document.querySelectorAll( '.ptv-catalog' ).forEach( initCatalog );
	} );
} )();
