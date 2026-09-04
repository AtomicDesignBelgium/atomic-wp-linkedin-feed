( function ( blocks, blockEditor, components, element, serverSideRender, i18n ) {
	'use strict';
	var el = element.createElement;
	var InspectorControls = blockEditor.InspectorControls;
	var PanelBody = components.PanelBody;
	var RangeControl = components.RangeControl;
	var ToggleControl = components.ToggleControl;
	var SelectControl = components.SelectControl;
	var TextControl = components.TextControl;
	var Notice = components.Notice;
	var __ = i18n.__;

	blocks.registerBlockType( 'atomic-wp-social-sync/feed', {
		edit: function ( props ) {
			var a = props.attributes;
			var set = props.setAttributes;
			var editorData = window.atomicSocialEditor || { pages: [], newsPageId: 0, singlePagesEnabled: false };

			// V1 default: LinkedIn-only feed. Keep attributes for backward compatibility.
			if ( ! a.providers || ! a.providers.length ) {
				set( { providers: [ 'linkedin' ] } );
			}

			var presentationOptions = [
				{ label: __( 'Compact', 'atomic-wp-social-sync' ), value: 'compact' },
				{ label: __( 'Full', 'atomic-wp-social-sync' ), value: 'full' }
			];
			if ( a.presentation === 'auto' ) {
				presentationOptions.unshift( { label: __( 'Auto (legacy)', 'atomic-wp-social-sync' ), value: 'auto' } );
			}

			var paginationOptions = [
				{ label: __( 'None', 'atomic-wp-social-sync' ), value: 'none' },
				{ label: __( 'Numbered', 'atomic-wp-social-sync' ), value: 'numbers' }
			];
			if ( a.pagination === 'load_more' ) {
				paginationOptions.push( { label: __( 'Load More (legacy)', 'atomic-wp-social-sync' ), value: 'load_more' } );
			}

			var pageOptions = [ { label: __( 'Select a page', 'atomic-wp-social-sync' ), value: 0 } ].concat(
				( editorData.pages || [] ).map( function ( p ) { return { label: p.title, value: p.id }; } )
			);

			return el( element.Fragment, {},
				el( InspectorControls, {},
					el( PanelBody, { title: __( 'Feed', 'atomic-wp-social-sync' ), initialOpen: true },
						el( RangeControl, { label: __( 'Posts per page', 'atomic-wp-social-sync' ), min: 1, max: 100, value: a.postsPerPage, onChange: function ( v ) { set( { postsPerPage: v } ); } } ),
						el( SelectControl, { label: __( 'Presentation', 'atomic-wp-social-sync' ), value: a.presentation, options: presentationOptions, onChange: function ( v ) { set( { presentation: v } ); } } ),
						el( SelectControl, { label: __( 'Pagination', 'atomic-wp-social-sync' ), value: a.pagination, options: paginationOptions, onChange: function ( v ) { set( { pagination: v } ); } } ),
						el( ToggleControl, { label: __( 'CTA enabled', 'atomic-wp-social-sync' ), checked: a.showFullNewsCta, onChange: function ( v ) { set( { showFullNewsCta: v } ); } } ),
						a.showFullNewsCta ? el( TextControl, { label: __( 'CTA label', 'atomic-wp-social-sync' ), value: a.fullNewsCtaLabel, onChange: function ( v ) { set( { fullNewsCtaLabel: v } ); } } ) : null,
						a.showFullNewsCta ? el( SelectControl, { label: __( 'CTA target page', 'atomic-wp-social-sync' ), value: a.newsPageId || 0, options: pageOptions, onChange: function ( v ) { set( { newsPageId: parseInt( v || 0, 10 ) || 0 } ); } } ) : null,
						a.showFullNewsCta && ! a.newsPageId ? el( Notice, { status: 'warning', isDismissible: false }, __( 'Select a CTA target page to enable “View full news” links.', 'atomic-wp-social-sync' ) ) : null
					)
				),
				a.cardLink === 'local' && ! editorData.singlePagesEnabled ? el( Notice, { status: 'warning', isDismissible: false }, __( 'Local post links require Individual Social Post Pages to be enabled in Settings.', 'atomic-wp-social-sync' ) ) : null,
				el( serverSideRender, { block: 'atomic-wp-social-sync/feed', attributes: a } )
			);
		},
		save: function () { return null; }
	} );
} )( window.wp.blocks, window.wp.blockEditor, window.wp.components, window.wp.element, window.wp.serverSideRender, window.wp.i18n );
