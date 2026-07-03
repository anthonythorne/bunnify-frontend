# Changelog

All notable changes to this project are documented here. The format is based on
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and this project adheres
to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added
- URL-surface coverage for bare attachment URLs. Beyond the `<img>`/resize
  pipeline, the plugin now rewrites `wp_get_attachment_url()` (ACF URL fields,
  theme templates, REST `source_url`, block bindings), the classic-theme custom
  header (`theme_mod_header_image` / `get_header_image`), and inline
  `background-image: url(...)` on `core/cover`/`core/group`/`core/columns`. The
  `wp_get_attachment_url` filter is re-entrancy-guarded via a new
  `AttachmentUrl::origin()` accessor (a Photon-style suspend flag) so the
  plugin's own internal origin lookups are never rewritten. New opt-in hooks:
  `bunnify_admin_allow_attachment_url`, `bunnify_skip_background_image`. All
  gated by the existing enabled/hostname/local-dev rules; data URIs, gradients,
  and already-CDN URLs are left untouched.
- WordPress.org submission-readiness pass: all user-facing admin strings are
  internationalised with the `bunnify-frontend` text domain; every setting now
  registers a `sanitize_callback` (hostname reduced to a bare host, log
  retention clamped to 1–100, checkboxes normalised to `'1'`/`'0'`); the debug
  log directory is hardened with an `index.php` and a `Require all denied`
  `.htaccess` so it is not browsable.
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

### Added
- The media library / editor picker now resolves through the CDN in local-dev
  mode. The `wp.media` modal builds its size URLs from `wp_get_attachment_url()`
  and the stored filenames (not `image_downsize`), so a new
  `wp_prepare_attachment_for_js` filter rewrites the picker's `url` and every
  `sizes.*.url` — otherwise an install without synced uploads showed blank
  thumbnails in the picker even though the front end resolved fine. Gated like
  the other filters: origin in production admin, per-image "local file if
  present, else CDN" in local-dev mode. Opt-in override:
  `bunnify_admin_allow_attachment_for_js`.

### Changed
- Local-development mode is now automatic. It enables on a development-class
  environment (`local` or `development`) via WordPress core's
  `wp_get_environment_type()` (no manual toggle needed), and now also covers
  admin picker/preview surfaces (the media library and featured-image
  previews). The mode's per-image rule ("serve the local file if present, else
  the CDN") applies everywhere. `staging` is deliberately **not** auto-enabled
  so a staging box still exercises the CDN before a production promotion.
  Resolution order: the `bunnify_local_dev_mode_check` filter (force on/off) →
  automatic `local`/`development` detection → the `bunnify_local_dev_mode`
  option (a manual force-on for staging / production-typed boxes). Production
  behaviour is unchanged.
- `image_exists_locally()` now caches its result per request (local-dev mode
  called it — and its two filesystem stats — for every image, often several
  times each).

### Fixed
- The debug log path was inconsistent: the writer used
  `uploads/bunnify-logs/debug.log` while the admin screen, field help, and
  `uninstall.php` referenced `uploads/bunnify-debug.log`. They now agree, so
  the admin panel reflects the real log and uninstall removes the whole
  `bunnify-logs/` directory. The admin panel no longer links the raw log URL
  publicly, and the removed-but-never-implemented `?bunnify_debug=1` / "page
  refresh" instructions are gone (logging runs automatically for enabled
  categories; retention is by log line).
- Replaced a `var_dump()` fallback in the logging trait with a `WP_DEBUG`-gated
  `error_log()`, and added `wp_unslash()`/sanitisation to the framework's
  request/`$_SERVER` reads.
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
