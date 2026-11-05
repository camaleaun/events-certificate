<?php
/**
 * The signer: the lead organizer who signs the certificates, with their role
 * (per language) and signature. On WordCamp sites it is one of the
 * Organizers (wcb_organizer, from wc-post-types): a "Signer" panel on
 * the organizer's screen marks them and holds the role and the signature.
 * The signature is restricted SVG, like the editor's (docs/decisions/0005):
 * a viewBox and filled paths, nothing else.
 *
 * Certificates freeze the signer's name, role and signature when issued.
 *
 * @package EventsCertificate
 */

namespace EventsCertificate\Signer;

use EventsCertificate\Render;
use EventsCertificate\Verify;

defined( 'ABSPATH' ) || exit;

/**
 * Option with the organizer (post ID) who signs.
 */
const OPTION = 'evcert_signer';

/**
 * Organizer meta: role per locale (array) and signature ({vw, vh, paths}).
 */
const ROLE_META      = '_evcert_signer_role';
const SIGNATURE_META = '_evcert_signature';

/**
 * Post type of organizers (wc-post-types).
 */
const ORGANIZER = 'wcb_organizer';

/**
 * Hooks.
 */
function bootstrap() {
	add_action( 'add_meta_boxes_' . ORGANIZER, __NAMESPACE__ . '\add_meta_box' );
	add_action( 'save_post_' . ORGANIZER, __NAMESPACE__ . '\save' );
}

/**
 * The Signer panel in the organizer's sidebar.
 */
function add_meta_box() {
	\add_meta_box( 'evcert-signer', __( 'Signer', 'events-certificate' ), __NAMESPACE__ . '\render_meta_box', ORGANIZER, 'side', 'default', array( '__back_compat_meta_box' => false ) );
}

/**
 * Reads SVG code into restricted fields: the viewBox size and the paths with
 * their fill (groups unwrapped, their fill passed down). Strokes, other
 * shapes, styles, scripts and links are dropped.
 *
 * @param string $code SVG code.
 * @return array{vw: float, vh: float, paths: array<int, array<string, string>>}|null
 */
function parse_svg( $code ) {
	$code = trim( (string) $code );
	// No DTDs or entities (XXE), no network.
	if ( '' === $code || ! class_exists( 'DOMDocument' ) || preg_match( '/<!(DOCTYPE|ENTITY)/i', $code ) ) {
		return null;
	}
	$doc      = new \DOMDocument();
	$previous = libxml_use_internal_errors( true );
	$ok       = $doc->loadXML( $code, LIBXML_NONET );
	libxml_use_internal_errors( $previous );
	$root = $ok ? $doc->documentElement : null; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- DOM API.
	if ( ! $root || 'svg' !== strtolower( $root->localName ) ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- DOM API.
		return null;
	}
	$fill_of = static function ( $node ) use ( $root ) {
		for ( $n = $node; $n instanceof \DOMElement; $n = $n->parentNode ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- DOM API.
			// A fill in the style attribute wins over the fill attribute, as in CSS (Inkscape writes styles).
			if ( preg_match( '/(?:^|;)\s*fill\s*:\s*([^;]+)/i', $n->getAttribute( 'style' ), $m ) ) {
				return trim( $m[1] );
			}
			if ( $n->hasAttribute( 'fill' ) ) {
				return $n->getAttribute( 'fill' );
			}
			if ( $n === $root ) {
				break;
			}
		}
		return '';
	};
	$paths   = array();
	foreach ( $root->getElementsByTagName( 'path' ) as $node ) {
		$d = trim( preg_replace( '/\s+/', ' ', $node->getAttribute( 'd' ) ) );
		if ( ! Render\is_path_data( $d ) ) {
			continue;
		}
		$fill = strtolower( trim( $fill_of( $node ) ) );
		if ( 'none' === $fill ) {
			continue; // Outline-only paths (strokes) aren't kept: convert strokes to filled outlines first.
		}
		$path = array( 'd' => $d );
		if ( preg_match( '/^(#[0-9a-f]{3,8}|rgba?\([\d\s.,%]+\))$/', $fill ) ) {
			$path['fill'] = $fill;
		}
		if ( 'evenodd' === $node->getAttribute( 'fill-rule' ) || preg_match( '/fill-rule\s*:\s*evenodd/i', $node->getAttribute( 'style' ) ) ) {
			$path['rule'] = 'evenodd';
		}
		$paths[] = $path;
	}
	if ( ! $paths ) {
		return null;
	}
	$box = preg_split( '/[\s,]+/', trim( $root->getAttribute( 'viewBox' ) ) );
	$vw  = 4 === count( $box ) && (float) $box[2] > 0 ? (float) $box[2] : ( (float) $root->getAttribute( 'width' ) ? (float) $root->getAttribute( 'width' ) : 100.0 );
	$vh  = 4 === count( $box ) && (float) $box[3] > 0 ? (float) $box[3] : ( (float) $root->getAttribute( 'height' ) ? (float) $root->getAttribute( 'height' ) : 100.0 );

	return array(
		'vw'    => $vw,
		'vh'    => $vh,
		'paths' => $paths,
	);
}

