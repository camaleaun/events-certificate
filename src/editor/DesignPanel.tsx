/**
 * The Certificate tab of the sidebar, like the block editor's document panel
 * (editor/src/components/post-card-panel + post-panel-row): a card with icon,
 * name and status, then label/value rows whose value opens a dropdown, and the
 * colors as a bordered, separated item group like the global styles palette.
 */
import {
	Button,
	ColorIndicator,
	Dropdown,
	__experimentalPaletteEdit as PaletteEdit,
	SelectControl,
	TextControl,
	__experimentalHStack as HStack,
	__experimentalItem as Item,
	__experimentalItemGroup as ItemGroup,
	__experimentalNumberControl as NumberControl,
	__experimentalToggleGroupControl as ToggleGroupControl,
	__experimentalToggleGroupControlOption as ToggleGroupControlOption, CheckboxControl, __experimentalVStack as VStack, RadioControl } from '@wordpress/components';
import { useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { Icon, page as pageIcon } from '@wordpress/icons';
import { color, type Design, type Mode, pageBg, withPageBg } from './model';

const MM = 2.83465;

/** Fields whose preview value can be changed (the others come from the site or the certificate). */
export const PREVIEW_FIELDS = (): Array< [ string, string ] > => [
	[ 'name', __( 'Participant', 'events-certificates' ) ],
	[ 'event', __( 'Event', 'events-certificates' ) ],
	[ 'location', __( 'Location', 'events-certificates' ) ],
	[ 'hours', __( 'Workload (hours)', 'events-certificates' ) ],
	[ 'role', __( 'Role', 'events-certificates' ) ],
	[ 'details', __( 'Details', 'events-certificates' ) ],
	[ 'signer_name', __( 'Signer', 'events-certificates' ) ],
	[ 'signer_role', __( 'Signer role', 'events-certificates' ) ],
];
const SIZES = {
	a4: { label: 'A4', w: 842, h: 595 },
	letter: { label: 'Letter', w: 792, h: 612 },
};

type Props = {
	design: Design;
	mode: Mode;
	title: string;
	status: string;
	// The template's conditions (Templates screen): the roles it applies to; the default one takes everyone else.
	roles: string[];
	roleLabels: Record< string, string >;
	isDefault: boolean;
	onRoles: ( roles: string[] ) => void;
	sample: Record< string, string >;
	defaults: Record< string, string >;
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
export function DropdownRow( { label, value, children }: { label: string; value: React.ReactNode; children: ( close: () => void ) => React.ReactNode } ) {
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

export default function DesignPanel( { design, mode, title, status, roles, roleLabels, isDefault, onRoles, sample, defaults, onTitle, onChange }: Props ) {
	const { page } = design;
	const landscape = page.w >= page.h;
	const sizeKey = ( Object.keys( SIZES ) as Array< keyof typeof SIZES > ).find( ( k ) => ( [ SIZES[ k ].w, SIZES[ k ].h ].sort().join() === [ page.w, page.h ].sort().join() ) ) || 'a4';
	const setPage = ( patch: Partial< Design[ 'page' ] > ) => onChange( { ...design, page: { ...page, ...patch } } );
	// The background of the mode being designed (Light/Dark in the toolbar): each mode can have its own.
	const bgValue = pageBg( page, mode );
	const bg = color( design, bgValue, mode );
	const bgVar = bgValue.startsWith( '$' ) ? design.colors[ bgValue.slice( 1 ) ] : null;

	return (
		<div className="evcert-design-panel">
			<div className="evcert-card">
				<span className="evcert-card__icon">
					<Icon icon={ pageIcon } />
				</span>
				<div>
					<h2 className="evcert-card__title">{ title || __( 'Certificate', 'events-certificates' ) }</h2>
					<p className="evcert-card__description">{ status }</p>
				</div>
			</div>

			<div className="evcert-inspector__section">
				<DropdownRow label={ __( 'Name', 'events-certificates' ) } value={ title || __( 'Certificate', 'events-certificates' ) }>
					{ () => <TextControl __next40pxDefaultSize __nextHasNoMarginBottom label={ __( 'Name', 'events-certificates' ) } value={ title } onChange={ onTitle } /> }
				</DropdownRow>
				<DropdownRow
					label={ __( 'Verification', 'events-certificates' ) }
					value={ design.verifyFirstPage === 'without-declaration' ? __( 'On the declaration page', 'events-certificates' ) : __( 'On the first page', 'events-certificates' ) }
				>
					{ () => (
						<RadioControl
							label={ __( 'QR code, code and hint', 'events-certificates' ) }
							selected={ design.verifyFirstPage === 'without-declaration' ? 'without-declaration' : 'always' }
							options={ [
								{ label: __( 'Always on the first page', 'events-certificates' ), value: 'always' },
								{ label: __( 'On the declaration page when there is one', 'events-certificates' ), value: 'without-declaration' },
							] }
							onChange={ ( v ) => onChange( { ...design, verifyFirstPage: v === 'without-declaration' ? 'without-declaration' : undefined } ) }
						/>
					) }
				</DropdownRow>
				<DropdownRow
					label={ __( 'Applies to', 'events-certificates' ) }
					value={ isDefault ? __( 'Everyone else', 'events-certificates' ) : roles.map( ( r ) => roleLabels[ r ] ).filter( Boolean ).join( ', ' ) || __( 'Nobody yet', 'events-certificates' ) }
				>
					{ () =>
						isDefault ? (
							<p className="evcert-meta">{ __( 'The default template: certificates no other template applies to.', 'events-certificates' ) }</p>
						) : (
							<VStack spacing={ 2 }>
								{ Object.entries( roleLabels ).map( ( [ key, label ] ) => (
									<CheckboxControl
										key={ key }
										__nextHasNoMarginBottom
										label={ label }
										checked={ roles.includes( key ) }
										onChange={ ( on ) => onRoles( on ? [ ...roles, key ].filter( ( r, i, all ) => all.indexOf( r ) === i ) : roles.filter( ( r ) => r !== key ) ) }
									/>
								) ) }
							</VStack>
						)
					}
				</DropdownRow>
				<DropdownRow label={ __( 'Size', 'events-certificates' ) } value={ `${ SIZES[ sizeKey ].label } · ${ landscape ? __( 'Landscape', 'events-certificates' ) : __( 'Portrait', 'events-certificates' ) }` }>
					{ () => (
						<>
							<SelectControl
								__next40pxDefaultSize
								__nextHasNoMarginBottom
								label={ __( 'Paper', 'events-certificates' ) }
								value={ sizeKey }
								options={ ( Object.keys( SIZES ) as Array< keyof typeof SIZES > ).map( ( k ) => ( { label: SIZES[ k ].label, value: k } ) ) }
								onChange={ ( k ) => {
									const s = SIZES[ k as keyof typeof SIZES ];
									setPage( landscape ? { w: s.w, h: s.h } : { w: s.h, h: s.w } );
								} }
							/>
							<ToggleGroupControl __next40pxDefaultSize __nextHasNoMarginBottom isBlock label={ __( 'Orientation', 'events-certificates' ) } value={ landscape ? 'landscape' : 'portrait' } onChange={ ( v ) => ( ( v === 'landscape' ) !== landscape ? setPage( { w: page.h, h: page.w } ) : null ) }>
								<ToggleGroupControlOption value="landscape" label={ __( 'Landscape', 'events-certificates' ) } />
								<ToggleGroupControlOption value="portrait" label={ __( 'Portrait', 'events-certificates' ) } />
							</ToggleGroupControl>
							<p className="evcert-meta">{ sprintf( /* translators: 1: width, 2: height in points. */ __( '%1$s × %2$s pt', 'events-certificates' ), String( page.w ), String( page.h ) ) }</p>
						</>
					) }
				</DropdownRow>
				<DropdownRow
					label={ mode === 'dark' ? __( 'Background (Dark)', 'events-certificates' ) : __( 'Background (Light)', 'events-certificates' ) }
					value={
						<HStack spacing={ 2 } justify="flex-start">
							<ColorIndicator colorValue={ bg === 'transparent' || bg === 'none' ? undefined : bg } />
							<span>{ bgVar ? bgVar.name : bgValue || __( 'Default (page color)', 'events-certificates' ) }</span>
						</HStack>
					}
				>
					{ ( close ) => (
						<ItemGroup isBordered isSeparated>
							{ /* No background: the page's own color shows (light or dark on the certificate page, the paper in the PDF). */ }
							<Item onClick={ () => { setPage( { bg: withPageBg( page, mode, '' ) } ); close(); } }>
								<HStack justify="flex-start">
									<ColorIndicator colorValue={ undefined } />
									<span>{ __( 'Default (page color)', 'events-certificates' ) }</span>
								</HStack>
							</Item>
							{ Object.entries( design.colors ).map( ( [ key, v ] ) => (
								<Item key={ key } onClick={ () => { setPage( { bg: withPageBg( page, mode, `$${ key }` ) } ); close(); } }>
									<HStack justify="flex-start">
										<ColorIndicator colorValue={ v[ mode ] === 'transparent' ? undefined : v[ mode ] } />
										<span>{ v.name }</span>
									</HStack>
								</Item>
							) ) }
						</ItemGroup>
					) }
				</DropdownRow>
				<DropdownRow label={ __( 'Safe margin', 'events-certificates' ) } value={ `${ Math.round( page.safe / MM ) } mm` }>
					{ () => (
						<NumberControl __next40pxDefaultSize label={ __( 'Safe margin', 'events-certificates' ) } min={ 0 } max={ 40 } value={ Math.round( page.safe / MM ) } suffix={ <span className="evcert-suffix">mm</span> } onChange={ ( v ) => setPage( { safe: ( Number( v ) || 0 ) * MM } ) } />
					) }
				</DropdownRow>
				<DropdownRow label={ __( 'Bleed', 'events-certificates' ) } value={ `${ Math.round( page.bleed / MM ) } mm` }>
					{ () => (
						<NumberControl __next40pxDefaultSize label={ __( 'Bleed', 'events-certificates' ) } min={ 0 } max={ 10 } value={ Math.round( page.bleed / MM ) } suffix={ <span className="evcert-suffix">mm</span> } onChange={ ( v ) => setPage( { bleed: ( Number( v ) || 0 ) * MM } ) } />
					) }
				</DropdownRow>
			</div>

			<div className="evcert-inspector__section">
				<Palette design={ design } onChange={ onChange } />
			</div>

			<div className="evcert-inspector__section">
				<h2 className="evcert-section-heading">{ __( 'Preview data', 'events-certificates' ) }</h2>
				{ PREVIEW_FIELDS().map( ( [ key, label ] ) => (
					<DropdownRow key={ key } label={ label } value={ sample[ key ] || '—' }>
						{ () => (
							<TextControl
								__next40pxDefaultSize
								__nextHasNoMarginBottom
								label={ label }
								// Your value; empty means the default (the signer, the event…), shown as the placeholder.
								value={ design.sample?.[ key ] || '' }
								placeholder={ defaults[ key ] || '' }
								onChange={ ( v ) => onChange( { ...design, sample: setSample( design.sample, key, v ) } ) }
							/>
						) }
					</DropdownRow>
				) ) }
				{ design.sample && Object.values( design.sample ).some( ( v ) => v !== '' ) && (
					<Button variant="link" onClick={ () => onChange( { ...design, sample: undefined } ) }>
						{ __( 'Reset preview data', 'events-certificates' ) }
					</Button>
				) }
			</div>
		</div>
	);
}

/** Sets one preview value; an empty one goes back to the default. */
export function setSample( current: Record< string, string > | undefined, key: string, value: string ): Record< string, string > | undefined {
	const next = { ...( current || {} ), [ key ]: value };
	if ( ! value ) {
		delete next[ key ];
	}
	return Object.keys( next ).length ? next : undefined;
}

const TRANSPARENT = '#ffffff00';

/**
 * The color variables, edited with core's PaletteEdit (the Styles > Colors >
 * Palette editor of the Site Editor): add, rename, recolor and remove. Each
 * variable has a light and a dark value; the toggle picks which one the
 * palette shows. Adding, renaming or removing applies to both modes.
 */
function Palette( { design, onChange }: { design: Design; onChange: ( design: Design ) => void } ) {
	const [ which, setWhich ] = useState< 'light' | 'dark' >( 'light' );
	const colors = Object.entries( design.colors ).map( ( [ slug, v ] ) => ( { slug, name: v.name, color: v[ which ] === 'transparent' ? TRANSPARENT : v[ which ] } ) );
	return (
		<>
			<ToggleGroupControl __next40pxDefaultSize __nextHasNoMarginBottom isBlock label={ __( 'Palette for', 'events-certificates' ) } value={ which } onChange={ ( v ) => setWhich( v as 'light' ) }>
				<ToggleGroupControlOption value="light" label={ __( 'Light', 'events-certificates' ) } />
				<ToggleGroupControlOption value="dark" label={ __( 'Dark', 'events-certificates' ) } />
			</ToggleGroupControl>
			<div className="evcert-palette-edit">
				<PaletteEdit
					paletteLabel={ __( 'Colors', 'events-certificates' ) }
					paletteLabelHeadingLevel={ 2 }
					colors={ colors }
					slugPrefix="custom-"
					emptyMessage={ __( 'No colors yet. Add one with +.', 'events-certificates' ) }
					popoverProps={ { placement: 'left-start', offset: 36 } }
					onChange={ ( next: Array< { slug: string; name: string; color: string } > = [] ) => {
						const updated: Design[ 'colors' ] = {};
						for ( const c of next ) {
							const old = design.colors[ c.slug ];
							const value = c.color === TRANSPARENT ? 'transparent' : c.color;
							updated[ c.slug ] = old ? { ...old, name: c.name, [ which ]: value } : { name: c.name, light: value, dark: value };
						}
						onChange( { ...design, colors: updated } );
					} }
				/>
			</div>
		</>
	);
}
