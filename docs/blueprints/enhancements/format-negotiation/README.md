# Format negotiation: next-gen formats & tuned quality

- **Status:** Implemented (2026-07-03)

> **Update (2026-07-03): `format` is a code-only filter, not a settings toggle.**
> WebP/AVIF are best served by BunnyCDN's zone-level automatic optimization,
> which negotiates on the browser `Accept` header (only supporting browsers get
> the next-gen format). Emitting `format=` in the URL forces the codec for ALL
> browsers, so it was demoted from a settings dropdown to the deliberate,
> per-image `bunnify_format` filter (default off). `quality` remains a safe,
> Accept-independent setting. Both now apply to full-size/bare URLs too, not
> just sized variants.

- **Created:** 2026-07-03
- **Owner:** _unassigned_
- **Related:** [[cwv-image-delivery]] (the Core Web Vitals image-delivery story this feeds — smaller bytes, faster LCP), [[data-driven-settings]] (the settings schema these two new options should ultimately register through)

## Summary
The transform pipeline already emits `?width=…&height=…&c=1` for every rewritten upload, but it never asks the CDN to serve a **next-gen format** (WebP/AVIF) or a **tuned quality**. Those are the two highest-leverage image-weight wins the CDN can do for free, and the plumbing to carry them is *already present* — `build_query_string()` passes through arbitrary scalar transform args (`src/php/Library/URLTransformer.php:224-245`) and `is_cdn_url()` already lists `quality` and `format` as recognised CDN params (`:430`). What is missing is a first-class, opt-in way for a site owner to turn them on without writing a `bunnify_pre_args` filter by hand.

This blueprint adds two settings — a **default quality** and a **format policy** (`off` / `auto` / `webp` / `avif`) — each with a matching override filter (`bunnify_default_quality`, `bunnify_format`), and applies them inside `build_query_string()` **after** the existing width/height/crop mapping so an explicit caller value always wins and the off-by-default output stays byte-for-byte identical to today. Format negotiation itself (picking WebP vs AVIF vs origin per browser) stays where it belongs: at the CDN edge, not in WordPress.

## Motivation / Problem
1. **The cheapest CWV win is left on the table.** The Optimizer can transcode a JPEG/PNG to WebP or AVIF and re-encode at a chosen quality on the fly, typically 25–50% smaller for the same perceived quality. Today every URL the plugin writes carries only geometry (`width`/`height`/`c`), so images are served in their origin format at the zone's default quality. The only way to change that is a hand-written filter.

2. **The escape hatch exists but is undiscoverable.** `docs/HOOKS.md:258-265` literally documents `add_filter( 'bunnify_pre_args', … $args['quality'] = 85 … )` as the way to force quality. That proves the pass-through path works end to end, but a site owner should not have to write PHP to get a next-gen format — this is table stakes for an image CDN plugin and belongs in the settings screen.

3. **No guardrails on the format decision.** Because there is no first-class setting, there is also no single place that (a) whitelists a safe policy value, (b) documents the caching / `Vary: Accept` implication of per-browser negotiation, and (c) keeps the emitted param names in sync with the `is_cdn_url()` detection list so a format-tagged URL is never re-processed.

## Goals
- Add two opt-in settings — `bunnify_default_quality` (integer) and `bunnify_format` (enum: `off` / `auto` / `webp` / `avif`) — registered, sanitised, and rendered alongside the existing hostname field.
- Add two override filters, `bunnify_default_quality` and `bunnify_format`, so a theme/plugin can vary quality or format per template (hero vs thumbnail) without touching stored options.
- Apply both inside `build_query_string()` (`src/php/Library/URLTransformer.php:191-246`) as the single injection point, so both call sites (`build_cdn_url()` `:176` and `build_cdn_url_from_attachment()` `:606`) get them uniformly.
- **Byte-for-byte parity when off.** With both settings at their default (policy `off`, quality omitted), the generated query string is character-identical to the current output — provable by a golden-string test.
- Preserve today's precedence: an explicit `quality`/`format` supplied by a caller (directly or via `bunnify_pre_args`) always overrides the settings-derived default.
- Keep the emitted param names and the `is_cdn_url()` `$cdn_params` list (`:430`) as a single source of truth so format/quality params never *defeat* already-processed detection.

