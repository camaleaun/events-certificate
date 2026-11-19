<?php
/**
 * The certificate generator for meetups: a page, made of blocks, where an
 * organizer fills in the event, adds the names (typed, pasted or from a Meetup
 * attendee export) and downloads one PDF each (a ZIP) or a single PDF. It runs
 * entirely in the browser (jsPDF, JSZip and SheetJS, bundled in assets/vendor):
 * nothing is sent to a server and nothing is saved, so its certificates carry
 * no verification code. For verifiable ones, use Certificates > Events.
 *
 * Inspired by the generator Pamela Ribeiro (pamprn) made with Débora Venâncio
 * de Oliveira and Sandra Peres at WordCamp Brasil 2026's Contributor Day,
 * used with their permission. Credits in readme.txt. docs/decisions/0017.
 *
 * @package EventsCertificate
 */

namespace EventsCertificate\QuickGenerator;

defined( 'ABSPATH' ) || exit;

/**
 * Option with the generator page's ID.
 */
const PAGE_OPTION = 'evcert_quick_generator_page_id';

/**
 * The name the page template is registered with (a block template, plugin//slug) and the bare slug WordPress keeps
 * in the page's _wp_page_template meta (and a classic theme lists).
 */
const TEMPLATE_NAME = 'events-certificates//quick-generator';
const TEMPLATE_SLUG = 'quick-generator';

/**
 * The blocks (blocks/<name>/block.json), each with its render function.
 */
const BLOCKS = array(
	'event-fields',
	'event-info',
	'event-schedule',
	'event-attrs',
	'signature',
	'people-list',
	'preview',
	'tutorial',
	'quick-generator',
);

/**
 * Hooks.
 */
function bootstrap() {
	add_action( 'init', __NAMESPACE__ . '\register_assets', 9 );
	add_action( 'init', __NAMESPACE__ . '\register_blocks' );
	add_action( 'init', __NAMESPACE__ . '\register_pattern' );
	add_action( 'init', __NAMESPACE__ . '\register_shortcode' );
	add_action( 'admin_init', __NAMESPACE__ . '\ensure_page', 20 );
	add_filter( 'block_categories_all', __NAMESPACE__ . '\add_category' );
	add_filter( 'template_include', __NAMESPACE__ . '\template', 99 );
	add_action( 'init', __NAMESPACE__ . '\register_template' );
	add_filter( 'theme_page_templates', __NAMESPACE__ . '\page_templates' );
	add_filter( 'body_class', __NAMESPACE__ . '\body_class' );
	// Without theme.json WordPress puts an inner container in every group, and a classic theme sizes what is in it: not here.
	add_filter( 'the_content', __NAMESPACE__ . '\drop_inner_container', 8 );
	add_filter( 'the_content', __NAMESPACE__ . '\restore_inner_container', 10 );
}

/**
 * The plugin's URL for a file.
 *
 * @param string $file File, from the plugin's folder.
 * @return string
 */
function url( $file ) {
	return plugins_url( $file, dirname( __DIR__ ) . '/events-certificates.php' );
}

/**
 * Registers the styles and scripts the blocks and the page use; WordPress
 * prints them (only where a block is, or the page) as enqueued files.
 */
function register_assets() {
	$version = \EventsCertificate\VERSION;
	$style   = \EventsCertificate\Assets\src( 'quick-generator', 'css' );
	wp_register_style( 'evcert-quick-generator', $style['url'], array(), $style['version'] );
	// The fonts of the design's sample (the admin screen and the block editor): the same files the PDF uses.
	wp_register_style( 'evcert-generator-sheet', false, array(), $version );
	wp_add_inline_style( 'evcert-generator-sheet', \EventsCertificate\Render\fonts_css( false ) );
	// The design's own fonts (Lora, Inter…) for the preview's canvas: the same files the PDF uses.
	wp_add_inline_style( 'evcert-quick-generator', \EventsCertificate\Render\fonts_css( false ) );
	// The tutorial's show / hide label, drawn by the stylesheet in the site's language.
	wp_add_inline_style( 'evcert-quick-generator', '.evcert-app{--evcert-show-hide:' . wp_json_encode( __( 'show / hide', 'events-certificates' ), JSON_UNESCAPED_UNICODE ) . ';}' );
	wp_register_script( 'evcert-jspdf', url( 'assets/vendor/jspdf.umd.min.js' ), array(), '2.5.1', true );
	wp_register_script( 'evcert-jszip', url( 'assets/vendor/jszip.min.js' ), array(), '3.10.1', true );
	wp_register_script( 'evcert-xlsx', url( 'assets/vendor/xlsx.full.min.js' ), array(), '0.18.5', true );
	$script = \EventsCertificate\Assets\src( 'quick-generator', 'js' );
	wp_register_script( 'evcert-quick-generator', $script['url'], array( 'evcert-jspdf', 'evcert-jszip', 'evcert-xlsx', 'evcert-design' ), $script['version'], true );

	// The editor's scripts are built (src/blocks, src/generator): their dependencies are in their .asset.php.
	register_built( 'evcert-blocks-editor', 'blocks' );
	register_built( 'evcert-generator', 'screen' );
	register_built( 'evcert-design', 'design' );
}

/**
 * The faces of the fonts the plugin ships, by "Family:weight".
 *
 * @return array<string, string> Face => address of its file.
 */
function fonts() {
	$faces = array();
	foreach ( \EventsCertificate\Render\FONTS as $font ) {
		$faces[ $font[0] . ':' . $font[1] ] = url( 'fonts/' . $font[2] . '.ttf' );
	}

	return $faces;
}

/**
 * What the browser app needs: the language, the fonts and every text (the
 * app's strings come from here, translated, so it needs no JSON files).
 *
 * @return array<string, mixed>
 */
