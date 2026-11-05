/**
 * The design editor: the page as an SVG that fills the window, elements
 * selected and dragged with snapping to the page centre, the safe margin and
 * the other elements, and a floating Dock with everything else.
 */
import apiFetch from '@wordpress/api-fetch';
import { Button, ColorPalette, DropdownMenu, MenuGroup, MenuItem, Modal, Popover, Spinner, RangeControl, SelectControl, TextareaControl, TextControl, ToggleControl } from '@wordpress/components';
import { chevronLeft, drawerRight, listView, moreVertical, plus, redo as redoIcon, undo as undoIcon } from '@wordpress/icons';
import { useCallback, useEffect, useMemo, useReducer, useRef, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import {
	box,
	color,
	create,
	moved,
	newId,
	REQUIRED_SLOTS,
	placed,
	recolor,
	resolve,
	rectPath,
	runsHtml,
	sanitizeSvg,
	runsOf,
	type Box,
	type Design,
	type Element,
	type Mode,
	type Run,
	type TextEl,
} from './model';
import DesignPanel from './DesignPanel';
import ElementToolbar from './ElementToolbar';
import FieldText, { type FieldTextCommands } from './FieldText';
import Inserter from './Inserter';
import Inspector from './Inspector';
import ListView from './ListView';
import base from '../../templates/base-design.json';
import './style.scss';

declare global {
	interface Window {
		evcertEditor: { design: number; exitUrl: string; fonts: string; sample: Record< string, string > };
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

function resized( origin: Element, handle: string, dx: number, dy: number, keepRatio: boolean ): Element {
	if ( origin.type === 'line' ) {
		return origin;
	}
	let { x, y, w, h } = origin;
	if ( handle.includes( 'e' ) ) {
		w = Math.max( 4, w + dx );
	}
	if ( handle.includes( 's' ) ) {
		h = Math.max( 4, h + dy );
	}
	if ( handle.includes( 'w' ) ) {
		const nw = Math.max( 4, w - dx );
		x += w - nw;
		w = nw;
	}
	if ( handle.includes( 'n' ) ) {
		const nh = Math.max( 4, h - dy );
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
	}
	return { ...origin, x, y, w, h };
}

/** One element, as SVG. */
function Shape( { el, design, mode, sample }: { el: Element; design: Design; mode: Mode; sample: Record< string, string > } ) {
	const opacity = el.opacity ?? 1;
	switch ( el.type ) {
		case 'rect':
			return <path d={ rectPath( el.x, el.y, el.w, el.h, el.radius, el.corner ) } fill={ color( design, el.fill, mode ) } stroke={ color( design, el.stroke, mode ) } strokeWidth={ el.strokeWidth || 0 } opacity={ opacity } />;
		case 'svg': {
			const fill = el.fill ? color( design, el.fill, mode ) : undefined;
			return (
				<svg x={ el.x } y={ el.y } width={ el.w } height={ el.h } viewBox={ `0 0 ${ el.vw } ${ el.vh }` } opacity={ opacity } overflow="visible">
					<g fill={ fill || 'currentColor' } style={ { color: color( design, '$text', mode ) } } dangerouslySetInnerHTML={ { __html: recolor( el.markup, fill ) } } />
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
			) : (
				<g opacity={ opacity }>
					<rect x={ el.x } y={ el.y } width={ el.w } height={ el.h } fill="#dcdcde" />
					<text x={ el.x + el.w / 2 } y={ el.y + el.h / 2 } textAnchor="middle" dominantBaseline="middle" fontSize={ 10 } fill="#50575e">
						{ __( 'Image', 'events-certificate' ) }
					</text>
				</g>
			);
		case 'qr':
			// The authenticity slot: a QR-shaped placeholder (the real code is drawn on certificates).
			return (
				<g opacity={ opacity } className="evcert-qr-placeholder">
					<rect x={ el.x } y={ el.y } width={ el.w } height={ el.h } fill={ color( design, '$text', mode ) } opacity={ 0.08 } />
					{ [ [ 0, 0 ], [ 1, 0 ], [ 0, 1 ] ].map( ( [ cx, cy ] ) => (
						<rect key={ `${ cx }${ cy }` } x={ el.x + cx * el.w * 0.64 + el.w * 0.04 } y={ el.y + cy * el.h * 0.64 + el.h * 0.04 } width={ el.w * 0.28 } height={ el.h * 0.28 } fill="none" stroke={ color( design, '$text', mode ) } strokeWidth={ el.w * 0.05 } />
					) ) }
					<rect x={ el.x + el.w * 0.42 } y={ el.y + el.h * 0.42 } width={ el.w * 0.16 } height={ el.h * 0.16 } fill={ color( design, '$text', mode ) } />
				</g>
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
	const [ history, dispatch ] = useReducer( historyReducer, { past: [], present: base as unknown as Design, future: [] } );
	const design = history.present;
	const [ draft, setDraft ] = useState< Design | null >( null );
	const shown = draft || design;
	// Anchored elements resolved to x/y/w/h for drawing, hit testing and snapping.
	const resolvedShown = useMemo( () => ( { ...shown, elements: shown.elements.map( ( e ) => resolve( e, shown.page ) ) } ), [ shown ] );
	const resolvedDesign = useMemo( () => ( { ...design, elements: design.elements.map( ( e ) => resolve( e, design.page ) ) } ), [ design ] );
	const [ selected, setSelected ] = useState< string | null >( null );
	const [ mode, setMode ] = useState< Mode >( 'light' );
	// One mode, like the block editor: click selects and drags, double-click edits text.
	const tab = 'art' as 'content' | 'art';
	const [ panel, setPanel ] = useState< 'insert' | 'layers' | null >( null );
	const [ sidebar, setSidebar ] = useState< 'design' | 'element' | null >( 'design' );
	const [ svgModal, setSvgModal ] = useState< string | null >( null );
	const [ editing, setEditing ] = useState< { id: string; runs: Run[] } | null >( null );
	const textCommands = useRef< FieldTextCommands | null >( null );
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
	const svgRef = useRef< SVGSVGElement >( null );
	const wrapRef = useRef< HTMLDivElement >( null );
	const dragRef = useRef< Drag >( null );
	const fileRef = useRef< HTMLInputElement >( null );
	const imageRef = useRef< HTMLInputElement >( null );
	const savedRef = useRef< Design | null >( null );
	const selRef = useRef< SVGRectElement >( null );
	const [ anchor, setAnchor ] = useState< SVGRectElement | null >( null );
	useEffect( () => setAnchor( selRef.current ), [ selected, tab, zoom ] );

	// Load the design.
	useEffect( () => {
		apiFetch< { title: { raw: string }; content: { raw: string } } >( { path: `/wp/v2/evcert-designs/${ config.design }?context=edit` } )
			.then( ( post ) => {
				let loaded = base as unknown as Design;
				try {
					const parsed = JSON.parse( post.content.raw );
					if ( parsed && Array.isArray( parsed.elements ) ) {
						loaded = parsed;
					}
				} catch ( e ) {}
				savedRef.current = loaded;
				dispatch( { type: 'load', design: loaded } );
				setTitle( post.title.raw );
				setStatus( 'saved' );
			} )
			.catch( () => setStatus( 'error' ) );
	}, [ config.design ] );

	useEffect( () => {
		if ( status !== 'loading' && status !== 'saving' ) {
			setStatus( design === savedRef.current ? 'saved' : 'dirty' );
		}
	}, [ design ] );

	// Fit the page in the window.
	const fit = useCallback( () => {
		const wrap = wrapRef.current;
		if ( ! wrap ) {
			return;
		}
		const pad = window.innerWidth < 600 ? 16 : 64;
		const z = Math.min( ( wrap.clientWidth - pad * 2 ) / design.page.w, ( wrap.clientHeight - pad * 2 - 72 ) / design.page.h );
		setZoom( Math.max( 0.2, Math.min( 3, z ) ) );
	}, [ design.page.w, design.page.h ] );
	const loaded = status !== 'loading';
	useEffect( () => {
		if ( loaded ) {
			fit();
		}
	}, [ loaded ] );
	useEffect( () => {
		fit();
		window.addEventListener( 'resize', fit );
		return () => window.removeEventListener( 'resize', fit );
	}, [ fit ] );

	const commit = ( next: Design ) => dispatch( { type: 'commit', design: next } );
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
			next = resized( drag.origin, drag.handle, dx, dy, event.shiftKey || drag.origin.type === 'qr' );
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
		if ( ! el || ( el.slot && REQUIRED_SLOTS.includes( el.slot ) ) ) {
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
	const insert = ( type: Element[ 'type' ] ) => {
		const el = create( type, design );
		commit( { ...design, elements: [ ...design.elements, el ] } );
		setSelected( el.id );
		setSidebar( 'element' );
		setPanel( null );
		if ( type === 'image' ) {
			imageRef.current?.click();
		}
	};

	const save = async () => {
		setStatus( 'saving' );
		try {
			await apiFetch( { path: `/wp/v2/evcert-designs/${ config.design }`, method: 'POST', data: { title, content: JSON.stringify( design ) } } );
			savedRef.current = design;
			setStatus( 'saved' );
		} catch ( e ) {
			setStatus( 'error' );
		}
	};

	const exportJson = () => {
		const blob = new Blob( [ JSON.stringify( { ...design, $schema: 'events-certificate/design/1' }, null, '\t' ) ], { type: 'application/json' } );
		const a = document.createElement( 'a' );
		a.href = URL.createObjectURL( blob );
		a.download = `${ ( title || 'certificate' ).toLowerCase().replace( /[^a-z0-9]+/g, '-' ) }.certificate.json`;
		a.click();
		URL.revokeObjectURL( a.href );
	};
	const importJson = async ( file: File ) => {
		try {
			const parsed = JSON.parse( await file.text() );
			if ( parsed?.version !== 1 || ! Array.isArray( parsed.elements ) || ! parsed.page ) {
				throw new Error( 'format' );
			}
			delete parsed.$schema;
			commit( { ...( base as unknown as Design ), ...parsed } );
			setSelected( null );
		} catch ( e ) {
			// eslint-disable-next-line no-alert
			window.alert( __( 'This file is not a certificate design.', 'events-certificate' ) );
		}
	};
	const onImage = ( file: File ) => {
		const reader = new FileReader();
		reader.onload = () => selected && update( selected, { src: String( reader.result ) } as Partial< Element > );
		reader.readAsDataURL( file );
	};

	// Keyboard: arrows nudge, Delete removes, Cmd/Ctrl+Z/Shift+Z/D/S.
	useEffect( () => {
		const onKey = ( event: KeyboardEvent ) => {
			const target = event.target as HTMLElement;
			if ( target && ( target.tagName === 'INPUT' || target.tagName === 'TEXTAREA' || target.isContentEditable ) ) {
				return;
			}
			const mod = event.metaKey || event.ctrlKey;
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
	const bg = color( shown, shown.page.bg, mode );
	const selBox = current ? box( current ) : null;
	const textEls = useMemo( () => shown.elements.filter( ( el ) => el.type === 'text' ), [ shown ] );
	const statusLabel = {
		loading: __( 'Loading…', 'events-certificate' ),
		saved: __( 'Saved', 'events-certificate' ),
		dirty: __( 'Unsaved changes', 'events-certificate' ),
		saving: __( 'Saving…', 'events-certificate' ),
		error: __( 'Couldn’t save', 'events-certificate' ),
	}[ status ];

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
					<button type="button" className="evcert-document-bar" onClick={ () => { setSelected( null ); setSidebar( 'design' ); } }>
						<span className="evcert-document-title">{ title || __( 'Certificate', 'events-certificate' ) }</span>
						<span className="evcert-document-type">· { __( 'Certificate design', 'events-certificate' ) }</span>
					</button>
				</div>
				<div className="evcert-header-right">
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
									<MenuItem onClick={ () => { fileRef.current?.click(); onClose(); } }>{ __( 'Import…', 'events-certificate' ) }</MenuItem>
									<MenuItem onClick={ () => { commit( base as unknown as Design ); onClose(); } }>{ __( 'Restore the Base design', 'events-certificate' ) }</MenuItem>
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
							<Inserter hasQr={ design.elements.some( ( el ) => el.type === 'qr' ) } onInsert={ ( type ) => ( type === 'svg' ? setSvgModal( '' ) : insert( type ) ) } onClose={ () => setPanel( null ) } />
						) : (
							<ListView
								elements={ design.elements }
								selected={ selected }
								onSelect={ ( id ) => { setSelected( id ); setSidebar( 'element' ); } }
								onToggle={ ( id, key ) => update( id, { [ key ]: ! design.elements.find( ( e ) => e.id === id )?.[ key ] } as Partial< Element > ) }
								onOrder={ reorder }
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
				<div className="evcert-stage" ref={ wrapRef } hidden={ status === 'loading' } onPointerDown={ () => { if ( editing ) { stopEditing(); } setSelected( null ); } }>
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
												sample={ config.sample }
												locale={ LOCALE }
												style={ textStyle( el, shown, mode ) }
												commands={ textCommands }
												onDone={ stopEditing }
											/>
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
												<Shape el={ el } design={ resolvedShown } mode={ mode } sample={ config.sample } />
											</g>
										) : (
											<Shape el={ el } design={ resolvedShown } mode={ mode } sample={ config.sample } />
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
							<Inspector el={ current } stored={ currentStored } design={ design } mode={ mode } tab={ tab } locale={ LOCALE } sample={ config.sample } onChange={ ( patch ) => update( current.id, patch ) } onImage={ () => imageRef.current?.click() } />
						) }
						{ sidebarTab === 'design' && <DesignPanel design={ design } mode={ mode } title={ title } status={ statusLabel } onTitle={ setTitle } onChange={ commit } /> }
					</aside>
				) }
			</div>

			{ current && ( tab === 'art' || editing ) && ! draft && (
				<ElementToolbar
					anchor={ editing ? editAnchor : anchor }
					editing={ !! editing }
					onBold={ () => textCommands.current?.bold() }
					onItalic={ () => textCommands.current?.italic() }
					onInsertField={ ( field ) => textCommands.current?.insertField( field ) }
					el={ current }
					required={ !! current.slot && REQUIRED_SLOTS.includes( current.slot ) }
					onSelectParent={ () => setSidebar( 'element' ) }
					onOrder={ ( to ) => reorder( current.id, to ) }
					onToggle={ ( key ) => update( current.id, { [ key ]: ! current[ key ] } as Partial< Element > ) }
					onDuplicate={ () => duplicate( current.id ) }
					onRemove={ () => remove( current.id ) }
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
				<Button size="small" onClick={ fit } label={ __( 'Fit to screen', 'events-certificate' ) }>
					{ Math.round( zoom * 100 ) }%
				</Button>
			</footer>

			{ svgModal !== null && (
				<Modal title={ __( 'Insert SVG code', 'events-certificate' ) } onRequestClose={ () => setSvgModal( null ) }>
					<TextareaControl
						__nextHasNoMarginBottom
						label={ __( 'Paste the SVG code (an icon, a logo, an ornament)', 'events-certificate' ) }
						help={ __( 'Only <svg> (size, viewBox) and paths with their fill are kept; groups are unwrapped. Strokes, other shapes, styles, scripts and links are removed.', 'events-certificate' ) }
						value={ svgModal }
						onChange={ setSvgModal }
						rows={ 8 }
					/>
					<div className="evcert-actions" style={ { marginTop: 16 } }>
						<Button
							variant="primary"
							disabled={ ! sanitizeSvg( svgModal ) }
							accessibleWhenDisabled
							onClick={ () => {
								const clean = sanitizeSvg( svgModal );
								if ( ! clean ) {
									return;
								}
								const size = 120;
								const ratio = clean.vw / clean.vh;
								const el = { id: newId( 'svg' ), type: 'svg', name: 'SVG', x: w / 2 - size / 2, y: h / 2 - size / 2 / ratio, w: size, h: size / ratio, ...clean } as Element;
								commit( { ...design, elements: [ ...design.elements, el ] } );
								setSelected( el.id );
								setSidebar( 'element' );
								setSvgModal( null );
								setPanel( null );
							} }
						>
							{ __( 'Insert', 'events-certificate' ) }
						</Button>
						<Button variant="tertiary" onClick={ () => setSvgModal( null ) }>
							{ __( 'Cancel', 'events-certificate' ) }
						</Button>
					</div>
				</Modal>
			) }
			<input ref={ fileRef } type="file" accept="application/json,.json" hidden onChange={ ( event ) => event.target.files?.[ 0 ] && importJson( event.target.files[ 0 ] ) } />
			<input ref={ imageRef } type="file" accept="image/png,image/jpeg,image/svg+xml" hidden onChange={ ( event ) => event.target.files?.[ 0 ] && onImage( event.target.files[ 0 ] ) } />
		</div>
	);
}

