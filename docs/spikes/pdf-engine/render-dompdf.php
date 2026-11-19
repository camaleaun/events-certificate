<?php
// Spike: design.php -> HTML (absolute positioning in pt) -> PDF with dompdf.
// Usage: php render-dompdf.php "Person Name" out/file.pdf
require __DIR__ . '/vendor/autoload.php';

use Dompdf\Dompdf;
use Dompdf\Options;

$t0     = microtime( true );
$name   = $argv[1] ?? 'Mary Anne Sue';
$out    = $argv[2] ?? __DIR__ . '/out/dompdf.pdf';
$design = require __DIR__ . '/design.php';
$cache  = __DIR__ . '/out/fontcache';
@mkdir( $cache, 0777, true );

$options = new Options();
$options->setChroot( __DIR__ );
$options->setFontDir( $cache );
$options->setFontCache( $cache );
$options->setIsRemoteEnabled( false );
$options->setDefaultFont( 'Inter' );
$options->setDpi( 72 );
$dompdf = new Dompdf( $options );

$fonts = array(
	array( 'Lora', 400, 'Lora-Regular' ),
	array( 'Lora', 500, 'Lora-Medium' ),
	array( 'Lora', 600, 'Lora-SemiBold' ),
	array( 'Inter', 400, 'Inter-Regular' ),
	array( 'Inter', 500, 'Inter-Medium' ),
	array( 'Inter', 600, 'Inter-SemiBold' ),
);
$css = '';
foreach ( $fonts as $f ) {
	$css .= sprintf( "@font-face{font-family:'%s';font-weight:%d;font-style:normal;src:url('%s/fonts/%s.ttf') format('truetype');}\n", $f[0], $f[1], __DIR__, $f[2] );
}
// Register fonts up front so getTextWidth() can measure before rendering.
$dompdf->getFontMetrics()->registerFont( array( 'family' => 'Lora', 'weight' => '500', 'style' => 'normal' ), __DIR__ . '/fonts/Lora-Medium.ttf' );

$qr_svg = function ( $data ) {
	$options = new \chillerlan\QRCode\QROptions(
		array(
			'outputType'   => \chillerlan\QRCode\QRCode::OUTPUT_MARKUP_SVG,
			'eccLevel'     => \chillerlan\QRCode\QRCode::ECC_M,
			'addQuietzone' => false,
			'imageBase64'  => true,
		)
	);
	return ( new \chillerlan\QRCode\QRCode( $options ) )->render( $data );
};

$html = '';
foreach ( $design['elements'] as $e ) {
	$box = sprintf( 'position:absolute;left:%.2fpt;top:%.2fpt;', $e['x'], $e['y'] );
	switch ( $e['type'] ) {
		case 'image':
			$style = $box . sprintf( 'width:%.2fpt;height:%.2fpt;', $e['w'], $e['h'] );
			if ( ! empty( $e['rotate'] ) ) {
				$style .= sprintf( 'transform:rotate(%ddeg);transform-origin:50%% 50%%;', $e['rotate'] );
			}
			$html .= sprintf( '<img src="%s/assets/%s" style="%s">', __DIR__, $e['src'], $style );
			break;
		case 'rect':
			$r     = $e['radius'];
			$html .= sprintf( '<div style="%swidth:%.2fpt;height:%.2fpt;background:%s;border-radius:%.2fpt %.2fpt %.2fpt %.2fpt;"></div>', $box, $e['w'], $e['h'], $e['fill'], $r[0], $r[1], $r[2], $r[3] );
			break;
		case 'line':
			$html .= sprintf( '<div style="%swidth:%.2fpt;height:0;border-top:1pt solid %s;opacity:%s;"></div>', $box, $e['w'], $e['color'], $e['opacity'] );
			break;
		case 'qr':
			$html .= sprintf( '<img src="%s" style="%swidth:%.2fpt;height:%.2fpt;">', $qr_svg( $e['data'] ), $box, $e['w'], $e['w'] );
			break;
		case 'text':
			$size    = $e['size'];
			$content = isset( $e['html'] ) ? $e['html'] : htmlspecialchars( str_replace( '{name}', $name, $e['text'] ) );
			if ( ! empty( $e['fit'] ) ) {
				$text   = str_replace( '{name}', $name, $e['text'] );
				$font   = $dompdf->getFontMetrics()->getFont( $e['font'], (string) $e['weight'] );
				$width  = $dompdf->getFontMetrics()->getTextWidth( $text, $font, $size );
				if ( $width > $e['w'] ) {
					$size = max( $e['min'], $size * $e['w'] / $width );
				}
			}
			$style = $box . sprintf(
				"width:%.2fpt;font-family:'%s';font-weight:%d;font-size:%.3fpt;line-height:%s;color:%s;text-align:center;%s%s",
				$e['w'],
				$e['font'],
				$e['weight'],
				$size,
				$e['lh'],
				$e['color'],
				! empty( $e['upper'] ) ? 'text-transform:uppercase;' : '',
				isset( $e['ls'] ) ? sprintf( 'letter-spacing:%.2fpt;', $e['ls'] ) : ''
			);
			$html .= sprintf( '<div style="%s">%s</div>', $style, $content );
			break;
	}
}

$doc = '<!DOCTYPE html><html><head><meta charset="utf-8"><style>' . $css . '
@page{size:842pt 595pt;margin:0}
html,body{margin:0;padding:0}
body{width:842pt;height:595pt;position:relative;overflow:hidden;background:#fff}
b,strong{font-weight:600}
</style></head><body>' . $html . '</body></html>';

file_put_contents( __DIR__ . '/out/dompdf.html', $doc );
$dompdf->loadHtml( $doc, 'UTF-8' );
$dompdf->setPaper( array( 0, 0, 842, 595 ) );
$dompdf->render();
file_put_contents( $out, $dompdf->output() );

printf( "dompdf: %.0f ms, peak %.1f MB, %s bytes\n", ( microtime( true ) - $t0 ) * 1000, memory_get_peak_usage( true ) / 1048576, filesize( $out ) );
