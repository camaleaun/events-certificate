/**
 * An SVG element as fields, laid out like the Certificate tab: the viewBox as
 * a row whose value opens a dropdown (like Size), and the paths as a list like
 * the palette's colors — a swatch for the fill and the path data, added with +
 * and removed with ×. A path without a fill uses the current color (the
 * element's Current color, by default the text color).
 */
import {
	Button,
	ColorIndicator,
	ColorPalette,
	Dropdown,
	TextControl,
	__experimentalHStack as HStack,
	__experimentalItem as Item,
	__experimentalItemGroup as ItemGroup,
	__experimentalNumberControl as NumberControl,
} from '@wordpress/components';
import { useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { closeSmall, plus } from '@wordpress/icons';
import { DropdownRow } from './DesignPanel';
import { color, isPathData, sanitizeSvg, svgPaths, type Design, type Mode, type SvgEl, type SvgPath } from './model';

type Props = {
	el: SvgEl;
	design: Design;
	mode: Mode;
	onChange: ( patch: Partial< SvgEl > ) => void;
};

const TRANSPARENT = '#ffffff00';

/** The fill swatch: current color, a palette color or a custom one. */
function FillSwatch( { value, current, design, mode, onChange }: { value?: string; current: string; design: Design; mode: Mode; onChange: ( fill?: string ) => void } ) {
	const variable = value?.startsWith( '$' ) ? design.colors[ value.slice( 1 ) ] : null;
	const shown = value ? color( design, value, mode ) : color( design, current, mode );
	const palette = Object.entries( design.colors ).map( ( [ slug, v ] ) => ( { slug, name: v.name, color: v[ mode ] === 'transparent' ? TRANSPARENT : v[ mode ] } ) );
	return (
		<Dropdown
			popoverProps={ { placement: 'left-start', offset: 36, shift: true } }
			renderToggle={ ( { isOpen, onToggle } ) => (
				<Button
					className="evcert-svg-path__swatch"
					onClick={ onToggle }
					aria-expanded={ isOpen }
					label={ sprintf( /* translators: %s: color name. */ __( 'Fill: %s', 'events-certificate' ), value ? variable?.name || value : __( 'Current color', 'events-certificate' ) ) }
					showTooltip
				>
					<ColorIndicator colorValue={ shown === 'none' ? undefined : shown } className={ value ? '' : 'is-current' } />
				</Button>
			) }
			renderContent={ () => (
				<div className="evcert-color-popover">
					<Button variant={ value ? 'secondary' : 'primary' } size="compact" onClick={ () => onChange( undefined ) }>
						{ __( 'Current color', 'events-certificate' ) }
					</Button>
					<p className="evcert-color-popover__label">{ __( 'Theme', 'events-certificate' ) }</p>
					<ColorPalette
						colors={ palette }
						value={ variable ? palette.find( ( c ) => `$${ c.slug }` === value )?.color : undefined }
						disableCustomColors
						clearable={ false }
						onChange={ ( picked ) => {
							const entry = palette.find( ( c ) => c.color === picked );
							onChange( entry ? `$${ entry.slug }` : picked || undefined );
						} }
					/>
					<p className="evcert-color-popover__label">{ __( 'Custom', 'events-certificate' ) }</p>
					<ColorPalette value={ value && ! variable ? value : undefined } onChange={ ( c ) => onChange( c || undefined ) } clearable />
				</div>
			) }
		/>
	);
}

/** The path data field: kept while typing, saved when it is valid. */
function PathData( { index, value, onChange, onImport }: { index: number; value: string; onChange: ( d: string ) => void; onImport: ( svg: { vw: number; vh: number; paths: SvgPath[] } ) => void } ) {
	const [ text, setText ] = useState( value );
	const valid = isPathData( text );
	return (
		<TextControl
			__next40pxDefaultSize
			__nextHasNoMarginBottom
			className={ `evcert-svg-path__d${ valid ? '' : ' is-invalid' }` }
			label={ sprintf( /* translators: %d: path number. */ __( 'Path %d data (d)', 'events-certificate' ), index + 1 ) }
			hideLabelFromVision
			placeholder="M0 0h24v24H0z"
			onPaste={ ( event: { clipboardData: DataTransfer; preventDefault: () => void } ) => {
				const pasted = event.clipboardData.getData( 'text/plain' );
				const svg = /^\s*</.test( pasted ) ? sanitizeSvg( pasted ) : null;
				if ( svg ) {
					event.preventDefault();
					onImport( svg );
				}
			} }
			value={ text }
			onChange={ ( d ) => {
				setText( d );
				if ( isPathData( d ) ) {
					onChange( d.trim() );
				}
			} }
		/>
	);
}

export default function SvgPanel( { el, design, mode, onChange }: Props ) {
	const paths = svgPaths( el );
	const current = el.color || '$text';
	const setPaths = ( next: SvgPath[] ) => onChange( { paths: next, markup: undefined, fill: undefined } );
	const setPath = ( i: number, patch: Partial< SvgPath > ) => setPaths( paths.map( ( p, j ) => ( j === i ? { ...p, ...patch } : p ) ) );

	return (
		<div className="evcert-svg-panel">
			<div className="evcert-inspector__section">
				<DropdownRow label={ __( 'ViewBox', 'events-certificate' ) } value={ `${ el.vw } × ${ el.vh }` }>
					{ () => (
						<>
							<HStack alignment="top">
								<NumberControl __next40pxDefaultSize label={ __( 'Width', 'events-certificate' ) } min={ 1 } value={ el.vw } onChange={ ( v ) => Number( v ) > 0 && onChange( { vw: Number( v ) } ) } />
								<NumberControl __next40pxDefaultSize label={ __( 'Height', 'events-certificate' ) } min={ 1 } value={ el.vh } onChange={ ( v ) => Number( v ) > 0 && onChange( { vh: Number( v ) } ) } />
							</HStack>
							<p className="evcert-meta">{ __( 'The coordinate space of the paths. The element’s size on the page is set in Dimensions.', 'events-certificate' ) }</p>
						</>
					) }
				</DropdownRow>
			</div>
			<div className="evcert-inspector__section">
				<HStack className="evcert-svg-panel__header">
					<h2 className="evcert-section-heading">{ __( 'Paths', 'events-certificate' ) }</h2>
					<Button size="small" icon={ plus } label={ __( 'Add path', 'events-certificate' ) } onClick={ () => setPaths( [ ...paths, { d: '' } ] ) } />
				</HStack>
				<ItemGroup isBordered isSeparated className="evcert-svg-paths">
					{ paths.map( ( p, i ) => (
						<Item key={ `${ i }-${ paths.length }` } className="evcert-svg-path">
							<HStack spacing={ 2 }>
								<FillSwatch value={ p.fill } current={ current } design={ design } mode={ mode } onChange={ ( fill ) => setPath( i, { fill } ) } />
								<PathData index={ i } value={ p.d } onChange={ ( d ) => setPath( i, { d } ) } onImport={ ( svg ) => onChange( { ...svg, markup: undefined, fill: undefined } ) } />
								<Button size="small" icon={ closeSmall } label={ __( 'Remove path', 'events-certificate' ) } disabled={ paths.length === 1 } accessibleWhenDisabled onClick={ () => setPaths( paths.filter( ( _, j ) => j !== i ) ) } />
							</HStack>
						</Item>
					) ) }
				</ItemGroup>
				<p className="evcert-meta">{ __( 'Each path is the d value of an SVG <path>. Without a fill it uses the current color, set in Color. Pasting SVG code into a path fills in the viewBox and the paths; nothing else is kept.', 'events-certificate' ) }</p>
			</div>
		</div>
	);
}
