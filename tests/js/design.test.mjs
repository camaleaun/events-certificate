/**
 * The public generator's drawing of the default design (src/quick-generator/design.ts), with a backend
 * that records what it is asked to draw. Run: npm run test:js
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import vm from 'node:vm';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import * as esbuild from 'esbuild';

const root = path.join( path.dirname( fileURLToPath( import.meta.url ) ), '..', '..' );
const base = JSON.parse( readFileSync( path.join( root, 'templates/base-design.json' ), 'utf8' ) );
const PT = 0.3528;

// The module as the browser gets it, with the one WordPress package it imports (translations) stubbed.
const built = await esbuild.build( {
	absWorkingDir: root,
	entryPoints: [ 'src/quick-generator/design.ts' ],
	bundle: true,
	format: 'iife',
	write: false,
	plugins: [
		{
			name: 'stub-i18n',
			setup( build ) {
				build.onResolve( { filter: /^@wordpress\/i18n$/ }, () => ( { path: 'i18n', namespace: 'stub' } ) );
				build.onLoad( { filter: /.*/, namespace: 'stub' }, () => ( { contents: 'export const __ = ( s ) => s;', loader: 'js' } ) );
			},
		},
	],
} );
const sandbox = { window: {} };
vm.runInNewContext( built.outputFiles[ 0 ].text, sandbox );
const { draw } = sandbox.window.evcertDesign;

/** A backend that notes the calls; text is 0.2 mm a letter and a point of size. */
function recorder() {
	const calls = [];
	const alphas = [];
	return {
		calls,
		alphas,
		// The faces the site ships: the generator's own and the design fonts.
		fonts: [ 'CertSerif:400', 'CertSans:400', 'CertSans:600', 'Lora:400', 'Lora:500', 'Lora:600', 'Inter:400', 'Inter:500', 'Inter:600' ],
		alpha: ( a ) => alphas.push( [ calls.length, a ] ),
		rect: ( ...a ) => calls.push( [ 'rect', ...a ] ),
		line: ( ...a ) => calls.push( [ 'line', ...a ] ),
		measure: ( text, font, size, spacing = 0 ) => text.length * size * 0.01 + spacing * text.length,
		text: ( text, x, y, o ) => calls.push( [ 'text', text, x, y, o ] ),
		image: ( ...a ) => calls.push( [ 'image', ...a ] ),
	};
}

const phrases = { title_text: 'Certificate of participation', intro_text: 'This is to certify that', took_part_text: 'took part in', band_text: '', date_label: 'DATE', time_label: 'TIME', venue_label: 'VENUE' };
const values = ( extra = {} ) => ( field ) => ( { ...phrases, name: 'Maria da Conceição', event: 'WordCamp Test', community: 'Comunidade Teste', code: '2611-ABCD-EFGH-JKLM', verify_url: 'example.org/certificate', ...extra }[ field ] || '' );
const texts = ( b ) => b.calls.filter( ( c ) => 'text' === c[ 0 ] );
const element = ( id ) => base.elements.find( ( e ) => e.id === id );

test( 'the page is white where the design has no background, and the band is the design\'s', () => {
	const b = recorder();
	draw( b, base, values(), {}, 'en' );

	assert.deepEqual( b.calls[ 0 ], [ 'rect', 0, 0, base.page.w * PT, base.page.h * PT, '#ffffff' ] );
	const band = element( 'band' );
	assert.deepEqual( b.calls[ 1 ], [ 'rect', band.x * PT, band.y * PT, band.w * PT, band.h * PT, '#1D2A6B' ] );
} );

test( 'a text is drawn where the design puts it, in its font and color, its first baseline from the line height', () => {
	const b = recorder();
	draw( b, base, values(), {}, 'en' );
	const title = element( 'title' );
	const call = texts( b ).find( ( c ) => 'Certificate of participation' === c[ 1 ] );

	assert.ok( call, 'the title is drawn' );
	assert.equal( call[ 2 ], title.x * PT );
	assert.equal( call[ 4 ].font, 'CertSerif:400' );
	assert.equal( call[ 4 ].color, '#1B1F2E' );
	const baseline = ( title.y + title.size * ( ( ( title.lh || 1.2 ) - 1.3 ) / 2 + 0.99 ) ) * PT;
	assert.ok( Math.abs( call[ 3 ] - baseline ) < 1e-9, 'the baseline is where the browser puts it' );
} );

