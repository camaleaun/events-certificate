# 0003 — Bundled libraries are prefixed with Strauss and committed

**Status:** Accepted (2026-11-05)

## Question

How do we ship dompdf and php-qrcode without clashing with other plugins that
load their own (possibly different) versions of the same libraries?

## Decision

[Strauss](https://github.com/BrianHenryIE/strauss) copies the runtime packages
into **`vendor-prefixed/`**, renaming their namespaces to
**`EventsCertificate\Vendor\…`** (classmaps `EventsCertificate_Vendor_`,
constants `EVCERT_VENDOR_`). The plugin loads only
`vendor-prefixed/autoload.php`; `vendor/` stays a development folder (PHPCS,
PHPUnit) and is not shipped.

`vendor-prefixed/` is **committed**: a clone of the repository (or a Playground
blueprint pointing at GitHub) works without running Composer or Strauss.

Regenerate after changing a library version:

```bash
composer update dompdf/dompdf chillerlan/php-qrcode
composer prefix-namespaces   # php bin/strauss.phar (see bin/README.md)
```

## Why

- Two plugins loading `Dompdf\Dompdf` from different versions break each other
  in ways that depend on load order. Prefixing removes the shared names.
- Strauss is installed as a phar, not a Composer dev dependency: its current
  release needs PHP 8 (flysystem 3) while the project's platform is PHP 7.4.
- Committing the generated folder trades ~4 MB in the repository for a plugin
  that runs from a plain clone.

## Consequences

- `vendor-prefixed/` is excluded from lint, PHPCS and Plugin Check (third-party
  code with its own licenses, which Strauss keeps next to each package).
- dompdf's DejaVu fonts are excluded from the copy (`extra.strauss.exclude_from_copy`).
- php-qrcode 4.x uses output types like `QRCode::OUTPUT_MARKUP_SVG` (`svg`);
  the 5.x names (`markup-svg`) do not apply.
