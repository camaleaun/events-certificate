/**
 * Layers, like the List View (block-editor/src/components/list-view): rows of
 * icon + title, hidden/locked shown as state icons, an Options menu per row.
 * Top of the list = front of the canvas.
 */
import { Button, DropdownMenu, MenuGroup, MenuItem } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { closeSmall, Icon, lock, moreVertical, unseen } from '@wordpress/icons';
import { elementIcon } from './icons';
import type { Element } from './model';

type Props = {
	elements: Element[];
	selected: string | null;
	onSelect: ( id: string ) => void;
	onToggle: ( id: string, key: 'locked' | 'hidden' ) => void;
	onOrder: ( id: string, to: 'up' | 'down' | 'top' | 'bottom' ) => void;
	onClose: () => void;
};

export default function ListView( { elements, selected, onSelect, onToggle, onOrder, onClose }: Props ) {
	return (
		<div className="evcert-list-view">
			<div className="evcert-inserter__header">
				<strong>{ __( 'Layers', 'events-certificate' ) }</strong>
				<Button size="compact" icon={ closeSmall } label={ __( 'Close', 'events-certificate' ) } onClick={ onClose } />
			</div>
			<ul className="evcert-list-view__tree" role="tree" aria-label={ __( 'Layers', 'events-certificate' ) }>
				{ [ ...elements ].reverse().map( ( el ) => (
					<li key={ el.id } role="treeitem" aria-selected={ el.id === selected } className={ `evcert-list-view__row${ el.id === selected ? ' is-selected' : '' }${ el.hidden ? ' is-hidden' : '' }` }>
						<button type="button" className="evcert-list-view__select" onClick={ () => onSelect( el.id ) }>
							<Icon icon={ elementIcon( el.type ) } size={ 24 } />
							<span className="evcert-list-view__title">{ el.name || el.type }</span>
							{ el.hidden && <Icon icon={ unseen } size={ 24 } className="evcert-list-view__state" /> }
							{ el.locked && <Icon icon={ lock } size={ 24 } className="evcert-list-view__state" /> }
						</button>
						<DropdownMenu icon={ moreVertical } label={ __( 'Options', 'events-certificate' ) } className="evcert-list-view__menu" toggleProps={ { size: 'small' } as any }>
							{ ( { onClose: close } ) => (
								<MenuGroup>
									<MenuItem onClick={ () => { onToggle( el.id, 'hidden' ); close(); } }>{ el.hidden ? __( 'Show', 'events-certificate' ) : __( 'Hide', 'events-certificate' ) }</MenuItem>
									<MenuItem onClick={ () => { onToggle( el.id, 'locked' ); close(); } }>{ el.locked ? __( 'Unlock', 'events-certificate' ) : __( 'Lock', 'events-certificate' ) }</MenuItem>
									<MenuItem onClick={ () => { onOrder( el.id, 'top' ); close(); } }>{ __( 'Bring to front', 'events-certificate' ) }</MenuItem>
									<MenuItem onClick={ () => { onOrder( el.id, 'bottom' ); close(); } }>{ __( 'Send to back', 'events-certificate' ) }</MenuItem>
								</MenuGroup>
							) }
						</DropdownMenu>
					</li>
				) ) }
			</ul>
		</div>
	);
}
