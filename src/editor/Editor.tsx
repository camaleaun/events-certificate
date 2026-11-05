/**
 * The design editor: the page as an SVG that fills the window, elements
 * selected and dragged with snapping to the page centre, the safe margin and
 * the other elements, and a floating Dock with everything else.
 */
import apiFetch from '@wordpress/api-fetch';
import { Button, ColorPalette, DropdownMenu, MenuGroup, MenuItem, Modal, Popover, RangeControl, SelectControl, TextareaControl, TextControl, ToggleControl } from '@wordpress/components';
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
	recolor,
	rectPath,
	runsHtml,
	sanitizeSvg,
	runsOf,
	type Box,
	type Design,
	type Element,
	type Mode,
} from './model';
import FieldText from './FieldText';
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

type Guide = { axis: 'x' | 'y'; at: number };
type Drag =
	| null
	| { kind: 'move'; id: string; start: { x: number; y: number }; origin: Element }
	| { kind: 'resize'; id: string; handle: string; start: { x: number; y: number }; origin: Element }
	| { kind: 'point'; id: string; end: 1 | 2; start: { x: number; y: number }; origin: Element };

const SNAP = 5;
const LOCALE = document.documentElement.lang || 'en';
const HANDLES = [ 'nw', 'n', 'ne', 'e', 'se', 's', 'sw', 'w' ];

