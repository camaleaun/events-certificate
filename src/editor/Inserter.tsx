/**
 * The inserter, like the block inserter (block-editor/src/components/inserter):
 * a 350px panel with a search field and a 3-column grid of icon + title.
 */
import { Button, SearchControl } from '@wordpress/components';
import { useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { closeSmall, Icon } from '@wordpress/icons';
import { elementIcon } from './icons';
import type { Element } from './model';

type Item = { type: Element[ 'type' ]; title: string; keywords: string };

export default function Inserter( { hasQr, onInsert, onClose }: { hasQr: boolean; onInsert: ( type: Element[ 'type' ] ) => void; onClose: () => void } ) {
	const [ search, setSearch ] = useState( '' );
	const items: Item[] = [
		{ type: 'text', title: __( 'Text', 'events-certificates' ), keywords: 'paragraph heading title' },
		{ type: 'line', title: __( 'Line', 'events-certificates' ), keywords: 'separator rule divider' },
		{ type: 'rect', title: __( 'Rectangle', 'events-certificates' ), keywords: 'square shape box frame' },
		{ type: 'ellipse', title: __( 'Ellipse', 'events-certificates' ), keywords: 'circle shape ring' },
		{ type: 'image', title: __( 'Image', 'events-certificates' ), keywords: 'logo picture png jpg' },
		{ type: 'svg', title: __( 'SVG', 'events-certificates' ), keywords: 'icon logo ornament vector path signature' },
		...( hasQr ? [] : [ { type: 'qr' as const, title: __( 'Authenticity (QR)', 'events-certificates' ), keywords: 'qr code verify' } ] ),
	];
	const term = search.trim().toLowerCase();
	const shown = term ? items.filter( ( i ) => `${ i.title } ${ i.keywords }`.toLowerCase().includes( term ) ) : items;

	return (
		<div className="evcert-inserter">
			<div className="evcert-inserter__header">
				<strong>{ __( 'Elements', 'events-certificates' ) }</strong>
				<Button size="compact" icon={ closeSmall } label={ __( 'Close', 'events-certificates' ) } onClick={ onClose } />
			</div>
			<div className="evcert-inserter__search">
				<SearchControl __nextHasNoMarginBottom label={ __( 'Search', 'events-certificates' ) } placeholder={ __( 'Search', 'events-certificates' ) } value={ search } onChange={ setSearch } />
			</div>
			<div className="evcert-inserter__list" role="listbox" aria-label={ __( 'Elements', 'events-certificates' ) }>
				{ shown.map( ( item ) => (
					<Button key={ item.type } className="evcert-inserter__item" onClick={ () => onInsert( item.type ) }>
						<span className="evcert-inserter__item-icon">
							<Icon icon={ elementIcon( item.type ) } />
						</span>
						<span className="evcert-inserter__item-title">{ item.title }</span>
					</Button>
				) ) }
				{ ! shown.length && <p className="evcert-inserter__empty">{ __( 'No results found.', 'events-certificates' ) }</p> }
			</div>
		</div>
	);
}
