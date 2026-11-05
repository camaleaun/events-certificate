/**
 * The Certificate panel on the organizer screen (wcb_organizer): whether this
 * organizer signs the certificates, their role in each of the site's
 * languages (one field, a language switch at the end of its label row) and
 * their signature, edited with the design editor's SVG components (the
 * viewBox row, the path rows, the appender and the larger Edit SVG modal).
 * Saved in post meta with the post (includes/signer.php).
 */
import { Button, CheckboxControl, DropdownMenu, MenuGroup, MenuItem, TextControl, __experimentalHStack as HStack, __experimentalNumberControl as NumberControl } from '@wordpress/components';
import { useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { DropdownRow } from '../editor/DesignPanel';
import type { Design, SvgEl, SvgPath } from '../editor/model';
import SvgEditor from '../editor/SvgEditor';
import { PathList } from '../editor/SvgPanel';
import '../editor/style.scss';

type Signature = { vw: number; vh: number; paths: SvgPath[] };
type Meta = { _evcert_signs?: boolean; _evcert_signer_role?: Record< string, string >; _evcert_signature?: Signature | null };
type Config = { locales: Array< { locale: string; name: string } >; signer: string };

declare global {
	interface Window {
		wp: any;
		evcertSigner: Config;
	}
}

const { registerPlugin } = window.wp.plugins;
const { PluginDocumentSettingPanel } = window.wp.editor;
const { useEntityProp } = window.wp.coreData;
const { useSelect } = window.wp.data;

// The signature is drawn in the text color: a one-color palette for the path rows.
const DESIGN = {
	version: 1,
	page: { w: 120, h: 44, bg: 'transparent', safe: 0, bleed: 0 },
	colors: { text: { name: __( 'Text', 'events-certificate' ), light: '#1e1e1e', dark: '#f0f0f0' } },
	elements: [],
} as unknown as Design;

/** A text field with a language switch at the end of its label row. */
function LocalizedField( { label, values, onChange, placeholder }: { label: string; values: Record< string, string >; onChange: ( values: Record< string, string > ) => void; placeholder?: string } ) {
	const { locales } = window.evcertSigner;
	const [ index, setIndex ] = useState( 0 );
	const current = locales[ index ] || locales[ 0 ];
	const short = ( l: string ) => l.split( '_' )[ 0 ].toUpperCase();
	return (
		<div className="evcert-localized">
			<HStack className="evcert-localized__header">
				<label className="evcert-localized__label" htmlFor="evcert-localized-input">
					{ label }
				</label>
				{ locales.length === 2 && (
					<Button size="small" variant="tertiary" className="evcert-localized__switch" label={ sprintf( /* translators: %s: language name. */ __( 'Edit in %s', 'events-certificate' ), locales[ 1 - index ].name ) } showTooltip onClick={ () => setIndex( 1 - index ) }>
						{ short( current.locale ) }
					</Button>
				) }
				{ locales.length > 2 && (
					<DropdownMenu label={ __( 'Language', 'events-certificate' ) } icon={ null } text={ short( current.locale ) } toggleProps={ { size: 'small', variant: 'tertiary' } as any }>
						{ ( { onClose } ) => (
							<MenuGroup>
								{ locales.map( ( l, i ) => (
									<MenuItem key={ l.locale } isSelected={ i === index } role="menuitemradio" onClick={ () => { setIndex( i ); onClose(); } }>
										{ l.name }
									</MenuItem>
								) ) }
							</MenuGroup>
						) }
					</DropdownMenu>
				) }
			</HStack>
			<TextControl
				__next40pxDefaultSize
				__nextHasNoMarginBottom
				id="evcert-localized-input"
				label={ `${ label } (${ current.name })` }
				hideLabelFromVision
				lang={ current.locale.replace( '_', '-' ) }
				value={ values[ current.locale ] || '' }
				placeholder={ placeholder }
				onChange={ ( v ) => onChange( { ...values, [ current.locale ]: v } ) }
			/>
		</div>
	);
}

function CertificatePanel() {
	const postType = useSelect( ( select: any ) => select( 'core/editor' ).getCurrentPostType(), [] );
	const [ meta, setMeta ] = useEntityProp( 'postType', 'wcb_organizer', 'meta' ) as [ Meta, ( meta: Meta ) => void ];
	const [ editing, setEditing ] = useState( false );
	if ( postType !== 'wcb_organizer' || ! meta ) {
		return null;
	}
	const signature = meta._evcert_signature && meta._evcert_signature.paths?.length ? meta._evcert_signature : null;
	const set = ( patch: Meta ) => setMeta( { ...meta, ...patch } );
	const setSignature = ( next: Signature | null ) => set( { _evcert_signature: next && next.paths.length ? next : null } );
	const el = { id: 'signature', type: 'svg', name: __( 'Signature', 'events-certificate' ), x: 0, y: 0, w: 120, h: 44, vw: signature?.vw || 120, vh: signature?.vh || 44, paths: signature?.paths || [], color: '$text' } as SvgEl;
	const { signer } = window.evcertSigner;

	return (
		<PluginDocumentSettingPanel name="evcert-certificate" title={ __( 'Certificate', 'events-certificate' ) } className="evcert-certificate-panel">
			<CheckboxControl
				__nextHasNoMarginBottom
				label={ __( 'Signs the certificates', 'events-certificate' ) }
				help={ signer && ! meta._evcert_signs ? sprintf( /* translators: %s: name of the organizer who signs now. */ __( 'Now signed by %s; checking this replaces them.', 'events-certificate' ), signer ) : undefined }
				checked={ !! meta._evcert_signs }
				onChange={ ( v ) => set( { _evcert_signs: v } ) }
			/>
			<LocalizedField label={ __( 'Role', 'events-certificate' ) } placeholder={ __( 'Event Coordinator', 'events-certificate' ) } values={ meta._evcert_signer_role || {} } onChange={ ( v ) => set( { _evcert_signer_role: v } ) } />

			<div className="evcert-certificate-panel__signature">
				<HStack className="evcert-svg-panel__header">
					<h3 className="evcert-section-heading">{ __( 'Signature', 'events-certificate' ) }</h3>
					<Button size="small" variant="tertiary" onClick={ () => setEditing( true ) }>
						{ __( 'Edit', 'events-certificate' ) }
					</Button>
				</HStack>
				{ signature && (
					<div className="evcert-certificate-panel__preview" aria-hidden="true">
						<svg viewBox={ `0 0 ${ signature.vw } ${ signature.vh }` }>
							{ signature.paths.map( ( p, i ) => (
								<path key={ i } d={ p.d } fill={ p.fill && ! p.fill.startsWith( '$' ) ? p.fill : 'currentColor' } fillRule={ p.rule } />
							) ) }
						</svg>
					</div>
				) }
				<DropdownRow label={ __( 'ViewBox', 'events-certificate' ) } value={ `${ el.vw } × ${ el.vh }` }>
					{ () => (
						<HStack alignment="top">
							<NumberControl __next40pxDefaultSize label={ __( 'Width', 'events-certificate' ) } min={ 1 } value={ el.vw } onChange={ ( v ) => Number( v ) > 0 && setSignature( { vw: Number( v ), vh: el.vh, paths: el.paths } ) } />
							<NumberControl __next40pxDefaultSize label={ __( 'Height', 'events-certificate' ) } min={ 1 } value={ el.vh } onChange={ ( v ) => Number( v ) > 0 && setSignature( { vw: el.vw, vh: Number( v ), paths: el.paths } ) } />
						</HStack>
					) }
				</DropdownRow>
				<PathList
					paths={ el.paths }
					current="$text"
					design={ DESIGN }
					mode="light"
					onPaths={ ( paths ) => setSignature( { vw: el.vw, vh: el.vh, paths } ) }
					onImport={ ( svg ) => setSignature( svg ) }
				/>
				<p className="evcert-meta">{ __( 'Filled paths only, like the certificate editor: paste SVG code into a path to import it. Convert strokes to filled outlines first.', 'events-certificate' ) }</p>
			</div>

			{ editing && (
				<SvgEditor
					el={ el }
					design={ DESIGN }
					mode="light"
					onApply={ ( patch ) => setSignature( { vw: patch.vw ?? el.vw, vh: patch.vh ?? el.vh, paths: patch.paths ?? el.paths } ) }
					onClose={ () => setEditing( false ) }
				/>
			) }
		</PluginDocumentSettingPanel>
	);
}

registerPlugin( 'evcert-certificate', { render: CertificatePanel } );
