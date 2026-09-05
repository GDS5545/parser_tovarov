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
				if ( ! response.ok ) {
					var message = data && data.message ? data.message : uwsAdmin.i18n.error;
					throw new Error( message );
				}
				return data;
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
			var previewJson = document.getElementById( 'uws-preview-json' );
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
				.then( function ( data ) {
					if ( preview && previewJson ) {
						previewJson.textContent = JSON.stringify( data, null, 2 );
						preview.hidden = false;
					}
				} )
				.catch( function ( error ) {
					showError( error.message );
				} )
				.finally( function () {
					button.disabled = false;
					button.textContent = wp && wp.i18n ? wp.i18n.__( 'Analyze Product', 'universal-woo-scraper' ) : 'Analyze Product';
				} );
		} );
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
						return restRequest( '/jobs', 'POST', { url: url, type: 'single' } ).catch( function () {
							return null;
						} );
					} )
				).then( function ( results ) {
					var queued = results.filter( Boolean ).length;
					showResult( queued + ' / ' + urls.length + ' URLs queued.' );
					bulkButton.disabled = false;
				} );
			} );
		}

		if ( categoryButton ) {
			categoryButton.addEventListener( 'click', function () {
				var input = document.getElementById( 'uws-category-url' );
				if ( ! input.value ) {
					return;
				}
				categoryButton.disabled = true;
				restRequest( '/jobs', 'POST', { url: input.value, type: 'category' } )
					.then( function ( data ) {
						showResult( 'Category job #' + data.id + ' queued.' );
					} )
					.catch( function ( error ) {
						showResult( error.message );
					} )
					.finally( function () {
						categoryButton.disabled = false;
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
				restRequest( '/jobs/' + id + '/' + action, 'POST' )
					.then( function () {
						window.location.reload();
					} )
					.catch( function ( error ) {
						alert( error.message ); // eslint-disable-line no-alert
						button.disabled = false;
					} );
			} );
		} );
	}

	document.addEventListener( 'DOMContentLoaded', function () {
		initAnalyzeForm();
		initBulkImport();
		initQueueActions();
	} );
} )();
