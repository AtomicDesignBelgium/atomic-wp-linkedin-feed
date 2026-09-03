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
	var CheckboxControl = components.CheckboxControl;
	var __ = i18n.__;

	blocks.registerBlockType( 'atomic-wp-social-sync/feed', {
		edit: function ( props ) {
			var a = props.attributes;
			var set = props.setAttributes;
			var editorData = window.atomicSocialEditor || { providers: [], connections: [], singlePagesEnabled: false };
			function toggle( values, value, checked ) {
				values = values || [];
				return checked ? values.concat( values.indexOf( value ) === -1 ? [ value ] : [] ) : values.filter( function ( item ) { return item !== value; } );
			}
			return el( element.Fragment, {},
				el( InspectorControls, {},
					el( PanelBody, { title: __( 'Content', 'atomic-wp-social-sync' ), initialOpen: true },
						el( RangeControl, { label: __( 'Posts per page', 'atomic-wp-social-sync' ), min: 1, max: 100, value: a.postsPerPage, onChange: function ( v ) { set( { postsPerPage: v } ); } } ),
						el( SelectControl, { label: __( 'Order', 'atomic-wp-social-sync' ), value: a.order, options: [ { label: __( 'Newest', 'atomic-wp-social-sync' ), value: 'newest' }, { label: __( 'Oldest', 'atomic-wp-social-sync' ), value: 'oldest' } ], onChange: function ( v ) { set( { order: v } ); } } ),
						el( ToggleControl, { label: __( 'Pinned first', 'atomic-wp-social-sync' ), checked: a.pinnedFirst, onChange: function ( v ) { set( { pinnedFirst: v } ); } } ),
						el( ToggleControl, { label: __( 'Only homepage-enabled', 'atomic-wp-social-sync' ), checked: a.homepageOnly, onChange: function ( v ) { set( { homepageOnly: v } ); } } ),
						el( 'p', {}, __( 'Sources (none selected means all)', 'atomic-wp-social-sync' ) ),
						editorData.providers.map( function ( provider ) { return el( CheckboxControl, { key: provider.slug, label: provider.label, checked: ( a.providers || [] ).indexOf( provider.slug ) !== -1, onChange: function ( checked ) { set( { providers: toggle( a.providers, provider.slug, checked ) } ); } } ); } ),
						el( 'p', {}, __( 'Connections (none selected means all)', 'atomic-wp-social-sync' ) ),
						editorData.connections.map( function ( connection ) { return el( CheckboxControl, { key: connection.id, label: connection.label + ' (' + connection.provider + ')', checked: ( a.connections || [] ).indexOf( connection.id ) !== -1, onChange: function ( checked ) { set( { connections: toggle( a.connections, connection.id, checked ) } ); } } ); } )
					),
					el( PanelBody, { title: __( 'Layout', 'atomic-wp-social-sync' ), initialOpen: false },
						el( RangeControl, { label: __( 'Desktop columns', 'atomic-wp-social-sync' ), min: 1, max: 6, value: a.desktopColumns, onChange: function ( v ) { set( { desktopColumns: v } ); } } ),
						el( RangeControl, { label: __( 'Tablet columns', 'atomic-wp-social-sync' ), min: 1, max: 4, value: a.tabletColumns, onChange: function ( v ) { set( { tabletColumns: v } ); } } ),
						el( RangeControl, { label: __( 'Mobile columns', 'atomic-wp-social-sync' ), min: 1, max: 2, value: a.mobileColumns, onChange: function ( v ) { set( { mobileColumns: v } ); } } ),
						el( SelectControl, { label: __( 'Gap', 'atomic-wp-social-sync' ), value: a.gap, options: [ { label: __( 'Small', 'atomic-wp-social-sync' ), value: 'small' }, { label: __( 'Medium', 'atomic-wp-social-sync' ), value: 'medium' }, { label: __( 'Large', 'atomic-wp-social-sync' ), value: 'large' } ], onChange: function ( v ) { set( { gap: v } ); } } )
					),
					el( PanelBody, { title: __( 'Display', 'atomic-wp-social-sync' ), initialOpen: false },
						el( ToggleControl, { label: __( 'Show image', 'atomic-wp-social-sync' ), checked: a.showImage, onChange: function ( v ) { set( { showImage: v } ); } } ),
						el( SelectControl, { label: __( 'Image ratio', 'atomic-wp-social-sync' ), value: a.imageRatio, options: [ { label: __( 'Auto', 'atomic-wp-social-sync' ), value: 'auto' }, { label: '1:1', value: '1-1' }, { label: '4:3', value: '4-3' }, { label: '3:2', value: '3-2' }, { label: '16:9', value: '16-9' } ], onChange: function ( v ) { set( { imageRatio: v } ); } } ),
						el( ToggleControl, { label: __( 'Show date', 'atomic-wp-social-sync' ), checked: a.showDate, onChange: function ( v ) { set( { showDate: v } ); } } ),
						el( ToggleControl, { label: __( 'Show excerpt', 'atomic-wp-social-sync' ), checked: a.showExcerpt, onChange: function ( v ) { set( { showExcerpt: v } ); } } ),
						el( RangeControl, { label: __( 'Excerpt words', 'atomic-wp-social-sync' ), min: 1, max: 200, value: a.excerptLength, onChange: function ( v ) { set( { excerptLength: v } ); } } ),
						el( ToggleControl, { label: __( 'Show source', 'atomic-wp-social-sync' ), checked: a.showSource, onChange: function ( v ) { set( { showSource: v } ); } } ),
						el( ToggleControl, { label: __( 'Show CTA', 'atomic-wp-social-sync' ), checked: a.showCta, onChange: function ( v ) { set( { showCta: v } ); } } ),
						el( TextControl, { label: __( 'CTA label', 'atomic-wp-social-sync' ), value: a.ctaLabel, onChange: function ( v ) { set( { ctaLabel: v } ); } } ),
						el( SelectControl, { label: __( 'Card link', 'atomic-wp-social-sync' ), value: a.cardLink, options: [ { label: __( 'Original source', 'atomic-wp-social-sync' ), value: 'original' }, { label: __( 'Local WordPress post', 'atomic-wp-social-sync' ), value: 'local' }, { label: __( 'None', 'atomic-wp-social-sync' ), value: 'none' } ], onChange: function ( v ) { set( { cardLink: v } ); } } ),
						el( SelectControl, { label: __( 'Pagination', 'atomic-wp-social-sync' ), value: a.pagination, options: [ { label: __( 'None', 'atomic-wp-social-sync' ), value: 'none' }, { label: __( 'Numbers', 'atomic-wp-social-sync' ), value: 'numbers' }, { label: __( 'Load More', 'atomic-wp-social-sync' ), value: 'load_more' } ], onChange: function ( v ) { set( { pagination: v } ); } } )
					)
				),
				a.cardLink === 'local' && ! editorData.singlePagesEnabled ? el( Notice, { status: 'warning', isDismissible: false }, __( 'Local post links require Individual Social Post Pages to be enabled in Settings.', 'atomic-wp-social-sync' ) ) : null,
				el( serverSideRender, { block: 'atomic-wp-social-sync/feed', attributes: a } )
			);
		},
		save: function () { return null; }
	} );
} )( window.wp.blocks, window.wp.blockEditor, window.wp.components, window.wp.element, window.wp.serverSideRender, window.wp.i18n );
