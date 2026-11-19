<?php
/**
 * Renderer: a design (docs/decisions/0004) and a certificate's frozen values
 * to positioned HTML in points. The same markup is the certificate page's
 * artwork and dompdf's input for the PDF (docs/decisions/0002), so screen and
 * PDF draw the same thing. Shapes and SVG elements become small SVG images;
 * texts are absolutely positioned blocks with the design's fonts.
 *
 * Mirrors src/editor/model.ts (anchors, lengths, corner shapes, fields).
 *
 * @package EventsCertificate
 */

namespace EventsCertificate\Render;

use EventsCertificate\Certificates;
use EventsCertificate\Environment;
use EventsCertificate\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Length units in points.
 */
const UNITS = array(
	'pt' => 1,
	'px' => 1,
	'mm' => 2.83465,
	'cm' => 28.3465,
	'in' => 72,
);

/**
 * Fonts the designs use: family, weight, file in fonts/.
 */
const FONTS = array(
	array( 'Lora', 400, 'Lora-Regular' ),
	array( 'Lora', 500, 'Lora-Medium' ),
	array( 'Lora', 600, 'Lora-SemiBold' ),
	array( 'Inter', 400, 'Inter-Regular' ),
	array( 'Inter', 500, 'Inter-Medium' ),
	array( 'Inter', 600, 'Inter-SemiBold' ),
	// The generator's certificate: IBM Plex Sans and Instrument Serif (subsets).
	array( 'CertSans', 400, 'CertSans-Regular' ),
	array( 'CertSans', 600, 'CertSans-Bold' ),
	array( 'CertSerif', 400, 'CertSerif-Regular' ),
);

/**
 * A length in points: "20pt", "10mm", "5%", "calc(100% - 40pt)" or a number.
 *
 * @param mixed $value Length.
 * @param float $total What 100% is.
 * @return float
 */
function parse_len( $value, $total ) {
	if ( is_int( $value ) || is_float( $value ) ) {
		return (float) $value;
	}
	$expr = strtolower( trim( (string) $value ) );
	if ( '' === $expr ) {
		return 0.0;
	}
	if ( preg_match( '/^calc\((.*)\)$/', $expr, $m ) ) {
		$expr = $m[1];
	}
	$sum = 0.0;
	if ( ! preg_match_all( '/([+-]?)\s*(-?\d*\.?\d+)\s*(pt|px|mm|cm|in|%)?/', $expr, $all, PREG_SET_ORDER ) ) {
		return 0.0;
	}
	foreach ( $all as $m ) {
		$n    = (float) $m[2] * ( '-' === $m[1] ? -1 : 1 );
		$unit = $m[3] ?? '';
		$sum += '%' === $unit ? $n * $total / 100 : $n * ( UNITS[ '' === $unit ? 'pt' : $unit ] ?? 1 );
	}

	return $sum;
}

/**
 * Resolves one axis of an anchor.
 *
 * @param array<string, mixed> $axis   Axis (mode, start, end, size).
 * @param float                $origin Reference box start.
 * @param float                $total  Reference box size.
 * @param float|null           $forced Size to use instead of the axis's own (aspect ratio kept).
 * @return float[] Position and size.
 */
function resolve_axis( array $axis, $origin, $total, $forced = null ) {
	$start = parse_len( $axis['start'] ?? '', $total );
	$end   = parse_len( $axis['end'] ?? '', $total );
	$size  = null === $forced ? parse_len( $axis['size'] ?? '', $total ) : (float) $forced;
	switch ( $axis['mode'] ?? 'start' ) {
		case 'end':
			return array( $origin + $total - $end - $size, $size );
		case 'center':
			return array( $origin + ( $total - $size ) / 2 + $start, $size );
		case 'stretch':
			return array( $origin + $start, max( 1, $total - $start - $end ) );
		default:
			return array( $origin + $start, $size );
	}
}

/**
 * An element with its anchors resolved to x, y, w, h.
 *
 * @param array<string, mixed> $el   Element.
 * @param array<string, mixed> $page Page.
 * @return array<string, mixed>
 */
function resolve( array $el, array $page ) {
	if ( empty( $el['layout'] ) || 'line' === ( $el['type'] ?? '' ) ) {
		return $el;
	}
	$layout                    = $el['layout'];
	$safe                      = (float) ( $page['safe'] ?? 0 );
	$ref                       = 'safe' === ( $layout['ref'] ?? 'page' )
		? array( $safe, $safe, $page['w'] - $safe * 2, $page['h'] - $safe * 2 )
		: array( 0, 0, $page['w'], $page['h'] );
	list( $el['x'], $el['w'] ) = resolve_axis( (array) ( $layout['h'] ?? array() ), $ref[0], $ref[2] );
	list( $el['y'], $el['h'] ) = resolve_axis( (array) ( $layout['v'] ?? array() ), $ref[1], $ref[3] );

	// Aspect ratio kept (src/editor/model.ts resolve()): the height follows the width, unless only the height fills.
	$ratio  = locked_ratio( $el );
	$fill_h = 'stretch' === ( $layout['h']['mode'] ?? '' );
	$fill_v = 'stretch' === ( $layout['v']['mode'] ?? '' );
	if ( $ratio && ! ( $fill_h && $fill_v ) ) {
		if ( $fill_v ) {
			list( $el['x'], $el['w'] ) = resolve_axis( (array) ( $layout['h'] ?? array() ), $ref[0], $ref[2], $el['h'] * $ratio );
		} else {
			list( $el['y'], $el['h'] ) = resolve_axis( (array) ( $layout['v'] ?? array() ), $ref[1], $ref[3], $el['w'] / $ratio );
		}
	}

	return $el;
}

/**
 * The width ÷ height an element keeps: an image's aspect ratio setting, or
 * the lock of other elements.
 *
 * @param array<string, mixed> $el Element.
 * @return float 0 when free.
 */
function locked_ratio( array $el ) {
	if ( 'image' === ( $el['type'] ?? '' ) ) {
		$parts = array_map( 'floatval', explode( '/', (string) ( $el['ratio'] ?? 'auto' ) ) );
		return 2 === count( $parts ) && $parts[0] > 0 && $parts[1] > 0 ? $parts[0] / $parts[1] : 0.0;
	}

	return ! empty( $el['lock'] ) && (float) $el['lock'] > 0 ? (float) $el['lock'] : 0.0;
}

/**
 * The event a certificate names: the event's current name (the WordCamp, or
 * the site), like the design, so renaming the event shows on every
 * certificate; the name recorded at issue when there is no current one.
 *
 * @param array<string, mixed> $cert Certificate.
 * @return string
 */
function event_title( array $cert ) {
	// Events of the plugin's own: that event's current name.
	$event = \EventsCertificate\Events\of_certificate( $cert );
	if ( $event ) {
		return $event['title'];
	}
	$name = \EventsCertificate\Compat\CampTix\event_name();

	return '' !== $name ? $name : (string) ( $cert['event_title'] ?? '' );
}

/**
 * Where the event happens: the current place (the WordCamp's, or the
 * setting), like the event's name; the place recorded at issue when none is set.
 *
 * @param array<string, mixed> $cert Certificate.
 * @return string
 */
function event_location( array $cert ) {
	$event = \EventsCertificate\Events\of_certificate( $cert );
	if ( $event && '' !== trim( $event['location'] ) ) {
		return $event['location'];
	}
	$place = \EventsCertificate\Compat\CampTix\event_location();
	if ( '' !== trim( $place ) ) {
		return $place;
	}
	$data = json_decode( (string) ( $cert['data_json'] ?? '' ), true );

	return is_array( $data ) ? (string) ( $data['location'] ?? '' ) : '';
}

/**
 * The page background in a mode: one value for both, or one per mode
 * (src/editor/model.ts pageBg()); '' = none, the page's own color shows.
 *
 * @param array<string, mixed> $page Page.
 * @param string               $mode light|dark.
 * @return string
 */
function page_bg( array $page, $mode ) {
	$bg = $page['bg'] ?? '';

	return is_array( $bg ) ? (string) ( $bg[ $mode ] ?? '' ) : (string) $bg;
}

/**
 * A color: "$name" reads the palette in the mode; anything else is literal.
 *
 * @param array<string, mixed> $design Design.
 * @param mixed                $value  Color.
 * @param string               $mode   light|dark.
 * @return string
 */
function color( array $design, $value, $mode = 'light' ) {
	$value = (string) $value;
	if ( '' === $value ) {
		return 'none';
	}
	if ( '$' === $value[0] ) {
		$var   = $design['colors'][ substr( $value, 1 ) ] ?? null;
		$value = is_array( $var ) ? (string) ( $var[ $mode ] ?? '' ) : '';
	}

	// Only color values: the result goes into CSS and SVG attributes.
	return preg_match( '/^(#[0-9a-f]{3,8}|rgba?\([\d\s.,%]+\)|transparent|none|currentcolor|[a-z]+)$/i', $value ) ? $value : 'none';
}

/**
 * A rectangle's outline as SVG path data, corners shaped like CSS corner-shape.
 *
 * @param float  $x      Left.
 * @param float  $y      Top.
 * @param float  $w      Width.
 * @param float  $h      Height.
 * @param array  $radius Radii: top-left, top-right, bottom-right, bottom-left.
 * @param string $shape  round|scoop|bevel|notch|squircle.
 * @return string
 */
