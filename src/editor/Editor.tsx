/**
 * The design editor: the page as an SVG that fills the window, elements
 * selected and dragged with snapping to the page centre, the safe margin and
 * the other elements, and a floating Dock with everything else.
 */
import apiFetch from '@wordpress/api-fetch';
import { Button, ColorPalette, DropdownMenu, MenuGroup, MenuItem, Popover, Snackbar, Spinner, RangeControl, SelectControl, TextControl, ToggleControl } from '@wordpress/components';
import { chevronLeft, drawerRight, external, lineSolid, listView, moreVertical, plus, redo as redoIcon, undo as undoIcon } from '@wordpress/icons';
import { useCallback, useEffect, useMemo, useReducer, useRef, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import {
	box,
	color,
	create,
	moved,
	newId,
	placed,
	fromView,
	migrate,
	toView,
	svgPaths,
	resolve,
	rectPath,
	runsHtml,
	runsOf,
	type Box,
	type Design,
	type Element,
	type Mode,
	type Run,
	type TextEl,
	type View, lockedRatio, isRequired, type SvgPath, pageBg, baseBack, fromSide, toSide, type Side } from './model';
import DesignPanel, { setSample } from './DesignPanel';
import ElementToolbar from './ElementToolbar';
import FieldText, { type FieldTextCommands } from './FieldText';
import Inserter from './Inserter';
import { ImagePlaceholder } from './ImageSource';
import { QR_PLACEHOLDER } from './qr-placeholder';
import ImportModal, { type Imported } from './ImportModal';
import Inspector from './Inspector';
import PreviewDropdown from './PreviewDropdown';
import SvgEditor from './SvgEditor';
import type { Picked } from './media';
import ListView from './ListView';
import { baseDesign, withRequired } from './base';
import './style.scss';

declare global {
	interface Window {
		// development: unfinished features, off unless a constant turns them on (includes/admin-app.php).
		evcertEditor: { design: number; exitUrl: string; fonts: string; sample: Record< string, string >; roles: Record< string, string >; isDefault: boolean; development?: { screenViews?: boolean } };
	}
}

type History = { past: Design[]; present: Design; future: Design[] };
type Action =
	| { type: 'load'; design: Design }
	| { type: 'commit'; design: Design }
	| { type: 'undo' }
	| { type: 'redo' };

function historyReducer( state: History, action: Action ): History {
	switch ( action.type ) {
		case 'load':
			return { past: [], present: action.design, future: [] };
		case 'commit':
			if ( action.design === state.present ) {
				return state;
			}
			return { past: [ ...state.past.slice( -99 ), state.present ], present: action.design, future: [] };
		case 'undo':
			if ( ! state.past.length ) {
				return state;
			}
			return { past: state.past.slice( 0, -1 ), present: state.past[ state.past.length - 1 ], future: [ state.present, ...state.future ] };
		case 'redo':
			if ( ! state.future.length ) {
				return state;
			}
			return { past: [ ...state.past, state.present ], present: state.future[ 0 ], future: state.future.slice( 1 ) };
	}
	return state;
}

type Guide =
	| { axis: 'x' | 'y'; at: number; kind?: 'align' }
	// Equal spacing: a gap from..to along the axis, drawn at "cross" on the other axis.
	| { axis: 'x' | 'y'; kind: 'gap'; from: number; to: number; cross: number };
type Drag =
	| null
	| { kind: 'move'; id: string; start: { x: number; y: number }; origin: Element }
	| { kind: 'resize'; id: string; handle: string; start: { x: number; y: number }; origin: Element }
	| { kind: 'point'; id: string; end: 1 | 2; start: { x: number; y: number }; origin: Element };

const SNAP = 5;

/** A text element's CSS, shared by the canvas and the inline editor. */
function textStyle( el: TextEl, design: Design, mode: Mode ) {
	return {
		fontFamily: `'${ el.font }'`,
		fontWeight: el.weight,
		fontSize: `${ el.size }px`,
		lineHeight: el.lh ?? 1.2,
		letterSpacing: el.ls ? `${ el.ls }px` : undefined,
		color: color( design, el.color, mode ),
		textAlign: el.align || 'left',
		whiteSpace: el.fit ? ( 'nowrap' as const ) : ( 'pre-line' as const ),
		textTransform: el.upper ? ( 'uppercase' as const ) : undefined,
	};
}
const LOCALE = document.documentElement.lang || 'en';
const HANDLES = [ 'nw', 'n', 'ne', 'e', 'se', 's', 'sw', 'w' ];

/** Snaps a moving box to the page and the other elements; returns the offset and the guides to draw. */
function snap( moving: Box, design: Design, exclude: string, threshold: number ) {
	const { w, h, safe } = design.page;
	const xs = [ 0, safe, w / 2, w - safe, w ];
	const ys = [ 0, safe, h / 2, h - safe, h ];
	const others: Box[] = [];
	for ( const el of design.elements ) {
		if ( el.id === exclude || el.hidden ) {
			continue;
		}
		const b = box( el );
		others.push( b );
		xs.push( b.x, b.x + b.w / 2, b.x + b.w );
		ys.push( b.y, b.y + b.h / 2, b.y + b.h );
	}
	const best = ( targets: number[], edges: number[] ) => {
		let found: { delta: number; at: number } | null = null;
		for ( const edge of edges ) {
			for ( const t of targets ) {
				const delta = t - edge;
				if ( Math.abs( delta ) <= threshold && ( ! found || Math.abs( delta ) < Math.abs( found.delta ) ) ) {
					found = { delta, at: t };
				}
			}
		}
		return found;
	};

	/**
	 * Equal spacing along one axis, against neighbours that overlap the moving
	 * box on the other axis: centred between the nearest one on each side, or
	 * repeating the gap between the nearest neighbour and the one past it.
	 */
	const spacing = ( axis: 'x' | 'y' ) => {
		const pos = axis === 'x' ? 'x' : 'y';
		const size = axis === 'x' ? 'w' : 'h';
		const cpos = axis === 'x' ? 'y' : 'x';
		const csize = axis === 'x' ? 'h' : 'w';
		const overlaps = ( b: Box ) => b[ cpos ] < moving[ cpos ] + moving[ csize ] && b[ cpos ] + b[ csize ] > moving[ cpos ];
		const row = others.filter( overlaps );
		const before = row.filter( ( b ) => b[ pos ] + b[ size ] <= moving[ pos ] + threshold ).sort( ( a, b ) => b[ pos ] + b[ size ] - ( a[ pos ] + a[ size ] ) );
		const after = row.filter( ( b ) => b[ pos ] >= moving[ pos ] + moving[ size ] - threshold ).sort( ( a, b ) => a[ pos ] - b[ pos ] );
		const cross = ( b: Box ) => ( Math.max( b[ cpos ], moving[ cpos ] ) + Math.min( b[ cpos ] + b[ csize ], moving[ cpos ] + moving[ csize ] ) ) / 2;
		const candidates: { delta: number; gaps: Guide[] }[] = [];
		const L = before[ 0 ];
		const R = after[ 0 ];
		if ( L && R ) {
			const target = ( L[ pos ] + L[ size ] + R[ pos ] - moving[ size ] ) / 2;
			const at = target;
			candidates.push( {
				delta: target - moving[ pos ],
				gaps: [
					{ axis, kind: 'gap', from: L[ pos ] + L[ size ], to: at, cross: cross( L ) },
					{ axis, kind: 'gap', from: at + moving[ size ], to: R[ pos ], cross: cross( R ) },
				],
			} );
		}
		if ( L && before[ 1 ] ) {
			const gap = L[ pos ] - ( before[ 1 ][ pos ] + before[ 1 ][ size ] );
			const target = L[ pos ] + L[ size ] + gap;
			candidates.push( {
				delta: target - moving[ pos ],
				gaps: [
					{ axis, kind: 'gap', from: before[ 1 ][ pos ] + before[ 1 ][ size ], to: L[ pos ], cross: cross( before[ 1 ] ) },
					{ axis, kind: 'gap', from: L[ pos ] + L[ size ], to: target, cross: cross( L ) },
				],
			} );
		}
		if ( R && after[ 1 ] ) {
			const gap = after[ 1 ][ pos ] - ( R[ pos ] + R[ size ] );
			const target = R[ pos ] - gap - moving[ size ];
			candidates.push( {
				delta: target - moving[ pos ],
				gaps: [
					{ axis, kind: 'gap', from: target + moving[ size ], to: R[ pos ], cross: cross( R ) },
					{ axis, kind: 'gap', from: R[ pos ] + R[ size ], to: after[ 1 ][ pos ], cross: cross( after[ 1 ] ) },
				],
			} );
		}
		return candidates.filter( ( c ) => Math.abs( c.delta ) <= threshold && c.gaps.every( ( g ) => 'to' in g && g.to - g.from >= 2 ) ).sort( ( a, b ) => Math.abs( a.delta ) - Math.abs( b.delta ) )[ 0 ] || null;
	};

	const fx = best( xs, [ moving.x, moving.x + moving.w / 2, moving.x + moving.w ] );
	const fy = best( ys, [ moving.y, moving.y + moving.h / 2, moving.y + moving.h ] );
	const sx = spacing( 'x' );
	const sy = spacing( 'y' );
	const guides: Guide[] = [];
	let dx = 0;
	let dy = 0;
	if ( sx && ( ! fx || Math.abs( sx.delta ) <= Math.abs( fx.delta ) ) ) {
		dx = sx.delta;
		guides.push( ...sx.gaps );
	} else if ( fx ) {
		dx = fx.delta;
		guides.push( { axis: 'x', at: fx.at } );
	}
	if ( sy && ( ! fy || Math.abs( sy.delta ) <= Math.abs( fy.delta ) ) ) {
		dy = sy.delta;
		guides.push( ...sy.gaps );
	} else if ( fy ) {
		dy = fy.delta;
		guides.push( { axis: 'y', at: fy.at } );
	}
	return { dx, dy, guides };
}

/**
 * A box resized by a handle, like vector apps: Shift keeps the proportions,
 * Alt/Option resizes from the centre (the opposite side moves too), both
 * together do both.
 */
function resized( origin: Element, handle: string, dx: number, dy: number, keepRatio: boolean, fromCenter = false ): Element {
	if ( origin.type === 'line' ) {
		return origin;
	}
	const k = fromCenter ? 2 : 1;
	let { x, y, w, h } = origin;
	if ( handle.includes( 'e' ) ) {
		w = Math.max( 4, w + dx * k );
	}
	if ( handle.includes( 's' ) ) {
		h = Math.max( 4, h + dy * k );
	}
	if ( handle.includes( 'w' ) ) {
		const nw = Math.max( 4, w - dx * k );
		x += w - nw;
		w = nw;
	}
	if ( handle.includes( 'n' ) ) {
		const nh = Math.max( 4, h - dy * k );
		y += h - nh;
		h = nh;
	}
	if ( keepRatio && origin.w && origin.h ) {
		const ratio = origin.w / origin.h;
		if ( handle === 'n' || handle === 's' ) {
			w = h * ratio;
		} else {
			h = w / ratio;
		}
		// The side opposite the handle stays put (or the centre, below).
		if ( handle.includes( 'n' ) ) {
			y = origin.y + origin.h - h;
		}
		if ( handle.includes( 'w' ) ) {
			x = origin.x + origin.w - w;
		}
		if ( handle === 'n' || handle === 's' ) {
			x = origin.x + ( origin.w - w ) / 2;
		} else if ( handle === 'e' || handle === 'w' ) {
			y = origin.y + ( origin.h - h ) / 2;
		}
	}
	if ( fromCenter ) {
		x = origin.x + ( origin.w - w ) / 2;
		y = origin.y + ( origin.h - h ) / 2;
	}
	return { ...origin, x, y, w, h };
}

/** A Cmd (Mac) or Ctrl shortcut as shown in menus. */
const primary = ( key: string ) => ( /Mac|iP(hone|ad)/.test( navigator.platform ) ? `⌘${ key }` : `Ctrl+${ key }` );
const displayShortcut = { primary };

const ZOOM_MIN = 0.1;
const ZOOM_MAX = 8;

/** Zoom in the footer: out, the level (a menu: fit, 50–200%), in. */
function ZoomControl( { zoom, onZoom, onFit }: { zoom: number; onZoom: ( z: number ) => void; onFit: () => void } ) {
	return (
		<div className="evcert-zoom">
			<Button size="small" icon={ lineSolid } label={ __( 'Zoom out', 'events-certificate' ) } shortcut={ displayShortcut.primary( '-' ) } onClick={ () => onZoom( zoom / 1.25 ) } disabled={ zoom <= ZOOM_MIN } />
			<DropdownMenu
				icon={ null }
				label={ __( 'Zoom', 'events-certificate' ) }
				text={ `${ Math.round( zoom * 100 ) }%` }
				toggleProps={ { size: 'small', className: 'evcert-zoom__level' } as any }
				popoverProps={ { placement: 'top-end' } }
			>
				{ ( { onClose } ) => (
					<MenuGroup>
						<MenuItem shortcut={ displayShortcut.primary( '0' ) } onClick={ () => { onFit(); onClose(); } }>
							{ __( 'Zoom to fit', 'events-certificate' ) }
						</MenuItem>
						{ [ 0.5, 1, 2 ].map( ( z ) => (
							<MenuItem key={ z } shortcut={ z === 1 ? displayShortcut.primary( '1' ) : undefined } onClick={ () => { onZoom( z ); onClose(); } }>
								{ `${ z * 100 }%` }
							</MenuItem>
						) ) }
					</MenuGroup>
				) }
			</DropdownMenu>
			<Button size="small" icon={ plus } label={ __( 'Zoom in', 'events-certificate' ) } shortcut={ displayShortcut.primary( '+' ) } onClick={ () => onZoom( zoom * 1.25 ) } disabled={ zoom >= ZOOM_MAX } />
		</div>
	);
}

/** One element, as SVG. */
/** The organizer's signature from the sample data (JSON), if any. */
function signatureOf( sample: Record< string, string > ): { vw: number; vh: number; paths: SvgPath[] } | null {
	try {
		const sig = sample.signature ? JSON.parse( sample.signature ) : null;
		return sig && sig.vw > 0 && sig.vh > 0 && Array.isArray( sig.paths ) && sig.paths.length ? sig : null;
	} catch {
		return null;
	}
}

function Shape( { el, design, mode, sample }: { el: Element; design: Design; mode: Mode; sample: Record< string, string > } ) {
	const opacity = el.opacity ?? 1;
	switch ( el.type ) {
		case 'rect':
			return <path d={ rectPath( el.x, el.y, el.w, el.h, el.radius, el.corner ) } fill={ color( design, el.fill, mode ) } stroke={ color( design, el.stroke, mode ) } strokeWidth={ el.strokeWidth || 0 } opacity={ opacity } />;
		case 'svg': {
			// The signature element previews the organizer's signature when one is set, upright like on certificates (includes/render.php).
			const signer = el.slot === 'signature' || el.id === 'signature' ? signatureOf( sample ) : null;
			if ( signer ) {
				const turn = ( ( ( el.rotation || 0 ) % 360 ) + 360 ) % 360;
				const swap = turn === 90 || turn === 270;
				const cx = el.x + el.w / 2;
				const cy = el.y + el.h / 2;
				const w = swap ? el.h : el.w;
				const h = swap ? el.w : el.h;
				return (
					<g transform={ turn ? `rotate(${ -turn } ${ cx } ${ cy })` : undefined }>
						<svg x={ cx - w / 2 } y={ cy - h / 2 } width={ w } height={ h } viewBox={ `0 0 ${ signer.vw } ${ signer.vh }` } opacity={ opacity } overflow="hidden">
							{ signer.paths.map( ( p, i ) => (
								<path key={ i } d={ p.d } fill={ color( design, p.fill || el.color || '$text', mode ) } fillRule={ p.rule } />
							) ) }
						</svg>
					</g>
				);
			}
			return (
				// Clipped to the viewBox, as certificates draw it (an <img> of the SVG).
				<svg x={ el.x } y={ el.y } width={ el.w } height={ el.h } viewBox={ `0 0 ${ el.vw } ${ el.vh }` } opacity={ opacity } overflow="hidden">
					{ svgPaths( el ).map( ( p, i ) => (
						<path key={ i } d={ p.d } fill={ color( design, p.fill || el.color || '$text', mode ) } fillRule={ p.rule } />
					) ) }
				</svg>
			);
		}
		case 'ellipse':
			return <ellipse cx={ el.x + el.w / 2 } cy={ el.y + el.h / 2 } rx={ el.w / 2 } ry={ el.h / 2 } fill={ color( design, el.fill, mode ) } stroke={ color( design, el.stroke, mode ) } strokeWidth={ el.strokeWidth || 0 } opacity={ opacity } />;
		case 'path':
			return (
				<svg x={ el.x } y={ el.y } width={ el.w } height={ el.h } viewBox={ `0 0 ${ el.vw } ${ el.vh }` } preserveAspectRatio="none" overflow="visible">
					<path d={ el.d } fill={ color( design, el.fill, mode ) } stroke={ color( design, el.stroke, mode ) } strokeWidth={ el.strokeWidth || 0 } opacity={ opacity } vectorEffect="non-scaling-stroke" />
				</svg>
			);
		case 'line': {
			const dash = el.dash === 'dashed' ? '6 4' : el.dash === 'dotted' ? '1 3' : undefined;
			return <line x1={ el.x1 } y1={ el.y1 } x2={ el.x2 } y2={ el.y2 } stroke={ color( design, el.stroke, mode ) } strokeWidth={ el.strokeWidth || 1 } strokeDasharray={ dash } strokeLinecap="round" opacity={ opacity } />;
		}
		case 'image':
			return el.src ? (
				<image href={ el.src } x={ el.x } y={ el.y } width={ el.w } height={ el.h } preserveAspectRatio={ el.fit === 'cover' ? 'xMidYMid slice' : 'xMidYMid meet' } opacity={ opacity } />
			) : null;
		case 'qr':
			// The authenticity slot: a QR-shaped placeholder (the real code is drawn on certificates).
			return (
				<svg x={ el.x } y={ el.y } width={ el.w } height={ el.h } viewBox={ QR_PLACEHOLDER.viewBox } opacity={ opacity } className="evcert-qr-placeholder" overflow="visible">
					<g fill={ color( design, '$text', mode ) } dangerouslySetInnerHTML={ { __html: QR_PLACEHOLDER.markup } } />
				</svg>
			);
		case 'text': {
			const style = textStyle( el, design, mode );
			return (
				<foreignObject x={ el.x } y={ el.y } width={ el.w } height={ Math.max( el.h, el.size * ( el.lh ?? 1.2 ) * 6 ) } opacity={ opacity } style={ { overflow: 'visible' } }>
					<div className="evcert-text" style={ style } dangerouslySetInnerHTML={ { __html: runsHtml( runsOf( el ), sample, LOCALE, el.upper ) } } />
				</foreignObject>
			);
		}
	}
	return null;
}

export default function Editor() {
	const config = window.evcertEditor;
	const [ history, dispatch ] = useReducer( historyReducer, { past: [], present: baseDesign(), future: [] } );
	// The stored design, and the design as the current view shows it (the print page or a screen).
	const stored = history.present;
	const [ view, setView ] = useState< View >( { device: 'page', orientation: 'landscape' } );
	// The sheet being designed: the certificate (front) or the declaration page (back).
	const [ side, setSide ] = useState< Side >( 'front' );
	const design = useMemo( () => toView( toSide( stored, side ), view ), [ stored, view, side ] );
	const [ draft, setDraft ] = useState< Design | null >( null );
	const shown = draft || design;
	// Preview values: the design's own, where set (an empty one means not set), over the defaults.
	const sample = useMemo( () => ( { ...config.sample, ...Object.fromEntries( Object.entries( design.sample || {} ).filter( ( [ , v ] ) => v !== '' ) ) } ), [ design.sample ] );
	// Anchored elements resolved to x/y/w/h for drawing, hit testing and snapping.
	const resolvedShown = useMemo( () => ( { ...shown, elements: shown.elements.map( ( e ) => resolve( e, shown.page ) ) } ), [ shown ] );
	const resolvedDesign = useMemo( () => ( { ...design, elements: design.elements.map( ( e ) => resolve( e, design.page ) ) } ), [ design ] );
	const [ selected, setSelected ] = useState< string | null >( null );
	const [ mode, setMode ] = useState< Mode >( 'light' );
	// One mode, like the block editor: click selects and drags, double-click edits text.
	const tab = 'art' as 'content' | 'art';
	const [ panel, setPanel ] = useState< 'insert' | 'layers' | null >( null );
	const [ sidebar, setSidebar ] = useState< 'design' | 'element' | null >( 'design' );
		const [ editing, setEditing ] = useState< { id: string; runs: Run[] } | null >( null );
	const textCommands = useRef< FieldTextCommands | null >( null );
	const [ marks, setMarks ] = useState( { b: false, i: false } );
	const [ drawing, setDrawing ] = useState< string | null >( null );
	const [ notice, setNotice ] = useState< string | null >( null );
	const [ importing, setImporting ] = useState( false );
	const clickToEdit = useRef< Element | null >( null );
	const [ editAnchor, setEditAnchor ] = useState< HTMLElement | null >( null );
	useEffect( () => {
		setEditAnchor( editing ? ( document.querySelector( '.evcert-text--editing' ) as HTMLElement | null ) : null );
	}, [ editing?.id ] );
	const startEditing = ( el: Element ) => {
		if ( el.type === 'text' ) {
			setSelected( el.id );
			setSidebar( 'element' );
			setEditing( { id: el.id, runs: runsOf( el ) } );
		}
	};
	const stopEditing = () => {
		const original = editing ? design.elements.find( ( e ) => e.id === editing.id ) : null;
		if ( editing && original && JSON.stringify( runsOf( original as TextEl ) ) !== JSON.stringify( editing.runs ) ) {
			update( editing.id, { runs: editing.runs, text: undefined } as Partial< Element > );
		}
		setEditing( null );
	};
	const [ guides, setGuides ] = useState< Guide[] >( [] );
	const [ title, setTitle ] = useState( '' );
	const [ status, setStatus ] = useState< 'loading' | 'saved' | 'dirty' | 'saving' | 'error' >( 'loading' );
	const [ zoom, setZoom ] = useState( 1 );
	const loaded = status !== 'loading';
	const svgRef = useRef< SVGSVGElement >( null );
	const wrapRef = useRef< HTMLDivElement >( null );
	const dragRef = useRef< Drag >( null );
	const savedRef = useRef< Design | null >( null );
	const savedTitleRef = useRef( '' );
	// The roles this template applies to (Designs\ROLES_META); the default template has none.
	const [ roles, setRoles ] = useState< string[] >( [] );
	const savedRolesRef = useRef( '' );
	const selRef = useRef< SVGRectElement >( null );
	const [ anchor, setAnchor ] = useState< SVGRectElement | null >( null );
	useEffect( () => setAnchor( selRef.current ), [ selected, tab, zoom ] );

	// Load the design.
	useEffect( () => {
		apiFetch< { title: { raw: string }; content: { raw: string }; meta?: { _evcert_roles?: string[] } } >( { path: `/wp/v2/evcert-designs/${ config.design }?context=edit` } )
			.then( ( post ) => {
				let loaded = baseDesign();
				try {
					const parsed = JSON.parse( post.content.raw );
					if ( parsed && Array.isArray( parsed.elements ) ) {
						loaded = parsed;
					}
				} catch ( e ) {}
				loaded = withRequired( migrate( loaded ) );
				savedRef.current = loaded;
				dispatch( { type: 'load', design: loaded } );
				savedTitleRef.current = post.title.raw;
				const loadedRoles = post.meta?._evcert_roles || [];
				savedRolesRef.current = loadedRoles.join( ',' );
				setRoles( loadedRoles );
				setTitle( post.title.raw );
				setStatus( 'saved' );
			} )
			.catch( () => setStatus( 'error' ) );
	}, [ config.design ] );

	// Unsaved: the design or its name differ from what was saved.
	useEffect( () => {
		if ( status !== 'loading' && status !== 'saving' ) {
			setStatus( stored === savedRef.current && title === savedTitleRef.current && roles.join( ',' ) === savedRolesRef.current ? 'saved' : 'dirty' );
		}
	}, [ stored, title, roles ] );

	// Fit the page in the window (and keep fitting on resize until zoomed by hand).
	const fittedRef = useRef( true );
	const fitZoom = useCallback( () => {
		const wrap = wrapRef.current;
		if ( ! wrap ) {
			return 1;
		}
		const pad = window.innerWidth < 600 ? 16 : 64;
		const z = Math.min( ( wrap.clientWidth - pad * 2 ) / design.page.w, ( wrap.clientHeight - pad * 2 - 72 ) / design.page.h );
		return Math.max( ZOOM_MIN, Math.min( 3, z ) );
	}, [ design.page.w, design.page.h ] );
	const fit = useCallback( ( force?: unknown ) => {
		if ( force === true ) {
			fittedRef.current = true;
		}
		if ( fittedRef.current ) {
			setZoom( fitZoom() );
		}
	}, [ fitZoom ] );
	/**
	 * Zooms to a level keeping the point under the pointer (or the stage's
	 * centre) where it is, like vector apps.
	 */
	const zoomTo = ( next: number, at?: { clientX: number; clientY: number } ) => {
		const wrap = wrapRef.current;
		const svg = svgRef.current;
		const z = Math.max( ZOOM_MIN, Math.min( ZOOM_MAX, next ) );
		fittedRef.current = false;
		if ( ! wrap || ! svg ) {
			setZoom( z );
			return;
		}
		const stage = wrap.getBoundingClientRect();
		const rect = svg.getBoundingClientRect();
		const px = at ? at.clientX : stage.left + stage.width / 2;
		const py = at ? at.clientY : stage.top + stage.height / 2;
		// The page point under (px, py), to put back there after the zoom.
		const ux = ( px - rect.left ) / zoom;
		const uy = ( py - rect.top ) / zoom;
		setZoom( z );
		requestAnimationFrame( () => {
			const after = svg.getBoundingClientRect();
			wrap.scrollLeft += after.left + ux * z - px;
			wrap.scrollTop += after.top + uy * z - py;
		} );
	};
	const zoomRef = useRef( zoomTo );
	zoomRef.current = zoomTo;

	// Cmd/Ctrl + wheel and trackpad pinch (a wheel with ctrlKey) zoom around the pointer.
	useEffect( () => {
		const wrap = wrapRef.current;
		if ( ! wrap ) {
			return;
		}
		const onWheel = ( event: WheelEvent ) => {
			if ( ! event.ctrlKey && ! event.metaKey ) {
				return;
			}
			event.preventDefault();
			zoomRef.current( zoomLevel.current * Math.exp( -event.deltaY * ( event.ctrlKey && ! event.metaKey ? 0.01 : 0.002 ) ), event );
		};
		wrap.addEventListener( 'wheel', onWheel, { passive: false } );
		return () => wrap.removeEventListener( 'wheel', onWheel );
	}, [ loaded ] );
	const zoomLevel = useRef( zoom );
	zoomLevel.current = zoom;
	useEffect( () => {
		if ( loaded ) {
			fit( true );
		}
	}, [ loaded ] );

	// Space + drag pans the view (the hand tool).
	const [ panning, setPanning ] = useState( false );
	const panRef = useRef< { x: number; y: number; left: number; top: number } | null >( null );
	useEffect( () => {
		const typing = ( t: EventTarget | null ) => !! t && ( ( t as HTMLElement ).isContentEditable || [ 'INPUT', 'TEXTAREA', 'SELECT', 'BUTTON' ].includes( ( t as HTMLElement ).tagName ) );
		const down = ( event: KeyboardEvent ) => {
			if ( event.code === 'Space' && ! typing( event.target ) ) {
				event.preventDefault();
				setPanning( true );
			}
		};
		const up = ( event: KeyboardEvent ) => event.code === 'Space' && setPanning( false );
		window.addEventListener( 'keydown', down );
		window.addEventListener( 'keyup', up );
		return () => {
			window.removeEventListener( 'keydown', down );
			window.removeEventListener( 'keyup', up );
		};
	}, [] );

	useEffect( () => {
		fit();
		window.addEventListener( 'resize', fit );
		return () => window.removeEventListener( 'resize', fit );
	}, [ fit ] );

	const commit = ( next: Design ) => dispatch( { type: 'commit', design: fromSide( stored, fromView( toSide( stored, side ), next, view ), side ) } );
	/** Shows a sheet; the declaration page starts from the standard one the first time. */
	const showSide = ( next: Side ) => {
		if ( next === 'back' && ! stored.back ) {
			dispatch( { type: 'commit', design: { ...stored, back: { elements: baseBack( stored ) } } } );
		}
		setSelected( null );
		setDraft( null );
		setSide( next );
	};
	const replaceDesign = ( next: Design ) => dispatch( { type: 'commit', design: next } );
	const shift = ( e: Element, dx: number, dy: number ): Element => ( e.layout ? placed( e, box( moved( resolve( e, design.page ), dx, dy ) ), design.page ) : moved( e, dx, dy ) );
	const update = ( id: string, patch: Partial< Element > ) =>
		commit( { ...design, elements: design.elements.map( ( el ) => ( el.id === id ? ( { ...el, ...patch } as Element ) : el ) ) } );
	const current = resolvedShown.elements.find( ( el ) => el.id === selected ) || null;
	const currentStored = design.elements.find( ( el ) => el.id === selected ) || null;

	const toPage = ( event: { clientX: number; clientY: number } ) => {
		const rect = svgRef.current!.getBoundingClientRect();
		return { x: ( event.clientX - rect.left ) / zoom - design.page.bleed, y: ( event.clientY - rect.top ) / zoom - design.page.bleed };
	};

	const startDrag = ( event: React.PointerEvent, drag: NonNullable< Drag > ) => {
		event.stopPropagation();
		( event.target as Element & globalThis.Element ).setPointerCapture?.( event.pointerId );
		dragRef.current = drag;
	};

	const onPointerMove = ( event: React.PointerEvent ) => {
		const drag = dragRef.current;
		if ( ! drag ) {
			return;
		}
		const p = toPage( event );
		const dx = p.x - drag.start.x;
		const dy = p.y - drag.start.y;
		let next: Element = drag.origin;
		let found: Guide[] = [];
		if ( drag.kind === 'move' ) {
			next = moved( drag.origin, dx, dy );
			if ( ! event.altKey ) {
				const s = snap( box( next ), resolvedDesign, drag.id, SNAP / zoom );
				next = moved( next, s.dx, s.dy );
				found = s.guides;
			}
		} else if ( drag.kind === 'resize' ) {
			next = resized( drag.origin, drag.handle, dx, dy, event.shiftKey || drag.origin.type === 'qr' || !! lockedRatio( drag.origin ), event.altKey );
		} else if ( drag.origin.type === 'line' ) {
			const key = drag.end === 1 ? { x: 'x1', y: 'y1' } : { x: 'x2', y: 'y2' };
			let nx = ( drag.origin as any )[ key.x ] + dx;
			let ny = ( drag.origin as any )[ key.y ] + dy;
			if ( event.shiftKey ) {
				const ox = drag.end === 1 ? drag.origin.x2 : drag.origin.x1;
				const oy = drag.end === 1 ? drag.origin.y2 : drag.origin.y1;
				const angle = Math.round( Math.atan2( ny - oy, nx - ox ) / ( Math.PI / 4 ) ) * ( Math.PI / 4 );
				const len = Math.hypot( nx - ox, ny - oy );
				nx = ox + Math.cos( angle ) * len;
				ny = oy + Math.sin( angle ) * len;
			}
			next = { ...drag.origin, [ key.x ]: nx, [ key.y ]: ny } as Element;
		}
		setGuides( found );
		setDraft( { ...design, elements: design.elements.map( ( el ) => ( el.id === drag.id ? ( el.layout ? placed( el, box( next ), design.page ) : next ) : el ) ) } );
	};

	const onPointerUp = () => {
		if ( dragRef.current && draft ) {
			commit( draft );
		} else if ( clickToEdit.current ) {
			startEditing( clickToEdit.current );
		}
		clickToEdit.current = null;
		dragRef.current = null;
		setDraft( null );
		setGuides( [] );
	};

	const remove = ( id: string ) => {
		const el = design.elements.find( ( e ) => e.id === id );
		if ( ! el || isRequired( el, design.elements ) ) {
			return;
		}
		commit( { ...design, elements: design.elements.filter( ( e ) => e.id !== id ) } );
		setSelected( null );
	};
	const duplicate = ( id: string ) => {
		const el = design.elements.find( ( e ) => e.id === id );
		if ( ! el ) {
			return;
		}
		const copy = { ...shift( el, 10, 10 ), id: newId( el.type ), slot: undefined, name: `${ el.name || el.type } copy` } as Element;
		commit( { ...design, elements: [ ...design.elements, copy ] } );
		setSelected( copy.id );
	};
	const reorder = ( id: string, to: 'up' | 'down' | 'top' | 'bottom' ) => {
		const list = [ ...design.elements ];
		const i = list.findIndex( ( e ) => e.id === id );
		const [ el ] = list.splice( i, 1 );
		const j = to === 'top' ? list.length : to === 'bottom' ? 0 : to === 'up' ? Math.min( list.length, i + 1 ) : Math.max( 0, i - 1 );
		list.splice( j, 0, el );
		commit( { ...design, elements: list } );
	};
	/** Restacks an element just in front of or behind another (dragging in Layers). */
	const move = ( id: string, target: string, place: 'front' | 'behind' ) => {
		const list = [ ...design.elements ];
		const [ el ] = list.splice( list.findIndex( ( e ) => e.id === id ), 1 );
		const t = list.findIndex( ( e ) => e.id === target );
		if ( ! el || t < 0 ) {
			return;
		}
		list.splice( place === 'front' ? t + 1 : t, 0, el );
		commit( { ...design, elements: list } );
	};
	const insert = ( type: Element[ 'type' ] ) => {
		const el = create( type, design );
		commit( { ...design, elements: [ ...design.elements, el ] } );
		setSelected( el.id );
		setSidebar( 'element' );
		setPanel( null );
	};

	const save = async () => {
		setStatus( 'saving' );
		try {
			await apiFetch( { path: `/wp/v2/evcert-designs/${ config.design }`, method: 'POST', data: { title, content: JSON.stringify( stored ), ...( config.isDefault ? {} : { meta: { _evcert_roles: roles } } ) } } );
			savedRef.current = stored;
			savedTitleRef.current = title;
			savedRolesRef.current = roles.join( ',' );
			setStatus( 'saved' );
		} catch ( e ) {
			setStatus( 'error' );
		}
	};

	const exportJson = () => {
		const blob = new Blob( [ JSON.stringify( { $schema: 'events-certificate/design/1', title, ...stored }, null, '\t' ) ], { type: 'application/json' } );
		const a = document.createElement( 'a' );
		a.href = URL.createObjectURL( blob );
		a.download = `${ ( title || 'certificate' ).toLowerCase().replace( /[^a-z0-9]+/g, '-' ) }.certificate.json`;
		a.click();
		URL.revokeObjectURL( a.href );
	};
	/** Replaces the whole design with an imported one, back on the print page with nothing selected. */
	const applyImport = ( { design: imported, title: importedTitle }: Imported ) => {
		if ( editing ) {
			setEditing( null );
		}
		setDraft( null );
		setDrawing( null );
		setSelected( null );
		setView( { device: 'page', orientation: view.orientation } );
		replaceDesign( withRequired( migrate( { ...baseDesign(), ...imported, version: 1 } as Design ) ) );
		if ( importedTitle ) {
			setTitle( importedTitle );
		}
		setNotice( __( 'Design imported. Save to keep it.', 'events-certificate' ) );
	};
	/** An image picked for an element: keeps the element's width and, unless it covers, the image's ratio. */
	const pickImage = ( id: string, picked: Picked ) => {
		const el = design.elements.find( ( e ) => e.id === id );
		if ( ! el || el.type !== 'image' ) {
			return;
		}
		let next: Element = { ...el, src: picked.src, attachment: picked.attachment, alt: picked.alt ?? el.alt };
		if ( picked.width && picked.height && ( ! el.ratio || el.ratio === 'auto' ) ) {
			const b = box( resolve( el, design.page ) );
			next = placed( next, { ...b, h: Math.round( ( b.w * picked.height ) / picked.width * 100 ) / 100 }, design.page );
		}
		commit( { ...design, elements: design.elements.map( ( e ) => ( e.id === id ? next : e ) ) } );
	};
	const setBox = ( id: string, b: Box ) => commit( { ...design, elements: design.elements.map( ( e ) => ( e.id === id ? placed( e, b, design.page ) : e ) ) } );

	// Keyboard: arrows nudge, Delete removes, Cmd/Ctrl+Z/Shift+Z/D/S.
	useEffect( () => {
		const onKey = ( event: KeyboardEvent ) => {
			const target = event.target as HTMLElement;
			if ( target && ( target.tagName === 'INPUT' || target.tagName === 'TEXTAREA' || target.isContentEditable ) ) {
				return;
			}
			const mod = event.metaKey || event.ctrlKey;
			// Zoom: Cmd/Ctrl + / - / 0 (fit) / 1 (100%), instead of the browser's page zoom.
			if ( mod && ( event.key === '=' || event.key === '+' ) ) {
				event.preventDefault();
				zoomTo( zoom * 1.25 );
				return;
			}
			if ( mod && event.key === '-' ) {
				event.preventDefault();
				zoomTo( zoom / 1.25 );
				return;
			}
			if ( mod && event.key === '0' ) {
				event.preventDefault();
				fit( true );
				return;
			}
			if ( mod && event.key === '1' ) {
				event.preventDefault();
				zoomTo( 1 );
				return;
			}
			if ( mod && event.key.toLowerCase() === 'z' ) {
				event.preventDefault();
				dispatch( { type: event.shiftKey ? 'redo' : 'undo' } );
			} else if ( mod && event.key.toLowerCase() === 's' ) {
				event.preventDefault();
				save();
			} else if ( mod && event.key.toLowerCase() === 'd' && selected ) {
				event.preventDefault();
				duplicate( selected );
			} else if ( ( event.key === 'Delete' || event.key === 'Backspace' ) && selected ) {
				event.preventDefault();
				remove( selected );
			} else if ( event.key === 'Enter' && selected && ! editing ) {
				const el = design.elements.find( ( e ) => e.id === selected );
				if ( el && el.type === 'text' ) {
					event.preventDefault();
					startEditing( el );
				}
			} else if ( event.key === 'Escape' ) {
				setSelected( null );
				setPanel( null );
			} else if ( selected && event.key.startsWith( 'Arrow' ) ) {
				event.preventDefault();
				const step = event.shiftKey ? 10 : 1;
				const el = design.elements.find( ( e ) => e.id === selected );
				if ( el && ! el.locked ) {
					const dx = event.key === 'ArrowLeft' ? -step : event.key === 'ArrowRight' ? step : 0;
					const dy = event.key === 'ArrowUp' ? -step : event.key === 'ArrowDown' ? step : 0;
					commit( { ...design, elements: design.elements.map( ( e ) => ( e.id === selected ? shift( e, dx, dy ) : e ) ) } );
				}
			}
		};
		window.addEventListener( 'keydown', onKey );
		return () => window.removeEventListener( 'keydown', onKey );
	} );

	const { w, h, safe, bleed } = shown.page;
	const bg = color( shown, pageBg( shown.page, mode ), mode );
	const selBox = current ? box( current ) : null;
	const textEls = useMemo( () => shown.elements.filter( ( el ) => el.type === 'text' ), [ shown ] );
	const statusLabel = {
		loading: __( 'Loading…', 'events-certificate' ),
		saved: __( 'Saved', 'events-certificate' ),
		dirty: __( 'Unsaved changes', 'events-certificate' ),
		saving: __( 'Saving…', 'events-certificate' ),
		error: __( 'Couldn’t save', 'events-certificate' ),
	}[ status ];

	/**
	 * Opens the certificate page with the design as it is now (unsaved changes
	 * included) and the preview data. The tab opens first, so browsers don't
	 * block it, then goes to the address once the design is stored.
	 */
	const preview = () => {
		const tab = window.open( '', '_blank' );
		apiFetch< { url: string } >( { path: '/evcert/v1/preview', method: 'POST', data: { design: stored, role: roles[ 0 ] || '' } } )
			.then( ( { url } ) => {
				if ( tab ) {
					tab.location.href = url;
				} else {
					window.open( url, '_blank' );
				}
			} )
			.catch( () => {
				tab?.close();
				setNotice( __( 'Couldn’t open the preview.', 'events-certificate' ) );
			} );
	};

	const layerName = ( el: Element ) => el.name || el.type;
	const sidebarTab = current ? sidebar : sidebar === 'element' ? 'design' : sidebar;

	return (
		<div className={ `evcert-editor is-${ mode }${ sidebarTab ? ' has-sidebar' : '' }${ panel ? ' has-panel' : '' }` }>
			<header className="evcert-header">
				<div className="evcert-header-left">
					<Button className="evcert-back" icon={ chevronLeft } href={ config.exitUrl } label={ __( 'Back to Certificates', 'events-certificate' ) } />
					{ tab === 'art' && (
						<Button
							className="evcert-inserter-toggle"
							variant="primary"
							size="compact"
							icon={ plus }
							isPressed={ panel === 'insert' }
							onClick={ () => setPanel( panel === 'insert' ? null : 'insert' ) }
							label={ panel === 'insert' ? __( 'Close', 'events-certificate' ) : __( 'Add element', 'events-certificate' ) }
						/>
					) }
					<Button size="compact" icon={ undoIcon } onClick={ () => dispatch( { type: 'undo' } ) } disabled={ ! history.past.length } accessibleWhenDisabled label={ __( 'Undo', 'events-certificate' ) } />
					<Button size="compact" icon={ redoIcon } onClick={ () => dispatch( { type: 'redo' } ) } disabled={ ! history.future.length } accessibleWhenDisabled label={ __( 'Redo', 'events-certificate' ) } />
					<Button size="compact" icon={ listView } isPressed={ panel === 'layers' } onClick={ () => setPanel( panel === 'layers' ? null : 'layers' ) } label={ __( 'Layers', 'events-certificate' ) } />
				</div>
				<div className="evcert-header-center">
					<div className="evcert-sides" role="group" aria-label={ __( 'Sheet', 'events-certificate' ) }>
						<Button size="compact" isPressed={ side === 'front' } onClick={ () => showSide( 'front' ) }>
							{ __( 'Certificate', 'events-certificate' ) }
						</Button>
						<Button size="compact" isPressed={ side === 'back' } onClick={ () => showSide( 'back' ) }>
							{ __( 'Declaration', 'events-certificate' ) }
						</Button>
					</div>
					<button type="button" className="evcert-document-bar" onClick={ () => { setSelected( null ); setSidebar( 'design' ); } }>
						<span className="evcert-document-title">{ title || __( 'Certificate', 'events-certificate' ) }</span>
						<span className="evcert-document-type">· { __( 'Certificate design', 'events-certificate' ) }</span>
					</button>
				</div>
				<div className="evcert-header-right">
					{ /* Screen views (Desktop/Tablet/Mobile, orientation): unfinished, behind EVCERT_DEVELOPMENT_SCREEN_VIEWS. */ }
					{ config.development?.screenViews && <PreviewDropdown view={ view } onChange={ ( next ) => { setView( next ); setDraft( null ); } } /> }
					<Button icon={ external } onClick={ preview } label={ __( 'Preview in new tab', 'events-certificate' ) } />
					<Button onClick={ () => setMode( mode === 'light' ? 'dark' : 'light' ) } label={ mode === 'light' ? __( 'Preview in dark mode', 'events-certificate' ) : __( 'Preview in light mode', 'events-certificate' ) }>
						{ mode === 'light' ? '☀︎' : '☾' }
					</Button>
					<Button size="compact" icon={ drawerRight } isPressed={ !! sidebarTab } onClick={ () => setSidebar( sidebarTab ? null : current ? 'element' : 'design' ) } label={ __( 'Settings', 'events-certificate' ) } />
					<Button
						variant="primary"
						size="compact"
						className="evcert-save"
						onClick={ save }
						isBusy={ status === 'saving' }
						disabled={ status !== 'dirty' && status !== 'error' }
						accessibleWhenDisabled
						label={ status === 'error' ? __( 'Couldn’t save. Try again.', 'events-certificate' ) : undefined }
						showTooltip={ status === 'error' }
					>
						{ status === 'saving' ? __( 'Saving…', 'events-certificate' ) : __( 'Save', 'events-certificate' ) }
					</Button>
					<DropdownMenu icon={ moreVertical } label={ __( 'Options', 'events-certificate' ) } popoverProps={ { placement: 'bottom-end' } }>
						{ ( { onClose } ) => (
							<>
								<MenuGroup label={ __( 'Design', 'events-certificate' ) }>
									<MenuItem onClick={ () => { exportJson(); onClose(); } }>{ __( 'Export (JSON)', 'events-certificate' ) }</MenuItem>
									<MenuItem onClick={ () => { setImporting( true ); onClose(); } }>{ __( 'Import…', 'events-certificate' ) }</MenuItem>
									<MenuItem onClick={ () => { replaceDesign( baseDesign() ); onClose(); } }>{ __( 'Restore the Base design', 'events-certificate' ) }</MenuItem>
								</MenuGroup>
								<MenuGroup label={ __( 'View', 'events-certificate' ) }>
									<MenuItem onClick={ () => { fit(); onClose(); } }>{ __( 'Fit to screen', 'events-certificate' ) }</MenuItem>
								</MenuGroup>
							</>
						) }
					</DropdownMenu>
				</div>
			</header>

			<div className="evcert-body">
				{ panel && (
					<aside className="evcert-left-panel">
						{ panel === 'insert' ? (
							<Inserter hasQr={ design.elements.some( ( el ) => el.type === 'qr' ) } onInsert={ insert } onClose={ () => setPanel( null ) } />
						) : (
							<ListView
								elements={ design.elements }
								selected={ selected }
								onSelect={ ( id ) => { setSelected( id ); setSidebar( 'element' ); } }
								onToggle={ ( id, key ) => update( id, { [ key ]: ! design.elements.find( ( e ) => e.id === id )?.[ key ] } as Partial< Element > ) }
								onOrder={ reorder }
								onMove={ move }
								onRename={ ( id, name ) => update( id, { name } as Partial< Element > ) }
								onRemove={ remove }
								onClose={ () => setPanel( null ) }
							/>
						) }
					</aside>
				) }

				{ status === 'loading' && (
					<div className="evcert-loading">
						<Spinner />
					</div>
				) }
				<div
					className={ `evcert-stage${ panning ? ' is-panning' : '' }${ panRef.current ? ' is-grabbing' : '' }` }
					ref={ wrapRef }
					hidden={ status === 'loading' }
					onPointerDownCapture={ ( event ) => {
						if ( ! panning || ! wrapRef.current ) {
							return;
						}
						// The hand tool: nothing gets selected or dragged.
						event.preventDefault();
						event.stopPropagation();
						panRef.current = { x: event.clientX, y: event.clientY, left: wrapRef.current.scrollLeft, top: wrapRef.current.scrollTop };
						( event.currentTarget as HTMLElement ).setPointerCapture( event.pointerId );
					} }
					onPointerMoveCapture={ ( event ) => {
						const p = panRef.current;
						if ( p && wrapRef.current ) {
							event.stopPropagation();
							wrapRef.current.scrollLeft = p.left - ( event.clientX - p.x );
							wrapRef.current.scrollTop = p.top - ( event.clientY - p.y );
						}
					} }
					onPointerUpCapture={ ( event ) => {
						if ( panRef.current ) {
							event.stopPropagation();
							panRef.current = null;
						}
					} }
					onPointerDown={ () => {
						if ( editing ) {
							stopEditing();
						}
						setSelected( null );
					} }
				>
					<svg
						ref={ svgRef }
						className="evcert-canvas"
						width={ ( w + bleed * 2 ) * zoom }
						height={ ( h + bleed * 2 ) * zoom }
						viewBox={ `${ -bleed } ${ -bleed } ${ w + bleed * 2 } ${ h + bleed * 2 }` }
						onPointerMove={ onPointerMove }
						onPointerUp={ onPointerUp }
					>
						<defs>
							<pattern id="evcert-checker" width="12" height="12" patternUnits="userSpaceOnUse">
								<rect width="12" height="12" fill="#ffffff" />
								<rect width="6" height="6" fill="#f0f0f1" />
								<rect x="6" y="6" width="6" height="6" fill="#f0f0f1" />
							</pattern>
							<clipPath id="evcert-page">
								<rect x={ -bleed } y={ -bleed } width={ w + bleed * 2 } height={ h + bleed * 2 } />
							</clipPath>
						</defs>
						<rect x={ -bleed } y={ -bleed } width={ w + bleed * 2 } height={ h + bleed * 2 } fill={ bg === 'transparent' || bg === 'none' ? 'url(#evcert-checker)' : bg } />
						<g clipPath="url(#evcert-page)">
							{ resolvedShown.elements.map( ( el ) =>
								el.hidden ? null : (
									editing && editing.id === el.id && el.type === 'text' ? (
										<foreignObject key={ el.id } x={ el.x } y={ el.y } width={ el.w } height={ Math.max( el.h, el.size * ( el.lh ?? 1.2 ) * 6 ) } style={ { overflow: 'visible' } } onPointerDown={ ( event ) => event.stopPropagation() }>
											<FieldText
												inline
												label={ el.name || __( 'Text', 'events-certificate' ) }
												runs={ editing.runs }
												onChange={ ( runs ) => setEditing( { id: el.id, runs } ) }
												sample={ sample }
												locale={ LOCALE }
												style={ textStyle( el, shown, mode ) }
												commands={ textCommands }
												onMarks={ setMarks }
												preview={ { defaults: config.sample, overrides: design.sample || {}, set: ( key, value ) => commit( { ...design, sample: setSample( design.sample, key, value ) } ) } }
												onDone={ stopEditing }
											/>
										</foreignObject>
									) : el.type === 'image' && ! el.src ? (
										<foreignObject key={ el.id } x={ el.x } y={ el.y } width={ el.w } height={ el.h } className={ `evcert-el${ selected === el.id ? ' is-selected' : '' }` } onPointerDown={ ( event ) => {
											event.stopPropagation();
											setSelected( el.id );
											setSidebar( 'element' );
											if ( ! el.locked && ( event.target as HTMLElement ).closest( 'button, input, a' ) === null ) {
												startDrag( event, { kind: 'move', id: el.id, start: toPage( event ), origin: el } );
											}
										} }>
											<div className="evcert-image-ph">
												<ImagePlaceholder onPick={ ( picked ) => pickImage( el.id, picked ) } />
											</div>
										</foreignObject>
									) : (
									<g
										key={ el.id }
										className={ `evcert-el${ el.locked ? ' is-locked' : '' }` }
										onDoubleClick={ () => startEditing( el ) }
										onPointerDown={ ( event ) => {
											if ( tab === 'content' && el.type !== 'text' ) {
												return;
											}
											event.stopPropagation();
											if ( editing ) {
												stopEditing();
											}
											// A second click on the selected text (without dragging) edits it.
											clickToEdit.current = selected === el.id && el.type === 'text' ? el : null;
											setSelected( el.id );
											setSidebar( 'element' );
											if ( tab === 'content' ) {
												startEditing( el );
												return;
											}
											if ( ! el.locked ) {
												startDrag( event, { kind: 'move', id: el.id, start: toPage( event ), origin: el } );
											}
										} }
									>
										{ el.rotation && el.type !== 'line' ? (
											<g transform={ `rotate(${ el.rotation } ${ el.x + el.w / 2 } ${ el.y + el.h / 2 })` }>
												<Shape el={ el } design={ resolvedShown } mode={ mode } sample={ sample } />
											</g>
										) : (
											<Shape el={ el } design={ resolvedShown } mode={ mode } sample={ sample } />
										) }
									</g>
									)
								)
							) }
						</g>
						{ /* Guides: page, safe margin, snapping. Never in the PDF. */ }
						<g className="evcert-guides" pointerEvents="none">
							<rect x={ 0 } y={ 0 } width={ w } height={ h } className="evcert-trim" />
							<rect x={ safe } y={ safe } width={ w - safe * 2 } height={ h - safe * 2 } className="evcert-safe" />
							{ guides.map( ( g, i ) => {
								if ( g.kind === 'gap' ) {
									const len = Math.round( ( g.to - g.from ) * 10 ) / 10;
									const tick = 4 / zoom;
									const label = `${ len }`;
									const mid = ( g.from + g.to ) / 2;
									return g.axis === 'x' ? (
										<g key={ i } className="evcert-gap">
											<line x1={ g.from } x2={ g.to } y1={ g.cross } y2={ g.cross } />
											<line x1={ g.from } x2={ g.from } y1={ g.cross - tick } y2={ g.cross + tick } />
											<line x1={ g.to } x2={ g.to } y1={ g.cross - tick } y2={ g.cross + tick } />
											<text x={ mid } y={ g.cross - tick * 1.5 } fontSize={ 10 / zoom } textAnchor="middle">{ label }</text>
										</g>
									) : (
										<g key={ i } className="evcert-gap">
											<line y1={ g.from } y2={ g.to } x1={ g.cross } x2={ g.cross } />
											<line y1={ g.from } y2={ g.from } x1={ g.cross - tick } x2={ g.cross + tick } />
											<line y1={ g.to } y2={ g.to } x1={ g.cross - tick } x2={ g.cross + tick } />
											<text x={ g.cross + tick * 1.5 } y={ mid } fontSize={ 10 / zoom } dominantBaseline="middle">{ label }</text>
										</g>
									);
								}
								return g.axis === 'x' ? <line key={ i } x1={ g.at } x2={ g.at } y1={ -bleed } y2={ h + bleed } className="evcert-snap" /> : <line key={ i } y1={ g.at } y2={ g.at } x1={ -bleed } x2={ w + bleed } className="evcert-snap" />;
							} ) }
						</g>
						{ current && selBox && tab === 'art' && ! editing && (
							<g className="evcert-selection">
								<rect ref={ selRef } x={ selBox.x } y={ selBox.y } width={ Math.max( 1, selBox.w ) } height={ Math.max( 1, selBox.h ) } />
								{ current.type === 'line'
									? ( [ 1, 2 ] as const ).map( ( end ) => (
											<circle
												key={ end }
												cx={ end === 1 ? current.x1 : current.x2 }
												cy={ end === 1 ? current.y1 : current.y2 }
												r={ 5 / zoom }
												onPointerDown={ ( event ) => startDrag( event, { kind: 'point', id: current.id, end, start: toPage( event ), origin: current } ) }
											/>
									  ) )
									: ! current.locked &&
									  HANDLES.map( ( hd ) => {
											const hx = selBox.x + ( hd.includes( 'w' ) ? 0 : hd.includes( 'e' ) ? selBox.w : selBox.w / 2 );
											const hy = selBox.y + ( hd.includes( 'n' ) ? 0 : hd.includes( 's' ) ? selBox.h : selBox.h / 2 );
											const s = 8 / zoom;
											return (
												<rect
													key={ hd }
													className={ `evcert-handle is-${ hd }` }
													x={ hx - s / 2 }
													y={ hy - s / 2 }
													width={ s }
													height={ s }
													onPointerDown={ ( event ) => startDrag( event, { kind: 'resize', id: current.id, handle: hd, start: toPage( event ), origin: current } ) }
												/>
											);
									  } ) }
							</g>
						) }
					</svg>
				</div>

				{ sidebarTab && (
					<aside className="evcert-sidebar" aria-label={ __( 'Settings', 'events-certificate' ) }>
						<div className="evcert-sidebar-tabs" role="tablist">
							<button type="button" role="tab" aria-selected={ sidebarTab === 'design' } className={ sidebarTab === 'design' ? 'is-active' : '' } onClick={ () => setSidebar( 'design' ) }>
								{ __( 'Certificate', 'events-certificate' ) }
							</button>
							<button
								type="button"
								role="tab"
								aria-selected={ sidebarTab === 'element' }
								className={ sidebarTab === 'element' ? 'is-active' : '' }
								disabled={ ! current }
								onClick={ () => setSidebar( 'element' ) }
							>
								{ __( 'Element', 'events-certificate' ) }
							</button>
							<Button size="small" className="evcert-sidebar-close" onClick={ () => setSidebar( null ) } label={ __( 'Close settings', 'events-certificate' ) }>
								×
							</Button>
						</div>
						{ sidebarTab === 'element' && current && (
							<Inspector el={ current } stored={ currentStored } design={ design } mode={ mode } tab={ tab } locale={ LOCALE } sample={ sample } onChange={ ( patch ) => update( current.id, patch ) } onBox={ ( b ) => setBox( current.id, b ) } onImage={ ( picked ) => pickImage( current.id, picked ) } />
						) }
						{ sidebarTab === 'design' && <DesignPanel design={ stored } mode={ mode } title={ title } status={ statusLabel } sample={ sample } defaults={ config.sample } onTitle={ setTitle } roles={ roles } roleLabels={ config.roles } isDefault={ config.isDefault } onRoles={ setRoles } onChange={ replaceDesign } /> }
					</aside>
				) }
			</div>

			{ current && ( tab === 'art' || editing ) && ! draft && ! drawing && (
				<ElementToolbar
					anchor={ editing ? editAnchor : anchor }
					editing={ !! editing }
					marks={ marks }
					onBold={ () => textCommands.current?.bold() }
					onItalic={ () => textCommands.current?.italic() }
					onInsertField={ ( field ) => textCommands.current?.insertField( field ) }
					onToggleEdit={ () => ( editing ? stopEditing() : current && startEditing( current ) ) }
					el={ current }
					required={ isRequired( current, design.elements ) }
					onSelectParent={ () => setSidebar( 'element' ) }
					onOrder={ ( to ) => reorder( current.id, to ) }
					onToggle={ ( key ) => update( current.id, { [ key ]: ! current[ key ] } as Partial< Element > ) }
					onDuplicate={ () => duplicate( current.id ) }
					onRemove={ () => remove( current.id ) }
					onImage={ ( picked ) => pickImage( current.id, picked ) }
					onEditDrawing={ () => setDrawing( current.id ) }
				/>
			) }
			<footer className="evcert-footer">
				<nav className="evcert-breadcrumb" aria-label={ __( 'Selection', 'events-certificate' ) }>
					<button type="button" onClick={ () => setSelected( null ) }>
						{ __( 'Certificate', 'events-certificate' ) }
					</button>
					{ current && (
						<>
							<span aria-hidden="true"> › </span>
							<span>{ layerName( current ) }</span>
						</>
					) }
				</nav>
				{ view.device !== 'page' && (
					<span className="evcert-view-badge">
						{ sprintf(
							/* translators: 1: screen (Desktop, Tablet, Mobile), 2: orientation, 3: width, 4: height. */
							__( '%1$s · %2$s · %3$s × %4$s', 'events-certificate' ),
							{ desktop: __( 'Desktop', 'events-certificate' ), tablet: __( 'Tablet', 'events-certificate' ), mobile: __( 'Mobile', 'events-certificate' ) }[ view.device ],
							view.orientation === 'portrait' ? __( 'Portrait', 'events-certificate' ) : __( 'Landscape', 'events-certificate' ),
							String( design.page.w ),
							String( design.page.h )
						) }
					</span>
				) }
				<ZoomControl
					zoom={ zoom }
					onZoom={ ( z ) => zoomTo( z ) }
					onFit={ () => fit( true ) }
				/>
			</footer>

			{ drawing && ( () => {
				const el = design.elements.find( ( e ) => e.id === drawing );
				return el && el.type === 'svg' ? <SvgEditor el={ el } design={ design } mode={ mode } onApply={ ( patch ) => update( el.id, patch as Partial< Element > ) } onClose={ () => setDrawing( null ) } /> : null;
			} )() }
			{ importing && <ImportModal onImport={ applyImport } onClose={ () => setImporting( false ) } /> }
			{ notice && (
				<div className="evcert-snackbar">
					<Snackbar onRemove={ () => setNotice( null ) }>{ notice }</Snackbar>
				</div>
			) }
		</div>
	);
}

