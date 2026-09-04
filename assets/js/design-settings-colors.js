/* global jQuery */
( function ( $ ) {
	'use strict';

	function dispatchNativeEvent( el, type ) {
		try {
			el.dispatchEvent( new Event( type, { bubbles: true } ) );
		} catch ( e ) {
			// IE fallback not needed in modern WP admin, but keep it safe.
		}
	}

	function setHidden( $control, value, label, color ) {
		var target = $control.data( 'target' );
		if ( ! target ) { return; }
		var $hidden = $( '#' + target );
		if ( ! $hidden.length ) { return; }

		$hidden.val( String( value || '' ) );
		dispatchNativeEvent( $hidden.get( 0 ), 'input' );
		dispatchNativeEvent( $hidden.get( 0 ), 'change' );

		// Update compact toggle display.
		if ( typeof label === 'string' ) {
			$control.find( '.atomic-color-control__toggle-label' ).text( label );
		}
		if ( typeof color === 'string' ) {
			$control.find( '.atomic-color-control__toggle-chip' ).css( 'background', color || 'transparent' );
		} else if ( '' === String( value || '' ) ) {
			$control.find( '.atomic-color-control__toggle-chip' ).css( 'background', 'transparent' );
		}

		// Selected styles.
		$control.find( '.atomic-color-control__swatch' ).removeClass( 'is-selected' ).attr( 'aria-selected', 'false' );
		$control.find( '.atomic-color-control__option' ).removeClass( 'is-selected' );

		$control.find( '.atomic-color-control__swatch[data-value="' + String( value ).replace( /"/g, '\\"' ) + '"]' ).addClass( 'is-selected' ).attr( 'aria-selected', 'true' );
		if ( '' === String( value || '' ) ) {
			$control.find( '.atomic-color-control__option[data-value=""]' ).addClass( 'is-selected' );
		}
	}

	function closePopover( $control ) {
		var $popover = $control.find( '.atomic-color-control__popover' );
		if ( ! $popover.length ) { return; }
		$popover.prop( 'hidden', true );
		$control.find( '.atomic-color-control__toggle' ).attr( 'aria-expanded', 'false' );
	}

	function openPopover( $control ) {
		var $popover = $control.find( '.atomic-color-control__popover' );
		if ( ! $popover.length ) { return; }
		$popover.prop( 'hidden', false );
		$control.find( '.atomic-color-control__toggle' ).attr( 'aria-expanded', 'true' );
	}

	function togglePopover( $control ) {
		var $popover = $control.find( '.atomic-color-control__popover' );
		if ( ! $popover.length ) { return; }
		if ( $popover.prop( 'hidden' ) ) {
			openPopover( $control );
		} else {
			closePopover( $control );
		}
	}

	function initControl( el ) {
		var $control = $( el );
		var $toggle = $control.find( '.atomic-color-control__toggle' );
		var $popover = $control.find( '.atomic-color-control__popover' );
		var $picker = $control.find( '.atomic-color-control__hex.atomic-color-picker[data-custom="1"]' );
		var defaultLabel = String( $control.data( 'default-label' ) || 'Default' );

		if ( $picker.length && $.fn.wpColorPicker ) {
			$picker.wpColorPicker( {
				change: function ( event, ui ) {
					var color = ui && ui.color ? ui.color.toString() : '';
					setHidden( $control, color, color, color );
				},
				clear: function () {
					setHidden( $control, '', defaultLabel, '' );
				}
			} );
		}

		$toggle.on( 'click', function () {
			// Close other open popovers.
			$( '.atomic-color-control__popover' ).each( function () {
				var $p = $( this );
				if ( ! $p.prop( 'hidden' ) && ! $.contains( $control.get( 0 ), $p.get( 0 ) ) ) {
					$p.prop( 'hidden', true );
					$p.closest( '.atomic-color-control' ).find( '.atomic-color-control__toggle' ).attr( 'aria-expanded', 'false' );
				}
			} );
			togglePopover( $control );
		} );

		$control.on( 'click', '.atomic-color-control__swatch', function () {
			var value = String( $( this ).data( 'value' ) || '' );
			var label = String( $( this ).data( 'label' ) || '' );
			var color = String( $( this ).data( 'color' ) || '' );
			setHidden( $control, value, label || defaultLabel, color );
			closePopover( $control );
		} );

		$control.on( 'click', '.atomic-color-control__option', function () {
			var value = String( $( this ).data( 'value' ) || '' );
			var label = String( $( this ).data( 'label' ) || defaultLabel );
			setHidden( $control, value, label, '' );
			closePopover( $control );
		} );

		// Keyboard: Enter/Space on toggle opens; Escape handled globally.
		$toggle.on( 'keydown', function ( e ) {
			if ( 'ArrowDown' === e.key || 'Enter' === e.key || ' ' === e.key ) {
				e.preventDefault();
				openPopover( $control );
				$control.find( '.atomic-color-control__swatch, .atomic-color-control__option, .wp-picker-container button, .wp-picker-input-wrap input' ).first().trigger( 'focus' );
			}
		} );

		// Keep popover focusable for keyboard users.
		if ( $popover.length ) {
			$popover.attr( 'tabindex', '-1' );
		}
	}

	$( function () {
		function closeAll() {
			$( '.atomic-color-control__popover' ).prop( 'hidden', true );
			$( '.atomic-color-control__toggle' ).attr( 'aria-expanded', 'false' );
		}

		// Global close handlers (once).
		$( document ).on( 'keydown', function ( e ) {
			if ( 'Escape' === e.key ) {
				closeAll();
			}
		} );
		$( document ).on( 'mousedown', function ( e ) {
			// Click outside any control closes all.
			if ( $( e.target ).closest( '.atomic-color-control' ).length ) {
				return;
			}
			closeAll();
		} );

		$( '.atomic-color-control' ).each( function () {
			initControl( this );
		} );
	} );
}( jQuery ) );
