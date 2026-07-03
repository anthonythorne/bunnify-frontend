# Runtime autoloader packaging for WordPress.org review

- **Status:** Implemented (Option B, 2026-07-02)
- **Created:** 2026-07-01
- **Owner:** Anthony Thorne
- **Related:** [[base-framework-standards]] (the `src/php/Base` mini-framework this loader has to resolve), [[cdn-config-consolidation]] (a sibling wp.org-prep cleanup)

> **Update (2026-07-02): Option B implemented.** The plugin now boots from a
> hand-written `autoload.php` at the plugin root (PSR-4 onto `src/php/` via a
> pure, unit-tested path mapper `BunnifyFrontend\autoload_class_path()`, plus
> the `Function/*.php` side-effect includes). `build-tools/` is deleted from
> source, `.distignore`/`.gitignore`/PHPCS/PHPStan updated, and `bin/build.sh`
> hard-fails if any Composer manifest, `build-tools/`, or `vendor/` survives
> staging — or a runtime essential is missing. A CI `package` job builds the
> zip and asserts its contents on every push. Tests:
> `tests/Unit/AutoloaderTest.php`, including an inverse walk proving every
> `src/php/` class file is reachable by the loader (the guarantee the old
> hand-committed classmap silently lacked — see commit 0446c59's manual
> refresh). Not done from the plan: the packaging PHPUnit test (superseded by
> the build.sh guards + CI job) and Plugin Check in CI (deferred to the wp.org
> submission checklist). The downstream consumer's sync script no
> longer needs its Composer regenerate step — updated the same day.

## Summary

The plugin boots by `require`-ing `build-tools/vendor/autoload.php`, a Composer-generated
autoloader whose PSR-4 map points *up and out* of its own directory (`../src/php`). For a
WordPress.org reviewer, a `build-tools/` folder reads like dev tooling that should not ship, and
the `..`-traversal paths look fragile. This blueprint proposes moving the runtime autoloader to a
clean plugin-root location — either a conventional `vendor/autoload.php` generated with
root-relative paths, or a small hand-written PSR-4 loader with **zero** Composer footprint — so the
shipped zip contains only the code needed to run, loaded from an obviously-runtime path.

## Motivation / Problem

Two concrete problems, both visible to a wp.org reviewer skimming the zip:

1. **The runtime autoloader lives under a "dev tooling" name.** The entry file loads it here:

   ```php
   // bunnify-frontend/bunnify-frontend.php:29
   $autoload_file = __DIR__ . '/build-tools/vendor/autoload.php';
   ...
   // bunnify-frontend/bunnify-frontend.php:36
   require_once $autoload_file;
   ```

   `build-tools/` is exactly the sort of directory reviewers expect to be *excluded* from a
   distribution, not required on every request. We keep it deliberately (`.gitignore` has an
   explicit "do not ignore" note, and `bunnify-frontend/.distignore:16-18` strips the manifests
   but keeps `build-tools/vendor/`), but that intent is invisible to a first-time reader.

2. **The generated map escapes its own vendor dir with `..`.** `build-tools/composer.json:11-16`
   declares parent-relative sources:

   ```json
   "autoload": {
       "files":  [ "../src/php/Function/AutoLoad.php" ],
       "psr-4":  { "BunnifyFrontend\\": "../src/php/" }
   }
   ```

   which Composer bakes into the generated static loader as literal upward traversal:

   ```php
   // build-tools/vendor/composer/autoload_static.php:10,23
   'ed2c56860cf0985c067bfa922138ff0d' => __DIR__ . '/../..' . '/../src/php/Function/AutoLoad.php',
   0 => __DIR__ . '/../..' . '/../src/php',
   ```

   So the vendor directory physically points at a *sibling* tree (`build-tools/../src/php`). It
   works, but it is brittle (any relocation of `build-tools/` or `src/php/` breaks it silently) and
   it is the opposite of what "vendored" normally means (self-contained under `vendor/`).

3. **The build path that would produce a clean layout does not exist yet.** `composer.json`
   (repo root) advertises a `"build": "bash bin/build.sh"` script, but there is no `bin/`
   directory — so today the shipped `build-tools/vendor/` is a hand-committed artefact, not a
   reproducible build output.