/** Snaps a moving box to the page and the other elements; returns the offset and the guides to draw. */
function snap( moving: Box, design: Design, exclude: string, threshold: number ) {
	const { w, h, safe } = design.page;
	const xs = [ 0, safe, w / 2, w - safe, w ];
	const ys = [ 0, safe, h / 2, h - safe, h ];
	for ( const el of design.elements ) {
		if ( el.id === exclude || el.hidden ) {
			continue;
		}
		const b = box( el );
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
	const fx = best( xs, [ moving.x, moving.x + moving.w / 2, moving.x + moving.w ] );
	const fy = best( ys, [ moving.y, moving.y + moving.h / 2, moving.y + moving.h ] );
	const guides: Guide[] = [];
	if ( fx ) {
		guides.push( { axis: 'x', at: fx.at } );
	}
	if ( fy ) {
		guides.push( { axis: 'y', at: fy.at } );
	}
	return { dx: fx ? fx.delta : 0, dy: fy ? fy.delta : 0, guides };
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
			const stroke = el.stroke ? color( design, el.stroke, mode ) : undefined;
			return (
				<svg x={ el.x } y={ el.y } width={ el.w } height={ el.h } viewBox={ `0 0 ${ el.vw } ${ el.vh }` } opacity={ opacity } overflow="visible">
					<g fill={ fill || 'currentColor' } style={ { color: color( design, '$text', mode ) } } dangerouslySetInnerHTML={ { __html: recolor( el.markup, fill, stroke ) } } />
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
			const style = {
				fontFamily: `'${ el.font }'`,
				fontWeight: el.weight,
				fontSize: `${ el.size }px`,
				lineHeight: el.lh ?? 1.2,
				letterSpacing: el.ls ? `${ el.ls }px` : undefined,
				color: color( design, el.color, mode ),
				textAlign: el.align || 'left',
				whiteSpace: el.fit ? ( 'nowrap' as const ) : ( 'normal' as const ),
			};
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
	const [ selected, setSelected ] = useState< string | null >( null );
	const [ mode, setMode ] = useState< Mode >( 'light' );
	const [ tab, setTab ] = useState< 'content' | 'art' >( 'art' );
	const [ panel, setPanel ] = useState< 'insert' | 'layers' | null >( null );
	const [ sidebar, setSidebar ] = useState< 'design' | 'element' | null >( 'design' );
	const [ svgModal, setSvgModal ] = useState< string | null >( null );
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
	useEffect( () => {
		fit();
		window.addEventListener( 'resize', fit );
		return () => window.removeEventListener( 'resize', fit );
	}, [ fit ] );

	const commit = ( next: Design ) => dispatch( { type: 'commit', design: next } );
	const update = ( id: string, patch: Partial< Element > ) =>
		commit( { ...design, elements: design.elements.map( ( el ) => ( el.id === id ? ( { ...el, ...patch } as Element ) : el ) ) } );
	const current = shown.elements.find( ( el ) => el.id === selected ) || null;

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
				const s = snap( box( next ), design, drag.id, SNAP / zoom );
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
		setDraft( { ...design, elements: design.elements.map( ( el ) => ( el.id === drag.id ? next : el ) ) } );
	};

	const onPointerUp = () => {
		if ( dragRef.current && draft ) {
			commit( draft );
		}
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
		const copy = { ...moved( el, 10, 10 ), id: newId( el.type ), slot: undefined, name: `${ el.name || el.type } copy` } as Element;
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
					commit( { ...design, elements: design.elements.map( ( e ) => ( e.id === selected ? moved( e, dx, dy ) : e ) ) } );
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
							icon={ plus }
							isPressed={ panel === 'insert' }
							onClick={ () => setPanel( panel === 'insert' ? null : 'insert' ) }
							label={ __( 'Insert', 'events-certificate' ) }
						/>
					) }
					<Button icon={ undoIcon } onClick={ () => dispatch( { type: 'undo' } ) } disabled={ ! history.past.length } accessibleWhenDisabled label={ __( 'Undo', 'events-certificate' ) } />
					<Button icon={ redoIcon } onClick={ () => dispatch( { type: 'redo' } ) } disabled={ ! history.future.length } accessibleWhenDisabled label={ __( 'Redo', 'events-certificate' ) } />
					<Button icon={ listView } isPressed={ panel === 'layers' } onClick={ () => setPanel( panel === 'layers' ? null : 'layers' ) } label={ __( 'Layers', 'events-certificate' ) } />
					<div className="evcert-mode-switch" role="group" aria-label={ __( 'Editing mode', 'events-certificate' ) }>
						<Button size="compact" isPressed={ tab === 'content' } onClick={ () => { setTab( 'content' ); setPanel( null ); } }>
							{ __( 'Content', 'events-certificate' ) }
						</Button>
						<Button size="compact" isPressed={ tab === 'art' } onClick={ () => setTab( 'art' ) }>
							{ __( 'Art', 'events-certificate' ) }
						</Button>
					</div>
				</div>
				<div className="evcert-header-center">
					<button type="button" className="evcert-document-bar" onClick={ () => { setSelected( null ); setSidebar( 'design' ); } }>
						<span className="evcert-document-title">{ title || __( 'Certificate', 'events-certificate' ) }</span>
						<span className="evcert-document-type">· { __( 'Certificate design', 'events-certificate' ) }</span>
					</button>
				</div>
				<div className="evcert-header-right">
					<span className={ `evcert-status is-${ status }` }>{ statusLabel }</span>
					<Button onClick={ () => setMode( mode === 'light' ? 'dark' : 'light' ) } label={ mode === 'light' ? __( 'Preview in dark mode', 'events-certificate' ) : __( 'Preview in light mode', 'events-certificate' ) }>
						{ mode === 'light' ? '☀︎' : '☾' }
					</Button>
					<Button icon={ drawerRight } isPressed={ !! sidebarTab } onClick={ () => setSidebar( sidebarTab ? null : current ? 'element' : 'design' ) } label={ __( 'Settings', 'events-certificate' ) } />
					<Button variant="primary" className="evcert-save" onClick={ save } disabled={ status === 'saving' || status === 'loading' } accessibleWhenDisabled>
						{ __( 'Save', 'events-certificate' ) }
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
						<div className="evcert-panel-header">
							<strong>{ panel === 'insert' ? __( 'Insert', 'events-certificate' ) : __( 'Layers', 'events-certificate' ) }</strong>
							<Button size="small" onClick={ () => setPanel( null ) } label={ __( 'Close', 'events-certificate' ) }>
								×
							</Button>
						</div>
						{ panel === 'insert' && (
							<div className="evcert-insert">
								{ (
									[
										[ 'text', __( 'Text', 'events-certificate' ), 'T' ],
										[ 'line', __( 'Line', 'events-certificate' ), '／' ],
										[ 'rect', __( 'Rectangle', 'events-certificate' ), '▭' ],
										[ 'ellipse', __( 'Ellipse', 'events-certificate' ), '◯' ],
										[ 'image', __( 'Image', 'events-certificate' ), '🖼' ],
										[ 'svg', __( 'SVG code', 'events-certificate' ), '⟨/⟩' ],
									] as const
								 ).map( ( [ type, label, glyph ] ) => (
									<button key={ type } type="button" className="evcert-insert-item" onClick={ () => ( type === 'svg' ? setSvgModal( '' ) : insert( type ) ) }>
										<span className="evcert-insert-icon" aria-hidden="true">{ glyph }</span>
										{ label }
									</button>
								) ) }
								{ ! design.elements.some( ( el ) => el.type === 'qr' ) && (
									<button type="button" className="evcert-insert-item" onClick={ () => insert( 'qr' ) }>
										<span className="evcert-insert-icon" aria-hidden="true">▦</span>
										{ __( 'Authenticity (QR)', 'events-certificate' ) }
									</button>
								) }
							</div>
						) }
						{ panel === 'layers' && (
							<ol className="evcert-layers">
								{ [ ...design.elements ].reverse().map( ( el ) => (
									<li key={ el.id } className={ el.id === selected ? 'is-selected' : '' }>
										<button type="button" className="evcert-layer-name" onClick={ () => { setSelected( el.id ); setSidebar( 'element' ); } }>
											{ layerName( el ) }
											{ el.slot && <span className="evcert-slot">{ el.slot }</span> }
										</button>
										<button type="button" title={ __( 'Show/hide', 'events-certificate' ) } onClick={ () => update( el.id, { hidden: ! el.hidden } ) }>
											{ el.hidden ? '◌' : '●' }
										</button>
										<button type="button" title={ __( 'Lock', 'events-certificate' ) } onClick={ () => update( el.id, { locked: ! el.locked } ) }>
											{ el.locked ? '🔒' : '🔓' }
										</button>
									</li>
								) ) }
							</ol>
						) }
					</aside>
				) }

				<div className="evcert-stage" ref={ wrapRef } onPointerDown={ () => setSelected( null ) }>
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
							{ shown.elements.map( ( el ) =>
								el.hidden ? null : (
									<g
										key={ el.id }
										className={ `evcert-el${ el.locked ? ' is-locked' : '' }` }
										onPointerDown={ ( event ) => {
											if ( tab === 'content' && el.type !== 'text' ) {
												return;
											}
											event.stopPropagation();
											setSelected( el.id );
											setSidebar( 'element' );
											if ( tab === 'art' && ! el.locked ) {
												startDrag( event, { kind: 'move', id: el.id, start: toPage( event ), origin: el } );
											}
										} }
									>
										{ el.rotation && el.type !== 'line' ? (
											<g transform={ `rotate(${ el.rotation } ${ el.x + el.w / 2 } ${ el.y + el.h / 2 })` }>
												<Shape el={ el } design={ shown } mode={ mode } sample={ config.sample } />
											</g>
										) : (
											<Shape el={ el } design={ shown } mode={ mode } sample={ config.sample } />
										) }
									</g>
								)
							) }
						</g>
						{ /* Guides: page, safe margin, snapping. Never in the PDF. */ }
						<g className="evcert-guides" pointerEvents="none">
							<rect x={ 0 } y={ 0 } width={ w } height={ h } className="evcert-trim" />
							<rect x={ safe } y={ safe } width={ w - safe * 2 } height={ h - safe * 2 } className="evcert-safe" />
							{ guides.map( ( g, i ) =>
								g.axis === 'x' ? <line key={ i } x1={ g.at } x2={ g.at } y1={ -bleed } y2={ h + bleed } className="evcert-snap" /> : <line key={ i } y1={ g.at } y2={ g.at } x1={ -bleed } x2={ w + bleed } className="evcert-snap" />
							) }
						</g>
						{ current && selBox && tab === 'art' && (
							<g className="evcert-selection">
								<rect x={ selBox.x } y={ selBox.y } width={ Math.max( 1, selBox.w ) } height={ Math.max( 1, selBox.h ) } />
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
							<Inspector
								el={ current }
								design={ design }
								mode={ mode }
								tab={ tab }
								onChange={ ( patch ) => update( current.id, patch ) }
								onRemove={ () => remove( current.id ) }
								onDuplicate={ () => duplicate( current.id ) }
								onOrder={ ( to ) => reorder( current.id, to ) }
								onImage={ () => imageRef.current?.click() }
							/>
						) }
						{ sidebarTab === 'design' && (
							<div className="evcert-inspector">
								<TextControl __next40pxDefaultSize label={ __( 'Name', 'events-certificate' ) } value={ title } onChange={ setTitle } />
								<p className="evcert-meta">{ sprintf( /* translators: 1: width, 2: height in points. */ __( 'A4 landscape · %1$s × %2$s pt', 'events-certificate' ), String( w ), String( h ) ) }</p>
								<SelectControl
									__next40pxDefaultSize
									label={ __( 'Background', 'events-certificate' ) }
									value={ design.page.bg as any }
									options={ [ { label: __( 'Transparent / Background variable', 'events-certificate' ), value: '$background' }, { label: __( 'White', 'events-certificate' ), value: '#ffffff' } ] }
									onChange={ ( bgValue ) => commit( { ...design, page: { ...design.page, bg: bgValue } } ) }
								/>
								<h3 className="evcert-section-title">{ __( 'Colors', 'events-certificate' ) }</h3>
								<div className="evcert-colors">
									{ Object.entries( design.colors ).map( ( [ key, v ] ) => (
										<div key={ key } className="evcert-color-row">
											<span>{ v.name }</span>
											{ ( [ 'light', 'dark' ] as const ).map( ( m ) => (
												<label key={ m } title={ m === 'light' ? __( 'Light', 'events-certificate' ) : __( 'Dark', 'events-certificate' ) }>
													<span className="evcert-swatch" style={ { background: v[ m ] } } />
													<input type="text" value={ v[ m ] } onChange={ ( event ) => commit( { ...design, colors: { ...design.colors, [ key ]: { ...v, [ m ]: event.target.value } } } ) } />
												</label>
											) ) }
										</div>
									) ) }
								</div>
							</div>
						) }
					</aside>
				) }
			</div>

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
						help={ __( 'Only drawing is kept: scripts, links, styles and embedded content are removed.', 'events-certificate' ) }
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