function config() {
	return array(
		'locale'     => str_replace( '_', '-', determine_locale() ),
		// The default design the certificates are drawn from (the same one the server draws), its standard texts,
		// and the site's date and time formats (Settings → General).
		'design'     => \EventsCertificate\Designs\get( \EventsCertificate\Designs\site_design_id() ),
		'phrases'    => \EventsCertificate\Render\phrases(),
		'dateFormat' => (string) get_option( 'date_format' ),
		'timeFormat' => (string) get_option( 'time_format' ),
		// Every face the site ships, "Family:weight" => file: the design's fonts (Lora, Inter…) as well as the generator's own.
		'fonts'      => fonts(),
		'i18n'       => array(
			'participantName'  => _x( 'Participant name', 'name shown on the certificate preview', 'events-certificates' ),
			'meetupTitle'      => __( 'Event title', 'events-certificates' ),
			'speaker'          => __( 'SPEAKER', 'events-certificates' ),
			'speakers'         => __( 'SPEAKERS', 'events-certificates' ),
			/* translators: 1: start time, 2: end time. */
			'fromTo'           => __( 'from %1$s to %2$s', 'events-certificates' ),
			/* translators: %s: start time. */
			'startingAt'       => __( 'starting at %s', 'events-certificates' ),
			/* translators: %s: a date. */
			'issuedOn'         => __( 'Issued on %s', 'events-certificates' ),
			/* translators: 1: the event's hours (from 7pm to 10pm), 2: how long it lasts (3 hours). */
			'withSpan'         => __( '%1$s (%2$s)', 'events-certificates' ),
			'oneHour'          => __( '1 hour', 'events-certificates' ),
			/* translators: %d: number of hours. */
			'nHours'           => __( '%d hours', 'events-certificates' ),
			/* translators: 1: hours, 2: minutes (two digits), as in 1h30. */
			'hoursMinutes'     => __( '%1$dh%2$02d', 'events-certificates' ),
			/* translators: %d: number of minutes. */
			'nMinutes'         => __( '%d minutes', 'events-certificates' ),
			'and'              => __( 'and', 'events-certificates' ),
			'noLogo'           => __( 'no logo', 'events-certificates' ),
			'noSignature'      => __( 'no signature', 'events-certificates' ),
			'currentLogo'      => __( 'Current logo', 'events-certificates' ),
			'currentSignature' => __( 'Current signature', 'events-certificates' ),
			'imageError'       => __( 'Could not read that image.', 'events-certificates' ),
			/* translators: %s: person's name. */
			'include'          => __( 'Include %s', 'events-certificates' ),
			'nameLabel'        => __( 'Participant name', 'events-certificates' ),
			/* translators: %s: person's name. */
			'downloadFor'      => __( 'Download the certificate of %s', 'events-certificates' ),
			'didNotAttend'     => __( 'did not attend', 'events-certificates' ),
			'noPeople'         => __( 'No participants yet. Import the Meetup CSV or add names by hand.', 'events-certificates' ),
			/* translators: 1: people selected, 2: people in the list. */
			'selectedOf'       => __( '%1$d of %2$d selected', 'events-certificates' ),
			'selectAll'        => __( 'Select all', 'events-certificates' ),
			'deselectAll'      => __( 'Deselect all', 'events-certificates' ),
			/* translators: %d: number of people. */
			'imported'         => __( '%d people imported', 'events-certificates' ),
			/* translators: %d: number of people. */
			'rsvpNo'           => __( '%d with RSVP "No" left out', 'events-certificates' ),
			/* translators: %d: number of people. */
			'absent'           => __( '%d marked as absent were left unchecked', 'events-certificates' ),
			'noHeader'         => __( 'No header found; the first column was used.', 'events-certificates' ),
			'noNames'          => __( 'No names found in that file. Check that it is the Meetup attendee list.', 'events-certificates' ),
			'readError'        => __( 'Could not read that file. Export it from Meetup again, or save it as .csv.', 'events-certificates' ),
			/* translators: %d: number of names. */
			'added'            => __( '%d name(s) added.', 'events-certificates' ),
			/* translators: %s: what is missing (the title, the date). */
			'fill'             => __( 'Fill in %s of the meetup before generating.', 'events-certificates' ),
			'theTitle'         => __( 'the title', 'events-certificates' ),
			'theDate'          => __( 'the date', 'events-certificates' ),
			'pickOne'          => __( 'Select at least one person.', 'events-certificates' ),
			'generating'       => __( 'Generating PDF…', 'events-certificates' ),
			/* translators: 1: certificates made, 2: certificates to make. */
			'progress'         => __( 'Generating %1$d of %2$d…', 'events-certificates' ),
			/* translators: %s: person's name. */
			'pdfReady'         => __( 'Certificate of %s ready.', 'events-certificates' ),
			/* translators: %d: number of pages. */
			'pdfAllReady'      => __( 'PDF with %d pages ready.', 'events-certificates' ),
			/* translators: %d: number of certificates. */
			'zipReady'         => __( '%d certificates ready in the ZIP.', 'events-certificates' ),
			'failed'           => __( 'Could not make the file. Try again.', 'events-certificates' ),
			'fileOne'          => __( 'certificate', 'events-certificates' ),
			'fileMany'         => __( 'certificates', 'events-certificates' ),
			'fileMeetup'       => __( 'meetup', 'events-certificates' ),
			'fileParticipant'  => __( 'participant', 'events-certificates' ),
		),
	);
}

/**
 * Loads the app where the page or a block shows it. The configuration (the default design, the texts) is
 * made here, not at registration: only a request that prints the generator pays for it.
 */
function enqueue_app() {
	static $configured = false;
	wp_enqueue_style( 'evcert-quick-generator' );
	wp_enqueue_script( 'evcert-quick-generator' );
	if ( ! $configured ) {
		$configured = true;
		wp_add_inline_script( 'evcert-quick-generator', 'window.evcertQuickGenerator = ' . wp_json_encode( config() ) . ';', 'before' );
	}
}

/**
 * Registers a built script (build-blocks/<name>.min.js) with the dependencies
 * and version its <name>.min.asset.php lists, and its translations.
 *
 * @param string $handle Script handle.
 * @param string $name   File name, without .min.js.
 */
function register_built( $handle, $name ) {
	$dir = dirname( __DIR__ ) . '/build-blocks/';
	if ( ! is_readable( $dir . $name . '.min.asset.php' ) ) {
		return;
	}
	$asset = require $dir . $name . '.min.asset.php';
	wp_register_script( $handle, url( 'build-blocks/' . $name . '.min.js' ), $asset['dependencies'], $asset['version'], true );
	wp_set_script_translations( $handle, 'events-certificates' );
}

