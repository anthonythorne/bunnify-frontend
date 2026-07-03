# Dependency Injection & a Shared CDN Service Layer

- **Status:** Proposed
- **Created:** 2026-07-01
- **Owner:** _unassigned_
- **Related:** [[static-to-instance-url-transformer]], [[unify-hostname-option-access]], [[settings-static-accessors-to-service]]

## Summary

Bunnify currently resolves its CDN configuration and builds `URLTransformer` instances in at
least four independent places — a private `init_cdn()` duplicated verbatim in `CDNController`
and `ContentController`, a parallel `init_static_cdn()` singleton inside `URLTransformer`, plus
several ad-hoc `get_option( 'bunnify_hostname' )` reads. This blueprint proposes collapsing all of
that into a single, lazily-resolved **CDN service** (hostname resolution + a memoized
`URLTransformer`) that is injected into controllers through the framework's existing
trait-driven service-injection mechanism (`Application::set_services_for_controller`), replacing
the `URLTransformer::$static_instance` singleton. The public `bunnify_url` filter API and every
`bunnify_*` hook stay byte-for-byte compatible, so a downstream consumer is unaffected.

## Motivation / Problem

The "read the option, guard on empty, `new URLTransformer`" dance is copy-pasted and the CDN
hostname is read from the database from several unrelated call sites:

- `src/php/Controller/CDNController.php:52-66` — `init_cdn()` reads `get_option( 'bunnify_hostname' )`,
  bails when empty, and lazily constructs `new URLTransformer( $this->bunnify_hostname )`.
- `src/php/Controller/ContentController.php:68-82` — a **character-for-character duplicate** of the
  same `init_cdn()` method, with its own private `$url_transformer` / `$bunnify_hostname` fields.
- `src/php/Library/URLTransformer.php:439-453` — `init_static_cdn()` is a *third* copy of the same
  logic, but as a static singleton backed by `URLTransformer::$static_instance`
  (`URLTransformer.php:425`) and `URLTransformer::$static_hostname` (`URLTransformer.php:432`).
- Additional stray hostname reads: `ContentController.php:340` (inside `transform_url_direct()`)
  and `URLTransformer.php:394` (inside the static `is_cdn_url()`), each independently calling
  `get_option( 'bunnify_hostname' )`.

Consequences:

- **Two lifetimes for the same concept.** `CDNController::cdn_url()` mixes an *instance* transformer
  (`$this->init_cdn()` at `CDNController.php:115`) with the *static* singleton
  (`URLTransformer::get_cdn_url_by_id()` at `CDNController.php:104`) within a single method — the
  attachment-ID path and the fallback path resolve the hostname through different mechanisms.
- **Testability.** The `URLTransformer::$static_instance` singleton retains state between tests and
  can only be reset with reflection; there is no seam to inject a fake transformer or a stub
  hostname, so Brain Monkey specs must stub `get_option` globally instead of injecting a service.
- **Config drift risk.** Five+ `get_option( 'bunnify_hostname' )` sites mean any change to how the
  hostname is resolved (e.g. honoring `bunnify_enabled`, a constant override, or a multisite
  network option) must be applied in five places or it silently diverges.
- **Framework already has the seam, unused here.** `Application::set_services_for_controller()`
  (`Application.php:124-144`) already injects shared `Route`/`REST`/`AdminAjax` instances by
  inspecting `class_uses()`. The CDN service is exactly the kind of shared dependency this
  mechanism exists for, yet controllers hand-roll it instead.

## Goals

- One place resolves the CDN hostname and decides enabled/disabled; every controller and library
  consumes that decision rather than re-reading `get_option( 'bunnify_hostname' )`.
- Exactly one `URLTransformer` instance per request, created lazily on first use, injected — not
  fetched from a static singleton.
- `URLTransformer::$static_instance`, `$static_hostname`, and `init_static_cdn()` are deleted;
  `URLTransformer` becomes a pure instance class with no static request state.
- `CDNController::init_cdn()` and `ContentController::init_cdn()` are removed; both controllers hold
  an injected service, not private `$url_transformer` / `$bunnify_hostname` fields.
