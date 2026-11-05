/**
 * The Certificate tab of the sidebar, like the block editor's document panel
 * (editor/src/components/post-card-panel + post-panel-row): a card with icon,
 * name and status, then label/value rows whose value opens a dropdown, and the
 * colors as a bordered, separated item group like the global styles palette.
 */
import {
	Button,
	ColorIndicator,
	ColorPicker,
	Dropdown,
	SelectControl,
	TextControl,
	ToggleControl,
	__experimentalHStack as HStack,
	__experimentalItem as Item,
	__experimentalItemGroup as ItemGroup,
	__experimentalNumberControl as NumberControl,
	__experimentalToggleGroupControl as ToggleGroupControl,
	__experimentalToggleGroupControlOption as ToggleGroupControlOption,
	__experimentalZStack as ZStack,
} from '@wordpress/components';
import { useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { Icon, page as pageIcon } from '@wordpress/icons';
import { color, type Design, type Mode } from './model';

const MM = 2.83465;
const SIZES = {
	a4: { label: 'A4', w: 842, h: 595 },
	letter: { label: 'Letter', w: 792, h: 612 },
};

type Props = {
	design: Design;
	mode: Mode;
	title: string;
	status: string;
	onTitle: ( title: string ) => void;
	onChange: ( design: Design ) => void;
};

function Row( { label, children }: { label: string; children: React.ReactNode } ) {
	return (
		<HStack className="evcert-panel-row">
			<div className="evcert-panel-row__label">{ label }</div>
			<div className="evcert-panel-row__control">{ children }</div>
		</HStack>
	);
}

/** A row whose value is a tertiary button opening a dropdown, like PostStatus/PostSchedule. */
function DropdownRow( { label, value, children }: { label: string; value: React.ReactNode; children: ( close: () => void ) => React.ReactNode } ) {
	return (
		<Row label={ label }>
			<Dropdown
				popoverProps={ { placement: 'left-start', offset: 36, shift: true } }
				renderToggle={ ( { isOpen, onToggle } ) => (
					<Button size="compact" variant="tertiary" onClick={ onToggle } aria-expanded={ isOpen }>
						{ value }
					</Button>
				) }
				renderContent={ ( { onClose } ) => <div className="evcert-row-popover">{ children( onClose ) }</div> }
			/>
		</Row>
	);
}

export default function DesignPanel( { design, mode, title, status, onTitle, onChange }: Props ) {
	const { page } = design;
	const landscape = page.w >= page.h;
	const sizeKey = ( Object.keys( SIZES ) as Array< keyof typeof SIZES > ).find( ( k ) => ( [ SIZES[ k ].w, SIZES[ k ].h ].sort().join() === [ page.w, page.h ].sort().join() ) ) || 'a4';
	const setPage = ( patch: Partial< Design[ 'page' ] > ) => onChange( { ...design, page: { ...page, ...patch } } );
	const bg = color( design, page.bg, mode );
	const bgVar = page.bg.startsWith( '$' ) ? design.colors[ page.bg.slice( 1 ) ] : null;

	return (
		<div className="evcert-design-panel">
			<div className="evcert-card">
				<span className="evcert-card__icon">
					<Icon icon={ pageIcon } />
				</span>
				<div>
					<h2 className="evcert-card__title">{ title || __( 'Certificate', 'events-certificate' ) }</h2>
					<p className="evcert-card__description">{ status }</p>
				</div>
			</div>

			<div className="evcert-inspector__section">
				<DropdownRow label={ __( 'Name', 'events-certificate' ) } value={ title || __( 'Certificate', 'events-certificate' ) }>
					{ () => <TextControl __next40pxDefaultSize __nextHasNoMarginBottom label={ __( 'Name', 'events-certificate' ) } value={ title } onChange={ onTitle } /> }
				</DropdownRow>
				<DropdownRow label={ __( 'Size', 'events-certificate' ) } value={ `${ SIZES[ sizeKey ].label } · ${ landscape ? __( 'Landscape', 'events-certificate' ) : __( 'Portrait', 'events-certificate' ) }` }>
					{ () => (
						<>
							<SelectControl
								__next40pxDefaultSize
								__nextHasNoMarginBottom
								label={ __( 'Paper', 'events-certificate' ) }
								value={ sizeKey }
								options={ ( Object.keys( SIZES ) as Array< keyof typeof SIZES > ).map( ( k ) => ( { label: SIZES[ k ].label, value: k } ) ) }
								onChange={ ( k ) => {
									const s = SIZES[ k as keyof typeof SIZES ];
									setPage( landscape ? { w: s.w, h: s.h } : { w: s.h, h: s.w } );
								} }
							/>
							<ToggleGroupControl __next40pxDefaultSize __nextHasNoMarginBottom isBlock label={ __( 'Orientation', 'events-certificate' ) } value={ landscape ? 'landscape' : 'portrait' } onChange={ ( v ) => ( ( v === 'landscape' ) !== landscape ? setPage( { w: page.h, h: page.w } ) : null ) }>
								<ToggleGroupControlOption value="landscape" label={ __( 'Landscape', 'events-certificate' ) } />
								<ToggleGroupControlOption value="portrait" label={ __( 'Portrait', 'events-certificate' ) } />
							</ToggleGroupControl>
							<p className="evcert-meta">{ sprintf( /* translators: 1: width, 2: height in points. */ __( '%1$s × %2$s pt', 'events-certificate' ), String( page.w ), String( page.h ) ) }</p>
						</>
					) }
				</DropdownRow>
				<DropdownRow
					label={ __( 'Background', 'events-certificate' ) }
					value={
						<HStack spacing={ 2 } justify="flex-start">
							<ColorIndicator colorValue={ bg === 'transparent' || bg === 'none' ? undefined : bg } />
							<span>{ bgVar ? bgVar.name : page.bg }</span>
						</HStack>
					}
				>
					{ ( close ) => (
						<ItemGroup isBordered isSeparated>
							{ Object.entries( design.colors ).map( ( [ key, v ] ) => (
								<Item key={ key } onClick={ () => { setPage( { bg: `$${ key }` } ); close(); } }>
									<HStack justify="flex-start">
										<ColorIndicator colorValue={ v[ mode ] === 'transparent' ? undefined : v[ mode ] } />
										<span>{ v.name }</span>
									</HStack>
								</Item>
							) ) }
						</ItemGroup>
					) }
				</DropdownRow>
				<DropdownRow label={ __( 'Safe margin', 'events-certificate' ) } value={ `${ Math.round( page.safe / MM ) } mm` }>
					{ () => (
						<NumberControl __next40pxDefaultSize label={ __( 'Safe margin', 'events-certificate' ) } help={ __( 'Keep text and the QR code inside it: printers and trimming eat the edges.', 'events-certificate' ) } min={ 0 } max={ 40 } value={ Math.round( page.safe / MM ) } suffix={ <span className="evcert-suffix">mm</span> } onChange={ ( v ) => setPage( { safe: ( Number( v ) || 0 ) * MM } ) } />
					) }
				</DropdownRow>
				<DropdownRow label={ __( 'Bleed', 'events-certificate' ) } value={ `${ Math.round( page.bleed / MM ) } mm` }>
					{ () => (
						<NumberControl __next40pxDefaultSize label={ __( 'Bleed', 'events-certificate' ) } help={ __( 'How far backgrounds may run past the edge, for print shops.', 'events-certificate' ) } min={ 0 } max={ 10 } value={ Math.round( page.bleed / MM ) } suffix={ <span className="evcert-suffix">mm</span> } onChange={ ( v ) => setPage( { bleed: ( Number( v ) || 0 ) * MM } ) } />
					) }
				</DropdownRow>
			</div>

			<div className="evcert-inspector__section">
				<h2 className="evcert-section-heading">{ __( 'Colors', 'events-certificate' ) }</h2>
				<p className="evcert-meta">{ __( 'Each color has a light and a dark value. Elements use these colors, so changing one here changes them all.', 'events-certificate' ) }</p>
				<ItemGroup isBordered isSeparated className="evcert-palette">
					{ Object.entries( design.colors ).map( ( [ key, v ] ) => (
						<ColorVariable
							key={ key }
							name={ v.name }
							light={ v.light }
							dark={ v.dark }
							onChange={ ( next ) => onChange( { ...design, colors: { ...design.colors, [ key ]: { ...v, ...next } } } ) }
						/>
					) ) }
				</ItemGroup>
			</div>
		</div>
	);
}

function ColorVariable( { name, light, dark, onChange }: { name: string; light: string; dark: string; onChange: ( v: { name?: string; light?: string; dark?: string } ) => void } ) {
	const [ which, setWhich ] = useState< 'light' | 'dark' >( 'light' );
	const value = which === 'light' ? light : dark;
	const swatch = ( c: string ) => ( c === 'transparent' ? undefined : c );
	return (
		<Dropdown
			popoverProps={ { placement: 'left-start', offset: 36, shift: true } }
			renderToggle={ ( { isOpen, onToggle } ) => (
				<Item onClick={ onToggle } aria-expanded={ isOpen } className={ isOpen ? 'is-open' : '' }>
					<HStack justify="flex-start">
						<ZStack isLayered={ false } offset={ -8 }>
							<ColorIndicator colorValue={ swatch( light ) } />
							<ColorIndicator colorValue={ swatch( dark ) } />
						</ZStack>
						<span className="evcert-palette__name">{ name }</span>
					</HStack>
				</Item>
			) }
			renderContent={ () => (
				<div className="evcert-row-popover">
					<TextControl __next40pxDefaultSize __nextHasNoMarginBottom label={ __( 'Name', 'events-certificate' ) } value={ name } onChange={ ( n ) => onChange( { name: n } ) } />
					<ToggleGroupControl __next40pxDefaultSize __nextHasNoMarginBottom isBlock label={ __( 'Mode', 'events-certificate' ) } value={ which } onChange={ ( v ) => setWhich( v as 'light' ) }>
						<ToggleGroupControlOption value="light" label={ __( 'Light', 'events-certificate' ) } />
						<ToggleGroupControlOption value="dark" label={ __( 'Dark', 'events-certificate' ) } />
					</ToggleGroupControl>
					<ToggleControl __nextHasNoMarginBottom label={ __( 'Transparent', 'events-certificate' ) } checked={ value === 'transparent' } onChange={ ( t ) => onChange( { [ which ]: t ? 'transparent' : '#ffffff' } ) } />
					{ value !== 'transparent' && <ColorPicker color={ value } onChange={ ( c ) => onChange( { [ which ]: c } ) } enableAlpha={ false } /> }
				</div>
			) }
		/>
	);
}
