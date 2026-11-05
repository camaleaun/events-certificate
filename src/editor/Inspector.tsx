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
	ToggleControl,
	__experimentalNumberControl as NumberControl,
	__experimentalToggleGroupControl as ToggleGroupControl,
	__experimentalToggleGroupControlOption as ToggleGroupControlOption,
	__experimentalToggleGroupControlOptionIcon as ToggleGroupControlOptionIcon,
	__experimentalToolsPanel as ToolsPanel,
	__experimentalToolsPanelItem as ToolsPanelItem,
	__experimentalHStack as HStack,
} from '@wordpress/components';
import { useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { alignCenter, alignLeft, alignRight, cornerBottomLeft, cornerBottomRight, cornerTopLeft, cornerTopRight, formatUppercase, Icon, link, linkOff, reset } from '@wordpress/icons';
import { elementIcon } from './icons';
import { color, REQUIRED_SLOTS, type Design, type Element, type Mode } from './model';

type Props = {
	el: Element;
	design: Design;
	mode: Mode;
	tab: 'content' | 'art';
	locale: string;
	sample: Record< string, string >;
	onChange: ( patch: Partial< Element > ) => void;
	onImage: () => void;
};

const FONT_SIZES = [ 8, 10, 12, 14, 16, 18, 24, 32, 42 ].map( ( size ) => ( { name: `${ size } pt`, slug: String( size ), size } ) );
const num = ( v: number ) => Math.round( v * 100 ) / 100;

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

export default function Inspector( { el, design, mode, tab, locale, sample, onChange, onImage }: Props ) {
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
				<div className="evcert-inspector__section">
					<Button variant="secondary" __next40pxDefaultSize onClick={ onImage }>
						{ __( 'Replace image…', 'events-certificate' ) }
					</Button>
				</div>
			) }

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
						{ ( el.type === 'rect' || el.type === 'ellipse' || el.type === 'path' || el.type === 'svg' ) && <ColorItem label={ __( 'Fill', 'events-certificate' ) } value={ el.fill } design={ design } mode={ mode } onChange={ ( fill ) => onChange( { fill } ) } /> }
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
									<RangeControl __next40pxDefaultSize __nextHasNoMarginBottom label={ __( 'All corners', 'events-certificate' ) } hideLabelFromVision value={ ( el.radius || [ 0 ] )[ 0 ] || 0 } min={ 0 } max={ Math.round( Math.min( el.w, el.h ) / 2 ) } onChange={ ( v ) => onChange( { radius: [ v ?? 0, v ?? 0, v ?? 0, v ?? 0 ] } ) } />
								) : (
									<div className="evcert-radius__corners">
										{ [ cornerTopLeft, cornerTopRight, cornerBottomRight, cornerBottomLeft ].map( ( icon, i ) => (
											<HStack key={ i }>
												<Icon icon={ icon } />
												<NumberControl
													__next40pxDefaultSize
													label={ [ __( 'Top left', 'events-certificate' ), __( 'Top right', 'events-certificate' ), __( 'Bottom right', 'events-certificate' ), __( 'Bottom left', 'events-certificate' ) ][ i ] }
													hideLabelFromVision
													min={ 0 }
													value={ ( el.radius || [ 0, 0, 0, 0 ] )[ i ] || 0 }
													onChange={ ( v ) => {
														const radius = [ ...( el.radius || [ 0, 0, 0, 0 ] ) ];
														radius[ i ] = Number( v ) || 0;
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
						<ToolsPanelItem label={ __( 'Position and size', 'events-certificate' ) } hasValue={ () => true } onDeselect={ () => {} } isShownByDefault>
							<div className="evcert-grid-2">
								{ ( [ [ 'x', 'X' ], [ 'y', 'Y' ], [ 'w', __( 'Width', 'events-certificate' ) ], [ 'h', __( 'Height', 'events-certificate' ) ] ] as const ).map( ( [ k, label ] ) => (
									<NumberControl key={ k } __next40pxDefaultSize label={ label } value={ num( ( el as any )[ k ] ) } onChange={ ( v ) => onChange( { [ k ]: Number( v ) } as Partial< Element > ) } suffix={ <span className="evcert-suffix">pt</span> } />
								) ) }
							</div>
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