/**
 * A category of its own in the inserter.
 *
 * @param array<int, array<string, string>> $categories Categories.
 * @return array<int, array<string, string>>
 */
function add_category( $categories ) {
	$categories[] = array(
		'slug'  => 'events-certificates',
		'title' => __( 'Events Certificates', 'events-certificates' ),
	);

	return $categories;
}

/**
 * Registers the blocks: their settings are in blocks/<name>/block.json and
 * they are drawn here, from their attributes.
 */
function register_blocks() {
	foreach ( BLOCKS as $slug ) {
		register_block_type(
			dirname( __DIR__ ) . '/blocks/' . $slug,
			array( 'render_callback' => __NAMESPACE__ . '\render_' . str_replace( '-', '_', $slug ) )
		);
	}
}

/**
 * The shortcode for the whole generator, for pages not made with blocks.
 */
function register_shortcode() {
	add_shortcode( 'evcert_quick_generator', __NAMESPACE__ . '\render_shortcode' );
}

/**
 * Shortcode: [evcert_quick_generator community="…" title="…"].
 *
 * @param array<string, string>|string $atts Attributes (those of the quick-generator block).
 * @return string
 */
function render_shortcode( $atts = array() ) {
	// On the generator page (no theme header) the heading is the page's title; elsewhere the theme prints it.
	$own = page_id() && is_page( page_id() ) && get_queried_object_id() === get_the_ID();

	return render_composition( shortcode_atts( attribute_defaults(), (array) $atts, 'evcert_quick_generator' ), $own );
}
/**
 * Defaults of the blocks' attributes.
 *
 * @return array<string, mixed>
 */
function attribute_defaults() {
	return array(
		'community' => '',
		'title'     => '',
		'venue'     => '',
		'city'      => '',
		'speakers'  => '',
		'link'      => '',
		'logoId'    => 0,
		'logoUrl'   => '',
		'signName'  => '',
		'signRole'  => _x( 'Organizer', 'default role of who signs, in the generator', 'events-certificates' ),
		'showHours' => true,
		'accent'    => '#3858e9',
	);
}

/**
 * A block's attributes with the defaults filled in.
 *
 * @param mixed $attributes Attributes.
 * @return array<string, mixed>
 */
function attributes( $attributes ) {
	$attributes           = array_merge(
		attribute_defaults(),
		array_filter(
			(array) $attributes,
			static function ( $value ) {
				return null !== $value && '' !== $value;
			}
		)
	);
	$attributes['accent'] = sanitize_hex_color( (string) $attributes['accent'] ) ? (string) $attributes['accent'] : '#3858e9';

	return $attributes;
}

/**
 * Whether the block is being drawn for the editor (a REST request), where
 * the form must not be used.
 *
 * @return bool
 */
function in_editor() {
	return defined( 'REST_REQUEST' ) && REST_REQUEST;
}

/**
 * The app's root: the element the stylesheet is scoped to. In the editor it
 * is inert (not focusable or clickable), a picture of what the page shows.
 *
 * @param string               $html       Markup.
 * @param array<string, mixed> $attributes Block attributes (the accent).
 * @return string
 */
function app( $html, $attributes = array() ) {
	$accent = attributes( $attributes )['accent'];

	return sprintf(
		'<div class="evcert-app"%1$s%2$s>%3$s</div>',
		'#3858e9' !== strtolower( $accent ) ? ' style="--accent:' . esc_attr( $accent ) . '"' : '',
		in_editor() ? ' inert' : '',
		$html
	);
}

/**
 * Text with bold parts (<b>), escaped.
 *
 * @param string $text Translated text.
 * @return string
 */
function bold( $text ) {
	return wp_kses( $text, array( 'b' => array() ) );
}

/**
 * A labelled field, as the generator's form has them.
 *
 * @param string               $id    Field ID.
 * @param string               $label Label.
 * @param array<string, mixed> $args  type, value, placeholder, hint, rows (a textarea), accept, style.
 * @return string
 */
function field( $id, $label, array $args = array() ) {
	$args  = $args + array(
		'type'        => 'text',
		'value'       => '',
		'placeholder' => '',
		'hint'        => '',
		'rows'        => 0,
		'textarea'    => false,
		'accept'      => '',
		'style'       => '',
	);
	$title = esc_html( $label ) . ( '' !== $args['hint'] ? ' <small>' . esc_html( $args['hint'] ) . '</small>' : '' );
	if ( $args['rows'] || $args['textarea'] ) {
		$control = sprintf(
			'<textarea id="evcert-%1$s"%2$s%3$s placeholder="%4$s">%5$s</textarea>',
			esc_attr( $id ),
			$args['rows'] ? ' rows="' . (int) $args['rows'] . '"' : '',
			'' !== $args['style'] ? ' style="' . esc_attr( $args['style'] ) . '"' : '',
			esc_attr( $args['placeholder'] ),
			esc_textarea( (string) $args['value'] )
		);
	} else {
		$control = sprintf(
			'<input type="%1$s" id="evcert-%2$s"%3$s%4$s%5$s>',
			esc_attr( $args['type'] ),
			esc_attr( $id ),
			'' !== (string) $args['value'] ? ' value="' . esc_attr( (string) $args['value'] ) . '"' : '',
			'' !== $args['placeholder'] ? ' placeholder="' . esc_attr( $args['placeholder'] ) . '"' : '',
			'' !== $args['accept'] ? ' accept="' . esc_attr( $args['accept'] ) . '"' : ''
		);
	}

	return '<label class="f"><span>' . $title . '</span>' . $control . '</label>';
}

/**
 * A panel (a section of the form).
 *
 * @param string $id    Short ID.
 * @param string $title Title.
 * @param string $html  Content.
 * @return string
 */
function panel( $id, $title, $html ) {
	return sprintf( '<section class="panel" aria-labelledby="evcert-h-%1$s"><h2 id="evcert-h-%1$s">%2$s</h2>%3$s</section>', esc_attr( $id ), esc_html( $title ), $html );
}

/**
 * The event panel's first part: community, logo and title.
 *
 * @param array<string, mixed> $a Attributes.
 * @return string
 */
