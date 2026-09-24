/**
 * Drives the "Fetch every contact now" progress loop.
 *
 * Several hundred contacts fetched one at a time outlasts any single PHP
 * request, so the browser asks for one batch at a time and keeps going until
 * the server reports nothing left.
 */
( function () {
	'use strict';

	var config = window.GHLDAdmin || {};
	var MAX_BATCHES = 200;

	document.addEventListener( 'DOMContentLoaded', function () {
		var button = document.querySelector( '[data-ghld-fetch-all]' );
		var status = document.querySelector( '[data-ghld-fetch-status]' );
		var bar = document.querySelector( '[data-ghld-fetch-bar]' );

		if ( ! button || ! status ) {
			return;
		}

		var batches = 0;

		/**
		 * Ask the server for one batch.
		 *
		 * @param {boolean} reset Clear the per-contact cooldown first.
		 */
		function step( reset ) {
			batches++;

			if ( batches > MAX_BATCHES ) {
				status.textContent = config.i18n.stalled;
				button.disabled = false;

				return;
			}

			var body = new FormData();
			body.append( 'action', 'ghld_enrich_batch' );
			body.append( 'nonce', config.nonce );
			body.append( 'reset', reset ? '1' : '0' );

			fetch( config.ajaxUrl, {
				method: 'POST',
				credentials: 'same-origin',
				body: body
			} )
				.then( function ( response ) {
					return response.json();
				} )
				.then( function ( payload ) {
					if ( ! payload || ! payload.success ) {
						status.textContent = ( payload && payload.data && payload.data.message ) || config.i18n.failed;
						button.disabled = false;

						return;
					}

					var data = payload.data;
					var done = data.total - data.remaining;

					status.textContent = config.i18n.progress
						.replace( '%1$s', done )
						.replace( '%2$s', data.total )
						.replace( '%3$s', data.photos );

					if ( bar ) {
						bar.style.width = data.total ? Math.round( ( done / data.total ) * 100 ) + '%' : '0';
					}

					if ( data.remaining > 0 ) {
						step( false );

						return;
					}

					status.textContent = config.i18n.done.replace( '%s', data.photos );
					button.disabled = false;
				} )
				.catch( function () {
					status.textContent = config.i18n.failed;
					button.disabled = false;
				} );
		}

		button.addEventListener( 'click', function ( event ) {
			event.preventDefault();
			button.disabled = true;
			batches = 0;
			status.textContent = config.i18n.starting;
			step( true );
		} );
	} );
}() );
