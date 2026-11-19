# 0029 — The settings are organized by feature, and the document is one country

**Status:** Accepted (2026-11-18). Complements 0027 and 0028.

## Context

The things that are customs of some countries (verification with a code and a QR code, the declaration page with an
identification document, self-issuing) were switches scattered over the settings, among old fields.

## Decision

- **Features first.** The settings open with a "Features" section with the switches (verification; with CampTix,
  self-issuing and the declaration page), each off by default and said in a line. Then Issuing (signer, place, date),
  Verification (check page, address, public site), the Identification document and the Event days (CampTix), and Data.
- **The rows that need a feature are dimmed and inert while it is off** (changed 2026-11-19: hiding made options look lost) (`evcert-needs-verification`, `evcert-needs-declaration`,
  a small script; without it everything shows). The own page of the plugin has tabs, one per group; CampTix's and
  GatherPress's tabs show the sections one after the other. Every section is on the form, so saving keeps them all.
- **Better fields:** the signature can be chosen from an SVG file (and is shown before saving) or pasted; the date of
  issue is two radios, each said; the days are cards, not a wide table.
- **The identification document is one country**, chosen in a select (none by default), and a box lets attendees say
  they are "Not from the event's country" (pt_BR: "Sou estrangeiro"): they give a passport, or nothing at all
  (`document_country`, `document_foreigner`). It replaces the list of countries of 0027.

## Consequences

- One place to turn the Brazilian-style features on; nothing is on until then.
- A foreigner who gives nothing leaves a certificate with no document (the declaration does not mention one).
