# Decisions

Why Events Certificate is built the way it is. One file per decision: the
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
| [0010](0010-non-public-sites.md) | Sites that are not public show a QR placeholder instead of authenticity | Accepted |
| [0011](0011-on-record-not-authentic.md) | The certificate page says "on record" and asks to compare, never "authentic" | Accepted |

New decisions get the next number. A decision that is replaced keeps its file,
with its status changed to "Replaced by NNNN".
