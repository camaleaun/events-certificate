/**
 * Editor side of the "Check a certificate" block: the server's render as the
 * preview (the same HTML the page shows), and its two settings. Plain script
 * on the WordPress globals, no build step.
 */
( function ( wp ) {
	var el = wp.element.createElement;
	var __ = wp.i18n.__;
	var ServerSideRender = wp.serverSideRender;
	var InspectorControls = wp.blockEditor.InspectorControls;
	var useBlockProps = wp.blockEditor.useBlockProps;
	var PanelBody = wp.components.PanelBody;
	var TextControl = wp.components.TextControl;
	var ToggleControl = wp.components.ToggleControl;

	wp.blocks.registerBlockType( 'events-certificate/verify', {
		edit: function ( props ) {
			var attributes = props.attributes;
			var setAttributes = props.setAttributes;
			return el(
				'div',
				useBlockProps(),
				el(
					InspectorControls,
					null,
					el(
						PanelBody,
						{ title: __( 'Settings', 'events-certificate' ) },
						el( TextControl, {
							__next40pxDefaultSize: true,
							__nextHasNoMarginBottom: true,
							label: __( 'Title', 'events-certificate' ),
							placeholder: __( 'Check a certificate', 'events-certificate' ),
							value: attributes.title,
							onChange: function ( title ) {
								setAttributes( { title: title } );
							},
						} ),
						el( ToggleControl, {
							__nextHasNoMarginBottom: true,
							label: __( 'Show help', 'events-certificate' ),
							checked: attributes.showHelp,
							onChange: function ( showHelp ) {
								setAttributes( { showHelp: showHelp } );
							},
						} )
					)
				),
				el(
					'div',
					{ inert: '' },
					el( ServerSideRender, { block: 'events-certificate/verify', attributes: attributes } )
				)
			);
		},
		save: function () {
			return null;
		},
	} );
} )( window.wp );
