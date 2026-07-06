# FIX-05 — `transform_url_direct()` bypasses the transformer it just initialised

- **Severity:** Low (consistency: quality param, skip filters, kill-switch, scheme)
- **Status:** Open
- **Found:** 2026-07-06 final review pass

## Problem

`src/php/Controller/ContentController.php:382-415`. The direct path (content
images whose attachment ID could not be resolved, and the background-image
fallback) calls `$this->init_cdn()` — which constructs
`$this->url_transformer` — and then **hand-builds the URL instead of using
it**:

```php
if ( ! $this->init_cdn() ) {
    return false;
}
…
$cdn_hostname = get_option( 'bunnify_hostname' );
if ( ! empty( $cdn_hostname ) ) {
    return 'https://' . $cdn_hostname . $url_parts['path'];
}
```

Consequences of the hand-rolled build versus `transform_url()`:

- **No `quality=` param** — the opt-in Image Quality setting applies to every
  other path but silently not to direct-transformed images (host-swap only).
- **Skips `bunnify_skip_for_url` / `bunnify_pre_image_url` /
  `bunnify_post_image_url`** — the documented public seams don't fire here.
- **Skips the `BUNNIFY_DISABLE` check** in `transform_url()` (FIX-01 makes
  this moot via `is_enabled()`, but consistency still wins).
- Hard-codes `https://` instead of `cdn_url_scheme()` (harmless in practice,
  inconsistent in principle). Also re-reads `get_option('bunnify_hostname')`
  although `init_cdn()` already cached it on `$this->bunnify_hostname`.

## Fix

Delegate:

```php
private function transform_url_direct( string $image_url ): string|false {
    // (keep the existing local-dev exists-locally early return)
    if ( ! $this->init_cdn() ) {
        return false;
    }

    $cdn_url = $this->url_transformer->transform_url( $image_url );

    // transform_url() returns the input unchanged when it declines; treat
    // that as "no transform" so callers keep the original attribute.
    return ( $cdn_url === $image_url ) ? false : $cdn_url;
}
```

Note `transform_url()` also runs its own local-dev check (cheap — the
exists-locally result is cached per request), applies the uploads-path rules
including the `bunnify_allow_non_upload_url` opt-in, and appends the query
string (quality) via `build_cdn_url()`.

## Tests

Unit test (Brain Monkey): with a configured hostname and
`bunnify_default_quality = '80'`, a non-attachment uploads URL passed through
the content pipeline's direct path emits `?quality=80`; with
`bunnify_skip_for_url` returning true for that URL, the original is kept.

## Acceptance criteria

- Direct-transformed images honour quality, the skip/pre/post filters, and
  scheme selection exactly like every other path.
- With quality off and no filters engaged, output for a plain uploads URL is
  byte-identical to today (`https://host/path`).
- Full suite green; changelog entry under Fixed.