function event_info_html( array $a ) {
	$logo = (string) $a['logoUrl'];
	if ( $a['logoId'] && wp_get_attachment_url( (int) $a['logoId'] ) ) {
		$logo = (string) wp_get_attachment_url( (int) $a['logoId'] );
	}

	return field(
		'community',
		__( 'Community', 'events-certificates' ),
		array(
			'value'       => $a['community'],
			'placeholder' => __( 'E.g.: WordPress Curitiba Community', 'events-certificates' ),
		)
	)
		. sprintf(
			'<div class="logo-field" id="evcert-logo-field" data-src="%1$s"><div class="logo-thumb" id="evcert-logo-thumb"><span>%2$s</span></div><div class="field-stack">%3$s<div><button class="small" type="button" id="evcert-logo-remove" hidden>%4$s</button></div></div></div>',
			esc_url( $logo ),
			esc_html__( 'no logo', 'events-certificates' ),
			field(
				'logo-file',
				__( 'Community logo', 'events-certificates' ),
				array(
					'type'   => 'file',
					'accept' => 'image/png,image/jpeg,image/svg+xml,image/webp',
					'hint'   => __( 'optional · PNG, JPG or SVG; goes in the top right corner', 'events-certificates' ),
				)
			),
			esc_html__( 'Remove logo', 'events-certificates' )
		)
		. field(
			'title',
			__( 'Event title', 'events-certificates' ),
			array(
				'rows'        => 2,
				'style'       => 'min-height:60px',
				'value'       => $a['title'],
				'placeholder' => __( 'E.g.: WPCuritiba 88: Building sites with AI Agents', 'events-certificates' ),
			)
		);
}

/**
 * The event panel's second part: date, times, venue and city.
 *
 * @param array<string, mixed> $a Attributes.
 * @return string
 */
function event_schedule_html( array $a ) {
	return '<div class="row">'
		. field( 'date', __( 'Date', 'events-certificates' ), array( 'type' => 'date' ) )
		. field( 'start', __( 'Start', 'events-certificates' ), array( 'type' => 'time' ) )
		. field( 'end', __( 'End', 'events-certificates' ), array( 'type' => 'time' ) )
		. '</div><div class="row">'
		. field(
			'venue',
			__( 'Venue', 'events-certificates' ),
			array(
				'value'       => $a['venue'],
				'placeholder' => __( 'E.g.: HOTMILK', 'events-certificates' ),
			)
		)
		. field(
			'city',
			__( 'City', 'events-certificates' ),
			array(
				'value'       => $a['city'],
				'placeholder' => __( 'E.g.: Curitiba, PR', 'events-certificates' ),
			)
		)
		. '</div>';
}

/**
 * The event panel's third part: speakers, link and the workload.
 *
 * @param array<string, mixed> $a Attributes.
 * @return string
 */
function event_attrs_html( array $a ) {
	return field(
		'speakers',
		__( 'Speaker(s)', 'events-certificates' ),
		array(
			'value'       => $a['speakers'],
			'hint'        => __( 'optional', 'events-certificates' ),
			'placeholder' => __( 'E.g.: Fellyph Cintra', 'events-certificates' ),
		)
	)
		. field(
			'link',
			__( 'Event link', 'events-certificates' ),
			array(
				'type'        => 'url',
				'value'       => $a['link'],
				'hint'        => __( 'optional, goes in the footer', 'events-certificates' ),
				'placeholder' => __( 'https://www.meetup.com/your-group/events/…', 'events-certificates' ),
			)
		)
		. '<label class="check"><input type="checkbox" id="evcert-show-hours"' . checked( ! empty( $a['showHours'] ), true, false ) . '> ' . esc_html__( 'Show the workload on the certificate', 'events-certificates' ) . '</label>';
}

/**
 * The signature panel.
 *
 * @param array<string, mixed> $a Attributes.
 * @return string
 */
function signature_html( array $a ) {
	return panel(
		'signature',
		__( '2 · Signature', 'events-certificates' ),
		'<div class="row">'
		. field(
			'sign-name',
			__( 'Name of who signs', 'events-certificates' ),
			array(
				'value'       => $a['signName'],
				'placeholder' => __( 'Who signs the certificate', 'events-certificates' ),
			)
		)
		. field(
			'sign-role',
			_x( 'Role', 'role of who signs, in the generator', 'events-certificates' ),
			array(
				'value'       => $a['signRole'],
				'placeholder' => __( 'E.g.: Organizer', 'events-certificates' ),
			)
		)
		. '</div>'
		. sprintf(
			'<div class="sig-preview"><div class="logo-thumb" id="evcert-sign-thumb"><span>%1$s</span></div><div class="field-stack">%2$s<div><button class="small" type="button" id="evcert-sign-remove" hidden>%3$s</button></div></div></div>',
			esc_html__( 'no signature', 'events-certificates' ),
			field(
				'sign-file',
				__( 'Signature image', 'events-certificates' ),
				array(
					'type'   => 'file',
					'accept' => 'image/*',
					'hint'   => __( 'optional · a PNG with a transparent background works best', 'events-certificates' ),
				)
			),
			esc_html__( 'Remove signature', 'events-certificates' )
		)
	);
}

/**
 * The participants panel.
 *
 * @return string
 */
function people_html() {
	return panel(
		'people',
		__( '3 · Participants', 'events-certificates' ),
		sprintf(
			'<div class="drop" id="evcert-drop"><p>%1$s<br>%2$s</p><input type="file" id="evcert-list-file" accept=".csv,.tsv,.xls,.xlsx,.txt"></div>',
			esc_html__( 'Drop the list exported from Meetup here', 'events-certificates' ),
			esc_html__( '(.csv, .xls or .xlsx)', 'events-certificates' )
		)
		. '<p class="tut-link">' . esc_html__( 'Not sure where to download the list?', 'events-certificates' ) . ' <a href="#evcert-tutorial">' . esc_html__( 'See the step by step on Meetup', 'events-certificates' ) . '</a>.</p>'
		. '<div class="status" id="evcert-import-status" role="status"></div>'
		. field(
			'manual',
			__( 'Or add names by hand, one per line', 'events-certificates' ),
			array(
				'textarea'    => true,
				'placeholder' => __( "Maria da Silva\nJohn Smith", 'events-certificates' ),
			)
		)
		. sprintf(
			'<div class="toolbar"><button class="small" id="evcert-add-manual" type="button">%1$s</button><div class="toolbar toolbar--tight"><span class="count" id="evcert-count"></span><button class="small" id="evcert-toggle-all" type="button">%2$s</button></div></div>',
			esc_html__( 'Add names', 'events-certificates' ),
			esc_html__( 'Select all', 'events-certificates' )
		)
		. '<div class="people"><table><tbody id="evcert-people-body"></tbody></table></div>'
	);
}

