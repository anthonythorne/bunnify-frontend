# Performance benchmarking & per-request overhead budget

- **Status:** Proposed
- **Created:** 2026-07-03
- **Owner:** _unassigned_
- **Related:** [[di-container-service-layer]] (the single `CdnService` that would own the memoized effective-enabled / hostname resolution this blueprint hardens), [[data-driven-settings]] (the `is_enabled()` / `bunnify_enabled` semantics whose reads we cache), [[full-test-coverage]] (the integration layer where the query-count-invariance and regression benchmarks live)

## Summary
Bunnify runs on ~13 image and content filters on **every front-end request** (`ImageController::set_up()` at `src/php/Controller/ImageController.php:159-184`, `ContentController::set_up()` at `src/php/Controller/ContentController.php:38-53`), and several of those filters fire *per image and per srcset candidate*. For a plugin whose entire job is to rewrite URLs in the hot path, its own overhead is a feature: it must stay well under the cost of the work it replaces. Today that overhead is neither measured nor bounded. This blueprint proposes (1) a **micro-benchmark harness** — a `wp bunnify benchmark` WP-CLI command plus a CI regression test — that renders N images through the real filters and reports added wall-time, peak-memory delta, and DB-query count with the plugin enabled vs disabled, against a documented **sub-millisecond-per-image** budget; and (2) a small set of **per-request memoizations and a "no work when disabled" fast path** for the hot lookups the profiler surfaces — `is_local_dev_mode_enabled()`, `is_enabled()`/hostname, `is_cdn_url()`'s option read, `wp_upload_dir()`, and a static memo in front of the already-`wp_cache`-backed `get_attachment_id_from_url()`. It extends the exact pattern already proven by `image_exists_locally()`'s per-request cache (`src/php/Library/URLTransformer.php:308-314`) and `get_image_sizes()`'s static memo (`src/php/Library/ImageProcessor.php:69-74`).

## Motivation / Problem
The plugin's overhead is real, unmeasured, and in places scales with the wrong variable.

1. **`is_local_dev_mode_enabled()` is re-evaluated a dozen-plus times per request, uncached.** Every rewriting filter gates on it, and it is called from ~13 sites, several inside per-image or per-srcset loops: `URLTransformer::transform_url()` (`URLTransformer.php:90`), `get_true_original_url()` (`:547`), and across the controllers at `ImageController.php:204,243,379,503,690,756` and `ContentController.php:137,215,287,389,413`. Each call runs `apply_filters( 'bunnify_local_dev_mode_check', null )`, then `wp_get_environment_type()`, then potentially `get_option( 'bunnify_local_dev_mode', false )` (`SettingsController::is_local_dev_mode_enabled()`, `src/php/Controller/SettingsController.php:636-650`). The answer cannot change within a request, yet a page with 40 images and their srcset candidates evaluates it hundreds of times.

2. **The disabled / unconfigured path pays a `get_option` per image — there is no fast path.** `URLTransformer::get_cdn_url_by_id()` (the entry point for essentially every rewrite) calls `init_static_cdn()` on every invocation (`URLTransformer.php:507`). When the plugin is enabled, the first success memoizes `self::$static_instance` and subsequent calls short-circuit (`:462-464`) — good. But when the master switch is off or no hostname is set, `init_static_cdn()` returns `false` **without memoizing the negative** (`:461-483`), so every image on the page re-runs `SettingsController::is_enabled()` → `get_option( 'bunnify_enabled' )` (`:467`) and, when enabled-but-unconfigured, `get_option( 'bunnify_hostname' )` (`:475`). A site that installed the plugin but has not configured it — or has paused it — is precisely the case that should cost *nothing*, and today it costs an option read per image. The same shape exists in `CdnClientTrait::init_cdn()` (`src/php/Library/CdnClientTrait.php:43-62`).

