/* global window, document */
( function () {
	'use strict';

	function initCarousel( root ) {
		var scroller = root.querySelector( '.atomic-social-feed__grid' );
		var prev = root.querySelector( '[data-atomic-linkedin-carousel-prev]' );
		var next = root.querySelector( '[data-atomic-linkedin-carousel-next]' );
		if ( ! scroller || ! prev || ! next ) { return; }

		function update() {
			var max = scroller.scrollWidth - scroller.clientWidth;
			var x = scroller.scrollLeft;
			prev.disabled = x <= 1;
			next.disabled = x >= max - 1;
		}

		function scrollByPage( dir ) {
			var amount = Math.max( 160, scroller.clientWidth * 0.9 );
			scroller.scrollBy( { left: dir * amount, behavior: 'smooth' } );
		}

		prev.addEventListener( 'click', function () { scrollByPage( -1 ); } );
		next.addEventListener( 'click', function () { scrollByPage( 1 ); } );
		scroller.addEventListener( 'scroll', update, { passive: true } );

		update();
		window.setTimeout( update, 0 );
	}

	document.addEventListener( 'DOMContentLoaded', function () {
		document.querySelectorAll( '[data-atomic-linkedin-carousel], [data-ermn-news-carousel]' ).forEach( initCarousel );
	} );
} )();

