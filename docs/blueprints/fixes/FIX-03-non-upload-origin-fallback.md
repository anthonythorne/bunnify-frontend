# FIX-03 — `get_cdn_url_by_id()` origin fallback collapses sizes for non-uploads attachments

- **Severity:** Low (only bites offloaded media / customised upload URLs)
- **Status:** Done (2026-07-06)
- **Found:** 2026-07-06 final review pass

## Problem

`src/php/Library/URLTransformer.php:560`:

```php
return self::$static_instance->build_cdn_url_from_attachment( $true_original_url, $args, $scheme ) ?? $original_url;
```

`build_cdn_url_from_attachment()` returns `null` when the attachment URL's
path is **not** under the local uploads path (`:645`) — e.g. media offloaded
to external storage (another plugin filtered its URL to a different host/path
during the origin lookup; only bunnify's own filter is suspended, third-party
filters still run), legacy `ms_files` multisite paths, or a customised
`upload_url_path`. The `?? $original_url` fallback then returns the
**full-size origin URL**, and `filter_image_downsize()` short-circuits core
with `[ $full_size_url, w, h, false ]` — every intermediate size collapses to
the full-size file. This is exactly the defect class fixed for the
disabled/unconfigured states (commit `d356251`), surviving on this one path.

## Fix

Return `null` instead of the origin URL:

```php
return self::$static_instance->build_cdn_url_from_attachment( $true_original_url, $args, $scheme );
```

Every caller already treats an empty return as "leave the input untouched"
(audited when `d356251` landed: `empty()` bails, `if ( $cdn_url )` guards, or
`?: $url` fallbacks) — so core's own resolution proceeds and non-uploads
attachments render their real intermediate files.

## Tests

Unit test for `get_cdn_url_by_id()` (Brain Monkey, static-state reset via
reflection as in the existing disabled/unconfigured tests): stub
`wp_get_attachment_url` to an external-host URL whose path is not under the
stubbed `wp_upload_dir()['baseurl']` path, enabled + hostname configured →
assert `null` (previously the origin URL).

## Acceptance criteria

- Attachments with non-uploads URLs are left entirely to core (no rewrite, no
  size collapse); uploads-path attachments behave byte-identically to today.
- Full suite green; changelog entry under Fixed.
