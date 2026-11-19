/**
 * The blocks in the block editor: the certificate generator's (each shows what
 * the page shows, drawn by the server, and its defaults are edited in the
 * Inspector) and "Check a certificate". Their markup and settings are in
 * blocks/<name>/block.json and includes/quick-generator.php.
 */
import { InnerBlocks, InspectorControls, MediaUpload, MediaUploadCheck, useBlockProps } from '@wordpress/block-editor';
import { registerBlockType } from '@wordpress/blocks';
import { BaseControl, Button, ColorPalette, PanelBody, TextControl, ToggleControl, __experimentalVStack as VStack } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import ServerSideRender from '@wordpress/server-side-render';

type Attributes = Record< string, any >;
type EditProps = { attributes: Attributes; setAttributes: ( next: Attributes ) => void };
type Setting = { attribute: string; label: string; kind?: 'toggle' | 'color' | 'logo' };

const NAME = 'events-certificates/';
const field = { __next40pxDefaultSize: true, __nextHasNoMarginBottom: true };

const text = ( attribute: string, label: string ): Setting => ( { attribute, label } );
const community = text( 'community', __( 'Community (default)', 'events-certificates' ) );
const title = text( 'title', __( 'Event title (default)', 'events-certificates' ) );
const logo: Setting = { attribute: 'logo', label: __( 'Default logo', 'events-certificates' ), kind: 'logo' };
const venue = text( 'venue', __( 'Venue (default)', 'events-certificates' ) );
const city = text( 'city', __( 'City (default)', 'events-certificates' ) );
const speakers = text( 'speakers', __( 'Speakers (default)', 'events-certificates' ) );
const link = text( 'link', __( 'Event link (default)', 'events-certificates' ) );
const showHours: Setting = { attribute: 'showHours', label: __( 'Show the workload by default', 'events-certificates' ), kind: 'toggle' };
const signName = text( 'signName', __( 'Signer name (default)', 'events-certificates' ) );
const signRole = text( 'signRole', __( 'Signer role (default)', 'events-certificates' ) );
const accent: Setting = { attribute: 'accent', label: __( 'Certificate accent color', 'events-certificates' ), kind: 'color' };

// The settings of each block.
const SETTINGS: Record< string, Setting[] > = {
	'event-info': [ community, title, logo ],
	'event-schedule': [ venue, city ],
	'event-attrs': [ speakers, link, showHours ],
	signature: [ signName, signRole ],
	preview: [ accent ],
	'quick-generator': [ community, title, logo, venue, city, speakers, link, showHours, signName, signRole, accent ],
};

function Control( { setting, props }: { setting: Setting; props: EditProps } ) {
	const { attribute, label, kind } = setting;
	const set = ( value: unknown ) => props.setAttributes( { [ attribute ]: value } );
	if ( 'toggle' === kind ) {
		return <ToggleControl { ...field } label={ label } checked={ !! props.attributes[ attribute ] } onChange={ set } />;
	}
	if ( 'color' === kind ) {
		return (
			<div>
				<p>{ label }</p>
				<ColorPalette value={ props.attributes[ attribute ] } onChange={ ( value ) => set( value || '#3858e9' ) } />
			</div>
		);
	}
	if ( 'logo' === kind ) {
		// Like the editor's featured image: a full-width button, then the picture with its actions.
		const url = props.attributes.logoUrl;
		return (
			<MediaUploadCheck>
				<MediaUpload
					allowedTypes={ [ 'image' ] }
					value={ props.attributes.logoId }
					onSelect={ ( media: { id: number; url: string } ) => props.setAttributes( { logoId: media.id, logoUrl: media.url } ) }
					render={ ( { open }: { open: () => void } ) => (
						<VStack spacing={ 2 }>
							<BaseControl.VisualLabel>{ label }</BaseControl.VisualLabel>
							{ url ? (
								<>
									<Button variant="secondary" className="editor-post-featured-image__preview" onClick={ open } aria-label={ __( 'Replace the logo', 'events-certificates' ) }>
										<img className="editor-post-featured-image__preview-image" src={ url } alt="" style={ { objectFit: 'contain' } } />
									</Button>
									<Button variant="secondary" isDestructive onClick={ () => props.setAttributes( { logoId: 0, logoUrl: '' } ) }>
										{ __( 'Remove the logo', 'events-certificates' ) }
									</Button>
								</>
							) : (
								<Button variant="secondary" className="editor-post-featured-image__toggle" style={ { minHeight: 90, justifyContent: 'center' } } onClick={ open }>
									{ __( 'Choose a logo', 'events-certificates' ) }
								</Button>
							) }
						</VStack>
					) }
				/>
			</MediaUploadCheck>
		);
	}
	return <TextControl { ...field } label={ label } value={ props.attributes[ attribute ] || '' } onChange={ set } />;
}

