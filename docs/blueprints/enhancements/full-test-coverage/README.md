# Comprehensive Automated Test Coverage

- **Status:** Proposed (Phases 0–1 implemented; see the 2026-07-02 update)
- **Created:** 2026-07-01
- **Owner:** _unassigned_
- **Related:** [[cdn-config-consolidation]] (shrinks the branch surface tests must cover), [[base-framework-standards]] (decides whether `src/php/Base` is ever tested here), [[ci-release-automation]] (the workflow this test gate plugs into)

> **Update (2026-07-02):** the "no suite exists" premise below is out of date —
> the enterprise restructure landed the Brain Monkey unit layer (Phase 0) and
> subsequent fix commits grew it to **60 green tests across 8 files**,
> including the `is_attachment_image()` truth table this blueprint names in
> Phase 1 (`tests/Unit/ContentControllerTest.php`) and a REST-posture guard
> (`tests/Unit/RestSurfaceTest.php`). The CI matrix now covers PHP
> 8.2/8.3/8.4. Phase 1 is complete; the wp-phpunit integration layer, the
> filter-contract suite, the coverage gate, and the WordPress-version matrix
> (Phases 2–5) remain open — the filter-contract suite should land **before**
> the [[di-container-service-layer]] refactor, since it freezes the hook
> contract that refactor must preserve. Also superseded: this doc's Phase 2
> caveats about `build-tools/vendor` (composer install in CI, the silent
> bootstrap return) — the plugin now ships a plugin-root `autoload.php` per
> [[wporg-runtime-autoloader]], which always exists in a checkout.

## Summary

Bunnify Frontend's automated-testing story today is aspirational: the tooling is declared but no suite exists, and the code that actually earns its keep — the WordPress filter callbacks that rewrite `<img>` markup, srcsets, downsized sources and galleries — is untested. This blueprint proposes a two-layer test strategy: a fast **Brain Monkey unit layer** for the pure/near-pure helpers, and a **WordPress integration layer** (wp-phpunit + a database) that boots a real WordPress, inserts real attachments, and asserts on the HTML the controllers emit. It then adds a coverage target scoped to plugin code, a CI gate, and a build matrix across PHP 8.2/8.3/8.4 and several WordPress versions so we catch core drift (e.g. `WP_HTML_Tag_Processor` behaviour) before users do.

## Motivation / Problem

The riskiest code in this plugin is exactly the code no unit test can reach, because it depends on WordPress core objects and the media/attachment subsystem:

- `ImageController::filter_attachment_image()` builds a `WP_HTML_Tag_Processor`, walks to the `img` tag, and rewrites `src`/`width`/`height`/`srcset` (`bunnify-frontend/src/php/Controller/ImageController.php:540`-`631`). This calls `wp_get_attachment_image_src()`, `wp_get_attachment_metadata()` and the tag processor — none of which are meaningfully mockable in isolation.
- `ContentController::process_content_with_tag_processor()` loops every `img` in post content, decides via `is_attachment_image()` heuristics whether to skip, then rewrites `src`/`srcset` (`bunnify-frontend/src/php/Controller/ContentController.php:142`-`233`). The skip heuristics keyed on `wp-post-image`, `attachment-`, `size-`, `srcset`, `sizes` (`.../ContentController.php:355`-`402`) are pure branching logic that is easy to regress and currently has zero guardrails.
- `ImageController::filter_image_downsize()` and `filter_attachment_img_srcs()` carry near-duplicated string-size vs array-size branches with aspect-ratio maths (`.../ImageController.php:192`-`469`). Duplication like this is precisely where a copy-paste fix lands in one branch but not the other — a test would pin both.

Two structural facts make the gap worse:

