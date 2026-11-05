/**
 * The core Image block's ways to add an image, as components: the placeholder
 * (Upload, Media Library, Insert from URL) and the toolbar's Replace menu.
 */
import { Button, Dropdown, MenuGroup, MenuItem, NavigableMenu, Placeholder, ToolbarButton, __experimentalHStack as HStack, TextControl } from '@wordpress/components';
import { useRef, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { image, keyboardReturn, media, upload as uploadIcon } from '@wordpress/icons';
import { IMAGE_TYPES, isImageUrl, openMediaLibrary, upload, type Picked } from './media';

type SourceProps = { attachment?: number; onPick: ( picked: Picked ) => void; onError?: ( message: string ) => void };

function useUpload( onPick: SourceProps[ 'onPick' ], onError?: SourceProps[ 'onError' ] ) {
	const input = useRef< HTMLInputElement >( null );
	const [ busy, setBusy ] = useState( false );
	const field = (
		<input
			ref={ input }
			type="file"
			accept={ IMAGE_TYPES }
			hidden
			onChange={ async ( event ) => {
				const file = event.target.files?.[ 0 ];
				event.target.value = '';
				if ( ! file ) {
					return;
				}
				setBusy( true );
				try {
					onPick( await upload( file ) );
				} catch ( e: any ) {
					onError?.( e?.message || __( 'The upload failed.', 'events-certificate' ) );
				}
				setBusy( false );
			} }
		/>
	);
	return { field, busy, open: () => input.current?.click() };
}

/** An URL field with an Apply button, like core's URLPopover. */
function UrlForm( { value = '', onSubmit }: { value?: string; onSubmit: ( url: string ) => void } ) {
	const [ url, setUrl ] = useState( value );
	return (
		<form
			className="evcert-url-form"
			onSubmit={ ( event ) => {
				event.preventDefault();
				if ( isImageUrl( url ) ) {
					onSubmit( url );
				}
			} }
		>
			<HStack alignment="bottom">
				<TextControl __next40pxDefaultSize __nextHasNoMarginBottom type="url" label={ __( 'URL', 'events-certificate' ) } hideLabelFromVision placeholder={ __( 'Paste or type URL', 'events-certificate' ) } value={ url } onChange={ setUrl } />
				<Button __next40pxDefaultSize icon={ keyboardReturn } label={ __( 'Apply', 'events-certificate' ) } type="submit" disabled={ ! isImageUrl( url ) } accessibleWhenDisabled />
			</HStack>
		</form>
	);
}

/** The empty image, like the core Image block's placeholder. */
export function ImagePlaceholder( { attachment, onPick, onError }: SourceProps ) {
	const { field, busy, open } = useUpload( onPick, onError );
	return (
		<Placeholder
			icon={ image }
			label={ __( 'Image', 'events-certificate' ) }
			instructions={ __( 'Upload a PNG, JPEG or GIF, pick one from your media library, or add one with a URL.', 'events-certificate' ) }
			className="evcert-image-placeholder"
		>
			{ field }
			<Button __next40pxDefaultSize variant="primary" isBusy={ busy } disabled={ busy } onClick={ open }>
				{ __( 'Upload', 'events-certificate' ) }
			</Button>
			<Button __next40pxDefaultSize variant="secondary" onClick={ () => openMediaLibrary( onPick, attachment ) }>
				{ __( 'Media Library', 'events-certificate' ) }
			</Button>
			<Dropdown
				popoverProps={ { placement: 'bottom-start' } }
				renderToggle={ ( { isOpen, onToggle } ) => (
					<Button __next40pxDefaultSize variant="tertiary" aria-expanded={ isOpen } onClick={ onToggle }>
						{ __( 'Insert from URL', 'events-certificate' ) }
					</Button>
				) }
				renderContent={ ( { onClose } ) => (
					<div className="evcert-url-popover">
						<UrlForm onSubmit={ ( src ) => { onPick( { src } ); onClose(); } } />
					</div>
				) }
			/>
		</Placeholder>
	);
}

/** The toolbar's Replace menu, like core's MediaReplaceFlow. */
export function ReplaceImage( { src, attachment, onPick, onError, toggleProps }: SourceProps & { src: string; toggleProps?: Record< string, unknown > } ) {
	const { field, open } = useUpload( onPick, onError );
	return (
		<>
			{ field }
			<Dropdown
				popoverProps={ { placement: 'bottom-start' } }
				contentClassName="evcert-replace-popover"
				renderToggle={ ( { isOpen, onToggle } ) => (
					<ToolbarButton { ...toggleProps } aria-expanded={ isOpen } aria-haspopup="true" onClick={ onToggle }>
						{ src ? __( 'Replace', 'events-certificate' ) : __( 'Add image', 'events-certificate' ) }
					</ToolbarButton>
				) }
				renderContent={ ( { onClose } ) => (
					<>
						<NavigableMenu>
							<MenuGroup>
								<MenuItem icon={ media } onClick={ () => { openMediaLibrary( onPick, attachment ); onClose(); } }>
									{ __( 'Open Media Library', 'events-certificate' ) }
								</MenuItem>
								<MenuItem icon={ uploadIcon } onClick={ () => { open(); onClose(); } }>
									{ __( 'Upload', 'events-certificate' ) }
								</MenuItem>
							</MenuGroup>
						</NavigableMenu>
						<div className="evcert-replace-popover__url">
							<p className="evcert-replace-popover__label">{ __( 'Current media URL:', 'events-certificate' ) }</p>
							<UrlForm value={ src.startsWith( 'data:' ) ? '' : src } onSubmit={ ( url ) => { onPick( { src: url } ); onClose(); } } />
						</div>
					</>
				) }
			/>
		</>
	);
}
