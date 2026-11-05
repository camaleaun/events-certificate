/**
 * Layers, like the List View (block-editor/src/components/list-view): rows of
 * icon + title, hidden/locked shown as state icons, an Options menu per row.
 * Top of the list = front of the canvas, so the order is the stacking order:
 * drag a row to restack it (a line shows where it lands), or use the menu.
 * Rename in place (menu, or double-click the title); delete from the menu
 * (a required part can be moved, not deleted, unless it is a duplicate).
 */
import { Button, DropdownMenu, MenuGroup, MenuItem } from '@wordpress/components';
import { useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { closeSmall, dragHandle, Icon, lock, moreVertical, unseen } from '@wordpress/icons';
import { elementIcon } from './icons';
import { isRequired, type Element } from './model';

type Props = {
	elements: Element[];
	selected: string | null;
	onSelect: ( id: string ) => void;
	onToggle: ( id: string, key: 'locked' | 'hidden' ) => void;
	onOrder: ( id: string, to: 'up' | 'down' | 'top' | 'bottom' ) => void;
	/** Moves an element just in front of (above in the list) or behind another. */
	onMove: ( id: string, target: string, place: 'front' | 'behind' ) => void;
	onRename: ( id: string, name: string ) => void;
	onRemove: ( id: string ) => void;
	onClose: () => void;
};

type Drop = { id: string; place: 'front' | 'behind' } | null;

function RenameField( { value, onDone }: { value: string; onDone: ( name: string | null ) => void } ) {
	const [ text, setText ] = useState( value );
	const [ done, setDone ] = useState( false );
	const finish = ( name: string | null ) => {
		if ( ! done ) {
			setDone( true );
			onDone( name );
		}
	};
	return (
		<input
			className="evcert-list-view__rename"
			aria-label={ __( 'Name', 'events-certificate' ) }
			value={ text }
			// eslint-disable-next-line jsx-a11y/no-autofocus -- the field appears because the user asked to rename.
			autoFocus
			onFocus={ ( event ) => event.target.select() }
			onChange={ ( event ) => setText( event.target.value ) }
			onKeyDown={ ( event ) => {
				if ( event.key === 'Enter' ) {
					finish( text );
				} else if ( event.key === 'Escape' ) {
					event.stopPropagation();
					finish( null );
				}
			} }
			onBlur={ () => finish( text ) }
		/>
	);
}

export default function ListView( { elements, selected, onSelect, onToggle, onOrder, onMove, onRename, onRemove, onClose }: Props ) {
	const [ renaming, setRenaming ] = useState< string | null >( null );
	const [ dragging, setDragging ] = useState< string | null >( null );
	const [ drop, setDrop ] = useState< Drop >( null );
	const last = elements.length - 1;

	return (
		<div className="evcert-list-view">
			<div className="evcert-inserter__header">
				<strong>{ __( 'Layers', 'events-certificate' ) }</strong>
				<Button size="compact" icon={ closeSmall } label={ __( 'Close', 'events-certificate' ) } onClick={ onClose } />
			</div>
			<ul className="evcert-list-view__tree" role="tree" aria-label={ __( 'Layers', 'events-certificate' ) } onDragLeave={ ( event ) => ! event.currentTarget.contains( event.relatedTarget as Node ) && setDrop( null ) }>
				{ [ ...elements ].reverse().map( ( el ) => {
					const index = elements.indexOf( el );
					const required = isRequired( el, elements );
					const classes = [
						'evcert-list-view__row',
						el.id === selected && 'is-selected',
						el.hidden && 'is-hidden',
						el.id === dragging && 'is-dragging',
						drop?.id === el.id && `is-drop-${ drop.place }`,
					].filter( Boolean ).join( ' ' );
					return (
						<li
							key={ el.id }
							role="treeitem"
							aria-selected={ el.id === selected }
							className={ classes }
							draggable={ renaming !== el.id }
							onDragStart={ ( event ) => {
								event.dataTransfer.effectAllowed = 'move';
								event.dataTransfer.setData( 'text/plain', el.id );
								setDragging( el.id );
							} }
							onDragEnd={ () => {
								setDragging( null );
								setDrop( null );
							} }
							onDragOver={ ( event ) => {
								if ( ! dragging || dragging === el.id ) {
									return;
								}
								event.preventDefault();
								const box = event.currentTarget.getBoundingClientRect();
								const place = event.clientY < box.top + box.height / 2 ? 'front' : 'behind';
								if ( drop?.id !== el.id || drop.place !== place ) {
									setDrop( { id: el.id, place } );
								}
							} }
							onDrop={ ( event ) => {
								event.preventDefault();
								if ( dragging && drop && dragging !== drop.id ) {
									onMove( dragging, drop.id, drop.place );
								}
								setDragging( null );
								setDrop( null );
							} }
						>
							<span className="evcert-list-view__handle" aria-hidden="true">
								<Icon icon={ dragHandle } size={ 16 } />
							</span>
							{ renaming === el.id ? (
								<div className="evcert-list-view__select">
									<Icon icon={ elementIcon( el.type ) } size={ 24 } />
									<RenameField
										value={ el.name || '' }
										onDone={ ( next ) => {
											setRenaming( null );
											if ( next !== null && next.trim() && next.trim() !== ( el.name || '' ) ) {
												onRename( el.id, next.trim() );
											}
										} }
									/>
								</div>
							) : (
								<button type="button" className="evcert-list-view__select" onClick={ () => onSelect( el.id ) } onDoubleClick={ () => setRenaming( el.id ) }>
									<Icon icon={ elementIcon( el.type ) } size={ 24 } />
									<span className="evcert-list-view__title">{ el.name || el.type }</span>
									{ el.hidden && <Icon icon={ unseen } size={ 24 } className="evcert-list-view__state" /> }
									{ el.locked && <Icon icon={ lock } size={ 24 } className="evcert-list-view__state" /> }
								</button>
							) }
							<DropdownMenu icon={ moreVertical } label={ __( 'Options', 'events-certificate' ) } className="evcert-list-view__menu" toggleProps={ { size: 'small' } as any }>
								{ ( { onClose: close } ) => (
									<>
										<MenuGroup>
											<MenuItem onClick={ () => { setRenaming( el.id ); close(); } }>{ __( 'Rename', 'events-certificate' ) }</MenuItem>
											<MenuItem onClick={ () => { onToggle( el.id, 'hidden' ); close(); } }>{ el.hidden ? __( 'Show', 'events-certificate' ) : __( 'Hide', 'events-certificate' ) }</MenuItem>
											<MenuItem onClick={ () => { onToggle( el.id, 'locked' ); close(); } }>{ el.locked ? __( 'Unlock', 'events-certificate' ) : __( 'Lock', 'events-certificate' ) }</MenuItem>
										</MenuGroup>
										<MenuGroup>
											<MenuItem disabled={ index === last } onClick={ () => { onOrder( el.id, 'up' ); close(); } }>{ __( 'Bring forward', 'events-certificate' ) }</MenuItem>
											<MenuItem disabled={ index === 0 } onClick={ () => { onOrder( el.id, 'down' ); close(); } }>{ __( 'Send backward', 'events-certificate' ) }</MenuItem>
											<MenuItem disabled={ index === last } onClick={ () => { onOrder( el.id, 'top' ); close(); } }>{ __( 'Bring to front', 'events-certificate' ) }</MenuItem>
											<MenuItem disabled={ index === 0 } onClick={ () => { onOrder( el.id, 'bottom' ); close(); } }>{ __( 'Send to back', 'events-certificate' ) }</MenuItem>
										</MenuGroup>
										<MenuGroup>
											<MenuItem
												isDestructive
												disabled={ required }
												info={ required ? __( 'Required: it can be moved, not deleted.', 'events-certificate' ) : undefined }
												onClick={ () => { onRemove( el.id ); close(); } }
											>
												{ __( 'Delete', 'events-certificate' ) }
											</MenuItem>
										</MenuGroup>
									</>
								) }
							</DropdownMenu>
						</li>
					);
				} ) }
			</ul>
		</div>
	);
}
