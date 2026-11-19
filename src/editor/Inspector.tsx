/**
 * The selected element's settings, like the block inspector
 * (block-editor/src/components/block-inspector): a card with icon, name and
 * description, then ToolsPanels — Typography, Color, Dimensions, Border —
 * with the public @wordpress/components the block editor's own panels use.
 */
import {
	Button,
	ColorIndicator,
	ColorPalette,
	Dropdown,
	FontSizePicker,
	RangeControl,
	SelectControl,
	TextareaControl,
	TextControl,
	ToggleControl,
	__experimentalNumberControl as NumberControl,
	__experimentalUnitControl as UnitControl,
	__experimentalToggleGroupControl as ToggleGroupControl,
	__experimentalToggleGroupControlOption as ToggleGroupControlOption,
	__experimentalToggleGroupControlOptionIcon as ToggleGroupControlOptionIcon,
	__experimentalToolsPanel as ToolsPanel,
	__experimentalToolsPanelItem as ToolsPanelItem,
	__experimentalHStack as HStack,
} from '@wordpress/components';
import { useState } from '@wordpress/element';
import { __, _x, sprintf } from '@wordpress/i18n';
import { justifyBottom, justifyCenter, justifyCenterVertical, justifyLeft, justifyRight, justifyStretch, justifyStretchVertical, justifyTop, alignCenter, alignLeft, alignRight, cornerBottomLeft, cornerBottomRight, cornerTopLeft, cornerTopRight, formatUppercase, Icon, link, linkOff, reset, settings } from '@wordpress/icons';
import { elementIcon } from './icons';
import { naturalSize, type Picked } from './media';
import SvgPanel from './SvgPanel';
import { fields, tokenOnly } from './FieldText';
import TextByLanguage from './TextByLanguage';
import { box as boxOf, color, isLen, layoutFrom, parseLen, lockedRatio, lockedSide, refBox, REQUIRED_SLOTS, type Axis, type AxisMode, type Box, type Layout, type Design, type Element, type Mode, fieldValue, runsOf } from './model';

type Props = {
	el: Element;
	stored?: Element | null;
	design: Design;
	mode: Mode;
	tab: 'content' | 'art';
	locale: string;
	sample: Record< string, string >;
	onChange: ( patch: Partial< Element > ) => void;
	onImage: ( picked: Picked ) => void;
	onBox: ( b: Box ) => void;
};

const FONT_SIZES = [ 8, 10, 12, 14, 16, 18, 24, 32, 42 ].map( ( size ) => ( { name: `${ size } pt`, slug: String( size ), size } ) );
const num = ( v: number ) => Math.round( v * 100 ) / 100;
const PT = [ { value: 'pt', label: 'pt', default: 0 } ];

/** A color item like the block editor's ColorGradientDropdown (indicator + name, a palette in a dropdown). */
export function ColorItem( { label, value, design, mode, onChange, clearTo = '' }: { label: string; value?: string; design: Design; mode: Mode; onChange: ( v: string ) => void; clearTo?: string } ) {
	const shown = color( design, value, mode );
	const variable = value?.startsWith( '$' ) ? design.colors[ value.slice( 1 ) ] : null;
	return (
		<Dropdown
			className="evcert-color-item"
			popoverProps={ { placement: 'left-start', offset: 36, shift: true } }
			renderToggle={ ( { isOpen, onToggle } ) => (
				<Button __next40pxDefaultSize className={ `evcert-color-item__toggle${ isOpen ? ' is-open' : '' }` } onClick={ onToggle } aria-expanded={ isOpen }>
					<HStack justify="flex-start">
						<ColorIndicator colorValue={ shown === 'none' ? undefined : shown } />
						<span className="evcert-color-item__name">{ label }</span>
						<span className="evcert-color-item__value">{ variable ? variable.name : value || __( 'None', 'events-certificates' ) }</span>
					</HStack>
				</Button>
			) }
			renderContent={ () => (
				<div className="evcert-color-popover">
					<p className="evcert-color-popover__label">{ __( 'Theme', 'events-certificates' ) }</p>
					<ColorPalette
						colors={ Object.entries( design.colors ).map( ( [ key, v ] ) => ( { name: v.name, color: v[ mode ] === 'transparent' ? '#ffffff00' : v[ mode ], slug: key } ) ) }
						value={ variable ? ( variable[ mode ] === 'transparent' ? '#ffffff00' : variable[ mode ] ) : undefined }
						disableCustomColors
						clearable={ false }
						onChange={ ( picked ) => {
							const entry = Object.entries( design.colors ).find( ( [ , v ] ) => v[ mode ] === picked || ( v[ mode ] === 'transparent' && picked === '#ffffff00' ) );
							onChange( entry ? `$${ entry[ 0 ] }` : picked || '' );
						} }
					/>
					<p className="evcert-color-popover__label">{ __( 'Custom', 'events-certificates' ) }</p>
					<ColorPalette value={ value?.startsWith( '$' ) ? undefined : value } onChange={ ( c ) => onChange( c || clearTo ) } clearable={ false } />
					{ /* One Clear for any color, theme or custom: back to the element's default (none, or the text color). */ }
					<div className="evcert-color-popover__footer">
						<Button variant="tertiary" size="small" disabled={ ( value || '' ) === clearTo } accessibleWhenDisabled onClick={ () => onChange( clearTo ) }>
							{ __( 'Clear', 'events-certificates' ) }
						</Button>
					</div>
				</div>
			) }
		/>
	);
}

