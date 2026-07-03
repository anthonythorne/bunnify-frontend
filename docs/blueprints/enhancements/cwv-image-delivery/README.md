# CWV image delivery — mapping PageSpeed image opportunities to Bunnify

- **Status:** Proposed
- **Created:** 2026-07-03
- **Owner:** _unassigned_
- **Related:** [[format-negotiation]] (the `format=`/`quality=` negotiation this blueprint only *emits* — content-type detection, `Accept`-header interplay, and Optimizer-off fallbacks are decided there), [[data-driven-settings]] (the schema + `sanitize_callback`s that the new opt-in toggles should register through), [[di-container-service-layer]] (where a shared CDN accessor would resolve the effective-enabled gate these features sit behind)

## Summary
Bunnify already rewrites image URLs to the CDN and preserves responsive `srcset`/`sizes`. Because it owns the delivered `<img>` markup, it also owns the levers a Lighthouse/PageSpeed run flags under **image** opportunities — Properly size images, Next-gen formats, Efficient encoding, "explicit width and height" (CLS), and the LCP image. This blueprint maps each of those opportunities to a concrete, **opt-in, filter-gated** Bunnify feature, reusing the transform plumbing already in place (`URLTransformer::build_query_string()` already passes `quality`/`format` through; `WPResourceHintsController` already handles preconnect). The through-line is a hard rule: **no feature may change layout or add a request without an explicit opt-in**, and the LCP hints must apply to **exactly one** image so we help Core Web Vitals instead of trading one regression for another.

Deep, ongoing CWV *measurement* (field data, per-URL LCP element attribution, regression alerting) is out of scope — that is the author's separate PagePulse plugin. Bunnify's job is narrow: make the markup it emits fast and correct.

## Motivation / Problem
A PageSpeed report on a Bunnify site today surfaces image opportunities that Bunnify is uniquely positioned to close but currently does not:

1. **Next-gen formats and quality are unreachable without code.** `URLTransformer::build_query_string()` already forwards a `format` or `quality` arg to the CDN (`src/php/Library/URLTransformer.php:224-243`), and `is_cdn_url()` already treats those params as CDN markers (`:430`), but **nothing in the plugin ever puts them into `$cdn_args`.** The only way to get `format=auto` or `quality=` today is for an integrator to hand-write a `bunnify_pre_args` / `bunnify_image_downsize_string` filter (documented at `docs/HOOKS.md:258-265`). There is no setting and no first-class filter, so the average install ships un-optimized bytes.

2. **Content images can render without `width`/`height` — a CLS source.** Post-thumbnail HTML goes through `ImageController::filter_attachment_image()`, which sets `width`/`height` on the `<img>` (`src/php/Controller/ImageController.php:554-560`) — but only *conditionally* (`if ( $width )` / `if ( $height )`). Inline content images go through `ContentController::process_content_with_tag_processor()`, which rewrites `src` and `srcset` (`:179`, `:186`) **but never touches `width`/`height`** (`src/php/Controller/ContentController.php:145-189`). An author-pasted `<img>` with no dimensions therefore stays dimensionless after rewriting, so the browser cannot reserve space → layout shift.

3. **Nothing marks or prioritizes the LCP image.** There is no `fetchpriority="high"`, no `<link rel="preload">`, and no coordination with lazy-loading. WordPress core's `wp_get_loading_optimization_attributes()` (6.3+) makes a *guess* at the first large image, but Bunnify — which knows the rewritten CDN URL and the actual rendered dimensions — never reinforces or corrects it, and never exposes a filter for a site to name its real hero image.

4. **`filter_sizes()` is an inert pass-through.** `ImageController::filter_sizes()` returns the incoming `sizes` verbatim (`src/php/Controller/ImageController.php:829-833`) with a standing "could be enhanced" comment. Mis-stated `sizes` makes the browser pick the wrong `srcset` candidate, which shows up as "Properly size images." We should at least *verify* correctness here rather than silently trusting it.

These are all additive: the rewriting pipeline is sound, but it stops short of the last mile that a PageSpeed audit measures.

