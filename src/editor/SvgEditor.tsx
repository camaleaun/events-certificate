/**
 * The larger SVG editor, in a modal: a big preview on the left (the path in
 * focus highlighted) and the same fields as the sidebar on the right — the
 * viewBox and the paths, each a fill and its d value — with more room. Changes
 * are a draft until Apply.
 */
import {
	Button,
	DropdownMenu,
	MenuGroup,
	MenuItem,
	Modal,
	Toolbar,
	ToolbarButton,
	ToolbarGroup,
	ToolbarItem,
	__experimentalHStack as HStack,
	__experimentalNumberControl as NumberControl,
} from '@wordpress/components';
import { Fragment, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { chevronDown, chevronUp, copy, moreVertical, plus, trash } from '@wordpress/icons';
import { elementIcon } from './icons';
import { color, svgPaths, type Design, type Mode, type SvgEl, type SvgPath } from './model';
import { DropdownRow } from './DesignPanel';
import { FillSwatch, PathData } from './SvgPanel';

type Props = {
	el: SvgEl;
	design: Design;
	mode: Mode;
	onApply: ( patch: Partial< SvgEl > ) => void;
	onClose: () => void;
};

export default function SvgEditor( { el, design, mode, onApply, onClose }: Props ) {
	const [ vw, setVw ] = useState( el.vw );
	const [ vh, setVh ] = useState( el.vh );
	const [ paths, setPaths ] = useState< SvgPath[] >( svgPaths( el ) );
	const [ focus, setFocus ] = useState< number | null >( null );
	// Remount the fields when paths are added, removed, moved or imported.
	const [ version, setVersion ] = useState( 0 );
	const current = el.color || '$text';
	const replace = ( next: SvgPath[] ) => {
		setPaths( next );
		setVersion( version + 1 );
	};
	const setPath = ( i: number, patch: Partial< SvgPath > ) => setPaths( paths.map( ( p, j ) => ( j === i ? { ...p, ...patch } : p ) ) );
	/** Inserts an empty path at a position, like the block inserter. */
	const insertAt = ( at: number ) => {
		replace( [ ...paths.slice( 0, at ), { d: '' }, ...paths.slice( at ) ] );
		setFocus( at );
	};
	const move = ( i: number, to: number ) => {
		const next = [ ...paths ];
		const [ p ] = next.splice( i, 1 );
		next.splice( to, 0, p );
		replace( next );
		setFocus( to );
	};

	return (
		<Modal title={ sprintf( /* translators: %s: element name. */ __( 'Edit drawing: %s', 'events-certificate' ), el.name || 'SVG' ) } size="large" onRequestClose={ onClose } className="evcert-svg-editor">
			<div className="evcert-svg-editor__body">
				<div className="evcert-svg-editor__preview">
					<svg viewBox={ `0 0 ${ vw } ${ vh }` } role="img" aria-label={ __( 'Preview', 'events-certificate' ) }>
						<rect width={ vw } height={ vh } className="evcert-svg-editor__box" />
						{ paths.map( ( p, i ) =>
							p.d ? <path key={ i } d={ p.d } fill={ color( design, p.fill || current, mode ) } fillRule={ p.rule } opacity={ focus === null || focus === i ? 1 : 0.25 } /> : null
						) }
					</svg>
				</div>
				<div className="evcert-svg-editor__fields">
					<DropdownRow label={ __( 'ViewBox', 'events-certificate' ) } value={ `${ vw } × ${ vh }` }>
						{ () => (
							<>
								<HStack alignment="top">
									<NumberControl __next40pxDefaultSize label={ __( 'Width', 'events-certificate' ) } min={ 1 } value={ vw } onChange={ ( v ) => Number( v ) > 0 && setVw( Number( v ) ) } />
									<NumberControl __next40pxDefaultSize label={ __( 'Height', 'events-certificate' ) } min={ 1 } value={ vh } onChange={ ( v ) => Number( v ) > 0 && setVh( Number( v ) ) } />
								</HStack>
								<p className="evcert-meta">{ __( 'The coordinate space of the paths.', 'events-certificate' ) }</p>
							</>
						) }
					</DropdownRow>
					<HStack className="evcert-svg-panel__header">
						<h2 className="evcert-section-heading">{ __( 'Paths', 'events-certificate' ) }</h2>
						<Button size="small" icon={ plus } label={ __( 'Add path', 'events-certificate' ) } onClick={ () => insertAt( paths.length ) } />
					</HStack>
					<ol className="evcert-svg-editor__paths">
						{ paths.map( ( p, i ) => (
							<Fragment key={ `${ version }-${ i }` }>
							{ i > 0 && (
								<li className="evcert-path-inserter" aria-hidden={ false }>
									<Button size="small" icon={ plus } label={ sprintf( /* translators: %d: path number. */ __( 'Add a path before path %d', 'events-certificate' ), i + 1 ) } onClick={ () => insertAt( i ) } />
								</li>
							) }
							<li className={ `evcert-path-block${ focus === i ? ' is-selected' : '' }` } onFocusCapture={ () => setFocus( i ) }>
								<Toolbar label={ sprintf( /* translators: %d: path number. */ __( 'Path %d tools', 'events-certificate' ), i + 1 ) } className="evcert-path-block__toolbar">
									<ToolbarGroup>
										<ToolbarButton icon={ elementIcon( 'path' ) } label={ sprintf( /* translators: %d: path number. */ __( 'Path %d', 'events-certificate' ), i + 1 ) } onClick={ () => setFocus( i ) }>
											<span className="evcert-path-block__title">{ sprintf( /* translators: %d: path number. */ __( 'Path %d', 'events-certificate' ), i + 1 ) }</span>
										</ToolbarButton>
									</ToolbarGroup>
									<ToolbarGroup className="evcert-path-block__mover">
										<ToolbarButton icon={ chevronUp } label={ __( 'Move up', 'events-certificate' ) } disabled={ i === 0 } accessibleWhenDisabled onClick={ () => move( i, i - 1 ) } />
										<ToolbarButton icon={ chevronDown } label={ __( 'Move down', 'events-certificate' ) } disabled={ i === paths.length - 1 } accessibleWhenDisabled onClick={ () => move( i, i + 1 ) } />
									</ToolbarGroup>
									<ToolbarGroup>
										<ToolbarItem>
											{ () => <FillSwatch value={ p.fill } current={ current } design={ design } mode={ mode } onChange={ ( fill ) => setPath( i, { fill } ) } /> }
										</ToolbarItem>
									</ToolbarGroup>
									<ToolbarGroup>
										<ToolbarItem>
											{ ( toggleProps ) => (
												<DropdownMenu icon={ moreVertical } label={ __( 'Options', 'events-certificate' ) } toggleProps={ toggleProps as any } popoverProps={ { placement: 'bottom-start' } }>
													{ ( { onClose } ) => (
														<>
															<MenuGroup>
																<MenuItem icon={ copy } onClick={ () => { replace( [ ...paths.slice( 0, i + 1 ), { ...p }, ...paths.slice( i + 1 ) ] ); setFocus( i + 1 ); onClose(); } }>
																	{ __( 'Duplicate', 'events-certificate' ) }
																</MenuItem>
															</MenuGroup>
															<MenuGroup>
																<MenuItem icon={ trash } disabled={ paths.length === 1 } onClick={ () => { replace( paths.filter( ( _, j ) => j !== i ) ); setFocus( null ); onClose(); } }>
																	{ __( 'Delete', 'events-certificate' ) }
																</MenuItem>
															</MenuGroup>
														</>
													) }
												</DropdownMenu>
											) }
										</ToolbarItem>
									</ToolbarGroup>
								</Toolbar>
								<PathData
									multiline
									index={ i }
									value={ p.d }
									onChange={ ( d ) => setPath( i, { d } ) }
									onImport={ ( svg ) => { setVw( svg.vw ); setVh( svg.vh ); replace( svg.paths ); } }
								/>
							</li>
							</Fragment>
						) ) }
					</ol>
					<Button className="evcert-path-appender" icon={ plus } label={ __( 'Add path', 'events-certificate' ) } onClick={ () => insertAt( paths.length ) } />
					<p className="evcert-meta">{ __( 'Paths further down are drawn on top. Without a fill a path uses the current color. Pasting SVG code into a path replaces the drawing with its viewBox and paths; nothing else is kept.', 'events-certificate' ) }</p>
				</div>
			</div>
			<div className="evcert-actions evcert-svg-editor__footer">
				<Button variant="tertiary" __next40pxDefaultSize onClick={ onClose }>
					{ __( 'Cancel', 'events-certificate' ) }
				</Button>
				<Button variant="primary" __next40pxDefaultSize onClick={ () => { onApply( { vw, vh, paths: paths.filter( ( p ) => p.d ), markup: undefined, fill: undefined } ); onClose(); } }>
					{ __( 'Apply', 'events-certificate' ) }
				</Button>
			</div>
		</Modal>
	);
}
