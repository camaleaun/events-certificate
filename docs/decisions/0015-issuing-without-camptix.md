# 0015 — Issuing without CampTix: events, participants, generator, GatherPress

**Status:** Accepted (2026-11-11)

## Decision

Certificates are issued on any site, with no other plugin:

- **Events** (`evcert_event` posts, managed on Certificates > Events): name,
  start and end, place, link, hours (from the times unless set) and an
  optional signer.
- **Participants** (the `evcert_people` table), each **registered**,
  **attended** or **no-show**: typed, pasted (name, e-mail, role), read from a
  spreadsheet export such as Meetup's attendee CSV, or synced from
  GatherPress. The same person is matched by record, e-mail or name.
- Only who **attended** gets a certificate (`People\issue()`), with
  `context = event`, `event_id` and `source = evcert` (`source_ref` = the
  participant). Like other certificates (decision 0001), the event's name,
  place, dates and signer are the current ones; name, role and hours are
  frozen.
- **Generator**: one screen with the event and the list; one click makes
  the event, the participants (as attended) and their certificates, and
  downloads them as a ZIP (one PDF each) or a single PDF.
- **GatherPress** (when active): import an event; RSVPs "attending" come in as
  registered and check-ins as attended; waiting list and "not attending"
  stay out. Syncing again never demotes anyone. GatherPress's data is only
  read.
- **Signer**: an event's own signer (name, role), else the one in Settings
  (name, role, an SVG signature), else WordCamp's organizer marked as signer.
  On a WordCamp site the organizer still comes first.

CampTix (WordCamp.org's fork) stays an integration: attendees there get
their own certificates from their ticket (decisions 0012, 0014).

## Why

- CampTix was closed in the plugin directory in 2019; a plugin that only
  issues through it can't be used, or reviewed, on a plain site.
- Meetups are the most common case: organizers export the attendee list from
  Meetup and want a certificate for everyone who came. Browser-only
  generators do that, but their PDFs can't be verified; here every
  certificate has its code and page.
- GatherPress is the WordPress community's own event plugin.

## Consequences

- Certificates > Events and Generator are menu items of their own (or
  buttons on Certificates when it sits in CampTix's Tickets menu).
- Uninstall with "Delete all data" also removes events and participants.
- Spreadsheet files must be CSV (no XLS/XLSX library is bundled); the
  screens say how to export one.