function rect_path( $x, $y, $w, $h, $radius = array(), $shape = 'round' ) {
	$f       = static function ( $n ) {
		return (string) round( $n, 2 );
	};
	$corners = array(
		array( array( $x, $y ), array( 0, 1 ), array( 1, 0 ) ),
		array( array( $x + $w, $y ), array( -1, 0 ), array( 0, 1 ) ),
		array( array( $x + $w, $y + $h ), array( 0, -1 ), array( -1, 0 ) ),
		array( array( $x, $y + $h ), array( 1, 0 ), array( 0, -1 ) ),
	);
	$d       = '';
	// A quarter circle from $a to $b bulging toward $toward, as a cubic Bézier (k = 0.5523).
	$quarter = static function ( $a, $b, $toward ) use ( $f ) {
		$k = 0.5523;
		return 'C' . $f( $a[0] + ( $toward[0] - $a[0] ) * $k ) . ' ' . $f( $a[1] + ( $toward[1] - $a[1] ) * $k )
			. ' ' . $f( $b[0] + ( $toward[0] - $b[0] ) * $k ) . ' ' . $f( $b[1] + ( $toward[1] - $b[1] ) * $k )
			. ' ' . $f( $b[0] ) . ' ' . $f( $b[1] );
	};
	foreach ( $corners as $i => $corner ) {
		list( $c, $in, $out ) = $corner;
		$rr                   = max( 0, min( (float) ( $radius[ $i ] ?? 0 ), $w / 2, $h / 2 ) );
		$start                = array( $c[0] + $in[0] * $rr, $c[1] + $in[1] * $rr );
		$end                  = array( $c[0] + $out[0] * $rr, $c[1] + $out[1] * $rr );
		$d                   .= ( 0 === $i ? 'M' : 'L' ) . $f( $start[0] ) . ' ' . $f( $start[1] );
		if ( ! $rr ) {
			continue;
		}
		switch ( $shape ) {
			case 'scoop':
				// Quarter circles as cubic curves (dompdf's SVG library draws arcs wrong): concave, around the inner point.
				$inner = array( $start[0] + $end[0] - $c[0], $start[1] + $end[1] - $c[1] );
				$d    .= $quarter( $start, $end, $inner );
				break;
			case 'bevel':
				$d .= 'L' . $f( $end[0] ) . ' ' . $f( $end[1] );
				break;
			case 'notch':
				$d .= 'L' . $f( $start[0] + $end[0] - $c[0] ) . ' ' . $f( $start[1] + $end[1] - $c[1] ) . 'L' . $f( $end[0] ) . ' ' . $f( $end[1] );
				break;
			case 'squircle':
				$k  = 0.18;
				$d .= 'C' . $f( $c[0] + ( $start[0] - $c[0] ) * $k ) . ' ' . $f( $c[1] + ( $start[1] - $c[1] ) * $k ) . ' ' . $f( $c[0] + ( $end[0] - $c[0] ) * $k ) . ' ' . $f( $c[1] + ( $end[1] - $c[1] ) * $k ) . ' ' . $f( $end[0] ) . ' ' . $f( $end[1] );
				break;
			default:
				$d .= $quarter( $start, $end, $c );
		}
	}

	return $d . 'Z';
}

/**
 * Path data: only path commands and numbers, starting with a move.
 *
 * @param string $d Path data.
 * @return bool
 */
function is_path_data( $d ) {
	return (bool) preg_match( '/^\s*[Mm][MmLlHhVvCcSsQqTtAaZz0-9eE.,\s+-]*$/', (string) $d );
}

/**
 * Path data rewritten in absolute commands with every elliptical arc as cubic
 * Bézier curves (dompdf draws SVG arcs wrong; see rect_path() for the same in
 * the corner shapes). S and T become C and Q, so a curve after a converted
 * arc keeps the meaning it had.
 *
 * @param string $d Path data (is_path_data()).
 * @return string
 */
function without_arcs( $d ) {
	$counts = array(
		'M' => 2,
		'L' => 2,
		'H' => 1,
		'V' => 1,
		'C' => 6,
		'S' => 4,
		'Q' => 4,
		'T' => 2,
		'A' => 7,
		'Z' => 0,
	);
	$d      = (string) $d;
	$len    = strlen( $d );
	$pos    = 0;
	$out    = array();
	$x      = 0.0;
	$y      = 0.0;
	$sx     = 0.0;
	$sy     = 0.0;
	$cubic  = null; // Last cubic control point, for S.
	$quad   = null; // Last quadratic control point, for T.
	$f      = static function ( $n ) {
		$s = rtrim( rtrim( sprintf( '%.4F', $n ), '0' ), '.' );
		return '-0' === $s ? '0' : $s;
	};
	$skip   = static function () use ( $d, $len, &$pos ) {
		while ( $pos < $len && false !== strpos( " \t\r\n,", $d[ $pos ] ) ) {
			++$pos;
		}
	};
	$number = static function ( $flag = false ) use ( $d, &$pos, $skip ) {
		$skip();
		if ( $flag ) {
			return (float) $d[ $pos++ ];
		}
		if ( ! preg_match( '/\G[+-]?(?:\d+\.?\d*|\.\d+)(?:[eE][+-]?\d+)?/', $d, $m, 0, $pos ) ) {
			return null;
		}
		$pos += strlen( $m[0] );
		return (float) $m[0];
	};

	$cmd = '';
	while ( true ) {
		$skip();
		if ( $pos >= $len ) {
			break;
		}
		if ( ctype_alpha( $d[ $pos ] ) ) {
			$cmd = $d[ $pos++ ];
		} elseif ( '' === $cmd ) {
			break;
		}
		$upper = strtoupper( $cmd );
		$rel   = $cmd !== $upper;
		$args  = array();
		for ( $i = 0; $i < $counts[ $upper ]; $i++ ) {
			$n = $number( 'A' === $upper && ( 3 === $i || 4 === $i ) );
			if ( null === $n ) {
				return $d; // Malformed: leave it as it was.
			}
			$args[] = $n;
		}
		$ox = $rel ? $x : 0.0;
		$oy = $rel ? $y : 0.0;
		switch ( $upper ) {
			case 'M':
				$x     = $args[0] + $ox;
				$y     = $args[1] + $oy;
				$sx    = $x;
				$sy    = $y;
				$out[] = 'M' . $f( $x ) . ' ' . $f( $y );
				$cmd   = $rel ? 'l' : 'L'; // Further pairs are lines.
				$cubic = null;
				$quad  = null;
				break;
			case 'L':
			case 'H':
			case 'V':
				$x     = 'V' === $upper ? $x : $args[0] + $ox;
				$y     = 'H' === $upper ? $y : ( 'V' === $upper ? $args[0] + $oy : $args[1] + $oy );
				$out[] = 'L' . $f( $x ) . ' ' . $f( $y );
				$cubic = null;
				$quad  = null;
				break;
			case 'C':
			case 'S':
				if ( 'C' === $upper ) {
					$c1 = array( $args[0] + $ox, $args[1] + $oy );
					$c2 = array( $args[2] + $ox, $args[3] + $oy );
					$e  = array( $args[4] + $ox, $args[5] + $oy );
				} else {
					$c1 = $cubic ? array( 2 * $x - $cubic[0], 2 * $y - $cubic[1] ) : array( $x, $y );
					$c2 = array( $args[0] + $ox, $args[1] + $oy );
					$e  = array( $args[2] + $ox, $args[3] + $oy );
				}
				$out[] = 'C' . $f( $c1[0] ) . ' ' . $f( $c1[1] ) . ' ' . $f( $c2[0] ) . ' ' . $f( $c2[1] ) . ' ' . $f( $e[0] ) . ' ' . $f( $e[1] );
				$cubic = $c2;
				$quad  = null;
				$x     = $e[0];
				$y     = $e[1];
				break;
			case 'Q':
			case 'T':
				if ( 'Q' === $upper ) {
					$c = array( $args[0] + $ox, $args[1] + $oy );
					$e = array( $args[2] + $ox, $args[3] + $oy );
				} else {
					$c = $quad ? array( 2 * $x - $quad[0], 2 * $y - $quad[1] ) : array( $x, $y );
					$e = array( $args[0] + $ox, $args[1] + $oy );
				}
				$out[] = 'Q' . $f( $c[0] ) . ' ' . $f( $c[1] ) . ' ' . $f( $e[0] ) . ' ' . $f( $e[1] );
				$quad  = $c;
				$cubic = null;
				$x     = $e[0];
				$y     = $e[1];
				break;
			case 'A':
				$ex = $args[5] + $ox;
				$ey = $args[6] + $oy;
				foreach ( arc_to_cubics( $x, $y, $args[0], $args[1], $args[2], (bool) $args[3], (bool) $args[4], $ex, $ey ) as $c ) {
					$out[] = 'C' . implode( ' ', array_map( $f, $c ) );
				}
				$x     = $ex;
				$y     = $ey;
				$cubic = null;
				$quad  = null;
				break;
			case 'Z':
				$out[] = 'Z';
				$x     = $sx;
				$y     = $sy;
				$cubic = null;
				$quad  = null;
				break;
		}
	}

	return implode( '', $out );
}

/**
 * An SVG elliptical arc as cubic Béziers (SVG 1.1 appendix F.6.5 and F.6.6),
 * one per quarter turn at most.
 *
 * @param float $x1    Start x.
 * @param float $y1    Start y.
 * @param float $rx    Radius x.
 * @param float $ry    Radius y.
 * @param float $angle X-axis rotation, degrees.
 * @param bool  $large Large-arc flag.
 * @param bool  $sweep Sweep flag.
 * @param float $x2    End x.
 * @param float $y2    End y.
 * @return array<int, float[]> Each curve as [c1x, c1y, c2x, c2y, x, y].
 */