3. **`is_cdn_url()` reads the hostname option per srcset source.** `URLTransformer::is_cdn_url()` calls `get_option( 'bunnify_hostname' )` on every invocation (`URLTransformer.php:416`), and it is called once per srcset candidate in `ImageController::filter_srcset_array()` (`:774`) and per content image in `ContentController` (`:238`), so the hostname option is fetched O(images × srcset-width) times.

4. **`wp_upload_dir()` is recomputed on many paths.** It is called in `transform_url()` (`URLTransformer.php:109`), `image_exists_locally()` (`:317`), `build_cdn_url_from_attachment()` (`:596`), and `ContentController::transform_url_direct()` (`:305`). Its result is request-stable.

5. **`get_attachment_id_from_url()` is a DB lookup per *content* image, and duplicates re-pay the wrapper cost.** `ContentController::process_content_with_tag_processor()` calls it for every non-attachment `<img>` in the content (`ContentController.php:130`), as does the srcset branch of `ImageController::filter_attachment_image()` (`ImageController.php:589`). It is already `wp_cache`-backed keyed by URL (`ImageProcessor.php:185-206`) and wraps a second cache layer in `attachment_url_to_postid()` (`:284-305`) — so the underlying `attachment_url_to_postid()` DB query runs at most once per unique URL per request *when a persistent object cache is present*. But (a) without a persistent object cache those caches are request-scoped only and cold on every request, and (b) even on a hit, a repeated identical URL (the same logo/hero rendered many times) still re-pays two `md5()` + `wp_cache_get()` round-trips and the dimension-strip regex in the miss branch. There is no lightweight static memo in front of it, and nothing asserts that query counts stay O(unique attachments) rather than O(images).

6. **A metadata read bypasses the shared cache wrapper.** `ImageController::filter_image_downsize()` calls raw `wp_get_attachment_metadata()` (`ImageController.php:276`), while the sibling `filter_attachment_img_srcs()` correctly routes through `get_cached_attachment_metadata()` (`:412`, `CachingTrait.php:80-95`). The inconsistency means one hot filter misses the cache the others hit.

None of this is currently visible: there is no benchmark, no query-count assertion, and no documented budget, so a regression here would ship silently.