1. **The public filter API is a consumer contract.** A consumer site hooks `bunnify_allow_non_upload_url` and `bunnify_skip_image` from its own plugin. Every `apply_filters()`/`do_action()` in the hot path (`bunnify_skip_for_url`, `bunnify_pre_image_url`, `bunnify_pre_args`, `bunnify_post_image_url`, `bunnify_override_image_downsize`, `bunnify_replace_attachment_srcs`, `bunnify_processing_attachment_image`, …) is a promise. Nothing currently asserts those hooks fire, fire once, with the documented arguments, and are honoured.
2. **The CDN-init path is duplicated.** `get_option( 'bunnify_hostname' )` + a private `init_cdn()`/`init_static_cdn()` appears in `ContentController` (`.../ContentController.php:68`-`82`), `CDNController` (`.../CDNController.php:52`-`66`), and `URLTransformer` (`.../URLTransformer.php:439`-`453`), plus ad-hoc reads in `WPResourceHintsController` and `URLTransformer::is_cdn_url()` (`.../URLTransformer.php:394`). Each copy is an independently reachable "configured vs disabled" branch, so the test matrix is larger than it needs to be until [[cdn-config-consolidation]] lands.

Today there is no `phpunit.xml.dist` and no `tests/` directory on disk. `composer.json` already requires `phpunit/phpunit ^9.6`, `brain/monkey ^2.6` and `yoast/phpunit-polyfills ^2.0`, reserves the `BunnifyFrontend\Tests\` → `tests/` PSR-4 map, and wires a `test` → `phpunit` script — but none of it runs against anything. Step one is therefore to make the declared tooling real, then expand it.

## Goals

- A committed, runnable **unit suite** (Brain Monkey) covering the pure/near-pure helpers, green via `composer test`.
- A committed **integration suite** that boots real WordPress against a database, seeds real attachments, and asserts on emitted HTML for the image and content filter pipelines.
- Assertions that pin the **public filter/action contract**: documented hooks fire, with documented arity, and short-circuit behaviour is honoured (e.g. `bunnify_override_image_downsize` returns original; `bunnify_replace_attachment_srcs` toggles the `wp_get_attachment_image_src` hook).
- A **line/branch coverage report** scoped to plugin code (`src/php/Controller`, `Library`, `Model`, `Function` and the entry file) that explicitly excludes `src/php/Base` and generated code.
- A **coverage gate** that fails CI below an agreed floor and can be ratcheted upward.
- A **CI matrix** across PHP 8.2/8.3/8.4 × a set of WordPress versions (minimum-supported through trunk), with trunk allowed to fail.
- Every goal above is observable from a CI run — no "trust me" coverage.

## Non-goals

- **Testing the `src/php/Base` mini-framework here.** Base is treated as vendored and is excluded from linting (`phpcs.xml.dist:5`-`14`) and effectively from analysis; whether it gains its own suite is owned by [[base-framework-standards]], not this document.
- **End-to-end / browser tests.** No Playwright/Cypress, no real BunnyCDN pull-zone round-trips. We assert on the *URLs and markup the plugin generates*, not on Bunny's image transforms.
- **Visual-regression or performance benchmarking.** Out of scope (perf is tracked separately in the consumer's perf blueprints).
- **Refactoring for testability as a deliverable.** We may note seams that hurt (static singletons, `get_option` duplication), but the refactors themselves belong to their own blueprints; this doc consumes whatever shape the code is in.
- **100% coverage.** We target a defensible floor on the code that matters, not a vanity number that forces tests for admin-render glue.

## Current state

- **Boot flow.** The entry file loads `build-tools/vendor/autoload.php`, defines `APP_NAME`, and constructs `Application` with six controller instances (`bunnify-frontend/bunnify-frontend.php:29`-`58`). `Application::__construct()` registers `setup_controllers()` on `plugins_loaded` (`bunnify-frontend/src/php/Base/Main/Application.php:86`), which injects services and calls each controller's `set_up()` (`.../Application.php:95`-`115`). This is the seam an integration test drives: activate the plugin, let `plugins_loaded` run, then exercise WordPress media functions.
- **Configuration.** A single option, `bunnify_hostname`, gates everything (empty ⇒ disabled ⇒ origin URLs). It is registered alongside debug/local-dev toggles in `SettingsController::init_settings()` (`bunnify-frontend/src/php/Controller/SettingsController.php:52`-`64`). Local-dev short-circuits run through `SettingsController::is_local_dev_mode_enabled()` (`.../SettingsController.php:448`), itself filterable via `bunnify_local_dev_mode_check`.
- **Pure / near-pure surface (ideal unit targets).**
  - `ImageProcessor::parse_dimensions_from_filename()` — a regex that pulls `-1024x684` off a filename; fully pure (`bunnify-frontend/src/php/Library/ImageProcessor.php:45`).
  - `URLTransformer::validate_image_url()` and `is_cdn_url()` — static predicates that lean only on `wp_parse_url()`, `get_option()` and one `apply_filters()`, all trivially fakeable with Brain Monkey (`bunnify-frontend/src/php/Library/URLTransformer.php:344`, `:381`).
  - The `bunnify_validate_image_url` filter (the final say inside `URLTransformer::validate_image_url()`) and the shared `ImageProcessor::ALLOWED_EXTENSIONS` const.
  - `URLTransformer::build_query_string()` / `cdn_url_scheme()` — private, but exercised through `transform_url()`; the query-builder's width/height/crop mapping and scalar pass-through (`.../URLTransformer.php:188`-`233`) is high-value unit territory.
- **WordPress-bound surface (integration targets).** `URLTransformer::get_cdn_url_by_id()` and `get_true_original_url()` depend on `wp_get_attachment_url()`, `wp_get_attachment_metadata()`, `wp_cache_*`, the `-scaled` filesystem probe (`.../URLTransformer.php:463`-`540`); `ImageProcessor::get_attachment_id_from_url()` calls `attachment_url_to_postid()` with dimension-stripping and `-scaled` fallbacks (`.../ImageProcessor.php:176`); and all of `ImageController`/`ContentController`'s callbacks touch attachments, metadata and `WP_HTML_Tag_Processor`. These want a real WordPress, not mocks.
- **Tooling.** Root `composer.json` declares the test stack and the `BunnifyFrontend\Tests\` autoload map, but no `phpunit.xml.dist` and no `tests/` exist yet. PHPStan runs at level 5 with a baseline; PHPCS runs WPCS against plugin code only.

## Proposed approach

Two suites, one config, one gate, one matrix.

### Layout

```
tests/
  bootstrap.php            # chooses unit vs integration bootstrap by env
  Unit/
    Library/UrlTransformerTest.php
    Library/ImageProcessorTest.php
    Controller/ContentControllerHeuristicsTest.php   # is_attachment_image() branch table
  Integration/
    ImagePipelineTest.php      # wp_get_attachment_image() end-to-end
    ContentPipelineTest.php    # the_content() end-to-end
    FilterContractTest.php     # public hooks fire with documented arity
    includes/factories.php     # seed attachments + metadata
