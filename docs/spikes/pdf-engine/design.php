<?php
// Spike: the Campus Connect certificate as a list of elements (pt, page 842x595).
return array(
	'page'     => array( 'w' => 842, 'h' => 595, 'bg' => '#ffffff' ),
	'elements' => array(
		array( 'type' => 'image', 'src' => 'wordpress-watermark-flat.svg', 'x' => 0, 'y' => 0, 'w' => 842, 'h' => 842 ),
		array( 'type' => 'image', 'src' => 'details-border.svg', 'x' => 16 + ( 810 - 563 ) / 2, 'y' => 16 + ( 563 - 810 ) / 2, 'w' => 563, 'h' => 810, 'rotate' => 90 ),
		array( 'type' => 'rect', 'x' => 357, 'y' => -26, 'w' => 128, 'h' => 190, 'fill' => '#3858E9', 'radius' => array( 0, 0, 64, 64 ) ),
		array( 'type' => 'image', 'src' => 'wpcc-logo-isolated-white.svg', 'x' => 361, 'y' => 36, 'w' => 120, 'h' => 120 ),
		array( 'type' => 'image', 'src' => 'name-logo.svg', 'x' => 338.57, 'y' => 179, 'w' => 164.15, 'h' => 38.9 ),
		array( 'type' => 'text', 'text' => 'Certificate of participation', 'x' => 184.25, 'y' => 267.39, 'w' => 473.5, 'font' => 'Lora', 'weight' => 600, 'size' => 16.065, 'color' => '#656A71', 'upper' => true, 'lh' => 1 ),
		array( 'type' => 'text', 'text' => '{name}', 'x' => 184.25, 'y' => 295.39, 'w' => 473.5, 'font' => 'Lora', 'weight' => 500, 'size' => 41.761, 'min' => 22, 'color' => '#1A1919', 'lh' => 1, 'fit' => true ),
		array( 'type' => 'text', 'html' => 'This certificate is presented for actively participating in the WordPress WordCamp Brasil event, held in <b>Belo Horizonte, Brazil,</b> celebrating collaboration, learning, and innovation within the WordPress community.', 'x' => 184.25, 'y' => 353.39, 'w' => 473.5, 'font' => 'Inter', 'weight' => 400, 'size' => 13, 'color' => '#1A1919', 'lh' => 1.4, 'ls' => -0.26 ),
		array( 'type' => 'text', 'text' => 'October 30th, 2026', 'x' => 125, 'y' => 488, 'w' => 194, 'font' => 'Lora', 'weight' => 400, 'size' => 12, 'color' => '#1A1919', 'lh' => 1 ),
		array( 'type' => 'line', 'x' => 122, 'y' => 510.5, 'w' => 200, 'color' => '#1A1919', 'opacity' => 0.5 ),
		array( 'type' => 'text', 'text' => 'Date of emission', 'x' => 125, 'y' => 515, 'w' => 194, 'font' => 'Inter', 'weight' => 600, 'size' => 8, 'color' => '#656A71', 'upper' => true, 'lh' => 1.3, 'ls' => -0.16 ),
		array( 'type' => 'image', 'src' => 'signature-example.svg', 'x' => 563 + ( 114.46 - 55.17 ) / 2, 'y' => 461 + ( 55.17 - 114.46 ) / 2, 'w' => 55.17, 'h' => 114.46, 'rotate' => 90 ),
		array( 'type' => 'line', 'x' => 521.18, 'y' => 510.5, 'w' => 200, 'color' => '#1A1919', 'opacity' => 0.5 ),
		array( 'type' => 'text', 'text' => 'Christian van ’t Hof', 'x' => 524.18, 'y' => 515, 'w' => 194, 'font' => 'Inter', 'weight' => 600, 'size' => 8, 'color' => '#656A71', 'upper' => true, 'lh' => 1.3, 'ls' => -0.16 ),
		array( 'type' => 'text', 'text' => 'Event Coordinator', 'x' => 524.18, 'y' => 526, 'w' => 194, 'font' => 'Inter', 'weight' => 500, 'size' => 8, 'color' => '#7F7F7F', 'lh' => 1.5 ),
		array( 'type' => 'qr', 'x' => 397, 'y' => 470, 'w' => 48, 'data' => 'https://saopaulo.wordcamp.org/2026/verify/2611-04K7-M29Q-XAP3/' ),
		array( 'type' => 'text', 'text' => '2611-04K7-M29Q-XAP3', 'x' => 346, 'y' => 521, 'w' => 150, 'font' => 'Inter', 'weight' => 500, 'size' => 6.5, 'color' => '#656A71', 'lh' => 1.2 ),
	),
);
