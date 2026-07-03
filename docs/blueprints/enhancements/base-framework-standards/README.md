# Bring the Base framework up to standard (or extract it)

- **Status:** Proposed
- **Created:** 2026-07-01
- **Owner:** _unassigned_
- **Related:** [[centralize-cdn-config]], [[cdn-service-provider]]

> **Update (2026-07-02):** the non-recursive `class_uses()` trait-detection bug
> called out below has been fixed ahead of this blueprint
> (`Application::get_traits_recursive()`, unit-tested). This diverges `Base`
> from the downstream consumer copy until a sync happens — one more argument
> for the `bin/sync-base.sh` guard in the rollout plan. Everything else here
> remains open.

## Summary
The `src/php/Base` mini-MVC framework is the load-bearing spine of the plugin — it boots the
application, wires controllers, and merges config — yet it is the one area we deliberately do not
hold to our own quality bar. It is excluded from PHPCS, has no `declare(strict_types=1)`, and uses
docblock-only typing on its properties and constructors. Worse, it is not unique to this plugin: a
byte-for-byte-equivalent copy lives in a downstream consumer's repo under a different namespace
root, so every fix has to be applied twice by hand. This blueprint proposes two mutually exclusive
end-states — **(a)** promote `Base` in-place to WPCS + PHPStan level 5 and fold it into linting, or
**(b)** extract it into a single versioned Composer package consumed by all of the author's plugins
— and recommends a phased path that does (a) first as the enabling step for (b).

## Motivation / Problem
`Base` is treated as vendored code, but it is not vendored in any real sense — it is hand-maintained
source we author, copied between plugins.

- **It is explicitly excluded from linting.** `phpcs.xml.dist` enumerates only
  `bunnify-frontend.php`, `uninstall.php`, and `src/php/{Controller,Library,Model,Function}`; the
  ruleset's own comment points at *this* blueprint as the reason `src/php/Base` is skipped. So the
  framework that every controller extends is never checked for the standards we enforce on the
  controllers themselves.
- **It is only lightly and inconsistently typed.** No file under `Base` declares
  `strict_types`. Properties are untyped with `@var` docblocks only — e.g.
  `Application.php:31-59` (`protected $name = ''`, `protected $controllers = []`,
  `protected $config = null`) and `Controller.php:24` (`protected $config = null`). Constructors are
  untyped too: `Application::__construct( $name, $directory, $controllers )` at
  `Application.php:79` takes three bare parameters. Meanwhile newer code *is* typed —
  `View::set_param( string $name, mixed $value ): void` at `View.php:32` and the FQCN-hinted
  `Config::__construct( \BunnifyFrontend\Base\Main\Application $app )` at `Config.php:49` — so the
  layer is a patchwork, which is the worst of both worlds for readers and for PHPStan inference.
- **There is a latent correctness bug hiding under the "vendored" label.**
  `Application::set_services_for_controller()` at `Application.php:125` detects mix-ins with
  `class_uses( $controller )`, which is **not recursive**: a controller that pulls in `RESTTrait`
  via an intermediate trait (rather than directly) would silently not receive its `REST` service.
  Excluding `Base` from static analysis is exactly how this class of bug stays invisible.
- **It is duplicated across plugins.** The same framework ships in a downstream consumer under a
  sibling plugin's namespace root (its own `Core\Base` instead of `BunnifyFrontend\Base`), with a
  comparable file count (34 files vs. our 33). A third variant appears in yet another sibling
  plugin's namespace. The provenance is documented in `src/php/Base/README.md:1-5`: these files are
  "derived from The Code Companies WPMVC mu-plugin." Today, keeping the copies in sync is a manual,
  error-prone diff-and-rename exercise with no version, no changelog, and no shared test suite.

The net effect: our least-tested, least-linted code is our most-shared, most-critical code.

## Goals
- `src/php/Base` passes `composer lint` (WPCS ruleset in `phpcs.xml.dist`) with **zero** new
  suppressions beyond the file-name/PSR-4 exclusions already granted plugin-wide.
- Every `Base` file declares `strict_types=1` and uses typed properties, parameters, and return
  types wherever the PHP 8.2 floor allows.
- `Base` is inside the PHPStan analysis set at level 5 with **no** `Base/*` entries in
  `phpstan-baseline.neon` (it currently has none — the goal is to keep it that way once linting is
  on and types are added).
