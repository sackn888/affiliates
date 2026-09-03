( function () {
	'use strict';

	var config = window.rltBeacon;

	if ( ! config || ! config.endpoint || ! config.postId ) {
		return;
	}

	var payload = JSON.stringify( { post_id: config.postId } );

	// sendBeacon survives the page being closed mid-flight, which a plain fetch
	// does not.
	if ( navigator.sendBeacon ) {
		navigator.sendBeacon(
			config.endpoint,
			new Blob( [ payload ], { type: 'application/json' } )
		);
		return;
	}

	fetch( config.endpoint, {
		method: 'POST',
		headers: { 'Content-Type': 'application/json' },
		body: payload,
		keepalive: true,
		credentials: 'same-origin'
	} ).catch( function () {
		// A failed pageview count is not worth surfacing to the visitor.
	} );
}() );
