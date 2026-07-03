# Changelog

All notable changes to this project are documented here. The format is based on
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and this project adheres
to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added
- Repository restructure: the installable plugin now lives in the
  `bunnify-frontend/` subdirectory; the repo root holds docs and tooling.
- Development toolchain: PHPCS (WordPress Coding Standards), PHPStan (level 5 +
  baseline) and a PHPUnit unit suite (Brain Monkey), wired into GitHub Actions
  CI across PHP 8.2 and 8.3.
- WordPress.org packaging: `readme.txt`, `LICENSE`, a multisite-aware
  `uninstall.php`, `.distignore`, a `bin/build.sh` zip builder, and a
  `deploy.yml` workflow that publishes tagged releases to the plugin SVN.
- `bunnify_allow_non_upload_url` filter for opt-in CDN processing of local
  assets outside `/wp-content/uploads/`.
- Pass-through of additional Bunny transform arguments (quality, format, …)
  alongside the core width/height/crop mapping.
- Project wiki under `docs/` and a `docs/blueprints/` roadmap.

### Changed
- Local-development mode is now automatic. It enables on any non-`production`
  environment via WordPress core's `wp_get_environment_type()` (no manual
  toggle needed on local/staging), and it now covers admin surfaces too — the
  media library and editor previews previously skipped rewriting wholesale, so
  an install without synced uploads showed blank thumbnails. The mode's
  per-image rule ("serve the local file if present, else the CDN") now applies
  everywhere. Resolution order: the `bunnify_local_dev_mode_check` filter
  (force on/off) → automatic non-production detection → the
  `bunnify_local_dev_mode` option (a manual force-on for a production-typed
  box). Production behaviour is unchanged.
- Resource hints moved from `dns-prefetch` to a dedicated
  `WPResourceHintsController` that adds a `preconnect` (skipping same-origin)
  and strips the redundant `dns-prefetch` for the CDN hostname.
- `declare(strict_types=1)` added to the `URLTransformer` and `ImageProcessor`
  libraries (with defensive casts on filtered returns), plus a
  behaviour-preserving WordPress Coding Standards pass across the plugin.

### Fixed
- A truthy `crop` transform argument emitted both `c=1` and `crop=1`; only the
  `c=1` shorthand is sent now, and a falsy `crop` no longer leaks a raw
  `crop=0` parameter. Bunny's native geometry form (`crop=w,h[,x,y]`) still
  passes through raw alongside the mapped `c=1`, as in 1.0.0.
- The **Enable BunnyCDN** setting (`bunnify_enabled`) was never read at
  runtime; it is now a real master switch that stops URL rewriting and CDN
  resource hints when unchecked. Installs that never saved the setting — and
  legacy saves that stored `''` while the checkbox was inert — remain
  enabled; a deliberate untick now stores an explicit `0`.
- A disabled or unconfigured CDN no longer short-circuits `image_downsize`
  and content rewriting with the full-size origin URL (which collapsed every
  intermediate image size); the filters now leave their input untouched.
- Assigning a never-saved `bunnify_hostname` option (returned as `false`)
  to a typed property fataled under `strict_types`; a defensive cast restores
  the pre-strict coercion.
- `Application` trait-driven service injection used non-recursive
  `class_uses()`, missing traits inherited from a parent class or composed by
  another trait.

### Changed (packaging)
- The runtime autoloader is now a hand-written `autoload.php` at the plugin
  root; the committed `build-tools/vendor` Composer autoloader (and its `..`
  path traversal and manual classmap refreshes) is gone. `bin/build.sh` now
  hard-fails if Composer artefacts leak into the distributable or runtime
  essentials are missing, and CI gained a `package` job asserting the zip
  contents. Consumers that copied `build-tools/` should sync the whole plugin
  directory.

### Removed
- The never-functional `RESTController` (an abandoned port of Jetpack
  Photon's disable-rewriting-during-REST guard; three no-op callbacks on
  every REST request). No behaviour change; a guard test keeps the withdrawn
  hooks (`rest_request_before_callbacks`, `rest_after_insert_attachment`,
  `rest_request_after_callbacks`) from silently returning. The plugin's REST
  posture — general filters do rewrite REST `sizes.*.source_url` and
  `content.rendered`; `content.raw` stays unfiltered — is now documented in
  `docs/ARCHITECTURE.md` and the `rest-controller-completion` blueprint.

### Packaging note
- Consumers that copy this repository into `wp-content/plugins/` must now sync
  from the `bunnify-frontend/` subdirectory rather than the repo root.

### Known issues
- See [`docs/blueprints/0001-enterprise-restructure`](docs/blueprints/0001-enterprise-restructure/README.md)
  for the remaining list and remediation plan.

## [1.0.0] - 2026-07-01

### Added
- Initial release. Frontend-only BunnyCDN URL rewriting for attachments,
  post content, blocks, galleries and image widgets; responsive `srcset`/`sizes`
  rewriting; a settings screen under **Media → BunnyCDN**; local-development
  mode; and an extensive filter API.

[Unreleased]: https://github.com/anthonythorne/bunnify-frontend/compare/v1.0.0...HEAD
[1.0.0]: https://github.com/anthonythorne/bunnify-frontend/releases/tag/v1.0.0
