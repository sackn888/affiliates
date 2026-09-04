( function () {
	'use strict';

	function run( button, action ) {
		var config = window.rltBulk;
		var status = document.getElementById( 'rlt-bulk-status' );
		var bar = document.getElementById( 'rlt-bulk-bar' );

		button.disabled = true;

		function step( offset ) {
			var body = new URLSearchParams();
			body.append( 'action', action );
			body.append( '_ajax_nonce', config.nonce );
			body.append( 'offset', String( offset ) );

			fetch( config.ajaxUrl, {
				method: 'POST',
				credentials: 'same-origin',
				body: body
			} )
				.then( function ( response ) {
					return response.json();
				} )
				.then( function ( json ) {
					if ( ! json.success ) {
						status.textContent = config.failed;
						button.disabled = false;
						return;
					}

					var data = json.data;
					var percent = data.total > 0 ? Math.round( ( data.offset / data.total ) * 100 ) : 100;

					bar.style.width = percent + '%';
					status.textContent = data.offset + ' / ' + data.total;

					if ( data.done ) {
						status.textContent = config.finished.replace( '%d', String( data.total ) );
						button.disabled = false;
						return;
					}

					step( data.offset );
				} )
				.catch( function () {
					status.textContent = config.failed;
					button.disabled = false;
				} );
		}

		step( 0 );
	}

	document.addEventListener( 'DOMContentLoaded', function () {
		var convert = document.getElementById( 'rlt-bulk-convert' );
		var restore = document.getElementById( 'rlt-bulk-restore' );

		// A browser too old to have fetch cannot drive the batch loop; give up
		// silently rather than throwing when a button is clicked. Mirrors
		// assets/beacon.js's own fetch guard.
		if ( typeof fetch !== 'function' ) {
			return;
		}

		if ( convert ) {
			convert.addEventListener( 'click', function ( event ) {
				event.preventDefault();
				run( convert, 'rlt_bulk_convert' );
			} );
		}

		if ( restore ) {
			restore.addEventListener( 'click', function ( event ) {
				event.preventDefault();

				if ( ! window.confirm( window.rltBulk.confirmRestore ) ) {
					return;
				}

				run( restore, 'rlt_bulk_restore' );
			} );
		}
	} );
}() );
