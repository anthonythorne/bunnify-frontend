# Local Development

This is the developer guide for **Bunnify Frontend** — a frontend-only BunnyCDN image-URL rewriter for WordPress. It covers how to set up the toolchain, run the checks, run individual tests, understand how the unit suite mocks WordPress, regenerate the PHPStan baseline, and build the distributable zip.

If you only want to *use* the plugin, install the zip from a WordPress release instead — everything below is for working on the code.

## Repository layout

The git root is a development workspace. The installable plugin lives in a subdirectory; the tooling around it (Composer, PHPCS, PHPStan, PHPUnit, CI) sits at the root and is **not** shipped.

```
bunnify-frontend/                 # repo root (dev workspace, NOT shipped)
├── bunnify-frontend/             # the installable plugin (this is what ships)
│   ├── bunnify-frontend.php      # entry file
│   ├── readme.txt                # WordPress.org readme (source of truth for version)
│   ├── uninstall.php, LICENSE, .distignore
│   ├── autoload.php              # hand-written runtime PSR-4 loader (ships)
│   └── src/php/{Base,Controller,Library,Model,Function}
├── docs/                         # the repo wiki, incl. docs/blueprints/
├── tests/Unit/                   # PHPUnit unit tests (Brain Monkey; WP not booted)
├── bin/build.sh                  # dist packaging script
├── composer.json
├── phpcs.xml.dist                # WordPress Coding Standards config
├── phpstan.neon.dist + phpstan-baseline.neon
├── phpunit.xml.dist
└── .github/workflows/            # ci.yml (PHP 8.2 & 8.3), deploy.yml (WP.org SVN)
```

Two paths matter constantly:

- **PSR-4 source** — `BunnifyFrontend\` maps to `bunnify-frontend/src/php/` (see `composer.json` `autoload`).
- **Test namespace** — `BunnifyFrontend\Tests\` maps to `tests/` (`autoload-dev`).

> A consuming site copies the plugin into its `wp-content/plugins/bunnify-frontend/` by syncing from the `bunnify-frontend/` **subdir**, not the repo root.

## Prerequisites

- **PHP 8.2+** with the CLI SAPI (`composer.json` requires `php >=8.2`; CI runs on 8.2 and 8.3). PHPCS is pinned to `testVersion` `8.2-` and PHPStan to `phpVersion 80200`.
- **Composer 2**.
- Standard CLI tools for packaging: `bash`, `rsync`, `zip` (used by `bin/build.sh`).
- Target runtime for reference: **WordPress 6.3+** (`minimum_supported_wp_version` in `phpcs.xml.dist`). You do **not** need a running WordPress install to develop or test — see [How the unit tests work](#how-the-unit-tests-work-brain-monkey).

## Install

From the repo root:

```bash
composer install
```

This installs the dev-only toolchain into `vendor/` (PHPCS + WordPress Coding Standards, PHPStan + `phpstan-wordpress`, PHPUnit, Brain Monkey, Yoast PHPUnit polyfills) and generates the autoloaders. The `dealerdirect/phpcodesniffer-composer-installer` and `phpstan/extension-installer` plugins are pre-allowed in `composer.json`, so PHPCS standards and the PHPStan WordPress extension are wired up automatically.

The plugin itself has **no** runtime Composer dependencies — it ships its own hand-written PSR-4 loader at `bunnify-frontend/autoload.php`. The root `vendor/` is purely for development.

## Composer commands

All commands run from the repo root. Descriptions live in `composer.json` under `scripts-descriptions`.

| Command | Underlying tool | What it does |
| --- | --- | --- |
| `composer lint` | `phpcs` | Checks coding standards against `phpcs.xml.dist` (WordPress Coding Standards). Scans the plugin entry file, `uninstall.php`, and `src/php/{Controller,Library,Model,Function}`. Vendored code, tests, and `src/php/Base` are excluded (the latter tracked in the `base-framework-standards` blueprint). |
| `composer lint:fix` | `phpcbf` | Auto-fixes the coding-standard violations PHPCBF can fix in place. Re-run `composer lint` afterwards to see what remains for manual fixing. |
| `composer analyse` | `phpstan analyse` | Static analysis at **level 5** (`phpstan.neon.dist`) over the entry file, `uninstall.php`, and `src/php`. WordPress symbols are provided by `szepeviktor/phpstan-wordpress`. Pre-existing findings are silenced via `phpstan-baseline.neon` so only *new* issues fail. |
| `composer test` | `phpunit` | Runs the PHPUnit unit suite defined in `phpunit.xml.dist` (the `unit` suite = `tests/Unit`, matching `*Test.php`). Strict mode: warnings, risky tests, and unexpected output all fail. |
| `composer check` | lint + analyse + test | The full local CI gate. Runs `lint`, then `analyse`, then `test` in order. Run this before opening a PR — it mirrors what `.github/workflows/ci.yml` enforces. |
| `composer build` | `bash bin/build.sh` | Assembles the distributable zip in `dist/`. See [Building the dist zip](#building-the-dist-zip). |

Pass flags through to the underlying tool with `--`, for example:

```bash
composer analyse -- --memory-limit=512M
composer lint -- --report=summary
```

## Running tests

Run the whole suite:

```bash
composer test
# or directly:
vendor/bin/phpunit
```

### Run a single test or a subset

Use PHPUnit's `--filter`, which matches against `ClassName::methodName`:

```bash
# One test method:
vendor/bin/phpunit --filter test_build_query_string_crop_emits_both_c_and_crop

