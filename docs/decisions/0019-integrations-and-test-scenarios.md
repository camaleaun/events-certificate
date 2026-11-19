# 0019 — Integrations: one place decides, four scenarios are tested

**Status:** Accepted (2026-11-18)

## Context

CampTix (WordCamp.org) and GatherPress are optional. The plugin behaves differently with none of them,
only CampTix, only GatherPress, or both: where the Certificates menu lives, where its settings are saved,
whether the generator page is made, which features each integration adds. The checks
(`class_exists( 'CampTix_Plugin' )`, `defined( 'GATHERPRESS_VERSION' )`) were spread over eight files, and
the tests ran only in whatever combination the site had active.

## Decision

- **One place.** `Environment\has_camptix()`, `has_gatherpress()` and `integration()` (`none`, `camptix`,
  `gatherpress`, `both`) are the only code that asks whether a plugin is active. The compat files register
  on `evcert_register_compat` (`plugins_loaded`, priority 20), when every plugin has loaded.
- **Menu and settings.** With one integration, Certificates is a submenu of that plugin's menu and its settings
  move to that plugin's settings. With none or both, it has its own menu and its own settings: two plugins can
  not both own it. Features that are about the integration's data (the ticket page step of CampTix, the import
  of GatherPress) stay in every combination that has the plugin.
- **Tests in four scenarios.** `EVCERT_INTEGRATIONS=none|camptix|gatherpress|both` makes the PHPUnit bootstrap
  change the active plugins before WordPress loads them, and abort if the run did not get what it asked for.
  The plugins are the real ones, installed in the test site (not stubs: a stub would test our assumptions about
  them). `composer test:all` runs the four; `composer check` includes it. Tests for one scenario call
  `requires_integration()` or assert from `Environment\integration()`.
- **CI** runs lint and phpcs only: the tests need a WordPress site with these plugins installed.

## Consequences

- A new check of an integration goes through `Environment`, with a case in `IntegrationsTest`.
- The markup snapshots (`SnapshotTest`) must be identical in the four scenarios.