function arc_to_cubics( $x1, $y1, $rx, $ry, $angle, $large, $sweep, $x2, $y2 ) {
	if ( ( $x1 === $x2 && $y1 === $y2 ) ) {
		return array();
	}
	$rx = abs( $rx );
	$ry = abs( $ry );
	if ( 0.0 === (float) $rx || 0.0 === (float) $ry ) {
		return array( array( $x1, $y1, $x2, $y2, $x2, $y2 ) ); // A straight line.
	}
	$phi = deg2rad( fmod( $angle, 360 ) );
	$cos = cos( $phi );
	$sin = sin( $phi );
	$dx  = ( $x1 - $x2 ) / 2;
	$dy  = ( $y1 - $y2 ) / 2;
	$x1p = $cos * $dx + $sin * $dy;
	$y1p = -$sin * $dx + $cos * $dy;
	// Radii too small for the endpoints are scaled up.
	$lambda = ( $x1p * $x1p ) / ( $rx * $rx ) + ( $y1p * $y1p ) / ( $ry * $ry );
	if ( $lambda > 1 ) {
		$rx *= sqrt( $lambda );
		$ry *= sqrt( $lambda );
	}
	$num  = $rx * $rx * $ry * $ry - $rx * $rx * $y1p * $y1p - $ry * $ry * $x1p * $x1p;
	$den  = $rx * $rx * $y1p * $y1p + $ry * $ry * $x1p * $x1p;
	$coef = ( $large === $sweep ? -1 : 1 ) * sqrt( max( 0, $num / $den ) );
	$cxp  = $coef * ( $rx * $y1p / $ry );
	$cyp  = $coef * ( -$ry * $x1p / $rx );
	$cx   = $cos * $cxp - $sin * $cyp + ( $x1 + $x2 ) / 2;
	$cy   = $sin * $cxp + $cos * $cyp + ( $y1 + $y2 ) / 2;

	$vector = static function ( $ux, $uy, $vx, $vy ) {
		$a = atan2( $ux * $vy - $uy * $vx, $ux * $vx + $uy * $vy );
		return $a;
	};
	$theta  = $vector( 1, 0, ( $x1p - $cxp ) / $rx, ( $y1p - $cyp ) / $ry );
	$delta  = $vector( ( $x1p - $cxp ) / $rx, ( $y1p - $cyp ) / $ry, ( -$x1p - $cxp ) / $rx, ( -$y1p - $cyp ) / $ry );
	if ( ! $sweep && $delta > 0 ) {
		$delta -= 2 * M_PI;
	} elseif ( $sweep && $delta < 0 ) {
		$delta += 2 * M_PI;
	}

	$segments = max( 1, (int) ceil( abs( $delta ) / ( M_PI / 2 ) - 1e-7 ) );
	$step     = $delta / $segments;
	$k        = 4 / 3 * tan( $step / 4 );
	$point    = static function ( $t ) use ( $rx, $ry, $cos, $sin, $cx, $cy ) {
		return array( $cx + $rx * cos( $t ) * $cos - $ry * sin( $t ) * $sin, $cy + $rx * cos( $t ) * $sin + $ry * sin( $t ) * $cos );
	};
	$tangent  = static function ( $t ) use ( $rx, $ry, $cos, $sin ) {
		return array( -$rx * sin( $t ) * $cos - $ry * cos( $t ) * $sin, -$rx * sin( $t ) * $sin + $ry * cos( $t ) * $cos );
	};
	$curves   = array();
	for ( $i = 0; $i < $segments; $i++ ) {
		$t1       = $theta + $i * $step;
		$t2       = $t1 + $step;
		$p1       = $point( $t1 );
		$p2       = $point( $t2 );
		$d1       = $tangent( $t1 );
		$d2       = $tangent( $t2 );
		$curves[] = array( $p1[0] + $k * $d1[0], $p1[1] + $k * $d1[1], $p2[0] - $k * $d2[0], $p2[1] - $k * $d2[1], $p2[0], $p2[1] );
	}
	// End exactly where the arc ends.
	$curves[ $segments - 1 ][4] = $x2;
	$curves[ $segments - 1 ][5] = $y2;

	return $curves;
}

/**
 * The values a certificate's fields show, in the current locale.
 *
 * @param array<string, mixed> $cert Certificate.
 * @return array<string, string>
 */
function values( array $cert ) {
	$data   = json_decode( (string) ( $cert['data_json'] ?? '' ), true );
	$data   = is_array( $data ) ? $data : array();
	$issued = issued_ts( $cert );
	$event  = \EventsCertificate\Events\of_certificate( $cert );
	$begins = $event ? \EventsCertificate\Events\utc( $event['start'] ) : ( $cert['event_start'] ?? '' );
	$start  = ! empty( $begins ) ? strtotime( $begins . ' UTC' ) : $issued;
	$roles  = \EventsCertificate\Designs\role_labels();
	$hours  = isset( $cert['hours'] ) && '' !== (string) $cert['hours'] ? (float) $cert['hours'] : null;
	// The signer is part of the look, like the design: the current one; what was recorded at issue only when none is set.
	$signer = \EventsCertificate\Signer\for_certificate( $cert );

	$values = array(
		'name'           => (string) ( $cert['name'] ?? '' ),
		'event'          => event_title( $cert ),
		'location'       => event_location( $cert ),
		'community'      => $event ? $event['community'] : '',
		'speakers'       => $event ? $event['speakers'] : '',
		'issued_ts'      => (string) $issued,
		'event_ts'       => (string) $start,
		'hours'          => null === $hours ? '' : (string) ( floor( $hours ) === $hours ? (int) $hours : $hours ),
		'role'           => $roles[ $cert['role'] ?? '' ] ?? (string) ( $cert['role'] ?? '' ),
		'details'        => (string) ( $cert['details'] ?? '' ),
		'signer_name'    => (string) ( $signer ? $signer['name'] : ( $data['signer_name'] ?? '' ) ),
		'signer_role'    => \EventsCertificate\Signer\role_in( (array) ( $signer ? $signer['roles'] : ( $data['signer_roles'] ?? array() ) ), determine_locale() ),
		'signature'      => (string) wp_json_encode( $signer ? $signer['signature'] : ( $data['signature'] ?? null ) ),
		'code'           => (string) ( $cert['code'] ?? '' ),
		'verify_url'     => untrailingslashit( (string) preg_replace( '#^https?://#', '', home_url( Settings\value( 'slug' ) ) ) ),
		'document'       => (string) ( $cert['doc_masked'] ?? '' ),
		'event_time'     => $event ? event_time( $event ) : '',
		'event_link'     => $event ? short_link( $event['link'] ) : '',
		// No speakers: no label either (a label with nothing under it).
		'speakers_label' => $event && '' !== trim( (string) $event['speakers'] ) ? speakers_label( $event['speakers'] ) : '',
		/* translators: %s: a date. */
		'issued_on'      => sprintf( __( 'Issued on %s', 'events-certificates' ), format_date( $issued, 'long' ) ),
	) + declared( $data ) + phrases( (string) ( $cert['role'] ?? '' ) );
	// The declaration page's fields (a template's declaration sheet uses them).
	$values['activities']            = activities_text( $data );
	$values['declaration_statement'] = statement_text( $cert, $values, $data );

	return $values;
}

/**
 * The date a certificate shows as its date of issue: when it was issued, or,
 * when Settings say so, the event's last day (its days, else the date the
 * certificate recorded), like the event's name and place, the current one.
 *
 * @param array<string, mixed> $cert Certificate.
 * @return int Unix time.
 */
function issued_ts( array $cert ) {
	$issued = strtotime( ( $cert['issued_at'] ?? 'now' ) . ' UTC' );
	if ( 'event_end' !== Settings\value( 'issued_date' ) ) {
		return (int) $issued;
	}
	// An event of the plugin's own: its current end.
	$event = \EventsCertificate\Events\of_certificate( $cert );
	if ( $event ) {
		$end = \EventsCertificate\Events\utc( $event['end'] );
		return $end ? (int) strtotime( $end . ' UTC' ) : (int) $issued;
	}
	$days = \EventsCertificate\Days\all();
	if ( $days ) {
		$last = end( $days );
		return (int) strtotime( $last['date'] . ' 12:00:00 ' . wp_timezone_string() );
	}

	return ! empty( $cert['event_end'] ) ? (int) strtotime( $cert['event_end'] . ' UTC' ) : (int) $issued;
}

/**
 * The place with its country, unless the place already names it.
 *
 * @param string $place   Place.
 * @param string $country Country name.
 * @return string
 */
function place_with_country( $place, $country ) {
	if ( '' === $country || false !== stripos( $place, $country ) ) {
		return $place;
	}

	/* translators: 1: place, 2: country, or a place and a date. */
	return sprintf( __( '%1$s, %2$s', 'events-certificates' ), $place, $country );
}

/**
 * The place and the date, as the declaration's footer says them ("Curitiba, November 18, 2026"); the date alone without a place.
 *
 * @param string $place Place.
 * @param string $date  Date.
 * @return string
 */
function place_and_date( $place, $date ) {
	if ( '' === trim( $place ) ) {
		return $date;
	}

	/* translators: 1: place, 2: date, as in "Curitiba, November 18, 2026". */
	return sprintf( __( '%1$s, %2$s', 'events-certificates' ), $place, $date );
}

/**
 * A day's heading on the declaration: its date and its name.
 *
 * @param array<string, mixed>|null $day Day (Days\get()), or null when it no longer exists.
 * @param string                    $id  The day's ID, shown when it no longer exists.
 * @return string
 */
function day_heading( $day, $id ) {
	if ( ! $day ) {
		return $id;
	}

	/* translators: 1: date of a day, 2: its name (Contributor Day). */
	return sprintf( __( '%1$s — %2$s', 'events-certificates' ), \EventsCertificate\Days\short_date( $day ), \EventsCertificate\Days\name( $day ) );
}

/**
 * What a document is called, in the language being shown: its type's own
 * label (the one stored when it was issued is the fallback for a type that is gone).
 *
 * @param array<string, mixed> $cert Certificate.
 * @return string
 */
function document_label( array $cert ) {
	$type = (string) ( $cert['doc_type'] ?? '' );
	$name = '' !== $type ? \EventsCertificate\Documents\label( $type ) : '';

	return '' !== $name ? $name : (string) ( $cert['doc_label'] ?? '' );
}

/**
 * The declaration's statement as plain text (who, which document, event,
 * place, days, self-declared workload).
 *
 * @param array<string, mixed>  $cert   Certificate.
 * @param array<string, string> $values values() (document: the masked number, or the full one when issuing).
 * @param array<string, mixed>  $data   The certificate's data_json.
 * @return string
 */
function statement_text( array $cert, array $values, array $data ) {
	$who = $values['name'];
	if ( '' !== (string) $values['document'] ) {
		/* translators: 1: person's name, 2: document type (CPF), 3: document number. */
		$who = sprintf( __( '%1$s, %2$s %3$s,', 'events-certificates' ), $who, document_label( $cert ), $values['document'] );
	}
	$country = ! empty( $data['country'] ) ? \EventsCertificate\Countries\name( (string) $data['country'] ) : '';
	$hours   = field_value( 'hours', '', $values );

	return sprintf(
		/* translators: 1: person (name and document), 2: event, 3: place, 4: days, 5: workload. */
		__( 'We declare that %1$s took part in %2$s, held in %3$s, on %4$s, with a self-declared workload of %5$s, in the activities below.', 'events-certificates' ),
		$who,
		$values['event'],
		place_with_country( $values['location'], $country ),
		'' !== $values['day'] ? $values['day'] : format_date( (int) $values['event_ts'], 'long' ),
		'' !== $hours ? $hours : '—'
	);
}

/**
 * The declared activities as lines: each day, its table (with its time) or
 * its talks (time · title — speakers).
 *
 * @param array<string, mixed> $data The certificate's data_json.
 * @return string
 */
