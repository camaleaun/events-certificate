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
import { __ } from '@wordpress/i18n';
import { justifyBottom, justifyCenter, justifyCenterVertical, justifyLeft, justifyRight, justifyStretch, justifyStretchVertical, justifyTop, alignCenter, alignLeft, alignRight, cornerBottomLeft, cornerBottomRight, cornerTopLeft, cornerTopRight, formatUppercase, Icon, link, linkOff, reset, settings } from '@wordpress/icons';
import { elementIcon } from './icons';
import { naturalSize, type Picked } from './media';
import SvgPanel from './SvgPanel';
import { box as boxOf, color, isLen, layoutFrom, parseLen, refBox, REQUIRED_SLOTS, type AxisMode, type Box, type Layout, type Design, type Element, type Mode } from './model';

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
	onEditDrawing: () => void;
};

const FONT_SIZES = [ 8, 10, 12, 14, 16, 18, 24, 32, 42 ].map( ( size ) => ( { name: `${ size } pt`, slug: String( size ), size } ) );
const num = ( v: number ) => Math.round( v * 100 ) / 100;
const PT = [ { value: 'pt', label: 'pt', default: 0 } ];

/** A color item like the block editor's ColorGradientDropdown (indicator + name, a palette in a dropdown). */
export function ColorItem( { label, value, design, mode, onChange }: { label: string; value?: string; design: Design; mode: Mode; onChange: ( v: string ) => void } ) {
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
						<span className="evcert-color-item__value">{ variable ? variable.name : value || __( 'None', 'events-certificate' ) }</span>
					</HStack>
				</Button>
			) }
			renderContent={ () => (
				<div className="evcert-color-popover">
					<p className="evcert-color-popover__label">{ __( 'Theme', 'events-certificate' ) }</p>
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
					<p className="evcert-color-popover__label">{ __( 'Custom', 'events-certificate' ) }</p>
					<ColorPalette value={ value?.startsWith( '$' ) ? undefined : value } onChange={ ( c ) => onChange( c || '' ) } clearable />
				</div>
			) }
		/>
	);
}

