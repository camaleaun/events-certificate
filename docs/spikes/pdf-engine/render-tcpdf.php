<?php
// Spike: design.php -> PDF drawn element by element with TCPDF.
// Usage: php render-tcpdf.php "Person Name" out/file.pdf
$cache = __DIR__ . '/out/tcpdf-fonts/';
@mkdir( $cache, 0777, true );
define( 'K_PATH_FONTS', $cache );
require __DIR__ . '/vendor/autoload.php';

$t0     = microtime( true );
$name   = $argv[1] ?? 'Mary Anne Sue';
$out    = $argv[2] ?? __DIR__ . '/out/tcpdf.pdf';
$design = require __DIR__ . '/design.php';

// Convert the TTFs once (TCPDF writes its own font files: .php + .z + .ctg.z).
$families = array();
foreach ( array(
	'Lora-400'  => 'Lora-Regular',
	'Lora-500'  => 'Lora-Medium',
	'Lora-600'  => 'Lora-SemiBold',
	'Inter-400' => 'Inter-Regular',
	'Inter-500' => 'Inter-Medium',
	'Inter-600' => 'Inter-SemiBold',
) as $key => $file ) {
	$families[ $key ] = TCPDF_FONTS::addTTFfont( __DIR__ . "/fonts/$file.ttf", 'TrueTypeUnicode', '', 32, $cache );
}

$pdf = new TCPDF( 'L', 'pt', array( 842, 595 ), true, 'UTF-8', false );
$pdf->setPrintHeader( false );
$pdf->setPrintFooter( false );
$pdf->SetMargins( 0, 0, 0 );
$pdf->SetAutoPageBreak( false, 0 );
$pdf->setCellPaddings( 0, 0, 0, 0 );
$pdf->AddPage();
// Inline bold in the paragraph: Inter SemiBold as Inter's "B" style.
$pdf->AddFont( $families['Inter-400'], '', $cache . $families['Inter-400'] . '.php' );
$pdf->AddFont( $families['Inter-400'], 'B', $cache . $families['Inter-600'] . '.php' );

$hex = function ( $color ) {
	$color = ltrim( $color, '#' );
	return array( hexdec( substr( $color, 0, 2 ) ), hexdec( substr( $color, 2, 2 ) ), hexdec( substr( $color, 4, 2 ) ) );
};

foreach ( $design['elements'] as $e ) {
	switch ( $e['type'] ) {
		case 'image':
			if ( ! empty( $e['rotate'] ) ) {
				$pdf->StartTransform();
				$pdf->Rotate( -$e['rotate'], $e['x'] + $e['w'] / 2, $e['y'] + $e['h'] / 2 );
			}
			$pdf->ImageSVG( __DIR__ . '/assets/' . $e['src'], $e['x'], $e['y'], $e['w'], $e['h'] );
			if ( ! empty( $e['rotate'] ) ) {
				$pdf->StopTransform();
			}
			break;
		case 'rect':
			$pdf->SetFillColorArray( $hex( $e['fill'] ) );
			// round_corner: top-right, bottom-right, bottom-left, top-left.
			$pdf->RoundedRect( $e['x'], $e['y'], $e['w'], $e['h'], 64, '0110', 'F' );
			break;
		case 'line':
			$pdf->SetAlpha( $e['opacity'] );
			$pdf->SetLineStyle( array( 'width' => 1, 'color' => $hex( $e['color'] ) ) );
			$pdf->Line( $e['x'], $e['y'], $e['x'] + $e['w'], $e['y'] );
			$pdf->SetAlpha( 1 );
			break;
		case 'qr':
			$pdf->write2DBarcode( $e['data'], 'QRCODE,M', $e['x'], $e['y'], $e['w'], $e['w'], array( 'padding' => 0, 'fgcolor' => array( 0, 0, 0 ), 'bgcolor' => false ), 'N' );
			break;
		case 'text':
			$family = $families[ $e['font'] . '-' . $e['weight'] ];
			$size   = $e['size'];
			$pdf->SetFont( $family, '', $size );
			$pdf->SetTextColorArray( $hex( $e['color'] ) );
			$pdf->setFontSpacing( $e['ls'] ?? 0 );
			$pdf->setCellHeightRatio( $e['lh'] );
			if ( isset( $e['html'] ) ) {
				$pdf->writeHTMLCell( $e['w'], 0, $e['x'], $e['y'], $e['html'], 0, 0, false, true, 'C' );
				break;
			}
			$text = str_replace( '{name}', $name, $e['text'] );
			if ( ! empty( $e['upper'] ) ) {
				$text = mb_strtoupper( $text, 'UTF-8' );
			}
			if ( ! empty( $e['fit'] ) ) {
				$width = $pdf->GetStringWidth( $text, $family, '', $size );
				if ( $width > $e['w'] ) {
					$size = max( $e['min'], $size * $e['w'] / $width );
					$pdf->SetFont( $family, '', $size );
				}
			}
			$pdf->MultiCell( $e['w'], 0, $text, 0, 'C', false, 1, $e['x'], $e['y'] );
			break;
	}
}

$pdf->Output( $out, 'F' );
printf( "tcpdf: %.0f ms, peak %.1f MB, %s bytes\n", ( microtime( true ) - $t0 ) * 1000, memory_get_peak_usage( true ) / 1048576, filesize( $out ) );