- The injection is unit-testable: a test can hand a controller a fake CDN service with no database.
- Zero change to the public hook surface — `bunnify_url` and all `bunnify_*` filters behave identically.

## Non-goals

- No change to URL-building behavior, query-arg mapping, `-scaled` handling, srcset logic, or the
  local-dev bypass — this is a wiring refactor, not a behavior change.
- Not adopting a full PSR-11 container or a third-party DI library; the framework stays dependency-free.
- Not restructuring `ImageController` / `RESTController` beyond swapping their static
  `URLTransformer::` calls onto the service (they are secondary consumers, migrated last).
- Not touching `SettingsController::is_local_dev_mode_enabled()` / `is_debug_enabled()` static
  accessors here — that is a sibling concern (see [[settings-static-accessors-to-service]]).
- No new admin option; `bunnify_hostname` remains the single source of truth.

## Current state

`bunnify-frontend.php:47-58` constructs the `Application` with a plain array of freshly-`new`ed,
argument-less controllers:

```php
$bunnify_app = new \BunnifyFrontend\Base\Main\Application(
    APP_NAME,
    __DIR__,
    [
        new \BunnifyFrontend\Controller\CDNController(),
        new \BunnifyFrontend\Controller\ContentController(),
        // ...
    ],
);
```

On `plugins_loaded`, `Application::setup_controllers()` (`Application.php:95-115`) walks that array
and, for each controller: fires `base_pre_controller_set_instances`, calls
`set_services_for_controller()`, fires `base_post_controller_set_instances`, injects config via
`set_config_instance()`, then calls `set_up()` (wrapped in `base_pre/post_controller_set_up`).

`set_services_for_controller()` (`Application.php:124-144`) is the existing injection point. It reads
`class_uses( $controller )` and, per matched trait, lazily builds a shared service stored in
`$this->services` (`Application.php:66-70`) with the null-coalescing-assign idiom and calls the
matching setter:

```php
if ( isset( $used_traits[ RouteTrait::class ] ) ) {
    $this->services['route'] ??= new Route();
    $controller->set_route_instance( $this->services['route'] );
}
```

The base `Controller` (`src/php/Base/Main/Controller.php`) provides `set_config_instance()` /
`get_config()`; the per-service setters (`set_route_instance()` etc.) live on the traits
themselves (e.g. `RouteTrait::set_route_instance`). Crucially, `src/php/Base` is treated as a
vendored mini-framework shared across the author's plugins and is **excluded from phpcs** — so any
change there is both cross-plugin and outside the lint gate, which the design must weigh.

Today's CDN wiring bypasses all of this: `CDNController` and `ContentController` each own private
`$url_transformer`/`$bunnify_hostname` fields and a duplicated `init_cdn()`, while `URLTransformer`
keeps its own static singleton for the `get_cdn_url_by_id()` / `is_cdn_url()` static entry points
that `CDNController`, `ContentController`, and `ImageController` all call.

The **consumer coupling is only through filters.** A consumer integrates every point
through `apply_filters( 'bunnify_url', ... )` (e.g. a consumer's image renderer and video-poster
helpers, image function, and preloader controller) plus the
`bunnify_allow_non_upload_url` and `bunnify_skip_image` filters added in the consumer's integration
controller. The consumer never does `new URLTransformer` and never calls the
static methods directly — which makes this refactor safe to do behind the filter seam.

## Proposed approach

Introduce a single plugin-space service class and inject it through a generalized version of the
existing trait mechanism.

### 1. `CdnService` (plugin code, linted, testable)

Lives in `src/php/Library/CdnService.php` so it is inside the phpcs-covered plugin tree (not the
excluded `Base`). It owns hostname resolution and the one `URLTransformer`:

```php
final class CdnService {
    private ?string $hostname = null;
    private bool $resolved = false;
    private ?URLTransformer $transformer = null;

    /** Memoized single read of the option (behind a filter for overrides). */
    public function get_hostname(): ?string { /* get_option( 'bunnify_hostname' ), once */ }

    public function is_enabled(): bool { return '' !== (string) $this->get_hostname(); }

    /** Lazily builds and caches the one URLTransformer, or null when disabled. */
    public function transformer(): ?URLTransformer;

    // Instance replacements for today's static entry points:
    public function transform_url( string $url, array|string $args = [], ?string $scheme = null ): string;
    public function url_by_attachment_id( int $id, array|string $args = [], ?string $scheme = null ): ?string;
    public function is_cdn_url( string $url ): bool;
}
```

