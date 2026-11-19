/**
 * Builds the editor scripts that aren't a wp-build route: the blocks (the
 * generator's and "Check a certificate", src/blocks) and the Generator screen's
 * form (src/generator). Like bin/build-signer.mjs, each is bundled with
 * esbuild and wp-build's WordPress externals plugin: @wordpress/* map to wp.*,
 * and a <name>.min.asset.php lists the script dependencies.
 *
 * Usage: node bin/build-blocks.mjs (run by npm run build).
 */
import * as esbuild from 'esbuild';
import { fileURLToPath, pathToFileURL } from 'url';
import path from 'path';

const root = path.dirname( path.dirname( fileURLToPath( import.meta.url ) ) );
const { createWordpressExternalsPlugin } = await import( pathToFileURL( path.join( root, 'node_modules/@wordpress/build/lib/wordpress-externals-plugin.mjs' ) ).href );
const externals = createWordpressExternalsPlugin( 'evcert', false, {}, 'evcert' );

const entries = {
	blocks: 'src/blocks/index.tsx',
	screen: 'src/generator/index.tsx',
	// The public generator's drawing of the default design (loaded before assets/es/quick-generator.js).
	design: 'src/quick-generator/design.ts',
};

for ( const [ name, entry ] of Object.entries( entries ) ) {
	for ( const minify of [ true, false ] ) {
		await esbuild.build( {
			absWorkingDir: root,
			entryPoints: [ entry ],
			bundle: true,
			format: 'iife',
			platform: 'browser',
			target: 'es2019',
			jsx: 'automatic',
			minify,
			sourcemap: ! minify,
			outfile: path.join( root, 'build-blocks', name + ( minify ? '.min.js' : '.js' ) ),
			define: { 'process.env.NODE_ENV': minify ? '"production"' : '"development"' },
			plugins: [ externals( name + '.min', 'iife', [], minify ) ],
			logLevel: 'warning',
		} );
	}
}
console.log( 'build-blocks: blocks and Generator form built.' );
