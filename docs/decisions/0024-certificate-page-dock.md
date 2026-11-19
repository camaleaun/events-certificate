# 0024 — The certificate page's Dock: welded to the edge, draggable, folds into a corner

**Status:** Accepted (2026-11-18)

## Context

The tools of the certificate page (PDF, share, language, light/dark, details) float in a Dock modeled on WordPress
Playground's. Its source (`packages/playground/website/src/components/dock`) was read to learn how it is built, styled
and moved: a fixed `nav` centred with `left: 50%` and `translateX(-50%)`, `--dock-center` once dragged; a drag armed on
the bar's own surface (not on its controls) with pointer capture, a 4 px threshold, sideways only, the centre kept 8 px
from the edges; past an edge by 36 px a preview (scale 0.8 toward the corner), and on release a fold of 0.34 s into a
46 px button 16 px from the corner; the button unfolds on click and, dragged out, brings the bar back under the pointer.

## Decision

- The same behaviour, in vanilla JS (`assets/es/verify.js`) and CSS (`assets/scss/verify/_dock.scss`, `_docked.scss`):
  drag, preview, fold, unfold and pull-out; the click that ends a drag is swallowed for 250 ms; no motion when the visitor
  asks for less (`prefers-reduced-motion`); a timer finishes the fold when the browser does not run the animation
  (a tab in the background).
- The dragged position is kept as a fraction of the window width (`evcert-dock-x`) so it survives a resize; the
  docked corner is kept as before (`evcert-dock`).
- Up to a tablet (1024 px) the bar spans the screen and does not drag. The two corner buttons of the bar stay: they
  are the way to dock by keyboard.

- **The grab sheen** (Playground's `dock-sheen`): a light that pools along the bar's top edge under the pointer, with a
  bright hairline on the very edge, invisible at rest. It answers when the pointer comes near (softer over a control),
  follows it while dragging and goes when it leaves; `--sheen-x` and `--sheen-o`, written by `verify.js`. Not on a narrow
  screen or while docked in a corner.

## Consequences

- Behaviour was checked in the browser with pointer events (threshold, preview, fold, unfold, pull-out, persistence,
  narrow screen); there is no automated test for it.