// A block that shows the server's drawing, with its settings in the Inspector.
function drawn( slug: string ) {
	return function Edit( props: EditProps ) {
		const settings = SETTINGS[ slug ] || [];
		return (
			<>
				<ServerSideRender block={ NAME + slug } attributes={ props.attributes } />
				{ settings.length > 0 && (
					<InspectorControls>
						<PanelBody title={ __( 'Generator settings', 'events-certificates' ) } initialOpen>
							{ settings.map( ( setting ) => (
								<Control key={ setting.attribute } setting={ setting } props={ props } />
							) ) }
						</PanelBody>
					</InspectorControls>
				) }
			</>
		);
	};
}

[ 'event-info', 'event-schedule', 'event-attrs', 'signature', 'people-list', 'preview', 'tutorial', 'quick-generator' ].forEach( ( slug ) => {
	registerBlockType( NAME + slug as any, { edit: drawn( slug ), save: () => null } as any );
} );

// The event panel holds its fields as inner blocks.
registerBlockType( NAME + 'event-fields' as any, {
	edit: () => (
		<div { ...useBlockProps() }>
			<section className="panel">
				<h2>{ __( '1 · Event', 'events-certificates' ) }</h2>
				<InnerBlocks
					allowedBlocks={ [ NAME + 'event-info', NAME + 'event-schedule', NAME + 'event-attrs' ] }
					template={ [ [ NAME + 'event-info' ], [ NAME + 'event-schedule' ], [ NAME + 'event-attrs' ] ] }
					templateLock="insert"
				/>
			</section>
		</div>
	),
	save: () => <InnerBlocks.Content />,
} as any );

// "Check a certificate": the server's page, and its two settings.
registerBlockType( NAME + 'verify' as any, {
	edit: ( { attributes, setAttributes }: EditProps ) => (
		<div { ...useBlockProps() }>
			<InspectorControls>
				<PanelBody title={ __( 'Settings', 'events-certificates' ) }>
					<TextControl
						{ ...field }
						label={ __( 'Title', 'events-certificates' ) }
						placeholder={ __( 'Check a certificate', 'events-certificates' ) }
						value={ attributes.title }
						onChange={ ( value ) => setAttributes( { title: value } ) }
					/>
				</PanelBody>
			</InspectorControls>
			<div { ...( { inert: '' } as object ) }>
				<ServerSideRender block={ NAME + 'verify' } attributes={ attributes } />
			</div>
		</div>
	),
	save: () => null,
} as any );

// "Get my certificate": the server's form.
registerBlockType( NAME + 'mine' as any, {
	edit: ( { attributes, setAttributes }: EditProps ) => (
		<div { ...useBlockProps() }>
			<InspectorControls>
				<PanelBody title={ __( 'Settings', 'events-certificates' ) }>
					<TextControl
						{ ...field }
						label={ __( 'Title', 'events-certificates' ) }
						placeholder={ __( 'Get my certificate', 'events-certificates' ) }
						value={ attributes.title }
						onChange={ ( value ) => setAttributes( { title: value } ) }
					/>
				</PanelBody>
			</InspectorControls>
			<div { ...( { inert: '' } as object ) }>
				<ServerSideRender block={ NAME + 'mine' } attributes={ attributes } />
			</div>
		</div>
	),
	save: () => null,
} as any );
