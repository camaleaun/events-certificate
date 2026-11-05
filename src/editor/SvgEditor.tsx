/**
 * The larger SVG editor, in a modal: a big preview on the left (the path
 * under the pointer or being edited highlighted) and on the right the same
 * rows as the sidebar — the viewBox and one row per path — with more room.
 * Changes are a draft until Apply.
 */
import { Button, Modal, __experimentalHStack as HStack, __experimentalNumberControl as NumberControl } from '@wordpress/components';
import { useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { DropdownRow } from './DesignPanel';
import { color, svgPaths, type Design, type Mode, type SvgEl, type SvgPath } from './model';
import { PathList } from './SvgPanel';

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
	const current = el.color || '$text';

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
					<PathList paths={ paths } current={ current } design={ design } mode={ mode } onPaths={ setPaths } onFocus={ setFocus } placement="bottom-start" onImport={ ( svg ) => { setVw( svg.vw ); setVh( svg.vh ); setPaths( svg.paths ); } } />
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