export default function Inspector( { el, stored, design, mode, tab, locale, sample, onChange, onImage, onBox }: Props ) {
	const [ linked, setLinked ] = useState( true );
	const required = !! el.slot && REQUIRED_SLOTS.includes( el.slot );
	const typeLabel = {
		text: __( 'Text', 'events-certificates' ),
		line: __( 'Line', 'events-certificates' ),
		rect: __( 'Rectangle', 'events-certificates' ),
		ellipse: __( 'Ellipse', 'events-certificates' ),
		path: __( 'Shape', 'events-certificates' ),
		image: __( 'Image', 'events-certificates' ),
		svg: __( 'SVG', 'events-certificates' ),
		qr: __( 'Authenticity', 'events-certificates' ),
	}[ el.type ];
	const description = required
		? __( 'Required on every certificate: it can be moved and styled, not deleted.', 'events-certificates' )
		: el.type === 'qr'
		? __( 'The QR code and the code anyone can check.', 'events-certificates' )
		: el.type === 'text'
		? __( 'Text with fields that each certificate fills in.', 'events-certificates' )
		: '';

	return (
		<div className="evcert-inspector">
			<div className="evcert-card">
				<span className="evcert-card__icon">
					<Icon icon={ elementIcon( el.type ) } />
				</span>
				<div>
					<h2 className="evcert-card__title">
						{ el.name || typeLabel }
						{ el.slot && <span className="evcert-slot">{ el.slot }</span> }
					</h2>
					{ description && <p className="evcert-card__description">{ description }</p> }
				</div>
			</div>

			{ tab === 'art' && el.type === 'image' && (
				<ToolsPanel label={ __( 'Settings', 'events-certificates' ) } resetAll={ () => onChange( { ratio: 'auto', fit: 'cover' } as Partial< Element > ) }>
					<ToolsPanelItem label={ __( 'Alternative text', 'events-certificates' ) } hasValue={ () => !! el.alt } onDeselect={ () => onChange( { alt: '' } as Partial< Element > ) } isShownByDefault>
						<TextareaControl
							__nextHasNoMarginBottom
							label={ __( 'Alternative text', 'events-certificates' ) }
							value={ el.alt || '' }
							onChange={ ( alt ) => onChange( { alt } as Partial< Element > ) }
						/>
					</ToolsPanelItem>
					<ToolsPanelItem label={ __( 'Aspect ratio', 'events-certificates' ) } hasValue={ () => ( el.ratio || 'auto' ) !== 'auto' } onDeselect={ () => onChange( { ratio: 'auto' } as Partial< Element > ) } isShownByDefault>
						<SelectControl
							__next40pxDefaultSize
							__nextHasNoMarginBottom
							label={ __( 'Aspect ratio', 'events-certificates' ) }
							value={ ( el.ratio || 'auto' ) as any }
							options={ [
								{ label: __( 'Original', 'events-certificates' ), value: 'auto' },
								{ label: __( 'Square - 1:1', 'events-certificates' ), value: '1' },
								{ label: __( 'Standard - 4:3', 'events-certificates' ), value: '4/3' },
								{ label: __( 'Portrait - 3:4', 'events-certificates' ), value: '3/4' },
								{ label: __( 'Classic - 3:2', 'events-certificates' ), value: '3/2' },
								{ label: __( 'Classic Portrait - 2:3', 'events-certificates' ), value: '2/3' },
								{ label: __( 'Wide - 16:9', 'events-certificates' ), value: '16/9' },
								{ label: __( 'Tall - 9:16', 'events-certificates' ), value: '9/16' },
							] }
							onChange={ async ( ratio ) => {
								const b = boxOf( el );
								let r = ratio.split( '/' ).map( Number ).reduce( ( x, y ) => x / y );
								if ( ratio === 'auto' ) {
									if ( ! el.src ) {
										onChange( { ratio } as Partial< Element > );
										return;
									}
									const n = await naturalSize( el.src ).catch( () => null );
									r = n ? n.width / n.height : b.w / b.h;
								}
								onChange( { ratio } as Partial< Element > );
								onBox( { ...b, h: Math.round( ( b.w / r ) * 100 ) / 100 } );
							} }
						/>
					</ToolsPanelItem>
					<ToolsPanelItem label={ __( 'Scale', 'events-certificates' ) } hasValue={ () => el.fit === 'contain' } onDeselect={ () => onChange( { fit: 'cover' } as Partial< Element > ) } isShownByDefault>
						<ToggleGroupControl
							__next40pxDefaultSize
							__nextHasNoMarginBottom
							isBlock
							label={ __( 'Scale', 'events-certificates' ) }
							value={ el.fit || 'cover' }
							onChange={ ( fit ) => onChange( { fit } as Partial< Element > ) }
						>
							<ToggleGroupControlOption value="cover" label={ __( 'Cover', 'events-certificates' ) } />
							<ToggleGroupControlOption value="contain" label={ __( 'Contain', 'events-certificates' ) } />
						</ToggleGroupControl>
					</ToolsPanelItem>
				</ToolsPanel>
			) }

			{ tab === 'art' && el.type === 'svg' && <SvgPanel el={ el } design={ design } mode={ mode } onChange={ ( patch ) => onChange( patch as Partial< Element > ) } /> }
			{ tab === 'art' && el.type === 'svg' && ( ! el.slot || el.slot === 'signature' ) && (
				<div className="evcert-inspector__section">
					<ToggleControl
						__nextHasNoMarginBottom
						label={ __( "Signer's signature", 'events-certificates' ) }
						checked={ el.slot === 'signature' }
						onChange={ ( on ) => onChange( { slot: on ? 'signature' : undefined } as Partial< Element > ) }
					/>
				</div>
			) }

			{ tab === 'art' && el.type === 'text' && ( () => {
				// A text that is only fields edits as a placeholder (no chips), so their formats are chosen here.
				const runs = runsOf( el );
				const only = tokenOnly( runs );
				const info = fields();
				const formatted = only ? runs.map( ( r, i ) => ( { r, i } ) ).filter( ( { r } ) => 'field' in r && info[ r.field ]?.formats ) : [];
				return formatted.length ? (
					<div className="evcert-inspector__section">
						{ formatted.map( ( { r, i } ) => {
							const field = ( r as { field: string; format?: string } ).field;
							return (
								<SelectControl
									key={ i }
									__next40pxDefaultSize
									__nextHasNoMarginBottom
									label={ sprintf( /* translators: %s: field name, e.g. Date of issue. */ __( 'Format: %s', 'events-certificates' ), info[ field ].label ) }
									value={ ( r as { format?: string } ).format || info[ field ].formats![ 0 ].key }
									options={ info[ field ].formats!.map( ( f ) => ( { label: `${ f.label } — ${ fieldValue( field, f.key, sample, locale ) }`, value: f.key } ) ) }
									onChange={ ( format ) => onChange( { runs: runs.map( ( x, j ) => ( j === i ? { ...x, format } : x ) ), text: undefined } as Partial< Element > ) }
								/>
							);
						} ) }
					</div>
				) : null;
			} )() }

			{ tab === 'art' && el.type === 'text' && <TextByLanguage el={ el } sample={ sample } onChange={ ( patch ) => onChange( patch as Partial< Element > ) } /> }

			{ tab === 'art' && el.type === 'text' && (
				<ToolsPanel
					label={ __( 'Typography', 'events-certificates' ) }
					resetAll={ () => onChange( { size: 14, lh: 1.2, ls: 0, upper: false, align: 'left', fit: false } ) }
					className="typography-block-support-panel"
				>
					<ToolsPanelItem label={ __( 'Font', 'events-certificates' ) } hasValue={ () => el.font !== 'Inter' } onDeselect={ () => onChange( { font: 'Inter' } ) } isShownByDefault>
						<SelectControl __next40pxDefaultSize __nextHasNoMarginBottom label={ __( 'Font', 'events-certificates' ) } value={ el.font as any } options={ [ { label: 'Inter', value: 'Inter' }, { label: 'Lora', value: 'Lora' }, { label: 'IBM Plex Sans', value: 'CertSans' }, { label: 'Instrument Serif', value: 'CertSerif' } ] } onChange={ ( font ) => onChange( { font } ) } />
					</ToolsPanelItem>
					<ToolsPanelItem label={ __( 'Size', 'events-certificates' ) } hasValue={ () => el.size !== 14 } onDeselect={ () => onChange( { size: 14 } ) } isShownByDefault>
						<FontSizePicker __next40pxDefaultSize fontSizes={ FONT_SIZES } value={ el.size } onChange={ ( size ) => onChange( { size: Number( size ) || 14 } ) } withSlider withReset={ false } units={ [ 'px' ] } />
					</ToolsPanelItem>
					<ToolsPanelItem label={ __( 'Appearance', 'events-certificates' ) } hasValue={ () => el.weight !== 400 } onDeselect={ () => onChange( { weight: 400 } ) } isShownByDefault>
						<SelectControl
							__next40pxDefaultSize
							__nextHasNoMarginBottom
							label={ __( 'Appearance', 'events-certificates' ) }
							value={ String( el.weight ) as any }
							options={ [ { label: __( 'Regular', 'events-certificates' ), value: '400' }, { label: __( 'Medium', 'events-certificates' ), value: '500' }, { label: __( 'Semi Bold', 'events-certificates' ), value: '600' } ] }
							onChange={ ( weight ) => onChange( { weight: Number( weight ) } ) }
						/>
					</ToolsPanelItem>
					<ToolsPanelItem className="single-column" label={ __( 'Line height', 'events-certificates' ) } hasValue={ () => ( el.lh ?? 1.2 ) !== 1.2 } onDeselect={ () => onChange( { lh: 1.2 } ) } isShownByDefault>
						<NumberControl __next40pxDefaultSize label={ __( 'Line height', 'events-certificates' ) } step={ 0.05 } min={ 0.8 } max={ 3 } value={ el.lh ?? 1.2 } onChange={ ( v ) => onChange( { lh: Number( v ) } ) } />
					</ToolsPanelItem>
					<ToolsPanelItem className="single-column" label={ __( 'Letter spacing', 'events-certificates' ) } hasValue={ () => !! el.ls } onDeselect={ () => onChange( { ls: 0 } ) } isShownByDefault>
						<NumberControl __next40pxDefaultSize label={ __( 'Letter spacing', 'events-certificates' ) } step={ 0.05 } value={ el.ls ?? 0 } onChange={ ( v ) => onChange( { ls: Number( v ) } ) } />
					</ToolsPanelItem>
					<ToolsPanelItem label={ __( 'Alignment', 'events-certificates' ) } hasValue={ () => ( el.align || 'left' ) !== 'left' } onDeselect={ () => onChange( { align: 'left' } ) } isShownByDefault>
						<ToggleGroupControl __next40pxDefaultSize __nextHasNoMarginBottom label={ __( 'Alignment', 'events-certificates' ) } value={ el.align || 'left' } onChange={ ( v ) => onChange( { align: v as 'left' } ) }>
							<ToggleGroupControlOptionIcon value="left" icon={ alignLeft } label={ __( 'Left', 'events-certificates' ) } />
							<ToggleGroupControlOptionIcon value="center" icon={ alignCenter } label={ __( 'Center', 'events-certificates' ) } />
							<ToggleGroupControlOptionIcon value="right" icon={ alignRight } label={ __( 'Right', 'events-certificates' ) } />
						</ToggleGroupControl>
					</ToolsPanelItem>
					<ToolsPanelItem label={ __( 'Letter case', 'events-certificates' ) } hasValue={ () => !! el.upper } onDeselect={ () => onChange( { upper: false } ) } isShownByDefault>
						<ToggleGroupControl __next40pxDefaultSize __nextHasNoMarginBottom isDeselectable label={ __( 'Letter case', 'events-certificates' ) } value={ el.upper ? 'uppercase' : 'none' } onChange={ ( v ) => onChange( { upper: v === 'uppercase' } ) }>
							<ToggleGroupControlOptionIcon value="none" icon={ reset } label={ __( 'None', 'events-certificates' ) } />
							<ToggleGroupControlOptionIcon value="uppercase" icon={ formatUppercase } label={ __( 'Uppercase', 'events-certificates' ) } />
						</ToggleGroupControl>
					</ToolsPanelItem>
					<ToolsPanelItem label={ __( 'Shrink to fit', 'events-certificates' ) } hasValue={ () => !! el.fit } onDeselect={ () => onChange( { fit: false } ) } isShownByDefault>
						<ToggleControl __nextHasNoMarginBottom label={ __( 'Shrink to fit (one line)', 'events-certificates' ) } checked={ !! el.fit } onChange={ ( fit ) => onChange( { fit } ) } />
					</ToolsPanelItem>
				</ToolsPanel>
			) }

			{ tab === 'art' && el.type !== 'qr' && el.type !== 'image' && (
				<ToolsPanel label={ __( 'Color', 'events-certificates' ) } resetAll={ () => {} } className="color-block-support-panel">
					<div className="evcert-color-items">
						{ el.type === 'text' && <ColorItem label={ __( 'Text', 'events-certificates' ) } value={ el.color } clearTo="$text" design={ design } mode={ mode } onChange={ ( c ) => onChange( { color: c } ) } /> }
						{ ( el.type === 'rect' || el.type === 'ellipse' || el.type === 'path' ) && <ColorItem label={ __( 'Fill', 'events-certificates' ) } value={ el.fill } design={ design } mode={ mode } onChange={ ( fill ) => onChange( { fill } ) } /> }
						{ el.type === 'svg' && <ColorItem label={ __( 'Current color', 'events-certificates' ) } value={ el.color || '$text' } clearTo="$text" design={ design } mode={ mode } onChange={ ( c ) => onChange( { color: c === '$text' ? undefined : c } as Partial< Element > ) } /> }
						{ el.type !== 'text' && el.type !== 'svg' && <ColorItem label={ el.type === 'line' ? __( 'Line', 'events-certificates' ) : __( 'Stroke', 'events-certificates' ) } value={ ( el as any ).stroke } design={ design } mode={ mode } onChange={ ( stroke ) => onChange( { stroke } as Partial< Element > ) } /> }
					</div>
				</ToolsPanel>
			) }

			{ tab === 'art' && ( el.type === 'rect' || el.type === 'ellipse' || el.type === 'path' || el.type === 'line' ) && (
				<ToolsPanel label={ el.type === 'line' ? __( 'Line', 'events-certificates' ) : __( 'Border', 'events-certificates' ) } resetAll={ () => onChange( { strokeWidth: 1 } as Partial< Element > ) } className="border-block-support-panel">
					<ToolsPanelItem label={ __( 'Width', 'events-certificates' ) } hasValue={ () => true } onDeselect={ () => onChange( { strokeWidth: 1 } as Partial< Element > ) } isShownByDefault>
						<RangeControl __next40pxDefaultSize __nextHasNoMarginBottom label={ __( 'Width', 'events-certificates' ) } value={ ( el as any ).strokeWidth || 0 } min={ 0 } max={ 60 } step={ 0.25 } onChange={ ( v ) => onChange( { strokeWidth: v ?? 0 } as Partial< Element > ) } />
					</ToolsPanelItem>
					{ el.type === 'line' && (
						<ToolsPanelItem label={ __( 'Style', 'events-certificates' ) } hasValue={ () => ( el.dash || 'solid' ) !== 'solid' } onDeselect={ () => onChange( { dash: 'solid' } as Partial< Element > ) } isShownByDefault>
							<ToggleGroupControl __next40pxDefaultSize __nextHasNoMarginBottom isBlock label={ __( 'Style', 'events-certificates' ) } value={ el.dash || 'solid' } onChange={ ( v ) => onChange( { dash: v } as Partial< Element > ) }>
								<ToggleGroupControlOption value="solid" label={ __( 'Solid', 'events-certificates' ) } />
								<ToggleGroupControlOption value="dashed" label={ __( 'Dashed', 'events-certificates' ) } />
								<ToggleGroupControlOption value="dotted" label={ __( 'Dotted', 'events-certificates' ) } />
							</ToggleGroupControl>
						</ToolsPanelItem>
					) }
					{ el.type === 'rect' && (
						<ToolsPanelItem label={ __( 'Radius', 'events-certificates' ) } hasValue={ () => ( el.radius || [] ).some( Boolean ) } onDeselect={ () => onChange( { radius: [ 0, 0, 0, 0 ] } ) } isShownByDefault>
							<div className="evcert-radius">
								<HStack className="evcert-radius__header">
									<span className="evcert-radius__legend">{ __( 'Radius', 'events-certificates' ) }</span>
									<Button size="small" icon={ linked ? link : linkOff } label={ linked ? __( 'Unlink radii', 'events-certificates' ) : __( 'Link radii', 'events-certificates' ) } onClick={ () => setLinked( ! linked ) } />
								</HStack>
								{ linked ? (
									<HStack className="evcert-radius__linked" alignment="center">
										<UnitControl
											__next40pxDefaultSize
											label={ __( 'All corners', 'events-certificates' ) }
											hideLabelFromVision
											units={ PT }
											min={ 0 }
											value={ `${ ( el.radius || [ 0 ] )[ 0 ] || 0 }pt` }
											onChange={ ( v ) => {
												const n = parseFloat( v || '0' ) || 0;
												onChange( { radius: [ n, n, n, n ] } );
											} }
										/>
										<RangeControl __next40pxDefaultSize __nextHasNoMarginBottom label={ __( 'All corners', 'events-certificates' ) } hideLabelFromVision withInputField={ false } value={ ( el.radius || [ 0 ] )[ 0 ] || 0 } min={ 0 } max={ Math.round( Math.min( el.w, el.h ) / 2 ) } onChange={ ( v ) => onChange( { radius: [ v ?? 0, v ?? 0, v ?? 0, v ?? 0 ] } ) } />
									</HStack>
								) : (
									<div className="evcert-radius__corners">
										{ [ cornerTopLeft, cornerTopRight, cornerBottomRight, cornerBottomLeft ].map( ( icon, i ) => (
											<HStack key={ i }>
												<Icon icon={ icon } />
												<UnitControl
													__next40pxDefaultSize
													label={ [ __( 'Top left', 'events-certificates' ), __( 'Top right', 'events-certificates' ), __( 'Bottom right', 'events-certificates' ), __( 'Bottom left', 'events-certificates' ) ][ i ] }
													hideLabelFromVision
													units={ PT }
													min={ 0 }
													value={ `${ ( el.radius || [ 0, 0, 0, 0 ] )[ i ] || 0 }pt` }
													onChange={ ( v ) => {
														const radius = [ ...( el.radius || [ 0, 0, 0, 0 ] ) ];
														radius[ i ] = parseFloat( v || '0' ) || 0;
														onChange( { radius } );
													} }
												/>
											</HStack>
										) ) }
									</div>
								) }
							</div>
						</ToolsPanelItem>
					) }
					{ el.type === 'rect' && (
						<ToolsPanelItem label={ __( 'Corner shape', 'events-certificates' ) } hasValue={ () => ( el.corner || 'round' ) !== 'round' } onDeselect={ () => onChange( { corner: 'round' } ) } isShownByDefault>
							<SelectControl
								__next40pxDefaultSize
								__nextHasNoMarginBottom
								label={ __( 'Corner shape', 'events-certificates' ) }
								value={ ( el.corner || 'round' ) as any }
								options={ [
									{ label: __( 'Round', 'events-certificates' ), value: 'round' },
									{ label: __( 'Scoop', 'events-certificates' ), value: 'scoop' },
									{ label: __( 'Bevel', 'events-certificates' ), value: 'bevel' },
									{ label: __( 'Notch', 'events-certificates' ), value: 'notch' },
									{ label: __( 'Squircle', 'events-certificates' ), value: 'squircle' },
								] }
								onChange={ ( corner ) => onChange( { corner: corner as 'round' } ) }
							/>
						</ToolsPanelItem>
					) }
				</ToolsPanel>
			) }

			{ tab === 'art' && (
				<ToolsPanel label={ __( 'Dimensions', 'events-certificates' ) } resetAll={ () => onChange( { rotation: 0, opacity: 1 } ) } className="dimensions-block-support-panel">
					{ el.type !== 'line' && (
						<ToolsPanelItem label={ __( 'Position and size', 'events-certificates' ) } hasValue={ () => !! stored?.layout } onDeselect={ () => onChange( { layout: undefined, x: el.x, y: el.y, w: el.w, h: el.h } as Partial< Element > ) } isShownByDefault>
							<Anchors el={ el } stored={ stored || el } page={ design.page } onChange={ onChange } />
						</ToolsPanelItem>
					) }
					{ el.type !== 'line' && (
						<ToolsPanelItem label={ __( 'Rotation', 'events-certificates' ) } hasValue={ () => !! el.rotation } onDeselect={ () => onChange( { rotation: 0 } ) } isShownByDefault>
							<ToggleGroupControl __next40pxDefaultSize __nextHasNoMarginBottom isBlock label={ __( 'Rotation', 'events-certificates' ) } value={ String( el.rotation || 0 ) } onChange={ ( v ) => onChange( { rotation: Number( v ) } ) }>
								{ [ '0', '90', '180', '270' ].map( ( d ) => (
									<ToggleGroupControlOption key={ d } value={ d } label={ `${ d }°` } />
								) ) }
							</ToggleGroupControl>
						</ToolsPanelItem>
					) }
					<ToolsPanelItem label={ __( 'Opacity', 'events-certificates' ) } hasValue={ () => ( el.opacity ?? 1 ) !== 1 } onDeselect={ () => onChange( { opacity: 1 } ) } isShownByDefault>
						<RangeControl __next40pxDefaultSize __nextHasNoMarginBottom label={ __( 'Opacity', 'events-certificates' ) } value={ Math.round( ( el.opacity ?? 1 ) * 100 ) } min={ 0 } max={ 100 } onChange={ ( v ) => onChange( { opacity: ( v ?? 100 ) / 100 } ) } />
					</ToolsPanelItem>
				</ToolsPanel>
			) }
		</div>
	);
}

