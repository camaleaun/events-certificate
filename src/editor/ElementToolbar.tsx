/**
 * The selected element's toolbar, floating above it like the block toolbar
 * (block-editor/src/components/block-toolbar + block-popover): element icon,
 * movers, lock, and an Options menu with Delete in its own last group.
 */
import { DropdownMenu, MenuGroup, MenuItem, Popover, Toolbar, ToolbarButton, ToolbarGroup } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { arrowDown, arrowUp, copy, lock, moreVertical, trash, unlock, unseen } from '@wordpress/icons';
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
};

export default function ElementToolbar( { anchor, el, required, onSelectParent, onOrder, onToggle, onDuplicate, onRemove }: Props ) {
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
				<ToolbarGroup>
					<ToolbarButton icon={ arrowUp } label={ __( 'Bring forward', 'events-certificate' ) } onClick={ () => onOrder( 'up' ) } />
					<ToolbarButton icon={ arrowDown } label={ __( 'Send backward', 'events-certificate' ) } onClick={ () => onOrder( 'down' ) } />
				</ToolbarGroup>
				<ToolbarGroup>
					<ToolbarButton icon={ el.locked ? lock : unlock } isPressed={ !! el.locked } label={ el.locked ? __( 'Unlock', 'events-certificate' ) : __( 'Lock', 'events-certificate' ) } onClick={ () => onToggle( 'locked' ) } />
				</ToolbarGroup>
				<ToolbarGroup>
					<DropdownMenu icon={ moreVertical } label={ __( 'Options', 'events-certificate' ) } popoverProps={ { placement: 'bottom-start' } } toggleProps={ { size: 'compact' } as any }>
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
				</ToolbarGroup>
			</Toolbar>
		</Popover>
	);
}
