# 0031 — The release flow: `trunk` in development, `release/X.Y` by cherry-pick, changelog in files

**Status:** Accepted (2026-11-19).

## Context

Until now every change was committed straight to `trunk`, the version was `0.71` everywhere and a release was a
manual `bin/package.sh` and a tag. Nothing says what is in development and what was released, and the
`readme.txt` Changelog, written by hand, conflicts as soon as two changes are in flight.

## Decision

- **`trunk` is in development.** Its version is the next `X.Y-dev` (`0.71-dev` until the first release);
  each change is a `feature/…` or `fix/…` branch, squash-merged (one commit per change).
- **`release/X.Y` is cut when the version's scope is in** (for 0.71: when it is time to send it to the directory).
  `trunk` then moves to the next dev version (`0.72-dev`). The release branch takes only fixes, brought from
  `trunk` with `git cherry-pick -x`, and is never merged back.
- **A version is `X.Y`, with no patch until one is needed** (`0.71`, then `0.72`; a fix to a release is `0.71.1`).
  The first one is `0.71`, after the first WordPress release. The kinds are `X.Y-dev` (header and constant only, no tag),
  `X.Y-rc.N` (a GitHub pre-release: its own tag, `Stable tag` untouched) and `X.Y` or `X.Y.Z` (also moves `Stable tag`). The directory takes numeric versions only.
- **The changelog is one short file per change**, `changelog/<branch>`: `Significance: patch|minor|major`,
  `Type: add|fix|update|dev` (the label), a blank line and one sentence. The release compiles them into
  `readme.txt` as Added, Changed and Fixed (`dev` stays out) and deletes them; a pre-release only adds them. The
  CI requires a file in a pull request that changes what ships.
- **One command, `evcert release`** (`bin/release.php`, helpers in `bin/release-lib.php`, tested by
  `ReleaseFlowTest`), bumps, compiles, checks, commits and tags. It **does not push** unless asked: a pushed tag
  runs `release.yml` and publishes, so it waits for the owner. A final that was never tagged can go back to its
  own `X.Y-dev`; nothing else goes back.
- **CI:** a "Changelog entry" job on pull requests, a "Versions agree" job (header = constant; a final: Stable
  tag = version; with a suffix: Stable tag is a final not after the series, so the series' own version is
  accepted until its first release) and the build, also on `release/**`. `release.yml` accepts `X.Y[.Z]` and
  `X.Y[.Z]-rc.N`, uses the version's Changelog entry as the release text and marks a suffixed tag as a pre-release.
- **Plugin Check** ignores `stable_tag_mismatch` while the version has a suffix (`bin/plugin-check.sh`).

## Consequences

- The zip sent to the directory comes from the release branch (`0.71`), never from `trunk`
  (`…-0.71-dev.zip`).
- A change to what ships needs a changelog file, or the pull request fails.
- The first release is cut from `trunk` as `release/0.71`; the history before it has no changelog files, so
  `0.71`'s Changelog stays as written in `readme.txt` and new entries are added to it.