## Goals
- Ship a repeatable **micro-benchmark harness** (`wp bunnify benchmark`) that renders N attachment images and a content blob through the *real* registered filters and reports, for **plugin-enabled vs plugin-disabled**: total wall-time, added time **per image**, peak-memory delta, and total DB queries.
- Publish a **documented overhead budget** — target **sub-millisecond added time per image on a warm object cache**, and **query count that is O(unique attachments), not O(rendered images)** — and wire a trimmed version of the harness into CI as a regression guard.
- Add **per-request memoization** for the request-stable hot lookups surfaced above (`is_local_dev_mode_enabled()`, effective-enabled + hostname, `is_cdn_url()`'s hostname read, `wp_upload_dir()`), following the established `image_exists_locally()` / `get_image_sizes()` static-cache pattern, with **zero behaviour change**.
- Add a **"no work when disabled/unconfigured" fast path** so a paused or unconfigured install pays a single resolution per request, not one option read per image.
- Add a **static memo in front of `get_attachment_id_from_url()`** and an accompanying test asserting query counts do not scale with duplicate images.
- Route the stray raw `wp_get_attachment_metadata()` in `filter_image_downsize()` through the existing cache wrapper.

## Non-goals
- **No runtime page-level monitoring, dashboards, or telemetry.** This harness is a developer/CI tool run on demand, not an always-on profiler shipped to production. Deep, ongoing page-speed / Core Web Vitals monitoring is a separate concern and explicitly out of scope for this lightweight, frontend-only plugin.
- No change to the public filter API in `docs/HOOKS.md` — every filter name, signature, and default is preserved. Memoization is an internal optimization.
- No new persistent object cache dependency and no change to the existing `wp_cache` TTL strategy (`ImageProcessor::get_attachment_cache_ttl()`, `ImageProcessor.php:246-275`). The new memos are per-request statics that sit *in front of* the existing caches, not a replacement for them.
- No move of the CDN-config resolution into a service object — that is the sibling [[di-container-service-layer]] blueprint; this doc only specifies the memoization semantics that a `CdnService` (or the current statics) must satisfy.
- No micro-optimization of the `WP_HTML_Tag_Processor` walk itself or the srcset-generation maths — the budget targets the per-image lookups, which the profiler will confirm dominate.

## Current state
The two hot controllers register their filters in `set_up()`:

- `ImageController::set_up()` (`src/php/Controller/ImageController.php:159-184`) hooks `image_downsize`, `wp_get_attachment_image_src`, `wp_get_attachment_image`, `wp_prepare_attachment_for_js`, `wp_calculate_image_srcset_meta`, `wp_calculate_image_srcset`, and `wp_calculate_image_sizes`.
- `ContentController::set_up()` (`src/php/Controller/ContentController.php:38-53`) hooks `the_content`, `widget_text`, `get_post_galleries`, `widget_media_image_instance`, and — notably — `render_block` (`:49`), which fires for **every block** on the page.

Every rewrite funnels through `URLTransformer::get_cdn_url_by_id()` (`URLTransformer.php:496-516`), which calls `init_static_cdn()` (`:461-484`) each time; the positive path memoizes `self::$static_instance` and short-circuits, the negative path does not. The direct-transform paths (galleries, image widget, `transform_url_direct`) go through `CdnClientTrait::init_cdn()` (`CdnClientTrait.php:43-62`), which memoizes `$this->url_transformer` on success but re-reads `is_enabled()` + hostname on the negative path.

**Prior art already in the tree** — the pattern this blueprint generalizes:
- `URLTransformer::image_exists_locally()` per-request static `$cache` keyed by URL (`URLTransformer.php:308-314`), added precisely because local-dev mode calls it for every image several times and each miss does two filesystem stats.
- `ImageProcessor::get_image_sizes()` static `$sizes` memo (`ImageProcessor.php:69-74`).
- `wp_cache` layers keyed by URL/ID in `get_attachment_id_from_url()` (`ImageProcessor.php:185-206`), `attachment_url_to_postid()` (`:284-305`), `get_cached_original_url()` (`:220-238`), and `get_true_original_url()` (`URLTransformer.php:525-578`), all with age-based TTLs.
- `CachingTrait::get_cached_attachment_metadata()` (`src/php/Base/Traits/CachingTrait.php:80-95`) and the sibling `get_cached_attachment_url()` (`:153-168`).

**No benchmark exists.** `bin/` contains only `build.sh`; there is no WP-CLI command anywhere in `src/php` (the sole `WP_CLI` reference is defensive logging in `Base/Traits/LoggingTrait.php`). There is therefore a clean slot for a `wp bunnify` command namespace registered only under `defined( 'WP_CLI' ) && WP_CLI`.

## Proposed approach

### 1. The benchmark harness
Register a WP-CLI command, guarded so it never loads on a web request:

```php
// Illustrative. Registered from the plugin bootstrap behind a WP_CLI guard.
if ( defined( 'WP_CLI' ) && WP_CLI ) {
    \WP_CLI::add_command( 'bunnify benchmark', BenchmarkCommand::class );
}
```

```php
/**
 * wp bunnify benchmark [--images=<n>] [--iterations=<n>] [--attachment=<id>]
 *
 * Renders <n> images through the real image + content filters and reports
 * added time / memory / queries with Bunnify enabled vs disabled.
 */
public function __invoke( array $args, array $assoc ): void {
    $n     = (int) ( $assoc['images'] ?? 50 );
    $iters = (int) ( $assoc['iterations'] ?? 20 );
    $id    = (int) ( $assoc['attachment'] ?? $this->pick_sample_attachment() );

    // Force query logging for the measurement window.
    if ( ! defined( 'SAVEQUERIES' ) ) {
        define( 'SAVEQUERIES', true );
    }

    $disabled = $this->measure( $id, $n, $iters, enabled: false );
    $enabled  = $this->measure( $id, $n, $iters, enabled: true );

    $added_ms_per_image = ( $enabled['ms'] - $disabled['ms'] ) / ( $n * $iters );

    \WP_CLI\Utils\format_items( 'table', [
        [ 'metric' => 'ms total (disabled)', 'value' => $disabled['ms'] ],
        [ 'metric' => 'ms total (enabled)',  'value' => $enabled['ms'] ],
        [ 'metric' => 'added ms / image',    'value' => round( $added_ms_per_image, 4 ) ],
        [ 'metric' => 'peak mem delta (KB)', 'value' => ( $enabled['mem'] - $disabled['mem'] ) / 1024 ],
        [ 'metric' => 'queries (disabled)',  'value' => $disabled['q'] ],
        [ 'metric' => 'queries (enabled)',   'value' => $enabled['q'] ],
    ], [ 'metric', 'value' ] );

    if ( $added_ms_per_image > (float) ( $assoc['budget-ms'] ?? 1.0 ) ) {
        \WP_CLI::warning( "Over budget: {$added_ms_per_image} ms/image" );
    }
}
```

`measure()` toggles the plugin the same way a real operator would — the honest "disabled" baseline is `update_option( 'bunnify_enabled', '0' )`, which drives `is_enabled()` false so every `get_cdn_url_by_id()` returns `null` and the filters leave input untouched (this is exactly the fast path §2 optimizes, so the benchmark also proves that path is cheap). It then renders through the **real** entry points, not a reimplementation:

```php
private function measure( int $id, int $n, int $iters, bool $enabled ): array {
    update_option( 'bunnify_enabled', $enabled ? '1' : '0' );
    wp_cache_flush();                       // cold-cache floor; run again warm for the warm number
    $q0 = get_num_queries();
    $m0 = memory_get_peak_usage( true );
    $t0 = hrtime( true );

    for ( $i = 0; $i < $iters; $i++ ) {
        for ( $k = 0; $k < $n; $k++ ) {
            wp_get_attachment_image( $id, 'large' );          // image_downsize + srcset filters
        }
        apply_filters( 'the_content', $this->sample_content( $id, $n ) ); // content path
    }

    return [
        'ms'  => ( hrtime( true ) - $t0 ) / 1e6,
        'mem' => memory_get_peak_usage( true ) - $m0,
        'q'   => get_num_queries() - $q0,
    ];
}
```

The **CI regression form** is a PHPUnit (integration) test that runs a small N and asserts two invariants rather than an absolute time (wall-time is noisy in CI): (a) queries with the plugin enabled do not exceed the disabled baseline by more than a fixed constant, and (b) rendering the *same* attachment N times issues no more attachment-lookup queries than rendering it once (the O(unique) guarantee).

### 2. Memoize the request-stable hot lookups
Apply the `image_exists_locally()` pattern to the offenders. Each is a per-request static; each has zero behaviour change because the underlying value cannot change within a request.

```php
// SettingsController::is_local_dev_mode_enabled() — memoize the whole resolution.
public static function is_local_dev_mode_enabled(): bool {
    static $memo = null;
    if ( null !== $memo ) {
        return $memo;
    }
    // ... existing filter / environment / option resolution ...
    return $memo = $result;
}
```

For the effective-enabled + hostname resolution (shared by `init_static_cdn()` and `init_cdn()`), resolve **once per request** into a small struct and give the negative path a memo so a disabled/unconfigured install stops re-reading options per image:

```php
// One request-scoped resolution: { enabled: bool, hostname: string }.
private static function cdn_config(): array {
    static $cfg = null;
    if ( null !== $cfg ) {
        return $cfg;
    }
    $enabled  = SettingsController::is_enabled();
    $hostname = $enabled ? (string) get_option( 'bunnify_hostname', '' ) : '';
    return $cfg = [ 'enabled' => $enabled && '' !== $hostname, 'hostname' => $hostname ];
}
```

`init_static_cdn()` / `init_cdn()` consult `cdn_config()`; when `enabled` is false they return early with no further option reads. This is the "no work when disabled" fast path — and it is the natural home for the [[di-container-service-layer]] `CdnService` should that land (the memo simply becomes instance state). `is_cdn_url()` reads its hostname from the same resolution instead of calling `get_option()` at `URLTransformer.php:416`.

Memoize `wp_upload_dir()` behind a tiny private accessor used by `transform_url()`, `image_exists_locally()`, `build_cdn_url_from_attachment()`, and `transform_url_direct()`:

```php
private static function upload_dir(): array {
    static $d = null;
    return $d ??= wp_upload_dir();
}
```

### 3. Static memo in front of `get_attachment_id_from_url()`
Layer a per-request array in front of the existing `wp_cache` so a repeated identical content URL skips the `md5()` + `wp_cache_get()` + regex work entirely — the same rationale as `image_exists_locally()`'s cache:

```php
public static function get_attachment_id_from_url( string $url ): int|false {
    static $memo = [];
    if ( array_key_exists( $url, $memo ) ) {
        return $memo[ $url ];
    }
    // ... existing wp_cache-backed lookup ...
    return $memo[ $url ] = $attachment_id;
}
```

### 4. Cache-wrapper consistency
Change the raw `wp_get_attachment_metadata()` at `ImageController.php:276` to `$this->get_cached_attachment_metadata( $attachment_id )`, matching `filter_attachment_img_srcs()` (`:412`) and the other filters, so all metadata reads share one cache.

## Migration & backwards compatibility
- **No DB migration, no option changes.** The benchmark command temporarily flips `bunnify_enabled` while running; it must **restore the operator's original value in a `finally` block** (and skip on production environments unless `--force`d) so a benchmark run cannot leave the site disabled. Document this in the command's help.
- **No public-API change.** All memoizations are internal; `docs/HOOKS.md` is untouched. `bunnify_local_dev_mode_check`, `bunnify_skip_for_url`, and the rest keep firing with identical timing semantics *within* a request — the memo captures the first resolved value, which is the value every subsequent call would have returned anyway.
- **Filter-timing subtlety.** Because `is_local_dev_mode_enabled()`'s memo captures the first `apply_filters( 'bunnify_local_dev_mode_check' )` result for the request, a consumer whose filter returns *different* values on repeated calls **within the same request** would see the first value stick. That is already the effective contract (the mode cannot meaningfully change mid-request), but it must be documented, and the memo must be reset between tests (a `reset_memo()` test seam, or a `bunnify_reset_request_cache` action).
- **Object-cache behaviour unchanged.** The new statics sit in front of the existing `wp_cache` layers; installs with and without a persistent object cache behave as before, only faster on repeats.
- **Consumer impact: none.** No consumer reads these internals; they hook the documented filters, whose outputs are unchanged.

## Risks & mitigations
- **Stale memo across a long-running process (WP-CLI, queue worker, unit test).** A per-request static persists for the life of the PHP process, which for CLI/cron can span option changes. Mitigation: expose a `reset_request_cache()` static (fired on a `bunnify_reset_request_cache` action) that clears every memo; call it in test `tearDown()` and document it for long-lived workers. The benchmark command calls it between enabled/disabled runs.
- **Benchmark leaves the site disabled.** A crash mid-run could persist `bunnify_enabled = '0'`. Mitigation: capture the original value up front and restore in `finally`; refuse to run on `production` environment type without `--force`; print the restored state on exit.
- **Memoizing `is_enabled()` hides a legitimate mid-request toggle.** Extremely rare (settings save + render in one request), and the settings page is admin-only. Mitigation: the effective-enabled memo is keyed to front-end request lifecycle; admin `options.php` saves happen on a separate request. Acceptable.
- **Wall-time assertions flake in CI.** Shared runners have noisy timing. Mitigation: CI asserts **query-count and query-scaling invariants** (deterministic), not absolute milliseconds; the millisecond budget is a local/`wp bunnify benchmark` tool and a `--warn`, not a hard CI gate.
- **Over-memoization masking a real cache miss.** A static memo that returns a stale `false` (attachment deleted mid-request) could serve a wrong ID. Mitigation: attachment identity is request-stable in practice; the memo mirrors `image_exists_locally()`'s already-accepted trade-off, and TTL'd `wp_cache` remains the cross-request source of truth.
- **Scope creep into a runtime profiler.** Mitigation: the harness is CLI/CI only, behind the `WP_CLI` guard, shipped in `src` but never hooked on a web request — enforced by a test asserting no `add_action`/`add_filter` registers it.

## Testing strategy
- **Query-scaling invariant (integration, `wp-phpunit`).** Render the same attachment through `the_content` 1× and N×; assert the number of attachment-lookup queries is identical (proves the `get_attachment_id_from_url()` memo + `wp_cache` keep it O(unique)). Render N *distinct* attachments and assert queries scale linearly with distinct count, not total renders.
- **Memoization unit tests (Brain Monkey).** Assert `get_option( 'bunnify_local_dev_mode' )`, `get_option( 'bunnify_enabled' )`, `get_option( 'bunnify_hostname' )`, and `wp_upload_dir()` are each invoked **at most once per request** across many rewrite calls (`Functions\expect( ... )->once()`), and that `reset_request_cache()` restores a second read.
- **Fast-path test.** With `bunnify_enabled = '0'`, assert `get_cdn_url_by_id()` reads `bunnify_enabled` at most once regardless of image count and that every filter returns its input unchanged (no CDN URL emitted).
- **Behaviour-parity guard.** Snapshot the rewritten `src`/`srcset`/HTML for a fixture image *before* and *after* the memoization changes and assert byte-identical output — memoization must not alter any URL.
- **Benchmark smoke test.** Run `wp bunnify benchmark --images=5 --iterations=2` in the integration suite and assert it exits 0, emits the metrics table, and restores the original `bunnify_enabled` value.
- **Static analysis.** Keep PHPStan and phpcs/WPCS green on the new `BenchmarkCommand` and the touched hot-path methods.

## Rollout plan
1. **Land the harness first, measuring the *current* code.** Add `BenchmarkCommand` + the `WP_CLI` registration and the CI query-scaling test. This captures a baseline (added ms/image, query counts) before any optimization, so every later step has a before/after number.
2. **Memoize `is_local_dev_mode_enabled()`** (highest call count) with its `reset_request_cache()` seam and unit test; re-run the benchmark and record the delta.
3. **Add the effective-enabled + hostname resolution and the disabled fast path**, routing `init_static_cdn()`, `init_cdn()`, and `is_cdn_url()` through it; land the fast-path test.
4. **Add the `get_attachment_id_from_url()` static memo** and the O(unique) query-scaling assertion.
5. **Memoize `wp_upload_dir()`** and fix the `filter_image_downsize()` metadata cache-wrapper inconsistency.
6. **Publish the budget** (sub-ms/image warm; O(unique) queries) in the plugin docs and wire the CI invariant as a required check.

Each step is independently shippable and measurable; the benchmark from step 1 quantifies every subsequent step.

## Effort estimate
**M.** The harness is a modest new WP-CLI command plus one integration test, and each memoization is a few-line static following an in-tree pattern — individually all S. What makes the whole **M** is doing it safely across ~six call-site clusters at once: proving byte-identical output before/after, adding the `reset_request_cache()` seam so long-lived CLI/test processes don't serve stale memos, guaranteeing the benchmark restores `bunnify_enabled`, and standing up the query-scaling invariant that turns the budget into an enforceable CI check rather than a one-off measurement.
