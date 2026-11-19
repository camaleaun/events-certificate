# 0018 — Stylesheets and scripts: sources in SCSS and ES, built files in assets/css and assets/js

**Status:** Accepted (2026-11-17)

## Context

The plugin's CSS and JavaScript were written by hand in `assets/`, with no minified copy and no
difference between source and shipped file. Another plugin of the owner (quickknobcore) already has a
layout for this: Sass and ES sources, a small Node build, a readable and a minified copy of each output,
and PHP that picks one of them.

## Decision

- **Sources** (in git): `assets/scss/<name>.scss` (entries) with their partials in `assets/scss/<name>/_*.scss`,
  and `assets/es/<name>.js`. Partials keep the order of the rules, because where two rules have the same
  specificity the later one wins.
- **Built files** (not in git): `assets/css/<name>.css` + `.css.map` + `<name>.min.css`, and
  `assets/js/<name>.js` + `<name>.min.js` + `.min.js.map`, made by `bin/build-assets.mjs` (Sass and Terser) as
  part of `npm run build`.
- **Loading:** `includes/assets.php` (`Assets\src()`) returns the address and version; `.min` files load
  unless `SCRIPT_DEBUG` is on, and on a "local" environment the readable files load with the file time as
  version. A missing build is reported with `_doing_it_wrong()` on those environments.
- **Release:** `git archive` leaves `assets/scss`, `assets/es` (sources, in `.distignore`/`.gitattributes`) and
  the built folders out; the zip step runs `npm run build` and adds `assets/css`, `assets/js`, `build/`,
  `build-signer/` and `build-blocks/`. The zip keeps the readable copies next to the minified ones.
- **Not built:** `assets/pdf.css` (dompdf reads it by path), the bundled libraries in `assets/vendor/`, the
  `verify` block's own `style.css` and `view.js` (loaded by `block.json`, which picks `.min` itself), and the
  TypeScript of the editor (wp-build and esbuild, ADR 0017).

## Consequences

- A change of a partial changes the CSS only if the rule order does; the generator page was checked against
  the original page after the move.
- Building is a step of the release; the plugin does not work from a bare `git archive`.