function activities_text( array $data ) {
	$time  = '\EventsCertificate\Dates\time_text';
	$lines = array();
	foreach ( (array) ( $data['days'] ?? array() ) as $id ) {
		$day = \EventsCertificate\Days\get( (string) $id );
		if ( $lines ) {
			$lines[] = '';
		}
		$lines[] = day_heading( $day, (string) $id );
		if ( isset( $data['table'][ $id ] ) ) {
			$span = (array) ( $data['table_time'][ $id ] ?? array() );
			/* translators: %s: table name (Contributor Day). */
			$lines[] = sprintf( __( 'Table: %s', 'events-certificates' ), (string) $data['table'][ $id ] ) . ( 2 === count( $span ) ? ' · ' . $time( $span[0] ) . '–' . $time( $span[1] ) : '' );
		}
		foreach ( (array) ( $data['sessions'] ?? array() ) as $session ) {
			if ( ( $session['day'] ?? '' ) === $id ) {
				$lines[] = $time( $session['start'] ) . '–' . $time( $session['end'] ) . ' · ' . $session['title'] . ( ! empty( $session['speakers'] ) ? ' — ' . implode( ', ', (array) $session['speakers'] ) : '' );
			}
		}
	}

	return implode( "\n", $lines );
}

/**
 * What the attendee declared (includes/compat/camptix-choices.php), as fields:
 * the days, the tables, the talks.
 *
 * @param array<string, mixed> $data The certificate's data_json.
 * @return array<string, string>
 */
function declared( array $data ) {
	$days = array();
	foreach ( (array) ( $data['days'] ?? array() ) as $id ) {
		$day    = \EventsCertificate\Days\get( (string) $id );
		$days[] = $day ? \EventsCertificate\Days\name( $day ) : (string) $id;
	}

	return array(
		'day'      => implode( ', ', $days ),
		'table'    => implode( ', ', array_map( 'strval', (array) ( $data['table'] ?? array() ) ) ),
		'sessions' => implode( '; ', array_map( 'strval', wp_list_pluck( (array) ( $data['sessions'] ?? array() ), 'title' ) ) ),
	);
}

/**
 * How a template shows verification on the first page: 'always', or
 * 'without-declaration' (left to the declaration page when there is one).
 *
 * @param array<string, mixed> $design Design.
 * @return string
 */
function verify_first_page( array $design ) {
	return 'without-declaration' === ( $design['verifyFirstPage'] ?? '' ) ? 'without-declaration' : 'always';
}

/**
 * Options for drawing a certificate's first page: without the QR code, code
 * and hint when its declaration page carries them and the template says so.
 *
 * @param array<string, mixed> $cert   Certificate.
 * @param array<string, mixed> $design Design.
 * @return array<string, bool>
 */
function first_page_options( array $cert, array $design ) {
	return array( 'no_verification' => has_declaration( $cert ) && 'without-declaration' === verify_first_page( $design ) );
}

/**
 * The signature as an image: the signer's (Signer\current(), as on the first
 * page), else the drawing of the template's signature element.
 *
 * @param array<string, mixed>  $design Design.
 * @param array<string, string> $values values().
 * @param string                $color  Color of paths without their own.
 * @return string Image source, or '' when there is none.
 */
function signature_src( array $design, array $values, $color ) {
	$signature = json_decode( $values['signature'] ?? 'null', true );
	if ( ! is_array( $signature ) || empty( $signature['paths'] ) ) {
		$signature = null;
		foreach ( (array) $design['elements'] as $el ) {
			if ( 'svg' === ( $el['type'] ?? '' ) && ( 'signature' === ( $el['slot'] ?? '' ) || 'signature' === ( $el['id'] ?? '' ) ) && ! empty( $el['paths'] ) ) {
				$signature = $el;
				break;
			}
		}
	}
	if ( ! $signature ) {
		return '';
	}
	$paths = '';
	foreach ( (array) $signature['paths'] as $path ) {
		if ( ! is_path_data( $path['d'] ?? '' ) ) {
			continue;
		}
		$fill   = isset( $path['fill'] ) && '$' !== substr( (string) $path['fill'], 0, 1 ) ? (string) $path['fill'] : $color;
		$paths .= sprintf( '<path d="%s" fill="%s"%s/>', esc_attr( without_arcs( $path['d'] ) ), esc_attr( $fill ), 'evenodd' === ( $path['rule'] ?? '' ) ? ' fill-rule="evenodd"' : '' );
	}

	return '' === $paths ? '' : svg_src( sprintf( '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 %s %s">%s</svg>', (float) $signature['vw'], (float) $signature['vh'], $paths ) );
}

/**
 * Whether a certificate has the declaration page.
 *
 * @param array<string, mixed> $cert Certificate.
 * @return bool
 */
function has_declaration( array $cert ) {
	$data = json_decode( (string) ( $cert['data_json'] ?? '' ), true );

	// The participant's own account of their days and talks: not a speaker's or an organizer's.
	return in_array( (string) ( $cert['role'] ?? '' ), array( '', 'attendee' ), true ) && is_array( $data ) && ! empty( $data['page2'] );
}

/**
 * The declaration page (decision 0014): a standard text, not a design — who,
 * which document, which event, which days, the workload they declared and
 * the activities — in the design's fonts and colors, the size of its page.
 *
 * @param array<string, mixed>  $cert    Certificate.
 * @param array<string, mixed>  $design  Design.
 * @param array<string, string> $values  values().
 * @param array<string, mixed>  $options mode; document (the full number, only right after issuing).
 * @return string HTML.
 */
function declaration( array $cert, array $design, array $values, array $options = array() ) {
	// The template's own declaration sheet (designed in the editor), else the standard page below.
	if ( ! empty( $design['back']['elements'] ) && is_array( $design['back']['elements'] ) ) {
		$sheet             = $design;
		$sheet['elements'] = $design['back']['elements'];
		if ( '' !== (string) ( $options['document'] ?? '' ) ) {
			$values['document']              = (string) $options['document'];
			$values['declaration_statement'] = statement_text( $cert, $values, (array) json_decode( (string) ( $cert['data_json'] ?? '' ), true ) );
		}
		return artwork( $sheet, $values, $options + array( 'page_break' => ! empty( $options['for_pdf'] ) ) );
	}
	$mode  = (string) ( $options['mode'] ?? 'light' );
	$page  = (array) $design['page'];
	$data  = (array) json_decode( (string) ( $cert['data_json'] ?? '' ), true );
	$text  = color( $design, '$text', $mode );
	$muted = isset( $design['colors']['muted'] ) ? color( $design, '$muted', $mode ) : $text;
	$bg    = color( $design, page_bg( $page, $mode ), $mode );
	$pad   = max( 36.0, (float) ( $page['safe'] ?? 0 ) + 24 );
	$doc   = '' !== (string) ( $options['document'] ?? '' ) ? (string) $options['document'] : (string) ( $cert['doc_masked'] ?? '' );
	$hours = field_value( 'hours', '', $values );

	$who = '<strong>' . esc_html( $values['name'] ) . '</strong>';
	if ( '' !== $doc ) {
		/* translators: 1: person's name, 2: document type (CPF), 3: document number. */
		$who = sprintf( __( '%1$s, %2$s %3$s,', 'events-certificates' ), $who, esc_html( document_label( $cert ) ), '<strong>' . esc_html( $doc ) . '</strong>' );
	}
	$country  = ! empty( $data['country'] ) ? \EventsCertificate\Countries\name( (string) $data['country'] ) : '';
	$sentence = sprintf(
		/* translators: 1: person (name and document), 2: event, 3: place, 4: days, 5: workload. */
		__( 'We declare that %1$s took part in %2$s, held in %3$s, on %4$s, with a self-declared workload of %5$s, in the activities below.', 'events-certificates' ),
		$who,
		'<strong>' . esc_html( $values['event'] ) . '</strong>',
		esc_html( place_with_country( $values['location'], $country ) ),
		esc_html( '' !== $values['day'] ? $values['day'] : format_date( (int) $values['event_ts'], 'long' ) ),
		'<strong>' . esc_html( '' !== $hours ? $hours : '—' ) . '</strong>'
	);

	$list = '';
	foreach ( (array) ( $data['days'] ?? array() ) as $id ) {
		$day   = \EventsCertificate\Days\get( (string) $id );
		$list .= sprintf( '<h2 style="font-family:\'Inter\';font-weight:600;font-size:10.5pt;margin:10pt 0 3pt;color:%s">%s</h2>', esc_attr( $text ), esc_html( day_heading( $day, (string) $id ) ) );
		if ( isset( $data['table'][ $id ] ) ) {
			$span = (array) ( $data['table_time'][ $id ] ?? array() );
			$time = 2 === count( $span ) ? ' · ' . \EventsCertificate\Dates\time_text( (int) $span[0] ) . '–' . \EventsCertificate\Dates\time_text( (int) $span[1] ) : '';
			/* translators: %s: table name (Contributor Day). */
			$list .= '<p style="margin:0 0 2pt">' . esc_html( sprintf( __( 'Table: %s', 'events-certificates' ), (string) $data['table'][ $id ] ) . $time ) . '</p>';
		}
		$items = '';
		foreach ( (array) ( $data['sessions'] ?? array() ) as $session ) {
			if ( ( $session['day'] ?? '' ) !== $id ) {
				continue;
			}
			$items .= sprintf(
				'<li style="margin:0 0 1pt">%s · %s%s</li>',
				esc_html( \EventsCertificate\Dates\time_text( (int) $session['start'] ) . '–' . \EventsCertificate\Dates\time_text( (int) $session['end'] ) ),
				esc_html( (string) $session['title'] ),
				! empty( $session['speakers'] ) ? sprintf( ' <span style="color:%s">— %s</span>', esc_attr( $muted ), esc_html( implode( ', ', (array) $session['speakers'] ) ) ) : ''
			);
		}
		$list .= '' !== $items ? '<ul style="margin:0;padding-left:14pt">' . $items . '</ul>' : '';
	}

	$signature = signature_src( $design, $values, $text );
	$footer    = sprintf(
		'<p style="margin:0">%s</p>%s<p style="margin:%s 0 0;line-height:1.3"><strong>%s</strong><br><span style="color:%s">%s</span></p>',
		esc_html( place_and_date( $values['location'], format_date( (int) $values['issued_ts'], 'long' ) ) ),
		'' !== $signature ? sprintf( '<img alt="" src="%s" style="display:block;height:34pt;max-width:150pt;margin:6pt 0 0">', esc_attr( $signature ) ) : '',
		'' !== $signature ? '1pt' : '10pt',
		esc_html( $values['signer_name'] ),
		esc_attr( $muted ),
		esc_html( $values['signer_role'] )
	);
	// Always on the declaration: the QR code, the code and the hint (on sites others can reach, decision 0010).
	$verification = '';
	if ( verifiable() || ! empty( $options['preview'] ) ) {
		$qr_back      = 'none' === $bg ? ( 'dark' === $mode ? '#000000' : '#ffffff' ) : $bg;
		$verification = sprintf(
			// Two columns, all to the right: the code and the hint, then the QR code (less height than stacked).
			'<table style="margin:0 0 0 auto;border-collapse:collapse;font-size:7pt;line-height:1.35;color:%2$s"><tr>'
			. '<td style="text-align:right;vertical-align:middle;padding:0 8pt 0 0"><div style="font-family:\'Inter\';font-weight:500;font-size:8pt;color:%4$s">%5$s</div><div>%6$s<br>%7$s</div></td>'
			. '<td style="vertical-align:middle;padding:0"><img alt="" src="%3$s" style="width:52pt;height:52pt;display:block"></td>'
			. '</tr></table>',
			0,
			esc_attr( $muted ),
			esc_attr( qr_src( \EventsCertificate\Certificates\url( $values['code'] ), $text, $qr_back, ! empty( $options['preview'] ) ) ),
			esc_attr( $text ),
			esc_html( $values['code'] ),
			esc_html( $values['verify_hint'] ),
			esc_html( $values['verify_url'] )
		);
	}

	return sprintf(
		// An inner box with its own width: dompdf ignores box-sizing with padding (the text wouldn't wrap).
		'<div class="evcert-page evcert-page--declaration" style="%10$sposition:relative;overflow:hidden;width:%1$.2Fpt;height:%2$.2Fpt;background:%4$s;color:%5$s;font-family:\'Inter\';font-size:9.5pt;line-height:1.38">'
		. '<div style="position:absolute;left:%3$.2Fpt;top:%3$.2Fpt;width:%11$.2Fpt">'
		. '<h1 style="font-family:\'Lora\';font-weight:500;font-size:17pt;margin:0 0 10pt">%6$s</h1><p style="margin:0">%7$s</p>%8$s</div>'
		// A bottom band: place, date and signer on the left; code, hint and QR code on the right; under it the
		// footnote: the activities are self-declared, the event only confirms presence per day (decision 0014).
		. '<div style="position:absolute;left:%3$.2Fpt;bottom:%3$.2Fpt;width:%11$.2Fpt">'
		. '<table style="width:100%%;border-collapse:collapse"><tr><td style="vertical-align:bottom;padding:0">%9$s</td><td style="vertical-align:bottom;padding:0;text-align:right">%12$s</td></tr></table>'
		. '<p style="margin:8pt 0 0;font-size:6.5pt;line-height:1.35;color:%14$s">%15$s</p></div></div>',
		(float) $page['w'],
		(float) $page['h'],
		$pad,
		esc_attr( 'none' === $bg ? '#ffffff' : $bg ),
		esc_attr( $text ),
		esc_html__( 'Declaration of participation', 'events-certificates' ),
		$sentence,
		$list,
		$footer,
		! empty( $options['for_pdf'] ) ? 'page-break-before:always;' : '',
		max( 100.0, (float) $page['w'] - 2 * $pad ),
		$verification,
		0,
		esc_attr( $muted ),
		esc_html__( 'Activities and workload declared by the participant. The event confirms only their presence on each day; sessions are chosen freely and are not checked one by one.', 'events-certificates' )
	);
}

