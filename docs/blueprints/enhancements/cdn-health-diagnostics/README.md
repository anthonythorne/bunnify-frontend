# CDN health diagnostics & on-demand self-test

- **Status:** Proposed
- **Created:** 2026-07-03
- **Owner:** _unassigned_
- **Related:** [[url-surface-coverage]] (the catalogue of HTML surfaces the rewriter does and does not cover, which the coverage report cross-references), [[observability-diagnostics-ui]] (how diagnostic results are visualised in the admin), [[data-driven-settings]] (the `is_enabled()` master-switch / hostname resolution this self-test probes)

## Summary
The plugin can be misconfigured — a wrong or unresolvable `bunnify_hostname`, a pull-zone that 404s, a hostname that resolves but returns HTML error pages instead of image bytes — and today the only feedback is broken images on the live site. This blueprint adds an admin **Diagnostics** panel with an on-demand **self-test** scoped strictly to CDN image delivery: (1) a **config self-test** that checks the hostname is set and DNS-resolvable and that a real CDN image URL returns `200` with image bytes and the expected cache/Bunny headers; (2) a **rewrite-coverage report** that fetches one sampled front-end page server-side and counts `<img>` sources pointing at the CDN vs the origin vs skipped, flagging surfaces the rewriter never touches; and (3) an **optional, clearly-bounded "image issues" check** — the DebugBear-like slice, images only — that reports images returning non-`200`, missing `width`/`height` (a CLS risk), or left un-rewritten. All three run only when an admin clicks a button (capability + nonce gated), use `wp_safe_remote_*` with short timeouts, and write timestamped results to a reviewable store.

**Explicit boundary.** This is *not* a page-speed / Core Web Vitals monitor. Full CWV, PSI/CrUX field data, waterfalls, timing, trend graphs, and headless-browser console-error capture are out of scope and belong to the separate PagePulse plugin. Bunnify's diagnostics stay inside one question: *is the CDN configured correctly and delivering the images this plugin rewrote?*

## Motivation / Problem
1. **There is no config feedback loop.** `URLTransformer::init_static_cdn()` gates on `is_enabled()` and a non-empty `bunnify_hostname` (`src/php/Library/URLTransformer.php:467`, `:475-480`) and the constructor does a `FILTER_VALIDATE_URL` shape check (`:41-48`), but nothing ever confirms the hostname *resolves*, that the pull zone is reachable, or that it returns image bytes. A typo, an expired zone, or a zone serving an HTML 404 all fail silently — the admin sees broken thumbnails and has no way to tell "hostname wrong" from "zone down" from "not rewritten at all".

2. **The admin page shows logs, not health.** `admin_page()` (`src/php/Controller/SettingsController.php:479-552`) renders exactly one diagnostic surface — a passive "Debug Log" card (`:516-549`) that reports the log file's size and line count. There is no "test my configuration now" action; the earlier sibling blueprint even refers to a "Test Configuration" card that does not exist in the current file. Debug logging (`DebugTrait::debug_log()`, `src/php/Base/Traits/DebugTrait.php:44-64`) only records what happened *during a real front-end request* for the categories the admin pre-enabled; it cannot answer "is the CDN healthy right now" on demand.

3. **Coverage is invisible.** The content rewriter walks `<img>` `src`/`srcset` with `WP_HTML_Tag_Processor` (`src/php/Controller/ContentController.php:181-186`) and the `is_local_upload_url()` gate only rewrites upload URLs (`URLTransformer.php:132-159`). Inline `style="background-image:url(...)"`, `<source>` in `<picture>`, and bare URLs in text are never rewritten by design — but the admin has no way to *see* how much of a given page is served from the CDN, nor which un-covered surfaces are leaking image traffic to the origin. That is real, actionable information the plugin already has everything it needs to compute.