/**
 * The signature as SVG markup (for previews), in a color.
 *
 * @param array<string, mixed> $signature Signature fields.
 * @param string               $color     Color of paths without their own fill.
 * @return string
 */
function signature_svg( array $signature, $color = 'currentColor' ) {
	$paths = '';
	foreach ( (array) ( $signature['paths'] ?? array() ) as $path ) {
		if ( Render\is_path_data( $path['d'] ?? '' ) ) {
			$paths .= sprintf( '<path d="%s" fill="%s"%s/>', esc_attr( $path['d'] ), esc_attr( $path['fill'] ?? $color ), 'evenodd' === ( $path['rule'] ?? '' ) ? ' fill-rule="evenodd"' : '' );
		}
	}

	return sprintf( '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 %s %s">%s</svg>', (float) $signature['vw'], (float) $signature['vh'], $paths );
}

/**
 * Prints the Certificate panel.
 *
 * @param \WP_Post $post Organizer.
 */
function render_meta_box( $post ) {
	wp_nonce_field( 'evcert_signer_' . $post->ID, 'evcert_signer_nonce' );
	$roles     = (array) get_post_meta( $post->ID, ROLE_META, true );
	$signature = get_post_meta( $post->ID, SIGNATURE_META, true );
	$current   = (int) get_option( OPTION );
	?>
	<p>
		<label><input type="checkbox" name="evcert_signer" value="1" <?php checked( $current, $post->ID ); ?>> <?php esc_html_e( 'Signs the certificates', 'events-certificate' ); ?></label>
		<?php if ( $current && $current !== $post->ID && get_post( $current ) ) : ?>
			<br><span class="description">
			<?php
			/* translators: %s: name of the organizer who signs now. */
			printf( esc_html__( 'Now signed by %s; checking this replaces them.', 'events-certificate' ), esc_html( get_the_title( $current ) ) );
			?>
			</span>
		<?php endif; ?>
	</p>
	<?php foreach ( Verify\locales() as $locale ) : ?>
		<p>
			<label for="evcert_signer_role_<?php echo esc_attr( $locale ); ?>">
				<?php
				/* translators: %s: language name. */
				printf( esc_html__( 'Role (%s)', 'events-certificate' ), esc_html( Verify\locale_name( $locale ) ) );
				?>
			</label>
			<input type="text" class="widefat" id="evcert_signer_role_<?php echo esc_attr( $locale ); ?>" name="evcert_signer_role[<?php echo esc_attr( $locale ); ?>]" value="<?php echo esc_attr( $roles[ $locale ] ?? '' ); ?>" placeholder="<?php esc_attr_e( 'Event Coordinator', 'events-certificate' ); ?>">
		</p>
	<?php endforeach; ?>
	<p>
		<label for="evcert_signature"><?php esc_html_e( 'Signature (SVG)', 'events-certificate' ); ?></label>
		<?php if ( is_array( $signature ) && ! empty( $signature['paths'] ) ) : ?>
			<span style="display:block;margin:6px 0;padding:8px;background:#fff;border:1px solid #dcdcde;border-radius:2px;color:#1e1e1e;">
				<img alt="<?php esc_attr_e( 'Signature', 'events-certificate' ); ?>" style="display:block;width:100%;height:60px;object-fit:contain;" src="<?php echo esc_attr( Render\svg_src( signature_svg( $signature, '#1e1e1e' ) ) ); ?>">
			</span>
			<label><input type="checkbox" name="evcert_signature_remove" value="1"> <?php esc_html_e( 'Remove the signature', 'events-certificate' ); ?></label>
		<?php endif; ?>
		<textarea class="widefat code" rows="3" id="evcert_signature" name="evcert_signature" placeholder="&lt;svg viewBox=&quot;0 0 120 44&quot;&gt;&lt;path d=&quot;M…&quot;/&gt;&lt;/svg&gt;"></textarea>
		<span class="description"><?php esc_html_e( 'Paste the SVG code. Only the viewBox and filled paths are kept: convert strokes to filled outlines first (in Inkscape, Path → Stroke to Path).', 'events-certificate' ); ?></span>
	</p>
	<?php
}

