/**
 * Element icons, from @wordpress/icons where the block editor has one (text →
 * title, line → separator line, rectangle → square, image, code, grid).
 * Ellipse has no core icon, so it is drawn with the same SVG primitives.
 */
import { Circle, SVG } from '@wordpress/components';
import { code, grid, image, lineSolid, square, title } from '@wordpress/icons';
import type { Element } from './model';

export const ellipse = (
	<SVG xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24">
		<Circle cx="12" cy="12" r="7.25" fill="none" stroke="currentColor" strokeWidth="1.5" />
	</SVG>
);

export function elementIcon( type: Element[ 'type' ] ) {
	return (
		{
			text: title,
			line: lineSolid,
			rect: square,
			ellipse,
			path: square,
			image,
			svg: code,
			qr: grid,
		} as Record< string, JSX.Element >
	 )[ type ];
}