/**
 * The preview of the certificate and the download buttons.
 *
 * @return string
 */
function preview_html() {
	if ( in_editor() ) {
		$sheet = wp_kses( sheet(), svg_allowed(), \EventsCertificate\Render\allowed_protocols() );
	} else {
		$sheet = sprintf( '<canvas id="evcert-canvas" aria-label="%s"></canvas>', esc_attr__( 'Certificate preview', 'events-certificates' ) );
		enqueue_app();
	}

	// The enlarged copy of a tutorial picture opens in this layer (assets/generator.js).
	$layer = in_editor() ? '' : '<div class="lightbox" id="evcert-lightbox" hidden></div>';

	return $layer . sprintf(
		'<aside class="preview-col" aria-label="%1$s"><div class="sheet">%2$s</div><div class="actions"><button class="primary" id="evcert-btn-zip" type="button">%3$s</button><button id="evcert-btn-all" type="button">%4$s</button><button id="evcert-btn-one" type="button">%5$s</button></div><div class="status" id="evcert-gen-status" role="status"></div></aside>',
		esc_attr__( 'Preview', 'events-certificates' ),
		$sheet,
		esc_html__( 'Download ZIP (one PDF each)', 'events-certificates' ),
		esc_html__( 'Download single PDF', 'events-certificates' ),
		esc_html__( 'Download just this one', 'events-certificates' )
	);
}

/**
 * What the tutorial says: the page's panel and the admin's modal show it.
 *
 * @return array{title: string, intro: string, hint: string, steps: array<int, array<string, mixed>>}
 */
function tutorial_data() {
	$steps = array(
		array( __( 'Click on the event', 'events-certificates' ), __( 'On the group page, under <b>Past events</b>, open the meetup that will get the certificates.', 'events-certificates' ) ),
		array( __( 'Click on Attendees', 'events-certificates' ), __( 'On the event page, the <b>Attendees</b> button sits next to Duplicate, Contact and Feedback.', 'events-certificates' ) ),
		array( __( 'Click on Manage', 'events-certificates' ), __( 'On the <b>Attendees</b> screen, use the <b>Manage</b> button (the gear icon) in the top right corner.', 'events-certificates' ) ),
		array( __( 'Click on Attendee details', 'events-certificates' ), __( 'In the menu, choose <b>Attendee details</b>. Meetup downloads a <b>.csv</b> file with the list.', 'events-certificates' ) ),
	);
	$list  = array();
	foreach ( $steps as $i => $step ) {
		$list[] = array(
			'title'   => $step[0],
			'text'    => bold( $step[1] ),
			'picture' => step_picture( $i + 1 ),
		);
	}

	return array(
		'title' => __( 'Before you start: download the attendee list from Meetup', 'events-certificates' ),
		'intro' => bold( __( 'Log in to Meetup with the group\'s organizer account (only organizers can export). It takes four clicks and Meetup downloads a .csv file. Then just drop that file into <b>3 · Participants</b>, right below.', 'events-certificates' ) ),
		'hint'  => __( 'Click an image to enlarge it.', 'events-certificates' ),
		'steps' => $list,
	);
}

/**
 * The step by step to get the list from Meetup, with a drawing of each step.
 *
 * @return string
 */
function tutorial_html() {
	$data  = tutorial_data();
	$items = '';
	foreach ( $data['steps'] as $i => $step ) {
		$items .= sprintf( '<li class="tut-step"><h3>%1$s</h3><p>%2$s</p>%3$s</li>', esc_html( $step['title'] ), $step['text'], step_image( $i + 1 ) );
	}

	return sprintf(
		'<details class="tutorial panel" id="evcert-tutorial" open><summary><span class="tut-title">%1$s</span><span class="tut-toggle">%2$s</span></summary><p class="tut-intro">%3$s</p><ol class="tut">%4$s</ol><p class="tut-link">%5$s</p></details>',
		esc_html( $data['title'] ),
		esc_html__( 'show / hide', 'events-certificates' ),
		$data['intro'],
		$items,
		esc_html( $data['hint'] )
	);
}

/**
 * Block: the event panel's frame; its fields are its inner blocks.
 *
 * @param array<string, mixed> $attributes Attributes.
 * @param string               $content    Inner blocks.
 * @return string
 */
function render_event_fields( $attributes = array(), $content = '' ) {
	return app( panel( 'event', __( '1 · Event', 'events-certificates' ), $content ), $attributes );
}

/**
 * Block: community, logo and title.
 *
 * @param array<string, mixed> $attributes Attributes.
 * @return string
 */
function render_event_info( $attributes = array() ) {
	return app( event_info_html( attributes( $attributes ) ), $attributes );
}

/**
 * Block: date, times, venue and city.
 *
 * @param array<string, mixed> $attributes Attributes.
 * @return string
 */
function render_event_schedule( $attributes = array() ) {
	return app( event_schedule_html( attributes( $attributes ) ), $attributes );
}

/**
 * Block: speakers, event link and the workload.
 *
 * @param array<string, mixed> $attributes Attributes.
 * @return string
 */
function render_event_attrs( $attributes = array() ) {
	return app( event_attrs_html( attributes( $attributes ) ), $attributes );
}

/**
 * Block: who signs, and the signature image.
 *
 * @param array<string, mixed> $attributes Attributes.
 * @return string
 */
function render_signature( $attributes = array() ) {
	return app( signature_html( attributes( $attributes ) ), $attributes );
}

/**
 * Block: the participants.
 *
 * @return string
 */
function render_people_list() {
	return app( people_html() );
}

/**
 * Block: the preview of the certificate and the download buttons.
 *
 * @param array<string, mixed> $attributes Attributes.
 * @return string
 */
function render_preview( $attributes = array() ) {
	return app( preview_html(), $attributes );
}

