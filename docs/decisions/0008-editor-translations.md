# 0008 — The editor's translations are handed to wp.i18n by the plugin

**Status:** Accepted (2026-11-05)

## Question

The editor (built with `@wordpress/build`) is a script module the page loads
when it routes to it. WordPress 7.0 prints module translations only for
**enqueued** modules and their dependencies, so the editor stayed in English on
translated sites.

## Decision

`includes/admin-app.php` reads the JSON translation file WordPress would look
for — `languages/plugins/events-certificate-{locale}-{md5("build/routes/editor/content.js")}.json`,
the file a language pack ships — and gives it to `wp.i18n.setLocaleData()`
before the editor loads (the module uses the global `wp.i18n`).

Strings stay in English in the code; `languages/events-certificate.pot`
includes the editor's strings (extracted from the unminified build).
Translations come from translate.wordpress.org once the plugin is there; until
then local files live in `wp-content/languages/plugins/`.

## Consequences

- If `@wordpress/build` or WordPress start printing route module translations
  themselves, this function can go.
- The file name depends on the build path; changing the route name changes it.
