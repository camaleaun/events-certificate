/**
 * The Generator screen's form (Certificates > Generator): the block editor's own
 * components (Panel, PanelBody, TextControl, TextareaControl, ToggleControl,
 * VStack, Button), so it looks and behaves like the editor's Inspector. Its
 * fields and texts are those of the front-end generator page. Each control also
 * writes its value to a hidden input of the server-rendered <form> (see
 * includes/generator.php), which posts the event, its people and their verifiable
 * certificates.
 */
import {
	BaseControl,
	Button,
	DropZone,
	Modal,
	Panel,
	PanelBody,
	TextareaControl,
	TextControl,
	ToggleControl,
	__experimentalVStack as VStack,
} from '@wordpress/components';
import { createInterpolateElement, createRoot, useEffect, useRef, useState } from '@wordpress/element';
import { __, _x } from '@wordpress/i18n';

declare global {
	interface Window {
		evcertGenerator?: { signerName?: string; signerRole?: string; tutorial?: Tutorial };
	}
}

type Picture = { src: string; width: number; height: number; alt: string };
type Tutorial = { title: string; intro: string; hint: string; steps: Array< { title: string; text: string; picture: Picture } > };

const config = window.evcertGenerator || {};
const field = { __next40pxDefaultSize: true, __nextHasNoMarginBottom: true };

// The sections, as the front-end generator names them (also listed in the left panel).
const SECTIONS = [
	[ 'event', __( '1 · Event', 'events-certificates' ) ],
	[ 'signature', __( '2 · Signature', 'events-certificates' ) ],
	[ 'people', __( '3 · Participants', 'events-certificates' ) ],
];

type Values = Record< string, string >;

function Hidden( { name, value }: { name: string; value: string } ) {
	return <input type="hidden" name={ name } value={ value } />;
}

// The CSV file, like the editor's featured image: a full-width button with a short
// label (the file's name under it), and a drop zone.
function CsvField( { onHelp }: { onHelp: () => void } ) {
	const input = useRef< HTMLInputElement >( null );
	const [ file, setFile ] = useState( '' );
	const take = ( files: FileList | File[] ) => {
		const first = files[ 0 ];
		if ( ! first || ! input.current ) {
			return;
		}
		const list = new DataTransfer();
		list.items.add( first );
		input.current.files = list.files;
		setFile( first.name );
	};
	return (
		<BaseControl
			{ ...field }
			id="evcert-csv"
			label={ __( 'Participant list file', 'events-certificates' ) }
			help={ createInterpolateElement(
				__( 'Not sure where to download the list? <a>See the step by step on Meetup</a>.', 'events-certificates' ),
				{ a: <Button variant="link" onClick={ onHelp } /> }
			) }
		>
			<div className="evcert-generator__file">
				<Button variant="secondary" className="evcert-generator__file-toggle" onClick={ () => input.current?.click() }>
					{ file ? __( 'Replace the file', 'events-certificates' ) : __( 'Choose a CSV file', 'events-certificates' ) }
				</Button>
				{ file && <p className="evcert-generator__file-name">{ file }</p> }
				<DropZone onFilesDrop={ take } />
				<input
					type="file"
					id="evcert-csv"
					ref={ input }
					name="evcert_csv"
					accept=".csv,text/csv"
					hidden
					onChange={ ( e ) => setFile( e.target.files?.[ 0 ]?.name || '' ) }
				/>
			</div>
		</BaseControl>
	);
}

// The tutorial the generator page shows: the same steps, texts and pictures, in a modal.
function TutorialModal( { tutorial, onClose }: { tutorial: Tutorial; onClose: () => void } ) {
	const [ big, setBig ] = useState< Picture | null >( null );
	return (
		<Modal title={ tutorial.title } size="large" onRequestClose={ big ? () => setBig( null ) : onClose } className="evcert-tutorial">
			<p className="evcert-tutorial__intro" dangerouslySetInnerHTML={ { __html: tutorial.intro } } />
			<ol className="evcert-tutorial__steps">
				{ tutorial.steps.map( ( step ) => (
					<li key={ step.title }>
						<h3>{ step.title }</h3>
						<p dangerouslySetInnerHTML={ { __html: step.text } } />
						<button type="button" className="evcert-tutorial__shot" onClick={ () => setBig( step.picture ) }>
							<img src={ step.picture.src } width={ step.picture.width } height={ step.picture.height } alt={ step.picture.alt } loading="lazy" />
						</button>
					</li>
				) ) }
			</ol>
			<p className="evcert-tutorial__hint">{ tutorial.hint }</p>
			{ big && (
				<button type="button" className="evcert-tutorial__big" onClick={ () => setBig( null ) }>
					<img src={ big.src } alt={ big.alt } />
				</button>
			) }
		</Modal>
	);
}

