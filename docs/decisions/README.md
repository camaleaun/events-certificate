# Decisions

Why Events Certificates is built the way it is. One file per decision: the
question, what was chosen, what was left out and why, and what follows from it.
The longer plan (in Portuguese) is [`../PLAN.md`](../PLAN.md); spikes with
measurements are in [`../spikes/`](../spikes/).

| # | Decision | Status |
|---|---|---|
| [0001](0001-certificates-are-records.md) | Certificates are frozen records; PDFs and images are generated on demand | Accepted |
| [0002](0002-pdf-engine-dompdf.md) | PDF engine: dompdf, with php-qrcode for the QR code | Accepted |
| [0003](0003-prefixed-dependencies-strauss.md) | Bundled libraries are prefixed with Strauss and committed | Accepted |
| [0004](0004-design-model.md) | One design JSON in points, rendered to SVG/HTML on screen and to PDF | Accepted |
| [0005](0005-restricted-svg.md) | SVG is kept as fields (viewBox + filled paths), never as markup | Accepted |
| [0006](0006-identity-documents-as-passwords.md) | Identity documents are stored like passwords and shown masked | Accepted |
| [0007](0007-admin-ui-gutenberg-components.md) | Admin: classic screens in CampTix; the canvas editor uses only public Gutenberg components | Accepted |
| [0008](0008-editor-translations.md) | The editor's translations are handed to wp.i18n by the plugin | Accepted |
| [0009](0009-screen-views.md) | Print page plus screen views (desktop, tablet, mobile) with per-screen overrides | Accepted |
| [0010](0010-non-public-sites.md) | Sites that are not public print no QR code, code, verification hint or address | Accepted |
| [0011](0011-on-record-not-authentic.md) | The certificate page says "on record" and asks to compare, never "authentic" | Accepted |
| [0012](0012-check-page-in-the-site.md) | "Check a certificate" is a block in a page of the site; only a certificate is full-screen | Accepted |
| [0013](0013-templates-by-condition.md) | Certificate templates chosen by role, a default for everyone else | Accepted |
| [0014](0014-declaration-page.md) | Declaration page with declared activities; the document in full only in the PDF when issuing | Accepted |
| [0015](0015-issuing-without-camptix.md) | Issuing without CampTix: events, participants, generator, GatherPress | Accepted |
| [0016](0016-abilities-for-agents.md) | Abilities for AI agents and MCP | Accepted |
| [0017](0017-generator-page-and-base-design.md) | The generator: one page in blocks, one certificate look (the Base design), libraries bundled | Accepted |
| [0018](0018-assets-source-and-build.md) | Stylesheets and scripts: SCSS and ES sources, built files in assets/css and assets/js | Accepted |
| [0019](0019-integrations-and-test-scenarios.md) | Integrations: one place decides, four scenarios are tested | Accepted |
| [0020](0020-certificate-lifecycle-rules.md) | A certificate's life: only a valid one is corrected, revoked is final, the PDF is for valid ones | Accepted |
| [0021](0021-certificate-language.md) | The language of a certificate: the one it was issued in, the visitor may switch | Accepted |
| [0022](0022-certificate-addresses.md) | The addresses of a certificate: the language and the PDF are path endpoints | Accepted |
| [0023](0023-base-design-filter-and-blueprint.md) | The Base design: flush, neutral, filterable; a Playground blueprint gives the meetup wording | Accepted |
| [0024](0024-certificate-page-dock.md) | The certificate page's Dock: welded to the edge, draggable, folds into a corner | Accepted |
| [0025](0025-one-certificate-per-role.md) | A person has roles, and a certificate for each | Accepted |
| [0026](0026-classic-screens-like-core.md) | Classic admin screens that look like core's | Accepted |
| [0027](0027-identification-documents-by-country.md) | Identification documents by country, chosen in Settings | Accepted |
| [0028](0028-verification-off-by-default.md) | Verification (code and QR code) is off by default | Accepted |
| [0029](0029-settings-by-feature.md) | The settings are organized by feature; the document is one country | Accepted |
| [0030](0030-get-by-email.md) | "Get my certificate": the e-mail, a proof of it and a page of its own | Accepted |
| [0031](0031-release-flow.md) | The release flow: `trunk` in development, `release/X.Y` by cherry-pick, changelog in files | Accepted |

New decisions get the next number. A decision that is replaced keeps its file,
with its status changed to "Replaced by NNNN".