/**
 * The standard texts of a certificate, as fields: a design holds the field,
 * not the words, so the text comes from the plugin's translation in the
 * language the certificate is shown in. A speaker's, an organizer's, a volunteer's
 * and a sponsor's say their own title and "gave a talk at", "organized"…
 *
 * @param string $role Role of the certificate (attendee by default).
 * @return array<string, string> Field key => text in the current locale.
 */
function phrases( $role = 'attendee' ) {
	$role    = isset( \EventsCertificate\Designs\role_labels()[ $role ] ) ? $role : 'attendee';
	$phrases = array(
		'title_text'        => __( 'Certificate of participation', 'events-certificates' ),
		'intro_text'        => __( 'This is to certify that', 'events-certificates' ),
		'issued_label'      => __( 'Date of issue', 'events-certificates' ),
		// The band's text is the site's to give (a Base design filter, or the template): nothing by default.
		'band_text'         => '',
		'took_part_text'    => __( 'took part in', 'events-certificates' ),
		'date_label'        => __( 'DATE', 'events-certificates' ),
		'time_label'        => __( 'TIME', 'events-certificates' ),
		'venue_label'       => __( 'VENUE', 'events-certificates' ),
		'place_date_label'  => __( 'Place and date', 'events-certificates' ),
		'verify_hint'       => __( 'Use the code above to verify at:', 'events-certificates' ),
		'declaration_title' => __( 'Declaration of participation', 'events-certificates' ),
		'declaration_note'  => __( 'Activities and workload declared by the participant. The event confirms only their presence on each day; sessions are chosen freely and are not checked one by one.', 'events-certificates' ),
	);
	// What a certificate of another role says in place of "Certificate of participation" and "took part in".
	$by_role = array(
		'speaker'   => array(
			'title_text'     => __( 'Speaker certificate', 'events-certificates' ),
			'took_part_text' => __( 'gave a talk at', 'events-certificates' ),
		),
		'organizer' => array(
			'title_text'     => __( 'Organizer certificate', 'events-certificates' ),
			'took_part_text' => __( 'organized', 'events-certificates' ),
		),
		'volunteer' => array(
			'title_text'     => __( 'Volunteer certificate', 'events-certificates' ),
			'took_part_text' => __( 'volunteered at', 'events-certificates' ),
		),
		'sponsor'   => array(
			'title_text'     => __( 'Sponsor certificate', 'events-certificates' ),
			'took_part_text' => __( 'sponsored', 'events-certificates' ),
		),
	);
	if ( isset( $by_role[ $role ] ) ) {
		$phrases = array_merge( $phrases, $by_role[ $role ] );
	}

	/**
	 * Filters the standard texts of a certificate (title, introduction, labels…) for a role.
	 *
	 * @param array<string, string> $phrases Texts by field.
	 * @param string                $role    Role: attendee, speaker, organizer, volunteer or sponsor.
	 */
	return apply_filters( 'evcert_phrases', $phrases, $role );
}

/**
 * The time of an event as its certificate says it: "from 7pm to 10pm (3 hours)",
 * "starting at 7pm", or nothing for an event without times.
 *
 * @param array<string, string> $event Event (Events\get()).
 * @return string
 */
function event_time( array $event ) {
	$start = date_create_immutable_from_format( 'Y-m-d H:i', $event['start'], wp_timezone() );
	$end   = date_create_immutable_from_format( 'Y-m-d H:i', $event['end'], wp_timezone() );
	if ( ! $start || ( '00:00' === $start->format( 'H:i' ) && $event['end'] === $event['start'] ) ) {
		return '';
	}
	$clock = static function ( $time ) {
		return \EventsCertificate\Dates\time_text( $time->getTimestamp() );
	};
	if ( ! $end || $end <= $start ) {
		/* translators: %s: start time. */
		return sprintf( __( 'starting at %s', 'events-certificates' ), $clock( $start ) );
	}
	/* translators: 1: start time, 2: end time. */
	$text = sprintf( __( 'from %1$s to %2$s', 'events-certificates' ), $clock( $start ), $clock( $end ) );
	if ( '0' !== ( $event['show_hours'] ?? '1' ) ) {
		$minutes = (int) round( ( $end->getTimestamp() - $start->getTimestamp() ) / 60 );
		$h       = intdiv( $minutes, 60 );
		$m       = $minutes % 60;
		if ( ! $m ) {
			/* translators: %d: number of hours. */
			$span = sprintf( _n( '%d hour', '%d hours', $h, 'events-certificates' ), $h );
		} elseif ( $h ) {
			/* translators: 1: hours, 2: minutes (two digits), as in 1h30. */
			$span = sprintf( __( '%1$dh%2$02d', 'events-certificates' ), $h, $m );
		} else {
			/* translators: %d: number of minutes. */
			$span = sprintf( _n( '%d minute', '%d minutes', $m, 'events-certificates' ), $m );
		}
		/* translators: 1: the event's hours (from 7pm to 10pm), 2: how long it lasts (3 hours). */
		$text = sprintf( __( '%1$s (%2$s)', 'events-certificates' ), $text, $span );
	}

	return $text;
}

/**
 * An address as a short link: no scheme, "www.", query or trailing slash.
 *
 * @param string $url Address.
 * @return string
 */
function short_link( $url ) {
	return untrailingslashit( (string) preg_replace( array( '#^https?://(www\.)?#', '#\?.*$#' ), '', (string) $url ) );
}

/**
 * "SPEAKER" or "SPEAKERS", by how many people the field lists.
 *
 * @param string $speakers The speakers field.
 * @return string
 */
function speakers_label( $speakers ) {
	// The field is typed in any language: a list with a comma, a semicolon, "&" or a common "and" word.
	$many = preg_match( '/[,;&]/', $speakers ) || preg_match( '/\s(and|e|y|et|und|en|och)\s/iu', $speakers ) || preg_match( '/\s' . preg_quote( __( 'and', 'events-certificates' ), '/' ) . '\s/iu', $speakers );

	return $many ? __( 'SPEAKERS', 'events-certificates' ) : __( 'SPEAKER', 'events-certificates' );
}

/**
 * A date in a format of the date field.
 *
 * @param int    $ts     Timestamp.
 * @param string $format site|long|medium|short|month|iso.
 * @return string
 */
function format_date( $ts, $format ) {
	if ( 'iso' === $format ) {
		return wp_date( 'Y-m-d', $ts );
	}
	if ( ! $format || 'site' === $format || ! class_exists( 'IntlDateFormatter' ) ) {
		return \EventsCertificate\Dates\date_text( $ts );
	}
	$styles = array(
		'long'   => \IntlDateFormatter::LONG,
		'medium' => \IntlDateFormatter::MEDIUM,
		'short'  => \IntlDateFormatter::SHORT,
	);
	// ICU knows zone names and "GMT+03:00", not the bare "+03:00" WordPress keeps for a UTC offset.
	$zone = wp_timezone_string();
	$zone = preg_match( '/^[+-]\d{2}:\d{2}$/', $zone ) ? 'GMT' . $zone : $zone;
	try {
		$intl = new \IntlDateFormatter( determine_locale(), $styles[ $format ] ?? \IntlDateFormatter::LONG, \IntlDateFormatter::NONE, $zone );
		if ( 'month' === $format ) {
			$intl->setPattern( 'LLLL y' );
		}
		$out = $intl->format( $ts );
	} catch ( \Throwable $e ) {
		$out = false;
	}

	return false === $out ? \EventsCertificate\Dates\date_text( $ts ) : $out;
}

