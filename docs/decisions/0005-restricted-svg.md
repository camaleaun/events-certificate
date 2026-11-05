# 0005 — SVG is kept as fields, never as markup

**Status:** Accepted (2026-11-05)

## Question

Logos, ornaments and signatures need vector art. How much SVG do we accept?

## Decision

An SVG element is **fields**: a viewBox size and a list of paths, each with its
path data (`d`, only path commands and numbers), a fill (a palette color, a
hex/rgb color, or none = the element's current color) and an optional even-odd
fill rule. No stroke, no other shapes, no groups, styles, scripts, links,
transforms or embedded content.

Pasting SVG code into a path field reads it into those fields (groups are
unwrapped, their fill passed to their paths) and keeps nothing else. There is
no code editor. Art with strokes is converted to filled outlines before
importing (for the Campus Connect assets we used Inkscape's "Stroke to Path").

## Why

- Markup is an injection surface (scripts, external references, CSS) on a page
  anyone can open; fields can be validated one by one, in the editor and again
  on the server.
- Strokes scale differently in the browser and in dompdf; filled outlines look
  the same everywhere.
- Colors as palette references follow the light/dark modes.

## Consequences

- Designs saved with SVG markup are migrated to fields when they load.
- Raster images stay possible (PNG, JPEG, GIF from the Media Library or a URL)
  for photos and logos without vector versions.
