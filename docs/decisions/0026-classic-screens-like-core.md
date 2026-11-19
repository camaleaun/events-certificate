# 0026 — Classic admin screens that look like core's

**Status:** Accepted (2026-11-18). Complements 0007 (no DataViews: the screens stay classic).

## Context

The screens the plugin made by hand (a list of people with tokens inline, an event form with its participants and an
"add" form on the same page, chips as filters) looked unlike WordPress. The owner asked for the lists to look like the
posts and pages list, and said what not to mix.

## Decision

- **A list looks like `edit.php`:** views (`subsubsub`) with counts, a search box, filters as selects with a Filter
  button in the table navigation, bulk actions above and below, a name column with row actions and a state beside the
  name ("— Revoked", like "— Draft"), a date column that says the state and the date ("Issued", then the date and time),
  sortable columns, Screen Options for the page size.
- **A role is shown like a taxonomy term:** a link that filters the list, a dash when there is none.
- **A wide list is not on the same screen as a form** (like `edit.php` and `post.php`): an event has four screens of
  one page (`page=evcert-events`): the list of events, its form (`&event=ID`, built like `post.php`: the name above,
  boxes, Save on the side), its participants (`&view=participants`) and the form to add them (`&view=add`, with the
  imports of the active integrations as a section). A narrow list may sit beside its form, like `edit-tags.php`.
- **Settings are not on a list's screen:** a screen of their own with tabs (Phase 5 of the plan).
- Actions that change something end in a redirect to the screen that shows the result (an action on a participant goes
  back to the participants, a saved event stays on its form, a new event goes on to its participants).
- No inline `onclick` or `style`; a small enqueued script reads `data-evcert-confirm`.

## Consequences

- Links to an event's participants (the Certificates list, the abilities' `admin_url`, the integrations' imports) use
  `EventsAdmin\participants_url()`.
- The people list's role tags and the "+ Role" select are the next step (the roles as links and a select).
