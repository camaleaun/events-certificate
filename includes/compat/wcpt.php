<?php
/**
 * WordCamp.org's wc-post-types: the speakers, organizers and volunteers a
 * WordCamp site keeps as posts become the people of an event, each with their
 * role (docs/decisions/0025). Someone who is a speaker and a volunteer is one
 * person with two roles and gets two certificates.
 *
 * @package EventsCertificate
 */

namespace EventsCertificate\Compat\WCPT;

use EventsCertificate\Admin;
use EventsCertificate\Events;
use EventsCertificate\EventsAdmin;
use EventsCertificate\People;

defined( 'ABSPATH' ) || exit;

/**
 * Post type of each role (wc-post-types).
 */
const POST_TYPES = array(
	'speaker'   => 'wcb_speaker',
	'organizer' => 'wcb_organizer',
	'volunteer' => 'wcb_volunteer',
);

/**
 * The meta that holds the e-mail, by role (organizers have none: their account's).
 */
const EMAIL_META = array(
	'speaker'   => '_wcb_speaker_email',
	'volunteer' => '_wcb_volunteer_email',
);

/**
 * Hooks.
 */
function register() {
	add_action( 'evcert_event_participants_actions', __NAMESPACE__ . '\render_import' );
	add_action( 'admin_post_evcert_wordcamp_import', __NAMESPACE__ . '\handle_import' );
}

/**
 * Whether this site keeps its speakers, organizers and volunteers as wc-post-types does.
 *
 * @return bool
 */
function available() {
	foreach ( POST_TYPES as $type ) {
		if ( post_type_exists( $type ) ) {
			return true;
		}
	}

	return false;
}

/**
 * The published speakers, organizers and volunteers, as people with their role.
 *
 * @return array<int, array<string, mixed>>
 */
function people() {
	$people = array();
	foreach ( POST_TYPES as $role => $type ) {
		if ( ! post_type_exists( $type ) ) {
			continue;
		}
		$posts = get_posts(
			array(
				'post_type'      => $type,
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'orderby'        => 'title',
				'order'          => 'ASC',
			)
		);
		foreach ( $posts as $post ) {
			$user  = (int) get_post_meta( $post->ID, '_wcpt_user_id', true );
			$user  = $user ? get_userdata( $user ) : false;
			$email = isset( EMAIL_META[ $role ] ) ? (string) get_post_meta( $post->ID, EMAIL_META[ $role ], true ) : '';
			$email = is_email( $email ) ? $email : ( $user ? $user->user_email : '' );
			$name  = html_entity_decode( $post->post_title, ENT_QUOTES, 'UTF-8' );
			if ( '' === trim( $name ) ) {
				continue;
			}
			$person = array(
				'name'           => $name,
				'email'          => $email,
				'wporg_username' => (string) get_post_meta( $post->ID, '_wcpt_user_name', true ),
				'roles'          => array( $role ),
				// They were there: a speaker spoke, an organizer organized.
				'status'         => 'attended',
				'source'         => 'wordcamp',
				'source_ref'     => $type . '-' . $post->ID,
			);
			// The same person in another role: by e-mail, else by WordPress.org user name, else by name.
			foreach ( $people as $i => $known ) {
				if ( same_person( $known, $person ) ) {
					$people[ $i ]['roles'] = array_values( array_unique( array_merge( $known['roles'], array( $role ) ) ) );
					$people[ $i ]['email'] = '' !== $known['email'] ? $known['email'] : $email;
					continue 2;
				}
			}
			$people[] = $person;
		}
	}

	/**
	 * Filters the people read from the WordCamp's posts.
	 *
	 * @param array<int, array<string, mixed>> $people People, each with name, email, roles, status.
	 */
	return (array) apply_filters( 'evcert_wordcamp_people', $people );
}

/**
 * Whether two entries are the same person.
 *
 * @param array<string, mixed> $a One.
 * @param array<string, mixed> $b Another.
 * @return bool
 */
function same_person( array $a, array $b ) {
	if ( '' !== $a['email'] && '' !== $b['email'] ) {
		return 0 === strcasecmp( $a['email'], $b['email'] );
	}
	if ( '' !== $a['wporg_username'] && '' !== $b['wporg_username'] ) {
		return 0 === strcasecmp( $a['wporg_username'], $b['wporg_username'] );
	}

	return 0 === strcasecmp( $a['name'], $b['name'] );
}

/**
 * Imports the people into an event (again later: the roles of someone already there are added to).
 *
 * @param int $event_id Event.
 * @return array{event: int, added: int, updated: int, failed: int}|\WP_Error
 */
function import( $event_id ) {
	if ( ! Events\get( $event_id ) ) {
		return new \WP_Error( 'evcert_invalid_event_id', __( 'That event no longer exists.', 'events-certificates' ) );
	}
	if ( ! available() ) {
		return new \WP_Error( 'evcert_no_wordcamp_people', __( 'This site has no WordCamp speakers, organizers or volunteers (wc-post-types).', 'events-certificates' ) );
	}

	return array( 'event' => (int) $event_id ) + People\import( (int) $event_id, people() );
}

/**
 * The button, in the people of an event.
 *
 * @param array<string, mixed> $event Event.
 */
function render_import( array $event ) {
	if ( ! available() ) {
		return;
	}
	?>
	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="evcert-gatherpress">
		<?php wp_nonce_field( 'evcert_wordcamp_import_' . (int) $event['id'] ); ?>
		<input type="hidden" name="action" value="evcert_wordcamp_import">
		<input type="hidden" name="evcert_event_id" value="<?php echo esc_attr( (string) $event['id'] ); ?>">
		<span><?php esc_html_e( 'From this WordCamp: speakers, organizers and volunteers come in as attended, each with their role.', 'events-certificates' ); ?></span>
		<button type="submit" class="button"><?php esc_html_e( 'Import speakers, organizers and volunteers', 'events-certificates' ); ?></button>
	</form>
	<?php
}

/**
 * Handles the import button.
 */
function handle_import() {
	$id = isset( $_POST['evcert_event_id'] ) ? absint( $_POST['evcert_event_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- names the nonce, checked on the next line.
	check_admin_referer( 'evcert_wordcamp_import_' . $id );
	if ( ! current_user_can( Admin\CAPABILITY ) ) {
		wp_die( esc_html__( 'Sorry, you are not allowed to manage certificates.', 'events-certificates' ), 403 );
	}
	$result = import( $id );
	if ( is_wp_error( $result ) ) {
		set_transient( 'evcert_event_error_' . get_current_user_id(), $result->get_error_message(), MINUTE_IN_SECONDS );
		wp_safe_redirect( EventsAdmin\url( array( 'evcert_done' => 'error' ) ) );
		exit;
	}
	wp_safe_redirect(
		EventsAdmin\url(
			array(
				'event'          => $result['event'],
				'view'           => 'participants',
				'evcert_done'    => 'imported',
				'evcert_added'   => $result['added'],
				'evcert_updated' => $result['updated'],
			)
		)
	);
	exit;
}