`URLTransformer` keeps its instance API (`transform_url()`, `build_cdn_url_from_attachment()`, the
static *pure* helpers `validate_image_url()` / `image_exists_locally()` which take no request state)
but loses `$static_instance`, `$static_hostname`, and `init_static_cdn()`. The
attachment-ID logic currently in the static `get_cdn_url_by_id()` (`URLTransformer.php:463-478`)
moves onto the instance and is reached via `CdnService::url_by_attachment_id()`.

### 2. Injection via a generalized service registry (recommended)

Rather than hard-coding a fourth `if ( isset( $used_traits[...] ) )` branch into the
lint-excluded, cross-plugin `Base`, make `set_services_for_controller()` data-driven so the *CDN
domain* stays entirely in plugin space:

```php
// Base stays generic: a map of trait => [ factory, setter ].
$this->register_service( CdnAwareTrait::class, fn() => new CdnService(), 'set_cdn_service' );
```

- Define `CdnAwareTrait` (in `src/php/Base/Traits` or, to keep Base domain-free, in a plugin
  `src/php/Trait` namespace) exposing `set_cdn_service( CdnService $s )` and a protected `$cdn`.
- `CDNController` and `ContentController` `use CdnAwareTrait`; the shared instance is built once and
  stored alongside `route`/`rest`/`admin_ajax` in `Application::$services`.
- `bunnify-frontend.php` registers the CDN service factory when constructing the `Application`, so
  the framework never names a CDN concept.

Then the controllers shrink: `CDNController::cdn_url()` becomes
`$this->cdn->transform_url( ... )` / `$this->cdn->url_by_attachment_id( ... )` with no `init_cdn()`,
and `ContentController`'s three `$this->init_cdn() ? $this->url_transformer->... : ...` sites
(`ContentController.php:115`-equivalent, `:425`, `:449`) and `transform_url_direct()`'s stray
`get_option` (`:340`) all route through `$this->cdn`.

### 3. Fallback: a tiny plugin-local container

If touching `Base` at all is undesirable (it is shared and lint-excluded), inject the service the
same way `Config` is injected: add a generic `set_container()` (or `set_cdn_service()`) on the base
`Controller` and hand controllers the instance in `setup_controllers()` from a one-object
plugin container constructed in `bunnify-frontend.php`. This keeps `Base` almost untouched but
introduces a second wiring path parallel to the trait mechanism. **Recommendation:** prefer the
generalized registry (option 2) — it removes, rather than adds, special-casing; fall back to the
container only if cross-plugin `Base` churn must be avoided this cycle.

## Migration & backwards compatibility

- **Public filter API is frozen.** `bunnify_url`, `bunnify_content`, `bunnify_skip_for_url`,
  `bunnify_pre_image_url`, `bunnify_pre_args`, `bunnify_post_image_url`,
  `bunnify_allow_non_upload_url`, `bunnify_any_extension_for_domain`, and `bunnify_skip_image`
  keep identical names, arguments, priorities, and firing order. The service refactor happens
  strictly *behind* `CDNController::cdn_url` (still hooked at `bunnify_url`, priority 10).
- **A consumer site is unaffected.** Its only touchpoints are those filters (see Current state); it
  never instantiates `URLTransformer` or calls its statics, so no consumer code changes are
  required. This is the primary safety guarantee of the design.
- **Deprecation shim for the statics.** `URLTransformer::get_cdn_url_by_id()` and
  `URLTransformer::is_cdn_url()` are still called from `ImageController` (`ImageController.php:271,
  311`) and `ContentController` (`:202, :267, :291`). Keep them as thin static shims that resolve
  the shared `CdnService` and delegate, for one minor release, so those call sites keep working
  while they are migrated to the injected service. Delete the static request-state (`$static_instance`)
  immediately; delete the shims once all internal callers use the service.
