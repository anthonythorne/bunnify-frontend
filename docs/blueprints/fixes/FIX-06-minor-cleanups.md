# FIX-06 — Minor cleanups batch (one small PR)

- **Severity:** Low (hygiene; no user-visible behaviour change expected)
- **Status:** Open
- **Found:** 2026-07-06 final review pass

Five small items, safe to land together.

## 1. Dead duplicate validator

`ImageProcessor::validate_image_url()` (`src/php/Library/ImageProcessor.php:127-171`)
has **zero callers** (grep confirms) and implements *different* rules from the
authoritative `URLTransformer::validate_image_url()` (scheme/port checks, no
CDN-URL rejection, its own `bunnify_validate_image_url` filter). Delete it —
and note `docs/HOOKS.md` if it documents `bunnify_validate_image_url`, since
that filter only fires from this dead method.

## 2. Redundant `is_cdn_url()` guards

`URLTransformer::validate_image_url()` already rejects CDN URLs internally
(`URLTransformer.php:424`). The explicit `|| URLTransformer::is_cdn_url( $url )`
in these newer call sites is unreachable dead logic — drop it (or keep one and
add a comment; pick one style):

- `ImageController::filter_attachment_url()`
- `ImageController::filter_header_image_url()` (`ImageController.php:821`)
- `ContentController::rewrite_loose_image_url()`

## 3. `full`-size generated-srcset edge

`ImageController::get_size_array()` (`ImageController.php:1035-1056`) returns
`[ null, null ]` for `'full'` (the registered sizes map stores nulls), and
`generate_srcset_and_sizes()` then builds a source keyed `''` producing a
malformed candidate (`url  w`, no width value) in the rare
attachment-image-without-srcset path. Guard: return `false` when width/height
resolve to null/0 so generation is skipped for dimensionless sizes. Unit-test
`get_size_array( 'full', … ) === false`.

## 4. Uncached metadata read

`filter_image_downsize()` array-size branch calls raw
`wp_get_attachment_metadata()` (`ImageController.php:329`) while its sibling
branches use `$this->get_cached_attachment_metadata()` (e.g. `:425`). Switch
to the cached helper for consistency (and one less uncached lookup per
array-size call).

## 5. Stale docblock claim

The `ImageController` file docblock's "Security and Validation" section claims
"Nonce validation for admin operations" (~`ImageController.php:150`). The
controller has no nonce handling (none is needed — it registers only filters).
Remove the line so the docs don't overstate the security surface.

## Acceptance criteria

- `composer check` green; no behavioural diffs on a configured install
  (item 3's edge aside, which only removes malformed output).
- Changelog: one grouped entry under Fixed/Changed is fine.
