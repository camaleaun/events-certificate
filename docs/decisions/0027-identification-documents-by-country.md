# 0027 — Identification documents by country, chosen in Settings

**Status:** Accepted (2026-11-18); the choice of countries as a list is replaced by one country and a foreigner box in 0029. Replaces the "types depend on the country" part of 0006 (the storage, the mask and the
check stay as 0006 says).

## Context

The declaration page asked for a document from any country of the ISO list: the CPF for Brazil, a generic "Document" for
the other 248. Settings said nothing about it, and the default left everything open.

## Decision

- **No country by default.** Settings has a list of countries (only the ones that have a document); the site ticks the
  ones it accepts (`document_countries`, empty by default). With none ticked the declaration page asks for no document and
  its statement does not mention one.
- **Each country has one document with a rule**, in `Documents\catalog()` (filter `evcert_document_catalog`): Brazil the
  CPF and nothing else; Argentina DNI; Chile RUT; Colombia Cédula; Spain DNI/NIE; Mexico CURP; Peru DNI; Portugal NIF;
  Uruguay CI. Only documents with a format and, where the country has one, a check digit or letter. The generic
  "Document" is no longer offered (it can come back through the filters).
- **Only Brazil is offered by default.** The other countries' documents stay in the code (`Documents\known()`) behind a
  flag: the option `evcert_more_document_countries` (no screen: WP-CLI) or the filter `evcert_more_document_countries`
  turn them on. Certificates already issued keep their label and mask whatever is on (they go by type, from `known()`).
- **The whole declaration is off by default**, document and public check included: a custom of some countries, such as
  Brazil. Settings has a box "Declaration page" (`declaration`, false by default; the filter `evcert_declaration_enabled`
  also turns it on). Off, the ticket step has no second page and ignores one that is posted; the certificate's own
  verification (code, QR code, public page) is not affected. The check of a document on the public page shows only for a
  certificate that holds one.
- **Countries are the ones with a document** (`Countries\all()`, filter `evcert_countries`), not the 249 of ISO.
- **A foreigner gives a passport** in any accepted country, Brazil included: a box on the ticket step switches the
  document to `passport` (6 to 9 letters and digits, no mask).
- The ticket step shows no country row when the site accepts one country, a select of the accepted ones when it accepts
  several, and nothing when it accepts none. The public check field takes the typing mask of the document's type.
- Certificates already issued keep their `doc_type`, hash and mask: the label and the check go by type.

## Consequences

- A site that wants the declaration's document has to tick its countries once; nothing is asked until then.
- Adding a country is a catalogue entry (type, label, example, mask) and its rule in `Documents\valid()`.
