# Spike: PDF engine

Date: 2026-11-04. Question: which PHP library draws the certificate PDF from the design's elements, on PHP 7.4+ (WordPress.org minimum we target) and on WordPress Playground.

## Setup

- Fixture: the *WordPress Campus Connect* certificate from Figma (842×595 pt) rebuilt as a list of elements (`design.php`): five SVG assets exported from Figma (watermark, ornamental border rotated 90°, white logo, name logo, signature rotated 90°), a rectangle with only the bottom corners rounded, Lora and Inter static TTFs (400/500/600), a paragraph with inline bold, letter spacing, uppercase, lines with opacity and a QR code.
- Names: "Mary Anne Sue" and a long one with accents ("Maria da Conceição Albuquerque Figueiredo dos Santos") for shrink-to-fit.
- Reference: Figma's render of the component (`references/figma/certificate-campus-connect.png`, 842×595, kept out of git).
- Machine: macOS, PHP 8.3 CLI; Playground CLI (PHP.wasm) for the second run.
- The SVG assets and the reference are not in the repository (WordPress trademarks); the scripts are.

## Candidates

| Library | License | PHP | Status |
|---|---|---|---|
| **dompdf 3.1.6** (+ php-svg-lib, php-font-lib) | LGPL-2.1 | 7.1+ | maintained |
| TCPDF 6.11.4 | LGPL-3.0 | 7.1+ | **deprecated** by its author ("Migrate to tecnickcom/tc-lib-pdf") |
| tc-lib-pdf 8.x | LGPL-3.0 | **8.2+** | out: above our PHP minimum |
| tFPDF 1.34 | LGPL | 5.x+ | **no SVG**: every drawn shape and SVG asset would need rasterizing; out |

## Results

| | dompdf | TCPDF |
|---|---|---|
| Render (warm font cache) | 230–430 ms, 20–24 MB | ~107 ms, 16 MB |
| PDF size | **~73 KB** (font subsets) | ~230 KB |
| Pixels differing from Figma (>32/255) | **5.7 %** | 85.6 % |
| SVG fidelity | correct: strokes, `fill="none"`, rotation, clipping at the page edge | **broken**: `fill="none"` strokes drawn as grey fills, signature filled, opacity lost |
| Rounded bottom corners only | ✅ CSS `border-radius` per corner | ✅ `RoundedRect(..., '0110')` |
| Inline bold, accents, embedded subset fonts | ✅ | ✅ |
| QR code | ✅ php-qrcode → SVG data URI (vector) | ✅ built-in |
| Fonts | reads TTF, caches metrics (`.ufm`, JSON, no PHP files) in a writable dir | converts TTF into its own `.php` + `.z` files (PHP written to disk) |
| Playground (PHP.wasm) | ✅ 1.0 s cold (font cache rebuilt), same 73 KB PDF | not tried (already out) |

## Decision

**dompdf**, with **chillerlan/php-qrcode 4.4** (MIT, PHP 7.4+) for the QR code as SVG. Both bundled with their licenses, namespaces prefixed (Strauss) so other plugins' copies don't clash. dompdf's own DejaVu/core fonts (7.5 MB of the 8.9 MB) are left out of the release: the plugin ships its own fonts.

A good side effect: dompdf renders **HTML/CSS**, so the design's elements become one positioned markup (with inline SVG for shapes and paths) that serves the web page, the PDF and, in the browser, the share bitmaps.

## What the spike showed must be handled

1. **Nested opacity in SVG**: Figma's watermark has `opacity="0.2"` and `fill-opacity="0.15"` on the same path (0.03 together); php-svg-lib applied only one. The SVG sanitizer flattens them into one `fill-opacity` on upload (tested: fixed).
2. **Line breaks differ** from the browser's (all three wrap the paragraph in 3 lines, but dompdf breaks them at other words and with taller lines; letter spacing likely ignored). Plan: one line-breaking algorithm on the TTF metrics in PHP and JS, and the renderer draws lines already broken (the "same breaks on screen and in PDF" item in PLAN.md 2.6).
3. **Vertical text position**: dompdf's line box places glyphs a few points off Figma's; position text by baseline from the font's ascent, not by line box.
4. **Shrink-to-fit** must also forbid wrapping (`white-space: nowrap`) and pick a sensible minimum, or allow two lines on purpose.
5. Speed: 0.2–0.4 s per PDF is fine on demand; batch jobs (ZIP, e-mail attachments) run in batches.