/**
 * A text element's font size: its own, or smaller when it must fit its width.
 *
 * @param array<string, mixed>  $el      Text element.
 * @param array<string, string> $values  values().
 * @param array<string, mixed>  $options Options (measure).
 * @return float
 */
function text_size( array $el, array $values, array $options ) {
	$size = (float) ( $el['size'] ?? 14 );
	if ( ! empty( $el['fit'] ) && isset( $options['measure'] ) ) {
		$width = (float) call_user_func( $options['measure'], runs_text( $el, $values ), (string) $el['font'], (int) $el['weight'], $size );
		if ( $width > $el['w'] && $width > 0 ) {
			$size = max( (float) ( $el['min'] ?? 8 ), $size * $el['w'] / $width );
		}
	}

	return $size;
}

/**
 * How far up the PDF moves a text of the generator's fonts, in font sizes, so
 * its first line sits where the browser puts it: the browser's baseline is
 * ((line height - 1.3) / 2 + ascent) below the top of the box, and dompdf's
 * measured on these fonts is 0.004 + 1.146 × line height.
 *
 * @param array<string, mixed> $el Text element.
 * @return float
 */
function pdf_baseline_shift( array $el ) {
	$ascent = array(
		'CertSans'  => 1.025,
		'CertSerif' => 0.99,
	);
	$font   = (string) ( $el['font'] ?? '' );
	if ( ! isset( $ascent[ $font ] ) ) {
		return 0.0;
	}
	$lh = (float) ( $el['lh'] ?? 1.2 );

	return ( ( $lh - 1.3 ) / 2 + $ascent[ $font ] ) - ( 0.004 + 1.146 * $lh );
}

/**
 * One field's text.
 *
 * @param string                $field  Field key.
 * @param string                $format Format.
 * @param array<string, string> $values values().
 * @return string
 */
function field_value( $field, $format, array $values ) {
	if ( 'issued_date' === $field || 'event_date' === $field ) {
		return format_date( (int) $values[ 'issued_date' === $field ? 'issued_ts' : 'event_ts' ], (string) $format );
	}
	if ( 'hours' === $field ) {
		if ( '' === $values['hours'] ) {
			return '';
		}
		if ( 'short' === $format ) {
			/* translators: %s: number of hours, short (3h). */
			return sprintf( _x( '%sh', 'hours, short', 'events-certificates' ), $values['hours'] );
		}
		/* translators: %s: number of hours. */
		return sprintf( _n( '%s hour', '%s hours', (int) ceil( (float) $values['hours'] ), 'events-certificates' ), number_format_i18n( (float) $values['hours'], floor( (float) $values['hours'] ) === (float) $values['hours'] ? 0 : 1 ) );
	}

	return (string) ( $values[ $field ] ?? '' );
}

/**
 * Words that stand for a field in designs made before the field existed (the
 * standard texts, and the Base's sample signer role), in English and in each
 * of the site's languages: they render as the field, so certificates already
 * issued show them in the page's language and with the real signer.
 *
 * @param string $text Text of a run, trimmed.
 * @return string Field key, or ''.
 */
function phrase_field( $text ) {
	static $keys = null;
	if ( null === $keys ) {
		$english = array(
			'Certificate of participation'     => 'title_text',
			'This is to certify that'          => 'intro_text',
			'Date of issue'                    => 'issued_label',
			'Date of emission'                 => 'issued_label',
			'Place and date'                   => 'place_date_label',
			'Use the code above to verify at:' => 'verify_hint',
			'Event Coordinator'                => 'signer_role',
		);
		$keys    = array();
		$locales = \EventsCertificate\Verify\locales();
		foreach ( $locales as $locale ) {
			$switched = switch_to_locale( $locale );
			foreach ( $english as $words => $key ) {
				$keys[ mb_strtolower( translate( $words, 'events-certificates' ) ) ] = $key; // phpcs:ignore WordPress.WP.I18n.LowLevelTranslationFunction, WordPress.WP.I18n.NonSingularStringLiteralText -- the same strings as phrases(), looked up by their words.
			}
			if ( $switched ) {
				restore_previous_locale();
			}
		}
		foreach ( $english as $words => $key ) {
			$keys[ mb_strtolower( $words ) ] = $key;
		}
	}

	return $keys[ mb_strtolower( $text ) ] ?? '';
}

/**
 * A text element's runs as HTML.
 *
 * @param array<string, mixed>  $el     Text element.
 * @param array<string, string> $values values().
 * @return string
 */
function runs_html( array $el, array $values ) {
	$runs = element_runs( $el );
	$html = '';
	$gap  = true; // At the start, or right after an empty field.
	foreach ( $runs as $run ) {
		$text = isset( $run['field'] ) ? field_value( (string) $run['field'], (string) ( $run['format'] ?? '' ), $values ) : (string) ( $run['text'] ?? '' );
		// A separator (", ", " · ") next to an empty field goes with it: "Recife, May 5" without a place is "May 5".
		if ( ! isset( $run['field'] ) && $gap && preg_match( '/^[\s,;·•–—|-]+$/u', $text ) ) {
			continue;
		}
		$gap = isset( $run['field'] ) && '' === trim( $text );
		if ( ! isset( $run['field'] ) && preg_match( '/^(\s*)(.+?)(\s*)$/su', $text, $m ) && '' !== phrase_field( $m[2] ) ) {
			$text = $m[1] . field_value( phrase_field( $m[2] ), '', $values ) . $m[3];
		}
		if ( ! empty( $el['upper'] ) ) {
			$text = function_exists( 'mb_strtoupper' ) ? mb_strtoupper( $text ) : strtoupper( $text );
		}
		$part = nl2br( esc_html( $text ), false );
		if ( ! empty( $run['i'] ) ) {
			$part = '<em>' . $part . '</em>';
		}
		if ( ! empty( $run['b'] ) ) {
			$part = '<strong>' . $part . '</strong>';
		}
		$html .= $part;
	}

	return $html;
}

/**
 * The runs a text shows: the variant of the language in force when it has one
 * (a full list, as the order of the fields changes between languages), else its own.
 *
 * @param array<string, mixed> $el Text element.
 * @return array<int, array<string, mixed>>
 */
function element_runs( array $el ) {
	$variants = isset( $el['variants'] ) && is_array( $el['variants'] ) ? $el['variants'] : array();
	$locale   = determine_locale();
	if ( ! empty( $variants[ $locale ] ) && is_array( $variants[ $locale ] ) ) {
		return $variants[ $locale ];
	}

	return isset( $el['runs'] ) && is_array( $el['runs'] ) ? $el['runs'] : array( array( 'text' => (string) ( $el['text'] ?? '' ) ) );
}

/**
 * Plain text of a text element (to measure it).
 *
 * @param array<string, mixed>  $el     Text element.
 * @param array<string, string> $values values().
 * @return string
 */
function runs_text( array $el, array $values ) {
	return html_entity_decode( wp_strip_all_tags( str_replace( '<br>', ' ', runs_html( $el, $values ) ) ), ENT_QUOTES, 'UTF-8' );
}

/**
 * An SVG document as an image source.
 *
 * @param string $svg SVG markup.
 * @return string
 */