## Non-goals
- **No client-side format negotiation in WordPress.** WordPress emits one stable URL; the CDN decides WebP vs AVIF vs origin per request. We do not sniff the `Accept` header in PHP, and we do not fan out `<picture>`/`<source>` markup.
- **No zone provisioning.** Enabling the Optimizer / automatic WebP at the CDN zone level is an account setting outside this plugin; the blueprint assumes the zone can honour the params and documents the dependency.
- **No change to the width/height/crop mapping or the geometry-crop passthrough** (`:203-211`, `:224-243`). Those are load-bearing and stay exactly as they are.
- **No new serialized option blob** — two discrete options, consistent with the existing eleven.
- Not the settings-schema refactor itself — these two options are hand-registered today and fold into [[data-driven-settings]]'s schema when it lands.

## Current state
`build_query_string()` (`src/php/Library/URLTransformer.php:191-246`) maps the three core keys — `width`→`width`, `height`→`height`, truthy `crop`→`c=1` (`:203-211`) — then runs a pass-through loop (`:224-243`) that carries *any other* scalar arg straight into the query, skipping the already-mapped `width`/`height` and the boolean-ish `crop` (whose geometry form `crop=w,h[,x,y]` is deliberately still passed through, `:231-233`). It finishes with `http_build_query( $query_parts )` (`:245`), which serialises in insertion order. So a caller who already puts `quality`/`format` in `$args` gets them emitted today — there is simply no setting that does it for them.

`is_cdn_url()` (`:403-440`) treats a URL as already-CDN if the host matches the configured hostname **or** the query contains any of `[ 'width', 'height', 'quality', 'format', 'crop' ]` (`:430`). `quality` and `format` are *already* in that list, so a URL we tag with them is correctly recognised as processed and skipped by `validate_image_url()` (`:379`) — this is why keeping emitted names in lockstep with that list matters.

On the settings side, `SettingsController::init_settings()` (`src/php/Controller/SettingsController.php:67-205`) hand-registers each option with a `sanitize_callback` (e.g. `sanitize_hostname()` `:223-243`, `sanitize_refreshes()` clamps to `[1,100]` `:251-253`) and renders a bespoke `*_field_callback`. The two new options follow that established shape until [[data-driven-settings]] converts them to schema entries. The runtime master switch is `is_enabled()` (`:604-612`); the new params only ever ride URLs the plugin was already going to rewrite, so they are gated by it for free.

## Proposed approach

### 1. Two new settings + filters
Register alongside the existing options (`SettingsController.php:67-101`), each with a sanitiser matching the current `sanitize_refreshes()`/`sanitize_hostname()` style:

```php
register_setting( 'bunnify_frontend_options', 'bunnify_default_quality', array(
    'type'              => 'integer',
    'sanitize_callback' => [ $this, 'sanitize_quality' ],
    'default'           => 0, // 0 = omit; let the zone default stand (byte-for-byte parity).
) );
register_setting( 'bunnify_frontend_options', 'bunnify_format', array(
    'type'              => 'string',
    'sanitize_callback' => [ $this, 'sanitize_format' ],
    'default'           => 'off',
) );

/** 0 (omit) or an integer quality in [1,100]. */
public function sanitize_quality( $value ): int {
    $value = (int) $value;
    return 0 === $value ? 0 : max( 1, min( 100, $value ) );
}

/** Whitelist the policy; anything unexpected falls back to the safe 'off'. */
public function sanitize_format( $value ): string {
    $value = is_string( $value ) ? strtolower( trim( $value ) ) : '';
    return in_array( $value, [ 'off', 'auto', 'webp', 'avif' ], true ) ? $value : 'off';
}
```

The quality field's help text suggests **85** as a sensible starting value (matching the `bunnify_pre_args` example in `docs/HOOKS.md:263`), but the stored *default* is `0`/omit so existing installs change nothing until an admin opts in. The format field is a `<select>` of the four policy values.

### 2. Apply defaults in `build_query_string()` — after mapping, before serialising
Insert immediately before `return http_build_query( $query_parts );` (`:245`). The `isset()` guards make an explicit caller value win, and both-at-default adds nothing:

