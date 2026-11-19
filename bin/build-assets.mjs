#!/usr/bin/env node
/**
 * Builds the plugin's stylesheets and scripts.
 *
 *   assets/scss/*.scss  ->  assets/css/<name>.css       (readable, with a source map)
 *                           assets/css/<name>.min.css   (compressed, no comments)
 *   assets/es/*.js      ->  assets/js/<name>.js         (readable copy)
 *                           assets/js/<name>.min.js     (minified, with a map)
 *
 * Files that start with "_" in scss/ are partials, imported by the entry files (`@use`). Shared partials are
 * found by name: `@use 'tokens'`.
 * The plugin only reads assets/css and assets/js (not in git, made by this script): it loads the readable
 * files with SCRIPT_DEBUG and on the "local" environment, and the .min files elsewhere (see includes/assets.php).
 * The TypeScript of the editor is built apart (wp-build, bin/build-signer.mjs, bin/build-blocks.mjs).
 *
 * Usage: node bin/build-assets.mjs [--watch]
 */

import { existsSync, mkdirSync, readdirSync, readFileSync, watch, writeFileSync } from 'node:fs';
import { basename, dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import * as sass from 'sass';
import { minify } from 'terser';

const root = join( dirname( fileURLToPath( import.meta.url ) ), '..' );
const scssDir = join( root, 'assets', 'scss' );
const esDir = join( root, 'assets', 'es' );
const cssDir = join( root, 'assets', 'css' );
const jsDir = join( root, 'assets', 'js' );
const watching = process.argv.includes( '--watch' );

/** Entry files of a folder: the files with an extension that do not start with "_". */
function entries( dir, extension ) {
	if ( ! existsSync( dir ) ) {
		return [];
	}
	return readdirSync( dir ).filter( ( file ) => file.endsWith( extension ) && ! file.startsWith( '_' ) );
}

function buildCss() {
	for ( const file of entries( scssDir, '.scss' ) ) {
		const name = basename( file, '.scss' );
		mkdirSync( cssDir, { recursive: true } );

		const expanded = sass.compile( join( scssDir, file ), { loadPaths: [ scssDir ], style: 'expanded', sourceMap: true, sourceMapIncludeSources: true } );
		writeFileSync( join( cssDir, `${ name }.css` ), `${ expanded.css }\n/*# sourceMappingURL=${ name }.css.map */\n` );
		writeFileSync( join( cssDir, `${ name }.css.map` ), JSON.stringify( expanded.sourceMap ) );

		const compressed = sass.compile( join( scssDir, file ), { loadPaths: [ scssDir ], style: 'compressed' } );
		writeFileSync( join( cssDir, `${ name }.min.css` ), compressed.css );
	}
}

async function buildJs() {
	for ( const file of entries( esDir, '.js' ) ) {
		const name = basename( file, '.js' );
		const code = readFileSync( join( esDir, file ), 'utf8' );
		mkdirSync( jsDir, { recursive: true } );

		writeFileSync( join( jsDir, file ), code );

		const minified = await minify( code, {
			// Our scripts are plain browser scripts; a file with import/export is an ES module.
			module: /^\s*(import|export)\s/m.test( code ),
			compress: true,
			mangle: true,
			format: { comments: false },
			sourceMap: { filename: `${ name }.min.js`, url: `${ name }.min.js.map` },
		} );
		writeFileSync( join( jsDir, `${ name }.min.js` ), minified.code );
		writeFileSync( join( jsDir, `${ name }.min.js.map` ), minified.map );
	}
}

async function build() {
	const started = Date.now();
	buildCss();
	await buildJs();
	console.log( `build-assets: ${ entries( scssDir, '.scss' ).length } stylesheet(s) and ${ entries( esDir, '.js' ).length } script(s) in ${ Date.now() - started } ms.` );
}

if ( watching ) {
	let timer;
	const rebuild = () => build().catch( ( error ) => console.error( error.message ) );

	rebuild();
	const onChange = () => {
		clearTimeout( timer );
		timer = setTimeout( rebuild, 100 );
	};
	watch( scssDir, { recursive: true }, onChange );
	watch( esDir, { recursive: true }, onChange );
	console.log( 'Watching assets/scss/ and assets/es/ ...' );
} else {
	build().catch( ( error ) => {
		console.error( error.message );
		process.exit( 1 );
	} );
}
