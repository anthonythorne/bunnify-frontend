# 0001 — Enterprise restructure

- **Status:** Implemented (branch `feature/enterprise-restructure`)
- **Created:** 2026-07-01
- **Owner:** Anthony Thorne

## Summary

Reshape the Bunnify Frontend repository from a bare plugin folder into a
professional, public, WordPress.org-ready open-source project — without changing
the plugin's runtime behaviour. This covers a directory restructure, a full
development toolchain (linting, static analysis, tests, CI), WordPress.org
packaging, a behaviour-preserving standards/typing pass over the code, and a
documentation wiki with this blueprint system.

## Motivation

The plugin is used in production by a consuming site and is public, but the repo was
a flat plugin directory with no tests, no CI, no coding-standards enforcement, no
distribution tooling, and a `Description` header that did not match the code. To
treat it as a real open-source product it needs the scaffolding that lets other
engineers trust, review, extend, and release it safely.

## Goals

- Separate **project** concerns (docs, tooling) from the **shippable plugin**.
- Make coding standards, static analysis and tests enforceable and green in CI.
- Be able to build and publish a clean plugin zip to WordPress.org.
- Preserve runtime behaviour exactly (the plugin runs in production).
- Record larger follow-up work as blueprints instead of doing it blind.

## Non-goals

- No functional/behavioural changes to CDN URL rewriting.
- No deep architectural rewrite (dependency injection, framework extraction,
  REST completion) — those are designed as [enhancements](../enhancements/README.md)
  to be executed once integration tests exist.

## What changed

### 1. Directory restructure

The installable plugin moved into a `bunnify-frontend/` subdirectory; the repo
root now holds documentation and the development toolchain.

```
Before                          After
------                          -----
/bunnify-frontend.php           /bunnify-frontend/bunnify-frontend.php
/src/…                          /bunnify-frontend/src/…
/build-tools/…                  /bunnify-frontend/build-tools/…
/README.md (plugin)             /bunnify-frontend/README.md
/docs/…                         /docs/…            (now the project wiki)
                                /README.md         (new: project overview)
                                /composer.json …   (new: dev toolchain)
                                /tests/…           (new)
                                /docs/blueprints/… (new)
```