phpunit.xml.dist             # unit suite (Brain Monkey), default
phpunit-integration.xml.dist # integration suite (wp-phpunit)
```

Two PHPUnit configs keep the layers independent: the unit config needs no database and runs everywhere in milliseconds; the integration config requires the WP test library and a DB. A single `tests/bootstrap.php` branches on an env var (e.g. `WP_TESTS_DIR` present ⇒ integration) so both share test utilities.

### Unit layer (Brain Monkey)

Brain Monkey + the Yoast polyfills are already required. Unit tests define WordPress functions as stubs and assert pure behaviour and filter dispatch. Illustrative:

```php
// tests/Unit/Library/ImageProcessorTest.php
public function test_parses_double_dash_dimensions(): void {
    self::assertSame( [1024, 684], ImageProcessor::parse_dimensions_from_filename( 'x--1024x684.jpg' ) );
    self::assertFalse( ImageProcessor::parse_dimensions_from_filename( 'no-dimensions.png' ) );
}

// tests/Unit/Library/UrlTransformerTest.php — is_cdn_url() honours query-param heuristic
Functions\when( 'get_option' )->justReturn( 'cdn.example.com' );
self::assertTrue( URLTransformer::is_cdn_url( 'https://origin.test/wp-content/uploads/a.jpg?width=300' ) );
```

The `ContentController::is_attachment_image()` heuristic (`.../ContentController.php:355`) is pure branching over attributes and is worth a data-provider truth-table at the unit level, since it decides skip-vs-process for every content image.

What unit tests deliberately *cannot* cover: anything constructing `WP_HTML_Tag_Processor` or calling `attachment_url_to_postid()`/`wp_get_attachment_metadata()`. Faking `WP_HTML_Tag_Processor` well enough to trust an assertion is more fragile than just booting WordPress — hence the integration layer.

### Integration layer (wp-phpunit + DB)

Adopt the WordPress PHPUnit test framework via the `wp-phpunit/wp-phpunit` Composer package (the Yoast polyfills we already require are its companion). The integration bootstrap loads the WP test library, then loads the plugin on `muplugins_loaded` so `plugins_loaded` → `Application::setup_controllers()` runs exactly as in production.

```php
// tests/bootstrap.php (integration branch, sketch)
require getenv('WP_TESTS_DIR') . '/includes/functions.php';
tests_add_filter( 'muplugins_loaded', function () {
    require dirname(__DIR__) . '/bunnify-frontend/bunnify-frontend.php';
} );
require getenv('WP_TESTS_DIR') . '/includes/bootstrap.php';
```

Bootstrap caveat: the entry file returns early unless `build-tools/vendor/autoload.php` exists (`bunnify-frontend.php:32`). CI must run `composer install --working-dir=bunnify-frontend/build-tools` before the integration job, or the bootstrap must register the root dev autoloader as a shim.

Representative integration tests, each seeding a real attachment with metadata via the WP factory:

```php
// tests/Integration/ImagePipelineTest.php
update_option( 'bunnify_hostname', 'cdn.example.com' );
$id   = self::factory()->attachment->create_upload_object( $fixture_jpg );
$html = wp_get_attachment_image( $id, 'medium' );