function ColorField( { label, value, design, mode, onChange }: { label: string; value?: string; design: Design; mode: Mode; onChange: ( v: string ) => void } ) {
	const [ open, setOpen ] = useState( false );
	const vars = Object.entries( design.colors );
	return (
		<div className="evcert-color-field">
			<span>{ label }</span>
			<button type="button" className="evcert-swatch" style={ { background: color( design, value, mode ) } } onClick={ () => setOpen( ! open ) } aria-label={ label } />
			<code>{ value || __( 'none', 'events-certificate' ) }</code>
			{ open && (
				<Popover onClose={ () => setOpen( false ) } placement="left">
					<div className="evcert-color-popover">
						<p>{ __( 'Variables', 'events-certificate' ) }</p>
						<div className="evcert-var-list">
							<button type="button" onClick={ () => onChange( '' ) }>
								{ __( 'None', 'events-certificate' ) }
							</button>
							{ vars.map( ( [ key, v ] ) => (
								<button key={ key } type="button" onClick={ () => onChange( `$${ key }` ) }>
									<span className="evcert-swatch" style={ { background: v[ mode ] } } /> { v.name }
								</button>
							) ) }
						</div>
						<p>{ __( 'Fixed color', 'events-certificate' ) }</p>
						<ColorPalette value={ value?.startsWith( '$' ) ? undefined : value } onChange={ ( c ) => onChange( c || '' ) } clearable />
					</div>
				</Popover>
			) }
		</div>
	);
}