test( 'capital letters, and an empty field draws nothing', () => {
	const b = recorder();
	draw( b, base, values( { community: 'Comunidade Teste', speakers: '' } ), {}, 'en' );

	assert.ok( texts( b ).some( ( c ) => 'COMUNIDADE TESTE' === c[ 1 ] ), 'the community is in capitals' );
	assert.ok( ! texts( b ).some( ( c ) => '' === c[ 1 ].trim() ), 'no empty text is drawn' );
} );

test( 'a label with nothing under it is not drawn: no time, no TIME column', () => {
	const none = recorder();
	draw( none, base, values( { event_time: '', location: 'Recife' } ), {}, 'en' );
	assert.ok( ! texts( none ).some( ( c ) => 'TIME' === c[ 1 ] ), 'no time, no TIME label' );
	assert.ok( texts( none ).some( ( c ) => 'VENUE' === c[ 1 ] ), 'the venue has its label' );

	const timed = recorder();
	draw( timed, base, values( { event_time: 'from 7pm to 9pm' } ), {}, 'en' );
	assert.ok( texts( timed ).some( ( c ) => 'TIME' === c[ 1 ] ), 'with a time, the label is there' );
} );

test( 'what vouches for a record (QR code, code, hint) is not drawn: the generator keeps no record', () => {
	const b = recorder();
	draw( b, base, values(), {}, 'en' );

	assert.ok( ! texts( b ).some( ( c ) => /2611-ABCD|example\.org\/certificate/.test( c[ 1 ] ) ) );
	assert.ok( ! b.calls.some( ( c ) => 'image' === c[ 0 ] ), 'no QR code' );
} );

test( 'the band text is turned about the middle of its box, counter-clockwise for the backends', () => {
	const b = recorder();
	draw( b, base, values( { band_text: 'Meetup' } ), {}, 'en' );
	const call = texts( b ).find( ( c ) => 'MEETUP' === c[ 1 ] );
	const el = element( 'band-text' );

	assert.ok( call, 'the band text is drawn, in capitals' );
	assert.equal( call[ 4 ].angle, 90, 'rotation -90 in the design is 90 counter-clockwise' );
	// Turned about the centre of the box: the text's start lands on the box's vertical axis.
	const cx = ( el.x + el.w / 2 ) * PT;
	assert.ok( Math.abs( call[ 2 ] - cx ) < ( el.size * PT * 2 ), 'x is near the box centre' );
} );

test( 'the signature fills the signature box from its left and bottom; without one nothing is drawn', () => {
	const signature = { src: 'data:image/png;base64,x', ratio: 4, alias: 'sig' };
	const el = element( 'signature' );
	const withSignature = recorder();
	draw( withSignature, base, values(), { signature }, 'en' );
	const image = withSignature.calls.find( ( c ) => 'image' === c[ 0 ] );

	assert.ok( image, 'the signature is drawn' );
	assert.equal( image[ 2 ], el.x * PT );
	assert.ok( Math.abs( image[ 3 ] + image[ 5 ] - ( el.y + el.h ) * PT ) < 1e-9, 'it rests on the bottom of the box' );
	assert.ok( image[ 4 ] <= el.w * PT + 1e-9 && image[ 5 ] <= el.h * PT + 1e-9, 'inside the box' );

	const without = recorder();
	draw( without, base, values(), {}, 'en' );
	assert.ok( ! without.calls.some( ( c ) => 'image' === c[ 0 ] ) );
} );

test( 'a design with no place for the logo gets it in the top right corner, over the title\'s right edge', () => {
	const b = recorder();
	draw( b, base, values(), { logo: { src: 'data:image/png;base64,x', ratio: 2, alias: 'logo' } }, 'en' );
	const image = b.calls.find( ( c ) => 'image' === c[ 0 ] );
	const title = element( 'title' );

	assert.ok( image );
	assert.ok( Math.abs( image[ 2 ] + image[ 4 ] - ( title.x + title.w ) * PT ) < 1e-9, 'its right edge is the title\'s' );
	assert.ok( image[ 3 ] < 30, 'near the top' );
} );

