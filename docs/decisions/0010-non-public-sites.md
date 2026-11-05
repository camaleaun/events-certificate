# 0010 — Sites that are not public print no verification

**Status:** Accepted (2026-11-04)

## Decision

On Playground, localhost and other non-public sites (detected by
`Environment\is_public()`, overridable with `evcert_is_public_site`), nobody
else can open the verification address, so certificates do not claim
authenticity: they leave out the QR code, the verification hint and any text
showing the verification address (revised 2026-11-05; at first a decorative
QR placeholder was drawn, which still looked checkable). The code stays, as
the certificate's reference. The editor always shows the placeholders, since
the design is made for when the site is public.

## Why

- A QR code pointing to `localhost` would look verifiable and fail for anyone
  else; a clear placeholder is honest and still useful for meetups printing
  from Playground.
