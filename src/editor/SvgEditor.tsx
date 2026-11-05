/**
 * The larger SVG editor, in a modal: a big preview on the left (the path
 * under the pointer or being edited highlighted) and on the right the same
 * rows as the sidebar — the viewBox and one row per path — with more room.
 * Changes are a draft until Apply.
 */
import { Button, Modal, TextControl, __experimentalHStack as HStack, __experimentalNumberControl as NumberControl } from '@wordpress/components';
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
	const [ title, setTitle ] = useState( el.title || '' );
	const [ vw, setVw ] = useState( el.vw );
	const [ vh, setVh ] = useState( el.vh );
	const [ paths, setPaths ] = useState< SvgPath[] >( svgPaths( el ) );
	const [ focus, setFocus ] = useState< number | null >( null );
	const current = el.color || '$text';

	return (
		<Modal title={ title.trim() ? sprintf( /* translators: %s: title of the SVG. */ __( 'Edit SVG: %s', 'events-certificate' ), title.trim() ) : __( 'Edit SVG', 'events-certificate' ) } size="large" onRequestClose={ onClose } className="evcert-svg-editor">
			<div className="evcert-svg-editor__body">
				<div className="evcert-svg-editor__preview">
					{ /* The checkerboard is the drawing's own area: its viewBox's aspect ratio, centred. */ }
					<svg viewBox={ `0 0 ${ vw } ${ vh }` } role="img" aria-label={ title.trim() || __( 'Preview', 'events-certificate' ) } style={ { '--evcert-ratio': vw / vh } as React.CSSProperties }>
						{ paths.map( ( p, i ) =>
							p.d ? <path key={ i } d={ p.d } fill={ color( design, p.fill || current, mode ) } fillRule={ p.rule } opacity={ focus === null || focus === i ? 1 : 0.25 } /> : null
						) }
					</svg>
				</div>
				<div className="evcert-svg-editor__fields">
					<DropdownRow label={ __( 'Title', 'events-certificate' ) } value={ title.trim() || __( 'None', 'events-certificate' ) }>
						{ () => (
							<TextControl
								__next40pxDefaultSize
								__nextHasNoMarginBottom
								label={ __( 'Title', 'events-certificate' ) }
								value={ title }
								onChange={ setTitle }
							/>
						) }
					</DropdownRow>
					<DropdownRow label={ __( 'ViewBox', 'events-certificate' ) } value={ `${ vw } × ${ vh }` }>
						{ () => (
							<>
								<HStack alignment="top">
									<NumberControl __next40pxDefaultSize label={ __( 'Width', 'events-certificate' ) } min={ 1 } value={ vw } onChange={ ( v ) => Number( v ) > 0 && setVw( Number( v ) ) } />
									<NumberControl __next40pxDefaultSize label={ __( 'Height', 'events-certificate' ) } min={ 1 } value={ vh } onChange={ ( v ) => Number( v ) > 0 && setVh( Number( v ) ) } />
								</HStack>
							</>
						) }
					</DropdownRow>
					<PathList paths={ paths } current={ current } design={ design } mode={ mode } onPaths={ setPaths } onFocus={ setFocus } placement="bottom-start" onImport={ ( svg ) => { setVw( svg.vw ); setVh( svg.vh ); setPaths( svg.paths ); } } />
				</div>
			</div>
			<div className="evcert-actions evcert-svg-editor__footer">
				<Button variant="tertiary" __next40pxDefaultSize onClick={ onClose }>
					{ __( 'Cancel', 'events-certificate' ) }
				</Button>
				<Button variant="primary" __next40pxDefaultSize onClick={ () => { onApply( { title: title.trim() || undefined, vw, vh, paths: paths.filter( ( p ) => p.d ), markup: undefined, fill: undefined } ); onClose(); } }>
					{ __( 'Apply', 'events-certificate' ) }
				</Button>
			</div>
		</Modal>
	);
}