test( 'a design font the site ships is used with its weight; one it does not falls back to the generator\'s own', () => {
	const design = JSON.parse( JSON.stringify( base ) );
	const title = design.elements.find( ( e ) => e.id === 'title' );
	Object.assign( title, { font: 'Lora', weight: 500 } );
	const shipped = recorder();
	draw( shipped, design, values(), {}, 'en' );
	assert.equal( texts( shipped ).find( ( c ) => 'Certificate of participation' === c[ 1 ] )[ 4 ].font, 'Lora:500' );

	Object.assign( title, { font: 'Georgia', weight: 700 } );
	const other = recorder();
	other.fonts = [ 'CertSerif:400', 'CertSans:400', 'CertSans:600' ];
	draw( other, design, values(), {}, 'en' );
	assert.equal( texts( other ).find( ( c ) => 'Certificate of participation' === c[ 1 ] )[ 4 ].font, 'CertSerif:400', 'a serif the site does not ship' );
} );

test( 'an element is as opaque as it says, and the next one is back to full', () => {
	const design = JSON.parse( JSON.stringify( base ) );
	design.elements.find( ( e ) => e.id === 'title' ).opacity = 0.4;
	const b = recorder();
	draw( b, design, values(), {}, 'en' );

	const titleAt = b.calls.findIndex( ( c ) => 'text' === c[ 0 ] && 'Certificate of participation' === c[ 1 ] );
	const before = b.alphas.filter( ( a ) => a[ 0 ] <= titleAt ).pop();
	assert.equal( before[ 1 ], 0.4, 'the title is drawn at 0.4' );
	const after = b.alphas.find( ( a ) => a[ 0 ] > titleAt );
	assert.equal( after[ 1 ], 1, 'the element after it is opaque again' );
	assert.equal( b.alphas[ b.alphas.length - 1 ][ 1 ], 1, 'it ends opaque' );
} );

test( 'a turned picture is placed by the box of its turn, centred where the element is', () => {
	const design = JSON.parse( JSON.stringify( base ) );
	design.elements.push( { id: 'turned', type: 'svg', x: 100, y: 100, w: 200, h: 100, vw: 200, vh: 100, rotation: 90, paths: [ { d: 'M0 0h10v10z', fill: '$primary' } ] } );
	const b = recorder();
	draw( b, design, values(), { art: { turned: { src: 'x', ratio: 0.5, alias: 'a', rot: { w: 100, h: 200 } } } }, 'en' );

	const call = b.calls.find( ( c ) => 'image' === c[ 0 ] );
	assert.ok( call, 'drawn' );
	assert.ok( Math.abs( call[ 2 ] - ( 200 - 50 ) * PT ) < 1e-9, 'x: the centre (200) minus half the turned width' );
	assert.ok( Math.abs( call[ 3 ] - ( 150 - 100 ) * PT ) < 1e-9, 'y: the centre (150) minus half the turned height' );
	assert.ok( Math.abs( call[ 4 ] - 100 * PT ) < 1e-9 && Math.abs( call[ 5 ] - 200 * PT ) < 1e-9, 'the turned size' );
} );

test( 'a text shows the variant of the page\'s language, else its own runs', () => {
	const design = JSON.parse( JSON.stringify( base ) );
	const title = design.elements.find( ( e ) => e.id === 'title' );
	title.runs = [ { text: 'Certificate' } ];
	title.variants = { pt_BR: [ { text: 'Certificado' } ] };

	const portuguese = recorder();
	draw( portuguese, design, values(), {}, 'pt-BR' );
	assert.ok( texts( portuguese ).some( ( c ) => 'CERTIFICADO' === c[ 1 ] || 'Certificado' === c[ 1 ] ), 'the variant of pt-BR (written pt_BR)' );

	const english = recorder();
	draw( english, design, values(), {}, 'en' );
	assert.ok( texts( english ).some( ( c ) => /^certificate$/i.test( c[ 1 ] ) ), 'no variant for English: the text itself' );

	const other = recorder();
	title.variants.pt_BR = [];
	draw( other, design, values(), {}, 'pt-BR' );
	assert.ok( texts( other ).some( ( c ) => /^certificate$/i.test( c[ 1 ] ) ), 'an empty variant keeps the text' );
} );

