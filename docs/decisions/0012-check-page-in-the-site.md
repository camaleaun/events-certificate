# 0012 — "Check a certificate" lives in a page of the site

**Status:** Accepted (2026-11-10)

## Decision

The form to open a certificate by its code is a block,
`events-certificate/verify` (and the `[evcert_verify]` shortcode, which draws
the same block), placed in a regular page with the theme's header and footer.
The plugin makes that page once ("Certificate", at the certificates' slug)
and remembers it (`evcert_verify_page`, chosen in Settings). The page's slug
is the certificates' slug: renaming the page moves the certificates' address.

Only a certificate itself (`<slug>/<code>/`) keeps the plugin's full-screen
page (decision 0011's dock, light/dark, PDF, sharing). Without a page, the
plugin's own card stays as the fallback.

The form sends `?evcert_code=`; a code on record opens its certificate
(`Code\normalize()` accepts lowercase, no dashes, spaces and O/I/L), anything
else comes back to the form with a message and what was typed. The field
shows what is typed as `XXXX-XXXX-XXXX-XXXX` in capitals (a small, optional
script module); the server decides.

## Why

- Checking a certificate is part of the event's site: people arrive from the
  site's menu and should see the site, not a bare page.
- CampTix's markup gives it the same look as the ticket forms on WordCamp
  sites; the block's own small stylesheet keeps it usable without CampTix.
- A block lets organizers add text around it, and the shortcode serves
  classic pages and CampTix's own pages.

## Consequences

- The bare slug is no longer a rewrite rule of the plugin while the page
  exists; `/certificate/` and `/verify/` without a code redirect to the page.
- An unknown code at `<slug>/<code>/` goes to the page with the code filled in,
  instead of a 404 page.