None of this is a runtime bug. It is a *reviewability* and *maintainability* problem that
directly raises the risk of a wp.org rejection or a back-and-forth review.

## Goals

- The shipped zip loads its autoloader from a path that plainly reads as runtime code
  (`vendor/autoload.php` or `autoload.php` at the plugin root), not from `build-tools/`.
- No Composer *manifest* (`composer.json`, `composer.lock`) ships in the zip.
- The autoloader map contains **no `..` traversal** — every mapped path is at or below the loader's
  own directory.
- The bootstrap change is a one-line `require` path edit in `bunnify-frontend.php` plus a
  reproducible build step; no controller, library, or public-hook code changes.
- A packaging test can assert, from the built zip, that (a) the loader file exists, (b) the
  manifests are absent, and (c) `BunnifyFrontend\*` classes autoload.
- The double-load guard (`BunnifyFrontend\APP_NAME`, `bunnify-frontend.php:39`) keeps working.

## Non-goals

- Not changing the `src/php` PSR-4 namespace root, class names, or file layout.
- Not touching the `Function/AutoLoad.php` glob side-effect loader's *behaviour* — only *how* it is
  invoked (see below).
- Not linting or refactoring `src/php/Base` — that is [[base-framework-standards]].
- Not adding third-party runtime dependencies. This plugin ships zero external packages; the whole
  point is that the "vendor" tree is autoloader scaffolding only.
- Not changing the dev toolchain autoload (root `composer.json` PSR-4 for tests/PHPStan is
  unaffected).

## Current state

- `bunnify-frontend.php:29-36` requires `build-tools/vendor/autoload.php`, bailing early if it is
  missing, then defines `BunnifyFrontend\APP_NAME` (line 44) and constructs the `Application` with
  the controller list (lines 47-58).
- `build-tools/composer.json` is a tiny manifest with a single constraint (`php >=8.2`), a PSR-4
  entry (`BunnifyFrontend\\ -> ../src/php/`) and one `files` entry
  (`../src/php/Function/AutoLoad.php`). There are **no `require`d packages**, so `vendor/` is pure
  Composer autoloader plumbing (`ClassLoader.php`, `autoload_static.php`, `InstalledVersions.php`,
  `platform_check.php`, etc.).
- `src/php/Function/AutoLoad.php` is *not* a class file — it is a side-effect loader that `glob()`s
  `src/php/Function/*.php` and `require_once`es each one (with an optional `$specific_file_load_order`
  hook). Composer pulls it in via the `files` autoload key, so it runs once at boot.
- `.gitignore` intentionally keeps `bunnify-frontend/build-tools/vendor/` tracked; `.distignore`
  strips `build-tools/composer.json` + `composer.lock` but keeps `build-tools/vendor/`. So the
  shipped zip already contains the *vendor* dir under `build-tools/` — the manifests are stripped,
  the plumbing stays.
- phpcs excludes `*/build-tools/vendor/*` and `*/vendor/*` (`phpcs.xml.dist`), so whichever
  autoloader we keep is currently unlinted.
- A downstream consumer carries a vendored *copy* of the plugin at a
  consuming site's `wp-content/plugins/bunnify-frontend/` (a plain directory, not a symlink or Composer path repo).
  Its entry file requires `build-tools/vendor/autoload.php` at line 27, mirroring trunk.

## Proposed approach

Two viable targets. Recommend **Option B** (hand-written loader) as the primary, with **Option A**
as the conservative fallback if we want to stay on Composer-generated output.

### Option A — relocate to a root `vendor/` with root-relative paths

Add a plugin-root manifest whose PSR-4 root is `src/php/` (no `..`), and generate into `vendor/`:

```jsonc
// bunnify-frontend/composer.json  (build-only; stripped from the zip)
{
  "name": "anthonythorne/bunnify-frontend",
  "type": "wordpress-plugin",
  "require": { "php": ">=8.2" },
  "autoload": {
    "files": [ "src/php/Function/AutoLoad.php" ],
    "psr-4": { "BunnifyFrontend\\": "src/php/" }
  },
  "config": { "optimize-autoloader": true, "classmap-authoritative": true }
}
```