const AXIS_LABELS = () => ( {
	h: {
		start: [ __( 'Left', 'events-certificates' ), __( 'Width', 'events-certificates' ) ],
		center: [ __( 'Offset', 'events-certificates' ), __( 'Width', 'events-certificates' ) ],
		end: [ __( 'Right', 'events-certificates' ), __( 'Width', 'events-certificates' ) ],
		stretch: [ __( 'Left', 'events-certificates' ), __( 'Right', 'events-certificates' ) ],
	},
	v: {
		start: [ __( 'Top', 'events-certificates' ), __( 'Height', 'events-certificates' ) ],
		center: [ __( 'Offset', 'events-certificates' ), __( 'Height', 'events-certificates' ) ],
		end: [ __( 'Bottom', 'events-certificates' ), __( 'Height', 'events-certificates' ) ],
		stretch: [ __( 'Top', 'events-certificates' ), __( 'Bottom', 'events-certificates' ) ],
	},
} );

const LEN_UNITS = [
	{ value: 'pt', label: 'pt', step: 1 },
	{ value: 'mm', label: 'mm', step: 1 },
	{ value: 'cm', label: 'cm', step: 0.1 },
	{ value: 'in', label: 'in', step: 0.01 },
	{ value: '%', label: '%', step: 1 },
];
const SIMPLE = /^-?\d*\.?\d+(pt|mm|cm|in|%)$/;

