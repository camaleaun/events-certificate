# 0021 — The language of a certificate

**Status:** Accepted (2026-11-18)

## Context

A certificate records the language it was issued in (`certificates.locale`, the site's language at that
moment), but nothing read it: the public page used the site's language at the time of the visit, and the
admin's downloads (a PDF, a ZIP) the language of whoever clicked. A Portuguese certificate downloaded by an
English-speaking admin came out in English, and dates mixed the site's format with another language's
month names ("18 de November de 2026").

## Decision

- **A certificate is a document: it is shown in the language it was issued in**, on its page and in every PDF
  (the admin's downloads, the ZIP, the ticket page's PDF, the public PDF). `Render\pdf_body()` switches to
  `Render\certificate_locale()` for each certificate, so one PDF may hold certificates in different languages.
  When that language is no longer installed, the site's is used.
- **The visitor may switch** to any installed language (`/certificate/CODE/pt-br/`, ADR 0022); the PDF button follows the choice.
- **Dates and times are the site's** (`includes/dates.php`): the formats of Settings → General, like any date
  WordPress shows, with the month and day names of the language shown. The plugin has **no translatable date or
  time formats** (an earlier version had them: GlotPress refuses a translation that changes the case of a
  format letter, "g" to "G", and a format is the site's choice, not the language's). A site that wants Portuguese
  clocks ("19h30") sets its time format to `G\hi`. The date fields of a design that ask for long, medium or
  short use ICU (`IntlDateFormatter`) in the language shown.
- **Everything the certificate says is translatable**, with the order of the words left to the translator:
  the place with its country or date, a day's heading, the hours and minutes (plurals), the label of the
  document type (rendered from its type, not the label stored at issue), the page's titles and tool labels.
- **A test renders a Portuguese certificate from English** (`tests/php/I18nTest.php`) and fails if a string of
  the certificate comes out in English; it needs the pt_BR translation installed and skips without it.

## Consequences

- New text on a certificate goes through `__()`/`_n()`/`_x()` with a translator comment, and into `I18nTest`.
- `bin/make-pot.sh` regenerates the template, including the editor's strings from the built scripts.