- The `class_uses()` trait-detection gap at `Application.php:125` is fixed and covered by a test.
- A single, documented mechanism exists to propagate a `Base` change to every consuming plugin
  (`bunnify-frontend`, downstream consumers, and future ones) — either "one source of truth to copy from"
  (path a) or "one Composer package to `composer update`" (path b).
- No change to the public WordPress filter API (`base_pre_controller_set_instances`,
  `base_post_controller_set_instances`, `base_pre_controller_set_up`, `base_post_controller_set_up`)
  or to controller-facing method signatures that consumers already call.

## Non-goals
- Rewriting the framework's design (the Application/Controller/Config/Trait model stays as-is).
- Changing the plugin's public hooks, option names (`bunnify_hostname` et al.), or the
  `CDNController`/`URLTransformer` behaviour — those are separate blueprints
  ([[centralize-cdn-config]]).
- Reconciling the upstream **WPMVC** mu-plugin itself. We treat WPMVC as historical provenance, not
  as a live dependency to re-adopt (that would be its own investigation).
- Adopting PHP 8.3+ features (readonly-by-default, typed constants) — the floor is 8.2 per
  `composer.json` and the plugin header.
- Raising PHPStan above level 5 for the whole plugin (can be a follow-up once `Base` is clean).

## Current state
The framework boots from `bunnify-frontend.php`: the entry file loads
`build-tools/vendor/autoload.php`, guards against double-loading with the
`BunnifyFrontend\APP_NAME` constant (`bunnify-frontend.php:37-44`), then constructs one
`Application` with the plugin name, directory, and a hand-written list of six controller instances
(`bunnify-frontend.php:46-57`).

- `Application::__construct()` stores those, calls `load_config()` (which news up a `Config` and
  autoloads `config/*.php` + environment overrides), and hooks `setup_controllers()` onto
  `plugins_loaded` (`Application.php:79-87`).
- On `plugins_loaded`, `setup_controllers()` iterates the controllers, runs each through the four
  `base_*` filters, injects services based on the traits it uses, injects the shared `Config`, and
  calls the controller's `set_up()` (`Application.php:95-114`).
- Services are lazily created and memoised in `$this->services` and matched to controllers by
  `class_uses()` (`Application.php:124-144`).
- `Controller` is an abstract base with an abstract `set_up()` plus `get_config()` /
  `set_config_instance()` (`Controller.php:17-53`). The concrete `Controller/*` classes in the
  plugin extend it and are the code we *do* lint.
- `Config` autoloads and recursively merges `config/*.php` with environment-specific overrides
  (`Config.php:123-171`).

Tooling boundaries today:
- **PHPCS** — `Base` is excluded (see file list in `phpcs.xml.dist`); the rest of `src/php` is
  linted against the `WordPress` ruleset with the PSR-4 filename sniff and short-array sniff turned
  off.
- **PHPStan** — level 5 over `bunnify-frontend/src/php` *including* `Base`, with a baseline that
  currently holds no `Base` entries. So `Base` is nominally analysed but has never been forced
  through PHPCS, and its thin typing gives PHPStan little to work with.

## Proposed approach
Two candidate end-states. They share Phase 1 (typing + linting), then diverge.

### Path A — Standardise in place
Keep `Base` as source inside each plugin, but hold it to the same bar as the rest of the code.

1. **Add `strict_types` and real types.** Convert docblock `@var` to typed properties and add
   parameter/return types. Illustrative, for `Application`:

   ```php
   <?php

   declare(strict_types=1);

   namespace BunnifyFrontend\Base\Main;

   final class Application {
       protected string  $name;
       protected string  $directory;
       protected string  $directory_uri;
       /** @var array<int, Controller> */
       protected array   $controllers;
       protected ?Config $config = null;

       /** @var array{route: ?Route, rest: ?REST, admin_ajax: ?AdminAjax} */
       protected array $services = [ 'route' => null, 'rest' => null, 'admin_ajax' => null ];

       /**
        * @param array<int, Controller> $controllers
        */
       public function __construct( string $name, string $directory, array $controllers ) { /* ... */ }
   }
   ```

   Note the residual generics (`array<int, Controller>`, the `services` shape) still live in
   docblocks — that is expected and is exactly what pushes PHPStan value up once the layer is in
   scope with no baseline hiding it.

