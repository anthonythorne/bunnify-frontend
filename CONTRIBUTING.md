# Contributing to Bunnify Frontend

Thanks for taking the time to contribute! Bunnify Frontend is a small, focused
BunnyCDN image-URL rewriter for WordPress, and it stays maintainable because
every change goes through the same lightweight quality gate. This guide gets you
from a fresh clone to a green pull request.

If anything here is unclear or out of date, please open an issue — improving the
contributor experience counts as a contribution too.

## Prerequisites

- **PHP 8.2 or newer** (CI runs the suite on 8.2 and 8.3).
- **Composer 2** for dependency management and the developer scripts.
- **Git**, and a GitHub account for opening pull requests.

You do **not** need a running WordPress install to develop or test. The unit
suite mocks WordPress with [Brain Monkey](https://github.com/Brain-WP/BrainMonkey),
so WordPress is never booted.

## One-time setup

Clone the repo and install the dev toolchain from the **repository root**:

```bash
git clone https://github.com/anthonythorne/bunnify-frontend.git
cd bunnify-frontend
composer install
```

`composer install` pulls in PHPCS + the WordPress Coding Standards, PHPStan, and
PHPUnit. That is everything you need for the dev loop below.

> Heads up on layout: the git root holds the docs and the development toolchain,
> which are **not** shipped. The installable plugin lives in the
> [`bunnify-frontend/`](bunnify-frontend/) subdirectory — that subdir is what
> ships to WordPress.org.

## The dev loop

All developer commands are Composer scripts, run from the repo root:

| Command | What it does |
| --- | --- |
| `composer lint` | Coding standards check (PHPCS / WordPress Coding Standards). |
| `composer lint:fix` | Auto-fix what PHPCBF can fix in place. |
| `composer analyse` | Static analysis (PHPStan, level 5 + baseline). |
| `composer test` | Run the PHPUnit unit suite. |
| `composer check` | `lint` + `analyse` + `test` — the full CI gate. |
| `composer build` | Assemble a distributable `dist/bunnify-frontend.zip`. |

Day to day, a tight loop looks like:

```bash
composer lint:fix   # fix trivial style issues automatically
composer check      # run the exact gate CI enforces
```

Run `composer check` before you push. If it is green locally, CI should be green
too.

## Where the code and tests live

Plugin source (PSR-4 under the `BunnifyFrontend\` namespace) lives in
[`bunnify-frontend/src/php/`](bunnify-frontend/src/php/):

- `Controller/` — WordPress-facing controllers (e.g. `CDNController`,
  `ImageController`, `ContentController`, `WPResourceHintsController`,
  `SettingsController`). Controllers are plain objects wired up on
  `plugins_loaded` by `Base\Main\Application`.
- `Library/` — the core logic (`URLTransformer`, `ImageProcessor`).
- `Model/`, `Function/` — supporting types and the runtime autoloader glue.
- `Base/` — the reusable mini-framework. **Treat this as vendored** (see coding
  standards below).

The plugin entry file is
[`bunnify-frontend/bunnify-frontend.php`](bunnify-frontend/bunnify-frontend.php),
which lists the active controllers.

Tests live in [`tests/Unit/`](tests/Unit/) under the
`BunnifyFrontend\Tests\Unit` namespace, with shared wiring in
[`tests/Unit/TestCase.php`](tests/Unit/TestCase.php) and
[`tests/bootstrap.php`](tests/bootstrap.php).

## Coding standards

- **WordPress Coding Standards** via PHPCS, configured in
  [`phpcs.xml.dist`](phpcs.xml.dist). Text domain is `bunnify-frontend`, and all
  global symbols must carry a `bunnify` / `BunnifyFrontend` prefix.
- **PHPStan at level 5**, configured in [`phpstan.neon.dist`](phpstan.neon.dist).
  A [`phpstan-baseline.neon`](phpstan-baseline.neon) grandfathers in legacy
  findings — **do not add new baseline entries** for code you write. New code
  should be clean at level 5.
- **`declare(strict_types=1)`** is required on **new library code** (and is the
  norm for new classes generally). `URLTransformer` is the reference example.
- **Short arrays** (`[]`), short ternary (`?:`), and typed properties are the
  house style; `.editorconfig` enforces the whitespace rules.

### The Base framework is vendored

`bunnify-frontend/src/php/Base` is treated as a **vendored dependency** and is
excluded from linting and analysis. Avoid editing `Base/` as part of a feature change. If you believe the
framework itself needs work, that is tracked separately under
[`docs/blueprints/`](docs/blueprints/) — start a discussion there rather than
patching it inline.

## Adding a unit test

Tests are isolated: WordPress is not loaded, so any WordPress function you touch
must be stubbed with Brain Monkey. To add one:

1. Create `tests/Unit/<Thing>Test.php`.
2. Extend the shared base class `BunnifyFrontend\Tests\Unit\TestCase` — it wires
   Brain Monkey's `setUp()` / `tearDown()` for you.
3. Stub any WordPress functions your code calls with
   `Brain\Monkey\Functions\when(...)` / `expect(...)`.
4. Run `composer test`.

A minimal example, modelled on
[`tests/Unit/URLTransformerTest.php`](tests/Unit/URLTransformerTest.php):

```php
<?php

declare( strict_types=1 );

namespace BunnifyFrontend\Tests\Unit;

use Brain\Monkey\Functions;
use BunnifyFrontend\Library\URLTransformer;

/**
 * @covers \BunnifyFrontend\Library\URLTransformer
 */
final class ExampleTest extends TestCase {

	public function test_it_builds_a_cdn_url(): void {
		Functions\when( 'sanitize_text_field' )->returnArg();

		$transformer = new URLTransformer( 'cdn.example.com' );

		$this->assertNotEmpty( $transformer );
	}
}
```

Guidelines:

- Name test files `*Test.php` (the suite only discovers that suffix) and mark
  classes `final`.
- Add a `@covers` annotation so coverage maps to the class under test.
- When testing behaviour that spans a public hook (e.g. `bunnify_url`,
  `bunnify_skip_for_url`), cover the filter contract, not just internals. See
  [`docs/HOOKS.md`](docs/HOOKS.md) for the full list of extension points.

## Commit messages

We use [Conventional Commits](https://www.conventionalcommits.org/). Keep the
subject short (~50 chars) and imperative; add a brief body only when the *why*
isn't obvious.

```
feat: rewrite srcset URLs through the CDN transformer
fix: skip rewriting for data: URIs
docs: document the bunnify_allow_non_upload_url filter
```

Common prefixes: `feat`, `fix`, `docs`, `test`, `refactor`, `chore`, `ci`.

## Branch naming

Branch off `main` using a type prefix:

- `feature/<short-description>` — new functionality.
- `fix/<short-description>` — bug fixes.

For example: `feature/rest-controller` or `fix/srcset-double-encoding`.

## Pull request process

1. Branch from `main` (`feature/*` or `fix/*`).
2. Make your change with tests, and run `composer check` until it is green.
3. Update docs when behaviour changes — including
   [`CHANGELOG.md`](CHANGELOG.md) and the plugin's
   [`bunnify-frontend/readme.txt`](bunnify-frontend/readme.txt) (the source of
   truth for version / "tested up to") when your change is user-facing.
4. Open a PR against `main` with a clear description of the *what* and *why*.
   Link any related issue or blueprint.
5. **CI must be green.** GitHub Actions re-runs `composer lint`,
   `composer analyse`, and `composer test` on PHP 8.2 and 8.3
   (see [`.github/workflows/ci.yml`](.github/workflows/ci.yml)). PRs are not
   merged with a red build.

Keep PRs focused — one logical change per PR reviews faster and reverts cleaner.

## More documentation

- [`docs/DEVELOPMENT.md`](docs/DEVELOPMENT.md) — deeper development notes and
  environment setup.
- [`docs/blueprints/`](docs/blueprints/) — design blueprints for planned
  enhancements (DI container, data-driven settings, REST controller completion,
  full test coverage, and more). A good place to look before proposing larger
  changes.
- [`docs/HOOKS.md`](docs/HOOKS.md) — the public filter surface.
- [`docs/TROUBLESHOOTING.md`](docs/TROUBLESHOOTING.md) and
  [`docs/LOGGING.md`](docs/LOGGING.md) — runtime debugging.

Welcome aboard, and thanks again for contributing.
