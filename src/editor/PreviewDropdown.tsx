/**
 * The preview switcher, like the post editor's (editor-preview-dropdown): the
 * print page by default, or the certificate page on a desktop, tablet or
 * phone. Screens can be turned (landscape/portrait); the print page can't —
 * its format is the one set in Size.
 */
import { DropdownMenu, MenuGroup, MenuItem } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { check, desktop, mobile, page, tablet } from '@wordpress/icons';
import type { Device, Orientation, View } from './model';

const DEVICES = (): Array< { value: Device; label: string; icon: typeof page } > => [
	{ value: 'page', label: __( 'Page (print)', 'events-certificate' ), icon: page },
	{ value: 'desktop', label: __( 'Desktop', 'events-certificate' ), icon: desktop },
	{ value: 'tablet', label: __( 'Tablet', 'events-certificate' ), icon: tablet },
	{ value: 'mobile', label: __( 'Mobile', 'events-certificate' ), icon: mobile },
];

export default function PreviewDropdown( { view, onChange }: { view: View; onChange: ( view: View ) => void } ) {
	const devices = DEVICES();
	const active = devices.find( ( d ) => d.value === view.device ) || devices[ 0 ];
	const orientations: Array< [ Orientation, string ] > = [
		[ 'landscape', __( 'Landscape', 'events-certificate' ) ],
		[ 'portrait', __( 'Portrait', 'events-certificate' ) ],
	];
	return (
		<DropdownMenu
			className={ `evcert-preview-dropdown evcert-preview-dropdown--${ view.device }` }
			icon={ active.icon }
			label={ __( 'View', 'events-certificate' ) }
			toggleProps={ { size: 'compact' } as any }
			popoverProps={ { placement: 'bottom-end' } }
		>
			{ ( { onClose } ) => (
				<>
					<MenuGroup>
						{ devices.map( ( d ) => (
							<MenuItem
								key={ d.value }
								role="menuitemradio"
								isSelected={ view.device === d.value }
								icon={ view.device === d.value ? check : undefined }
								onClick={ () => { onChange( { ...view, device: d.value } ); onClose(); } }
							>
								{ d.label }
							</MenuItem>
						) ) }
					</MenuGroup>
					<MenuGroup label={ __( 'Orientation', 'events-certificate' ) }>
						{ orientations.map( ( [ value, label ] ) => (
							<MenuItem
								key={ value }
								role="menuitemradio"
								isSelected={ view.orientation === value }
								icon={ view.device !== 'page' && view.orientation === value ? check : undefined }
								disabled={ view.device === 'page' }
								onClick={ () => { onChange( { ...view, orientation: value } ); onClose(); } }
							>
								{ label }
							</MenuItem>
						) ) }
					</MenuGroup>
					{ view.device === 'page' ? (
						<p className="evcert-preview-dropdown__note">{ __( 'The page is the print format set in Size; it does not turn.', 'events-certificate' ) }</p>
					) : (
						<p className="evcert-preview-dropdown__note">{ __( 'Moving, resizing or hiding here changes this screen only. Text and style are shared.', 'events-certificate' ) }</p>
					) }
				</>
			) }
		</DropdownMenu>
	);
}