export default function Inspector( { el, stored, design, mode, tab, locale, sample, onChange, onImage, onBox, onEditDrawing }: Props ) {
	const [ linked, setLinked ] = useState( true );
	const required = !! el.slot && REQUIRED_SLOTS.includes( el.slot );
	const typeLabel = {
		text: __( 'Text', 'events-certificate' ),
		line: __( 'Line', 'events-certificate' ),
		rect: __( 'Rectangle', 'events-certificate' ),
		ellipse: __( 'Ellipse', 'events-certificate' ),
		path: __( 'Shape', 'events-certificate' ),
		image: __( 'Image', 'events-certificate' ),
		svg: __( 'SVG', 'events-certificate' ),
		qr: __( 'Authenticity', 'events-certificate' ),
	}[ el.type ];
	const description = required
		? __( 'Required on every certificate: it can be moved and styled, not deleted.', 'events-certificate' )
		: el.type === 'qr'
		? __( 'The QR code and the code anyone can check.', 'events-certificate' )
		: el.type === 'text'
		? __( 'Text with fields that each certificate fills in.', 'events-certificate' )
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

			{ el.type === 'text' && (
				<div className="evcert-inspector__section evcert-meta">{ __( 'Click the selected text again (or press Enter) to edit it on the canvas. Fields are inserted from the toolbar. Locked texts can still be edited, not moved.', 'events-certificate' ) }</div>
			) }

			{ tab === 'art' && el.type === 'image' && (
				<ToolsPanel label={ __( 'Settings', 'events-certificate' ) } resetAll={ () => onChange( { ratio: 'auto', fit: 'cover' } as Partial< Element > ) }>
					<ToolsPanelItem label={ __( 'Alternative text', 'events-certificate' ) } hasValue={ () => !! el.alt } onDeselect={ () => onChange( { alt: '' } as Partial< Element > ) } isShownByDefault>
						<TextareaControl
							__nextHasNoMarginBottom
							label={ __( 'Alternative text', 'events-certificate' ) }
							help={ __( 'Describe the purpose of the image. Read aloud on the certificate page; leave empty if the image is purely decorative.', 'events-certificate' ) }
							value={ el.alt || '' }
							onChange={ ( alt ) => onChange( { alt } as Partial< Element > ) }
						/>
					</ToolsPanelItem>
					<ToolsPanelItem label={ __( 'Aspect ratio', 'events-certificate' ) } hasValue={ () => ( el.ratio || 'auto' ) !== 'auto' } onDeselect={ () => onChange( { ratio: 'auto' } as Partial< Element > ) } isShownByDefault>
						<SelectControl
							__next40pxDefaultSize
							__nextHasNoMarginBottom
							label={ __( 'Aspect ratio', 'events-certificate' ) }
							value={ ( el.ratio || 'auto' ) as any }
							options={ [
								{ label: __( 'Original', 'events-certificate' ), value: 'auto' },
								{ label: __( 'Square - 1:1', 'events-certificate' ), value: '1' },
								{ label: __( 'Standard - 4:3', 'events-certificate' ), value: '4/3' },
								{ label: __( 'Portrait - 3:4', 'events-certificate' ), value: '3/4' },
								{ label: __( 'Classic - 3:2', 'events-certificate' ), value: '3/2' },
								{ label: __( 'Classic Portrait - 2:3', 'events-certificate' ), value: '2/3' },
								{ label: __( 'Wide - 16:9', 'events-certificate' ), value: '16/9' },
								{ label: __( 'Tall - 9:16', 'events-certificate' ), value: '9/16' },
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
					<ToolsPanelItem label={ __( 'Scale', 'events-certificate' ) } hasValue={ () => el.fit === 'contain' } onDeselect={ () => onChange( { fit: 'cover' } as Partial< Element > ) } isShownByDefault>
						<ToggleGroupControl
							__next40pxDefaultSize
							__nextHasNoMarginBottom
							isBlock
							label={ __( 'Scale', 'events-certificate' ) }
							value={ el.fit || 'cover' }
							help={ el.fit === 'contain' ? __( 'Image is contained without distortion.', 'events-certificate' ) : __( 'Image covers the space evenly.', 'events-certificate' ) }
							onChange={ ( fit ) => onChange( { fit } as Partial< Element > ) }
						>
							<ToggleGroupControlOption value="cover" label={ __( 'Cover', 'events-certificate' ) } />
							<ToggleGroupControlOption value="contain" label={ __( 'Contain', 'events-certificate' ) } />
						</ToggleGroupControl>
					</ToolsPanelItem>
				</ToolsPanel>
			) }

			{ tab === 'art' && el.type === 'svg' && <SvgPanel el={ el } design={ design } mode={ mode } onChange={ ( patch ) => onChange( patch as Partial< Element > ) } onOpen={ onEditDrawing } /> }

			{ tab === 'art' && el.type === 'text' && (
				<ToolsPanel
					label={ __( 'Typography', 'events-certificate' ) }
					resetAll={ () => onChange( { size: 14, lh: 1.2, ls: 0, upper: false, align: 'left', fit: false } ) }
					className="typography-block-support-panel"
				>
					<ToolsPanelItem label={ __( 'Font', 'events-certificate' ) } hasValue={ () => el.font !== 'Inter' } onDeselect={ () => onChange( { font: 'Inter' } ) } isShownByDefault>
						<SelectControl __next40pxDefaultSize __nextHasNoMarginBottom label={ __( 'Font', 'events-certificate' ) } value={ el.font as any } options={ [ { label: 'Inter', value: 'Inter' }, { label: 'Lora', value: 'Lora' } ] } onChange={ ( font ) => onChange( { font } ) } />
					</ToolsPanelItem>
					<ToolsPanelItem label={ __( 'Size', 'events-certificate' ) } hasValue={ () => el.size !== 14 } onDeselect={ () => onChange( { size: 14 } ) } isShownByDefault>
						<FontSizePicker __next40pxDefaultSize fontSizes={ FONT_SIZES } value={ el.size } onChange={ ( size ) => onChange( { size: Number( size ) || 14 } ) } withSlider withReset={ false } units={ [ 'px' ] } />
					</ToolsPanelItem>
					<ToolsPanelItem label={ __( 'Appearance', 'events-certificate' ) } hasValue={ () => el.weight !== 400 } onDeselect={ () => onChange( { weight: 400 } ) } isShownByDefault>
						<SelectControl
							__next40pxDefaultSize
							__nextHasNoMarginBottom
							label={ __( 'Appearance', 'events-certificate' ) }
							value={ String( el.weight ) as any }
							options={ [ { label: __( 'Regular', 'events-certificate' ), value: '400' }, { label: __( 'Medium', 'events-certificate' ), value: '500' }, { label: __( 'Semi Bold', 'events-certificate' ), value: '600' } ] }
							onChange={ ( weight ) => onChange( { weight: Number( weight ) } ) }
						/>
					</ToolsPanelItem>
					<ToolsPanelItem className="single-column" label={ __( 'Line height', 'events-certificate' ) } hasValue={ () => ( el.lh ?? 1.2 ) !== 1.2 } onDeselect={ () => onChange( { lh: 1.2 } ) } isShownByDefault>
						<NumberControl __next40pxDefaultSize label={ __( 'Line height', 'events-certificate' ) } step={ 0.05 } min={ 0.8 } max={ 3 } value={ el.lh ?? 1.2 } onChange={ ( v ) => onChange( { lh: Number( v ) } ) } />
					</ToolsPanelItem>
					<ToolsPanelItem className="single-column" label={ __( 'Letter spacing', 'events-certificate' ) } hasValue={ () => !! el.ls } onDeselect={ () => onChange( { ls: 0 } ) } isShownByDefault>
						<NumberControl __next40pxDefaultSize label={ __( 'Letter spacing', 'events-certificate' ) } step={ 0.05 } value={ el.ls ?? 0 } onChange={ ( v ) => onChange( { ls: Number( v ) } ) } />
					</ToolsPanelItem>
					<ToolsPanelItem label={ __( 'Alignment', 'events-certificate' ) } hasValue={ () => ( el.align || 'left' ) !== 'left' } onDeselect={ () => onChange( { align: 'left' } ) } isShownByDefault>
						<ToggleGroupControl __next40pxDefaultSize __nextHasNoMarginBottom label={ __( 'Alignment', 'events-certificate' ) } value={ el.align || 'left' } onChange={ ( v ) => onChange( { align: v as 'left' } ) }>
							<ToggleGroupControlOptionIcon value="left" icon={ alignLeft } label={ __( 'Left', 'events-certificate' ) } />
							<ToggleGroupControlOptionIcon value="center" icon={ alignCenter } label={ __( 'Center', 'events-certificate' ) } />
							<ToggleGroupControlOptionIcon value="right" icon={ alignRight } label={ __( 'Right', 'events-certificate' ) } />
						</ToggleGroupControl>
					</ToolsPanelItem>
					<ToolsPanelItem label={ __( 'Letter case', 'events-certificate' ) } hasValue={ () => !! el.upper } onDeselect={ () => onChange( { upper: false } ) } isShownByDefault>
						<ToggleGroupControl __next40pxDefaultSize __nextHasNoMarginBottom isDeselectable label={ __( 'Letter case', 'events-certificate' ) } value={ el.upper ? 'uppercase' : 'none' } onChange={ ( v ) => onChange( { upper: v === 'uppercase' } ) }>
							<ToggleGroupControlOptionIcon value="none" icon={ reset } label={ __( 'None', 'events-certificate' ) } />
							<ToggleGroupControlOptionIcon value="uppercase" icon={ formatUppercase } label={ __( 'Uppercase', 'events-certificate' ) } />
						</ToggleGroupControl>
					</ToolsPanelItem>
					<ToolsPanelItem label={ __( 'Shrink to fit', 'events-certificate' ) } hasValue={ () => !! el.fit } onDeselect={ () => onChange( { fit: false } ) } isShownByDefault>
						<ToggleControl __nextHasNoMarginBottom label={ __( 'Shrink to fit (one line)', 'events-certificate' ) } help={ __( 'For names: long ones get smaller instead of wrapping.', 'events-certificate' ) } checked={ !! el.fit } onChange={ ( fit ) => onChange( { fit } ) } />
					</ToolsPanelItem>
				</ToolsPanel>
			) }

			{ tab === 'art' && el.type !== 'qr' && el.type !== 'image' && (
				<ToolsPanel label={ __( 'Color', 'events-certificate' ) } resetAll={ () => {} } className="color-block-support-panel">
					<div className="evcert-color-items">
						{ el.type === 'text' && <ColorItem label={ __( 'Text', 'events-certificate' ) } value={ el.color } design={ design } mode={ mode } onChange={ ( c ) => onChange( { color: c } ) } /> }
						{ ( el.type === 'rect' || el.type === 'ellipse' || el.type === 'path' ) && <ColorItem label={ __( 'Fill', 'events-certificate' ) } value={ el.fill } design={ design } mode={ mode } onChange={ ( fill ) => onChange( { fill } ) } /> }
						{ el.type === 'svg' && <ColorItem label={ __( 'Current color', 'events-certificate' ) } value={ el.color || '$text' } design={ design } mode={ mode } onChange={ ( c ) => onChange( { color: c } as Partial< Element > ) } /> }
						{ el.type !== 'text' && el.type !== 'svg' && <ColorItem label={ el.type === 'line' ? __( 'Line', 'events-certificate' ) : __( 'Stroke', 'events-certificate' ) } value={ ( el as any ).stroke } design={ design } mode={ mode } onChange={ ( stroke ) => onChange( { stroke } as Partial< Element > ) } /> }
					</div>
				</ToolsPanel>
			) }

			{ tab === 'art' && ( el.type === 'rect' || el.type === 'ellipse' || el.type === 'path' || el.type === 'line' ) && (
				<ToolsPanel label={ el.type === 'line' ? __( 'Line', 'events-certificate' ) : __( 'Border', 'events-certificate' ) } resetAll={ () => onChange( { strokeWidth: 1 } as Partial< Element > ) } className="border-block-support-panel">
					<ToolsPanelItem label={ __( 'Width', 'events-certificate' ) } hasValue={ () => true } onDeselect={ () => onChange( { strokeWidth: 1 } as Partial< Element > ) } isShownByDefault>
						<RangeControl __next40pxDefaultSize __nextHasNoMarginBottom label={ __( 'Width', 'events-certificate' ) } value={ ( el as any ).strokeWidth || 0 } min={ 0 } max={ 60 } step={ 0.25 } onChange={ ( v ) => onChange( { strokeWidth: v ?? 0 } as Partial< Element > ) } />
					</ToolsPanelItem>
					{ el.type === 'line' && (
						<ToolsPanelItem label={ __( 'Style', 'events-certificate' ) } hasValue={ () => ( el.dash || 'solid' ) !== 'solid' } onDeselect={ () => onChange( { dash: 'solid' } as Partial< Element > ) } isShownByDefault>
							<ToggleGroupControl __next40pxDefaultSize __nextHasNoMarginBottom isBlock label={ __( 'Style', 'events-certificate' ) } value={ el.dash || 'solid' } onChange={ ( v ) => onChange( { dash: v } as Partial< Element > ) }>
								<ToggleGroupControlOption value="solid" label={ __( 'Solid', 'events-certificate' ) } />
								<ToggleGroupControlOption value="dashed" label={ __( 'Dashed', 'events-certificate' ) } />
								<ToggleGroupControlOption value="dotted" label={ __( 'Dotted', 'events-certificate' ) } />
							</ToggleGroupControl>
						</ToolsPanelItem>
					) }
					{ el.type === 'rect' && (
						<ToolsPanelItem label={ __( 'Radius', 'events-certificate' ) } hasValue={ () => ( el.radius || [] ).some( Boolean ) } onDeselect={ () => onChange( { radius: [ 0, 0, 0, 0 ] } ) } isShownByDefault>
							<div className="evcert-radius">
								<HStack className="evcert-radius__header">
									<span className="evcert-radius__legend">{ __( 'Radius', 'events-certificate' ) }</span>
									<Button size="small" icon={ linked ? link : linkOff } label={ linked ? __( 'Unlink radii', 'events-certificate' ) : __( 'Link radii', 'events-certificate' ) } onClick={ () => setLinked( ! linked ) } />
								</HStack>
								{ linked ? (
									<HStack className="evcert-radius__linked" alignment="center">
										<UnitControl
											__next40pxDefaultSize
											label={ __( 'All corners', 'events-certificate' ) }
											hideLabelFromVision
											units={ PT }
											min={ 0 }
											value={ `${ ( el.radius || [ 0 ] )[ 0 ] || 0 }pt` }
											onChange={ ( v ) => {
												const n = parseFloat( v || '0' ) || 0;
												onChange( { radius: [ n, n, n, n ] } );
											} }
										/>
										<RangeControl __next40pxDefaultSize __nextHasNoMarginBottom label={ __( 'All corners', 'events-certificate' ) } hideLabelFromVision withInputField={ false } value={ ( el.radius || [ 0 ] )[ 0 ] || 0 } min={ 0 } max={ Math.round( Math.min( el.w, el.h ) / 2 ) } onChange={ ( v ) => onChange( { radius: [ v ?? 0, v ?? 0, v ?? 0, v ?? 0 ] } ) } />
									</HStack>
								) : (
									<div className="evcert-radius__corners">
										{ [ cornerTopLeft, cornerTopRight, cornerBottomRight, cornerBottomLeft ].map( ( icon, i ) => (
											<HStack key={ i }>
												<Icon icon={ icon } />
												<UnitControl
													__next40pxDefaultSize
													label={ [ __( 'Top left', 'events-certificate' ), __( 'Top right', 'events-certificate' ), __( 'Bottom right', 'events-certificate' ), __( 'Bottom left', 'events-certificate' ) ][ i ] }
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
						<ToolsPanelItem label={ __( 'Corner shape', 'events-certificate' ) } hasValue={ () => ( el.corner || 'round' ) !== 'round' } onDeselect={ () => onChange( { corner: 'round' } ) } isShownByDefault>
							<SelectControl
								__next40pxDefaultSize
								__nextHasNoMarginBottom
								label={ __( 'Corner shape', 'events-certificate' ) }
								value={ ( el.corner || 'round' ) as any }
								options={ [
									{ label: __( 'Round', 'events-certificate' ), value: 'round' },
									{ label: __( 'Scoop', 'events-certificate' ), value: 'scoop' },
									{ label: __( 'Bevel', 'events-certificate' ), value: 'bevel' },
									{ label: __( 'Notch', 'events-certificate' ), value: 'notch' },
									{ label: __( 'Squircle', 'events-certificate' ), value: 'squircle' },
								] }
								onChange={ ( corner ) => onChange( { corner: corner as 'round' } ) }
							/>
						</ToolsPanelItem>
					) }
				</ToolsPanel>
			) }

			{ tab === 'art' && (
				<ToolsPanel label={ __( 'Dimensions', 'events-certificate' ) } resetAll={ () => onChange( { rotation: 0, opacity: 1 } ) } className="dimensions-block-support-panel">
					{ el.type !== 'line' && (
						<ToolsPanelItem label={ __( 'Position and size', 'events-certificate' ) } hasValue={ () => !! stored?.layout } onDeselect={ () => onChange( { layout: undefined, x: el.x, y: el.y, w: el.w, h: el.h } as Partial< Element > ) } isShownByDefault>
							<Anchors el={ el } stored={ stored || el } page={ design.page } onChange={ onChange } />
						</ToolsPanelItem>
					) }
					{ el.type !== 'line' && (
						<ToolsPanelItem label={ __( 'Rotation', 'events-certificate' ) } hasValue={ () => !! el.rotation } onDeselect={ () => onChange( { rotation: 0 } ) } isShownByDefault>
							<ToggleGroupControl __next40pxDefaultSize __nextHasNoMarginBottom isBlock label={ __( 'Rotation', 'events-certificate' ) } value={ String( el.rotation || 0 ) } onChange={ ( v ) => onChange( { rotation: Number( v ) } ) }>
								{ [ '0', '90', '180', '270' ].map( ( d ) => (
									<ToggleGroupControlOption key={ d } value={ d } label={ `${ d }°` } />
								) ) }
							</ToggleGroupControl>
						</ToolsPanelItem>
					) }
					<ToolsPanelItem label={ __( 'Opacity', 'events-certificate' ) } hasValue={ () => ( el.opacity ?? 1 ) !== 1 } onDeselect={ () => onChange( { opacity: 1 } ) } isShownByDefault>
						<RangeControl __next40pxDefaultSize __nextHasNoMarginBottom label={ __( 'Opacity', 'events-certificate' ) } value={ Math.round( ( el.opacity ?? 1 ) * 100 ) } min={ 0 } max={ 100 } onChange={ ( v ) => onChange( { opacity: ( v ?? 100 ) / 100 } ) } />
					</ToolsPanelItem>
				</ToolsPanel>
			) }
		</div>
	);
}

const AXIS_LABELS = () => ( {
	h: {
		start: [ __( 'Left', 'events-certificate' ), __( 'Width', 'events-certificate' ) ],
		center: [ __( 'Offset', 'events-certificate' ), __( 'Width', 'events-certificate' ) ],
		end: [ __( 'Right', 'events-certificate' ), __( 'Width', 'events-certificate' ) ],
		stretch: [ __( 'Left', 'events-certificate' ), __( 'Right', 'events-certificate' ) ],
	},
	v: {
		start: [ __( 'Top', 'events-certificate' ), __( 'Height', 'events-certificate' ) ],
		center: [ __( 'Offset', 'events-certificate' ), __( 'Height', 'events-certificate' ) ],
		end: [ __( 'Bottom', 'events-certificate' ), __( 'Height', 'events-certificate' ) ],
		stretch: [ __( 'Top', 'events-certificate' ), __( 'Bottom', 'events-certificate' ) ],
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
function LengthField( { label, value, total, onChange }: { label: string; value: string; total: number; onChange: ( v: string ) => void } ) {
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
			label={ custom ? __( 'Use a value and unit', 'events-certificate' ) : __( 'Set custom value (calc)', 'events-certificate' ) }
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
				{ toggle }
			</HStack>
			{ custom ? (
				<TextControl
					__next40pxDefaultSize
					__nextHasNoMarginBottom
					label={ label }
					hideLabelFromVision
					value={ shown }
					className={ valid ? '' : 'is-invalid' }
					help={ valid ? `= ${ Math.round( parseLen( shown, total ) * 10 ) / 10 } pt` : __( 'Use pt, mm, % or calc(100% - 20pt).', 'events-certificate' ) }
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

/** Anchors per axis (start, centre, end, stretch) with open length fields, relative to the page or the safe margin. */
function Anchors( { el, stored, page, onChange }: { el: Element; stored: Element; page: Design[ 'page' ]; onChange: ( patch: Partial< Element > ) => void } ) {
	const labels = AXIS_LABELS();
	const b = { x: el.x as number, y: el.y as number, w: el.w as number, h: el.h as number };
	const layout: Layout = stored.layout || layoutFrom( { h: { mode: 'start' }, v: { mode: 'start' }, ref: 'page' }, b, page );
	const ref = refBox( page, layout.ref );
	const set = ( next: Layout ) => onChange( { layout: next } as Partial< Element > );
	const setMode = ( axis: 'h' | 'v', mode: AxisMode ) => set( layoutFrom( { ...layout, [ axis ]: { mode } }, b, page ) );
	const setRef = ( r: 'page' | 'safe' ) => set( layoutFrom( { ...layout, ref: r }, b, page ) );
	const field = ( axis: 'h' | 'v', key: 'start' | 'end' | 'size', label: string ) => (
		<LengthField
			label={ label }
			value={ layout[ axis ][ key ] || '0pt' }
			total={ axis === 'h' ? ref.w : ref.h }
			onChange={ ( v ) => set( { ...layout, [ axis ]: { ...layout[ axis ], [ key ]: v } } ) }
		/>
	);
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
	const fill = ( r: 'page' | 'safe' ) => set( { ref: r, h: { mode: 'stretch', start: '0pt', end: '0pt' }, v: { mode: 'stretch', start: '0pt', end: '0pt' } } );
	return (
		<div className="evcert-anchors">
			<ToggleGroupControl __next40pxDefaultSize __nextHasNoMarginBottom isBlock label={ __( 'Relative to', 'events-certificate' ) } value={ layout.ref || 'page' } onChange={ ( v ) => setRef( v as 'page' ) }>
				<ToggleGroupControlOption value="page" label={ __( 'Page', 'events-certificate' ) } />
				<ToggleGroupControlOption value="safe" label={ __( 'Safe margin', 'events-certificate' ) } />
			</ToggleGroupControl>
			<ToggleGroupControl __next40pxDefaultSize __nextHasNoMarginBottom label={ __( 'Horizontal', 'events-certificate' ) } value={ layout.h.mode } onChange={ ( v ) => setMode( 'h', v as AxisMode ) }>
				<ToggleGroupControlOptionIcon value="start" icon={ justifyLeft } label={ __( 'Left', 'events-certificate' ) } />
				<ToggleGroupControlOptionIcon value="center" icon={ justifyCenter } label={ __( 'Center', 'events-certificate' ) } />
				<ToggleGroupControlOptionIcon value="end" icon={ justifyRight } label={ __( 'Right', 'events-certificate' ) } />
				<ToggleGroupControlOptionIcon value="stretch" icon={ justifyStretch } label={ __( 'Stretch', 'events-certificate' ) } />
			</ToggleGroupControl>
			{ fields( 'h' ) }
			<ToggleGroupControl __next40pxDefaultSize __nextHasNoMarginBottom label={ __( 'Vertical', 'events-certificate' ) } value={ layout.v.mode } onChange={ ( v ) => setMode( 'v', v as AxisMode ) }>
				<ToggleGroupControlOptionIcon value="start" icon={ justifyTop } label={ __( 'Top', 'events-certificate' ) } />
				<ToggleGroupControlOptionIcon value="center" icon={ justifyCenterVertical } label={ __( 'Middle', 'events-certificate' ) } />
				<ToggleGroupControlOptionIcon value="end" icon={ justifyBottom } label={ __( 'Bottom', 'events-certificate' ) } />
				<ToggleGroupControlOptionIcon value="stretch" icon={ justifyStretchVertical } label={ __( 'Stretch', 'events-certificate' ) } />
			</ToggleGroupControl>
			{ fields( 'v' ) }
			<HStack justify="flex-start">
				<Button variant="secondary" size="compact" onClick={ () => fill( 'page' ) }>
					{ __( 'Fill page', 'events-certificate' ) }
				</Button>
				<Button variant="secondary" size="compact" onClick={ () => fill( 'safe' ) }>
					{ __( 'Fill safe margin', 'events-certificate' ) }
				</Button>
			</HStack>
		</div>
	);
}
