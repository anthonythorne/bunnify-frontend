# FIX-02 — `-scaled` lookup retry builds a garbage URL and never matches

- **Severity:** Medium-low (silent feature failure + a wasted, cached DB query)
- **Status:** Open
- **Found:** 2026-07-06 final review pass

## Problem

`ImageProcessor::get_attachment_id_from_url()` has a fallback for URLs whose
original file was scaled by WordPress (uploads over the big-image threshold
store `photo-scaled.jpg`, so a lookup for the dimension-stripped `photo.jpg`
finds nothing). `src/php/Library/ImageProcessor.php:197-201`:

```php
if ( ! $attachment_id && ! strpos( $stripped_url, '-scaled.' ) ) {
    $scaled_url    = str_replace( '.', '-scaled.', $stripped_url );
    $attachment_id = self::attachment_url_to_postid( $scaled_url );
}
```

`str_replace( '.', '-scaled.', $url )` replaces **every dot in the URL**:

```
in:  https://example.com/wp-content/uploads/2026/06/photo.jpg
out: https://example-scaled.com/wp-content/uploads/2026/06/photo-scaled.jpg
```

The host becomes `example-scaled.com`, so `attachment_url_to_postid()` can
never match. Verified by executing the expression. Net effect: the intended
fallback **never works**; every trigger wastes one DB lookup on a garbage URL
(then caches the miss for ≥5 minutes). Content images derived from scaled
originals silently fall through to `transform_url_direct()` — they still
rewrite, but via host-swap without the attachment-ID metadata path (no
true-original resolution, no metadata-derived dimensions).

## Fix

Affix `-scaled` before the file extension only:

```php
$scaled_url = preg_replace( '#\.((?:jpe?g|png|gif|webp|heic|avif))$#i', '-scaled.$1', $stripped_url );
```

(or `substr`/`pathinfo` insertion — any approach that touches only the final
extension). Reuse the shared extension list if FIX-04 lands first. Also
tighten the guard on line 198: `false === strpos( … )` instead of the loose
`! strpos( … )` (works today only by accident of position 0 being impossible).

## Tests

Unit tests on `get_attachment_id_from_url()` with a stubbed
`attachment_url_to_postid` (Brain Monkey): assert the retry is called with
`…/photo-scaled.jpg` on the **same host**, and that a URL already containing
`-scaled.` does not retry.

## Acceptance criteria

- The constructed retry URL differs from the input only by the `-scaled`
  suffix before the extension.
- A dimension-suffixed URL of a scaled original resolves to its attachment ID
  (verifiable on a dev install: upload a >2560px JPEG, embed a sized variant,
  confirm the attachment-ID path is taken).
- Full suite green; no change for non-scaled lookups.