```php
// --- Format-negotiation defaults (opt-in) --------------------------------
// Runs AFTER the core mapping and pass-through, so an explicit caller value
// (direct $args or via bunnify_pre_args) is never overwritten. With quality
// at 0/omit and format 'off', nothing is appended and the output is
// byte-for-byte identical to the pre-feature string.

// Quality: 0 (or unset option) => omit and let the zone's default stand.
if ( ! isset( $query_parts['quality'] ) ) {
    $quality = (int) apply_filters(
        'bunnify_default_quality',
        (int) get_option( 'bunnify_default_quality', 0 )
    );
    if ( $quality > 0 ) {
        $query_parts['quality'] = min( 100, max( 1, $quality ) );
    }
}

// Format policy: off | auto | webp | avif.
if ( ! isset( $query_parts['format'] ) ) {
    $format = (string) apply_filters(
        'bunnify_format',
        (string) get_option( 'bunnify_format', 'off' )
    );

    // 'auto' delegates to the CDN's per-request Accept negotiation, so emit
    // NOTHING and let the zone pick WebP/AVIF/origin (see caching note). Only
    // 'webp'/'avif' bake a concrete format into the URL.
    if ( in_array( $format, [ 'webp', 'avif' ], true ) ) {
        $query_parts['format'] = $format;
    }
}
```

> **⚠️ Verify the exact Optimizer param names at build time.** `quality`, `format`, `format=webp`, `format=avif` are the *assumed* names and must be confirmed against the current CDN Optimizer documentation before shipping — some Optimizers expose an explicit `format=auto` param (in which case `auto` would emit it rather than nothing), some gate WebP/AVIF purely at the zone level with no URL param at all, and the quality param may differ. Whatever names are confirmed, they must be used in **both** the emit sites above **and** the `is_cdn_url()` `$cdn_params` list (`:430`) — ideally hoisted into one shared `private const CDN_PARAMS` on `URLTransformer` so the emitter and the detector can never drift.

### 3. `format=auto`, the `Accept` header, and caching
`auto` is the recommended on-value because it lets the CDN serve the best format each browser can decode. The important property: **WordPress emits one URL**; the edge returns WebP to a browser that sends `Accept: image/webp`, AVIF to one that accepts AVIF, and the origin format otherwise. For that to be correct the CDN must respond `Vary: Accept` on those images so an intermediary cache never hands a WebP to a client that cannot render it — this is the CDN's job (its automatic image optimization sets it), **not** WordPress's, and it is worth stating plainly in the settings help so nobody goes looking for `Vary` handling in PHP.

The trade-off with an explicit `webp`/`avif` policy: the format is baked into the URL, so each format is a distinct cache key and no `Vary` is needed — but you then risk serving AVIF to a browser that cannot decode it. That makes explicit formats an advanced, "I control my audience" choice; `auto` is the safe default-if-on. Full-page HTML caching is unaffected either way: the image URL is stable, so no cache-buster churn.

### 4. Detection stays consistent
Because the emitted names live in the same `$cdn_params` list `is_cdn_url()` checks (`:430`), a URL we tag with `quality`/`format` is still recognised as already-processed and skipped by `validate_image_url()` (`:379`) on the content-scan path — quality/format params **strengthen** detection rather than defeat it. The single-const refactor in step 2 makes this guarantee mechanical instead of a thing to remember.

## Migration & backwards compatibility
- **No DB migration.** Two brand-new options with `off`/`0` defaults; every existing install renders and behaves exactly as before until an admin changes them.
- **Byte-for-byte output when off.** The `isset()` guards plus the `0`/`off` defaults mean `build_query_string()` returns the identical string for identical `$args` — locked down by a golden test (Testing strategy).
- **Caller precedence preserved.** Anyone already forcing `quality`/`format` via `$args` or `bunnify_pre_args` (`docs/HOOKS.md:258-265`) keeps exactly that behaviour; the settings only fill the gap when the key is absent.
- **New filters are additive.** `bunnify_default_quality` and `bunnify_format` join the documented hook surface (`docs/HOOKS.md`); no existing filter changes signature.
- **Uninstall.** Add the two option names to `uninstall.php`'s delete list so a clean uninstall removes them (mirrors the existing eleven).
- **Consumer impact: none by default.** A downstream site sees no change until it opts in; a consuming site wires only `bunnify_allow_non_upload_url` / `bunnify_skip_image` and is untouched.

