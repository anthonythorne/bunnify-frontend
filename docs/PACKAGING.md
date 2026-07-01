# Packaging & Release

How Bunnify Frontend is versioned, built into a distributable zip, and published
to WordPress.org. Read this before cutting a release.

> **Repo layout reminder.** The git root holds project docs + the dev toolchain
> (none of which ships). The *installable plugin* lives in the
> [`bunnify-frontend/`](../bunnify-frontend/) subdirectory — that subdir, minus
> the `.distignore` exclusions, is exactly what WordPress.org receives.

## Versioning surface (keep these in sync)

A release bumps the version in more than one place. All of these must agree, or
WordPress.org will either reject the upload or serve a version that disagrees
with the plugin header:

| What | File | Field | Current |
| --- | --- | --- | --- |
| Plugin header | [`bunnify-frontend/bunnify-frontend.php`](../bunnify-frontend/bunnify-frontend.php) | `Version:` | `1.0.0` |
| wp.org stable tag | [`bunnify-frontend/readme.txt`](../bunnify-frontend/readme.txt) | `Stable tag:` | `1.0.0` |
| Changelog | `CHANGELOG.md` (repo root) + `== Changelog ==` in `readme.txt` | new version heading | `1.0.0` |

Rules:

- **Header `Version` == `Stable tag`.** These two are the release identity.
  WordPress.org treats `Stable tag` as authoritative for what gets served; a
  mismatch is the single most common self-inflicted release bug.
- **`CHANGELOG.md` is the source of truth for the human-readable history.** Keep
  a `CHANGELOG.md` at the repo root (Keep a Changelog style). Mirror the new
  version's entry into the `== Changelog ==` section of `readme.txt`, because
  that is what renders on the wp.org listing. If `CHANGELOG.md` does not exist
  yet, create it as part of the first release that adds it and backfill `1.0.0`.
- **Adjacent metadata that drifts.** When you bump the version, also review — in
  the same commit — the compatibility fields that tend to go stale:
  `Tested up to:` (currently `6.9`), `Requires at least:` (`6.3`), and
  `Requires PHP:` (`8.2`). The last two exist in *both* the plugin header and
  `readme.txt` and must match each other.
- **Use SemVer.** `MAJOR.MINOR.PATCH`. The git tag is `X.Y.Z` (no `v` prefix —
  it must equal the stable tag string).

## Build locally

```bash
composer build        # -> bin/build.sh -> dist/bunnify-frontend.zip
```

[`bin/build.sh`](../bin/build.sh) does the following:

1. Wipes `build/` and `dist/`, then stages the plugin into `build/bunnify-frontend/`.
2. `rsync`s [`bunnify-frontend/`](../bunnify-frontend/) into the stage, applying
   one `--exclude` per non-comment line of
   [`bunnify-frontend/.distignore`](../bunnify-frontend/.distignore).
3. Zips from `build/` so the archive is rooted at a single `bunnify-frontend/`
   folder — the layout WordPress expects (the slug directory must be the top
   level inside the zip).

Output: `dist/bunnify-frontend.zip`. Unzip it and inspect the tree before you
trust it — the local zip is your best pre-flight check that `.distignore` is
excluding what you think it is:

```bash
composer build
unzip -l dist/bunnify-frontend.zip
```

> **The local build and the wp.org deploy are two paths to the same result.**
> `bin/build.sh` is a local convenience for producing a hand-installable zip.
> The wp.org deploy (below) does **not** call `bin/build.sh`; the 10up action
> runs its own `rsync` against `BUILD_DIR` honouring the same `.distignore`. Keep
> `.distignore` correct and both paths agree.

### What `.distignore` excludes — and why `build-tools/vendor` stays

[`.distignore`](../bunnify-frontend/.distignore) strips development and packaging
cruft from the shipped tree:

- `README.md` — the wp.org listing is built from `readme.txt`, not `README.md`.
- VCS / packaging metadata — `.distignore`, `.gitignore`, `.git`, `.github`.
- `build-tools/composer.json` and `build-tools/composer.lock` — Composer
  *manifests* are not needed at runtime.
- OS/editor cruft — `.DS_Store`, `Thumbs.db`.

**`build-tools/vendor/` is intentionally kept.** It is not dev tooling — it is the
minimal, zero-dependency PSR-4 autoloader the plugin `require`s on boot
(`bunnify-frontend.php` loads `build-tools/vendor/autoload.php`). Removing it
would break activation. Only the *manifests* are stripped; the generated
`vendor/` plumbing ships.

> This `build-tools/` naming is a known wart — it reads like something that
> should be excluded, and the generated autoload map uses `..` traversal into
> `../src/php`. The planned cleanup (move the loader to an obviously-runtime path
> and drop the Composer footprint) is specced in
> [wporg-runtime-autoloader](blueprints/enhancements/wporg-runtime-autoloader/README.md).
> Until that lands, **do not** add `build-tools/vendor/` to `.distignore`.

## WordPress.org release flow

Publishing runs through
[`.github/workflows/deploy.yml`](../.github/workflows/deploy.yml) — **which is
deliberately disabled until the plugin is ready for wordpress.org.**

> **Deploy is gated OFF.** Two independent guardrails prevent an accidental
> release: the automatic `release:` trigger is commented out (so publishing a
> GitHub Release does nothing), and the job is skipped unless the repository
> variable `WPORG_RELEASE_ENABLED` is set to `true`. To enable releases later:
> add the `SVN_USERNAME`/`SVN_PASSWORD` secrets, set `WPORG_RELEASE_ENABLED=true`,
> and uncomment the `release:` trigger in `deploy.yml`.

