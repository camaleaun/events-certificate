<?php
/**
 * Which stylesheet and script files load.
 *
 * @package EventsCertificate
 */

namespace EventsCertificate\Tests;

use EventsCertificate\Assets;

/**
 * The built files load minified, except with SCRIPT_DEBUG or on a local environment.
 */
class AssetsTest extends Test_Case {

	/**
	 * Production loads .min files with the plugin's version.
	 */
	public function test_production_loads_min_files() {
		$this->assertSame(
			array(
				'suffix'  => '.min',
				'version' => 'plugin',
			),
			Assets\choose( false, false )
		);
	}

	/**
	 * SCRIPT_DEBUG loads the readable files; a local site also versions them by file time.
	 */
	public function test_debug_and_local_load_readable_files() {
		$this->assertSame( '', Assets\choose( true, false )['suffix'] );
		$this->assertSame( 'plugin', Assets\choose( true, false )['version'] );
		$this->assertSame( '', Assets\choose( false, true )['suffix'] );
		$this->assertSame( 'mtime', Assets\choose( false, true )['version'] );
	}

	/**
	 * A built entry has an address under assets/css or assets/js and a version.
	 */
	public function test_src_points_to_the_built_file() {
		if ( ! is_readable( dirname( __DIR__, 2 ) . '/assets/css/quick-generator.css' ) ) {
			$this->markTestSkipped( 'The assets are not built (npm run build).' );
		}
		$style  = Assets\src( 'quick-generator', 'css' );
		$script = Assets\src( 'quick-generator', 'js' );

		$this->assertMatchesRegularExpression( '#/assets/css/quick-generator(\.min)?\.css$#', $style['url'] );
		$this->assertMatchesRegularExpression( '#/assets/js/quick-generator(\.min)?\.js$#', $script['url'] );
		$this->assertNotSame( '', $style['version'] );
	}

	/**
	 * The page wp-build generates requires the boot asset file that bin/postbuild.mjs writes; it must load
	 * (a function it calls was once deleted as dead code and the template editor fataled).
	 */
	public function test_the_generated_boot_asset_loads() {
		$file = dirname( __DIR__, 2 ) . '/build/modules/boot/index.min.asset.php';
		if ( ! is_file( $file ) ) {
			$this->markTestSkipped( 'The editor is not built.' );
		}
		$asset = require $file;

		$this->assertIsArray( $asset );
		$this->assertArrayHasKey( 'dependencies', $asset );
		$this->assertArrayHasKey( 'version', $asset );
	}
}
