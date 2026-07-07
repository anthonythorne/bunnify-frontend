# FIX-04 — AVIF uploads never rewrite; extension allow-list duplicated

- **Severity:** Low
- **Status:** Done (2026-07-06)
- **Found:** 2026-07-06 final review pass

## Problem

Two hard-coded extension allow-lists, both `[ gif, jpg, jpeg, png, webp, heic ]`:

- `src/php/Library/URLTransformer.php:430` (`validate_image_url()` — the gate
  every rewrite path passes through).
- `src/php/Library/ImageProcessor.php:32-39` (`$allowed_extensions` — used by
  `parse_dimensions_from_filename()`; also referenced by its own
  `validate_image_url()`, see FIX-06).

WordPress 6.5+ accepts `.avif` uploads by default, and Bunny serves/transforms
AVIF — but any AVIF attachment fails `validate_image_url()` and is never
rewritten to the CDN. The duplication also means the two lists can drift.

## Fix

1. Hoist one shared list — e.g. `URLTransformer::ALLOWED_EXTENSIONS` (public
   const) — including `avif`; have `ImageProcessor` reference it (or vice
   versa; one source of truth either way).
2. Add an existing-pattern escape hatch note: the
   `bunnify_any_extension_for_domain` filter (`URLTransformer.php:434`)
   already allows opting in other extensions; the const is the default set.
3. Keep `svg` **out** deliberately (origin-served UI assets; document that in
   a code comment so it isn't "fixed" later).

## Tests

- `validate_image_url()` accepts an `.avif` uploads URL and still rejects
  `.txt`/`.svg`.
- `parse_dimensions_from_filename( 'photo-300x200.avif' )` returns
  `[300, 200]`.

## Acceptance criteria

- Exactly one authoritative extension list in the codebase, containing `avif`.
- Full suite green; changelog entry under Added/Fixed.
