/**
 * GoHighLevel Integration — filter bar behaviour.
 *
 * Progressive enhancement: the markup works as a plain GET form, and this
 * script upgrades it to fetch rendered fragments from the REST endpoint so the
 * page doesn't reload while filtering or paging.
 */
( function () {
	'use strict';

	var config = window.GHLDirectory || {};
	var DEBOUNCE_MS = 300;

	if ( ! config.endpoint || ! window.fetch ) {
		return;
	}

	/**
	 * Wire up one directory instance.
	 *
	 * @param {HTMLElement} root Directory wrapper.
	 */
	function init( root ) {
		var instance = root.getAttribute( 'data-ghld-instance' );
		var form = root.querySelector( '[data-ghld-form]' );
		var results = root.querySelector( '[data-ghld-results]' );
		var pagination = root.querySelector( '[data-ghld-pagination]' );
		var summary = root.querySelector( '[data-ghld-summary]' );
		var controller = null;
		var timer = null;

		if ( ! instance || ! results ) {
			return;
		}

		root.classList.add( 'is-enhanced' );

		/**
		 * Current filter values plus an explicit page.
		 *
		 * @param {number} page Page to request.
		 * @return {URLSearchParams} Query parameters.
		 */
		function params( page ) {
			var query = new URLSearchParams();
			query.set( 'instance', instance );

			if ( form ) {
				Array.prototype.forEach.call( form.querySelectorAll( '[data-ghld-control]' ), function ( control ) {
					if ( control.name && control.value ) {
						query.set( control.name, control.value );
					}
				} );
			}

			query.set( config.prefix + 'page', String( page || 1 ) );

			return query;
		}

		/**
		 * Fetch and swap in a page of results.
		 *
		 * @param {number}  page       Page to request.
		 * @param {boolean} scrollBack Whether to scroll the directory into view.
		 */
		function load( page, scrollBack ) {
			var query = params( page );

			if ( controller ) {
				controller.abort();
			}
			controller = typeof AbortController === 'function' ? new AbortController() : null;

			results.setAttribute( 'aria-busy', 'true' );

			fetch( config.endpoint + '?' + query.toString(), {
				credentials: 'same-origin',
				headers: {
					Accept: 'application/json',
					'X-WP-Nonce': config.nonce || ''
				},
				signal: controller ? controller.signal : undefined
			} )
				.then( function ( response ) {
					if ( ! response.ok ) {
						throw new Error( 'HTTP ' + response.status );
					}

					return response.json();
				} )
				.then( function ( data ) {
					results.innerHTML = data.results;

					if ( pagination ) {
						pagination.innerHTML = data.pagination || '';
					}
					if ( summary ) {
						summary.textContent = data.summary || '';
					}

					results.setAttribute( 'aria-busy', 'false' );
					updateUrl( query );

					if ( scrollBack ) {
						root.scrollIntoView( { behavior: 'smooth', block: 'start' } );
					}
				} )
				.catch( function ( error ) {
					if ( error && 'AbortError' === error.name ) {
						return;
					}

					results.setAttribute( 'aria-busy', 'false' );

					// A scope that expired server-side only needs a real page load.
					if ( form ) {
						form.submit();

						return;
					}

					results.innerHTML = '<p class="ghld-error"></p>';
					results.firstChild.textContent = ( config.i18n && config.i18n.error ) || 'Could not load contacts.';
				} );
		}

		/**
		 * Keep the address bar in step so filtered views can be shared.
		 *
		 * @param {URLSearchParams} query Current query.
		 */
		function updateUrl( query ) {
			if ( ! window.history || ! window.history.replaceState ) {
				return;
			}

			var url = new URL( window.location.href );

			// Drop this plugin's params, then re-add the active ones.
			Array.prototype.forEach.call( Array.from( url.searchParams.keys() ), function ( key ) {
				if ( 0 === key.indexOf( config.prefix ) ) {
					url.searchParams.delete( key );
				}
			} );

			query.forEach( function ( value, key ) {
				if ( 'instance' === key ) {
					return;
				}
				if ( key === config.prefix + 'page' && '1' === value ) {
					return;
				}
				url.searchParams.set( key, value );
			} );

			window.history.replaceState( {}, '', url.toString() );
		}

		/**
		 * Debounced reload back to page one.
		 */
		function schedule() {
			window.clearTimeout( timer );
			timer = window.setTimeout( function () {
				load( 1, false );
			}, DEBOUNCE_MS );
		}

		if ( form ) {
			form.addEventListener( 'submit', function ( event ) {
				event.preventDefault();
				window.clearTimeout( timer );
				load( 1, false );
			} );

			form.addEventListener( 'input', function ( event ) {
				if ( event.target.matches( '[data-ghld-control]' ) ) {
					schedule();
				}
			} );

			form.addEventListener( 'change', function ( event ) {
				if ( event.target.matches( 'select[data-ghld-control]' ) ) {
					window.clearTimeout( timer );
					load( 1, false );
				}
			} );

			form.addEventListener( 'reset', function () {
				// Let the browser clear the fields before reading them back.
				window.setTimeout( function () {
					load( 1, false );
				}, 0 );
			} );
		}

		root.addEventListener( 'click', function ( event ) {
			var link = event.target.closest( '[data-ghld-page]' );

			if ( ! link || ! root.contains( link ) ) {
				return;
			}

			event.preventDefault();
			load( parseInt( link.getAttribute( 'data-ghld-page' ), 10 ) || 1, true );
		} );
	}

	/**
	 * Boot every directory on the page.
	 */
	function boot() {
		Array.prototype.forEach.call(
			document.querySelectorAll( '.ghld-directory[data-ghld-instance]' ),
			init
		);
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', boot );
	} else {
		boot();
	}
}() );
