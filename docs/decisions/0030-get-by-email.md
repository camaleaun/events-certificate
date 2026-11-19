# 0030 — "Get my certificate": the e-mail, a proof of it and a page of its own

**Status:** Accepted (2026-11-19). Complements 0012 (the check page) and 0026.

## Context

Attendees got their certificate only from the link in the CampTix ticket e-mail or from the organizers. The two generators
this plugin descends from (WP Brasil's `gerador-de-certificados` and `certificates-for-wordcamp`) did it the way people
expect: type the e-mail you registered with, get the certificate. Typing an e-mail proves nothing, so the way it is proven
has to be a choice of the site, not a fixed rule.

## Decision

- **A block, a shortcode and a page.** `events-certificates/mine` (server rendered), `[evcert_mine]` (it renders the block),
  and a page at `/certificates/` (pt_BR `/certificados/`, translatable slug) made once like the check page
  (`evcert_mine_page`). With CampTix the form uses its markup (`#tix`, `tix_tickets_table`, `tix-submit`).
- **Who can:** the e-mail is on record (a CampTix ticket, a participant of an event) **and** the person was marked present
  (CampTix: the same rule as the ticket page, `self_issue`; plugin: `status = attended`). Otherwise a message.
- **How the e-mail is proven** (setting `email_auth`, filter `evcert_email_auth`): none; **simple (default)**: the e-mail
  shows a simplified view (name, event, role; no certificate, PDF, QR code or code) and the certificate asks to
  authenticate; a 4 or 6-digit code; link only. `email_auth_link` sends the link with the digits too.
- **The proof:** a code is kept as an HMAC, good for 15 minutes and 5 tries; a link works once for an hour. Proven, the
  e-mail becomes a signed cookie (HMAC with the site's salt, 12 hours, no WordPress user). Asking for mail is limited
  (5 per e-mail and address in 15 minutes) and the form has a nonce.
- **No list leaking:** with the modes that mail something the answer is the same whether the e-mail is on the list or not.
  Only "none" and "simple" say "not found" and "not present", as the owner asked.
- **The mail** is CampTix's (`$camptix->wp_mail()`) when it is active, `wp_mail()` otherwise; `evcert_mine_email` filters it.
- **After proving,** the person gets their certificates: CampTix tickets go to the ticket's certificate step (with its
  edit token), participants of an event get the certificate issued and opened.

## Consequences

- A site that wants the old behavior (anyone with the e-mail) picks "No authentication"; the default is the safe one.
- The texts come from the two WP Brasil generators; they are in English in the code and translated in pt_BR.
- **The simplified view** is the certificate page itself (the artwork with name, event and role; no code, hours, PDF,
  share or details) for the participants of an event, opened from the list with a key only the list can give (an HMAC:
  a nonce is the same for everyone logged out, and person IDs are sequential). Its Dock has **Authenticate**, which
  mails the code or link; proven, the person goes on to the certificate. CampTix tickets keep their own link and token.
- Certificates of the same person found by e-mail alone (without an event record) are not part of this version.