function svg_src( $svg ) {
	return 'data:image/svg+xml;base64,' . base64_encode( $svg ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- a data URI for dompdf and the browser, not obfuscation.
}

/**
 * The authenticity mark: the real QR code on public sites, the placeholder
 * elsewhere (docs/decisions/0010).
 *
 * The QR code keeps its quiet zone (4 modules), which readers need to find
 * it; in the dark mode it is inverted (light modules on the dark page), which
 * current readers handle.
 *
 * @param string $url   Verification address.
 * @param string $color Color of the modules.
 * @param string $back  Color of the quiet zone and the light modules.
 * @param bool   $placeholder Draw the placeholder anyway (previews).
 * @return string Image source.
 */
function qr_src( $url, $color, $back = 'none', $placeholder = false ) {
	if ( ! $placeholder && verifiable() && class_exists( '\EventsCertificate\Vendor\chillerlan\QRCode\QRCode' ) ) {
		$options = new \EventsCertificate\Vendor\chillerlan\QRCode\QROptions(
			array(
				'outputType'    => \EventsCertificate\Vendor\chillerlan\QRCode\QRCode::OUTPUT_MARKUP_SVG,
				'eccLevel'      => \EventsCertificate\Vendor\chillerlan\QRCode\QRCode::ECC_M,
				'addQuietzone'  => true,
				'quietzoneSize' => 4,
				'imageBase64'   => true,
				'markupDark'    => $color,
				'markupLight'   => $back,
			)
		);
		return ( new \EventsCertificate\Vendor\chillerlan\QRCode\QRCode( $options ) )->render( $url );
	}
	$svg = (string) file_get_contents( dirname( __DIR__ ) . '/assets/qr-placeholder.svg' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- the plugin's own asset.

	return svg_src( str_replace( 'currentColor', $color, $svg ) );
}

/**
 * An image source dompdf can read: data URIs as they are, this site's media
 * as files (dompdf doesn't fetch URLs).
 *
 * @param string $src    Source.
 * @param bool   $for_pdf Whether dompdf reads it.
 * @return string
 */
function image_src( $src, $for_pdf ) {
	$src = (string) $src;
	// Raster images inline (the editor's uploads), or addresses on the web.
	if ( preg_match( '#^data:image/(png|jpe?g|gif|webp);base64,[A-Za-z0-9+/=]+$#', $src ) ) {
		return $src;
	}
	if ( ! $for_pdf ) {
		return esc_url_raw( $src, array( 'http', 'https' ) );
	}
	// dompdf reads only this site's media, as files inside the uploads folder.
	$uploads = wp_get_upload_dir();
	if ( 0 === strpos( $src, $uploads['baseurl'] . '/' ) ) {
		$file = realpath( $uploads['basedir'] . '/' . rawurldecode( substr( $src, strlen( $uploads['baseurl'] ) + 1 ) ) );
		$base = realpath( $uploads['basedir'] );
		return $file && $base && 0 === strpos( $file, $base . DIRECTORY_SEPARATOR ) && is_readable( $file ) ? $file : '';
	}

	return '';
}

/**
 * One element as HTML.
 *
 * @param array<string, mixed>  $el      Element (resolved).
 * @param array<string, mixed>  $design  Design.
 * @param array<string, string> $values  values().
 * @param array<string, mixed>  $options Options (mode, for_pdf, measure).
 * @return string
 */
function element_html( array $el, array $design, array $values, array $options ) {
	$mode    = $options['mode'];
	$type    = (string) ( $el['type'] ?? '' );
	$opacity = isset( $el['opacity'] ) ? (float) $el['opacity'] : 1.0;
	$rotate  = ! empty( $el['rotation'] ) && 'line' !== $type ? sprintf( 'transform:rotate(%ddeg);transform-origin:50%% 50%%;', (int) $el['rotation'] ) : '';
	if ( 'text' === $type && ! empty( $options['for_pdf'] ) ) {
		$el['y'] += pdf_baseline_shift( $el ) * text_size( $el, $values, $options );
	}
	$box   = 'line' === $type ? '' : sprintf( 'position:absolute;left:%.2Fpt;top:%.2Fpt;width:%.2Fpt;height:%.2Fpt;%s', $el['x'], $el['y'], max( 0, $el['w'] ), max( 0, $el['h'] ), $rotate );
	$alpha = $opacity < 1 ? sprintf( 'opacity:%.3F;', $opacity ) : '';
	$img   = static function ( $src, $style, $alt = '' ) {
		return '' === $src ? '' : sprintf( '<img alt="%s" src="%s" style="%s">', esc_attr( $alt ), esc_attr( $src ), esc_attr( $style ) );
	};

	// A preview (includes/preview.php) shows the verification parts with placeholders, on any site.
	// The declaration page carries it when the template says so (first_page_options()).
	if ( is_verification( $el ) && ( ! empty( $options['no_verification'] ) || ( ! verifiable() && empty( $options['preview'] ) ) ) ) {
		return '';
	}

	switch ( $type ) {
		case 'text':
			$size  = text_size( $el, $values, $options );
			$style = $box . sprintf(
				"height:auto;font-family:'%s';font-weight:%d;font-size:%.3Fpt;line-height:%s;color:%s;text-align:%s;%s%s%s",
				preg_replace( '/[^A-Za-z0-9 -]/', '', (string) ( $el['font'] ?? 'Inter' ) ),
				(int) ( $el['weight'] ?? 400 ),
				$size,
				(float) ( $el['lh'] ?? 1.2 ),
				color( $design, $el['color'] ?? '$text', $mode ),
				in_array( $el['align'] ?? 'left', array( 'left', 'center', 'right' ), true ) ? ( $el['align'] ?? 'left' ) : 'left',
				! empty( $el['ls'] ) ? sprintf( 'letter-spacing:%.2Fpt;', $el['ls'] ) : '',
				! empty( $el['fit'] ) ? 'white-space:nowrap;' : '',
				$alpha
			);
			return sprintf( '<div class="evcert-t" style="%s">%s</div>', esc_attr( $style ), runs_html( $el, $values ) );

		case 'rect':
			$d   = rect_path( 0, 0, $el['w'], $el['h'], (array) ( $el['radius'] ?? array() ), (string) ( $el['corner'] ?? 'round' ) );
			$sw  = (float) ( $el['strokeWidth'] ?? 0 );
			$svg = sprintf(
				'<svg xmlns="http://www.w3.org/2000/svg" width="%1$.2F" height="%2$.2F" viewBox="%3$.2F %3$.2F %4$.2F %5$.2F"><path d="%6$s" fill="%7$s" stroke="%8$s" stroke-width="%9$.2F"/></svg>',
				$el['w'] + $sw,
				$el['h'] + $sw,
				-$sw / 2,
				$el['w'] + $sw,
				$el['h'] + $sw,
				$d,
				esc_attr( color( $design, $el['fill'] ?? '', $mode ) ),
				esc_attr( color( $design, $el['stroke'] ?? '', $mode ) ),
				$sw
			);
			return $img( svg_src( $svg ), sprintf( 'position:absolute;left:%.2Fpt;top:%.2Fpt;width:%.2Fpt;height:%.2Fpt;%s%s', $el['x'] - $sw / 2, $el['y'] - $sw / 2, $el['w'] + $sw, $el['h'] + $sw, $rotate, $alpha ) );

		case 'ellipse':
			$svg = sprintf(
				'<svg xmlns="http://www.w3.org/2000/svg" width="%1$.2F" height="%2$.2F" viewBox="0 0 %1$.2F %2$.2F"><ellipse cx="%3$.2F" cy="%4$.2F" rx="%3$.2F" ry="%4$.2F" fill="%5$s"/></svg>',
				$el['w'],
				$el['h'],
				$el['w'] / 2,
				$el['h'] / 2,
				esc_attr( color( $design, $el['fill'] ?? '', $mode ) )
			);
			return $img( svg_src( $svg ), $box . $alpha );

		case 'line':
			$sw   = max( 0.25, (float) ( $el['strokeWidth'] ?? 1 ) );
			$x    = min( $el['x1'], $el['x2'] );
			$y    = min( $el['y1'], $el['y2'] );
			$w    = abs( $el['x2'] - $el['x1'] );
			$h    = abs( $el['y2'] - $el['y1'] );
			$dash = array(
				'dashed' => '6 4',
				'dotted' => '1 3',
			)[ $el['dash'] ?? '' ] ?? '';
			$svg  = sprintf(
				'<svg xmlns="http://www.w3.org/2000/svg" width="%1$.2F" height="%2$.2F" viewBox="%3$.2F %4$.2F %1$.2F %2$.2F"><line x1="%5$.2F" y1="%6$.2F" x2="%7$.2F" y2="%8$.2F" stroke="%9$s" stroke-width="%10$.2F" stroke-linecap="round"%11$s/></svg>',
				$w + $sw * 2,
				$h + $sw * 2,
				$x - $sw,
				$y - $sw,
				$el['x1'],
				$el['y1'],
				$el['x2'],
				$el['y2'],
				esc_attr( color( $design, $el['stroke'] ?? '$line', $mode ) ),
				$sw,
				$dash ? ' stroke-dasharray="' . $dash . '"' : ''
			);
			return $img( svg_src( $svg ), sprintf( 'position:absolute;left:%.2Fpt;top:%.2Fpt;width:%.2Fpt;height:%.2Fpt;%s', $x - $sw, $y - $sw, $w + $sw * 2, $h + $sw * 2, $alpha ) );

		case 'svg':
			// The signature element draws the signer's signature when there is one.
			$signature = json_decode( $values['signature'] ?? 'null', true );
			$is_slot   = 'signature' === ( $el['slot'] ?? '' ) || 'signature' === ( $el['id'] ?? '' );
			// No one's signature to draw: a certificate shows none rather than the template's sample (previews keep it).
			if ( $is_slot && ( ! is_array( $signature ) || empty( $signature['paths'] ) ) && empty( $options['preview'] ) ) {
				return '';
			}
			if ( $is_slot && is_array( $signature ) && ! empty( $signature['paths'] ) ) {
				// Upright, filling the box the element shows on the page (a design may rotate its own art by 90°).
				if ( in_array( (int) ( $el['rotation'] ?? 0 ) % 180, array( 90, -90 ), true ) ) {
					$cx                        = $el['x'] + $el['w'] / 2;
					$cy                        = $el['y'] + $el['h'] / 2;
					list( $el['w'], $el['h'] ) = array( $el['h'], $el['w'] );
					$el['x']                   = $cx - $el['w'] / 2;
					$el['y']                   = $cy - $el['h'] / 2;
				}
				$el['rotation'] = 0;
				$el             = array_merge( $el, array_intersect_key( $signature, array_flip( array( 'vw', 'vh', 'paths' ) ) ) );
			}
			$box     = sprintf( 'position:absolute;left:%.2Fpt;top:%.2Fpt;width:%.2Fpt;height:%.2Fpt;%s', $el['x'], $el['y'], max( 0, $el['w'] ), max( 0, $el['h'] ), ! empty( $el['rotation'] ) ? sprintf( 'transform:rotate(%ddeg);transform-origin:50%% 50%%;', (int) $el['rotation'] ) : '' );
			$current = color( $design, $el['color'] ?? '$text', $mode );
			$paths   = '';
			foreach ( (array) ( $el['paths'] ?? array() ) as $path ) {
				if ( ! is_path_data( $path['d'] ?? '' ) ) {
					continue;
				}
				$paths .= sprintf(
					'<path d="%s" fill="%s"%s/>',
					// dompdf draws SVG arcs wrong: the PDF gets them as Bézier curves.
					esc_attr( empty( $options['for_pdf'] ) ? $path['d'] : without_arcs( $path['d'] ) ),
					esc_attr( isset( $path['fill'] ) ? color( $design, $path['fill'], $mode ) : $current ),
					'evenodd' === ( $path['rule'] ?? '' ) ? ' fill-rule="evenodd"' : ''
				);
			}
			// The title names the drawing: the image's alt text and the SVG's <title>.
			$title = trim( (string) ( $el['title'] ?? '' ) );
			$svg   = sprintf( '<svg xmlns="http://www.w3.org/2000/svg" width="%1$.2F" height="%2$.2F" viewBox="0 0 %3$s %4$s">%5$s%6$s</svg>', $el['w'], $el['h'], (float) $el['vw'], (float) $el['vh'], '' === $title ? '' : '<title>' . esc_html( $title ) . '</title>', $paths );
			return $img( svg_src( $svg ), $box . $alpha, $title );

		case 'image':
			$src = image_src( (string) ( $el['src'] ?? '' ), ! empty( $options['for_pdf'] ) );
			return $img( $src, $box . $alpha . 'object-fit:' . ( 'contain' === ( $el['fit'] ?? 'cover' ) ? 'contain' : 'cover' ) . ';' );

		case 'qr':
			// Modules in the text color on the page color (so the dark mode inverts it), with a solid quiet zone.
			$page_bg = color( $design, page_bg( (array) $design['page'], $mode ), $mode );
			if ( in_array( $page_bg, array( 'none', 'transparent' ), true ) ) {
				$page_bg = 'dark' === $mode ? '#000000' : '#ffffff';
			}
			return $img( qr_src( Certificates\url( $values['code'] ), color( $design, '$text', $mode ), $page_bg, ! empty( $options['preview'] ) ), $box . $alpha );
	}

	return '';
}

/**
 * Whether certificates here can be checked by others: verification is on (it is off by default, docs/decisions/0028)
 * and the site is public. On a site that isn't public (localhost, Playground…) nobody else can open the verification
 * address, so certificates carry no QR code, code, address or hint (docs/decisions/0010).
 *
 * @return bool
 */
function verifiable() {
	return Environment\verification_enabled() && Environment\is_public();
}

/**
 * Whether an element only serves verification: the QR code, the code, the
 * verification hint, or a text that shows the code or the verification address.
 *
 * @param array<string, mixed> $el Element.
 * @return bool
 */
function is_verification( array $el ) {
	if ( 'qr' === ( $el['type'] ?? '' ) || in_array( $el['slot'] ?? '', array( 'verify', 'code' ), true ) ) {
		return true;
	}
	foreach ( (array) ( $el['runs'] ?? array() ) as $run ) {
		if ( in_array( $run['field'] ?? '', array( 'code', 'verify_url', 'verify_hint' ), true ) ) {
			return true;
		}
	}

	return false;
}

/**
 * The @font-face rules for the design fonts.
 *
 * @param bool $for_pdf Files (dompdf) or URLs (browser).
 * @return string
 */
function fonts_css( $for_pdf ) {
	$dir = dirname( __DIR__ ) . '/fonts/';
	$url = plugins_url( 'fonts/', dirname( __DIR__ ) . '/events-certificates.php' );
	$css = '';
	foreach ( FONTS as $font ) {
		$css .= sprintf(
			"@font-face{font-family:'%s';font-weight:%d;font-style:normal;src:url('%s%s.ttf') format('truetype');}",
			$font[0],
			$font[1],
			$for_pdf ? $dir : esc_url_raw( $url ),
			$font[2]
		);
	}

	return $css;
}

/**
 * The certificate's artwork: a page-sized box with the positioned elements.
 *
 * @param array<string, mixed>  $design  Design.
 * @param array<string, string> $values  values().
 * @param array<string, mixed>  $options mode (light|dark), for_pdf, measure (callable).
 * @return string
 */
function artwork( array $design, array $values, array $options = array() ) {
	$options = array_merge(
		array(
			'mode'    => 'light',
			'for_pdf' => false,
		),
		$options
	);
	$page    = (array) $design['page'];
	$bg      = color( $design, page_bg( $page, (string) $options['mode'] ), $options['mode'] );
	$html    = '';
	$orphans = orphan_labels( (array) $design['elements'], $values );
	foreach ( (array) $design['elements'] as $el ) {
		if ( ! is_array( $el ) || ! empty( $el['hidden'] ) || in_array( $el['id'] ?? '', $orphans, true ) ) {
			continue;
		}
		$html .= element_html( resolve( $el, $page ), $design, $values, $options );
	}

	return sprintf(
		'<div class="evcert-page" style="%s position:relative;overflow:hidden;width:%.2Fpt;height:%.2Fpt;background:%s;">%s</div>',
		! empty( $options['page_break'] ) ? 'page-break-before:always;' : '',
		$page['w'],
		$page['h'],
		esc_attr( in_array( $bg, array( 'none', 'transparent' ), true ) ? 'transparent' : $bg ),
		$html
	);
}

/**
 * The labels with nothing under them: a text element "x-label" whose "x-value" says nothing (no speakers,
 * no time on a WordCamp that runs for days) is not drawn, so the column goes with its value.
 *
 * @param array<int, mixed>     $elements Design elements.
 * @param array<string, string> $values   values().
 * @return string[] Ids of the labels to leave out.
 */
function orphan_labels( array $elements, array $values ) {
	$by_id = array();
	foreach ( $elements as $el ) {
		if ( is_array( $el ) && isset( $el['id'] ) && 'text' === ( $el['type'] ?? '' ) ) {
			$by_id[ $el['id'] ] = $el;
		}
	}
	$orphans = array();
	foreach ( $by_id as $id => $el ) {
		$value = '-label' === substr( (string) $id, -6 ) ? ( $by_id[ substr( (string) $id, 0, -6 ) . '-value' ] ?? null ) : null;
		if ( $value && empty( $value['hidden'] ) && '' === trim( wp_strip_all_tags( runs_html( $value, $values ) ) ) ) {
			$orphans[] = $id;
		}
	}

	return $orphans;
}

/**
 * The tags and attributes artwork() and declaration() make: what wp_kses()
 * lets through when they are printed. Their CSS (position, transform,
 * opacity, object-fit…) is in WordPress's own safe list.
 *
 * @return array<string, array<string, bool>>
 */
function allowed_html() {
	$style = array( 'style' => true );

	return array(
		'div'    => array(
			'class' => true,
			'style' => true,
		),
		'img'    => array(
			'alt'   => true,
			'src'   => true,
			'style' => true,
		),
		'strong' => array(),
		'em'     => array(),
		'br'     => array(),
		'ul'     => $style,
		'li'     => $style,
		'span'   => $style,
		'p'      => $style,
		'h1'     => $style,
		'h2'     => $style,
		'table'  => $style,
		'tr'     => array(),
		'td'     => $style,
	);
}

/**
 * The URL protocols of artwork images: the site's, and data: (the drawings
 * and QR codes are SVG data URIs, shown in <img>, where no script runs).
 *
 * @return string[]
 */
function allowed_protocols() {
	return array_merge( wp_allowed_protocols(), array( 'data' ) );
}

/**
 * Artwork markup escaped for output (what the certificate pages print).
 *
 * @param string $html Markup.
 * @return string
 */
function kses_artwork( $html ) {
	return wp_kses( $html, allowed_html(), allowed_protocols() );
}

/**
 * The certificate as a PDF (always in the light mode).
 *
 * @param array<string, mixed> $cert    Certificate.
 * @param array<string, mixed> $design  Design.
 * @param array<string, mixed> $extra   document: the full number, only in the PDF downloaded when issuing.
 * @return string PDF bytes.
 */
function pdf( array $cert, array $design, array $extra = array() ) {
	return pdf_many( array( array( $cert, $design, $extra ) ) );
}

/**
 * A landscape A4 page, in points (when there is nothing to take it from).
 *
 * @return array<string, float>
 */
function base_page() {
	return array(
		'w' => 842.0,
		'h' => 595.0,
	);
}

/**
 * The language a certificate is shown in by default: the one it was issued in
 * (a certificate is a document, the same whoever opens it), the site's when that
 * one is no longer installed.
 *
 * @param array<string, mixed> $cert Certificate.
 * @return string
 */
function certificate_locale( array $cert ) {
	$stored = (string) ( $cert['locale'] ?? '' );

	return '' !== $stored && in_array( $stored, \EventsCertificate\Verify\locales(), true ) ? $stored : \EventsCertificate\Dates\site_locale();
}

/**
 * Runs something with WordPress in a language, then puts it back.
 *
 * @param string   $locale   Locale, e.g. pt_BR ('' or the current one: nothing is switched).
 * @param callable $callback What to run.
 * @return mixed What it returns.
 */
function with_locale( $locale, callable $callback ) {
	$switched = '' !== $locale && determine_locale() !== $locale && switch_to_locale( $locale );
	try {
		return $callback();
	} finally {
		if ( $switched ) {
			restore_previous_locale();
		}
	}
}

/**
 * The markup of the pages of a PDF: each certificate's artwork, and its
 * declaration page when chosen. Apart from pdf_many() so it can be read
 * (and tested) without making a PDF.
 *
 * @param array<int, array{0: array<string, mixed>, 1: array<string, mixed>, 2?: array<string, mixed>}> $items   Certificate, design and extra (as in pdf()) of each.
 * @param callable                                                                                      $measure Text width: ( text, family, weight, size ).
 * @return string
 */
function pdf_body( array $items, callable $measure ) {
	$body = '';
	foreach ( array_values( $items ) as $i => $item ) {
		list( $cert, $design ) = $item;
		$extra                 = (array) ( $item[2] ?? array() );
		// In the language it was issued in, unless the caller chose one (the page's language switch).
		$locale = (string) ( $extra['locale'] ?? '' );
		$locale = in_array( $locale, \EventsCertificate\Verify\locales(), true ) ? $locale : certificate_locale( $cert );
		$body  .= with_locale(
			$locale,
			static function () use ( $cert, $design, $extra, $measure, $i ) {
				return artwork(
					$design,
					values( $cert ),
					array(
						'mode'       => 'light',
						'for_pdf'    => true,
						'measure'    => $measure,
						'page_break' => $i > 0,
					) + first_page_options( $cert, $design )
				)
				// The declaration page, when chosen (decision 0014).
				. ( has_declaration( $cert ) ? declaration(
					$cert,
					$design,
					values( $cert ),
					array(
						'document' => (string) ( $extra['document'] ?? '' ),
						'for_pdf'  => true,
						'mode'     => 'light',
						'measure'  => $measure,
					)
				) : '' );
			}
		);
	}

	return $body;
}

/**
 * Several certificates in one PDF, one after the other (the page size is
 * the first one's).
 *
 * @param array<int, array{0: array<string, mixed>, 1: array<string, mixed>, 2?: array<string, mixed>}> $items Certificate, design and extra (as in pdf()) of each.
 * @return string PDF bytes.
 */
function pdf_many( array $items ) {
	$cache = wp_get_upload_dir()['basedir'] . '/evcert-fonts';
	wp_mkdir_p( $cache );

	$options = new \EventsCertificate\Vendor\Dompdf\Options();
	$options->setChroot( array( dirname( __DIR__ ), wp_get_upload_dir()['basedir'] ) );
	$options->setFontDir( $cache );
	$options->setFontCache( $cache );
	$options->setIsRemoteEnabled( false );
	$options->setDefaultFont( 'Inter' );
	$options->setDpi( 72 );
	$dompdf  = new \EventsCertificate\Vendor\Dompdf\Dompdf( $options );
	$metrics = $dompdf->getFontMetrics();
	foreach ( FONTS as $font ) {
		$metrics->registerFont(
			array(
				'family' => $font[0],
				'weight' => (string) $font[1],
				'style'  => 'normal',
			),
			dirname( __DIR__ ) . '/fonts/' . $font[2] . '.ttf'
		);
	}
	$measure = static function ( $text, $family, $weight, $size ) use ( $metrics ) {
		$font = $metrics->getFont( $family, (string) $weight );
		return $font ? $metrics->getTextWidth( $text, $font, $size ) : 0;
	};

	$body  = pdf_body( $items, $measure );
	$first = $items ? reset( $items ) : null;
	$page  = $first ? (array) $first[1]['page'] : base_page();
	$dompdf->loadHtml( '<!DOCTYPE html><html><head><meta charset="utf-8"></head><body>' . $body . '</body></html>', 'UTF-8' );
	// The page's CSS, a file of the plugin; the size comes from setPaper().
	$dompdf->getCss()->load_css_file( dirname( __DIR__ ) . '/assets/pdf.css' );
	$dompdf->setPaper( array( 0, 0, (float) $page['w'], (float) $page['h'] ) );
	$dompdf->render();

	return (string) $dompdf->output();
}
