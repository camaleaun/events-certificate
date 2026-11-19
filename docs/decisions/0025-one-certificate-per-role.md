# 0025 — A person has roles, and a certificate for each

**Status:** Accepted (2026-11-18). Replaces the "one certificate per person and event" of ADR 0020.

## Context

On a WordCamp the same person is often an attendee, a speaker, an organizer and a volunteer, and each role deserves
its own certificate: its own wording, template and workload. The plugin assumed one certificate per person: the
people table held one role and one certificate pointer, adding "Ana, speaker" and "Ana, organizer" overwrote the
first role, and the cross-source match (same e-mail and event) would have taken the speaker's certificate for the
attendee's.

## Decision

- **A role is part of a certificate's identity**: (event or source record, person, role). A person's roles and the
  certificate of each are rows of `evcert_person_roles` (schema 3; `UNIQUE (person_id, role)`); a person read from
  `People\get()` carries `roles` (role → certificate ID, in the order they were given) and `role` (the first, the
  primary one). The old `role` and `certificate_id` columns of the people table are left unused (dbDelta does not
  drop columns) and their data is copied once by `Install\migrate()`.
- **Adding a role never replaces one**: `People\add()` joins the roles it is given to the person's, a name or e-mail
  already on the list is the same person, and a line with no role adds none (a new person with none is an attendee).
  A role is taken off (`remove_role()`) only while it has no certificate (that one is revoked), and never the last one.
- **Issuing is per role**: `People\issue( $person, $role )`, `issue_many( $ids, $event_id, $roles )` (one certificate for
  each role of each person, or of the roles asked for); `certificate()` and `latest_certificate()` take the role;
  `counts()['certified']` counts certificates and `people_certified` the people who hold any.
- **Lookups carry the role**: `Certificates\find_by_source()`, `latest_by_source()` and `find_for_person()` require it.
- **Correcting and revoking are per certificate**: a correction keeps the role (another role is another certificate: revoke
  this one and issue the other), and a revoked certificate is final for its role only.
- **Lists accept several roles**: "speaker / organizer", "Palestrante + Voluntário", "organizer, speaker", one more cell each,
  and a role column in a CSV (Role, Papel, Função, Cargo).

- **Each role says its own words**: `Render\phrases( $role )` gives a speaker's certificate its own title ("Speaker
  certificate") and "gave a talk at", an organizer's "organized", a volunteer's "volunteered at", a sponsor's
  "sponsored"; the attendee's are the ones that were always there. The `evcert_phrases` filter changes them, and a
  template of a role (`Designs\for_certificate`) still wins, with its own literal text. The declaration page (the
  participant's own account of days and talks) is for attendees only.
- **Each role has its own hours**: an event's optional `hours_by_role` (an organizer's 40, a volunteer's 8) is read by
  `Events\hours( $event, $role )` and frozen in the certificate; a role left out uses the event's hours.

- **Where the roles come from**: the pasted list and CSV (a role column, or several in a text), the abilities, the
  GatherPress organizers (Settings → Roles, `evcert_gatherpress_roles` adds more), the WordCamp's speakers, organizers
  and volunteers (`includes/compat/wcpt.php`, button and ability `import-wordcamp-people`; the same person in two roles
  is merged by e-mail, then WordPress.org user name, then name) and the role set on a CampTix ticket (box on the
  ticket, `evcert_camptix_role` filter). Whoever is a speaker or organizer by these sources is marked as attended:
  they were there.

## Consequences

- `tests/php/PeopleRolesTest.php` covers the roles, the certificates per role, the per-role correction and revocation,
  the counts, the lookups and the migration.
- Out of scope: the public Quick generator, which makes one certificate per person in the browser (ADR 0017).
- Next: tags in the people and certificate lists, role-aware wording and hours, and the sources of roles (ADR to follow).