## Goals
- Add a **Diagnostics** admin surface (a card or tab on the existing BunnyCDN settings page) with a manual **Run self-test** button, gated behind `manage_options` and a nonce.
- **Config self-test:** report pass/fail for (a) `is_enabled()` + hostname present, (b) hostname DNS-resolvable, (c) a known CDN image URL returns `200`, (d) `content-type` is `image/*` and the body's leading bytes are a real image, (e) the expected cache / CDN headers are present — surfacing the **actual** response headers and status, not just a boolean.
- **Rewrite-coverage report:** fetch one admin-chosen (default: `home_url()`) front-end URL server-side and return counts of `<img>` sources that are CDN-host / origin-upload / skipped-or-external, plus a list of *un-covered surfaces detected* (inline background-image, `<picture><source>`, bare URLs), cross-referenced to [[url-surface-coverage]].
- **Optional image-issues check (bounded):** for a capped sample of images on the fetched page, report those returning non-`200`, missing `width`/`height`, or not rewritten — images only, on demand only.
- **Log every run** to a reviewable, timestamped store (a dedicated diagnostics option/transient plus an optional summary line into the existing debug log under a new `diagnostics` category).
- Respect wordpress.org rules: `wp_safe_remote_head()`/`wp_safe_remote_get()` only, explicit short timeouts, capped work, **zero always-on / background remote requests** — nothing fires without an admin click.

## Non-goals
- **No page-speed / CWV monitoring.** No PSI, no CrUX, no field data, no timing/waterfall, no LCP/CLS/INP metrics beyond the static "missing width/height" heuristic, no trend graphs, no scheduled runs. That is PagePulse; do not reinvent it here.
- **No headless browser and no console-error capture.** Rendered-DOM inspection, JS-injected images, and console/network error capture require a browser engine and are an explicit non-goal — defer to PagePulse.
- **No writes to the rewrite path.** Diagnostics is strictly read-only observation; it never changes how URLs are rewritten and adds no runtime cost to front-end requests.
- **No new public rewrite filters.** The `bunnify_*` filter API in `docs/HOOKS.md` is untouched. (A read-only `bunnify_diagnostics_*` filter to tune the sample URL / caps is optional and additive.)
- **No detailed UI framework decision.** *How* results render (badges, header table, coverage bar, React vs classic) is deferred to [[observability-diagnostics-ui]]; this doc specifies the data and the safe execution, not the pixels.
- **No non-image asset auditing.** CSS/JS/font delivery is out of scope; this is a CDN *image*-delivery diagnostic.

## Current state
The settings page is a plain Settings API screen. `set_up()` hooks `admin_menu` → `add_admin_menu()` and `admin_init` → `init_settings()` (`src/php/Controller/SettingsController.php:45-48`); the page is a `manage_options` submenu under `upload.php` (`:53-62`) and `admin_page()` re-checks `current_user_can('manage_options')` at the top (`:480-482`). The only diagnostic UI is the read-only "Debug Log" card (`:516-549`), which reports log size/line count from `get_debug_log_file()` (`:559-562`); there is no action button and the page processes no POST of its own beyond the core `options.php` save.

The master switch and hostname accessors the self-test must probe already exist: `is_enabled()` (`:604-612`), and CDN readiness is resolved in `URLTransformer::init_static_cdn()` (`:461-484`) which fails closed when disabled (`:467`) or hostname-empty (`:475-480`). `is_cdn_url()` already knows how to recognise a CDN host by comparing `wp_parse_url()['host']` to `get_option('bunnify_hostname')` (`URLTransformer.php:403-440`, hostname read at `:416`), and `validate_image_url()` carries the allowed-extension list (`:366-395`, list at `:385`) — both are directly reusable for classifying `<img>` sources in the coverage report. `WPResourceHintsController::update_resource_hints()` already builds the canonical `https://$bunnify_hostname` origin and compares it against the site host (`src/php/Controller/WPResourceHintsController.php:57-66`), the exact comparison the coverage classifier needs.

Logging infrastructure is in place and reusable. `DebugTrait::debug_log()` writes timestamped `[time][category][context] message` lines with `file_put_contents(..., FILE_APPEND | LOCK_EX)` (`DebugTrait.php:44-64`, format at `:54-57`); `get_debug_log_file()` creates a hardened `uploads/bunnify-logs/` dir with a `Require all denied` `.htaccess` and `index.php` (`:103-124`); `trim_log_file_by_refreshes()` caps the log by line count (`:147-167`). Categories are gated by `SettingsController::is_debug_enabled_for_category()` (`:398-407`) and enumerated in `get_enabled_debug_categories()` (`:414-432`) — a `diagnostics` category slots straight into that list.

