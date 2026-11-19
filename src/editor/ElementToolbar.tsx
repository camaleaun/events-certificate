/**
 * The selected element's toolbar, floating above it like the block toolbar
 * (block-editor/src/components/block-toolbar + block-popover): element icon,
 * movers, lock, and an Options menu with Delete in its own last group.
 */
import { DropdownMenu, MenuGroup, MenuItem, Popover, Toolbar, ToolbarButton, ToolbarDropdownMenu, ToolbarGroup, ToolbarItem } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { chevronDown, chevronUp, copy, formatBold, formatItalic, lock, moreVertical, pencil, plus, trash, unlock, unseen } from '@wordpress/icons';
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
	marks?: { b: boolean; i: boolean };
	onBold?: () => void;
	onItalic?: () => void;
	onInsertField?: ( field: string ) => void;
	onToggleEdit?: () => void;
	onImage?: ( picked: Picked ) => void;
	onEditDrawing?: () => void;
};

export default function ElementToolbar( { anchor, el, required, onSelectParent, onOrder, onToggle, onDuplicate, onRemove, editing, marks, onBold, onItalic, onInsertField, onToggleEdit, onImage, onEditDrawing }: Props ) {
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
			<Toolbar label={ __( 'Element tools', 'events-certificates' ) } className="evcert-element-toolbar">
				<ToolbarGroup>
					<ToolbarButton icon={ elementIcon( el.type ) } label={ el.name || el.type } onClick={ onSelectParent } />
				</ToolbarGroup>
				{ el.type === 'text' && (
					<ToolbarGroup>
						<ToolbarButton
							icon={ pencil }
							isPressed={ !! editing }
							label={ editing ? __( 'Done editing', 'events-certificates' ) : __( 'Edit text', 'events-certificates' ) }
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
				{ el.type === 'svg' && onEditDrawing && (
					<ToolbarGroup>
						<ToolbarButton onClick={ onEditDrawing } label={ __( 'Edit SVG', 'events-certificates' ) } showTooltip>{ __( 'Edit', 'events-certificates' ) }</ToolbarButton>
					</ToolbarGroup>
				) }
				{ editing && (
					<ToolbarGroup>
						<ToolbarButton icon={ formatBold } isPressed={ !! marks?.b } label={ __( 'Bold', 'events-certificates' ) } shortcut="⌘B" onMouseDown={ ( e: { preventDefault: () => void } ) => e.preventDefault() } onClick={ onBold } />
						<ToolbarButton icon={ formatItalic } isPressed={ !! marks?.i } label={ __( 'Italic', 'events-certificates' ) } shortcut="⌘I" onMouseDown={ ( e: { preventDefault: () => void } ) => e.preventDefault() } onClick={ onItalic } />
						<ToolbarDropdownMenu
							icon={ plus }
							label={ __( 'Insert a field', 'events-certificates' ) }
							controls={ [ false, true ].map( ( isText ) => Object.entries( info ).filter( ( [ , f ] ) => ( f.group === 'text' ) === isText ).map( ( [ key, f ] ) => ( { title: f.label, onClick: () => onInsertField?.( key ) } ) ) ) }
						/>
					</ToolbarGroup>
				) }
				{ ! editing && (
				<ToolbarGroup className="evcert-mover">
					<ToolbarButton icon={ chevronUp } label={ __( 'Bring forward', 'events-certificates' ) } onClick={ () => onOrder( 'up' ) } />
					<ToolbarButton icon={ chevronDown } label={ __( 'Send backward', 'events-certificates' ) } onClick={ () => onOrder( 'down' ) } />
				</ToolbarGroup>
				) }
				<ToolbarGroup>
					<ToolbarButton icon={ el.locked ? lock : unlock } isPressed={ !! el.locked } label={ el.locked ? __( 'Unlock', 'events-certificates' ) : __( 'Lock', 'events-certificates' ) } onClick={ () => onToggle( 'locked' ) } />
				</ToolbarGroup>
				<ToolbarGroup>
					<ToolbarItem>
						{ ( toggleProps ) => (
					<DropdownMenu icon={ moreVertical } label={ __( 'Options', 'events-certificates' ) } popoverProps={ { placement: 'bottom-start' } } toggleProps={ toggleProps }>
						{ ( { onClose } ) => (
							<>
								<MenuGroup>
									<MenuItem icon={ copy } shortcut="⌘D" onClick={ () => { onDuplicate(); onClose(); } }>
										{ __( 'Duplicate', 'events-certificates' ) }
									</MenuItem>
									<MenuItem onClick={ () => { onOrder( 'top' ); onClose(); } }>{ __( 'Bring to front', 'events-certificates' ) }</MenuItem>
									<MenuItem onClick={ () => { onOrder( 'bottom' ); onClose(); } }>{ __( 'Send to back', 'events-certificates' ) }</MenuItem>
									<MenuItem icon={ unseen } onClick={ () => { onToggle( 'hidden' ); onClose(); } }>
										{ __( 'Hide', 'events-certificates' ) }
									</MenuItem>
								</MenuGroup>
								<MenuGroup>
									<MenuItem
										icon={ trash }
										shortcut="⌫"
										disabled={ required }
										info={ required ? __( 'Required: it can be moved, not deleted.', 'events-certificates' ) : undefined }
										onClick={ () => { onRemove(); onClose(); } }
									>
										{ __( 'Delete', 'events-certificates' ) }
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
