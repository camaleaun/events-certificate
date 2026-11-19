# Contributing to Events Certificates

How the branches, versions and releases work. The decision behind it is
[docs/decisions/0031-release-flow.md](../docs/decisions/0031-release-flow.md).

## Branches

| Branch | What goes in | Version in `events-certificates.php` |
|---|---|---|
| `trunk` | every change, squash-merged from a feature or fix branch | the next `X.Y-dev` (`0.71-dev` until the first release) |
| `feature/<n>-<name>`, `fix/<n>-<name>` | one change, branched from `trunk` (e.g. `feature/7-menus`) | — |
| `release/X.Y` | fixes only, after the release is cut | `X.Y-rc.N`, `X.Y`, then `X.Y.1`… |

A version is `X.Y`, with no patch until one is needed (`0.71`, `0.72`; a fix to a release is `0.71.1`).

`readme.txt`'s `Stable tag` is the last final release (until the first one, the
version the first release will have).

## Day to day

```sh
git switch trunk && git pull
git switch -c feature/7-menus
# work, add changelog/7-menus (below), and before each commit, from the plugin folder:
composer check && npm run check-types && npm run test:js
# after editing readme.txt (never README.md, which is made from it), and commit the result:
npm run readme
git push -u origin feature/7-menus
gh pr create --base trunk           # squash-merged: one commit per change
```

## Changelog entries

Every change to what ships (`includes/`, `src/`, `routes/`, `templates/`,
`blocks/`, `events-certificates.php`, `uninstall.php`, `readme.txt`) adds one
file to `changelog/`, named after its branch without the prefix
(`changelog/7-menus`); the CI checks it. Nobody edits `readme.txt`'s Changelog by
hand, so branches never conflict there.

```
Significance: minor
Type: add

Export navigation menus: classic menus with their theme locations.
```

- `Significance`: `patch` (a fix), `minor` (a feature) or `major` (a breaking change). The release command warns when the version is smaller than the entries need.
- `Type` (the label): `add`, `fix`, `update` (the readme's *Added:*, *Fixed:*, *Changed:*) or `dev` (tooling, tests, docs: left out of the readme).
- The entry: one short sentence for users, starting with a verb.

The release command adds the entries to the version's Changelog (a pre-release
adds them, the release adds them and deletes the files).

## Releasing

All from the plugin folder, with WP-CLI (`wp`, or `studio wp` from the site root).
Nothing is pushed unless you add `--push`: **a pushed tag publishes the release**,
so that waits for the owner's OK.

1. **Cut the release branch** when the version's changes are on `trunk`, and move
   `trunk` to the next dev version:
   ```sh
   git switch -c release/0.71 trunk
   git switch trunk
   wp --require=bin/release.php evcert release 0.72-dev --no-checks
   ```
2. **Fixes** land on `trunk` first, then on the release branch with
   `git cherry-pick -x <commit>` (it records the origin commit in the message).
   A fix that only makes sense on the release branch is committed there and
   cherry-picked to `trunk`. **Release branches are never merged back.** To see what
   `trunk` has that the release branch doesn't: `git cherry -v release/0.71 trunk`
   (a `+` is still to be picked).
3. **Optional pre-release** for testing, GitHub only (not the latest release, not
   for WordPress.org): write the version's Upgrade Notice in `readme.txt`, then
   `evcert release 0.71-rc.1` from `release/0.71`.
4. **The release**, from `release/0.71`: `evcert release patch` (from `0.71-dev` or
   an `-rc.N`; or `evcert release 0.71`). It bumps the version and `Stable tag`,
   compiles `changelog/`, needs the version's Changelog and Upgrade Notice, runs the
   checks, commits `chore: release 0.71` and tags `0.71`. The tag, once pushed, runs
   `.github/workflows/release.yml`: the zip and the GitHub release.
5. **Back to `trunk`:** `git cherry-pick -x` the `chore: release 0.71` commit, keeping
   `trunk`'s dev version and taking `readme.txt`'s Changelog, Upgrade Notice and
   Stable tag.

`bash bin/package.sh` makes the zip from a committed tree. `--no-checks` skips
`composer check`, the build, types, JS tests and Plugin Check (the checks need `php`
and `composer` on the PATH, and a Studio site for Plugin Check: run them by hand when
the command can't).

Patch releases (`0.71.1`) come from the same `release/0.71`, for bugs that break
something for users; everything else waits for the next minor.
