( function () {
	'use strict';
	document.addEventListener( 'click', function ( event ) {
		var button = event.target.closest( '[data-atomic-social-load-more]' );
		if ( ! button || button.disabled ) { return; }
		var feed = button.closest( '.atomic-social-feed' );
		var grid = feed && feed.querySelector( '.atomic-social-feed__grid' );
		var status = button.parentElement.querySelector( '[aria-live]' );
		if ( ! grid ) { return; }
		button.disabled = true;
		button.setAttribute( 'aria-busy', 'true' );
		fetch( window.atomicSocialFeed.restUrl, {
			method: 'POST',
			headers: { 'Content-Type': 'application/json' },
			body: button.dataset.attributes
		} ).then( function ( response ) {
			if ( ! response.ok ) { throw new Error( 'Request failed' ); }
			return response.json();
		} ).then( function ( result ) {
			grid.insertAdjacentHTML( 'beforeend', result.html );
			var attributes = JSON.parse( button.dataset.attributes );
			attributes.page = result.page + 1;
			button.dataset.attributes = JSON.stringify( attributes );
			if ( status ) { status.textContent = window.atomicSocialFeed.loadedMessage; }
			if ( ! result.has_more ) { button.remove(); return; }
			button.disabled = false;
			button.removeAttribute( 'aria-busy' );
		} ).catch( function () {
			button.disabled = false;
			button.removeAttribute( 'aria-busy' );
			if ( status ) { status.textContent = window.atomicSocialFeed.errorMessage; }
		} );
	} );
} )();