Build step: `composer dump-autoload --no-dev --classmap-authoritative --working-dir bunnify-frontend`,
which emits `bunnify-frontend/vendor/` with paths like `__DIR__ . '/../..' . '/src/php'` — still a
`..` back to the plugin root, but no longer *escaping* into a sibling. Entry file becomes:

```php
$autoload_file = __DIR__ . '/vendor/autoload.php';
```

`.distignore` strips `composer.json`, `composer.lock`, and (optionally) the whole `build-tools/`.

Pros: familiar; keeps classmap optimization; matches other Composer-based author plugins. Cons:
still ships `vendor/composer/*` generated files (`ClassLoader.php`, `platform_check.php` — the
latter can `exit` on a PHP-version mismatch, which some reviewers flag), and reviewers still see
Composer scaffolding for a plugin with no dependencies.

### Option B — hand-written PSR-4 loader, zero Composer footprint (recommended)

For a zero-dependency plugin, a ~30-line loader is more auditable than Composer's plumbing and
removes every "is this dev tooling?" question. Ship a single file at the plugin root:

```php
// bunnify-frontend/autoload.php  (illustrative)
namespace BunnifyFrontend;

spl_autoload_register( static function ( string $class ): void {
    $prefix   = __NAMESPACE__ . '\\';                 // 'BunnifyFrontend\'
    $base_dir = __DIR__ . '/src/php/';
    $len      = strlen( $prefix );
    if ( 0 !== strncmp( $prefix, $class, $len ) ) {
        return;                                        // not ours
    }
    $relative = substr( $class, $len );
    $file     = $base_dir . str_replace( '\\', '/', $relative ) . '.php';
    if ( is_readable( $file ) ) {
        require $file;
    }
} );

// Preserve the existing side-effect function loader (formerly the Composer `files` entry).
require_once __DIR__ . '/src/php/Function/AutoLoad.php';
```

Entry file change:

```php
// bunnify-frontend/bunnify-frontend.php:29
$autoload_file = __DIR__ . '/autoload.php';
```

The early-bail (`file_exists`) and `APP_NAME` guard (lines 32-44) stay exactly as they are.

Pros: no `build-tools/`, no `vendor/`, no `composer.json`/`lock` in the zip; every path is
`__DIR__`-anchored and below the plugin root; the loader is plugin code we *lint and static-analyse*
like everything else. Cons: no classmap prewarming (negligible for ~a dozen classes; PSR-4 stats are
cheap and WP opcache-caches them), and it diverges from any sibling plugin that leans on Composer —
acceptable given this plugin vendors nothing.

### Build step (either option)

Author the missing `bin/build.sh` referenced by the root `composer.json` `"build"` script:

1. `rsync` the plugin dir into `dist/bunnify-frontend/` honouring `.distignore`.
2. Option A: run `composer dump-autoload --no-dev --classmap-authoritative` into the staging copy;
   Option B: nothing to generate — the loader is source.
3. Remove `build-tools/`, `composer.json`, `composer.lock` from the staging copy.
4. `zip -r dist/bunnify-frontend.zip bunnify-frontend`.
5. Assert-and-fail if any manifest or `build-tools/` survived (the packaging guard, below).

## Migration & backwards compatibility

- **Public API is untouched.** The plugin's contract is its WordPress filters/actions (see
  `docs/HOOKS.md`) and the single `bunnify_hostname` option. This change is purely bootstrap +
  packaging; no hook name, signature, or option key moves. Existing integrators are unaffected.
- **Downstream consumer.** Its plugin copy at a consuming site's `wp-content/plugins/bunnify-frontend/` is a
  wholesale vendored copy, not a symlink or Composer path repo, so it is replaced as a unit when it
  next syncs the new build. Because the new build's own entry file carries the new `require` path,
  the copy is self-consistent — **no edit to any consumer `composer.json` is required.** The one
  thing to verify: the consumer must pull the *built* layout (root `autoload.php` / `vendor/`), not
  cherry-pick the old `build-tools/vendor/` tree. Call this out in the consumer's update notes.
