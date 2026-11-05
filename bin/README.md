# bin

| File | What it does |
|---|---|
| `plugin-check.sh` | Runs Plugin Check on what ships (the files `.distignore` leaves out are left out of the check). |
| `postbuild.mjs` | Run after `npm run build`: makes the PHP that `@wordpress/build` generates pass Plugin Check. |
| `strauss.phar` | Not in git. Copies dompdf and php-qrcode into `vendor-prefixed/` with the `EventsCertificate\Vendor\` prefix (see `docs/decisions/0003-prefixed-dependencies-strauss.md`). |

Get Strauss (once) and regenerate `vendor-prefixed/`:

```bash
curl -fsSL -o bin/strauss.phar https://github.com/BrianHenryIE/strauss/releases/latest/download/strauss.phar
composer prefix-namespaces
```

It is a phar rather than a Composer dev dependency because its current release
needs PHP 8 while the project's platform is PHP 7.4.