function Inspector( props: {
	el: Element;
	design: Design;
	mode: Mode;
	tab: 'content' | 'art';
	onChange: ( patch: Partial< Element > ) => void;
	onRemove: () => void;
	onDuplicate: () => void;
	onOrder: ( to: 'up' | 'down' | 'top' | 'bottom' ) => void;
	onImage: () => void;
} ) {
	const { el, design, mode, tab, onChange } = props;
	const required = !! el.slot && REQUIRED_SLOTS.includes( el.slot );
	const num = ( v: number ) => Math.round( v * 100 ) / 100;
	return (
		<div className="evcert-inspector">
			<header>
				<strong>{ el.name || el.type }</strong>
				{ el.slot && <span className="evcert-slot">{ el.slot }</span> }
			</header>
			{ el.type === 'text' && (
				<>
					<FieldText
						key={ el.id }
						label={ __( 'Text', 'events-certificate' ) }
						runs={ runsOf( el ) }
						onChange={ ( runs ) => onChange( { runs, text: undefined } as Partial< Element > ) }
						sample={ window.evcertEditor.sample }
						locale={ LOCALE }
					/>
					{ tab === 'art' && (
						<>
							<div className="evcert-row">
								<SelectControl __next40pxDefaultSize label={ __( 'Font', 'events-certificate' ) } value={ el.font as any } options={ [ { label: 'Lora', value: 'Lora' }, { label: 'Inter', value: 'Inter' } ] } onChange={ ( font ) => onChange( { font } ) } />
								<SelectControl
									__next40pxDefaultSize
									label={ __( 'Weight', 'events-certificate' ) }
									value={ String( el.weight ) as any }
									options={ [ { label: '400', value: '400' }, { label: '500', value: '500' }, { label: '600', value: '600' } ] }
									onChange={ ( weight ) => onChange( { weight: Number( weight ) } ) }
								/>
							</div>
							<div className="evcert-row">
								<TextControl __next40pxDefaultSize type="number" label={ __( 'Size (pt)', 'events-certificate' ) } value={ String( num( el.size ) ) } onChange={ ( size ) => onChange( { size: Number( size ) } ) } />
								<TextControl __next40pxDefaultSize type="number" step="0.05" label={ __( 'Line height', 'events-certificate' ) } value={ String( el.lh ?? 1.2 ) } onChange={ ( lh ) => onChange( { lh: Number( lh ) } ) } />
								<TextControl __next40pxDefaultSize type="number" step="0.1" label={ __( 'Spacing', 'events-certificate' ) } value={ String( el.ls ?? 0 ) } onChange={ ( ls ) => onChange( { ls: Number( ls ) } ) } />
							</div>
							<SelectControl
								__next40pxDefaultSize
								label={ __( 'Align', 'events-certificate' ) }
								value={ el.align || 'left' }
								options={ [ { label: __( 'Left', 'events-certificate' ), value: 'left' }, { label: __( 'Center', 'events-certificate' ), value: 'center' }, { label: __( 'Right', 'events-certificate' ), value: 'right' } ] }
								onChange={ ( align ) => onChange( { align: align as 'left' } ) }
							/>
							<ToggleControl label={ __( 'Uppercase', 'events-certificate' ) } checked={ !! el.upper } onChange={ ( upper ) => onChange( { upper } ) } />
							<ToggleControl label={ __( 'Shrink to fit (one line)', 'events-certificate' ) } checked={ !! el.fit } onChange={ ( fit ) => onChange( { fit } ) } />
							<ColorField label={ __( 'Color', 'events-certificate' ) } value={ el.color } design={ design } mode={ mode } onChange={ ( c ) => onChange( { color: c } ) } />
						</>
					) }
				</>
			) }
			{ tab === 'art' && ( el.type === 'rect' || el.type === 'ellipse' || el.type === 'path' ) && (
				<>
					<ColorField label={ __( 'Fill', 'events-certificate' ) } value={ el.fill } design={ design } mode={ mode } onChange={ ( fill ) => onChange( { fill } ) } />
					<ColorField label={ __( 'Stroke', 'events-certificate' ) } value={ el.stroke } design={ design } mode={ mode } onChange={ ( stroke ) => onChange( { stroke } ) } />
					<RangeControl __next40pxDefaultSize label={ __( 'Stroke width', 'events-certificate' ) } value={ el.strokeWidth || 0 } min={ 0 } max={ 60 } step={ 0.5 } onChange={ ( v ) => onChange( { strokeWidth: v ?? 0 } ) } />
					{ el.type === 'rect' && (
						<SelectControl
							__next40pxDefaultSize
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
					) }
					{ el.type === 'rect' && (
						<div className="evcert-row">
							{ [ __( '↖', 'events-certificate' ), __( '↗', 'events-certificate' ), __( '↘', 'events-certificate' ), __( '↙', 'events-certificate' ) ].map( ( label, i ) => (
								<TextControl
									key={ i }
									__next40pxDefaultSize
									type="number"
									label={ label }
									value={ String( ( el.radius || [ 0, 0, 0, 0 ] )[ i ] || 0 ) }
									onChange={ ( v ) => {
										const radius = [ ...( el.radius || [ 0, 0, 0, 0 ] ) ];
										radius[ i ] = Number( v );
										onChange( { radius } );
									} }
								/>
							) ) }
						</div>
					) }
				</>
			) }
			{ tab === 'art' && el.type === 'line' && (
				<>
					<ColorField label={ __( 'Color', 'events-certificate' ) } value={ el.stroke } design={ design } mode={ mode } onChange={ ( stroke ) => onChange( { stroke } ) } />
					<RangeControl __next40pxDefaultSize label={ __( 'Width', 'events-certificate' ) } value={ el.strokeWidth || 1 } min={ 0.25 } max={ 20 } step={ 0.25 } onChange={ ( v ) => onChange( { strokeWidth: v ?? 1 } ) } />
					<SelectControl
						__next40pxDefaultSize
						label={ __( 'Style', 'events-certificate' ) }
						value={ el.dash || 'solid' }
						options={ [ { label: __( 'Solid', 'events-certificate' ), value: 'solid' }, { label: __( 'Dashed', 'events-certificate' ), value: 'dashed' }, { label: __( 'Dotted', 'events-certificate' ), value: 'dotted' } ] }
						onChange={ ( dash ) => onChange( { dash: dash as 'solid' } ) }
					/>
				</>
			) }
			{ tab === 'art' && el.type === 'svg' && (
				<>
					<ColorField label={ __( 'Fill', 'events-certificate' ) } value={ el.fill } design={ design } mode={ mode } onChange={ ( fill ) => onChange( { fill } ) } />
					<ColorField label={ __( 'Stroke', 'events-certificate' ) } value={ el.stroke } design={ design } mode={ mode } onChange={ ( stroke ) => onChange( { stroke } ) } />
					<p className="evcert-meta">{ __( 'Empty keeps the SVG’s own colors.', 'events-certificate' ) }</p>
				</>
			) }
			{ tab === 'art' && el.type === 'image' && (
				<Button variant="secondary" onClick={ props.onImage }>
					{ __( 'Choose image…', 'events-certificate' ) }
				</Button>
			) }
			{ tab === 'art' && (
				<>
					{ el.type !== 'line' && (
						<div className="evcert-row">
							{ ( [ 'x', 'y', 'w', 'h' ] as const ).map( ( k ) => (
								<TextControl key={ k } __next40pxDefaultSize type="number" label={ k.toUpperCase() } value={ String( num( ( el as any )[ k ] ) ) } onChange={ ( v ) => onChange( { [ k ]: Number( v ) } as Partial< Element > ) } />
							) ) }
						</div>
					) }
					{ el.type !== 'line' && (
						<SelectControl
							__next40pxDefaultSize
							label={ __( 'Rotation', 'events-certificate' ) }
							value={ String( el.rotation || 0 ) as any }
							options={ [ { label: '0°', value: '0' }, { label: '90°', value: '90' }, { label: '180°', value: '180' }, { label: '270°', value: '270' } ] }
							onChange={ ( r ) => onChange( { rotation: Number( r ) } ) }
						/>
					) }
					<RangeControl __next40pxDefaultSize label={ __( 'Opacity', 'events-certificate' ) } value={ Math.round( ( el.opacity ?? 1 ) * 100 ) } min={ 0 } max={ 100 } onChange={ ( v ) => onChange( { opacity: ( v ?? 100 ) / 100 } ) } />
					<div className="evcert-actions">
						<Button size="small" onClick={ () => props.onOrder( 'top' ) }>{ __( 'Front', 'events-certificate' ) }</Button>
						<Button size="small" onClick={ () => props.onOrder( 'bottom' ) }>{ __( 'Back', 'events-certificate' ) }</Button>
						<Button size="small" onClick={ props.onDuplicate }>{ __( 'Duplicate', 'events-certificate' ) }</Button>
						<Button size="small" isDestructive disabled={ required } onClick={ props.onRemove } label={ required ? __( 'Required: it can be moved, not removed.', 'events-certificate' ) : undefined } showTooltip={ required }>
							{ __( 'Delete', 'events-certificate' ) }
						</Button>
					</div>
				</>
			) }
		</div>
	);
}