- **Idempotent double-load.** The `BunnifyFrontend\APP_NAME` guard already prevents a second boot if
  two copies are active; the new loader path does not change that.
- **Rollback** is a one-line revert of the `require` path plus restoring `build-tools/vendor/` —
  cheap, because no source moved.

## Risks & mitigations

- **Class fails to autoload after the switch (typo in prefix/base path).** Mitigate with the
  packaging smoke test that instantiates `Application` and asserts a controller resolves; run it in
  CI against the *built* zip, not the source tree.
- **Option B drift from Composer conventions.** Some contributors expect `vendor/`. Mitigate with a
  short header comment in `autoload.php` and a note in the build script explaining the deliberate
  zero-dependency choice; link this blueprint.
- **`Function/AutoLoad.php` stops running** if we forget to re-`require` it (it was the Composer
  `files` entry, invisible in the new loader). Mitigate by keeping the explicit `require_once` in
  the new loader and covering "a function file's side effect happened" in a test.
- **Reviewer still sees `build-tools/` if `.distignore` misses it.** Mitigate with the build-guard
  step that hard-fails on any surviving `build-tools/`, `composer.json`, or `composer.lock`.
- **Linting a previously-excluded file.** The new `autoload.php` becomes plugin code; add it to the
  phpcs `<file>` list and fix any WPCS nits (it will need a `phpcs:ignore` for the `spl_autoload_register`
  closure only if WPCS objects — expected clean).

## Testing strategy

- **Unit / integration (Brain Monkey + PHPUnit 9):** a bootstrap test that includes the new loader
  and asserts `class_exists( \BunnifyFrontend\Controller\CDNController::class )` and that a
  `Function/*.php` side effect fired. Assert the `APP_NAME` guard prevents a second define.
- **Packaging test (the load-bearing one):** run `bin/build.sh` in CI, unzip to a temp dir, and
  assert: `autoload.php` (or `vendor/autoload.php`) exists; `composer.json`, `composer.lock`, and
  `build-tools/` are **absent**; and a subprocess `php -r` that requires the loader can resolve a
  `BunnifyFrontend\*` class. Grep the built tree for `'/../..'`/`../src` to prove no upward traversal
  ships (Option B) or that it stays within the plugin root (Option A).
- **Plugin Check (PCP):** run the official `plugin-check` against the built zip and confirm no new
  "files that shouldn't be in a release" or autoloader findings.
- **Static analysis / lint:** add the loader to phpcs and confirm PHPStan level 5 still passes with
  the new file in scope (update the baseline only if unavoidable).

## Rollout plan

1. **Author the build script.** Write `bin/build.sh` producing `dist/` from `.distignore`, wire it
   to the existing `composer build` script, and add the build-guard assertions. No behaviour change
   yet — it should reproduce today's `build-tools/vendor/` layout first, to prove the pipeline.
2. **Introduce the new loader behind the same entry point.** Add `autoload.php` (Option B) or the
   root `composer.json` + generated `vendor/` (Option A). Do **not** change the `require` path yet;
   land the loader and its unit test.
3. **Flip the bootstrap.** Change `bunnify-frontend.php:29` to the new path; run the full CI gate
   (`composer check`) plus the packaging test and PCP.
4. **Strip the old tree.** Remove `build-tools/` from the source (Option B) or reduce it to the
   build-only manifest (Option A); update `.distignore`/`.gitignore` and their explanatory comments.
5. **Coordinate the consumer.** Sync the downstream consumer's vendored copy to the new build and smoke-test the
   site; document that the copy must track built output.
6. **Tag a release build** and archive the zip that passed PCP as the wp.org submission candidate.

## Effort estimate

**M** — the code delta is a one-line `require` change plus a small loader, but the honest cost is in
the *missing* build tooling (`bin/build.sh` does not exist), the packaging/CI assertions, PCP
validation, and coordinating the downstream consumer's copy — a day or two, not an afternoon.