/**
 * Saves the panel.
 *
 * @param int $post_id Organizer ID.
 */
function save( $post_id ) {
	if ( ! isset( $_POST['evcert_signer_nonce'] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['evcert_signer_nonce'] ) ), 'evcert_signer_' . $post_id ) || ! current_user_can( 'edit_post', $post_id ) || wp_is_post_revision( $post_id ) ) {
		return;
	}

	if ( ! empty( $_POST['evcert_signer'] ) ) {
		update_option( OPTION, $post_id, false );
	} elseif ( (int) get_option( OPTION ) === $post_id ) {
		delete_option( OPTION );
	}

	$roles = array();
	foreach ( Verify\locales() as $locale ) {
		$roles[ $locale ] = isset( $_POST['evcert_signer_role'][ $locale ] ) ? sanitize_text_field( wp_unslash( $_POST['evcert_signer_role'][ $locale ] ) ) : '';
	}
	update_post_meta( $post_id, ROLE_META, array_filter( $roles ) );

	if ( ! empty( $_POST['evcert_signature_remove'] ) ) {
		delete_post_meta( $post_id, SIGNATURE_META );
	}
	$code = isset( $_POST['evcert_signature'] ) ? trim( (string) wp_unslash( $_POST['evcert_signature'] ) ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- SVG code, read into restricted fields by parse_svg().
	if ( '' !== $code ) {
		$signature = parse_svg( $code );
		if ( $signature ) {
			update_post_meta( $post_id, SIGNATURE_META, $signature );
		}
	}
}

/**
 * The signer now: name, role per locale and signature, or null.
 *
 * @return array{name: string, roles: array<string, string>, signature: array<string, mixed>|null}|null
 */
function current() {
	$id   = (int) get_option( OPTION );
	$post = $id ? get_post( $id ) : null;
	if ( ! $post || ORGANIZER !== $post->post_type ) {
		return null;
	}
	$signature = get_post_meta( $id, SIGNATURE_META, true );

	return array(
		'name'      => html_entity_decode( get_the_title( $post ), ENT_QUOTES, 'UTF-8' ),
		'roles'     => array_filter( (array) get_post_meta( $id, ROLE_META, true ) ),
		'signature' => is_array( $signature ) && ! empty( $signature['paths'] ) ? $signature : null,
	);
}

/**
 * A role in a locale: that locale's, else the site language's, else any.
 *
 * @param array<string, string> $roles  Role per locale.
 * @param string                $locale Locale.
 * @return string
 */
function role_in( array $roles, $locale ) {
	if ( ! empty( $roles[ $locale ] ) ) {
		return $roles[ $locale ];
	}
	if ( ! empty( $roles[ get_locale() ] ) ) {
		return $roles[ get_locale() ];
	}

	return $roles ? (string) reset( $roles ) : '';
}
