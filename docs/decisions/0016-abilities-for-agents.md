# 0016 — Abilities for AI agents and MCP

**Status:** Accepted (2026-11-11)

## Decision

The plugin registers WordPress Abilities (category `events-certificates`),
shown in REST (`/wp-abilities/v1`) and public to the MCP adapter
(`meta.mcp.public`), one per question or state transition:

- Reads: `get-overview` (start here: verifiable?, signer, counts, time zone,
  how to proceed), `get-events`, `get-participants`, `get-certificates`
  (also answers "is this code valid?"), `get-settings`.
- Writes: `create-event`, `update-event`, `add-participants` (list and/or
  CSV text), `set-participant-status`, `remove-participants`,
  `issue-certificates`, `revoke-certificate`, `quick-issue`,
  `update-settings`, and `import-gatherpress-event` when GatherPress is
  active.

Each has a description that says when to use it and which ability comes
next, full input and output schemas (enums, patterns, defaults), all three
annotations (readonly, destructive, idempotent; destructive ones run with
DELETE, as core requires) and the screens' capability. Certificates come
back with their verification page and PDF addresses.

Errors use codes an agent can act on, with HTTP statuses:
`evcert_missing_<field>` and `evcert_invalid_<field>` (400; 404 for an ID or
code that doesn't exist), `ability_invalid_input` from core's schema check,
401/403 from the permission check.

The abilities are thin adapters over the screens' code (Events, People,
Certificates\query(), Generator\run(), the GatherPress import), so both follow
the same rules.

## Why

- Organizers already ask assistants to "make certificates for last night's
  meetup": with MCP the assistant does it on the site, and the certificates
  stay verifiable.
- Semantic abilities (not one per REST verb) keep the tool list small and
  each choice obvious.