function Form() {
	const [ values, setValues ] = useState< Values >( {
		community: '',
		title: '',
		date: '',
		start_time: '',
		end_time: '',
		venue: '',
		city: '',
		speakers: '',
		link: '',
		signer_name: config.signerName || '',
		signer_role: config.signerRole || '',
		lines: '',
	} );
	const [ showHours, setShowHours ] = useState( true );
	const [ open, setOpen ] = useState( 'event' );
	const [ help, setHelp ] = useState( false );
	const set = ( key: string ) => ( value: string ) => setValues( ( old ) => ( { ...old, [ key ]: value } ) );

	// A text field and its hidden input.
	const text = ( key: string, label: string, extra: Record< string, unknown > = {} ) => (
		<div key={ key }>
			<TextControl { ...field } label={ label } value={ values[ key ] } onChange={ set( key ) } { ...extra } />
			<Hidden name={ 'evcert_' + key } value={ values[ key ] } />
		</div>
	);
	const panel = ( id: string, children: React.ReactNode ) => (
		<PanelBody key={ id } title={ SECTIONS.find( ( s ) => s[ 0 ] === id )?.[ 1 ] } opened={ open === id } onToggle={ () => setOpen( open === id ? '' : id ) }>
			<VStack spacing={ 4 }>{ children }</VStack>
		</PanelBody>
	);

	useEffect( () => {
		window.dispatchEvent( new CustomEvent( 'evcert:step', { detail: { active: open } } ) );
	}, [ open ] );
	useEffect( () => {
		const show = () => setHelp( true );
		window.addEventListener( 'evcert:open-help', show );
		return () => window.removeEventListener( 'evcert:open-help', show );
	}, [] );

	return (
		<Panel>
			{ panel( 'event', [
				text( 'community', __( 'Community', 'events-certificates' ), { placeholder: __( 'E.g.: WordPress Curitiba Community', 'events-certificates' ) } ),
				<div key="title">
					<TextareaControl
						{ ...field }
						label={ __( 'Event title', 'events-certificates' ) }
						value={ values.title }
						rows={ 2 }
						placeholder={ __( 'E.g.: WPCuritiba 88: Building sites with AI Agents', 'events-certificates' ) }
						onChange={ set( 'title' ) }
					/>
					<Hidden name="evcert_title" value={ values.title } />
				</div>,
				text( 'date', __( 'Date', 'events-certificates' ), { type: 'date' } ),
				text( 'start_time', __( 'Start', 'events-certificates' ), { type: 'time' } ),
				text( 'end_time', __( 'End', 'events-certificates' ), { type: 'time' } ),
				text( 'venue', __( 'Venue', 'events-certificates' ), { placeholder: __( 'E.g.: HOTMILK', 'events-certificates' ) } ),
				text( 'city', __( 'City', 'events-certificates' ), { placeholder: __( 'E.g.: Curitiba, PR', 'events-certificates' ) } ),
				text( 'speakers', __( 'Speaker(s)', 'events-certificates' ), { placeholder: __( 'E.g.: Fellyph Cintra', 'events-certificates' ), help: __( 'optional', 'events-certificates' ) } ),
				text( 'link', __( 'Event link', 'events-certificates' ), { type: 'url', placeholder: 'https://', help: __( 'optional, goes in the footer', 'events-certificates' ) } ),
				<div key="hours">
					<ToggleControl { ...field } label={ __( 'Show the workload on the certificate', 'events-certificates' ) } checked={ showHours } onChange={ setShowHours } />
					<Hidden name="evcert_show_hours" value={ showHours ? '1' : '0' } />
				</div>,
			] ) }
			{ panel( 'signature', [
				text( 'signer_name', __( 'Name of who signs', 'events-certificates' ), { placeholder: config.signerName || __( 'Who signs the certificate', 'events-certificates' ) } ),
				text( 'signer_role', _x( 'Role', 'role of who signs, in the generator', 'events-certificates' ), { placeholder: config.signerRole || _x( 'Organizer', 'default role of who signs, in the generator', 'events-certificates' ), help: __( 'Leave empty to use who signs in Settings.', 'events-certificates' ) } ),
			] ) }
			{ panel( 'people', [
				<div key="lines">
					<TextareaControl
						{ ...field }
						label={ __( 'Or add names by hand, one per line', 'events-certificates' ) }
						value={ values.lines }
						rows={ 6 }
						help={ __( 'One per line: name, and optionally e-mail and role, separated by commas (or pasted from a spreadsheet).', 'events-certificates' ) }
						onChange={ set( 'lines' ) }
					/>
					<Hidden name="evcert_lines" value={ values.lines } />
				</div>,
				<CsvField key="csv" onHelp={ () => setHelp( true ) } />,
			] ) }
			{ help && config.tutorial && <TutorialModal tutorial={ config.tutorial } onClose={ () => setHelp( false ) } /> }
		</Panel>
	);
}

// The left panel lists the sections; the open one is marked.
function Steps() {
	const [ active, setActive ] = useState( 'event' );
	useEffect( () => {
		const on = ( e: Event ) => setActive( ( e as CustomEvent ).detail.active );
		window.addEventListener( 'evcert:step', on );
		return () => window.removeEventListener( 'evcert:step', on );
	}, [] );
	return (
		<ol className="evcert-generator__steps">
			{ SECTIONS.map( ( [ id, label ] ) => (
				<li key={ id } className={ 'evcert-generator__step' + ( active === id ? ' is-active' : '' ) }>
					{ label }
				</li>
			) ) }
		</ol>
	);
}

// The header's buttons (printed by the server) open the left panel and the help.
const toggle = document.getElementById( 'evcert-generator-toggle-left' );
const left = document.getElementById( 'evcert-generator-left-panel' );
toggle?.addEventListener( 'click', () => {
	const show = 'true' !== toggle.getAttribute( 'aria-expanded' );
	left?.classList.toggle( 'is-open', show );
	left?.setAttribute( 'aria-hidden', show ? 'false' : 'true' );
	toggle.setAttribute( 'aria-expanded', show ? 'true' : 'false' );
} );
document.getElementById( 'evcert-generator-help' )?.addEventListener( 'click', () => window.dispatchEvent( new CustomEvent( 'evcert:open-help' ) ) );

const leftRoot = document.getElementById( 'evcert-generator-left-root' );
if ( leftRoot ) {
	createRoot( leftRoot ).render( <Steps /> );
}
const root = document.getElementById( 'evcert-generator-root' );
if ( root ) {
	createRoot( root ).render( <Form /> );
}
