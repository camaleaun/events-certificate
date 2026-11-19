# 0022 — The addresses of a certificate: the language and the PDF are path endpoints

**Status:** Accepted (2026-11-18)

## Context

A certificate's page took its language and its PDF as query args (`?evcert_lang=pt_BR`, `?format=pdf`). Path
endpoints are cleaner to share, to read in a QR code or an e-mail, and to cache. Testing the language switch also
showed that `page_url()` compared the language with `get_locale()` after the page had switched to it, so a visitor
who chose a language got the PDF button, the share link and `og:url` of the language it was issued in.

## Decision

- `/certificate/CODE/` is the certificate **in the language it was issued in**; `/certificate/CODE/pt-br/` another
  of the installed languages (the locale lowercased with a hyphen: `pt-br`, `de-de-formal`, `ast`); `/pdf/` the PDF,
  alone or after the language: `/certificate/CODE/pt-br/pdf/`.
- The rules (`Verify\add_rules()`) try the fixed `/pdf/` before a language segment, because a language slug would
  also match "pdf"; query vars are `evcert_lang` and `evcert_format` (not `format`, which WordPress uses). A
  language slug is mapped back with the installed locales (`Verify\locale_from_slug()`), never by rebuilding
  the case; one that is not installed is not found. The rules carry a version (`RULES_VERSION`) so existing sites
  rebuild them once.
- `Verify\page_url( $cert, $locale, $pdf )` and `pdf_url()` build the addresses and compare with the language the
  certificate was issued in, which fixes the bug above. The abilities (`pdf_url`) and the admin list use them.
- **The old addresses keep working**: `?format=pdf` and `?evcert_lang=` answer with a 301 to the path (links already
  printed or shared). Other args that belong to the page (`tix_attendee_id`, `tix_edit_token`) are kept; the form
  of "Check a certificate" (`evcert_code`) is unchanged.

## Consequences

- `tests/php/UrlsTest.php` routes the endpoints, round-trips the language segment and checks the 301s.
- Share images stay keyed by the real locale (`pt_BR`) in their REST call; the QR code is the bare address.
