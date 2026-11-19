# 0020 — A certificate's life: valid, replaced, revoked

**Status:** Accepted (2026-11-18)

## Context

A certificate is issued, may be corrected (a new one replaces it) and may be revoked. The rules were
implicit and had gaps found when the whole flow was tested in the four integration scenarios (ADR 0019):
a revoked certificate was issued again by the next bulk issue or from the ticket page, a participant kept
pointing at the replaced certificate after a correction (so the corrected one was missing from the
downloads and a third one was made), and the PDF of a revoked or replaced code looked like a valid one.

## Decision

- **Only a valid certificate is corrected.** `Certificates\reissue()` refuses a replaced or revoked one, and
  if the old one cannot be marked replaced it removes the new one: never two valid certificates for one
  correction.
- **Revoked is final for issuing.** A bulk issue skips who was revoked and counts them apart from the rest
  ("left out: their certificate was revoked"); the ticket page refuses with a message; the abilities report
  a `revoked` count. Reinstating is not offered in 0.71.
- **A participant follows the corrections.** `People\certificate()` follows `replaced_by` to the newest
  certificate, so downloads and a second issue use the corrected one.
- **The PDF is for valid certificates.** The public the PDF address (`/pdf/`, ADR 0022) of a replaced or revoked code redirects to
  its page, which says what happened (and links the new certificate). The download buttons of the admin keep
  working.
- **Revoking what is not valid is an error** in the ability (`evcert_not_revocable`), not a quiet success.
- **One certificate per person, event and role across sources** (ADR 0025 added the role). With CampTix and GatherPress together, a person
  the ticket page certified is not certified again by an imported event, and the other way round: the match
  is the same e-mail and event name from another source, and the first one issued is the certificate.
- **A WordCamp certificate carries the WordCamp's days** (start and end, at noon UTC so the day does not move
  with the timezone), not the day it was issued.

- **The document check limits wrong tries for a while, not for good.** 5 per visitor and 20 per certificate
  (all visitors together), in windows of 15 minutes and one hour kept in transients; then nothing is answered,
  not even the right number. Before, 20 wrong tries from anyone turned the check off for that certificate
  forever. The visitor is the connection's address, hashed (the `evcert_visitor_address` filter is for sites
  behind a proxy). The uninstall removes the transients.
- **Messages between a request and its redirect travel in a per-user transient, not in the address**
  ("Correct and reissue" showed text from the URL).

## Consequences

- Every test class extends `Test_Case`, so its writes (options and transients too) are rolled back; some
  classes did not and left rows in the test site.
- `tests/php/LifecycleTest.php` runs these rules from the Events screen, the generator, the ticket page and a
  GatherPress import, in the scenarios where each exists.
