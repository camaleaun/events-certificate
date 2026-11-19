# 0028 — Verification (code and QR code) is off by default

**Status:** Accepted (2026-11-18). Complements 0010 (non-public sites) and 0027.

## Context

Checking a certificate (a code and a QR code printed on it, and a public page that confirms it) is a custom of some
countries, such as Brazil. Other markets do not expect it, and an unverifiable certificate is the normal one there.

## Decision

- **Verification is off by default.** Settings has a box "Verification" (`verification`, false by default); the filter
  `evcert_verification_enabled` also turns it on. `Render\verifiable()` is true only when it is on **and** the site is
  public (0010).
- Off: certificates carry no QR code, code, verification address or hint (the elements that only serve verification are
  left out, as on a site that is not public). The pages of a certificate (`/certificate/CODE/`, the PDF) still work for
  whoever has the link; the "Check a certificate" block and the document check are tools for a site that turns it on.
- **No code, no revoking, no replacing.** Off, no screen shows a code: the Certificates list has no Code column and no
  checkboxes, the participants' column says "View" instead of the code, the ticket page does not show it, and the
  Revoke action and ability are not there. A correction **edits the certificate where it is** (same record, same code
  kept inside as the key of its address; `Certificates\edit_in_place()`), with no replaced or revoked state. Certificates
  already replaced or revoked keep their state.
- The settings notice says which: "Verification is off…", or the usual public-site explanation when it is on.

## Consequences

- The default certificate is cleaner and works everywhere; a site that wants authenticity ticks one box.
- The tests run with the filter on (the base class), and `VerificationFlagTest` covers the default.
