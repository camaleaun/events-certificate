# 0011 — The page says "on record", not "authentic"

**Status:** Accepted (2026-11-05)

## Question

What does the certificate page claim when someone opens a valid code?

## Decision

It says the certificate is **on record** ("Registrado" / "Certificate on
record") and asks whoever checks to **compare the copy they have with the
page**: name, event, dates and code must be exactly the ones shown. It never
says "authentic".

## Why

A valid code proves that the record exists, not that a printed or forwarded
copy is unaltered: someone can change the name on a copy and keep a real QR
code, and a page saying "authentic" would back the forgery. The check is a
visual comparison by the person checking; the page shows the issued data
(name, event, workload, issue date, code) and the artwork as issued.

## Consequences

- Replaced and revoked codes say so, with a link to the corrected certificate.
- Nothing in the PDF or the page should suggest that scanning alone proves a copy.
