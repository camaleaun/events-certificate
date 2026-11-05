/**
 * The selected element's toolbar, floating above it like the block toolbar
 * (block-editor/src/components/block-toolbar + block-popover): element icon,
 * movers, lock, and an Options menu with Delete in its own last group.
 */
import { DropdownMenu, MenuGroup, MenuItem, Popover, Toolbar, ToolbarButton, ToolbarDropdownMenu, ToolbarGroup, ToolbarItem } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { arrowDown, arrowUp, copy, formatBold, formatItalic, lock, moreVertical, pencil, plus, trash, unlock, unseen } from '@wordpress/icons';
import { fields } from './FieldText';
import { ReplaceImage } from './ImageSource';
import type { Picked } from './media';
import { elementIcon } from './icons';
import type { Element } from './model';

type Props = {
	anchor: globalThis.Element | null;
	el: Element;
	required: boolean;
	onSelectParent: () => void;
	onOrder: ( to: 'up' | 'down' | 'top' | 'bottom' ) => void;
	onToggle: ( key: 'locked' | 'hidden' ) => void;
	onDuplicate: () => void;
	onRemove: () => void;
	editing?: boolean;
	onBold?: () => void;
	onItalic?: () => void;
	onInsertField?: ( field: string ) => void;
	onToggleEdit?: () => void;
	onImage?: ( picked: Picked ) => void;
};

export default function ElementToolbar( { anchor, el, required, onSelectParent, onOrder, onToggle, onDuplicate, onRemove, editing, onBold, onItalic, onInsertField, onToggleEdit, onImage }: Props ) {
	const info = fields();
	if ( ! anchor ) {
		return null;
	}
	return (
		<Popover
			anchor={ anchor }
			variant="unstyled"
			animate={ false }
			focusOnMount={ false }
			placement="top-start"
			resize={ false }
			flip
			shift
			offset={ 12 }
			className="evcert-element-toolbar-popover"
		>
			<Toolbar label={ __( 'Element tools', 'events-certificate' ) } className="evcert-element-toolbar">
				<ToolbarGroup>
					<ToolbarButton icon={ elementIcon( el.type ) } label={ el.name || el.type } onClick={ onSelectParent } />
				</ToolbarGroup>
				{ el.type === 'text' && (
					<ToolbarGroup>
						<ToolbarButton
							icon={ pencil }
							isPressed={ !! editing }
							label={ editing ? __( 'Done editing', 'events-certificate' ) : __( 'Edit text', 'events-certificate' ) }
							shortcut="↵"
							onMouseDown={ ( e: { preventDefault: () => void } ) => e.preventDefault() }
							onClick={ onToggleEdit }
						/>
					</ToolbarGroup>
				) }
				{ el.type === 'image' && onImage && (
					<ToolbarGroup>
						<ToolbarItem>{ ( toggleProps ) => <ReplaceImage src={ el.src } attachment={ el.attachment } onPick={ onImage } toggleProps={ toggleProps as any } /> }</ToolbarItem>
					</ToolbarGroup>
				) }
				{ editing && (
					<ToolbarGroup>
						<ToolbarButton icon={ formatBold } label={ __( 'Bold', 'events-certificate' ) } shortcut="⌘B" onMouseDown={ ( e: { preventDefault: () => void } ) => e.preventDefault() } onClick={ onBold } />
						<ToolbarButton icon={ formatItalic } label={ __( 'Italic', 'events-certificate' ) } shortcut="⌘I" onMouseDown={ ( e: { preventDefault: () => void } ) => e.preventDefault() } onClick={ onItalic } />
						<ToolbarDropdownMenu
							icon={ plus }
							label={ __( 'Insert a field', 'events-certificate' ) }
							controls={ Object.entries( info ).map( ( [ key, f ] ) => ( { title: f.label, onClick: () => onInsertField?.( key ) } ) ) }
						/>
					</ToolbarGroup>
				) }
				{ ! editing && (
				<ToolbarGroup>
					<ToolbarButton icon={ arrowUp } label={ __( 'Bring forward', 'events-certificate' ) } onClick={ () => onOrder( 'up' ) } />
					<ToolbarButton icon={ arrowDown } label={ __( 'Send backward', 'events-certificate' ) } onClick={ () => onOrder( 'down' ) } />
				</ToolbarGroup>
				) }
				<ToolbarGroup>
					<ToolbarButton icon={ el.locked ? lock : unlock } isPressed={ !! el.locked } label={ el.locked ? __( 'Unlock', 'events-certificate' ) : __( 'Lock', 'events-certificate' ) } onClick={ () => onToggle( 'locked' ) } />
				</ToolbarGroup>
				<ToolbarGroup>
					<ToolbarItem>
						{ ( toggleProps ) => (
					<DropdownMenu icon={ moreVertical } label={ __( 'Options', 'events-certificate' ) } popoverProps={ { placement: 'bottom-start' } } toggleProps={ toggleProps }>
						{ ( { onClose } ) => (
							<>
								<MenuGroup>
									<MenuItem icon={ copy } shortcut="⌘D" onClick={ () => { onDuplicate(); onClose(); } }>
										{ __( 'Duplicate', 'events-certificate' ) }
									</MenuItem>
									<MenuItem onClick={ () => { onOrder( 'top' ); onClose(); } }>{ __( 'Bring to front', 'events-certificate' ) }</MenuItem>
									<MenuItem onClick={ () => { onOrder( 'bottom' ); onClose(); } }>{ __( 'Send to back', 'events-certificate' ) }</MenuItem>
									<MenuItem icon={ unseen } onClick={ () => { onToggle( 'hidden' ); onClose(); } }>
										{ __( 'Hide', 'events-certificate' ) }
									</MenuItem>
								</MenuGroup>
								<MenuGroup>
									<MenuItem
										icon={ trash }
										shortcut="⌫"
										disabled={ required }
										info={ required ? __( 'Required: it can be moved, not deleted.', 'events-certificate' ) : undefined }
										onClick={ () => { onRemove(); onClose(); } }
									>
										{ __( 'Delete', 'events-certificate' ) }
									</MenuItem>
								</MenuGroup>
							</>
						) }
					</DropdownMenu>
						) }
					</ToolbarItem>
				</ToolbarGroup>
			</Toolbar>
		</Popover>
	);
}