What is missing: any code that issues an outbound request to the CDN, any parse of a rendered front-end page, and any structured (non-line-oriented) results store.

## Proposed approach
Add a **`DiagnosticsController`** (in `src/php/Controller/`, inside phpcs coverage) that owns the admin surface, the `admin-post` handler, the three probes, and the results store. It reuses `DebugTrait` for logging and the existing `URLTransformer` accessors for classification. All three probes are pure functions of "fetch a URL, inspect the response" and share one hardened request helper.

### Safe request helper (shared)
Every outbound call goes through one method so the wp.org guarantees are enforced in a single place: `wp_safe_remote_*` (which honours `WP_Http`'s SSRF protections and blocks internal hosts), an explicit short timeout, `redirection` capped, and no retries.

```php
// Illustrative only.
private function probe_head( string $url ): array {
    $res = wp_safe_remote_head( $url, [
        'timeout'     => 5,
        'redirection' => 2,
        'sslverify'   => true,
        'user-agent'  => 'bunnify-frontend/diagnostics',
    ] );
    // HEAD is not always honoured by CDNs; fall back to a tiny ranged GET.
    if ( is_wp_error( $res ) || 405 === (int) wp_remote_retrieve_response_code( $res ) ) {
        $res = wp_safe_remote_get( $url, [
            'timeout'     => 5,
            'redirection' => 2,
            'headers'     => [ 'Range' => 'bytes=0-2047' ], // enough for magic-byte sniff
        ] );
    }
    return [
        'code'    => is_wp_error( $res ) ? 0 : (int) wp_remote_retrieve_response_code( $res ),
        'headers' => is_wp_error( $res ) ? [] : wp_remote_retrieve_headers( $res )->getAll(),
        'body'    => is_wp_error( $res ) ? '' : (string) wp_remote_retrieve_body( $res ),
        'error'   => is_wp_error( $res ) ? $res->get_error_message() : '',
    ];
}
```

### (1) Config self-test
A sequence of checks that each yield `pass` / `fail` / `warn` plus the evidence that produced it. The CDN URL under test is derived from a *real* attachment via the existing accessor so the test exercises the true rewrite path, not a hand-built string:

```php
private function run_config_selftest(): array {
    $checks = [];

    // (a) master switch + hostname present.
    $hostname = (string) get_option( 'bunnify_hostname', '' );
    $enabled  = SettingsController::is_enabled();
    $checks['config'] = [
        'status'  => ( $enabled && '' !== $hostname ) ? 'pass' : 'fail',
        'enabled' => $enabled,
        'hostname'=> $hostname,
    ];
    if ( 'fail' === $checks['config']['status'] ) {
        return $this->finalise( $checks ); // nothing else is testable.
    }

    // (b) DNS resolves (local resolver call, not an HTTP request).
    $resolved = gethostbyname( $hostname ); // returns input unchanged on failure.
    $checks['dns'] = [
        'status' => ( $resolved !== $hostname ) ? 'pass' : 'fail',
        'ip'     => ( $resolved !== $hostname ) ? $resolved : null,
    ];

    // (c)-(e) fetch a known CDN image URL and inspect it.
    $sample_id  = $this->pick_sample_attachment_id();      // newest image attachment.
    $cdn_url    = $sample_id ? URLTransformer::get_cdn_url_by_id( $sample_id ) : null;
    if ( $cdn_url ) {
        $r = $this->probe_head( $cdn_url );
        $ct = strtolower( $r['headers']['content-type'] ?? '' );
        $checks['http'] = [
            'status'  => ( 200 === $r['code'] ) ? 'pass' : 'fail',
            'code'    => $r['code'],
            'url'     => $cdn_url,
        ];
        $checks['content_type'] = [
            'status' => ( 0 === strpos( $ct, 'image/' ) ) ? 'pass' : 'fail',
            'value'  => $ct,
        ];
        // Bytes actually look like an image (magic-byte / getimagesizefromstring).
        $checks['image_bytes'] = [
            'status' => $this->looks_like_image( $r['body'] ) ? 'pass' : 'warn',
        ];
        // Expected cache / CDN headers — surfaced, tolerant (warn, not fail, if absent).
        $checks['cdn_headers'] = $this->assess_cdn_headers( $r['headers'] );
    }

    return $this->finalise( $checks );
}
```

`assess_cdn_headers()` looks for the caching signals a correctly-fronted Bunny response carries — a `cache-control`, a `cdn-cache` / `cdn-cache-control` / `cdn-status` style header, and a Bunny `server`/`cdn-*` marker — and returns the **actual header values** alongside the verdict. It is deliberately *tolerant*: a missing vendor header is a `warn` ("served, but doesn't look CDN-cached"), not a hard `fail`, because header names are a moving target and the plugin must not start flagging healthy sites when the CDN renames a header. The whole panel surfaces the raw headers so an admin (or support) can eyeball them.

`looks_like_image()` sniffs the leading bytes (JPEG `FF D8 FF`, PNG `89 50 4E 47`, GIF `47 49 46 38`, WebP `RIFF`…`WEBP`) or runs `getimagesizefromstring()` on the ranged body — catching the classic "hostname resolves and returns 200, but it's an HTML holding page" failure that a status-code check alone misses.

### (2) Rewrite-coverage report
Fetch one front-end URL server-side, then classify every `<img>` with the same tag processor the rewriter uses and the same host comparison `is_cdn_url()` already implements:

```php
private function run_coverage( string $url ): array {
    $r = $this->probe_get( $url ); // full GET, timeout 8, capped.
    if ( 200 !== $r['code'] ) {
        return [ 'status' => 'fail', 'code' => $r['code'] ];
    }

    $site_host = wp_parse_url( home_url(), PHP_URL_HOST );
    $counts    = [ 'cdn' => 0, 'origin' => 0, 'external' => 0, 'total' => 0 ];

    $p = new \WP_HTML_Tag_Processor( $r['body'] );
    while ( $p->next_tag( 'img' ) ) {
        $src = (string) $p->get_attribute( 'src' );
        if ( '' === $src ) { continue; }
        $counts['total']++;
        $host = (string) wp_parse_url( $src, PHP_URL_HOST );
        if ( URLTransformer::is_cdn_url( $src ) )      { $counts['cdn']++; }
        elseif ( $host === $site_host )               { $counts['origin']++; }
        else                                          { $counts['external']++; }
    }

    // Detect surfaces the rewriter never touches (see [[url-surface-coverage]]).
    $uncovered = [
        'inline_background_image' => preg_match( '/style=("|\')[^"\']*background(-image)?\s*:\s*url\(/i', $r['body'] ) ? 'present' : 'none',
        'picture_source'          => ( false !== stripos( $r['body'], '<source' ) ) ? 'present' : 'none',
        // bare-URL-in-text detection is best-effort and noted as such.
    ];

    return [
        'status'      => 'pass',
        'counts'      => $counts,
        'coverage_pct'=> $counts['total'] ? round( 100 * $counts['cdn'] / $counts['total'] ) : null,
        'uncovered'   => $uncovered,
    ];
}
```

The output is intentionally a *count and a flag*, not a fix. "12 of 15 `<img>` served from CDN; 3 from origin; inline background-image detected (not rewritten)" tells the admin exactly where origin traffic is leaking and links to [[url-surface-coverage]] for the why. Classification reuses `is_cdn_url()` (`URLTransformer.php:403-440`) so it can never drift from the runtime definition of "is a CDN URL".

### (3) Optional image-issues check (the bounded DebugBear-like slice)
Reusing the page already fetched in (2), take a **capped** sample of image URLs (e.g. the first 50) and for each do one ranged `probe_head`, plus read `width`/`height` off the tag while iterating:

```php
// While walking <img> in run_coverage(), collect:
$issues[] = array_filter( [
    'url'         => $src,
    'not_rewritten' => ( $host === $site_host && URLTransformer::validate_image_url( $src ) ) ? true : null,
    'missing_dimensions' => ( null === $p->get_attribute( 'width' ) || null === $p->get_attribute( 'height' ) ) ? true : null,
    // status filled by a capped, deduped batch of probe_head() calls afterwards.
] );
```

Only three signals, all image-and-delivery specific: **non-200** (broken/misconfigured CDN object), **missing width/height** (a layout-shift risk this image-plugin can legitimately flag), and **not rewritten** (an upload image still on the origin). No timing, no rendering, no console — those are PagePulse. This whole check is opt-in (its own sub-button or a checkbox on the run form) because it issues up to N extra HEADs; the config self-test (1) issues exactly one.

### Results store & logging
Persist a structured record per run — timestamp, which probes ran, and their verdicts — so results are reviewable after the fact:

```php
private function finalise( array $checks ): array {
    $record = [ 'ts' => time(), 'checks' => $checks ];

    // Rolling store of the last few runs (bounded; autoload off).
    $history = get_option( 'bunnify_diagnostics_history', [] );
    array_unshift( $history, $record );
    update_option( 'bunnify_diagnostics_history', array_slice( $history, 0, 10 ), false );

    // Human-readable trail into the existing debug log (new 'diagnostics' category).
    $this->debug_log( wp_json_encode( $checks ), 'run_config_selftest', 'diagnostics' );

    return $record;
}
```

Two-track on purpose: the **option** (`bunnify_diagnostics_history`, autoload `false`, capped at ~10 runs) holds the structured last-run data the panel renders and is trivially cleared on uninstall alongside the other options; the **debug log** line gives the same reviewable, timestamped trail through the channel the plugin already hardened, gated by a new `diagnostics` entry in `get_enabled_debug_categories()` (`SettingsController.php:414-432`). No new storage mechanism, no DB table.

### Execution gating (wp.org compliance)
The run is triggered by an `admin_post_bunnify_run_diagnostics` handler (or an `admin-ajax`/authenticated REST action), never on page load:

```php
public function handle_run(): void {
    if ( ! current_user_can( 'manage_options' ) ) { wp_die( -1, 403 ); }
    check_admin_referer( 'bunnify_run_diagnostics' );
    $url = isset( $_POST['sample_url'] )
        ? esc_url_raw( wp_unslash( $_POST['sample_url'] ) )
        : home_url( '/' );
    $this->run( $url, ! empty( $_POST['include_image_issues'] ) );
    wp_safe_redirect( add_query_arg( 'bunnify_diag', 'done', wp_get_referer() ) );
    exit;
}
```

Capability check, nonce (`check_admin_referer`), sanitised input, and a redirect back — the standard admin-post pattern. Because every outbound request is behind this click, the plugin makes **no unexpected external requests**, satisfying the wp.org guideline; the timeouts and caps keep a single run bounded to a few seconds.

## Migration & backwards compatibility
- **Purely additive.** No existing option, filter, page slug, section ID, or rewrite behaviour changes. The Diagnostics surface is a new card/handler alongside the existing Debug Log card.
- **New option `bunnify_diagnostics_history`** is registered with autoload `false` and added to the `uninstall.php` deletion list so uninstall stays clean (mirrors the existing option-enumeration cleanup).
- **New debug category `diagnostics`** is added to `get_enabled_debug_categories()`; it is off unless the admin enables debug logging *and* the category, exactly like every existing category — no behaviour change for current installs.
- **Downstream consumer impact: none.** No public filter is added or changed; a consumer wires only `bunnify_allow_non_upload_url` / `bunnify_skip_image`, which are untouched.
- **No new dependency.** Uses core `WP_Http` (`wp_safe_remote_*`), `WP_HTML_Tag_Processor`, and `getimagesizefromstring()` — all WordPress/PHP built-ins already relied on elsewhere in the plugin.

## Risks & mitigations
- **SSRF / fetching an internal URL.** Mitigation: `wp_safe_remote_*` only (honours `WP_Http`'s internal-host block); the coverage/issues URL is admin-supplied but sanitised with `esc_url_raw` and the request is capped and gated behind `manage_options` + nonce.
- **wp.org "no phoning home" rejection.** Mitigation: every outbound request is behind a manual button; nothing runs on activation, on admin page load, or on a schedule; document this explicitly in the readme.
- **Slow or hanging probes freezing the admin.** Mitigation: explicit short `timeout` (5s self-test, 8s page fetch), `redirection` capped, no retries, and the image-issues batch capped to N images and deduped. Worst case is bounded to a few seconds and only affects the admin who clicked.
- **False-negative on healthy sites from strict header matching.** Mitigation: cache/CDN header checks are **tolerant** — a missing vendor header is a `warn`, not a `fail`, and the panel always surfaces the raw headers so a human can judge. Only `code !== 200` and non-image bytes are hard fails.
- **HEAD not honoured by the CDN.** Mitigation: fall back to a tiny ranged GET (`Range: bytes=0-2047`) on `405`/`WP_Error`, which is also what the magic-byte sniff needs.
- **Scope creep toward PagePulse.** Mitigation: the Non-goals fence (no timing, no CWV, no headless, no scheduling) is load-bearing; any request for trend graphs, console errors, or field data is a signal to route the user to PagePulse, not to grow this panel.
- **`home_url()` sample returns a cached/edge copy** that doesn't reflect the rewriter. Mitigation: fetch is server-side origin-side by default; note in the UI that coverage reflects the HTML the server emits, and allow the admin to point at any URL.
- **No image attachments exist** to build a CDN test URL. Mitigation: `pick_sample_attachment_id()` returns null → the HTTP checks report `skipped` with a clear "upload an image to test delivery" message rather than a scary fail.

## Testing strategy
- **Unit (PHPUnit + Brain Monkey).** Mock `wp_safe_remote_head`/`wp_safe_remote_get` to return canned responses and assert verdicts: `200` + `image/jpeg` + JPEG magic bytes → all `pass`; `200` + `text/html` → `content_type` fail + `image_bytes` warn (the "HTML holding page" case); `WP_Error` → `http` fail; `405` → GET fallback is attempted. Assert `assess_cdn_headers()` returns `warn` (not `fail`) when vendor headers are absent.
- **Coverage classifier.** Feed fixture HTML with a known mix of CDN-host, origin-upload, and external `<img>` plus an inline `background-image` and a `<picture><source>`; assert the counts, `coverage_pct`, and `uncovered` flags. Reuse `is_cdn_url()` so the test also pins classification to the runtime definition.
- **DNS check.** Guard `gethostbyname()` behind a wrapper so it can be stubbed; assert "unresolvable → fail, resolvable → pass with IP".
- **Gating.** Assert `handle_run()` `wp_die`s without `manage_options`, and fails the nonce check without a valid referer (Brain Monkey `expect` on `check_admin_referer`).
- **Store.** Assert `finalise()` prepends to `bunnify_diagnostics_history`, caps at 10, writes with autoload `false`, and emits one `diagnostics`-category `debug_log` line.
- **No-attachment path.** Assert the self-test reports `skipped` (not `fail`) when `pick_sample_attachment_id()` is null.
- **Static analysis.** PHPStan level 5 green and phpcs/WPCS clean on the new controller (escaping on all echoed headers via `esc_html`, since response headers are attacker-influenced input).

## Rollout plan
1. **Ship the config self-test first (the highest-value, single-request slice).** Add `DiagnosticsController`, the `probe_head` helper, `run_config_selftest()`, the `admin-post` handler with cap+nonce, the results option, and a minimal results render on the settings page. This alone answers "is my CDN configured and delivering images". Independently shippable.
2. **Add the rewrite-coverage report.** The page fetch + `WP_HTML_Tag_Processor` classification + `uncovered` flags, reusing `is_cdn_url()`. Land [[url-surface-coverage]] alongside (or link a stub) so the flags have a reference.
3. **Add the optional image-issues check** behind its own opt-in control, reusing the page fetched in step 2 and the capped HEAD batch.
4. **Wire the `diagnostics` debug category** into `get_enabled_debug_categories()` and document the store + the new option in the readme/changelog and `uninstall.php`.
5. **Hand the presentation to [[observability-diagnostics-ui]]** — badges, header table, coverage bar — once the data contract from steps 1–3 is stable.

Each step is independently shippable and reversible; step 1 delivers most of the value.

## Effort estimate
**L.** No single probe is hard — each is "fetch a URL, inspect the response" — but the surface is a whole new controller with outbound I/O: the shared safe-request helper with HEAD/GET fallback, three probes, magic-byte sniffing, HTML classification that must reuse the runtime CDN-host definition, a bounded results store, the capability/nonce-gated `admin-post` flow, and the mocked-HTTP test suite that proves the wp.org "no unexpected requests / bounded, on-demand only" guarantees. It is larger than the settings/schema refactors (M) because of the remote-request safety surface and the three distinct sub-features — though it phases cleanly, and shipping only step 1 (config self-test) is a genuine **M** on its own.
