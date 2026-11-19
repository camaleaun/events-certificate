# 0017 — The generator: one page, one certificate look, libraries bundled

**Status:** Accepted (2026-11-17)

## Context

The meetup certificate generator by Pamela Ribeiro (with Débora Venâncio de Oliveira and Sandra Peres) was
a single HTML file that runs in the browser. The plugin brings it in as part of 0.71, next to the
verifiable certificates, with the same look on its front-end page, in the admin and in the PDF.

## Decision

- **A page of the site.** A page (`/certificate-generator/`, `/gerador-certificados/` in Brazilian Portuguese)
  is made on the first admin request, once; deleting it doesn't bring it back, and uninstalling with
  "delete all data" removes it. A bare template (`templates/quick-generator-page.php`) prints only the page's
  content, with no theme chrome.
- **Content in blocks.** The page is core blocks (hero, a Details block for the tutorial, layout groups) and
  this plugin's smaller blocks (`event-fields` with `event-info`, `event-schedule`, `event-attrs`,
  `signature`, `people-list`, `preview`). The texts and pictures can be edited in the block editor. The
  one-block version (`quick-generator`) still exists for pages that want it. The page is made once (when no integration is active) and never rewritten: its
  content is the site's own from then on.
- **Any theme.** The block markup is the one source of the page: `[evcert_quick_generator]` (and the one-block version) render
  it with `do_blocks()`, the way WooCommerce has a classic and a block cart, except that here the classic one is not a PHP
  template. The heading is the page's title (a Post Title block, "Certificates of participation" as in the original
  page) only on the generator page; elsewhere the theme prints the title. The page template is a block template
  (`register_block_template()`) in a block theme and a listed page template in a classic one (the theme can have its own,
  `evcert-quick-generator.php`), kept in `_wp_page_template`. In a classic theme the inner container WordPress puts in
  groups is left out of the app's, and the canvas styles of the editor come through `block_editor_settings_all`, which
  reaches classic themes (a theme.json style does not). Taken from the axellcore-atelier plugin's approach.
- **The look.** The layout, colors, fonts and tutorial are those of the original page, to the pixel
  (0 different pixels against it in Brazilian Portuguese). Its CSS is scoped under `.evcert-app` with a
  theme isolation block, so it looks the same on any theme. Tutorial pictures are files of the plugin by
  language: `assets/images/tutorial/pt-br/passo-*.jpg` (the names of the original repository) and
  `assets/images/tutorial/step-*.jpg` in English.
- **Libraries bundled, no CDN.** jsPDF, JSZip and SheetJS are in `assets/vendor/` with their licenses; the
  page loads nothing from other servers. Fonts are IBM Plex Sans and Instrument Serif subsets (OFL).
- **One certificate.** The Base design (`templates/base-design.json`) is the generator's certificate: the
  side band with its text turned 90°, title, name, event, details and signature. The admin Generator screen
  draws the site's design with `Render\artwork()`, and the PDF is that design. The browser app draws the
  same layout on a canvas. For the generator's fonts the PDF moves text up by the difference between
  dompdf's first baseline and the browser's (measured), so both place the first line alike.
- **Editor tools.** The blocks and the admin form are TSX built to `build-blocks/` with esbuild and
  wp-build's WordPress externals (no `wp.element` by hand); the form uses the editor's own components.
- **Colors in the editor.** A theme.json filter adds the generator's colors (`evcert-*`) as presets, only
  on the generator page, so they can be recolored there; the stylesheet reads them in light mode.

## Consequences

- The page's own texts are stored in its content in the site's language when it is made.
- Sites that made the page before 0.71 keep their content; only the one-block page is composed.
- Existing designs keep their own PDFs: the baseline fix is only for the generator's fonts.
- A new font for the editor needs an entry in `Render\FONTS`, in the editor's font list and, to be
  placed like the browser does, a measurement like `pdf_baseline_shift()`.

## Addendum (2026-11-18): the generator draws the default design

The public generator no longer has a layout of its own: `src/quick-generator/design.ts` draws the site's default
design (`Designs\get( Designs\site_design_id() )`, the same JSON the server draws, passed in
`window.evcertQuickGenerator.design` with `Render\phrases()` and the site's date and time formats) on the two
backends it already had, canvas and jsPDF. Shapes the backends can't draw are rasterized once. The parts that
vouch for a record (QR code, code, hint) are not drawn, since the generator keeps no record. The community's logo
goes in the top right corner when the design has no place for it, and the form's signature fills the design's
signature box. The configuration is made when the app is enqueued, not at registration. `npm run test:js`
runs the drawing against a recording backend.