- **Option compatibility.** `bunnify_hostname` remains the single option (`SettingsController.php:53`);
  its semantics (empty ⇒ disabled ⇒ origin URLs) are preserved by `CdnService::is_enabled()`.

## Risks & mitigations

- **Changing `Base` ripples across sibling plugins.** `Base` is shared and phpcs-excluded.
  *Mitigation:* keep the `Base` change generic and mechanical (a trait⇒factory registry that
  reproduces the current Route/REST/AdminAjax behavior), put all CDN-specific code in the linted
  plugin tree, and cover the registry with a focused test; or take the container fallback to leave
  `Base` essentially untouched.
- **Hidden reliance on singleton reuse.** Any code assuming `URLTransformer::$static_instance`
  persists across calls could change timing. *Mitigation:* one memoized instance per request via
  the service preserves the same "build once" behavior; grep confirms the only readers are the
  static methods being migrated.
- **Order-of-initialization.** Services are injected in `setup_controllers()` before `set_up()`
  (`Application.php:102` then `:112`), so the service is always present when hooks register.
  Resolution stays lazy (first `transform_url`), so no DB read happens at construction.
- **Partial migration leaving two hostname sources.** *Mitigation:* land the shim so statics and
  service resolve through the *same* `CdnService`, guaranteeing a single hostname read path even
  mid-migration.

## Testing strategy

- **Unit (Brain Monkey, PHPUnit 9):** construct a `CdnService`, stub `get_option( 'bunnify_hostname' )`
  once, assert `is_enabled()` / `get_hostname()` memoize (option read exactly once), and that
  `transformer()` returns the *same* instance across calls and `null` when the option is empty.
- **Controller injection:** hand `CDNController` / `ContentController` a fake `CdnService` (no DB)
  and assert `cdn_url()` / `filter_the_content()` produce the expected URLs — a seam that does not
  exist today.
- **Registry:** assert `Application::set_services_for_controller()` injects the shared `CdnService`
  into a controller that `use`s `CdnAwareTrait`, injects nothing into one that does not, and reuses
  a single instance across multiple controllers.
- **Behavior parity / regression:** snapshot `bunnify_url` output for a representative set
  (attachment-ID hit, non-attachment upload, external URL, local-dev bypass, disabled hostname)
  before and after; they must match exactly.
- **No lingering static state:** a test that transforms with hostname A, then "changes" the option
  to B, must reflect B via a fresh service — proving the singleton is gone.
- Keep PHPStan level 5 green (adjust the baseline only for intentionally-removed static members)
  and ensure the new plugin-space files pass phpcs/WPCS.

## Rollout plan

1. **Add `CdnService`** with instance methods delegating to a `URLTransformer` it owns; unit-test in
   isolation. No call sites changed yet.
2. **Migrate `URLTransformer` statics to instance + shim:** move `get_cdn_url_by_id` / `is_cdn_url`
   logic onto the instance; keep static wrappers delegating to a shared `CdnService`; delete
   `$static_instance` / `$static_hostname` / `init_static_cdn()`.
3. **Generalize the registry** in `Application` (or add the container fallback) and register the CDN
   factory from `bunnify-frontend.php`; add `CdnAwareTrait`.
4. **Migrate `CDNController`:** `use CdnAwareTrait`, delete `init_cdn()` and private fields, route
   `cdn_url()` through the service. Verify `bunnify_url` parity.
5. **Migrate `ContentController`:** same, covering all three transform sites and the stray
   `get_option` in `transform_url_direct()`.
6. **Migrate secondary consumers** (`ImageController`, and `ContentController`'s remaining static
   calls) onto the injected service; then **remove the static shims** and update the PHPStan baseline.
7. **Docs:** update `docs/HOOKS.md` note that `bunnify_url` is unchanged; cross-link this blueprint.

## Effort estimate

**M** — Confined to `CdnService` + a small `Application` registry change and mechanical migration of
two-to-three controllers, all behind a stable `bunnify_url` filter seam; the main cost is the
deprecation-shim window for the `URLTransformer` statics and the accompanying test updates, not
algorithmic risk.