/**
 * A length: a value and a unit, like the block editor's dimension fields
 * (UnitControl). The settings button switches to a custom value that also
 * takes calc(), like the spacing controls' "Set custom size".
 */
function LengthField( { label, value, total, onChange, locked }: { label: string; value: string; total: number; onChange: ( v: string ) => void; locked?: boolean } ) {
	const [ custom, setCustom ] = useState( ! SIMPLE.test( value ) );
	const [ text, setText ] = useState( value );
	const [ focused, setFocused ] = useState( false );
	const shown = focused ? text : value;
	const valid = isLen( shown );
	const commitText = () => {
		if ( isLen( text ) && text !== value ) {
			onChange( /^-?\d*\.?\d+$/.test( text.trim() ) ? `${ text.trim() }pt` : text.trim() );
		}
	};
	const toggle = (
		<Button
			size="small"
			icon={ settings }
			isPressed={ custom }
			label={ custom ? __( 'Use a value and unit', 'events-certificates' ) : __( 'Set custom value (calc)', 'events-certificates' ) }
			onClick={ () => {
				if ( custom && ! SIMPLE.test( value ) ) {
					onChange( `${ Math.round( parseLen( value, total ) * 100 ) / 100 }pt` );
				}
				setCustom( ! custom );
			} }
		/>
	);
	return (
		<div className="evcert-length">
			<HStack className="evcert-length__header">
				<span className="evcert-length__label">{ label }</span>
				{ ! locked && toggle }
			</HStack>
			{ locked ? (
				// Follows the other size (aspect ratio kept): shown, not edited.
				<UnitControl __next40pxDefaultSize label={ label } hideLabelFromVision units={ LEN_UNITS } value={ value } disabled onChange={ () => undefined } />
			) : custom ? (
				<TextControl
					__next40pxDefaultSize
					__nextHasNoMarginBottom
					label={ label }
					hideLabelFromVision
					value={ shown }
					className={ valid ? '' : 'is-invalid' }
					help={ valid ? `= ${ Math.round( parseLen( shown, total ) * 10 ) / 10 } pt` : __( 'Use pt, mm, % or calc(100% - 20pt).', 'events-certificates' ) }
					onFocus={ () => { setText( value ); setFocused( true ); } }
					onBlur={ () => { commitText(); setFocused( false ); } }
					onKeyDown={ ( event: { key: string } ) => event.key === 'Enter' && commitText() }
					onChange={ setText }
				/>
			) : (
				<UnitControl
					__next40pxDefaultSize
					label={ label }
					hideLabelFromVision
					units={ LEN_UNITS }
					value={ value }
					onChange={ ( v ) => v && SIMPLE.test( v ) && v !== value && onChange( v ) }
				/>
			) }
		</div>
	);
}