2. **Fix the `class_uses()` recursion gap.** Replace the direct call at `Application.php:125` with a
   recursive helper (walk `class_uses()` across the parent chain and nested traits, or use a
   `class_uses_deep()` utility) so trait-detected service injection is correct regardless of how a
   controller composes its traits.

3. **Fold `Base` into PHPCS.** Add `src/php/Base` to the `<file>` list in `phpcs.xml.dist`, delete
   the "excluded for now" comment, run `composer lint:fix`, and hand-resolve the remainder. Keep the
   baseline empty for `Base`; if a small number of unavoidable WPCS findings remain (e.g.
   deliberate dynamic hook names), suppress them narrowly and in-file with justification rather than
   broadening the exclude list.

4. **Keep copies in sync with a scripted vendor-sync, not hand edits.** Designate one repo as the
   canonical `Base` source and add a `bin/sync-base.sh` that copies files into each consumer and
   rewrites the namespace root (`BunnifyFrontend\Base` ↔ a consumer's own `Core\Base`) via a documented
   sed/AST pass, with a CI check that fails if a consumer's `Base` has drifted from canonical. This
   is cheap and needs no autoloader changes, but it is still copy-on-write.

**Trade-off:** lowest risk, no packaging or namespace churn, works with the existing per-plugin
autoloader and the `APP_NAME` double-load guard. But it does not actually remove the duplication —
it only makes the duplication linted and disciplined.

### Path B — Extract to a Composer package
Promote `Base` to a standalone library, e.g. `thecodecompany/wp-base` (or `anthonythorne/wp-base`),
versioned with SemVer, with its own `phpcs.xml`, `phpstan.neon`, and PHPUnit suite. Consumers depend
on it and drop their in-tree `Base`.

```jsonc
// consumer composer.json
"require": { "php": ">=8.2", "thecodecompany/wp-base": "^1.0" }
```

The hard problem is **namespaces and multi-plugin coexistence**. Today each plugin re-namespaces its
copy (`BunnifyFrontend\Base`, a consumer's own `Core\Base`) precisely so two active plugins on one site
never collide and each loads its own copy through its own `build-tools/vendor/autoload.php`. A shared
package has one fixed namespace (say `TheCodeCompany\WPBase`). Two options:

- **B1 — shared dependency, single copy.** Both plugins require the package; whichever loads first
  wins. The `APP_NAME` guard in `bunnify-frontend.php:37-44` already prevents *this* plugin from
  re-booting, but it does not prevent two *different* plugins from shipping *different* versions of
  the same package. Requires disciplined version alignment across plugins on the same site.
- **B2 — scoped/prefixed copies.** Run [PHP-Scoper](https://github.com/humbug/php-scoper) at build
  time so each plugin bundles `BunnifyFrontend\Vendor\TheCodeCompany\WPBase`, restoring today's
  collision-free isolation at the cost of a build step. This preserves current runtime semantics
  most faithfully.

Either way, the framework's own `strict_types`, typed API, and tests live in one repo, and a fix is
a `composer update` — the sync problem disappears.

**Trade-off:** highest long-term payoff (single source, real versioning, one test suite), but the
biggest migration cost and the coexistence question above must be answered before adoption.

### Recommendation
Do **Path A first** — it is the enabling work either way (you cannot cleanly extract untyped,
unlinted code) and it delivers value immediately. Then, once `Base` is green and covered by tests,
evaluate **Path B (with B2/PHP-Scoper)** as a fast-follow, because the duplication across
`bunnify-frontend` and its sibling plugins is the real cost and only extraction removes it.

## Migration & backwards compatibility
- **Public filter API is frozen.** The four `base_*` filter names and their argument shapes in
  `setup_controllers()` (`Application.php:99-113`) do not change. Consumers hooking them are
  unaffected.
- **Controller-facing signatures stay compatible.** Adding parameter/return types must not narrow
  what callers already pass. `set_config_instance(Config $config)` is already typed; new types on
  `Application::__construct` merely codify the existing contract (`string, string, array`). Any
  consumer calling with wrong types was already relying on undefined behaviour.
- **Downstream consumer impact.** Because a consumer carries its own re-namespaced copy, Path A
  changes reach it only through the sync script — it can adopt on its own schedule; nothing breaks
  until it syncs. Path B is the disruptive one: a consumer shipped as an mu-plugin and its sibling
  plugins would each need to add the Composer dependency and delete their in-tree `Base`, which is
  why B is gated behind A and behind resolving B1-vs-B2. Recommend piloting extraction in
  `bunnify-frontend` (a normal plugin with a clean `build-tools/vendor`) before touching the
  mu-plugin consumers.
- **`strict_types` behavioural risk.** Enabling strict typing can turn previously-coerced scalars
  (e.g. an int passed where a string is hinted) into `TypeError`s. This is a real, if small,
  behaviour change and is why typing lands behind tests (below) and rolls out file-by-file.

## Risks & mitigations
- **`strict_types` surfaces latent type coercion at runtime.** → Land types incrementally, one class
  per PR; rely on the WordPress stubs in `szepeviktor/phpstan-wordpress` and the test suite to catch
  mismatches before release.
- **Fixing `class_uses()` recursion changes which services get injected.** → It only *adds* missing
  injections; guard with a dedicated test asserting a controller using a trait-of-a-trait receives
  its service, and smoke-test the six real controllers in `bunnify-frontend.php:46-57`.
- **Sync-script drift (Path A).** → CI job that diffs each consumer's `Base` against canonical
  (namespace-normalised) and fails on divergence; make the script the *only* sanctioned way to edit
  `Base`.
- **Version skew / double-load (Path B1).** → Prefer B2 (PHP-Scoper) so each plugin is isolated; if
  B1, add a runtime version-compat assertion and align versions in a lockstep release.
- **Scope creep into a framework rewrite.** → Non-goals fence this off; typing and linting only, no
  redesign.

## Testing strategy
- **Unit (Brain Monkey / PHPUnit 9, already in `require-dev`).** Cover `Application::setup_controllers()`
  wiring — assert each `base_*` filter fires in order, `Config` is injected, and `set_up()` is
  called. Add the trait-recursion regression test for `set_services_for_controller()`. Cover
  `Config::get()` defaults and `autoload()`'s recursive env-override merge (`Config.php:123-171`).
- **Static analysis as a gate.** After typing each class, confirm PHPStan level 5 stays green with
  **no** new `Base/*` baseline entries; treat a needed baseline entry as a signal to fix the type,
  not to suppress.
- **Lint as a gate.** `composer lint` must pass with `Base` included and no broadened excludes.
- **Package CI (Path B).** The extracted repo runs its own `lint` + `analyse` + `test` matrix on
  PHP 8.2/8.3; a downstream "consumer smoke" job installs it into a throwaway plugin and boots the
  `Application` to catch namespace/scoper regressions.

## Rollout plan
1. **Baseline & bug-fix (S).** Add `Base` to PHPStan intent (it already is), fix the
   `class_uses()` recursion at `Application.php:125`, and land the wiring/regression tests — green
   before any type changes.
2. **Type the core (M).** `Main/Application`, `Main/Controller`, `Library/Config` first (the classes
   every plugin touches): add `strict_types`, typed props/params/returns. One PR per file.
3. **Type the rest (M).** Remaining `Library/*`, `Traits/*`, `Model/*`, `Main/*` — following the
   already-typed pattern in `View.php:32`.
4. **Turn on linting (S).** Add `src/php/Base` to `phpcs.xml.dist`, run `lint:fix`, resolve the tail,
   remove the "excluded for now" comment.
5. **Sync guard (S).** Add `bin/sync-base.sh` + the CI drift check; propagate the standardised `Base`
   to the sibling-plugin consumers once, establishing a clean shared baseline. *(End state for Path A.)*
6. **Extraction spike (L, optional).** Stand up `thecodecompany/wp-base`, move the now-clean files,
   pick B1 vs B2 (recommend B2/PHP-Scoper), migrate `bunnify-frontend` as the pilot consumer, then
   the mu-plugin consumers. *(End state for Path B.)*

## Effort estimate
**M** to reach the Path A end-state (typing + linting + sync guard is mechanical, well-bounded work
on ~33 files with an existing test toolchain); **L–XL** additionally if Path B extraction and the
multi-plugin namespace/scoper migration are pursued.
