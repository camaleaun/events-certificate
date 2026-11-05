# 0006 — Identity documents are stored like passwords

**Status:** Accepted (2026-11-04)

## Question

Brazilian course certificates often carry the CPF to tell people with the same
name apart. How do we offer that without keeping a list of documents?

## Decision

The document is **optional**. Its number is stored like a password: an HMAC
with a site pepper, then Argon2id; the plugin never keeps the plain number. The
certificate shows a **mask that reveals at most 60 %** of the characters.
Anyone holding the document can confirm it matches; nobody can read it back.
Document types are registered in the admin (default "Other", open); none is
hard-coded for a country.

There is **no public listing** by name, username, e-mail or document: a
certificate is checked by its code only (LGPD/GDPR).

## Consequences

- A lost document cannot be recovered from the site; the person types it again.
- Leaked databases do not expose document numbers.
