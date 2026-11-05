# 0001 — Certificates are frozen records; files are generated on demand

**Status:** Accepted (2026-11-04)

## Question

Should an issued certificate be a stored file (PDF, PNG) or data?

## Decision

A certificate is a **row** in `{prefix}evcert_certificates`, frozen when it is
issued: the participant's name, the event's name, place and dates, role,
workload, signer, the design version and the public code
(`YYMM-DDxx-xxxx-xxxx`, Crockford base32). Nothing reads live event data after
issuing, so editing an event or a design never changes a certificate already
given to someone.

The PDF, the HTML page and the share images are **generated when asked for**,
from the record and the design version it points to.

## Why

- Files go stale (a fixed typo means re-uploading), take disk space per person
  and are awkward to move between sites; a record is small and exports with the
  site.
- A correction is explicit: "correct and reissue" keeps the old code answering
  "replaced by …" instead of silently changing a document people already shared.
- The same record serves every output (PDF, page, image) and every language the
  site offers (see 0002, 0004).

## Consequences

- Generating a PDF costs 0.2–0.4 s (see 0002); batches (ZIP, e-mails) run in
  chunks.
- Designs are versioned: a certificate points to the version it was issued
  with.
- The public page `/certificate/<code>/` (slug configurable) is where anyone
  checks authenticity; it never lists certificates by person (see 0006).
