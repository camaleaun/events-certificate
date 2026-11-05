/**
 * Builds the Signer panel (src/signer-panel) for the organizer screen. It
 * imports the design editor's components, which wp-build's packages can't
 * (each package compiles alone), so it's bundled here with esbuild and
 * wp-build's own WordPress externals plugin: @wordpress/* map to wp.*, and an
 * index.min.asset.php lists the script dependencies.
 *
 * Usage: node bin/build-signer.mjs (run by npm run build).
 */
import * as esbuild from 'esbuild';
import { sassPlugin } from 'esbuild-sass-plugin';
import { fileURLToPath, pathToFileURL } from 'url';
import path from 'path';

const root = path.dirname( path.dirname( fileURLToPath( import.meta.url ) ) );
const { createWordpressExternalsPlugin } = await import( pathToFileURL( path.join( root, 'node_modules/@wordpress/build/lib/wordpress-externals-plugin.mjs' ) ).href );
const externals = createWordpressExternalsPlugin( 'evcert', false, {}, 'evcert' );
const extra = [ 'wp-plugins', 'wp-editor', 'wp-core-data', 'wp-data' ];

for ( const minify of [ true, false ] ) {
	await esbuild.build( {
		absWorkingDir: root,
		entryPoints: [ 'src/signer-panel/index.tsx' ],
		bundle: true,
		format: 'iife',
		platform: 'browser',
		target: 'es2019',
		jsx: 'automatic',
		minify,
		sourcemap: ! minify,
		outfile: path.join( root, 'build-signer', minify ? 'index.min.js' : 'index.js' ),
		define: { 'process.env.NODE_ENV': minify ? '"production"' : '"development"' },
		plugins: [ sassPlugin( { type: 'css' } ), externals( 'index.min', 'iife', extra, minify ) ],
		logLevel: 'warning',
	} );
}
console.log( 'build-signer: Signer panel built.' );
