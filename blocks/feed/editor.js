( function ( blocks, blockEditor, components, element, serverSideRender, i18n ) {
	'use strict';
	var el = element.createElement;
	var InspectorControls = blockEditor.InspectorControls;
	var PanelColorSettings = blockEditor.PanelColorSettings;
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

			var layoutOptions = [
				{ label: __( 'Grid', 'atomic-wp-social-sync' ), value: 'grid' },
				{ label: __( 'Carousel', 'atomic-wp-social-sync' ), value: 'carousel' },
				{ label: __( 'Stacked', 'atomic-wp-social-sync' ), value: 'stacked' }
			];

			var stackedWidthOptions = [
				{ label: __( 'Native embed width', 'atomic-wp-social-sync' ), value: 'native' },
				{ label: __( 'Full container width', 'atomic-wp-social-sync' ), value: 'full' }
			];

			var borderStyleOptions = [
				{ label: __( 'Use global', 'atomic-wp-social-sync' ), value: 'global' },
				{ label: __( 'None', 'atomic-wp-social-sync' ), value: 'none' },
				{ label: __( 'Solid', 'atomic-wp-social-sync' ), value: 'solid' }
			];

			return el( element.Fragment, {},
				el( InspectorControls, {},
					el( PanelBody, { title: __( 'Feed', 'atomic-wp-social-sync' ), initialOpen: true },
						el( RangeControl, { label: __( 'Posts per page', 'atomic-wp-social-sync' ), min: 1, max: 100, value: a.postsPerPage, onChange: function ( v ) { set( { postsPerPage: v } ); } } ),
						el( SelectControl, { label: __( 'Presentation', 'atomic-wp-social-sync' ), value: a.presentation, options: presentationOptions, onChange: function ( v ) { set( { presentation: v } ); } } ),
						el( SelectControl, { label: __( 'Pagination', 'atomic-wp-social-sync' ), value: a.pagination, options: paginationOptions, onChange: function ( v ) { set( { pagination: v } ); } } )
					),

					el( PanelBody, { title: __( 'Layout', 'atomic-wp-social-sync' ), initialOpen: false },
						el( SelectControl, { label: __( 'Layout', 'atomic-wp-social-sync' ), value: a.layout || 'grid', options: layoutOptions, onChange: function ( v ) { set( { layout: v } ); } } ),

						( ! a.layout || a.layout === 'grid' ) ? el( RangeControl, { label: __( 'Minimum post width (px)', 'atomic-wp-social-sync' ), min: 280, max: 600, value: a.minWidth || 340, onChange: function ( v ) { set( { minWidth: v } ); } } ) : null,

						a.layout === 'carousel' ? el( RangeControl, { label: __( 'Minimum slide width (px)', 'atomic-wp-social-sync' ), min: 280, max: 600, value: a.minWidth || 340, onChange: function ( v ) { set( { minWidth: v } ); } } ) : null,

						a.layout === 'stacked' ? el( SelectControl, { label: __( 'Post width', 'atomic-wp-social-sync' ), value: a.stackedWidth || 'native', options: stackedWidthOptions, onChange: function ( v ) { set( { stackedWidth: v } ); } } ) : null,
						a.layout === 'stacked' ? el( ToggleControl, { label: __( 'Show separator', 'atomic-wp-social-sync' ), checked: !! a.showSeparator, onChange: function ( v ) { set( { showSeparator: v } ); } } ) : null,

						( a.layout === 'stacked' && a.showSeparator ) ? el( RangeControl, { label: __( 'Separator thickness (px)', 'atomic-wp-social-sync' ), min: 1, max: 10, value: a.separatorThickness || 1, onChange: function ( v ) { set( { separatorThickness: v } ); } } ) : null,
						( a.layout === 'stacked' && a.showSeparator ) ? el( RangeControl, { label: __( 'Separator spacing (px)', 'atomic-wp-social-sync' ), min: 0, max: 120, value: a.separatorSpacing || 40, onChange: function ( v ) { set( { separatorSpacing: v } ); } } ) : null,
						( a.layout === 'stacked' && a.showSeparator ) ? el( PanelColorSettings, {
							title: __( 'Separator color', 'atomic-wp-social-sync' ),
							colorSettings: [ {
								value: a.separatorColor || '#dcdcde',
								onChange: function ( v ) { set( { separatorColor: v } ); },
								label: __( 'Separator color', 'atomic-wp-social-sync' )
							} ]
						} ) : null
					),

					el( PanelBody, { title: __( 'CTA', 'atomic-wp-social-sync' ), initialOpen: false },
						el( ToggleControl, { label: __( 'CTA enabled', 'atomic-wp-social-sync' ), checked: a.showFullNewsCta, onChange: function ( v ) { set( { showFullNewsCta: v } ); } } ),
						a.showFullNewsCta ? el( TextControl, { label: __( 'CTA label', 'atomic-wp-social-sync' ), value: a.fullNewsCtaLabel, onChange: function ( v ) { set( { fullNewsCtaLabel: v } ); } } ) : null,
						a.showFullNewsCta ? el( SelectControl, { label: __( 'CTA target page', 'atomic-wp-social-sync' ), value: a.newsPageId || 0, options: pageOptions, onChange: function ( v ) { set( { newsPageId: parseInt( v || 0, 10 ) || 0 } ); } } ) : null,
						a.showFullNewsCta && ! a.newsPageId ? el( Notice, { status: 'warning', isDismissible: false }, __( 'Select a target page for the CTA.', 'atomic-wp-social-sync' ) ) : null
					),

					el( PanelBody, { title: __( 'Appearance', 'atomic-wp-social-sync' ), initialOpen: false },
						el( ToggleControl, { label: __( 'Override block styles', 'atomic-wp-social-sync' ), checked: !! a.overrideStyles, onChange: function ( v ) { set( { overrideStyles: v } ); } } ),
						a.overrideStyles ? el( SelectControl, { label: __( 'Border style', 'atomic-wp-social-sync' ), value: a.borderStyle || 'global', options: borderStyleOptions, onChange: function ( v ) { set( { borderStyle: v } ); } } ) : null,
						( a.overrideStyles && a.borderStyle === 'solid' ) ? el( RangeControl, { label: __( 'Border width (px)', 'atomic-wp-social-sync' ), min: 0, max: 12, value: a.borderWidth || 1, onChange: function ( v ) { set( { borderWidth: v } ); } } ) : null,
						( a.overrideStyles && a.borderStyle === 'solid' ) ? el( PanelColorSettings, {
							title: __( 'Border color', 'atomic-wp-social-sync' ),
							colorSettings: [ {
								value: a.borderColor || '',
								onChange: function ( v ) { set( { borderColor: v || '' } ); },
								label: __( 'Border color', 'atomic-wp-social-sync' )
							} ]
						} ) : null
					)
				),
				a.cardLink === 'local' && ! editorData.singlePagesEnabled ? el( Notice, { status: 'warning', isDismissible: false }, __( 'Local post links require Individual Social Post Pages to be enabled in Settings.', 'atomic-wp-social-sync' ) ) : null,
				el( serverSideRender, { block: 'atomic-wp-social-sync/feed', attributes: a } )
			);
		},
		save: function () { return null; }
	} );
} )( window.wp.blocks, window.wp.blockEditor, window.wp.components, window.wp.element, window.wp.serverSideRender, window.wp.i18n );