# All tests in one class:
vendor/bin/phpunit --filter URLTransformerTest

# A class + method:
vendor/bin/phpunit --filter 'URLTransformerTest::test_is_cdn_url_matches_configured_hostname'
```

You can also point PHPUnit at a single file:

```bash
vendor/bin/phpunit tests/Unit/URLTransformerTest.php
```

Note that `phpunit.xml.dist` sets `cacheResult="false"`, so there is no `--cache-result` state to clear between runs.

## How the unit tests work (Brain Monkey)

The `tests/Unit` suite is a set of **isolated unit tests**: **WordPress is never booted**. There is no test WordPress install, no database, and no `wp-load.php`. This keeps the suite fast, hermetic, and runnable anywhere PHP 8.2+ exists — which is exactly what CI relies on.

Three pieces make that work:

1. **Bootstrap** — `tests/bootstrap.php` defines the handful of WordPress *time constants* (`MINUTE_IN_SECONDS` … `YEAR_IN_SECONDS`) that some classes reference at class-load time (e.g. `CachingTrait` declares TTLs in terms of them), then requires `vendor/autoload.php`. Nothing else about WordPress is loaded.

2. **Base test case** — `tests/Unit/TestCase.php` wires Brain Monkey's lifecycle: `Monkey\setUp()` in `setUp()` and `Monkey\tearDown()` in `tearDown()`. Every test class extends this base.

3. **Per-test function mocks** — each test declares only the WordPress functions it needs, using `Brain\Monkey\Functions`. A few patterns from `tests/Unit/URLTransformerTest.php`:

   ```php
   // Return the first argument unchanged (e.g. sanitize_text_field passthrough):
   Functions\when( 'sanitize_text_field' )->returnArg();

   // Delegate to a real PHP function:
   Functions\when( 'wp_parse_url' )->alias( 'parse_url' );

   // Return a fixed value (e.g. the configured hostname option):
   Functions\when( 'get_option' )->justReturn( 'cdn.example.com' );

   // apply_filters returning its passed-through value (2nd arg):
   Functions\when( 'apply_filters' )->returnArg( 2 );
   ```

Because WordPress functions are stubbed rather than executed, the tests exercise the plugin's *own* logic (URL building, validation, CDN detection) in complete isolation. Private methods that need direct coverage are reached via `ReflectionMethod` (see `build_query_string()` in the same file).

The trade-off: unit tests **cannot** catch integration issues — filter registration, hook ordering, real WordPress data flow. Those are covered by the planned integration suite (see [Planned: integration / WP tests](#planned-integration--wp-tests)).

## Regenerating the PHPStan baseline

`phpstan-baseline.neon` records the pre-existing (legacy) findings so that `composer analyse` fails only on **new** problems. When you intentionally change the set of accepted findings — for example after removing legacy code, or after a large refactor that shifts line numbers — regenerate it:

```bash
composer analyse -- --generate-baseline
```

This rewrites `phpstan-baseline.neon` with the current findings. Guidelines:

- Prefer **fixing** a new error over baselining it. The baseline is for legacy debt, not a way to mute fresh issues.
- Review the diff before committing — a regeneration that *adds* entries means you introduced findings that are now being suppressed.
- `phpstan.neon.dist` includes the baseline (`includes: - phpstan-baseline.neon`), so a regenerated file takes effect immediately with no further config changes.

## Building the dist zip

```bash
composer build
```

This runs `bin/build.sh`, which:

1. Cleans the `build/` and `dist/` directories.
2. `rsync`s the plugin subdir (`bunnify-frontend/`) into a staging folder, honouring the exclusion patterns in `bunnify-frontend/.distignore`, then hard-fails if any Composer manifest, `build-tools/`, or `vendor/` survives — or if a runtime essential (entry file, `autoload.php`, `readme.txt`, …) is missing.
3. Zips the staging folder so the archive is rooted at a single `bunnify-frontend/` directory — the layout WordPress expects when installing from a zip.

Output: **`dist/bunnify-frontend.zip`**. The script prints the final path and size.

The version and "tested up to" values in the packaged plugin come from `bunnify-frontend/readme.txt` (the WordPress.org source of truth), not from `composer.json`. On a tagged GitHub release, `.github/workflows/deploy.yml` performs the equivalent packaging and pushes to the WordPress.org SVN repo.

## Planned: integration / WP tests

The current suite is unit-only by design. A full integration/WP-test layer — booting WordPress, asserting hook registration and end-to-end URL rewriting against a real install — is a planned enhancement. The design and scope live in the repo wiki:

- `docs/blueprints/enhancements/full-test-coverage/README.md`

Until that lands, validate WordPress-integration behaviour manually in a real install (a downstream consumer site is the usual proving ground), and keep new logic in units small and mockable so it stays covered by the fast suite.
