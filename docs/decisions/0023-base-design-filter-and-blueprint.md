# 0023 — The Base design: flush, neutral, filterable; a Playground blueprint gives the meetup wording

**Status:** Accepted (2026-11-18)

## Context

The Base design (`templates/base-design.json`) is the certificate a site starts from. It said "WORDPRESS MEETUP" on
its band and "took part in the meetup" for every event, which suits one community and not a plugin for any event.
In the editor, elements flush with the page edge (the band at x = 0) looked offset: the Base had a 3 mm bleed, the
editor drew the sheet (background and shadow) around the bleed, and the PDF, which ignores bleed, was flush.
Networks such as WordCamp.org or WordPress.org multisites also need to say which certificate their sites start from.

## Decision

- **No bleed** in the Base (`page.bleed` 0; the safe margin stays a guide). The editor draws the sheet, its background
  and its shadow on the page itself, and what bleeds outside it is drawn outside.
- **No default wording about meetups**: the band text is empty (`phrases()['band_text']`), the text before the event
  is "took part in", the placeholders say "Event title".
- **`evcert_base_design` filters the Base** (`Designs\base()`): a design with a `page` and `elements`, anything else is
  ignored. It decides the design of a site that has none yet (`site_design_id()`, made the first time it is
  used, so on a network each site gets it when first used), what "New template" copies, and what the editor's
  "Restore the Base design" and import start from (the page gives the filtered Base to the editor as
  `window.evcertEditor.base`). A site that already has a design keeps it.
- **A Playground blueprint** (`.wordpress-org/blueprints/blueprint.json`, which wordpress.org reads) installs the plugin
  and writes a mu-plugin that uses the filter to put "WORDPRESS MEETUP" and "took part in the meetup" back, and
  lands on the Generator. `BaseDesignTest` runs that mu-plugin and checks the wording reaches the certificate.

## Consequences

- Designs saved before keep what they had (an 8.5 pt bleed); "Restore the Base design" gives the new one.
- The blueprint installs from wordpress.org by slug, so it works once the plugin is published.
