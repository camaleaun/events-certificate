# 0013 — Certificate templates chosen by condition

**Status:** Accepted (2026-11-10)

## Decision

A site has several certificate **templates** (the `evcert_design` posts),
listed on a Templates screen opened from Certificates. Each template applies
to some **roles** (`_evcert_roles`: attendee, speaker, organizer, volunteer,
sponsor); one template is the **default** (`evcert_site_design`) and applies
to everyone else. A certificate is drawn with the first template, in the
list's order (`menu_order`), that applies to its role
(`Designs\for_certificate()`), else the default.

The screen adds templates (from the Base design), duplicates one (content and
roles, "… (copy)"), makes one the default (its roles are cleared), moves one
up or down and trashes one (never the default). The editor's Certificate tab
edits a template's roles ("Applies to"); the preview shows its first role.

## Why

- Events give different certificates by role: volunteers, speakers and
  organizers get other words than attendees. Duplicating the general one
  and changing a few texts is the usual way to make them.
- The choice is made when a certificate is drawn, like the design itself
  (decision 0001): changing a template, its roles or the order shows on
  every certificate it applies to.

## Consequences

- `Certificates\design()` and `issue()` (for the recorded `design_hash`) use
  the template for the certificate's role.
- Share images are named after every template's state
  (`Designs\fingerprint()`), so any change draws them again.
- Conditions are meant to grow (days of the event, ticket types) as the same
  kind of meta.
