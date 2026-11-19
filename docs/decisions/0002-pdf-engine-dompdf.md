# 0002 — PDF engine: dompdf, with php-qrcode for the QR code

**Status:** Accepted (2026-11-04)

## Question

Which PHP library draws the certificate PDF, on PHP 7.4+ and on WordPress
Playground?

## Decision

**dompdf 3.1** for the PDF and **chillerlan/php-qrcode 4.4** for the QR code
(as vector SVG). PDFs are always rendered in the light color mode, with a
transparent page background where the design has none.

## Why

Measured in the spike ([`../spikes/pdf-engine/RESULTS.md`](../spikes/pdf-engine/RESULTS.md))
with the Campus Connect certificate rebuilt as elements:

| | dompdf | TCPDF |
|---|---|---|
| Pixels differing from the Figma render | **5.7 %** | 85.6 % |
| PDF size | **~73 KB** | ~230 KB |
| SVG fidelity | correct | broken (`fill="none"` strokes filled, opacity lost) |
| Render time | 0.2–0.4 s | ~0.1 s |
| Playground | works (1.0 s cold) | not tried |

- TCPDF is deprecated by its author; its successor tc-lib-pdf needs PHP 8.2.
  tFPDF has no SVG. Both were left out.
- dompdf renders HTML/CSS, so the design becomes one positioned markup (with
  inline SVG for shapes) that also serves the web page.
- Speed is fine on demand; dompdf writes font metrics as JSON/UFM, never PHP
  files, which matters for hosts and Playground.

## Consequences

- Line breaks and vertical text positions differ slightly from the browser;
  the plan is one line-breaking routine on the font metrics used by both the
  editor and the PDF, and text positioned by baseline.
- dompdf's own DejaVu fonts (7.5 MB) are left out; the plugin ships its fonts
  (Inter, Lora).
- Both libraries are bundled with prefixed namespaces (see 0003).