## Risks & mitigations
- **Wrong Optimizer param names** (vendor-neutral / wordpress.org-bound). Emitting a param the zone ignores is a silent no-op; emitting a *wrong* one could produce a broken transform. Mitigation: the build-time verification note in step 2, and keep the whole feature behind the `off` default until confirmed on a real zone.
- **Emitter/detector drift** — if `format`'s emitted name and the `$cdn_params` entry diverge, a format-tagged URL stops being recognised as processed and could be double-handled. Mitigation: one shared `CDN_PARAMS` constant consumed by both, asserted in a test.
- **AVIF served to a non-supporting browser** under an explicit `avif` policy. Mitigation: document `auto` as the recommended value; keep `webp`/`avif` as advanced options with a help-text warning; `auto` relies on the edge's `Accept` negotiation which never mis-serves.
- **Quality set absurdly low** degrades every image site-wide. Mitigation: `sanitize_quality()` clamps to `[1,100]`; help text suggests 85; it is a single reversible setting.
- **Over-aggressive default.** Shipping a non-`off` default would change output for every install on upgrade. Mitigation: default is `off`/`0`; opt-in only.
- **`Vary: Accept` misconfigured at the edge** could cache a WebP for a JPEG-only client. Mitigation: this is CDN-side and out of the plugin's control, but the settings help states the requirement so operators enable it with automatic optimization.

## Testing strategy
- **Golden byte-for-byte parity (the critical one).** With both options at default, assert `build_query_string()` output for representative `$args` (`['width'=>300,'height'=>200,'crop'=>true]`, geometry-crop `['crop'=>'300,200,0,0']`, string args) is **character-identical** to the current output. This is the guard that "off changes nothing".
- **Quality emission.** Option `85` + no caller quality ⇒ `quality=85` appended; option `0` ⇒ absent; caller `$args['quality']=70` ⇒ `70` wins regardless of the option (isset guard); `sanitize_quality()` clamps `0`→`0`, `500`→`100`, `-5`→`1`.
- **Format policy matrix.** `off`⇒no `format`; `webp`/`avif`⇒`format=webp`/`avif`; `auto`⇒no `format` param emitted (delegated to the edge); caller `$args['format']` always wins; `sanitize_format()` maps any unknown string ⇒ `off`.
- **Filter override.** `bunnify_default_quality`/`bunnify_format` filters override the stored option per request; a filter returning an out-of-range quality is still clamped.
- **Detection consistency.** `is_cdn_url()` returns true for a URL carrying only `quality=` and for one carrying only `format=webp` (guards the `$cdn_params`/emitter shared-constant contract).
- **Settings.** `sanitize_quality`/`sanitize_format` unit tests (Brain Monkey), and a render smoke test asserting the quality input and the format `<select>` emit escaped values and the four options.
- **PHPStan level 5 + phpcs/WPCS** stay green on both files.

## Rollout plan
1. **Confirm the real Optimizer param names** against current CDN docs on a live zone (quality param, format param, and whether `auto` is a URL param or a zone-level behaviour). This gates everything else.
2. **Hoist `CDN_PARAMS`** into a shared `URLTransformer` constant and repoint `is_cdn_url()` (`:430`) at it — pure refactor, no behaviour change, land with its own test.
3. **Add the two settings + sanitisers + uninstall entries**, defaulting to `off`/`0`; ship the render and sanitise tests. No output changes yet (nothing reads them).
4. **Wire the defaults into `build_query_string()`** behind the `isset()` guards; land the golden parity test and the quality/format/precedence matrix. This is the step that can change output, and only for opt-in installs.
5. **Document** `bunnify_default_quality`, `bunnify_format`, the `auto` + `Vary: Accept` note, and the "CDN, not WordPress, negotiates" framing in `docs/HOOKS.md` and the settings help; add a changelog line.
6. **Fold the two options into the schema** once [[data-driven-settings]] lands (add an `enum` sanitise strategy for the policy); optional/last.

Each step is independently shippable and reversible; steps 1–3 are inert until step 4 flips the wiring on.

## Effort estimate
**S.** The carrier plumbing (`build_query_string()` pass-through and the `is_cdn_url()` param list) already exists, so the change is two settings, a ~20-line guarded insertion, and tests. What keeps it a *solid* S rather than trivial is the build-time verification of the real Optimizer param names, the shared-constant refactor that keeps the emitter and detector from drifting, and the byte-for-byte parity test that has to prove off-by-default output is untouched.