/**
 * Block: the step by step to get the list from Meetup.
 *
 * @return string
 */
function render_tutorial() {
	return app( tutorial_html() );
}

/**
 * Block: the whole generator, as the page shows it: heading, step by step,
 * form, participants and preview.
 *
 * @param array<string, mixed> $attributes Attributes.
 * @return string
 */
function render_quick_generator( $attributes = array() ) {
	return render_composition( (array) $attributes, false );
}
/**
 * The picture of one step (src, width, height, alt). The pictures of the site's language are in a folder
 * named after it (assets/images/tutorial/pt-br/), the others in the folder
 * above (English).
 *
 * @param int $step 1 to 4.
 * @return array<string, mixed>
 */
function step_picture( $step ) {
	// The files of the site's language have the names of the first generator's pictures; the others, those names in English.
	$names  = array(
		''      => array(
			1 => 'step-1-event',
			2 => 'step-2-attendees',
			3 => 'step-3-manage',
			4 => 'step-4-attendee-details',
		),
		'pt-br' => array(
			1 => 'passo-1-evento',
			2 => 'passo-2-participantes',
			3 => 'passo-3-manage',
			4 => 'passo-4-attendee-details',
		),
	);
	$folder = 'assets/images/tutorial/';
	$locale = strtolower( str_replace( '_', '-', determine_locale() ) );
	if ( isset( $names[ $locale ] ) && is_readable( dirname( __DIR__ ) . '/' . $folder . $locale . '/' . $names[ $locale ][ $step ] . '.jpg' ) ) {
		$folder .= $locale . '/';
		$file    = $names[ $locale ][ $step ] . '.jpg';
	} else {
		$file = $names[''][ $step ] . '.jpg';
	}
	$size = wp_getimagesize( dirname( __DIR__ ) . '/' . $folder . $file );

	return array(
		'src'    => url( $folder . $file ),
		'width'  => $size ? (int) $size[0] : 1000,
		'height' => $size ? (int) $size[1] : 600,
		/* translators: %d: step number. */
		'alt'    => sprintf( __( 'Screenshot of step %d', 'events-certificates' ), $step ),
	);
}

/**
 * The picture of one step, as an image.
 *
 * @param int $step 1 to 4.
 * @return string
 */
function step_image( $step ) {
	$picture = step_picture( $step );

	return sprintf(
		'<img src="%1$s" width="%2$d" height="%3$d" alt="%4$s" loading="lazy">',
		esc_url( $picture['src'] ),
		$picture['width'],
		$picture['height'],
		esc_attr( $picture['alt'] )
	);
}

/**
 * A sample of the certificate for the editor and the admin screen (where the
 * browser app doesn't run): the site's design drawn with example values, so it
 * is the Base design (or the site's own) that shows, band and all. The page is
 * inside an SVG, so it scales to its box.
 *
 * @param string $accent Accent color of the block.
 * @return string
 */
function sheet( $accent = '#3858e9' ) {
	$design = (array) \EventsCertificate\Designs\get( \EventsCertificate\Designs\site_design_id() );
	$accent = sanitize_hex_color( $accent ) ? $accent : '#3858e9';
	if ( '#3858e9' !== $accent && isset( $design['colors']['primary'] ) ) {
		$design['colors']['primary']['light'] = $accent;
		if ( isset( $design['colors']['band'] ) ) {
			$design['colors']['band']['light'] = darken( $accent, 0.5 );
		}
	}
	$values                   = \EventsCertificate\Preview\values( $design );
	$values['name']           = _x( 'Participant name', 'name shown on the certificate preview', 'events-certificates' );
	$values['event']          = __( 'Event title', 'events-certificates' );
	$values['community']      = '';
	$values['location']       = '';
	$values['speakers']       = '';
	$values['event_time']     = '';
	$values['event_link']     = '';
	$values['event_ts']       = (string) time();
	$values['speakers_label'] = '';
	$page                     = (array) $design['page'];
	$px                       = 96 / 72;

	return sprintf(
		'<svg class="evcert-sheet__svg" viewBox="0 0 %1$.2F %2$.2F" role="img" aria-label="%3$s"><foreignobject x="0" y="0" width="%1$.2F" height="%2$.2F">%4$s</foreignobject></svg>',
		$page['w'] * $px,
		$page['h'] * $px,
		esc_attr__( 'Certificate preview', 'events-certificates' ),
		\EventsCertificate\Render\artwork( $design, $values, array( 'mode' => 'light' ) )
	);
}

/**
 * Darkens a #rrggbb color (the side band).
 *
 * @param string $hex  Color.
 * @param float  $keep How much of each channel stays (0 to 1).
 * @return string
 */
function darken( $hex, $keep ) {
	$n = (int) hexdec( substr( str_pad( ltrim( $hex, '#' ), 6, '0' ), 0, 6 ) );

	return sprintf( '#%02x%02x%02x', round( ( ( $n >> 16 ) & 255 ) * $keep ), round( ( ( $n >> 8 ) & 255 ) * $keep ), round( ( $n & 255 ) * $keep ) );
}

/**
 * The tags and attributes sheet() makes (the design's HTML inside an SVG), for wp_kses().
 *
 * @return array<string, array<string, bool>>
 */
function svg_allowed() {
	return \EventsCertificate\Render\allowed_html() + array(
		'svg'           => array(
			'class'      => true,
			'viewbox'    => true,
			'role'       => true,
			'aria-label' => true,
		),
		'foreignobject' => array(
			'x'      => true,
			'y'      => true,
			'width'  => true,
			'height' => true,
		),
	);
}

/**
 * Pattern: the generator page, in blocks.
 */
function register_pattern() {
	register_block_pattern_category( 'events-certificates', array( 'label' => __( 'Events Certificates', 'events-certificates' ) ) );
	register_block_pattern(
		'events-certificates/generator',
		array(
			'title'       => __( 'Certificate generator', 'events-certificates' ),
			'description' => __( 'A page where organizers fill in a meetup, add who attended and download their certificates.', 'events-certificates' ),
			'categories'  => array( 'events-certificates' ),
			'content'     => page_content(),
		)
	);
}

/**
 * Bold marks of the app's texts as the editor writes them: <strong>.
 *
 * @param string $html Text with <b>.
 * @return string
 */
