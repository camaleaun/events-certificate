/**
 * Image sources, like the core Image block's placeholder and Replace menu
 * (block-editor/src/components/media-placeholder, media-replace-flow): an
 * upload to the Media Library, a pick from it, or a URL.
 */
import apiFetch from '@wordpress/api-fetch';
import { __ } from '@wordpress/i18n';

export type Picked = { src: string; attachment?: number; alt?: string; width?: number; height?: number };

export const IMAGE_TYPES = 'image/png,image/jpeg,image/gif';

declare global {
	interface Window {
		wp: any;
	}
}

/** Opens the Media Library (wp.media, loaded with wp_enqueue_media()) to pick one image. */
export function openMediaLibrary( onSelect: ( picked: Picked ) => void, current?: number ) {
	if ( ! window.wp?.media ) {
		return;
	}
	const frame = window.wp.media( {
		title: __( 'Select or upload an image', 'events-certificate' ),
		library: { type: [ 'image/png', 'image/jpeg', 'image/gif' ] },
		button: { text: __( 'Select', 'events-certificate' ) },
		multiple: false,
	} );
	if ( current ) {
		frame.on( 'open', () => {
			const attachment = window.wp.media.attachment( current );
			attachment.fetch();
			frame.state().get( 'selection' ).add( attachment );
		} );
	}
	frame.on( 'select', () => {
		const a = frame.state().get( 'selection' ).first().toJSON();
		onSelect( { src: a.url, attachment: a.id, alt: a.alt, width: a.width, height: a.height } );
	} );
	frame.open();
}

/** Uploads a file to the Media Library. */
export async function upload( file: File ): Promise< Picked > {
	const body = new window.FormData();
	body.append( 'file', file, file.name );
	const media = await apiFetch< { id: number; source_url: string; alt_text: string; media_details?: { width?: number; height?: number } } >( { path: '/wp/v2/media', method: 'POST', body } );
	return { src: media.source_url, attachment: media.id, alt: media.alt_text, width: media.media_details?.width, height: media.media_details?.height };
}

/** Accepts http(s) URLs only. */
export function isImageUrl( url: string ): boolean {
	try {
		return [ 'http:', 'https:' ].includes( new URL( url ).protocol );
	} catch ( e ) {
		return false;
	}
}

/** The natural size of an image, to keep its aspect ratio. */
export function naturalSize( src: string ): Promise< { width: number; height: number } > {
	return new Promise( ( resolve, reject ) => {
		const img = new window.Image();
		img.onload = () => resolve( { width: img.naturalWidth, height: img.naturalHeight } );
		img.onerror = reject;
		img.src = src;
	} );
}