const round2 = ( n: number ) => Math.round( n * 100 ) / 100;

/** Anchors per axis (start, centre, end, stretch) with open length fields, relative to the page or the safe margin. */
function Anchors( { el, stored, page, onChange }: { el: Element; stored: Element; page: Design[ 'page' ]; onChange: ( patch: Partial< Element > ) => void } ) {
	const labels = AXIS_LABELS();
	const b = { x: el.x as number, y: el.y as number, w: el.w as number, h: el.h as number };
	const layout: Layout = stored.layout || layoutFrom( { h: { mode: 'start' }, v: { mode: 'start' }, ref: 'page' }, b, page );
	const ref = refBox( page, layout.ref );
	const set = ( next: Layout ) => onChange( { layout: next } as Partial< Element > );
	const setMode = ( axis: 'h' | 'v', mode: AxisMode ) => set( layoutFrom( { ...layout, [ axis ]: { mode } }, b, page ) );
	const setRef = ( r: 'page' | 'safe' ) => set( layoutFrom( { ...layout, ref: r }, b, page ) );
	const ratio = lockedRatio( stored );
	const side = ratio ? lockedSide( layout ) : null;
	const field = ( axis: 'h' | 'v', key: 'start' | 'end' | 'size', label: string ) => {
		const follows = key === 'size' && side === ( axis === 'h' ? 'w' : 'h' );
		return (
			<LengthField
				key={ `${ axis }-${ key }-${ follows }` }
				label={ label }
				value={ follows ? `${ Math.round( ( axis === 'h' ? b.w : b.h ) * 100 ) / 100 }pt` : layout[ axis ][ key ] || '0pt' }
				total={ axis === 'h' ? ref.w : ref.h }
				locked={ follows }
				onChange={ ( v ) => set( { ...layout, [ axis ]: { ...layout[ axis ], [ key ]: v } } ) }
			/>
		);
	};
	const fields = ( axis: 'h' | 'v' ) => {
		const mode = layout[ axis ].mode;
		const [ first, second ] = labels[ axis ][ mode ];
		return (
			<div className="evcert-grid-2">
				{ field( axis, mode === 'end' ? 'end' : 'start', first ) }
				{ field( axis, mode === 'stretch' ? 'end' : 'size', second ) }
			</div>
		);
	};
	// Images keep their ratio from Settings > Aspect ratio; the other elements lock here.
	const canLock = stored.type === 'svg' || stored.type === 'rect' || stored.type === 'ellipse' || stored.type === 'qr';
	const toggleLock = () => {
		if ( stored.lock ) {
			onChange( { lock: undefined } as Partial< Element > );
			return;
		}
		const lock = stored.type === 'svg' && stored.vw && stored.vh ? stored.vw / stored.vh : b.w / b.h;
		onChange( ( stored.layout ? { lock } : { lock, h: b.w / lock } ) as Partial< Element > );
	};
	// Fill the reference box (page or margin, as chosen above) along one axis or both; Fit keeps the aspect ratio inside it.
	const whole: Axis = { mode: 'stretch', start: '0pt', end: '0pt' };
	const fill = ( axes: Array< 'h' | 'v' > ) => set( { ...layout, ...Object.fromEntries( axes.map( ( a ) => [ a, whole ] ) ) } );
	const fit = () => {
		if ( ! ratio ) {
			return;
		}
		const r = round2;
		set(
			ref.w / ref.h > ratio
				? { ...layout, v: whole, h: { mode: 'center', start: '0pt', size: `${ r( ref.h * ratio ) }pt` } }
				: { ...layout, h: whole, v: { mode: 'center', start: '0pt', size: `${ r( ref.w / ratio ) }pt` } }
		);
	};
	const fills = { h: layout.h.mode === 'stretch', v: layout.v.mode === 'stretch' };
	return (
		<div className="evcert-anchors">
			<ToggleGroupControl __next40pxDefaultSize __nextHasNoMarginBottom isBlock label={ __( 'Relative to', 'events-certificates' ) } value={ layout.ref || 'page' } onChange={ ( v ) => setRef( v as 'page' ) }>
				<ToggleGroupControlOption value="page" label={ __( 'Page', 'events-certificates' ) } />
				<ToggleGroupControlOption value="safe" label={ __( 'Margin', 'events-certificates' ) } aria-label={ __( 'Safe margin', 'events-certificates' ) } showTooltip />
			</ToggleGroupControl>
			<ToggleGroupControl
				__next40pxDefaultSize
				__nextHasNoMarginBottom
				isBlock
				className="evcert-anchors__fill"
				label={ _x( 'Fill', 'size: fill the page or margin', 'events-certificates' ) }
				value={ fills.h && fills.v ? 'both' : fills.h ? 'h' : fills.v ? 'v' : '' }
				onChange={ ( v ) => ( v === 'fit' ? fit() : fill( v === 'both' ? [ 'h', 'v' ] : [ v as 'h' | 'v' ] ) ) }
			>
				<ToggleGroupControlOption value="h" label={ __( 'Width', 'events-certificates' ) } />
				<ToggleGroupControlOption value="v" label={ __( 'Height', 'events-certificates' ) } />
				<ToggleGroupControlOption value="both" label={ __( 'Both', 'events-certificates' ) } />
				{ ratio && <ToggleGroupControlOption value="fit" label={ __( 'Fit', 'events-certificates' ) } aria-label={ __( 'As large as fits, keeping the aspect ratio', 'events-certificates' ) } showTooltip /> }
			</ToggleGroupControl>
			<ToggleGroupControl __next40pxDefaultSize __nextHasNoMarginBottom label={ __( 'Horizontal', 'events-certificates' ) } value={ layout.h.mode } onChange={ ( v ) => setMode( 'h', v as AxisMode ) }>
				<ToggleGroupControlOptionIcon value="start" icon={ justifyLeft } label={ __( 'Left', 'events-certificates' ) } />
				<ToggleGroupControlOptionIcon value="center" icon={ justifyCenter } label={ __( 'Center', 'events-certificates' ) } />
				<ToggleGroupControlOptionIcon value="end" icon={ justifyRight } label={ __( 'Right', 'events-certificates' ) } />
				<ToggleGroupControlOptionIcon value="stretch" icon={ justifyStretch } label={ __( 'Fill', 'events-certificates' ) } />
			</ToggleGroupControl>
			{ fields( 'h' ) }
			<ToggleGroupControl __next40pxDefaultSize __nextHasNoMarginBottom label={ __( 'Vertical', 'events-certificates' ) } value={ layout.v.mode } onChange={ ( v ) => setMode( 'v', v as AxisMode ) }>
				<ToggleGroupControlOptionIcon value="start" icon={ justifyTop } label={ __( 'Top', 'events-certificates' ) } />
				<ToggleGroupControlOptionIcon value="center" icon={ justifyCenterVertical } label={ __( 'Middle', 'events-certificates' ) } />
				<ToggleGroupControlOptionIcon value="end" icon={ justifyBottom } label={ __( 'Bottom', 'events-certificates' ) } />
				<ToggleGroupControlOptionIcon value="stretch" icon={ justifyStretchVertical } label={ __( 'Fill', 'events-certificates' ) } />
			</ToggleGroupControl>
			{ fields( 'v' ) }
			{ canLock && (
				<Button
					className="evcert-anchors__lock"
					variant="tertiary"
					size="compact"
					icon={ stored.lock ? link : linkOff }
					isPressed={ !! stored.lock }
					onClick={ toggleLock }
				>
					{ stored.lock ? __( 'Aspect ratio locked', 'events-certificates' ) : __( 'Lock aspect ratio', 'events-certificates' ) }
				</Button>
			) }
		</div>
	);
}
