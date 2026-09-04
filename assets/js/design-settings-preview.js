/* global document, atomicLinkedInFeedDesign */
( function () {
	'use strict';

	function qs( sel, root ) { return ( root || document ).querySelector( sel ); }

	function getValue( sel, fallback ) {
		var el = qs( sel );
		if ( ! el ) { return fallback; }
		return String( typeof el.value === 'undefined' ? fallback : el.value );
	}

	function getChecked( sel, fallback ) {
		var el = qs( sel );
		if ( ! el ) { return !! fallback; }
		return !! el.checked;
	}

	function intOr( v, fallback ) {
		var n = parseInt( String( v ), 10 );
		return isNaN( n ) ? fallback : n;
	}

	function px( n ) { return intOr( n, 0 ) + 'px'; }

	function setVar( root, name, value ) {
		if ( ! root ) { return; }
		if ( value === '' || value === null || typeof value === 'undefined' ) {
			root.style.removeProperty( name );
			return;
		}
		root.style.setProperty( name, String( value ) );
	}

	function toggle( sel, show ) {
		var el = qs( sel );
		if ( el ) { el.style.display = show ? '' : 'none'; }
	}

	function resolveColorForPreview( value ) {
		value = String( value || '' );
		if ( value.indexOf( 'var(--wp--preset--color--' ) !== 0 ) {
			return value;
		}
		var m = value.match( /^var\(--wp--preset--color--([a-z0-9-]+)\)$/ );
		if ( ! m ) { return value; }
		if ( ! atomicLinkedInFeedDesign || ! atomicLinkedInFeedDesign.themePalette ) { return value; }
		return String( atomicLinkedInFeedDesign.themePalette[ m[ 1 ] ] || value );
	}

	function readCurrentDesignForm() {
		return {
			// Layout & spacing (global defaults).
			gap: getValue( '#atomic-layout-gap', 'medium' ) || 'medium',
			minWidth: intOr( getValue( '#atomic-layout-min-width', 340 ), 340 ),
			sepEnabled: getChecked( '#atomic-layout-separator-enable', false ),
			sepThickness: intOr( getValue( '#atomic-layout-separator-thickness', 1 ), 1 ),
			sepSpacing: intOr( getValue( '#atomic-layout-separator-spacing', 40 ), 40 ),
			sepColor: getValue( '#atomic-layout-separator-color', '' ) || '',

			// Card.
			borderStyle: getValue( '#atomic-design-border-style', 'solid' ) || 'solid',
			borderWidth: intOr( getValue( '#atomic-design-border-width', 1 ), 1 ),
			borderColor: getValue( '#atomic-design-border-color', '' ),
			radius: intOr( getValue( '#atomic-design-radius', 12 ), 12 ),
			background: getValue( '#atomic-design-background', '' ),
			shadow: getValue( '#atomic-design-shadow', 'none' ) || 'none',
			hover: getValue( '#atomic-design-hover', 'none' ) || 'none',
			transitionMs: intOr( getValue( '#atomic-design-transition', 200 ), 200 ),

			// Pagination.
			pgBorderStyle: getValue( '#atomic-pg-border-style', 'solid' ) || 'solid',
			pgBorderWidth: intOr( getValue( '#atomic-pg-border-width', 1 ), 1 ),
			pgBorderColor: getValue( '#atomic-pg-border-color', '' ),
			pgFontSize: intOr( getValue( '#atomic-pg-font-size', 16 ), 16 ),
			pgFontWeight: intOr( getValue( '#atomic-pg-font-weight', 600 ), 600 ),
			pgMinWidth: intOr( getValue( '#atomic-pg-min-width', 44 ), 44 ),
			pgPadX: intOr( getValue( '#atomic-pg-padding-x', 14 ), 14 ),
			pgPadY: intOr( getValue( '#atomic-pg-padding-y', 10 ), 10 ),
			pgGap: intOr( getValue( '#atomic-pg-gap', 8 ), 8 ),
			pgRadius: intOr( getValue( '#atomic-pg-radius', 6 ), 6 ),
			pgColor: getValue( '#atomic-pg-color', '' ),
			pgBg: getValue( '#atomic-pg-bg', '' ),
			pgActiveColor: getValue( '#atomic-pg-active-color', '' ),
			pgActiveBg: getValue( '#atomic-pg-active-bg', '' ),
			pgActiveBorder: getValue( '#atomic-pg-active-border', '' ),
			pgHoverColor: getValue( '#atomic-pg-hover-color', '' ),
			pgHoverBg: getValue( '#atomic-pg-hover-bg', '' ),
			pgHoverBorder: getValue( '#atomic-pg-hover-border', '' ),
			pgShadow: getValue( '#atomic-pg-shadow', 'none' ) || 'none',
			pgTransitionMs: intOr( getValue( '#atomic-pg-transition', 200 ), 200 )
		};
	}

	function applyPreviewState( state ) {
		var preview = qs( '#atomic-linkedin-design-preview' );
		if ( ! preview ) { return; }

		// Layout helpers.
		setVar( preview, '--atomic-linkedin-min-width', px( state.minWidth ) );
		var gapMap = { small: '1rem', medium: '1.5rem', large: '2rem' };
		setVar( preview, '--atomic-social-gap', gapMap[ state.gap ] || '1.5rem' );

		toggle( '#atomic-layout-separator-thickness-field', !! state.sepEnabled );
		toggle( '#atomic-layout-separator-color-field', !! state.sepEnabled );
		toggle( '#atomic-layout-separator-spacing-field', !! state.sepEnabled );
		setVar( preview, '--atomic-linkedin-separator-thickness', px( state.sepThickness ) );
		setVar( preview, '--atomic-linkedin-separator-spacing', px( state.sepSpacing ) );
		setVar( preview, '--atomic-linkedin-separator-color', resolveColorForPreview( state.sepColor || '#dcdcde' ) );

		var stackedDemo = qs( '[data-preview="stacked-demo"]', preview );
		if ( stackedDemo ) {
			stackedDemo.classList.toggle( 'is-separator-off', ! state.sepEnabled );
		}

		// Card.
		toggle( '#atomic-design-border-width-field', state.borderStyle !== 'none' );
		toggle( '#atomic-design-border-color-field', state.borderStyle !== 'none' );
		setVar( preview, '--atomic-social-card-border-style', state.borderStyle );
		setVar( preview, '--atomic-social-card-border-width', state.borderStyle === 'none' ? '0px' : px( state.borderWidth ) );
		setVar( preview, '--atomic-social-card-border-color', resolveColorForPreview( state.borderColor ) );
		setVar( preview, '--atomic-social-card-radius', px( state.radius ) );
		setVar( preview, '--atomic-social-card-background', resolveColorForPreview( state.background ) );
		setVar( preview, '--atomic-social-card-shadow', state.shadow === 'subtle' ? '0 6px 18px rgba(0,0,0,0.08)' : 'none' );
		setVar( preview, '--atomic-social-card-hover-translate', state.hover === 'lift' ? '-2px' : '0px' );
		setVar( preview, '--atomic-social-card-hover-scale', state.hover === 'scale' ? '1.015' : '1' );
		setVar( preview, '--atomic-social-transition', state.transitionMs + 'ms' );

		// Pagination.
		toggle( '#atomic-pg-border-width-field', state.pgBorderStyle !== 'none' );
		toggle( '#atomic-pg-border-color-field', state.pgBorderStyle !== 'none' );
		setVar( preview, '--atomic-linkedin-pagination-font-size', px( state.pgFontSize ) );
		setVar( preview, '--atomic-linkedin-pagination-font-weight', state.pgFontWeight );
		setVar( preview, '--atomic-linkedin-pagination-min-width', px( state.pgMinWidth ) );
		setVar( preview, '--atomic-linkedin-pagination-padding-x', px( state.pgPadX ) );
		setVar( preview, '--atomic-linkedin-pagination-padding-y', px( state.pgPadY ) );
		setVar( preview, '--atomic-linkedin-pagination-gap', px( state.pgGap ) );
		setVar( preview, '--atomic-linkedin-pagination-radius', px( state.pgRadius ) );

		setVar( preview, '--atomic-linkedin-pagination-border-style', state.pgBorderStyle );
		setVar( preview, '--atomic-linkedin-pagination-border-width', state.pgBorderStyle === 'none' ? '0px' : px( state.pgBorderWidth ) );
		setVar( preview, '--atomic-linkedin-pagination-border-color', resolveColorForPreview( state.pgBorderColor ) );

		setVar( preview, '--atomic-linkedin-pagination-color', resolveColorForPreview( state.pgColor ) );
		setVar( preview, '--atomic-linkedin-pagination-background', resolveColorForPreview( state.pgBg ) );
		setVar( preview, '--atomic-linkedin-pagination-active-color', resolveColorForPreview( state.pgActiveColor ) );
		setVar( preview, '--atomic-linkedin-pagination-active-background', resolveColorForPreview( state.pgActiveBg ) );
		setVar( preview, '--atomic-linkedin-pagination-active-border-color', resolveColorForPreview( state.pgActiveBorder ) );
		setVar( preview, '--atomic-linkedin-pagination-hover-color', resolveColorForPreview( state.pgHoverColor ) );
		setVar( preview, '--atomic-linkedin-pagination-hover-background', resolveColorForPreview( state.pgHoverBg ) );
		setVar( preview, '--atomic-linkedin-pagination-hover-border-color', resolveColorForPreview( state.pgHoverBorder ) );
		setVar( preview, '--atomic-linkedin-pagination-shadow', state.pgShadow === 'subtle' ? '0 2px 10px rgba(0,0,0,0.08)' : 'none' );
		setVar( preview, '--atomic-linkedin-pagination-transition', state.pgTransitionMs + 'ms' );
	}

	function updatePreview() {
		applyPreviewState( readCurrentDesignForm() );
	}

	function init() {
		var form = qs( '#atomic-linkedin-design-form' );
		if ( ! form ) { return; }
		form.addEventListener( 'input', updatePreview );
		form.addEventListener( 'change', updatePreview );
		updatePreview();
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
} )();
