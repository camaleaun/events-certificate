# 0004 — One design JSON in points

**Status:** Accepted (2026-11-04)

## Decision

A design is one JSON document (stored in the `evcert_design` post type's
content): the page (size in points, background, safe margin, bleed), a color
palette with a **light and a dark** value per color, preview sample data, and a
list of elements (text with runs and fields, rectangles with corner shapes,
ellipses, lines, images, SVG, the authenticity QR). Positions are in points;
elements may be **anchored** (start / center / end / stretch, relative to the
page or the safe margin) with lengths in pt, mm, cm, in, % or `calc()`.

The editor draws the JSON as SVG/HTML; the server renders the same JSON to
HTML for dompdf (0002). Export and import use the same JSON, so a design moves
between sites as one file (a Campus Connect design is kept outside the plugin
because of the WordPress trademarks).

## Why

- One source of truth for screen and PDF avoids two renderers drifting.
- Points match print; anchors make the same design adapt to other page sizes
  and to screens (0009).
- Text is runs (text and fields, with bold/italic), never strings with braces:
  fields keep their formats (dates follow the site format by default) and the
  editor shows them as chips.

## Consequences

- The Base design ships as `templates/base-design.json`, its texts translated
  when a design starts from it. The name, the QR code, the code and the
  verification hint are required: designs that lack them get them added.
