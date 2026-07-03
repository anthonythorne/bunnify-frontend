# URL surface coverage — attachment URLs, header images, and CSS backgrounds

- **Status:** Proposed
- **Created:** 2026-07-03
- **Owner:** _unassigned_
- **Related:** [[di-container-service-layer]] (the shared CDN service / `bunnify_url` path this reuses), [[full-test-coverage]] (the `wp-phpunit` integration layer the origin-lookup regression tests depend on), [[rest-controller-completion]] (the REST `source_url` surface this newly rewrites)

## Summary
Bunnify rewrites `<img>` `src`/`srcset` and every WordPress resize path, but it never touches a **bare attachment URL** — the plain `https://site/wp-content/uploads/…/photo.jpg` that `wp_get_attachment_url()` returns, that classic themes emit for the custom header, and that block editor writes into inline `background-image: url(…)` styles. Those URLs stay on the origin host, so on an install without synced uploads they 404 (blank hero/background), and in production they silently bypass the CDN (origin bandwidth, no edge cache, no LCP win). This blueprint closes three concrete leaks: (1) the `wp_get_attachment_url` filter — with a re-entrancy guard because the plugin itself calls `wp_get_attachment_url()` in eleven internal places to obtain the *origin* URL; (2) `theme_mod_header_image` / `get_header_image` for classic themes; and (3) inline `background-image: url(…)` on `core/cover`, `core/group`, and `core/columns` (WP 6.5+). It reuses the existing enabled/hostname/local-dev gating and the public `bunnify_url` filter, and adds no new subsystems — deep page/field/CWV monitoring stays out of scope (that is the author's separate PagePulse plugin, not this one).

## Motivation / Problem
The plugin's coverage is shaped around one HTML element and WordPress's resize pipeline. Three real surfaces fall outside it.

1. **Bare attachment URLs leak the origin.** `wp_get_attachment_url()` is the canonical "give me this attachment's URL" call, used by themes, page builders, block bindings, feeds, and the REST media endpoint (`source_url`). The plugin does not filter it, so every one of those consumers emits an origin URL. On a fresh install whose uploads were never synced to disk (a common local/staging/CI state that local-dev mode exists to paper over) those URLs **404**; in production they resolve but skip the CDN entirely. The image filters only fire for images routed through `image_downsize` / `wp_get_attachment_image*` — a raw `wp_get_attachment_url()` in a template bypasses all of them.

2. **Classic-theme header images bypass the CDN.** A classic theme's custom header renders through `get_header_image()` / the `theme_mod_header_image` theme mod, not through any image filter Bunnify hooks. The header is frequently the LCP element, so this is the single highest-value bare URL on many sites, and it is completely untouched today.

3. **Block background images leak.** `core/cover` with a fixed or repeated background, and `core/group` / `core/columns` background-image support (WP 6.5+), render the image as an inline `style="background-image:url(…)"` attribute on the block wrapper — not as an `<img>`. `ContentController`'s tag processor walks the content but only matches `img` tags and only rewrites `src`/`srcset` (`src/php/Controller/ContentController.php:116`), so these background URLs pass straight through on the origin host. Covers are typically above the fold, so this is a visible CWV and bandwidth cost.

The reason (1) is not a one-line `add_filter` is the crux of this blueprint: the plugin calls `wp_get_attachment_url()` in **eleven internal places specifically to get the origin URL** (to build a CDN URL from it, to test local existence, or to cache it). A naive global filter would make those internal calls return the CDN URL, so `get_cdn_url_by_id()` would try to build a CDN URL from a CDN URL, `image_exists_locally()` would stat a CDN path, and the caches would be poisoned — plus the filter would recurse into itself.

## Goals
- Rewrite bare attachment image URLs to CDN via a `wp_get_attachment_url` filter, gated exactly like the existing image filters (`is_enabled()` + configured hostname + admin guard + local-dev exists-locally + `validate_image_url`).
- Introduce a **Photon-style suspend guard** (a private static `$suspend` flag) and a single `origin_attachment_url()` wrapper, and route **all eleven** internal origin lookups through it so they keep returning the origin URL and the new filter never re-enters itself.
- Rewrite classic-theme header images via `theme_mod_header_image` / `get_header_image`, reusing the `bunnify_url` filter path.
- Rewrite inline `background-image: url(…)` on `core/cover` / `core/group` / `core/columns` by extending the existing `WP_HTML_Tag_Processor` loop to also rewrite the `style` attribute, reusing the content-image URL path.
- Prove, with integration tests, that with the new filter registered the eleven internal origin lookups still return origin (not CDN) and that there is no recursion.
- Add escape hatches consistent with the existing filter API; document every new hook in `docs/HOOKS.md`.
- Keep the plugin lightweight: no new options, no new admin UI, no stylesheet parsing, no page-level monitoring.

## Non-goals
- **No general CSS/stylesheet rewriting.** Only inline `style` attributes on block wrappers in post content are in scope. Parsing external or `<style>`-block CSS to find `url(…)` references is explicitly out — it is heavy, fragile, and outside the plugin's identity as a lightweight frontend URL rewriter.
- **No page-speed, field-level, or CWV monitoring / reporting.** Measuring which URLs leaked, LCP attribution, or coverage dashboards belong in the author's separate **PagePulse** plugin, not here. This blueprint only *rewrites* URLs.
- **No new settings, options, or admin screens.** All behaviour is derived from the existing `bunnify_enabled` / `bunnify_hostname` / local-dev gating.
- **No non-image attachment handling by default.** The `wp_get_attachment_url` filter fires for PDFs, docs, and zips too; the default gate stays images-only via `validate_image_url` (a filter can opt those in). Bunny can serve any file, but broadening the default is a separate decision.
- **No change to the existing image/srcset/content filters' output** beyond the shared-helper refactor needed to reuse their URL logic.
- **No block-editor (`edit` context) rewriting.** Admin/editor surfaces keep origin URLs under the existing `is_admin_without_local_dev()` posture.

## Current state
**The eleven internal origin lookups.** Every one of these calls `wp_get_attachment_url()` to obtain the *origin* URL, then either builds a CDN URL from it, tests local existence, or caches it:

| # | Site | Purpose |
| --- | --- | --- |
| 1 | `src/php/Library/URLTransformer.php:497` | `get_cdn_url_by_id()` — fetches origin to build the CDN URL. **The critical one:** the new filter callback calls this, so it must go through the guard or the filter recurses. |
| 2 | `src/php/Library/ImageProcessor.php:228` | `get_cached_original_url()` — caches the origin URL under `bunnify_original_url_{id}`. |
| 3 | `src/php/Base/Traits/CachingTrait.php:158` | `get_cached_attachment_url()` — caches origin under `attachment_url_{id}`. |
| 4 | `src/php/Model/PostType/Attachment.php:42` | `Attachment::get_attachment_url()` — model getter. |
| 5 | `src/php/Controller/ImageController.php:237` | `filter_image_downsize()` — origin for CDN build. |
| 6 | `src/php/Controller/ImageController.php:373` | `filter_attachment_img_srcs()` — origin for CDN build. |
| 7 | `src/php/Controller/ImageController.php:497` | `filter_attachment_image()` — origin for CDN build. |
| 8 | `src/php/Controller/ImageController.php:683` | `filter_attachment_for_js()` — origin for the media picker. |
| 9 | `src/php/Controller/ImageController.php:747` | `filter_srcset_array()` — origin for CDN build. |
| 10 | `src/php/Controller/ContentController.php:138` | tag-processor `img` loop — origin to test `image_exists_locally()`. |
| 11 | `src/php/Controller/ContentController.php:216` | `transform_srcset_for_content()` — origin to test `image_exists_locally()`. |

None of these are filtered today, so they all return origin; adding a `wp_get_attachment_url` filter changes that for all of them at once unless they are guarded.

**Existing gating and the reusable path.** The plugin already centralizes the decisions this work needs:
- Master switch + hostname: `SettingsController::is_enabled()` (`src/php/Controller/SettingsController.php:604`) and the hostname check inside `URLTransformer::init_static_cdn()` (`src/php/Library/URLTransformer.php:461-484`) and `CdnClientTrait::init_cdn()` (`src/php/Library/CdnClientTrait.php:43-62`).
- Local-dev: `SettingsController::is_local_dev_mode_enabled()` (`:636`) + the per-image `URLTransformer::image_exists_locally()` (`src/php/Library/URLTransformer.php:307`).
- Admin posture: `ImageController::is_admin_without_local_dev()` (`src/php/Controller/ImageController.php:203`).
- Image-URL gate: `URLTransformer::validate_image_url()` (`src/php/Library/URLTransformer.php:366`).
- The public `bunnify_url` filter, registered by `CDNController` (`src/php/Controller/CDNController.php:36`), whose `cdn_url()` handler (`:47`) already resolves an attachment ID, honours `bunnify_skip_for_url` / `bunnify_pre_image_url`, and falls back to `transform_url()` — exactly the "give me the CDN URL for this bare URL" entry point the header-image and background surfaces need.

**Content tag processor.** `ContentController::process_content_with_tag_processor()` (`src/php/Controller/ContentController.php:113-204`) loops `while ( $processor->next_tag( 'img' ) )` and rewrites only `src`/`srcset`. The `style` attribute is never read. The per-image logic (validate → attachment ID → local-dev bypass → `get_cdn_url_by_id()` → fallback `transform_url_direct()`) is inlined in that loop (`:125-199`) and is a natural extract point for reuse by the background-image branch.

**Header image.** No `theme_mod_header_image`, `get_header_image`, or `get_custom_header` hook exists anywhere in `src/` (confirmed by grep). This surface is entirely unhandled.

## Proposed approach

### 1. `wp_get_attachment_url` filter + suspend guard + `origin_attachment_url()` wrapper
Add a tiny static provider in the plugin's `Library` (kept inside phpcs coverage, unlike `src/php/Base`) that owns the guard and the wrapper. It is static so both the static call sites (`URLTransformer`, `ImageProcessor`) and the instance/trait/model call sites can reach it.

```php
// src/php/Library/AttachmentUrl.php — illustrative.
namespace BunnifyFrontend\Library;

final class AttachmentUrl {
    /** Photon-style re-entrancy guard: true while we are fetching an ORIGIN URL. */
    private static bool $suspend = false;

    public static function is_suspended(): bool {
        return self::$suspend;
    }

    /**
     * Origin (un-rewritten) attachment URL. Suspends the wp_get_attachment_url
     * filter for the duration so our own callback returns the raw URL.
     */
    public static function origin( int $attachment_id ): string|false {
        $prev = self::$suspend;
        self::$suspend = true;
        try {
            return wp_get_attachment_url( $attachment_id );
        } finally {
            self::$suspend = $prev; // nested-safe restore
        }
    }
}
```

The filter callback lives on `ImageController` (the image hub) and is registered in `set_up()` alongside the other image filters:

```php
add_filter( 'wp_get_attachment_url', [ $this, 'filter_attachment_url' ], 10, 2 );

public function filter_attachment_url( $url, $post_id ) {
    // (a) Internal origin lookup in progress — never rewrite it. This is the
    //     re-entrancy guard AND what keeps the eleven callers returning origin.
    if ( \BunnifyFrontend\Library\AttachmentUrl::is_suspended() ) {
        return $url;
    }
    if ( ! is_string( $url ) || '' === $url ) {
        return $url;
    }
    // (b) Same admin posture as the other image filters.
    if ( $this->is_admin_without_local_dev()
        && false === apply_filters( 'bunnify_admin_allow_attachment_url', false, $url, $post_id ) ) {
        return $url;
    }
    // (c) Images only, by default (PDFs/docs opt in via bunnify_validate_image_url).
    if ( ! URLTransformer::validate_image_url( $url ) ) {
        return $url;
    }
    // (d) Local-dev: a locally-present original stays on origin.
    if ( SettingsController::is_local_dev_mode_enabled()
        && URLTransformer::image_exists_locally( $url ) ) {
        return $url;
    }
    // (e) Full-size CDN URL (no args → preserves the original filename).
    //     get_cdn_url_by_id() internally calls AttachmentUrl::origin(), so the
    //     suspend flag is set during that inner call → no recursion, no double
    //     transform. A null return means "CDN unavailable" → keep origin.
    $cdn = URLTransformer::get_cdn_url_by_id( (int) $post_id );
    return $cdn ?: $url;
}
```

**The mechanical part: route all eleven call sites through `AttachmentUrl::origin()`.** Each `wp_get_attachment_url( $id )` in the table above becomes `\BunnifyFrontend\Library\AttachmentUrl::origin( $id )`. Site #1 (`URLTransformer.php:497`) is load-bearing — with it converted, the filter callback's `get_cdn_url_by_id()` call fetches origin under suspension, builds the CDN URL, and returns without recursing. Sites #2/#3 (the caches) must be converted so they cache the *origin* URL, not the CDN URL. Sites #10/#11 must be converted so `image_exists_locally()` receives an origin filesystem path.

No behaviour changes for the eleven callers because `origin()` returns exactly what `wp_get_attachment_url()` returned before the filter existed.

### 2. Header image (classic themes)
Rewrite the header theme mod and the resolved header URL through the existing `bunnify_url` path, with the same local-dev bypass as everything else:

```php
// A small controller (or ImageController::set_up()).
add_filter( 'theme_mod_header_image', [ $this, 'filter_header_image_url' ], 10 );
add_filter( 'get_header_image',       [ $this, 'filter_header_image_url' ], 10 );

public function filter_header_image_url( $url ) {
    if ( ! is_string( $url ) || '' === $url || 'remove-header' === $url ) {
        return $url;
    }
    if ( ! URLTransformer::validate_image_url( $url ) ) {
        return $url;
    }
    if ( SettingsController::is_local_dev_mode_enabled()
        && URLTransformer::image_exists_locally( $url ) ) {
        return $url;
    }
    // Reuse the public path: resolves the attachment ID, honours bunnify_skip_for_url,
    // and applies enabled/hostname gating inside cdn_url()/init_cdn().
    return (string) apply_filters( 'bunnify_url', $url );
}
```

`get_custom_header()->url` used by block themes flows through `wp_get_attachment_url` / the media pipeline and is already covered by (1); the theme-mod filters here are the classic-theme gap.

### 3. Inline `background-image: url(…)` in blocks
First, extract the existing per-image URL logic from the tag-processor loop into a reusable helper so both the `img` branch and the new `style` branch share one gated code path:

```php
// Extracted from process_content_with_tag_processor()'s img branch.
private function rewrite_content_image_url( string $url, array $cdn_args = [] ): ?string {
    if ( ! URLTransformer::validate_image_url( $url ) || URLTransformer::is_cdn_url( $url ) ) {
        return null;
    }
    if ( SettingsController::is_local_dev_mode_enabled()
        && URLTransformer::image_exists_locally( $url ) ) {
        return null; // keep origin
    }
    $attachment_id = ImageProcessor::get_attachment_id_from_url( $url );
    if ( $attachment_id ) {
        return URLTransformer::get_cdn_url_by_id( $attachment_id, $cdn_args ) ?: null;
    }
    return $this->transform_url_direct( $url ) ?: null;
}
```

Then extend the processor loop to also handle `style`. Rather than a second `img`-only pass, iterate every tag once and branch:

```php
$processor = new WP_HTML_Tag_Processor( $content );
while ( $processor->next_tag() ) {
    // existing img handling, now calling rewrite_content_image_url()...

    // Block background images: core/cover (fixed/repeat), core/group,
    // core/columns (WP 6.5+) render background-image inline on the wrapper.
    $style = $processor->get_attribute( 'style' );
    if ( is_string( $style ) && false !== stripos( $style, 'background-image' ) ) {
        $new_style = preg_replace_callback(
            '/url\(\s*([\'"]?)([^\'")]+)\1\s*\)/i',
            function ( $m ) {
                $cdn = $this->rewrite_content_image_url( trim( $m[2] ) );
                return $cdn ? 'url(' . $m[1] . $cdn . $m[1] . ')' : $m[0];
            },
            $style
        );
        if ( $new_style !== $style ) {
            $processor->set_attribute( 'style', $new_style );
        }
    }
}
```

This stays inside the block wrapper's inline style — no stylesheet parsing — and is gated by the existing `bunnify_skip_content_processing` short-circuit at the top of `filter_the_content()` (`src/php/Controller/ContentController.php:99`). A `bunnify_skip_background_image` filter can exempt specific URLs.

## Migration & backwards compatibility
- **New public hooks are additive:** `bunnify_admin_allow_attachment_url` and `bunnify_skip_background_image`. Existing filters (`bunnify_url`, `bunnify_skip_for_url`, `bunnify_pre_image_url`, `bunnify_local_dev_mode_check`, …) are unchanged. Document all new hooks in `docs/HOOKS.md` and add `wp_get_attachment_url`, `theme_mod_header_image`, `get_header_image` to the "WordPress Core Hooks Used" list.
- **Behaviour change — bare attachment URLs now rewrite.** This is the intended fix, but it changes what `wp_get_attachment_url()` returns on the front end for image attachments site-wide. It is fully gated: off unless `is_enabled()` and a hostname are set, images-only, admin-guarded, local-dev-aware, and skippable per-URL via `bunnify_skip_for_url` (honoured through the `bunnify_url` path) and `bunnify_admin_allow_attachment_url`. Existing installs with no hostname see zero change.
- **REST `source_url` now CDN-rewritten.** `/wp/v2/media` `source_url` comes from `wp_get_attachment_url` and `is_admin()` is false in REST, so it will be rewritten — consistent with the existing REST posture documented in `docs/HOOKS.md` (media size URLs are already CDN-rewritten by design; `context=edit` `content.raw` stays raw). Cross-reference the [[rest-controller-completion]] decision record.
- **No DB migration, no new options.** Nothing is stored; the guard and wrapper are runtime-only.
- **The eleven internal callers are behaviour-preserving:** `AttachmentUrl::origin()` returns the same value `wp_get_attachment_url()` did pre-filter, so caches, local-dev checks, and CDN builds are byte-identical to today.
- **Consumer impact (downstream ext plugins):** a consumer that hooks only the public filters is unaffected; one that reads `wp_get_attachment_url()` on the front end expecting origin must switch to the `bunnify_skip_for_url` escape or accept the CDN URL (documented).

## Risks & mitigations
- **Filter re-entrancy / infinite recursion.** `wp_get_attachment_url()` applies its own filter, and the callback calls `get_cdn_url_by_id()` which calls `wp_get_attachment_url()` again. **Mitigation:** the `$suspend` guard short-circuits the callback whenever an internal origin lookup is in flight; `origin()` sets/clears it with a `try/finally` and nested-safe restore. A test asserts a single-attachment render does not exceed one filter re-entry.
- **A missed internal call site poisons a cache or double-transforms.** If any of the eleven is left on raw `wp_get_attachment_url()`, its cache stores a CDN URL or `image_exists_locally()` stats a CDN path. **Mitigation:** an integration test that registers the real filter and asserts each caching/lookup helper (`ImageProcessor::get_cached_original_url()`, `CachingTrait::get_cached_attachment_url()`, `Attachment::get_attachment_url()`, `URLTransformer::get_cdn_url_by_id()`) still returns/uses origin; plus a phpcs/grep guard that flags any new raw `wp_get_attachment_url(` outside `AttachmentUrl::origin()`.
- **Over-broad rewriting (non-image attachments, feeds, sitemaps).** **Mitigation:** default images-only via `validate_image_url`; `bunnify_skip_for_url` honoured; admin guarded. Feeds/sitemaps that want origin can filter.
- **`style`-attribute rewriting corrupting CSS.** A greedy or scheme-naive regex could mangle `url()` values (data URIs, gradients, external URLs). **Mitigation:** the regex captures a single `url(...)` token; each candidate still passes `validate_image_url` (which rejects data URIs and non-image extensions) and the local-upload check before rewrite; `data:`/external URLs simply fail the gate and are returned untouched. Unit tests cover data-URI, gradient, multiple-background, and external-URL cases.
- **Scope creep toward a general CSS rewriter or monitoring.** **Mitigation:** documented as an explicit non-goal; inline block-wrapper styles only; monitoring is PagePulse's job.
- **Iterating every tag (vs `img` only) adds work on large posts.** **Mitigation:** the `style` branch does a cheap `stripos` before any regex; the loop was already O(tags); measured overhead is a `get_attribute('style')` per tag. Fall back to a second targeted pass only if profiling shows a regression.

## Testing strategy
- **Unit (PHPUnit 9 + Brain Monkey).**
  - `AttachmentUrl`: `origin()` sets `$suspend` true during the inner call and restores it (including nested calls); `is_suspended()` reflects state.
  - `filter_attachment_url()`: returns `$url` unchanged when suspended, when disabled/no hostname, in admin without local-dev, for non-image URLs, and when the local file exists in local-dev; returns the CDN URL otherwise; `null` from `get_cdn_url_by_id()` falls back to origin.
  - Header-image callback: rewrites a valid image theme mod, passes through `remove-header` and non-image values, bypasses in local-dev-with-local-file.
  - Background-image regex: rewrites single/multiple `url()`, leaves data URIs, `linear-gradient()`, external URLs, and already-CDN URLs untouched.
- **Integration (`wp-phpunit`, per [[full-test-coverage]]) — the required origin-lookup regression.** With the real `wp_get_attachment_url` filter registered against a seeded image attachment on a configured hostname: assert raw `wp_get_attachment_url()` returns the CDN host, **and** that all eleven internal origin lookups still return the origin host — concretely `AttachmentUrl::origin()`, `URLTransformer::get_cdn_url_by_id()` (builds from origin path), `ImageProcessor::get_cached_original_url()`, `CachingTrait::get_cached_attachment_url()`, and `Attachment::get_attachment_url()`. Assert the cache entries (`bunnify_original_url_{id}`, `attachment_url_{id}`) hold origin, not CDN. Assert no recursion (filter fires at most twice per resolution).
- **Content integration.** Render `core/cover` (fixed background), `core/group`, and `core/columns` block markup through `the_content` and assert the inline `background-image` URL is on the CDN host; assert `bunnify_skip_background_image` and `bunnify_skip_content_processing` exempt it.
- **Static analysis.** PHPStan level 5 green; phpcs/WPCS clean on the touched files and the new `AttachmentUrl` provider (in `src/php/Library`, inside the linted tree).

## Rollout plan
1. **Land `AttachmentUrl` + the wrapper, convert the eleven call sites, add the integration test — but do not register the filter yet.** This is a pure no-op refactor (origin in, origin out) and proves the guard before any behaviour changes.
2. **Register `wp_get_attachment_url` filter** with full gating and the `bunnify_admin_allow_attachment_url` escape; document it. Ship behind the existing enabled/hostname gate so unconfigured installs are unaffected.
3. **Add the header-image filters** (`theme_mod_header_image` / `get_header_image`), reusing `bunnify_url`.
4. **Extract `rewrite_content_image_url()`** from the img loop (no behaviour change; covered by existing content tests) and **add the `style`/background-image branch** with `bunnify_skip_background_image`.
5. **Update `docs/HOOKS.md`** (core hooks list + the two new custom filters) and the changelog; cross-link the [[rest-controller-completion]] note about `source_url`.

Each step is independently shippable and reversible; step 1 carries no user-facing change, and steps 2–4 are each gated and skippable.

## Effort estimate
**L (~2–4 days / 16–30h).** The wrapper and the eleven call-site conversions are mechanical, but this touches a **global, site-wide filter** (`wp_get_attachment_url`) whose blast radius includes themes, feeds, and REST, so the re-entrancy guard, the images-only/admin/local-dev gating, and — above all — the integration harness that *proves* the eleven internal origin lookups still return origin under a live filter are what push it well past the settings-refactor's M. The header-image and background-image surfaces are smaller (a few filters and one shared-helper extraction) but each needs its own gated tests. The dependency on the [[full-test-coverage]] `wp-phpunit` layer for the origin-regression tests is the main scheduling constraint.