$p = new WP_HTML_Tag_Processor( $html );
$p->next_tag( 'img' );
self::assertStringStartsWith( 'https://cdn.example.com/', $p->get_attribute( 'src' ) );
self::assertStringContainsString( 'width=', $p->get_attribute( 'src' ) );      // downsize args survive
foreach ( explode( ',', $p->get_attribute( 'srcset' ) ) as $src ) {
    self::assertStringContainsString( 'cdn.example.com', $src );               // every srcset entry rewritten
}
```

The high-value integration scenarios:

- **Disabled path.** Empty `bunnify_hostname` ⇒ markup is byte-for-byte the origin output (guards every `init_cdn()`/`init_static_cdn()` copy at once).
- **Downsize + srcset.** `wp_get_attachment_image()` for registered sizes and custom `16:9-768` sizes; assert `src`, `width`/`height`, and that *all* srcset entries are CDN URLs with correct `w` descriptors.
- **Content rewrite.** `apply_filters( 'the_content', $post_html )` with a mix of attachment images (`wp-image-*`, `wp-post-image`) and bare `<img>` tags; assert the skip heuristics behave and non-attachment uploads still route through `transform_url_direct()`.
- **Galleries & widgets.** `get_post_galleries` and `widget_media_image_instance` rewrite the `src`/`url` keys (`.../ContentController.php:410`, `:439`).
- **Local-dev bypass.** With `bunnify_local_dev_mode` on and the fixture present on disk, assert URLs are left untouched; toggle the `bunnify_local_dev_mode_check` filter and assert override.
- **Idempotence.** Feeding already-CDN markup back through the filters is a no-op (guards the `is_cdn_url()` double-processing checks in both controllers).

### Filter-contract tests

A dedicated `FilterContractTest` pins the consumer promise a downstream consumer relies on. Using Brain Monkey `expectApplied()`/`expectDone()` (unit) and real hook probes (integration):

```php
// short-circuit is honoured
add_filter( 'bunnify_override_image_downsize', '__return_true' );
self::assertSame( $original, $controller->filter_image_downsize( $original, $id, 'medium' ) );

// disabling attachment-src replacement removes the hook
add_filter( 'bunnify_replace_attachment_srcs', '__return_false' );
$controller->set_up();
self::assertFalse( has_filter( 'wp_get_attachment_image_src', [ $controller, 'filter_attachment_img_srcs' ] ) );

