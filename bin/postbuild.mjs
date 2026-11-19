/**
 * After `wp-build`: makes the generated PHP pass Plugin Check (WordPress.org).
 *
 * - Every PHP file gets `defined( 'ABSPATH' ) || exit;`.
 * - Loader files whose top-level code uses unprefixed variables ($file,
 *   $routes, $page_slug…) run inside a static closure, so nothing leaks into
 *   the global scope. Files that `return` a value (registries, constants,
 *   *.asset.php) are left as they are.
 * - A page's render function prints no <style>: its critical styles go
 *   with wp_add_inline_style() on the page's prerequisites stylesheet,
 *   which the page's enqueue function already loads (WordPress.org asks
 *   for enqueued styles).
 * - The full-page mode (pages/<id>/page.php) is removed: the plugin only uses
 *   the "-wp-admin" mode, and pages.php loads page.php only if it exists.
 * - build/modules/boot/index.min.asset.php is written: wp-build 0.19's page
 *   reads the @wordpress/boot asset only from the plugin's build. This one
 *   returns, at runtime, the asset of the boot module the site has — the
 *   Gutenberg plugin's when active (it replaces core's), else core's
 *   (WordPress 7.0+). Jetpack copies a polyfill there instead.
 *
 * Run: node bin/postbuild.mjs (part of `npm run build`).
 */
import { mkdirSync, readdirSync, readFileSync, rmSync, statSync, writeFileSync } from 'node:fs';
import { join } from 'node:path';

const BUILD = new URL( '../build/', import.meta.url ).pathname;
const GUARD = "defined( 'ABSPATH' ) || exit;";

function phpFiles( dir ) {
	return readdirSync( dir ).flatMap( ( name ) => {
		const path = join( dir, name );
		if ( statSync( path ).isDirectory() ) {
			return phpFiles( path );
		}
		return name.endsWith( '.php' ) ? [ path ] : [];
	} );
}

// Full-page mode: not used.
for ( const page of readdirSync( join( BUILD, 'pages' ) ) ) {
	rmSync( join( BUILD, 'pages', page, 'page.php' ), { force: true } );
}

// The site's @wordpress/boot asset, at runtime.
mkdirSync( join( BUILD, 'modules', 'boot' ), { recursive: true } );
writeFileSync(
	join( BUILD, 'modules', 'boot', 'index.min.asset.php' ),
	`<?php
/**
 * The asset of the @wordpress/boot script module the site provides
 * (written by bin/postbuild.mjs).
 *
 * @package EventsCertificate
 */

defined( 'ABSPATH' ) || exit;

return \\EventsCertificate\\AdminApp\\boot_asset();
`
);

let changed = 0;
for ( const file of phpFiles( BUILD ) ) {
	let php = readFileSync( file, 'utf8' );
	const original = php;

	// After `<?php` and its file docblock, if any.
	const header = php.match( /^<\?php\s*(\/\*\*[\s\S]*?\*\/\s*)?/ );
	const head = header[ 0 ];
	let body = php.slice( head.length );

	if ( ! php.includes( 'ABSPATH' ) ) {
		body = `${ GUARD }\n\n${ body }`;
	}

	const returnsValue = /^\s*(defined\( 'ABSPATH' \) \|\| exit;\s*)?return\s+(array|\[|require)/.test( body );
	const hasLoose = /^\$(?!evcert_)[a-z_]+\s*=|^foreach \(/m.test( body );
	if ( hasLoose && ! returnsValue && ! body.includes( '( static function () {' ) ) {
		const guardEnd = body.startsWith( GUARD ) ? GUARD.length : 0;
		body = `${ body.slice( 0, guardEnd ) }\n\n( static function () {\n${ body
			.slice( guardEnd )
			.trim() }\n} )();\n`;
	}

	// $GLOBALS[ $name ] where the name is built with the prefix ('eizport_' . $page . '_routes_data').
	body = body.replace(
		/^(\s*\$GLOBALS\[ \$[a-z_]+ \] = [^;]+;)$/gm,
		'$1 // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- the name is built with the plugin prefix.'
	);

	// The render function's <style>, enqueued instead.
	const style = body.match( /\n\t<style>\n([\s\S]*?)\n\t<\/style>\n/ );
	const prerequisites = body.match( /\n(\t+)wp_enqueue_style\( '([a-z0-9-]+-prerequisites)' \);\n/ );
	if ( style && prerequisites ) {
		const [ , indent, handle ] = prerequisites;
		const css = style[ 1 ]
			.split( '\n' )
			.map( ( line ) => line.replace( /^\t\t/, '' ) )
			.join( '\n' )
			.trim()
			.replace( /\\/g, '\\\\' )
			.replace( /'/g, "\\'" );
		body = body
			.replace( style[ 0 ], '\n' )
			.replace( prerequisites[ 0 ], `${ prerequisites[ 0 ] }${ indent }wp_add_inline_style(\n${ indent }\t'${ handle }',\n${ indent }\t'${ css.replace( /\n(?=.)/g, `\n${ indent }\t` ) }'\n${ indent });\n` );
	}

	php = head + body;
	if ( php !== original ) {
		writeFileSync( file, php );
		changed++;
	}
}

console.log( `postbuild: ${ changed } generated PHP files made Plugin Check-clean.` );
