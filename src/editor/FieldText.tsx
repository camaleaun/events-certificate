/**
 * Text with fields as chips (like Google Sheets' custom date format input):
 * free text between chips, chips inserted from a menu, each chip with its own
 * options (a date's format), Bold/Italic from buttons or ⌘B/⌘I. The value is a
 * list of runs, never text with braces.
 */
import { Button, Dropdown, MenuGroup, MenuItem, Popover, SelectControl, TextControl } from '@wordpress/components';
import { useEffect, useLayoutEffect, useRef, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { fieldValue, type Run } from './model';

/** Fields with an editable preview value (same list as the Certificate tab's Preview data). */
const PREVIEW_KEYS = [ 'name', 'event', 'location', 'hours', 'role', 'details', 'signer_name', 'signer_role' ];

/**
 * group 'text': a standard text (the title, "This is to certify that"…), shown
 * as a chip with its words; the words come from the translation in the
 * language the certificate is shown in (Render\phrases() in PHP).
 */
export type FieldInfo = { label: string; group?: 'text'; formats?: { key: string; label: string }[] };

export function fields(): Record< string, FieldInfo > {
	const dateFormats = [
		{ key: 'site', label: __( 'Site default', 'events-certificate' ) },
		{ key: 'long', label: __( 'Long (5 November 2026)', 'events-certificate' ) },
		{ key: 'medium', label: __( 'Medium (5 Nov 2026)', 'events-certificate' ) },
		{ key: 'short', label: __( 'Short (05/11/2026)', 'events-certificate' ) },
		{ key: 'month', label: __( 'Month and year', 'events-certificate' ) },
		{ key: 'iso', label: __( 'ISO (2026-11-05)', 'events-certificate' ) },
	];
	return {
		name: { label: __( 'Participant name', 'events-certificate' ) },
		event: { label: __( 'Event', 'events-certificate' ) },
		location: { label: __( 'Location', 'events-certificate' ) },
		event_date: { label: __( 'Event date', 'events-certificate' ), formats: dateFormats },
		issued_date: { label: __( 'Date of issue', 'events-certificate' ), formats: dateFormats },
		hours: {
			label: __( 'Workload', 'events-certificate' ),
			formats: [
				{ key: 'long', label: __( '8 hours', 'events-certificate' ) },
				{ key: 'short', label: __( '8h', 'events-certificate' ) },
			],
		},
		role: { label: __( 'Role', 'events-certificate' ) },
		details: { label: __( 'Details (talk title)', 'events-certificate' ) },
		signer_name: { label: __( 'Signer name', 'events-certificate' ) },
		signer_role: { label: __( 'Signer role', 'events-certificate' ) },
		code: { label: __( 'Certificate code', 'events-certificate' ) },
		verify_url: { label: __( 'Verification address', 'events-certificate' ) },
		document: { label: __( 'Document (masked)', 'events-certificate' ) },
		day: { label: __( 'Days', 'events-certificate' ) },
		table: { label: __( 'Table', 'events-certificate' ) },
		sessions: { label: __( 'Talks attended', 'events-certificate' ) },
		declaration_statement: { label: __( 'Declaration statement', 'events-certificate' ) },
		activities: { label: __( 'Activities', 'events-certificate' ) },
		declaration_title: { label: __( 'Declaration of participation', 'events-certificate' ), group: 'text' },
		declaration_note: { label: __( 'Activities and workload declared by the participant. The event confirms only their presence on each day; sessions are chosen freely and are not checked one by one.', 'events-certificate' ), group: 'text' },
		title_text: { label: __( 'Certificate of participation', 'events-certificate' ), group: 'text' },
		intro_text: { label: __( 'This is to certify that', 'events-certificate' ), group: 'text' },
		issued_label: { label: __( 'Date of issue', 'events-certificate' ), group: 'text' },
		place_date_label: { label: __( 'Place and date', 'events-certificate' ), group: 'text' },
		verify_hint: { label: __( 'Use the code above to verify at:', 'events-certificate' ), group: 'text' },
	};
}

const MARKS = { b: 'strong, b', i: 'em, i' };

/** Is the chip bold/italic: inside a <strong>/<em> of the editable. */
function hasMark( chip: HTMLElement, root: HTMLElement | null, mark: 'b' | 'i' ): boolean {
	const anc = chip.parentElement?.closest( MARKS[ mark ] );
	return !! anc && !! root?.contains( anc );
}

/** Bold or italic on one chip: wraps it, or moves it out of the mark (splitting the mark around it). */
function toggleMark( chip: HTMLElement, root: HTMLElement | null, mark: 'b' | 'i' ) {
	const anc = chip.parentElement?.closest( MARKS[ mark ] ) as HTMLElement | null;
	if ( ! anc || ! root?.contains( anc ) ) {
		const wrap = document.createElement( mark === 'b' ? 'strong' : 'em' );
		chip.replaceWith( wrap );
		wrap.appendChild( chip );
		return;
	}
	const range = document.createRange();
	if ( anc.lastChild && chip !== anc.lastChild && ! chip.contains( anc.lastChild ) ) {
		range.setStartAfter( chip );
		range.setEndAfter( anc.lastChild );
		const tail = anc.cloneNode( false ) as HTMLElement;
		tail.append( range.extractContents() );
		anc.after( tail );
	}
	range.setStartBefore( chip );
	range.setEndAfter( anc.lastChild as Node );
	anc.after( range.extractContents() );
	if ( ! anc.textContent && ! anc.querySelector( '.evcert-chip' ) ) {
		anc.remove();
	}
}

const esc = ( v: string ) => v.replace( /&/g, '&amp;' ).replace( /</g, '&lt;' ).replace( />/g, '&gt;' );

/**
 * A text that is only fields (the code, the date, the verification address,
 * a standard text…), with at most spaces or line breaks between them: it
 * edits like an empty paragraph, the fields' preview values as its
 * placeholder. Typing replaces it with your own text; emptying it brings the
 * fields back. Fields show as chips only among text of your own.
 */
export function tokenOnly( runs: Run[] ): Run[] | null {
	return runs.some( ( r ) => 'field' in r ) && runs.every( ( r ) => 'field' in r || ! r.text.trim() ) ? runs : null;
}

/** Whether the editable holds nothing (a lone <br> or spaces count as nothing). */
const isBlank = ( runs: Run[] ) => runs.every( ( r ) => 'text' in r && ! r.text.trim() );

/** Runs as the editable's HTML: text in spans, fields as non-editable chips. */
function toHtml( runs: Run[], info: Record< string, FieldInfo > ): string {
	return runs
		.map( ( run, index ) => {
			let html =
				'field' in run
					? `<span class="evcert-chip" contenteditable="false" data-field="${ esc( run.field ) }" data-format="${ esc( run.format || '' ) }" data-index="${ index }">${ esc( info[ run.field ]?.label || run.field ) }</span>`
					: esc( run.text ).replace( /\n/g, '<br>' );
			if ( run.i ) {
				html = `<em>${ html }</em>`;
			}
			if ( run.b ) {
				html = `<strong>${ html }</strong>`;
			}
			return html;
		} )
		.join( '' );
}

/** The editable's DOM back to runs (merging neighbours with the same marks). */
function fromDom( root: HTMLElement ): Run[] {
	const runs: Run[] = [];
	const push = ( run: Run ) => {
		const last = runs[ runs.length - 1 ];
		if ( last && 'text' in last && 'text' in run && !! last.b === !! run.b && !! last.i === !! run.i ) {
			last.text += run.text;
			return;
		}
		runs.push( run );
	};
	const walk = ( node: Node, b: boolean, i: boolean ) => {
		if ( node.nodeType === Node.TEXT_NODE ) {
			const text = ( node.textContent || '' ).replace( / /g, ' ' );
			if ( text ) {
				push( { text, ...( b ? { b } : {} ), ...( i ? { i } : {} ) } );
			}
			return;
		}
		if ( ! ( node instanceof HTMLElement ) ) {
			return;
		}
		if ( node.dataset.field ) {
			push( { field: node.dataset.field, ...( node.dataset.format ? { format: node.dataset.format } : {} ), ...( b ? { b } : {} ), ...( i ? { i } : {} ) } );
			return;
		}
		if ( node.tagName === 'BR' ) {
			push( { text: '\n', ...( b ? { b } : {} ), ...( i ? { i } : {} ) } );
			return;
		}
		const weight = node.style.fontWeight;
		const nb = b || node.tagName === 'B' || node.tagName === 'STRONG' || weight === 'bold' || Number( weight ) >= 600;
		const ni = i || node.tagName === 'I' || node.tagName === 'EM' || node.style.fontStyle === 'italic';
		node.childNodes.forEach( ( child ) => walk( child, nb, ni ) );
	};
	root.childNodes.forEach( ( child ) => walk( child, false, false ) );
	return runs;
}

export type FieldTextCommands = { bold: () => void; italic: () => void; insertField: ( field: string ) => void };

type FieldTextProps = {
	label: string;
	runs: Run[];
	onChange: ( runs: Run[] ) => void;
	sample: Record< string, string >;
	locale: string;
	// Inline: just the editable (on the canvas), styled like the element; the toolbar lives elsewhere.
	inline?: boolean;
	style?: Record< string, string | number | undefined >;
	commands?: { current: FieldTextCommands | null };
	onDone?: () => void;
	// Bold/italic state of the selection or of the selected field, for the toolbar's toggles.
	onMarks?: ( marks: { b: boolean; i: boolean } ) => void;
	// Preview values editable from a field's popover (the Certificate tab's Preview data).
	preview?: { defaults: Record< string, string >; overrides: Record< string, string >; set: ( key: string, value: string ) => void };
};

export default function FieldText( { label, runs, onChange, sample, locale, inline, style, commands, onDone, onMarks, preview }: FieldTextProps ) {
	const info = fields();
	const ref = useRef< HTMLDivElement >( null );
	const last = useRef< string >( '' );
	const [ chip, setChip ] = useState< { el: HTMLElement; index: number } | null >( null );
	// The fields this element stands for while it shows as a placeholder.
	const placeholder = tokenOnly( runs );
	const tokens = useRef< Run[] | null >( placeholder );
	if ( placeholder ) {
		tokens.current = placeholder;
	}
	const placeholderText = tokens.current ? tokens.current.map( ( r ) => ( 'field' in r ? fieldValue( r.field, r.format, sample, locale ) : r.text ) ).join( '' ) : '';
	const firstField = placeholder?.find( ( r ) => 'field' in r );

	// Only rewrite the DOM when the value changed from outside (undo, another element).
	useLayoutEffect( () => {
		const html = placeholder ? '' : toHtml( runs, info );
		if ( ref.current && html !== last.current && ( placeholder ? ! isBlank( fromDom( ref.current ) ) : toHtml( fromDom( ref.current ), info ) !== html ) ) {
			ref.current.innerHTML = html;
		}
		last.current = html;
	}, [ runs ] );

	const emit = () => {
		if ( ref.current ) {
			const next = fromDom( ref.current );
			if ( isBlank( next ) && tokens.current ) {
				last.current = '';
				onChange( tokens.current );
				return;
			}
			last.current = toHtml( next, info );
			onChange( next );
		}
	};
	const editableClass = ( base: string ) => [ base, placeholder && 'is-placeholder', firstField?.b && 'is-placeholder-b', firstField?.i && 'is-placeholder-i' ].filter( Boolean ).join( ' ' );

	const insertField = ( field: string ) => {
		const root = ref.current;
		if ( ! root ) {
			return;
		}
		root.focus();
		const selection = window.getSelection();
		let range = selection && selection.rangeCount ? selection.getRangeAt( 0 ) : null;
		if ( ! range || ! root.contains( range.commonAncestorContainer ) ) {
			range = document.createRange();
			range.selectNodeContents( root );
			range.collapse( false );
		}
		const span = document.createElement( 'span' );
		span.className = 'evcert-chip';
		span.contentEditable = 'false';
		span.dataset.field = field;
		span.textContent = info[ field ]?.label || field;
		range.deleteContents();
		range.insertNode( span );
		const space = document.createTextNode( ' ' );
		span.after( space );
		range.setStartAfter( space );
		range.collapse( true );
		selection?.removeAllRanges();
		selection?.addRange( range );
		emit();
	};

	// The selected field (clicked chip): Bold/Italic toggle its marks instead of the text selection's.
	const chipRef = useRef< HTMLElement | null >( null );
	const selectChip = ( el: HTMLElement | null ) => {
		chipRef.current?.classList.remove( 'is-selected' );
		chipRef.current = el;
		el?.classList.add( 'is-selected' );
	};
	const reportMarks = () => {
		if ( ! onMarks ) {
			return;
		}
		const root = ref.current;
		if ( chipRef.current && root?.contains( chipRef.current ) ) {
			onMarks( { b: hasMark( chipRef.current, root, 'b' ), i: hasMark( chipRef.current, root, 'i' ) } );
			return;
		}
		const selection = window.getSelection();
		if ( root && selection?.anchorNode && root.contains( selection.anchorNode ) ) {
			onMarks( { b: document.queryCommandState( 'bold' ), i: document.queryCommandState( 'italic' ) } );
		}
	};
	const mark = ( which: 'b' | 'i' ) => {
		if ( chipRef.current && ref.current?.contains( chipRef.current ) ) {
			toggleMark( chipRef.current, ref.current, which );
		} else {
			document.execCommand( which === 'b' ? 'bold' : 'italic' );
		}
		emit();
		reportMarks();
	};

	if ( commands ) {
		commands.current = {
			bold: () => mark( 'b' ),
			italic: () => mark( 'i' ),
			insertField,
		};
	}

	// Keep the toolbar's toggles in step with the caret.
	useEffect( () => {
		const onSelection = () => reportMarks();
		document.addEventListener( 'selectionchange', onSelection );
		return () => document.removeEventListener( 'selectionchange', onSelection );
	}, [] );

	// On the canvas: focus with the caret at the end as soon as editing starts.
	useEffect( () => {
		if ( ! inline || ! ref.current ) {
			return;
		}
		ref.current.focus();
		const range = document.createRange();
		range.selectNodeContents( ref.current );
		range.collapse( false );
		const selection = window.getSelection();
		selection?.removeAllRanges();
		selection?.addRange( range );
	}, [] );

	useEffect( () => {
		const root = ref.current;
		if ( ! root ) {
			return;
		}
		const onClick = ( event: MouseEvent ) => {
			const target = ( event.target as HTMLElement ).closest( '.evcert-chip' ) as HTMLElement | null;
			selectChip( target );
			if ( target ) {
				setChip( { el: target, index: Number( target.dataset.index ?? -1 ) } );
			}
			reportMarks();
		};
		root.addEventListener( 'click', onClick );
		return () => root.removeEventListener( 'click', onClick );
	}, [] );

	const chipField = chip?.el.dataset.field || '';
	const chipInfo = info[ chipField ];

	const chipPopover = chip && chipInfo && (
					<Popover anchor={ chip.el } onClose={ () => setChip( null ) } placement="bottom-start" focusOnMount={ false }>
						<div className="evcert-chip-popover">
							<strong>{ chipInfo.label }</strong>
							{ preview && PREVIEW_KEYS.includes( chipField ) ? (
								<TextControl
									__next40pxDefaultSize
									__nextHasNoMarginBottom
									label={ __( 'Preview value', 'events-certificate' ) }
									value={ preview.overrides[ chipField ] || '' }
									placeholder={ preview.defaults[ chipField ] }
									onChange={ ( v ) => preview.set( chipField, v ) }
								/>
							) : (
								<p className="evcert-chip-sample">{ fieldValue( chipField, chip.el.dataset.format, sample, locale ) }</p>
							) }
							{ chipInfo.formats && (
								<SelectControl
									__next40pxDefaultSize
									label={ __( 'Format', 'events-certificate' ) }
									value={ chip.el.dataset.format || chipInfo.formats[ 0 ].key }
									options={ chipInfo.formats.map( ( f ) => ( { label: `${ f.label } — ${ fieldValue( chipField, f.key, sample, locale ) }`, value: f.key } ) ) }
									onChange={ ( format ) => {
										chip.el.dataset.format = format;
										emit();
										setChip( { ...chip } );
									} }
								/>
							) }
							<Button
								variant="link"
								isDestructive
								onClick={ () => {
									chip.el.remove();
									setChip( null );
									emit();
								} }
							>
								{ __( 'Remove field', 'events-certificate' ) }
							</Button>
						</div>
					</Popover>
	);

	if ( inline ) {
		return (
			<>
			<div
					ref={ ref }
					className={ editableClass( inline ? 'evcert-text evcert-text--editing' : 'evcert-field-text-input' ) }
					data-placeholder={ placeholderText || undefined }
					style={ inline ? style : undefined }
					contentEditable
					suppressContentEditableWarning
					role="textbox"
					aria-multiline="true"
					aria-label={ label }
					onInput={ emit }
					onBlur={ ( event ) => {
						// Leaving the text (not into its chip popover or the toolbar) ends inline editing.
						const next = event.relatedTarget as HTMLElement | null;
						if ( inline && onDone && ! next?.closest( '.components-popover, .evcert-element-toolbar' ) ) {
							onDone();
						}
					} }
					onKeyDown={ ( event ) => {
						if ( inline && event.key === 'Escape' ) {
							event.preventDefault();
							onDone?.();
							return;
						}
						if ( ( event.metaKey || event.ctrlKey ) && ( event.key === 'b' || event.key === 'i' ) ) {
							event.preventDefault();
							mark( event.key );
						} else if ( ! event.metaKey && ! event.ctrlKey && chipRef.current ) {
							selectChip( null );
						}
						event.stopPropagation();
					} }
					onPaste={ ( event ) => {
						event.preventDefault();
						document.execCommand( 'insertText', false, event.clipboardData.getData( 'text/plain' ) );
					} }
				/>
			{ chipPopover }
			</>
		);
	}

	return (
		<div className="evcert-field-text">
			<div className="evcert-field-text-label">{ label }</div>
			<div className="evcert-field-text-toolbar">
				<Button size="small" onMouseDown={ ( e: { preventDefault: () => void } ) => e.preventDefault() } onClick={ () => { document.execCommand( 'bold' ); emit(); } } label={ __( 'Bold', 'events-certificate' ) } shortcut="⌘B">
					<strong>B</strong>
				</Button>
				<Button size="small" onMouseDown={ ( e: { preventDefault: () => void } ) => e.preventDefault() } onClick={ () => { document.execCommand( 'italic' ); emit(); } } label={ __( 'Italic', 'events-certificate' ) } shortcut="⌘I">
					<em>I</em>
				</Button>
				<Dropdown
					popoverProps={ { placement: 'bottom-start' } }
					renderToggle={ ( { isOpen, onToggle } ) => (
						<Button size="small" variant="secondary" onMouseDown={ ( e: { preventDefault: () => void } ) => e.preventDefault() } onClick={ onToggle } aria-expanded={ isOpen }>
							{ __( '+ Field', 'events-certificate' ) }
						</Button>
					) }
					renderContent={ ( { onClose } ) => (
						<>
							{ [ false, true ].map( ( isText ) => (
								<MenuGroup key={ String( isText ) } label={ isText ? __( 'Translated texts', 'events-certificate' ) : __( 'Insert a field', 'events-certificate' ) }>
									{ Object.entries( info )
										.filter( ( [ , f ] ) => ( f.group === 'text' ) === isText )
										.map( ( [ key, f ] ) => (
											<MenuItem
												key={ key }
												info={ isText ? undefined : fieldValue( key, undefined, sample, locale ) }
												onClick={ () => {
													insertField( key );
													onClose();
												} }
											>
												{ f.label }
											</MenuItem>
										) ) }
								</MenuGroup>
							) ) }
						</>
					) }
				/>
			</div>
			<div
				ref={ ref }
				className={ editableClass( inline ? 'evcert-text evcert-text--editing' : 'evcert-field-text-input' ) }
					data-placeholder={ placeholderText || undefined }
				style={ inline ? style : undefined }
				contentEditable
				suppressContentEditableWarning
				role="textbox"
				aria-multiline="true"
				aria-label={ label }
				onInput={ emit }
				onBlur={ ( event ) => {
					// Leaving the text (not into its chip popover or the toolbar) ends inline editing.
					const next = event.relatedTarget as HTMLElement | null;
					if ( inline && onDone && ! next?.closest( '.components-popover, .evcert-element-toolbar' ) ) {
						onDone();
					}
				} }
				onKeyDown={ ( event ) => {
					if ( inline && event.key === 'Escape' ) {
						event.preventDefault();
						onDone?.();
						return;
					}
					if ( ( event.metaKey || event.ctrlKey ) && ( event.key === 'b' || event.key === 'i' ) ) {
						event.preventDefault();
						mark( event.key );
					} else if ( ! event.metaKey && ! event.ctrlKey && chipRef.current ) {
						selectChip( null );
					}
					event.stopPropagation();
				} }
				onPaste={ ( event ) => {
					event.preventDefault();
					document.execCommand( 'insertText', false, event.clipboardData.getData( 'text/plain' ) );
				} }
			/>
			{ chipPopover }
		</div>
	);
}
