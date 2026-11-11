# 0014 — Declaration page: declared activities, document in full only when issuing

**Status:** Accepted (2026-11-11)

## Decision

On the certificate step, attendees declare what they did on the days they
were marked present (decision on days: `includes/days.php`): the table on a
tables day, the talks on a talks day. Two chosen talks can't overlap, even in
part (back to back is fine); the browser disables clashing talks and the
server refuses them. The workload is **self-declared**: a tables day counts
from its first item to its last, a talks day the chosen talks.

They can add a **second page**: a standard declaration (not a design) with
their name, identity document, the event, the days, the workload and the
activities, in the template's fonts and colors. The document depends on the
country (a translatable list of countries, like WooCommerce's): Brazil's is
the CPF, checked by its digits.

The document is stored like a password (decision 0006). Its **full number is
printed only in the PDF downloaded right when issuing**, and only when the
person ticks that showing it is their responsibility; the page and every
later PDF show the mask. Anyone holding the document can type it on the
certificate page (Details) to confirm it matches; wrong tries are counted and
stop after 20.

The declaration page **always** carries the QR code, the code and the hint
(on sites others can reach, decision 0010). On the first page they become
optional: a template can leave them to the declaration page when there is
one ("Verification: on the declaration page when there is one").
A table is shown with its time (the day's first to last item).

## Why

- Brazilian course certificates are expected to carry the document and the
  workload; event organizers can't vouch for which talks someone attended,
  so the workload is declared by the person and says so.
- Printing the full number is the person's choice and responsibility; the
  site never keeps a readable copy (LGPD).

## Consequences

- `data_json` keeps the days, tables, talks (title, time, speakers), country
  and whether there is a second page; the certificate keeps `doc_type`,
  `doc_label`, `doc_masked`, `doc_hash`.
- The PDF is two pages when the declaration was chosen.