Once enabled, the flow is:

- **Trigger:** a **published** GitHub Release (`on: release: [published]`).
  Creating the tag alone does nothing; the Release must be *published*.
- **Action:** [`10up/action-wordpress-plugin-deploy@stable`](https://github.com/10up/action-wordpress-plugin-deploy),
  configured with:
  - `SLUG: bunnify-frontend` — the wp.org plugin slug.
  - `BUILD_DIR: ./bunnify-frontend` — the plugin subdir (the action rsyncs this,
    applying `.distignore`, into the SVN `trunk/` and the tagged release).
  - `ASSETS_DIR: .wordpress-org` — the listing assets (below), pushed to SVN
    `assets/` (not into the plugin).
- **Secrets required:** `SVN_USERNAME` and `SVN_PASSWORD` — a wordpress.org
  account with commit access to the `bunnify-frontend` plugin. Set these under
  the repo's **Settings → Secrets and variables → Actions**.

> **Current status (2026-07): intentionally disabled.** The plugin is not ready
> for wordpress.org, so the deploy is gated off (see the callout above). Even once
> enabled, first-time submission — uploading the initial zip through the wp.org
> "Add Your Plugin" flow and passing review — is a one-off manual step; the
> workflow only takes over for releases after approval.

### Where wp.org listing assets go

The [`.wordpress-org/`](../.wordpress-org/) directory holds the plugin *listing*
graphics — icon, banner, screenshots — deployed to the wp.org SVN `assets/`
folder. They are **not** part of the plugin zip. See
[`.wordpress-org/README.md`](../.wordpress-org/README.md) for the exact
filenames/sizes; the essentials:

| File | Size | Purpose |
| --- | --- | --- |
| `icon-128x128.png` / `icon-256x256.png` | 128² / 256² | Plugin icon (+ retina) |
| `banner-772x250.png` / `banner-1544x500.png` | 772×250 / 1544×500 | Header banner (+ retina) |
| `screenshot-1.png` | any | Must match `== Screenshots ==` entry 1 in `readme.txt` |

Screenshot filenames must line up with the numbered entries in the `readme.txt`
`== Screenshots ==` section, or they will not caption correctly on the listing.

### Consumer repo note (caretochange)

The **caretochange** project vendors a copy of this plugin into
`wp-content/plugins/bunnify-frontend/`. After the repo restructure, that copy
must be synced from the **`bunnify-frontend/` subdir**, not from the git root —
the root is docs + toolchain and is not a valid plugin. The cleanest source for
the consumer is the built artifact: unzip `dist/bunnify-frontend.zip` (or pull
the exact tagged tree) so the copy tracks *built* output, including the correct
autoloader path. See the migration note in
[wporg-runtime-autoloader](blueprints/enhancements/wporg-runtime-autoloader/README.md)
for why the copy must follow the build rather than cherry-pick `build-tools/`.

## Release checklist

Do these from a clean, up-to-date `main` working tree.

1. **Pick the version** (`X.Y.Z`, SemVer) and confirm it is greater than the
   current `Stable tag`.
2. **Bump the version everywhere:**
   - [ ] `Version:` in `bunnify-frontend/bunnify-frontend.php`.
   - [ ] `Stable tag:` in `bunnify-frontend/readme.txt`.
   - [ ] New entry in `CHANGELOG.md` (repo root).
   - [ ] Mirror that entry into `== Changelog ==` (and, if user-facing, add an
         `== Upgrade Notice ==` line) in `readme.txt`.
3. **Review compatibility metadata:** `Tested up to`, `Requires at least`,
   `Requires PHP` — matching between the header and `readme.txt`.
4. **Confirm listing assets** exist in `.wordpress-org/` if any changed (icon,
   banner, screenshots aligned to `readme.txt`).
5. **Run the full gate:** `composer check` (lint + analyse + test) — must be green.
6. **Build and inspect the zip locally:**
   ```bash
   composer build
   unzip -l dist/bunnify-frontend.zip   # verify build-tools/vendor present,
                                        # composer.json / .git / README.md absent
   ```
   Optionally run the official [Plugin Check](https://wordpress.org/plugins/plugin-check/)
   against the unzipped tree.
7. **Commit** the version bump on `main` (or via PR) and push.
8. **Tag and release:** create a Git tag `X.Y.Z` (no `v` prefix) and publish a
   **GitHub Release** from it. Paste the changelog entry as the release notes.
   ```bash
   gh release create X.Y.Z --title "X.Y.Z" --notes-file <(...)
   ```
9. **Watch the deploy:** confirm the *Deploy to WordPress.org* workflow ran and
   `10up/action-wordpress-plugin-deploy` committed `trunk/` + the new SVN tag.
   (Deploy is gated off until the plugin is ready — re-enable it per the callout
   in *WordPress.org release flow* above, once `WPORG_RELEASE_ENABLED` and the
   SVN secrets are set.)
10. **Verify on wp.org:** the listing shows the new version, `Tested up to`, and
    changelog; a fresh install activates cleanly.
11. **Sync consumers:** update the caretochange vendored copy from the
    `bunnify-frontend/` subdir (built output) and smoke-test the site.

## Related

- [wporg-runtime-autoloader](blueprints/enhancements/wporg-runtime-autoloader/README.md)
  — planned autoloader/packaging cleanup (drop `build-tools/`, ship a clean
  runtime loader, add a packaging smoke test).
- [`bin/build.sh`](../bin/build.sh), [`.distignore`](../bunnify-frontend/.distignore),
  [`deploy.yml`](../.github/workflows/deploy.yml) — the moving parts referenced above.
