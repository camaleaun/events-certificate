=== Events Certificate ===
Contributors: camaleaun
Tags: certificate, events, attendance, camptix, qr code
Requires at least: 7.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 0.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Verifiable attendance certificates with a QR code. Attendees from CampTix, GatherPress, Meetup.com CSV exports or added by hand.

== Description ==

Events Certificate issues attendance certificates for events (meetups, WordCamps, courses) that anyone can verify: each certificate has a unique code and a QR code pointing to a public verification page on your site, in the spirit of the authenticity codes on government documents.

This plugin is in early development.

**Planned**

* A canvas editor for the certificate artwork, with a mobile and a social image version.
* Attendees from CampTix (WordCamps), GatherPress, a Meetup.com attendee export or any other CSV, or added by hand: one at a time or pasted from a spreadsheet.
* Certificates frozen when issued and drawn again on demand: no PDF files kept on the server.
* Verification pages in the languages you choose, so people from other countries can check a certificate.
* Optional identity documents stored like passwords: the site never keeps the number, only a way to check it.

== Frequently Asked Questions ==

= Why does my certificate show a placeholder instead of a QR code? =

The QR code and the verification link only make sense when anyone can open your site. On WordPress Playground, localhost, .local or .test addresses and private networks the plugin shows a placeholder of the same size instead. An administrator can force authenticity on a public staging site.

= Does deleting the plugin delete the certificates? =

No, unless an administrator chose to delete all data. Printed certificates point to their verification page, so they are kept by default.

== External services ==

To name the site's languages the way WordPress's language selector does ("Português do Brasil"), the plugin reads WordPress's list of translations with the core function wp_get_available_translations(), which asks the WordPress.org API (api.wordpress.org/translations/core/) for it. It runs in the admin, for administrators, only when a language of the site has no name stored yet, at most once a day; it sends the WordPress version and the site address WordPress sends on its own update checks. The names are stored in the site, so the public certificate page makes no request. WordPress.org privacy policy: https://wordpress.org/about/privacy/

== Changelog ==

= 0.1.0 =

* In development.
