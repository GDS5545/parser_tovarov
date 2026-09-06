/**
 * Vanilla JS for the Universal Scraper admin pages. No build step and no
 * external dependency: everything here just calls the plugin's own REST
 * routes under /wp-json/uws/v1 using the nonce localized as `uwsAdmin`.
 */
( function () {
	'use strict';

	if ( typeof window.uwsAdmin === 'undefined' ) {
		return;
	}

	// Holds the last /analyze response so the Import button can rebuild
	// the full ProductData shape (including fields with no dedicated
	// input, like gtin/mpn/stock_status) from it plus whatever the user
	// edited in the visible fields.
	var lastAnalyzeResult = null;

	function restRequest( path, method, body ) {
		return fetch( uwsAdmin.restUrl + path, {
			method: method,
			headers: {
				'Content-Type': 'application/json',
				'X-WP-Nonce': uwsAdmin.nonce,
			},
			body: body ? JSON.stringify( body ) : undefined,
		} ).then( function ( response ) {
			return response.json().then( function ( data ) {
				return { ok: response.ok, status: response.status, data: data };
			} );
		} );
	}

	function showError( message ) {
		var box = document.getElementById( 'uws-error' );
		var text = document.getElementById( 'uws-error-message' );
		if ( ! box || ! text ) {
			return;
		}
		text.textContent = message;
		box.hidden = false;
	}

	function confidenceClass( value ) {
		if ( typeof value !== 'number' ) {
			return '';
		}
		if ( value >= 0.85 ) {
			return 'uws-confidence-high';
		}
		if ( value >= 0.5 ) {
			return 'uws-confidence-medium';
		}
		return 'uws-confidence-low';
	}

	function applyConfidenceStyling( result ) {
		document.querySelectorAll( '.uws-confidence-field' ).forEach( function ( field ) {
			var name = field.getAttribute( 'data-field' );
			var confidence = result.confidence ? result.confidence[ name ] : undefined;
			field.classList.remove( 'uws-confidence-high', 'uws-confidence-medium', 'uws-confidence-low' );
			var cls = confidenceClass( confidence );
			if ( cls ) {
				field.classList.add( cls );
			}
		} );
	}

	function renderAttributes( attributes ) {
		var container = document.getElementById( 'uws-attributes-list' );
		container.innerHTML = '';

		( attributes || [] ).forEach( function ( attribute, index ) {
			var row = document.createElement( 'div' );
			row.className = 'uws-attribute-row';
			row.innerHTML =
				'<input type="text" class="uws-attr-key" value="' + escapeHtml( attribute.attribute_key || '' ) + '" style="width:30%;" />' +
				'<input type="text" class="uws-attr-value" value="' + escapeHtml( attribute.value_raw || '' ) + '" style="width:40%;" />' +
				'<label style="white-space:nowrap;"><input type="checkbox" class="uws-attr-variation" ' + ( attribute.is_variation ? 'checked' : '' ) + ' /> ' +
					( uwsAdmin.i18n.usedForVariations || 'Used for variations' ) + '</label>' +
				'<button type="button" class="button uws-attr-remove" data-index="' + index + '">&times;</button>';
			container.appendChild( row );
		} );

		container.querySelectorAll( '.uws-attr-remove' ).forEach( function ( button ) {
			button.addEventListener( 'click', function () {
				button.closest( '.uws-attribute-row' ).remove();
			} );
		} );
	}

	function renderImages( images ) {
		var container = document.getElementById( 'uws-images-list' );
		container.innerHTML = '';

		( images || [] ).forEach( function ( image, index ) {
			var row = document.createElement( 'label' );
			row.style.display = 'block';
			row.innerHTML =
				'<input type="checkbox" class="uws-image-include" data-index="' + index + '" checked /> ' +
				escapeHtml( image.url ) + ( image.is_main ? ' (' + ( window.uwsAdmin.i18n.mainImage || 'main' ) + ')' : '' );
			container.appendChild( row );
		} );
	}

	function initAddImage() {
		var button = document.getElementById( 'uws-add-image-btn' );
		var input = document.getElementById( 'uws-add-image-url' );
		if ( ! button || ! input ) {
			return;
		}

		button.addEventListener( 'click', function () {
			var url = input.value.trim();
			if ( ! url ) {
				return;
			}
			if ( ! lastAnalyzeResult ) {
				lastAnalyzeResult = {};
			}
			if ( ! Array.isArray( lastAnalyzeResult.images ) ) {
				lastAnalyzeResult.images = [];
			}
			lastAnalyzeResult.images.push( { url: url, is_main: 0 === lastAnalyzeResult.images.length, variation_key: null } );
			renderImages( lastAnalyzeResult.images );
			input.value = '';
		} );
	}

	function escapeHtml( value ) {
		var div = document.createElement( 'div' );
		div.textContent = value == null ? '' : String( value );
		return div.innerHTML;
	}

	function populatePreview( result ) {
		lastAnalyzeResult = result;

		document.getElementById( 'uws-f-name' ).value = result.name || '';
		document.getElementById( 'uws-f-sku' ).value = result.sku || '';
		document.getElementById( 'uws-f-brand' ).value = result.brand || '';
		document.getElementById( 'uws-f-regular-price' ).value = result.regular_price || '';
		document.getElementById( 'uws-f-sale-price' ).value = result.sale_price || '';
		document.getElementById( 'uws-f-currency' ).value = result.currency || '';
		document.getElementById( 'uws-f-categories' ).value = ( result.categories || [] ).join( ', ' );
		document.getElementById( 'uws-f-short-description' ).value = result.short_description || '';
		document.getElementById( 'uws-f-description' ).value = result.description || '';

		document.getElementById( 'uws-f-is-variable' ).checked = 'variable' === result.product_type;

		renderAttributes( result.attributes );
		renderImages( result.images );
		applyConfidenceStyling( result );
		renderEngineNote( result );

		document.getElementById( 'uws-preview' ).hidden = false;
	}

	function renderEngineNote( result ) {
		var note = document.getElementById( 'uws-engine-note' );
		if ( ! note ) {
			return;
		}

		if ( 'HttpEngine' === result.engine && ! result.name && ! result.regular_price ) {
			note.textContent = uwsAdmin.i18n.httpEngineEmptyHint ||
				'Fetched via plain HTTP (no browser) and found little usable data — this site may render its content with JavaScript. Try deploying the Playwright worker under Browser Settings for this site.';
		} else if ( 'HttpEngine' === result.engine ) {
			note.textContent = uwsAdmin.i18n.httpEngineHint || 'Fetched via plain HTTP (no browser needed).';
		} else if ( result.engine ) {
			note.textContent = uwsAdmin.i18n.browserEngineHint || 'Fetched via the Playwright browser worker.';
		} else {
			note.textContent = '';
		}
	}

	function collectEditedData() {
		var base = lastAnalyzeResult ? JSON.parse( JSON.stringify( lastAnalyzeResult ) ) : {};

		base.name = document.getElementById( 'uws-f-name' ).value;
		base.sku = document.getElementById( 'uws-f-sku' ).value;
		base.brand = document.getElementById( 'uws-f-brand' ).value;
		base.regular_price = document.getElementById( 'uws-f-regular-price' ).value;
		base.sale_price = document.getElementById( 'uws-f-sale-price' ).value;
		base.currency = document.getElementById( 'uws-f-currency' ).value;
		base.categories = document.getElementById( 'uws-f-categories' ).value
			.split( ',' )
			.map( function ( s ) { return s.trim(); } )
			.filter( Boolean );
		base.short_description = document.getElementById( 'uws-f-short-description' ).value;
		base.description = document.getElementById( 'uws-f-description' ).value;
		base.product_type = document.getElementById( 'uws-f-is-variable' ).checked ? 'variable' : 'simple';

		base.attributes = Array.prototype.map.call(
			document.querySelectorAll( '#uws-attributes-list .uws-attribute-row' ),
			function ( row ) {
				return {
					attribute_key: row.querySelector( '.uws-attr-key' ).value,
					value_raw: row.querySelector( '.uws-attr-value' ).value,
					is_variation: row.querySelector( '.uws-attr-variation' ).checked,
					source: 'user',
				};
			}
		).filter( function ( attribute ) {
			return attribute.attribute_key && attribute.value_raw;
		} );

		var includedImages = [];
		document.querySelectorAll( '#uws-images-list .uws-image-include' ).forEach( function ( checkbox ) {
			if ( checkbox.checked ) {
				var index = Number( checkbox.getAttribute( 'data-index' ) );
				if ( base.images && base.images[ index ] ) {
					includedImages.push( base.images[ index ] );
				}
			}
		} );
		base.images = includedImages;

		return base;
	}

	function submitImport( action ) {
		var status = document.getElementById( 'uws-import-status' );
		var button = document.getElementById( 'uws-import-btn' );
		var data = collectEditedData();

		button.disabled = true;
		status.textContent = uwsAdmin.i18n.importing || 'Importing…';

		restRequest( '/import', 'POST', { data: data, action: action || 'create' } ).then( function ( result ) {
			button.disabled = false;

			if ( 409 === result.status && 'duplicate' === result.data.status ) {
				var choice = window.prompt(
					( uwsAdmin.i18n.duplicateFound || 'This product was already imported (product #' ) +
						result.data.existing.product_id +
						( uwsAdmin.i18n.duplicatePromptSuffix || '). Type "update", "duplicate", or "skip":' ),
					'update'
				);
				if ( choice && [ 'update', 'duplicate', 'skip' ].indexOf( choice ) !== -1 ) {
					submitImport( choice );
				} else {
					status.textContent = '';
				}
				return;
			}

			if ( ! result.ok ) {
				status.textContent = '';
				showError( result.data && result.data.message ? result.data.message : uwsAdmin.i18n.error );
				return;
			}

			if ( 'imported' === result.data.status ) {
				status.textContent = ( uwsAdmin.i18n.imported || 'Imported as product #' ) + result.data.product_id + '.';
				if ( result.data.warnings && result.data.warnings.length ) {
					status.textContent += ' ' + result.data.warnings.join( ' ' );
				}
			} else {
				status.textContent = result.data.status;
			}
		} );
	}

	function initAnalyzeForm() {
		var form = document.getElementById( 'uws-analyze-form' );
		if ( ! form ) {
			return;
		}

		form.addEventListener( 'submit', function ( event ) {
			event.preventDefault();

			var urlInput = document.getElementById( 'uws-product-url' );
			var button = document.getElementById( 'uws-analyze-btn' );
			var preview = document.getElementById( 'uws-preview' );
			var errorBox = document.getElementById( 'uws-error' );

			if ( errorBox ) {
				errorBox.hidden = true;
			}
			if ( preview ) {
				preview.hidden = true;
			}

			button.disabled = true;
			button.textContent = uwsAdmin.i18n.analyzing;

			restRequest( '/analyze', 'POST', { url: urlInput.value } )
				.then( function ( result ) {
					if ( ! result.ok ) {
						showError( result.data && result.data.message ? result.data.message : uwsAdmin.i18n.error );
						return;
					}
					populatePreview( result.data );
				} )
				.finally( function () {
					button.disabled = false;
					button.textContent = uwsAdmin.i18n.analyzeProduct || 'Analyze Product';
				} );
		} );

		var importButton = document.getElementById( 'uws-import-btn' );
		if ( importButton ) {
			importButton.addEventListener( 'click', function () {
				submitImport( 'create' );
			} );
		}
	}

	function initBulkImport() {
		var bulkButton = document.getElementById( 'uws-bulk-submit' );
		var categoryButton = document.getElementById( 'uws-category-submit' );
		var resultBox = document.getElementById( 'uws-bulk-result' );
		var resultMessage = document.getElementById( 'uws-bulk-result-message' );

		function showResult( message ) {
			if ( resultBox && resultMessage ) {
				resultMessage.textContent = message;
				resultBox.hidden = false;
			}
		}

		if ( bulkButton ) {
			bulkButton.addEventListener( 'click', function () {
				var textarea = document.getElementById( 'uws-bulk-urls' );
				var urls = textarea.value
					.split( '\n' )
					.map( function ( line ) {
						return line.trim();
					} )
					.filter( Boolean );

				if ( ! urls.length ) {
					return;
				}

				bulkButton.disabled = true;

				Promise.all(
					urls.map( function ( url ) {
						return restRequest( '/jobs', 'POST', { url: url, type: 'single' } );
					} )
				).then( function ( results ) {
					var queued = results.filter( function ( r ) { return r.ok; } ).length;
					var template = uwsAdmin.i18n.urlsQueuedTemplate || '%1$d / %2$d URLs queued.';
					showResult( template.replace( '%1$d', queued ).replace( '%2$d', urls.length ) );
					bulkButton.disabled = false;
				} );
			} );
		}

		if ( categoryButton ) {
			categoryButton.addEventListener( 'click', function () {
				var input = document.getElementById( 'uws-category-url' );
				var depthInput = document.getElementById( 'uws-category-depth' );
				if ( ! input.value ) {
					return;
				}
				categoryButton.disabled = true;
				var payload = { url: input.value, type: 'category' };
				if ( depthInput && depthInput.value ) {
					payload.max_depth = parseInt( depthInput.value, 10 );
				}
				restRequest( '/jobs', 'POST', payload ).then( function ( result ) {
					categoryButton.disabled = false;
					if ( result.ok ) {
						var template = uwsAdmin.i18n.categoryQueuedTemplate || 'Category job #%d queued.';
						showResult( template.replace( '%d', result.data.id ) );
					} else {
						showResult( result.data && result.data.message ? result.data.message : uwsAdmin.i18n.error );
					}
				} );
			} );
		}
	}

	function initQueueActions() {
		document.querySelectorAll( '.uws-job-cancel, .uws-job-retry' ).forEach( function ( button ) {
			button.addEventListener( 'click', function () {
				var id = button.getAttribute( 'data-id' );
				var action = button.classList.contains( 'uws-job-cancel' ) ? 'cancel' : 'retry';

				button.disabled = true;
				restRequest( '/jobs/' + id + '/' + action, 'POST' ).then( function ( result ) {
					if ( result.ok ) {
						window.location.reload();
					} else {
						alert( result.data && result.data.message ? result.data.message : uwsAdmin.i18n.error ); // eslint-disable-line no-alert
						button.disabled = false;
					}
				} );
			} );
		} );
	}

	document.addEventListener( 'DOMContentLoaded', function () {
		initAnalyzeForm();
		initBulkImport();
		initQueueActions();
		initAddImage();
	} );
} )();
