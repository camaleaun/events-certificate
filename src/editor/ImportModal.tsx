/**
 * Import a design: a modal that says what happens (the whole design is
 * replaced), lets you pick or drop a file, shows what it contains, and only
 * replaces the design after Replace design.
 */
import { Button, DropZone, FormFileUpload, Modal, Notice } from '@wordpress/components';
import { useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';
import { upload } from '@wordpress/icons';
import type { Design } from './model';

export type Imported = { design: Partial< Design > & { elements: Design[ 'elements' ]; page: Design[ 'page' ] }; title?: string };

/** Reads a design file; throws when it is not one. */
export async function readDesign( file: File ): Promise< Imported > {
	const parsed = JSON.parse( await file.text() );
	if ( ! parsed || ( parsed.version !== undefined && parsed.version !== 1 ) || ! Array.isArray( parsed.elements ) || ! parsed.page ) {
		throw new Error( 'format' );
	}
	const { $schema, title, ...design } = parsed;
	return { design, title: typeof title === 'string' && title ? title : undefined };
}

export default function ImportModal( { onImport, onClose }: { onImport: ( imported: Imported ) => void; onClose: () => void } ) {
	const [ file, setFile ] = useState< File | null >( null );
	const [ imported, setImported ] = useState< Imported | null >( null );
	const [ error, setError ] = useState( '' );

	const choose = async ( picked?: File ) => {
		if ( ! picked ) {
			return;
		}
		setFile( picked );
		setImported( null );
		setError( '' );
		try {
			setImported( await readDesign( picked ) );
		} catch ( e ) {
			setError( __( 'This file is not a certificate design.', 'events-certificate' ) );
		}
	};

	const page = imported?.design.page;
	const count = imported?.design.elements.length || 0;

	return (
		<Modal title={ __( 'Import design', 'events-certificate' ) } onRequestClose={ onClose } className="evcert-import-modal">
			<p>{ __( 'Choose a design file (.json), like one exported from this editor. It replaces the whole current design: elements, colors and page. Nothing is saved until you click Save.', 'events-certificate' ) }</p>
			<div className="evcert-import-modal__drop">
				<DropZone onFilesDrop={ ( files ) => choose( files[ 0 ] ) } label={ __( 'Drop the design file here', 'events-certificate' ) } />
				<FormFileUpload accept="application/json,.json" onChange={ ( event ) => { choose( event.currentTarget.files?.[ 0 ] ); event.currentTarget.value = ''; } } render={ ( { openFileDialog } ) => (
					<Button variant="secondary" __next40pxDefaultSize icon={ upload } onClick={ openFileDialog }>
						{ file ? __( 'Choose another file', 'events-certificate' ) : __( 'Choose file', 'events-certificate' ) }
					</Button>
				) } />
				<span className="evcert-meta">{ file ? file.name : __( 'or drop it here', 'events-certificate' ) }</span>
			</div>
			{ error && (
				<Notice status="error" isDismissible={ false }>
					{ error }
				</Notice>
			) }
			{ imported && page && (
				<Notice status="warning" isDismissible={ false }>
					{ sprintf(
						/* translators: 1: number of elements, 2: page width, 3: page height (points). */
						_n( '%1$s element, page %2$s × %3$s pt. The current design will be replaced.', '%1$s elements, page %2$s × %3$s pt. The current design will be replaced.', count, 'events-certificate' ),
						String( count ),
						String( page.w ),
						String( page.h )
					) }
				</Notice>
			) }
			<div className="evcert-actions evcert-import-modal__footer">
				<Button variant="tertiary" __next40pxDefaultSize onClick={ onClose }>
					{ __( 'Cancel', 'events-certificate' ) }
				</Button>
				<Button variant="primary" __next40pxDefaultSize isDestructive disabled={ ! imported } accessibleWhenDisabled onClick={ () => { if ( imported ) { onImport( imported ); onClose(); } } }>
					{ __( 'Replace design', 'events-certificate' ) }
				</Button>
			</div>
		</Modal>
	);
}
