# bin

| File | What it does |
|---|---|
| `plugin-check.sh` | Runs Plugin Check on what ships (the files `.distignore` leaves out are left out of the check). |
| `package.sh` | Makes the release zip from HEAD: `git archive` plus the folders the build makes (`build*/`, `assets/css`, `assets/js`), without source maps. |
| `make-pot.sh` | Regenerates `languages/events-certificates.pot`, with the editor's strings from the built scripts. |
| `build-assets.mjs` | Sass and ES to `assets/css` and `assets/js` (see below). |
| `postbuild.mjs` | Run after `npm run build`: makes the PHP that `@wordpress/build` generates pass Plugin Check. |
| `strauss.phar` | Not in git. Copies dompdf and php-qrcode into `vendor-prefixed/` with the `EventsCertificate\Vendor\` prefix (see `docs/decisions/0003-prefixed-dependencies-strauss.md`). |

Get Strauss (once) and regenerate `vendor-prefixed/`:

```bash
curl -fsSL -o bin/strauss.phar https://github.com/BrianHenryIE/strauss/releases/latest/download/strauss.phar
composer prefix-namespaces
```

It is a phar rather than a Composer dev dependency because its current release
needs PHP 8 while the project's platform is PHP 7.4.

## build-assets.mjs

Builds the plugin's own stylesheets and scripts (not the TypeScript of the editor, which wp-build, `build-signer.mjs`
and `build-blocks.mjs` build):

- `assets/scss/<name>.scss` (entries; a folder per entry holds its partials, `@use 'quick-generator/tokens'`) becomes
  `assets/css/<name>.css` (readable, with a source map) and `<name>.min.css`.
- `assets/es/<name>.js` becomes `assets/js/<name>.js` (a readable copy) and `<name>.min.js` (with a map).
- `npm run assets` builds them, `npm start` rebuilds when a source changes, `npm run build` does everything.
- `assets/css` and `assets/js` are generated and not in git; `includes/assets.php` loads the `.min` files, or the
  readable ones with `SCRIPT_DEBUG` and on a "local" environment.
- `assets/pdf.css` is not built: dompdf reads that file by path.


## Tests

`composer test` runs PHPUnit against the WordPress site the plugin is installed in (inside a transaction rolled
back after each test). The plugin behaves differently with CampTix and GatherPress, so the suite also runs in four
scenarios (`docs/decisions/0019-integrations-and-test-scenarios.md`):

```bash
composer test:none          # neither integration
composer test:camptix       # only CampTix
composer test:gatherpress   # only GatherPress
composer test:both          # both
composer test:all           # the four; composer check runs this
EVCERT_INTEGRATIONS=both vendor/bin/phpunit --filter LifecycleTest
```

The plugins `camptix` and `gatherpress` must be installed in the test site; a run aborts if it did not get the
integrations it asked for. `EVCERT_UPDATE_SNAPSHOTS=1 composer test` rewrites the markup snapshots in
`tests/php/snapshots/` (review the diff before committing).

`npm run test:js` runs the Node tests (`tests/js/`): the public generator's drawing of the default design against a
backend that records its calls.
