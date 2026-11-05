# 0007 — Classic admin screens; the canvas editor uses public Gutenberg components

**Status:** Accepted (2026-11-04)

## Decision

- With CampTix active, the plugin lives inside it: **Tickets → Certificates**
  (after Attendees, a WP List Table screen) and a **Certificates** tab in
  **Tickets → Setup** (before Beta). Settings use the Settings API.
- The only app-like screen is the **design editor**: full screen, built with
  `@wordpress/build` pages and following the block editor's patterns (header,
  inserter, List View, block toolbar, ToolsPanel inspector, PaletteEdit, unit
  controls, preview dropdown).
- It uses **only public packages** (`@wordpress/components`,
  `@wordpress/icons`, `@wordpress/element`, `@wordpress/i18n`,
  `@wordpress/api-fetch`), never `@wordpress/block-editor` internals or private
  APIs.

## Why

- Admins already know CampTix's screens and the block editor; the editor should
  feel like the block editor without depending on it.
- Public components keep working across WordPress updates; private APIs and
  internals change without notice.