// action fires once, with documented arity
self::assertSame( 1, did_action( 'bunnify_processing_attachment_image' ) );
```

Keeping these in one file makes the public API's test surface auditable, and makes an accidental hook rename or arity change fail loudly rather than silently break downstream.

### Coverage config & gate

Scope coverage to plugin code and exclude Base + generated code, mirroring the lint scope (`phpcs.xml.dist:10`-`21`):

```xml
<!-- phpunit-integration.xml.dist (excerpt) -->
<source>
  <include>
    <directory>bunnify-frontend/src/php/Controller</directory>
    <directory>bunnify-frontend/src/php/Library</directory>
    <directory>bunnify-frontend/src/php/Model</directory>
    <directory>bunnify-frontend/src/php/Function</directory>
    <file>bunnify-frontend/bunnify-frontend.php</file>
  </include>
  <exclude>
    <directory>bunnify-frontend/src/php/Base</directory>
    <directory>bunnify-frontend/build-tools/vendor</directory>
  </exclude>
</source>
```

Coverage is meaningful only from the integration run (that is where the hot paths execute), so the gate reads the integration suite's Clover/Cobertura report. Enforce a floor in CI (e.g. via a small script or a coverage action) and ratchet it as the suite fills in. Start with a realistic line-coverage floor (proposed **70%** of the scoped source, branch coverage reported but not yet gated) and raise it per milestone; a too-high day-one gate just gets bypassed.

### CI matrix

```yaml
strategy:
  fail-fast: false
  matrix:
    php: ['8.2', '8.3', '8.4']          # composer requires php >=8.2
    wp:  ['6.3', '6.6', 'latest']       # 6.3 = "Requires at least"
    include:
      - { php: '8.4', wp: 'trunk', experimental: true }
