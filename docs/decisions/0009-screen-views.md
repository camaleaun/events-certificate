# 0009 — Print page plus screen views

**Status:** Accepted (2026-11-05)

## Decision

The design's page is the **print format** (A4 or Letter, landscape or
portrait), and it never turns. The editor's View menu also previews the
certificate page on **desktop (1280×800), tablet (1024×768) and mobile
(844×390)**, each in landscape or portrait.

On a screen, content and style are shared; moving, resizing or hiding an
element is stored for that screen only (`views` on the element). Elements
without a view keep their anchors; unanchored ones keep their centre as a share
of the page, with shapes, images and the QR code scaled evenly.

## Why

- The PDF must match print; the web page must be readable on a phone. One set
  of texts with per-screen positions avoids keeping several designs in sync.
