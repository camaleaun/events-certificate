# 0010 — Sites that are not public show a QR placeholder

**Status:** Accepted (2026-11-04)

## Decision

On Playground, localhost and other non-public sites (detected by
`Environment\is_public()`, overridable with `evcert_is_public_site`), nobody
else can open the verification address, so certificates do not claim
authenticity: the QR code is replaced by a decorative placeholder
(`assets/qr-placeholder.svg`) and a text explains the limitation. The editor
always shows the same placeholder where each certificate gets its real QR code.

## Why

- A QR code pointing to `localhost` would look verifiable and fail for anyone
  else; a clear placeholder is honest and still useful for meetups printing
  from Playground.
