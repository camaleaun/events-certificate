# 0001 — Certificates are frozen records; files are generated on demand

**Status:** Accepted (2026-11-04)

## Question

Should an issued certificate be a stored file (PDF, PNG) or data?

## Decision

A certificate is a **row** in `{prefix}evcert_certificates`, frozen when it is
issued: the participant's name, the event's name, place and dates, role,
workload, signer and the public code (`YYMM-DDxx-xxxx-xxxx`, Crockford
base32). Nothing reads live event data after issuing, so editing an event
never changes what a certificate already given to someone says.

The **look** is not frozen: certificates are drawn with the current site
design (revised 2026-11-05, see below). The design version a certificate was
issued with is still kept (`design_hash`) as history, and drawn only if the
site design is gone.

The PDF, the HTML page and the share images are **generated when asked for**,
from the record and the current design.

## Why

- Files go stale (a fixed typo means re-uploading), take disk space per person
  and are awkward to move between sites; a record is small and exports with the
  site.
- A correction is explicit: "correct and reissue" keeps the old code answering
  "replaced by …" instead of silently changing a document people already shared.
- The same record serves every output (PDF, page, image) and every language the
  site offers (see 0002, 0004).

## Revision (2026-11-05): the design follows

At first the design version was frozen too. In practice organizers adjust the
design after the first certificates go out (a logo, a date format) and expect
every certificate to show it; reissuing each one just for its look would give
everyone a new code. What must not change silently is the content — who, what
event, how many hours, who signed, the code — and that stays in the row. The
words of the standard texts already follow the page's language the same way.
Share images are named after the current look, so a design change makes them
be drawn again.

## Consequences

- Generating a PDF costs 0.2–0.4 s (see 0002); batches (ZIP, e-mails) run in
  chunks.
- A design change shows on every certificate, issued or not; the version
  each was issued with stays recorded.
- The public page `/certificate/<code>/` (slug configurable) is where anyone
  checks authenticity; it never lists certificates by person (see 0006).