All moves were done with `git mv` so history is preserved as renames. The
runtime autoloader (`build-tools/vendor`, PSR-4 `BunnifyFrontend\`) resolves
unchanged because the plugin file and `src/` moved together (relative paths
intact).

### 2. Development toolchain (repo root, never shipped)

- **`composer.json`** dev dependencies and scripts:
  `composer lint` / `lint:fix` / `analyse` / `test` / `check` / `build`.
- **PHPCS** with the WordPress Coding Standards ([`phpcs.xml.dist`](../../../phpcs.xml.dist)).
- **PHPStan** level 5 with a baseline for legacy findings
  ([`phpstan.neon.dist`](../../../phpstan.neon.dist),
  [`phpstan-baseline.neon`](../../../phpstan-baseline.neon)).
- **PHPUnit** unit suite using Brain Monkey to mock WordPress
  ([`tests/`](../../../tests)).
- **GitHub Actions**: `ci.yml` (lint + analyse + test on PHP 8.2/8.3) and
  `deploy.yml` (WordPress.org SVN deploy on release).

### 3. WordPress.org packaging

- `bunnify-frontend/readme.txt` (wp.org format), `LICENSE` (GPL-2.0),
  multisite-aware `uninstall.php`, and `.distignore`.
- `bin/build.sh` (`composer build`) assembles `dist/bunnify-frontend.zip`
  (verified: 76 files; ships the runtime autoloader; excludes `README.md`,
  `.distignore`, and the Composer manifests).
- `.wordpress-org/` for listing assets (banner/icon/screenshots).
- Corrected the plugin header (accurate description, `License URI`,
  `Domain Path`).

### 4. Standards & typing pass (behaviour-preserving)

- `declare(strict_types=1)` on the `URLTransformer` and `ImageProcessor`
  libraries, with defensive `(string)`/`(bool)` casts on values returned from
  `apply_filters()` so a non-scalar filter return cannot fatal under strict
  types (this replicates the previous, non-strict coercion behaviour).
- A `phpcbf` auto-fix pass plus manual fixes (Yoda conditions, global-variable
  prefixes, a docblock parameter-name mismatch) across the plugin code.
- Result: **PHPCS 0 errors**, **PHPStan 0 (48 legacy findings baselined)**,
  **20 passing unit tests**.

### 5. Documentation & blueprints

- Project `README.md`, a `docs/` wiki (architecture, development, packaging, plus
  the pre-existing hooks/flow/logging/troubleshooting references), community
  health files (`CONTRIBUTING`, `SECURITY`, `CODE_OF_CONDUCT`, PR template), and
  a `CHANGELOG.md`.
- This blueprint system, seeded with six enhancement blueprints.

## Migration & backwards compatibility

**Runtime behaviour is unchanged.** The plugin loads, autoloads, and rewrites
URLs exactly as before.

**The one breaking change is packaging path, not code.** The installable plugin
now lives in `bunnify-frontend/` rather than at the repo root. Anything that
consumes this repository must be updated to point at the subdirectory:

- **Downstream consumer repo** (a consuming site's `wp-content/plugins/bunnify-frontend/`):
  the copy currently mirrors the repo root. However this repo is synced into
  `wp-content/plugins/bunnify-frontend/`, that sync must now take its source from
  this repo's `bunnify-frontend/` subdirectory. The consumer's integration is via the
  public `bunnify_*` filters (e.g. the `bunnify_allow_non_upload_url` hook in its
  integration controller), none of which changed, so no consumer code needs
  updating — only the file-copy source path.
- **WordPress.org deploy**: `deploy.yml` already accounts for the subdirectory
  via `BUILD_DIR: ./bunnify-frontend`.

No options, hooks, filter names, or option storage changed, so existing
installations upgrade transparently.

## Known issues surfaced by this work

These were found while adding tests and static analysis. They are **pre-existing**
and were intentionally left as-is (behaviour preserved / production-tested); each
is captured for follow-up.

| Issue | Location | Follow-up |
| --- | --- | --- |
| A truthy `crop` argument emits **both** `c=1` and `crop=1` (the generic passthrough re-adds `crop`). Locked by a characterisation test. | `URLTransformer::build_query_string()` | [data-driven-settings] / review with CDN param mapping |
| Strict comparison `=== false` against an always-int expression is always false (dead branch). | `URLTransformer` (baselined) | [full-test-coverage] |
| `RESTController` is three no-op callbacks with dead `try/catch` and never-read properties. | `RESTController` | [[rest-controller-completion]] |
| `bunnify_enabled` option is registered and rendered but never read (dead toggle). | `SettingsController` | [[data-driven-settings]] |
| `Application::set_services_for_controller()` uses `class_uses()` (non-recursive), so trait detection misses traits pulled in transitively. | `Base\Main\Application` | [[di-container-service-layer]] / [[base-framework-standards]] |
| Unused `CACHE_TTL_*` constants from `CachingTrait` in the library classes. | `URLTransformer`, `ImageProcessor` (baselined) | [[base-framework-standards]] |

> **Update (2026-07-02):** five of these have since been fixed on this branch: the
> `crop` double-emission, the dead `=== false` branch, the non-recursive
> `class_uses()` trait detection, the dead `bunnify_enabled` toggle (now a
> real master switch — missing/legacy-`''` values stay enabled, a deliberate
> untick stores `'0'`), and the `RESTController` no-op (removed per
> [[rest-controller-completion]], with a guard test). Follow-up fixes from the
> same review wave: a disabled/unconfigured CDN no longer short-circuits
> `image_downsize` with the full-size origin URL, a `strict_types` fatal on a
> never-saved hostname got a defensive cast, and Bunny geometry `crop=w,h`
> strings pass through again. Still open: the unused `CACHE_TTL_*` constants.

## Deferred work

Larger changes are designed as [enhancement blueprints](../enhancements/README.md):
dependency injection & a shared CDN service, comprehensive (integration) test
coverage, REST-layer removal, data-driven settings, Base-framework standards, and
a cleaner wp.org runtime autoloader. Each should land behind its own tests before
deploy, given the plugin runs in production.

## Decisions & notes

- **Base framework treated as vendored.** `src/php/Base` is a mini-MVC framework
  shared across the author's plugins. It is excluded from PHPCS and only lightly
  covered by PHPStan (baselined) for now; bringing it up to standard or
  extracting it is [[base-framework-standards]].
- **`strict_types` scoped to libraries first.** The WordPress-facing controllers
  keep looser typing until integration tests exist, to avoid introducing
  scalar-coercion fatals into production filter callbacks.
- **PHPStan baseline over mass edits.** Legacy findings are baselined so new code
  is held to level 5 without a risky sweep of tested code.

## Verification

```
composer lint      # PHPCS: 0 errors
composer analyse   # PHPStan: [OK] No errors (48 legacy findings baselined)
composer test      # PHPUnit: OK (20 tests, 20 assertions)
composer build     # dist/bunnify-frontend.zip (76 files)
```

[data-driven-settings]: ../enhancements/data-driven-settings/README.md
[full-test-coverage]: ../enhancements/full-test-coverage/README.md