function strong_tags( $html ) {
	return str_replace( array( '<b>', '</b>' ), array( '<strong>', '</strong>' ), $html );
}

/**
 * A block of the app as a comment, with the settings that differ from its defaults.
 *
 * @param string               $name       Block name, without the namespace.
 * @param array<string, mixed> $attributes All settings (attributes()).
 * @param string[]             $keys       The ones this block has.
 * @return string
 */
function block_tag( $name, array $attributes, array $keys ) {
	$defaults = attribute_defaults();
	$set      = array();
	foreach ( $keys as $key ) {
		if ( isset( $attributes[ $key ] ) && $attributes[ $key ] !== $defaults[ $key ] ) {
			$set[ $key ] = $attributes[ $key ];
		}
	}

	return '<!-- wp:events-certificates/' . $name . ( $set ? ' ' . serialize_block_attributes( $set ) : '' ) . ' /-->';
}

/**
 * The whole app, drawn by its blocks: the same markup as the page's content, rendered. The shortcode
 * and the one-block version use it, so a classic theme or a page builder shows what the block editor does.
 *
 * @param array<string, mixed> $attributes The blocks' settings.
 * @param bool                 $with_title Whether the heading is the page's title.
 * @return string
 */
function render_composition( array $attributes, $with_title ) {
	stop_inner_container();
	$html = do_blocks( page_content( $attributes, $with_title ) );
	restore_inner_container( '' );
	$accent = attributes( $attributes )['accent'];
	if ( '#3858e9' !== strtolower( $accent ) ) {
		// The app's own wrapper carries the accent color of the whole page.
		$tags = new \WP_HTML_Tag_Processor( $html );
		if ( $tags->next_tag( array( 'class_name' => 'evcert-app' ) ) ) {
			$tags->set_attribute( 'style', '--accent:' . $accent );
			$html = $tags->get_updated_html();
		}
	}

	return $html;
}

/**
 * The generator page's content: the page shell is core blocks (hero, tutorial,
 * layout) and only the app's own sections are this plugin's smaller blocks, so
 * the texts and pictures of the page can be edited in the block editor. The
 * texts come in the site's language when the page is made.
 *
 * @param array<string, mixed> $attributes The blocks' settings (the shortcode's attributes), as the blocks' own comments.
 * @param bool                 $with_title Whether the heading of the hero is the page's title (a Post Title block). Off where
 *                                         the theme prints the title of the page the content is in.
 * @return string
 */
function page_content( array $attributes = array(), $with_title = true ) {
	$attributes = attributes( $attributes );
	$title      = $with_title ? '<!-- wp:post-title {"level":1} /-->

' : '';
	$data       = tutorial_data();
	$steps      = '';
	foreach ( $data['steps'] as $step ) {
		$picture = $step['picture'];
		$steps  .= '<!-- wp:group {"className":"tut-step","layout":{"type":"default"}} -->
<div class="wp-block-group tut-step"><!-- wp:heading {"level":3} -->
<h3 class="wp-block-heading">' . esc_html( $step['title'] ) . '</h3>
<!-- /wp:heading -->

<!-- wp:paragraph -->
<p>' . strong_tags( $step['text'] ) . '</p>
<!-- /wp:paragraph -->

<!-- wp:image {"sizeSlug":"full","linkDestination":"none","className":"tut-img"} -->
<figure class="wp-block-image size-full tut-img"><img src="' . esc_url( $picture['src'] ) . '" alt="' . esc_attr( $picture['alt'] ) . '"/></figure>
<!-- /wp:image --></div>
<!-- /wp:group -->

';
	}

	return '<!-- wp:group {"align":"full","className":"evcert-app","layout":{"type":"default"}} -->
<div class="wp-block-group alignfull evcert-app"><!-- wp:group {"className":"wrap","layout":{"type":"default"}} -->
<div class="wp-block-group wrap"><!-- wp:group {"tagName":"header","className":"top","layout":{"type":"default"}} -->
<header class="wp-block-group top"><!-- wp:group {"layout":{"type":"default"}} -->
<div class="wp-block-group">' . $title . '<!-- wp:paragraph -->
<p>' . esc_html__( 'For meetups of the WordPress community. Fill in the event details, import the attendee list exported from Meetup and download one PDF per person.', 'events-certificates' ) . '</p>
<!-- /wp:paragraph --></div>
<!-- /wp:group -->

<!-- wp:paragraph {"className":"privacy"} -->
<p class="privacy"><strong>' . esc_html__( 'Everything runs in your browser.', 'events-certificates' ) . '</strong> ' . esc_html__( 'The list is not sent to any server.', 'events-certificates' ) . '</p>
<!-- /wp:paragraph --></header>
<!-- /wp:group -->

<!-- wp:details {"showContent":true,"className":"tutorial panel","anchor":"evcert-tutorial"} -->
<details id="evcert-tutorial" class="wp-block-details tutorial panel" open><summary>' . esc_html( $data['title'] ) . '</summary><!-- wp:paragraph {"className":"tut-intro"} -->
<p class="tut-intro">' . strong_tags( $data['intro'] ) . '</p>
<!-- /wp:paragraph -->

<!-- wp:group {"className":"tut","layout":{"type":"default"}} -->
<div class="wp-block-group tut">' . $steps . '</div>
<!-- /wp:group -->

<!-- wp:paragraph {"className":"tut-link"} -->
<p class="tut-link">' . esc_html( $data['hint'] ) . '</p>
<!-- /wp:paragraph --></details>
<!-- /wp:details -->

<!-- wp:group {"className":"grid","layout":{"type":"default"}} -->
<div class="wp-block-group grid"><!-- wp:group {"className":"stack","layout":{"type":"default"}} -->
<div class="wp-block-group stack"><!-- wp:events-certificates/event-fields -->
' . block_tag( 'event-info', $attributes, array( 'community', 'title', 'logoId', 'logoUrl' ) ) . '
' . block_tag( 'event-schedule', $attributes, array( 'venue', 'city' ) ) . '
' . block_tag( 'event-attrs', $attributes, array( 'speakers', 'link', 'showHours' ) ) . '
<!-- /wp:events-certificates/event-fields -->

' . block_tag( 'signature', $attributes, array( 'signName', 'signRole' ) ) . '

<!-- wp:events-certificates/people-list /--></div>
<!-- /wp:group -->

' . block_tag( 'preview', $attributes, array( 'accent' ) ) . '</div>
<!-- /wp:group --></div>
<!-- /wp:group --></div>
<!-- /wp:group -->
';
}