```

- **PHP** spans the supported floor (`composer.json` `php: ">=8.2"`, header `Requires PHP: 8.2`) through current 8.4.
- **WordPress** spans the minimum supported version (6.3 per the plugin header and `phpcs.xml.dist:30`) through `latest`, plus `trunk` as an allowed-to-fail early-warning lane. `WP_HTML_Tag_Processor` behaviour has shifted across core releases, so covering old-and-new WP is the whole point of the matrix.
- **Jobs.** Lint (PHPCS) and static analysis (PHPStan) run once on the primary PHP; the unit suite runs on every PHP with no services; the integration suite runs on the full matrix with a MySQL (or MariaDB) service — or, to skip the DB service entirely, the WordPress SQLite integration driver. Only the primary cell uploads coverage and enforces the gate, to keep the matrix fast.

## Migration & backwards compatibility

- **No runtime behaviour changes.** This blueprint adds `tests/`, two PHPUnit configs, dev-only Composer packages (`wp-phpunit/wp-phpunit`, a coverage driver), and CI. Nothing ships in the `.zip` — `.distignore` already excludes dev artefacts, and tests live outside the `bunnify-frontend/` plugin dir.
- **Public filter API is frozen by these tests, deliberately.** A downstream consumer hooks `bunnify_allow_non_upload_url` and `bunnify_skip_image` (the latter is referenced downstream even though the plugin currently emits `bunnify_skip_for_url` — a naming mismatch the contract tests will surface and [[cdn-config-consolidation]]/the hooks audit should reconcile). Once `FilterContractTest` exists, any future hook rename, arity change, or default-value change must update the contract test in the same PR — turning silent breakage of downstream sites into a red build.
- **Interaction with consolidation work.** If [[cdn-config-consolidation]] lands first, the disabled-path and init-branch tests collapse from three near-identical cases to one; writing the integration tests against *behaviour* (URLs/markup) rather than internal `init_cdn()` calls keeps them valid across that refactor.
- **Base exclusion respected.** Coverage and suites ignore `src/php/Base`, so this does not pre-empt [[base-framework-standards]]; if that blueprint later brings Base under test, it can add its own suite without colliding with this scope.

## Risks & mitigations

- **Flaky/slow integration environment.** A DB service plus the WP test library is the classic CI flake source. *Mitigation:* pin WP/PHP versions per matrix cell, cache Composer + the WP test-suite download, and offer the SQLite driver so contributors can run integration tests locally without standing up MySQL.
- **`WP_HTML_Tag_Processor` behaviour drift across WP versions.** The same input can serialize attributes differently across core releases, making brittle string-equality assertions fail spuriously. *Mitigation:* assert via a fresh `WP_HTML_Tag_Processor` on the *output* (parse and read attributes) rather than comparing raw HTML strings; the matrix then legitimately flags real behaviour changes.
- **Bootstrap coupling to `build-tools/vendor`.** The entry file silently returns if that autoloader is missing (`bunnify-frontend.php:32`), which would make the whole integration suite "pass" by testing nothing. *Mitigation:* the bootstrap asserts the plugin actually loaded (e.g. `class_exists( Application::class )` and a known hook is registered) and fails hard otherwise.
- **Coverage theatre.** A high gate invites tests that execute lines without asserting behaviour. *Mitigation:* gate on a moderate floor, review for meaningful assertions, and value the contract/integration tests over raw percentage.
- **Untestable-by-design admin glue.** `SettingsController`'s field-render callbacks are `echo`-heavy WP admin plumbing with low defect risk and high test cost. *Mitigation:* exclude admin-render methods from the coverage denominator (annotate or scope) rather than writing low-value output tests.
- **Static singleton state leaks between tests.** `URLTransformer::$static_instance`/`$static_hostname` (`.../URLTransformer.php:425`-`432`) and the `static $sizes` cache in `ImageProcessor::get_image_sizes()` (`.../ImageProcessor.php:68`) persist across tests in one process. *Mitigation:* run integration tests in isolation or add teardown that resets these (reflection, or a test-only reset hook), and clear the object cache (`wp_cache_flush()`) between tests.

## Testing strategy

This blueprint *is* the testing strategy, but its own deliverables are verified by:

- **Self-check on the gate.** Land the gate against the first real suite and confirm CI goes red when the floor is lowered artificially, then green when restored — proving the gate is wired, not decorative.
- **Mutation spot-check (optional, later).** Run Infection over `URLTransformer`/`ImageProcessor` once the unit layer exists to confirm the assertions actually catch mutants, not just execute lines.
- **Matrix smoke.** A deliberately WP-version-sensitive assertion (an attribute-order or srcset-serialization check) confirms the matrix distinguishes WP versions rather than all cells running identical code.

## Rollout plan

1. **Phase 0 — make the declared tooling real.** Add `phpunit.xml.dist` and a minimal `tests/bootstrap.php`; commit one trivial unit test so `composer test` is green. No behaviour, pure scaffolding.
2. **Phase 1 — unit layer.** Cover `ImageProcessor::parse_dimensions_from_filename()`, `URLTransformer::validate_image_url()`/`is_cdn_url()`, `ImageProcessor::validate_image_url()`, the query-string/scheme builders, and the `is_attachment_image()` truth-table. Wire the unit suite into CI across PHP 8.2/8.3/8.4 (no DB).
3. **Phase 2 — integration harness.** Add `wp-phpunit`, the integration config, attachment factories, and the disabled-path + basic downsize tests. Prove the plugin actually boots under the WP test library (bootstrap assertion).
4. **Phase 3 — pipeline coverage.** Fill in srcset, content rewrite, galleries/widgets, local-dev bypass, and idempotence; add `FilterContractTest` to pin the public hooks.
5. **Phase 4 — coverage + gate.** Turn on the scoped coverage report, set the initial floor, and make the primary matrix cell enforce it.
6. **Phase 5 — full matrix + ratchet.** Expand to all PHP × WP cells with the `trunk` allowed-to-fail lane, then ratchet the coverage floor upward as the suite matures. Optionally add mutation spot-checks.

## Effort estimate

**L.** The unit layer is small, but a reliable wp-phpunit integration harness (bootstrap that respects the `build-tools/vendor` guard, attachment fixtures, static-state teardown), the filter-contract suite, and a green PHP×WP matrix with caching and a coverage gate are collectively multi-week — the harness and matrix, not the individual tests, are the cost.
