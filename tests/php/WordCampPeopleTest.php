<?php
/**
 * The speakers, organizers and volunteers of a WordCamp site as people with roles.
 *
 * @package EventsCertificate
 */

namespace EventsCertificate\Tests;

use EventsCertificate\Compat\WCPT;
use EventsCertificate\People;

/**
 * Someone who is a speaker and a volunteer is one person with two roles.
 */
class WordCampPeopleTest extends Test_Case {

	/**
	 * The types wc-post-types registers, when the site does not have them.
	 */
	protected function set_up() {
		parent::set_up();
		foreach ( WCPT\POST_TYPES as $type ) {
			if ( ! post_type_exists( $type ) ) {
				register_post_type( $type, array( 'public' => false ) );
			}
		}
	}

	/**
	 * A post of a type.
	 *
	 * @param string               $type Post type.
	 * @param string               $name Title.
	 * @param array<string,string> $meta Meta.
	 */
	private function post( $type, $name, array $meta = array() ) {
		$id = wp_insert_post(
			array(
				'post_type'   => $type,
				'post_status' => 'publish',
				'post_title'  => $name,
			)
		);
		foreach ( $meta as $key => $value ) {
			update_post_meta( $id, $key, $value );
		}
	}

	/**
	 * Speaker and volunteer with the same e-mail are one person; a draft stays out; an organizer comes with their account's e-mail.
	 */
	public function test_roles_of_the_same_person_are_merged() {
		$user = wp_insert_user(
			array(
				'user_login' => 'wcorg' . wp_generate_password( 6, false ),
				'user_pass'  => wp_generate_password(),
				'user_email' => 'organizer-account@example.test',
			)
		);
		$this->post( 'wcb_speaker', 'Ana Lima', array( '_wcb_speaker_email' => 'ana@example.test' ) );
		$this->post( 'wcb_volunteer', 'Ana L.', array( '_wcb_volunteer_email' => 'ANA@example.test' ) );
		$this->post( 'wcb_organizer', 'Bruno Souza', array( '_wcpt_user_id' => $user ) );
		wp_insert_post(
			array(
				'post_type'   => 'wcb_speaker',
				'post_status' => 'draft',
				'post_title'  => 'Rascunho Pessoa',
			)
		);

		$people = array_values(
			array_filter(
				WCPT\people(),
				static function ( $person ) {
					return in_array( $person['name'], array( 'Ana Lima', 'Bruno Souza', 'Rascunho Pessoa' ), true );
				}
			)
		);

		$this->assertCount( 2, $people, 'A draft is not a speaker yet.' );
		$this->assertSame( array( 'speaker', 'volunteer' ), $people[0]['roles'] );
		$this->assertSame( array( 'organizer' ), $people[1]['roles'] );
		$this->assertSame( 'organizer-account@example.test', $people[1]['email'] );
		$this->assertSame( 'attended', $people[0]['status'] );
	}

	/**
	 * Importing puts them in an event, and again changes nothing.
	 */
	public function test_import_into_an_event() {
		$event = Fixtures::event();
		$this->post( 'wcb_speaker', 'Zélia Teste Wc', array( '_wcb_speaker_email' => 'zelia-wc@example.test' ) );
		$this->post( 'wcb_volunteer', 'Zélia Teste Wc', array( '_wcb_volunteer_email' => 'zelia-wc@example.test' ) );

		$first = WCPT\import( $event );
		$again = WCPT\import( $event );
		$found = People\find(
			$event,
			People\clean(
				array(
					'email' => 'zelia-wc@example.test',
					'name'  => 'Zélia Teste Wc',
				)
			)
		);

		$this->assertGreaterThanOrEqual( 1, $first['added'] );
		$this->assertSame( 0, $again['added'] );
		$this->assertSame( array( 'speaker', 'volunteer' ), array_keys( $found['roles'] ) );
		$this->assertSame( 'attended', $found['status'] );
		$this->assertInstanceOf( \WP_Error::class, WCPT\import( 99999999 ) );
	}
}
