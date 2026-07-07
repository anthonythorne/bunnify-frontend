# FIX-01 — Make `BUNNIFY_DISABLE` the kill-switch the readme promises

- **Severity:** Medium (documented behaviour ≠ actual behaviour)
- **Status:** Done (2026-07-06)
- **Found:** 2026-07-06 final review pass

## Problem

`readme.txt` (FAQ, lines 55–58) tells operators:

> *How do I disable the CDN temporarily?* — Clear the hostname under
> **Media → BunnyCDN**, or define `BUNNIFY_DISABLE` as `true` in `wp-config.php`.

But the constant is only checked in **two** places:

- `src/php/Controller/CDNController.php:58` — the `bunnify_url` filter path.
- `src/php/Library/URLTransformer.php:73` — `transform_url()` (direct
  transforms: non-attachment content images, galleries, widgets, header).

The **entire attachment pipeline ignores it**: `image_downsize`,
`wp_get_attachment_image_src`, `wp_get_attachment_image`, srcset generation,
the media-picker payload (`wp_prepare_attachment_for_js`), the bare-URL
`wp_get_attachment_url` filter, and the content attachment-ID path all route
through `URLTransformer::get_cdn_url_by_id()` → `init_static_cdn()`, which
checks only `SettingsController::is_enabled()` + hostname. With
`BUNNIFY_DISABLE` defined true, **most images keep rewriting to the CDN** —
the documented wp-config kill-switch is largely a no-op.

## Root cause

The constant predates the real master switch (`is_enabled()`); it was wired
into the two oldest paths and never into the central gate.

## Fix

Honour the constant inside the one gate everything already consults:

```php
// SettingsController::is_enabled() — before reading the option.
if ( defined( 'BUNNIFY_DISABLE' ) && BUNNIFY_DISABLE ) {
    return false;
}
```

That covers `init_static_cdn()` (all static/attachment paths),
`CdnClientTrait::init_cdn()` (content/gallery/widget bootstrap), and
`WPResourceHintsController` (no preconnect while disabled) in one place.

Optionally leave the two existing scattered checks as cheap early-outs, or
remove them as now-redundant (either is fine; removing keeps one source of
truth). Update the `is_enabled()` docblock to document the constant as the
highest-priority override. Note in the docblock/readme that the constant
disables **rewriting**, not the settings screen.

## Tests

In `tests/Unit/SettingsControllerTest.php`: the constant cannot be defined
per-test (constants are process-global), so run the constant case in a
separate PHPUnit process (`@runInSeparateProcess` + `define()` at test start)
asserting `is_enabled()` returns false even with `bunnify_enabled = '1'` and a
hostname stored. Keep the existing option-semantics tests untouched (they must
still pass without the constant).

## Acceptance criteria

- With `BUNNIFY_DISABLE` true: `image_downsize`, bare `wp_get_attachment_url`,
  content rewriting, and resource hints all leave origin output untouched
  (spot-check via wp-cli `eval` on a dev install).
- Without the constant: behaviour byte-identical to today; full suite green.
- `readme.txt` FAQ stays accurate (and while editing it, refresh the
  local-development FAQ: the mode now auto-enables on `local`/`development`
  environments via `wp_get_environment_type()` — the checkbox is a force-on
  for other environments).