/**
 * Whether the generator page is meant for this site: only where no event
 * plugin brings its own way to issue certificates.
 *
 * @return bool
 */
function wanted() {
	return 'none' === \EventsCertificate\Environment\integration();
}

/**
 * The generator page's ID, or 0.
 *
 * @return int
 */
function page_id() {
	$id = (int) get_option( PAGE_OPTION );

	return $id && 'page' === get_post_type( $id ) && 'trash' !== get_post_status( $id ) ? $id : 0;
}

/**
 * The page's address name in the site's language.
 *
 * @return string
 */
function slug() {
	/* translators: the address of the generator page, lowercase words joined by hyphens (certificate-generator). */
	return sanitize_title( _x( 'certificate-generator', 'page slug', 'events-certificates' ) );
}

/**
 * Whether the pages of this site are edited with the block editor (not with the Classic Editor plugin):
 * then the page's content is the blocks, else the shortcode that renders them.
 *
 * @return bool
 */
function blocks_for_page() {
	return ! function_exists( 'use_block_editor_for_post_type' ) || use_block_editor_for_post_type( 'page' );
}

/**
 * Makes the page, once, for an administrator (on the admin screens, where
 * WordPress is fully loaded: never on a visitor's request).
 */
function ensure_page() {
	if ( wp_doing_ajax() || ! current_user_can( \EventsCertificate\Admin\CAPABILITY ) ) {
		return;
	}
	if ( page_id() ) {
		return;
	}
	if ( ! wanted() || false !== get_option( PAGE_OPTION ) ) {
		return;
	}
	$id = wp_insert_post(
		array(
			'post_type'    => 'page',
			'post_status'  => 'publish',
			'post_title'   => __( 'Certificates of participation', 'events-certificates' ),
			'post_name'    => slug(),
			'post_content' => wp_slash( blocks_for_page() ? page_content() : '[evcert_quick_generator]' ),
		)
	);
	if ( ! is_wp_error( $id ) && $id ) {
		update_post_meta( (int) $id, '_wp_page_template', TEMPLATE_SLUG );
	}
	// Asked once: deleting the page doesn't bring it back.
	update_option( PAGE_OPTION, is_wp_error( $id ) ? 0 : (int) $id, false );
}

/**
 * Registers the page template of a block theme: the page's content on its own, with no header or footer.
 * Pages choose it in the editor's Template setting; the generator page is made with it.
 */
function register_template() {
	register_block_template(
		TEMPLATE_NAME,
		array(
			'title'       => __( 'Certificate generator (blank canvas)', 'events-certificates' ),
			'description' => __( 'The page\'s content on its own, with no header or footer: for the certificate generator.', 'events-certificates' ),
			'content'     => '<!-- wp:post-content {"layout":{"type":"default"}} /-->',
			'post_types'  => array( 'page' ),
		)
	);
}

/**
 * The same template in a classic theme's list of page templates (the editor keeps only listed ones).
 *
 * @param array<string, string> $templates Template slug => name.
 * @return array<string, string>
 */
function page_templates( $templates ) {
	if ( ! wp_is_block_theme() ) {
		$templates[ TEMPLATE_SLUG ] = __( 'Certificate generator (blank canvas)', 'events-certificates' );
	}

	return $templates;
}

/**
 * Whether the page shown has the generator's page template: the generator page, or a page that chose it.
 *
 * @return bool
 */
function uses_template() {
	return ( page_id() && is_page( page_id() ) ) || ( is_page() && TEMPLATE_SLUG === get_page_template_slug() );
}

/**
 * Body class of the generator's pages (their background and text colors).
 *
 * @param string[] $classes Classes.
 * @return string[]
 */
function body_class( $classes ) {
	if ( uses_template() ) {
		$classes[] = 'evcert-quick-generator-page';
	}

	return $classes;
}

/**
 * A classic theme gets the template as a PHP file (a theme can have its own, evcert-quick-generator.php, like
 * WooCommerce's templates); a block theme draws the registered block template, as WordPress picks it.
 *
 * @param string $template Template.
 * @return string
 */
function template( $template ) {
	if ( ! uses_template() ) {
		return $template;
	}
	if ( wp_is_block_theme() && TEMPLATE_SLUG === get_page_template_slug() ) {
		return $template;
	}
	$theirs = locate_template( 'evcert-quick-generator.php' );

	return '' !== $theirs ? $theirs : dirname( __DIR__ ) . '/templates/quick-generator-page.php';
}

/**
 * Whether a content has the app: its wrapper, or the shortcode that draws it.
 *
 * @param string $content Content.
 * @return bool
 */
function has_app( $content ) {
	return false !== strpos( $content, 'evcert-app' ) || false !== strpos( $content, '[evcert_quick_generator' );
}

/**
 * Stops WordPress from putting an inner container in the groups, remembering that it was this code that did it
 * (restore_inner_container() puts it back only then: someone else may have removed it on purpose).
 */
function stop_inner_container() {
	if ( has_filter( 'render_block_core/group', 'wp_restore_group_inner_container' ) ) {
		remove_filter( 'render_block_core/group', 'wp_restore_group_inner_container', 10 );
		$GLOBALS['evcert_inner_container_dropped'] = true;
	}
}

/**
 * Before the blocks are drawn: the app's groups keep the markup they were saved with.
 *
 * @param string $content Content.
 * @return string
 */
function drop_inner_container( $content ) {
	if ( has_app( $content ) ) {
		stop_inner_container();
	}

	return $content;
}

/**
 * After them: the rest of the site's groups are drawn as before.
 *
 * @param string $content Content.
 * @return string
 */
function restore_inner_container( $content ) {
	if ( $GLOBALS['evcert_inner_container_dropped'] ?? false ) {
		add_filter( 'render_block_core/group', 'wp_restore_group_inner_container', 10, 2 );
		$GLOBALS['evcert_inner_container_dropped'] = false;
	}

	return $content;
}
