# Browser tests: the whole flow in each integration scenario

Run with the Chrome DevTools MCP (an isolated browser context for the visitor, another for the admin, logged in with
`/studio-auto-login?redirect_to=…`). One Studio site per scenario; set them up once:

| Scenario | Site | Port | Seed |
|---|---|---|---|
| none | `~/Studio/evcert-clean` | 8921 | `bin/e2e-seed.sh none` |
| camptix | `~/Studio/evcert-camptix` (+ `camptix`, `wc-post-types`, `mu-plugins/wordcamp-shims.php`) | 8922 | `bin/e2e-seed.sh camptix [attended\|confirmed\|off]` |
| gatherpress | `~/Studio/evcert-gp` (+ `gatherpress`) | 8923 | `bin/e2e-seed.sh gatherpress` |
| both | `~/Studio/wp-events-certificates` with both active | 8920 | `bin/e2e-seed.sh both` |

Create a site with `studio site create --path ~/Studio/<name> --name "<Name>" --php 8.3 --skip-browser`, install the zip made by
`bin/package.sh` into `wp-content/plugins/`, activate, copy `languages/plugins/events-certificates-pt_BR.*` to test `/pt-br/`.
E-mails the sites send: with Camaleaunmail (installed and active in the four test sites) they are in its log (`studio wp camaleaunmail logs list`); else `.eml` files in `~/.studio/email/<site-id>/`. `tests/browser/latest-mail.sh <address> [site folder]` prints the links and numbers.

**CampTix on a Studio (SQLite) site:** CampTix serializes a checkout with MySQL `GET_LOCK()`, which SQLite does not have, so
every checkout says "A lot of people are checking out at the same time". `mu-plugins/wordcamp-shims.php` grants those locks
(a `query` filter). CampTix also refuses to sell without a payment method even for a free ticket (the seed turns PayPal on;
a $0 order never calls it).

## camptix (self_issue = attended)

1. Visitor: `/tickets/`, quantity 1 of the free ticket, first name, last name, e-mail, *Claim Tickets*: the order completes and
   lands on `?tix_action=access_tickets&tix_access_token=…` (the e-mail has this link; it is the attendee's entry point).
2. Before presence is recorded: the access page shows, under *Edit information*, why there is no certificate (the plugin's
   hint); `?tix_action=certificate&…` says certificates are for attendees marked as present.
3. Admin: *Attendees*, the *Days* column of the plugin: click the day. `tix_attended` and `evcert_days` are saved and the
   button turns green without reloading.
4. Visitor: the access page now has *Certificate*; the step asks the day and the table (required) and offers a declaration
   page; *Issue certificate* → "Your certificate was issued." with the code and address.
5. Visitor: `/certificate/CODE/` (name, event, status), `/certificate/CODE/pdf/` (`application/pdf`, `%PDF-`),
   `/certificate/CODE/pt-br/` (`lang="pt-BR"`, Portuguese tools), `/certificate/CODE/pt-br/pdf/`; the old `?format=pdf` and
   `?evcert_lang=` answer 301.
6. Visitor: the step again, change the name, *Correct and reissue*: the old code says replaced and links the new one, has no PDF
   button, its `/pdf/` redirects; the new one has the new name.
7. Admin: *Tickets → Certificates* (`edit.php?post_type=tix_ticket&page=evcert-certificates`): PDF action is the `/pdf/` address;
   *Revoke*: "1 certificate revoked."
8. Visitor: the code says *Revoked*, no PDF button, `/pdf/` redirects; the step says it was revoked and shows no form; the access
   page has no certificate link.

## gatherpress

1. Admin: *Events → Add New Event*, pick the *Event with RSVP* starter pattern, title, publish (its RSVP block needs the inner
   blocks the editor makes; PHP can not seed them). The event must be in the future (the RSVP form hides once it is over).
2. Visitor (not logged in): the *RSVP* button answers "Login Required"; log in as the member the seed made, *RSVP*, *Attend*:
   "Attending (1)".
3. Admin: *Events → RSVPs*, *Check in* on the row (the row actions show on hover; the link is `action=check_in`).
4. Admin: *Certificates* sits under the GatherPress *Events* menu; plugin *Events* screen (`admin.php?page=evcert-events`):
   *Import from GatherPress* → "1 participant added", status *Attended* (the check-in).
5. Select the participant, *Issue certificates* → "1 certificate issued."; the row shows the code.
6. Visitor: page, `/pdf/`, `/pt-br/`, `/pt-br/pdf/` as in camptix step 5.
7. Admin: revoke from *Certificates*; select the participant again, *Issue certificates* → "0 certificates issued. 1 left out:
   their certificate was revoked."

## none

1. Admin: top-level *Certificates* with *Events*, *Generator*, *Settings*; no GatherPress import, no CampTix tab.
2. *Events → Add event* (title, start, end, place, signer), paste two names with *Everyone attended*, select both, *Issue
   certificates* → "2 certificates issued."
3. Page, `/pdf/` as above. The public generator page (`/certificate-generator/`) draws the default design (the jsPDF output
   is a valid PDF with one page per person).

## both

1. *Certificates* is a top-level menu (not under Tickets, not under GatherPress); its settings page has the CampTix-only
   *self-issue* field; the *Import from GatherPress* form lists the GatherPress events.
2. Run camptix steps 1-8 and gatherpress steps 1-7: the same person has one certificate per event (the rule across sources
   is in `tests/php/LifecycleTest.php`).

## Same person, two roles

Per scenario, after the flow above: give one person two roles and check each certificate on its own.

1. In the event's people, paste `Eva Duarte, eva@example.test, Speaker / Organizer` (or use "+ Role" on a person): one row, two role tags.
2. Mark as attended, then the row action "Issue the missing certificates (2)": two codes, one tag per role with its code.
3. Each page `/certificate/CODE/` (and `/pt-br/`, `/pdf/`) says the title and verb of its role ("Speaker certificate", "gave a talk at"; "Organizer certificate", "organized"); the hours are those of the role.
4. Certificates list, filter by role: only that role's certificates.
5. Revoke one: its page says revoked, the other stays valid; a bulk issue does not bring the revoked one back.

Run on no integration (8921) on 2026-11-18: all passed (screenshot `capturas/e2e/roles-none-speaker.png`). Still to run on CampTix (ticket role box), GatherPress (organizers) and the dev site (wc-post-types).