## Goals
- Provide an **opt-in `format` control** (e.g. `auto`/`webp`/`avif`/off) that injects `format=` into `$cdn_args`, plus a `bunnify_image_format` filter for per-image override. Emits nothing when off. Defers content-negotiation semantics to [[format-negotiation]].
- Provide an **opt-in `quality` control** (integer, off by default) that injects `quality=` into `$cdn_args`, plus a `bunnify_image_quality` filter. Absent/`0` emits nothing (preserving today's bytes-unchanged behaviour).
- **Guarantee `width` and `height` on every Bunnify-rewritten `<img>`** where dimensions are resolvable — closing the CLS gap in `ContentController` and making the `ImageController` emission unconditional when a dimension is known. Gated so it never overwrites author-supplied values with a mismatched aspect ratio.
- Add **opt-in LCP acceleration**: `fetchpriority="high"` on exactly one detected image (first eligible content/attachment image), an optional `<link rel="preload" as="image">` for that image's CDN URL (with `imagesrcset`/`imagesizes`), and a `bunnify_lcp_image` filter so a site can name its hero explicitly.
- **Coordinate lazy-loading**: never emit `loading="lazy"` on the LCP image; leave core's below-the-fold `loading="lazy"` intact and do not fight `wp_get_loading_optimization_attributes()`.
- Keep every feature behind a setting that defaults to **off/neutral**, and behind the existing effective-enabled master gate (`SettingsController::is_enabled()`, `src/php/Controller/SettingsController.php:604-612`). No behaviour change for an install that upgrades and touches nothing.
- Note (not re-implement) preconnect: it is already correct in `WPResourceHintsController` (`src/php/Controller/WPResourceHintsController.php:35-71`).

## Non-goals
- **Not a CWV monitor.** No field-data collection, no LCP-element attribution reporting, no regression dashboards — that is PagePulse. This blueprint only shapes emitted markup.
- **Not format content-negotiation.** Whether `format=auto` is safe for a given origin type (animated GIF, already-`webp`, transparency), `Accept`-header behaviour, and Optimizer-disabled fallbacks are [[format-negotiation]]'s remit. Here we only wire the emission path and its opt-in.
- **No new image-resize math.** Dimension resolution reuses the existing `calculate_aspect_ratio_dimensions()` / metadata lookups; we do not introduce a second sizing source of truth.
- **No client-side JS.** No lazy-load library, no `IntersectionObserver` LCP detector, no runtime measurement script. LCP selection is a server-side, deterministic "first eligible image" heuristic plus a filter override.
- **No forced global `loading` policy.** We coordinate with core's optimizer; we do not strip or blanket-rewrite `loading` on every image.
- **No admin-page redesign.** New toggles slot into the existing Settings API section (ideally via [[data-driven-settings]]'s schema once it lands).

## Current state
**Transform plumbing (ready, unused for CWV).** `URLTransformer::build_query_string()` (`src/php/Library/URLTransformer.php:191-246`) maps `width`/`height`/`crop` and then passes through any additional scalar arg — so `$cdn_args['quality'] = 82` or `$cdn_args['format'] = 'auto'` would already reach the CDN as `quality=82`/`format=auto`. `is_cdn_url()` (`:403-440`) already lists `quality`, `format`, `crop` among the params that mark a URL as CDN-processed, so re-processing guards already understand them. **No caller ever sets these keys.**

**Attachment images (`ImageController`).** `filter_attachment_image()` (`:492-641`) resolves dimensions, builds `$cdn_args`, generates the CDN URL, then uses `WP_HTML_Tag_Processor` to set `src` and — conditionally — `width`/`height` (`:550-560`). `srcset` is transformed in place or generated via `generate_srcset_and_sizes()` (`:890-920`). Hooks are registered in `set_up()` (`:159-184`). `filter_sizes()` is a no-op (`:829-833`).

**Content images (`ContentController`).** `process_content_with_tag_processor()` (`:113-204`) walks every `<img>`, skips already-handled attachment images via `is_attachment_image()` (`:326-373`), then sets `src` (`:179`) and `srcset` (`:186`). It reads existing `width`/`height` attributes to build `$cdn_args` (`:145-166`) **but never writes them back** — so a source `<img>` with no dimensions gets a rewritten `src` and still no `width`/`height`.

**Resource hints.** `WPResourceHintsController::update_resource_hints()` (`:35-71`) adds a plain (non-`crossorigin`) `preconnect` to the CDN host when it differs from the site host, and strips the duplicate `dns-prefetch`. Gated on `SettingsController::is_enabled()`. **This is the item-7 "already handled" note — no work here.**

**Gating primitives.** `SettingsController::is_enabled()` (`:604-612`) is the master switch (missing/`''` ⇒ enabled, explicit `'0'` ⇒ off). `is_local_dev_mode_enabled()` (`:636-650`) bypasses rewriting for locally-present files. Per-feature debug categories flow through `is_debug_enabled_for_category()` (`:398`). New toggles register alongside the existing `register_setting( 'bunnify_frontend_options', … )` calls (`:73-101`).

## Proposed approach
Every feature is a settings toggle (default off/neutral) **and** a filter, resolved once per image inside the existing filters. Nothing runs unless `SettingsController::is_enabled()` is true and the feature's own opt-in is set.

### Shared: a single arg-decoration point
Rather than sprinkle `format`/`quality` across every `$cdn_args` build site, decorate args in one place the whole pipeline already funnels through. `build_query_string()` is too low (it also serves non-image transforms), so add a small helper on a CWV concern that the two image controllers call just before `get_cdn_url_by_id()`:

```php
// Illustrative. Lives in a CwvImageTrait or a small CwvController helper,
// used by ImageController and ContentController.
private function decorate_cwv_args( array $cdn_args, int $attachment_id, array $ctx = [] ): array {
    // Format — opt-in. '' / 'off' emits nothing (see [[format-negotiation]]).
    $format = (string) apply_filters(
        'bunnify_image_format',
        SettingsController::image_format(),   // '' | 'auto' | 'webp' | 'avif'
        $attachment_id,
        $ctx
    );
    if ( '' !== $format && 'off' !== $format ) {
        $cdn_args['format'] = $format;
    }

    // Quality — opt-in. 0 / absent emits nothing (bytes unchanged).
    $quality = (int) apply_filters(
        'bunnify_image_quality',
        SettingsController::image_quality(),  // 0 = unset
        $attachment_id,
        $ctx
    );
    if ( $quality > 0 ) {
        $cdn_args['quality'] = min( max( $quality, 1 ), 100 );
    }

    return $cdn_args;
}
```

Because `build_query_string()` already forwards these keys (`URLTransformer.php:224-243`), no `URLTransformer` change is needed — this is purely "populate `$cdn_args` before the URL is built." Call it in `ImageController::filter_attachment_image()` (before `:541`), the srcset builders, and `ContentController::process_content_with_tag_processor()` (before `:173`).

### (1) Properly size images — verify, don't rebuild
This is already core behaviour (`filter_image_downsize`, `filter_srcset_array`, `generate_srcset_and_sizes`). The action item is a **correctness audit**, not new code: confirm the generated `sizes` string in `generate_sizes_attribute()` (`ImageController.php:1026-1041`) matches real layout width for common cases, and decide whether `filter_sizes()` (`:829-833`) should stay a pass-through or gain an opt-in override. Keep it a pass-through unless the audit finds the heuristic breakpoints causing over-fetch; if it does, the fix is an opt-in `bunnify_sizes` filter, not a default change.

### (2) Next-gen formats — `format=auto|webp|avif`
Covered by `decorate_cwv_args()` above. Setting `bunnify_image_format` (default `''` = off). When set, `format=auto` (or an explicit codec) reaches Bunny's Optimizer. **Vendor note:** the CDN's image optimizer must be enabled on the pull zone for `format`/`quality` to take effect; when it is off these params are inert, not harmful. The *decision* of when `auto` is safe (transparency, animation, already-optimized origins) is deferred to [[format-negotiation]] — this blueprint only provides the switch and the emission.

### (3) Efficient encoding — `quality=`
Covered by `decorate_cwv_args()`. Setting `bunnify_image_quality` (integer, default `0`/off), clamped `1–100`, plus the `bunnify_image_quality` filter for per-image control (e.g. lower quality for below-the-fold, higher for the hero). Off by default so no existing install silently changes its bytes.

### (4) Explicit width/height (CLS) — always emit when known
In `ContentController`, after computing `$cdn_args` (which already resolves aspect-correct dimensions via `calculate_aspect_ratio_dimensions()`, `:155-166`), write the dimensions back — but only when the source `<img>` lacks them, so we never fight an intentional author value:

```php
// ContentController::process_content_with_tag_processor(), after set_attribute('src', …) at :179
if ( SettingsController::emit_dimensions() ) {                 // opt-in, default on for new, off-safe on upgrade
    if ( ! $processor->get_attribute( 'width' ) && ! empty( $cdn_args['width'] ) ) {
        $processor->set_attribute( 'width', (string) $cdn_args['width'] );
    }
    if ( ! $processor->get_attribute( 'height' ) && ! empty( $cdn_args['height'] ) ) {
        $processor->set_attribute( 'height', (string) $cdn_args['height'] );
    }
}
```

In `ImageController::filter_attachment_image()`, make the existing conditional set (`:555-560`) reliably fire whenever a dimension is resolved (it usually is), and log a debug entry when it cannot so gaps are visible. **No-layout-surprise rule:** we only *add* missing attributes, never overwrite present ones, and the pair we write is the aspect-consistent pair Bunnify already computed for the CDN URL — so the reserved box matches the delivered pixels.

### (5) LCP image — `fetchpriority="high"` + optional preload
The hard constraint is **exactly one** high-priority image per response. Track it in a per-request flag on a small `CwvLcpController` (or a static on a trait) that both content and attachment paths consult:

```php
private static bool $lcp_assigned = false;

private function maybe_mark_lcp( WP_HTML_Tag_Processor $img, string $cdn_url, ?string $srcset, ?string $sizes ): void {
    if ( self::$lcp_assigned || ! SettingsController::lcp_optimize() || is_admin() ) {
        return;
    }
    // A site can name its hero; default heuristic = first eligible image.
    $is_lcp = (bool) apply_filters( 'bunnify_lcp_image', true, $cdn_url );
    if ( ! $is_lcp ) {
        return;
    }
    $img->set_attribute( 'fetchpriority', 'high' );
    $img->remove_attribute( 'loading' );           // never lazy-load the LCP image
    self::$lcp_assigned = true;

    if ( SettingsController::lcp_preload() ) {
        self::$preload = [ 'href' => $cdn_url, 'srcset' => $srcset, 'sizes' => $sizes ];
    }
}
```

The optional preload is emitted from `wp_head` (early priority) using the captured CDN URL so the preload and the `<img src>` are byte-identical (a mismatched preload is a wasted download — worse than none):

```php
add_action( 'wp_head', function () {
    if ( empty( self::$preload ) ) { return; }
    printf(
        '<link rel="preload" as="image" href="%s"%s%s fetchpriority="high" />' . "\n",
        esc_url( self::$preload['href'] ),
        self::$preload['srcset'] ? ' imagesrcset="' . esc_attr( self::$preload['srcset'] ) . '"' : '',
        self::$preload['sizes']  ? ' imagesizes="'  . esc_attr( self::$preload['sizes'] )  . '"' : ''
    );
}, 1 );
```

Because content is usually rendered inside `the_content` (which runs *after* `wp_head`), the preload path works when the hero is a **post thumbnail** resolved before `wp_head` (e.g. a theme header calling `the_post_thumbnail()`), or via the `bunnify_lcp_image` filter naming a known attachment ID up front. Where the hero is only known mid-body, `fetchpriority="high"` on the `<img>` still applies without a preload. Document this ordering honestly rather than emitting a late, ignored preload.

### (6) Lazy-loading coordination
Two rules, both additive:
- The LCP image never gets `loading="lazy"` — handled by `remove_attribute('loading')` in `maybe_mark_lcp()` above. If core already set `fetchpriority`/`loading` via `wp_get_loading_optimization_attributes()`, our removal + `fetchpriority="high"` reinforces the correct outcome for the *actual* hero when a site has used the filter to correct core's guess.
- Below-the-fold images keep core's `loading="lazy"`. We do **not** add our own lazy attribute globally (that risks lazy-loading an above-the-fold image core deliberately left eager). `WP_HTML_Tag_Processor` preserves any attribute we don't touch, so leaving `loading` alone is the safe default.

### (7) Preconnect — already handled
`WPResourceHintsController` (`:35-71`) already emits the correct plain `preconnect` and strips the duplicate `dns-prefetch`, gated on `is_enabled()`. No change. Cross-referenced from the caching/CDN notes; called out here only so the CWV picture is complete.

### Settings surface
Add `bunnify_image_format` (select), `bunnify_image_quality` (int), `bunnify_emit_dimensions` (checkbox), `bunnify_lcp_optimize` (checkbox), `bunnify_lcp_preload` (checkbox), each with a `sanitize_callback`. These should register through [[data-driven-settings]]'s schema once it lands; until then they follow the existing `register_setting`/`add_settings_field` pattern (`SettingsController.php:73-101`, `:110+`) with matching accessor statics (`image_format()`, `image_quality()`, `lcp_optimize()`, …) mirroring `is_enabled()`.

## Migration & backwards compatibility
- **No DB migration; upgrade-neutral by construction.** Every new option is absent on existing installs, and every accessor treats absent as the *pre-feature* behaviour: `format`/`quality` absent ⇒ no param emitted (bytes unchanged), `lcp_optimize` absent ⇒ no `fetchpriority`/preload, `lcp_preload` absent ⇒ no `<link>`. `bunnify_emit_dimensions` is the one judgement call — recommend defaulting it **on for the CLS win** *only if* the "add-missing-only, never-overwrite" rule holds (it does), since adding a correct `width`/`height` to a dimensionless `<img>` cannot regress layout; if the team prefers zero upgrade delta, default it off and flip it on in a minor release with a changelog note.
- **Public filter API is additive.** New filters — `bunnify_image_format`, `bunnify_image_quality`, `bunnify_lcp_image`, and (if adopted) `bunnify_sizes` — are new and optional. No existing filter in `docs/HOOKS.md` changes signature or default. Document the additions there.
- **`is_cdn_url()` already recognizes the new params.** `quality`/`format`/`crop` are already in its CDN-marker list (`URLTransformer.php:430`), so re-processing/idempotency guards keep working the moment we start emitting them — no guard changes required.
- **Downstream consumer impact: none unless opted in.** A consuming site typically wires only `bunnify_allow_non_upload_url` / `bunnify_skip_image` today; these features stay dormant until the consumer's integration controller (or the admin UI) sets a toggle or adds a filter.

## Risks & mitigations
- **More than one `fetchpriority="high"`.** Multiple high-priority images defeat the purpose and can *hurt* LCP. Mitigation: the per-request `$lcp_assigned` latch and a test asserting exactly one `fetchpriority="high"` across a full rendered page (content + thumbnail + widgets).
- **Preload that doesn't match the rendered `<img>`.** A preloaded URL differing from the final `src` (different `format`/`quality`/dimensions, or a srcset candidate the browser wouldn't pick) is a wasted byte download. Mitigation: capture the *exact* CDN URL and `srcset`/`sizes` used on the `<img>` and reuse them verbatim in the `<link>`; only emit the preload when the hero is known before `wp_head` (post thumbnail or filter-named ID), otherwise skip the preload and keep only `fetchpriority`.
- **`format=auto` on an unsuitable origin** (animation loss on GIFs, banding on flat art, breaking transparency). Mitigation: off by default; the *policy* lives in [[format-negotiation]]; the `bunnify_image_format` filter lets a site exclude specific attachments/mime types.
- **Overwriting an intentional author `width`/`height` and shifting layout.** Mitigation: the add-missing-only guard (`! $processor->get_attribute('width')`) and a test that a present dimension is left untouched. We only ever write the aspect-consistent pair Bunnify itself computed.
- **Fighting WordPress core's loading optimizer (6.3+).** Core may already set `fetchpriority`/`loading`; double-managing could conflict. Mitigation: only *remove* `loading` on the one LCP image and *add* `fetchpriority` there; never blanket-rewrite `loading`; test on WP ≥ 6.3 that a below-the-fold image retains core's `loading="lazy"`.
- **`wp_head` ordering / hero known too late.** Content renders after `wp_head`, so a mid-body hero can't be preloaded. Mitigation: documented above — degrade to `fetchpriority` only; don't emit a late, ignored preload. Provide the `bunnify_lcp_image` filter so sites with a known hero attachment get the full preload.
- **CDN Optimizer not enabled on the pull zone.** `format`/`quality` params are then inert. Mitigation: this is safe (no breakage), but the settings help text must state that the CDN-side Optimizer is a prerequisite, and the "Test Configuration" card could surface it.
- **Over-scoping into measurement.** Temptation to add "did LCP improve" reporting. Mitigation: explicit non-goal — that is PagePulse; this blueprint ends at emitted markup.

## Testing strategy
- **Dimensions present (CLS).** Given a content `<img>` with no `width`/`height`, assert the rewritten output carries both, and that the pair is aspect-consistent with `$cdn_args`. Given an `<img>` that already has dimensions, assert they are unchanged. (Brain Monkey unit test over `ContentController::process_content_with_tag_processor()`.)
- **Exactly one LCP image.** Render a fixture page with several images (a post thumbnail, two content images, a widget image); assert `fetchpriority="high"` appears on exactly one, that it is the first eligible one (or the filter-named one), and that that image has no `loading="lazy"`.
- **Preload correctness.** When `lcp_preload` is on and the hero is a pre-`wp_head` post thumbnail, assert one `<link rel="preload" as="image">` is emitted whose `href` (and `imagesrcset`/`imagesizes`) byte-matches the `<img>`. When the hero is only known mid-content, assert **no** preload is emitted but `fetchpriority` still is.
- **Format/quality params in URL.** With `bunnify_image_format='auto'` and `bunnify_image_quality=82`, assert rewritten URLs contain `format=auto&quality=82` (order-agnostic) and that with both off the URLs are byte-identical to today's output. Assert the `bunnify_image_quality` filter clamps out-of-range values.
- **Idempotency.** Re-running the content filter over already-rewritten HTML does not double-append params or re-mark a second LCP image (`is_cdn_url()` short-circuit + the latch).
- **Core-loading interop (integration).** On a `wp-phpunit` layer (per [[data-driven-settings]]/full-test-coverage), assert a below-the-fold image keeps core's `loading="lazy"` and the LCP image is eager on WP ≥ 6.3.
- **Upgrade-neutrality.** With no options set, assert emitted markup is byte-identical to the current release for a representative page (guards the "touch nothing, change nothing" promise).
- **Static analysis.** PHPStan level 5 green and phpcs/WPCS clean on the touched controllers and any new `Cwv*` class/trait.

## Rollout plan
1. **`decorate_cwv_args()` + format/quality settings, defaulting off.** Wire the helper into the two image controllers; add the two settings and their accessors/filters; land the URL-param tests. Ship — zero effect until a toggle is set.
2. **Explicit width/height for content images.** Add the add-missing-only writes in `ContentController` and firm up `ImageController`'s emission; land the CLS tests. Decide the `bunnify_emit_dimensions` default per Migration.
3. **LCP `fetchpriority` (no preload yet).** Add the `CwvLcpController`/trait latch, the `bunnify_lcp_image` filter, and the lazy-load coordination; land the "exactly one" test.
4. **Optional preload.** Add the `wp_head` emitter and the `lcp_preload` toggle; land the preload-match test and the "degrade gracefully when hero is late" test.
5. **`sizes`/"properly size images" audit.** Verify `generate_sizes_attribute()` against real layouts; add an opt-in `bunnify_sizes` filter only if the audit warrants it.
6. **Docs.** Add all new filters and settings to `docs/HOOKS.md`, note the CDN-Optimizer prerequisite, and cross-link [[format-negotiation]]. Update the enhancements index row.

Each step is independently shippable and reversible; steps 1–2 are the highest value-to-risk (bytes + CLS), steps 3–4 carry the LCP nuance.

## Effort estimate
**L.** Items 1 and 7 are verify/note-only, and items 2–4 are small additions riding plumbing that already forwards the params and computes the dimensions — those alone would be an M. What pushes it to L is the LCP work in items 5–6: a per-request "exactly one image" latch that spans the content and attachment paths, a `wp_head` preload whose URL must byte-match a later-rendered `<img>` (with an honest graceful-degrade when the hero is known too late), and correct coordination with WordPress core's own 6.3+ loading optimizer — plus the interop/integration tests that prove none of it double-fires or fights core. The breadth (five opt-in toggles across two controllers and a new CWV concern) and the "no layout surprise / exactly one hint" guarantees are what make this a large rather than medium change.
