# Observability: diagnostics visualisation on the settings page

- **Status:** Proposed
- **Created:** 2026-07-03
- **Owner:** _unassigned_
- **Related:** [[cdn-health-diagnostics]] (the data source this blueprint renders — counters and timing samples), [[performance-benchmarking]] (where CDN-vs-origin timing methodology is defined), [[data-driven-settings]] (the admin page this UI is appended to)

## Summary
[[cdn-health-diagnostics]] proposes collecting point-in-time delivery data — how many image URLs on the last rendered page(s) were **rewritten** to the CDN, served from **origin** (fallback), or **skipped** by a filter, plus a small ring of sampled CDN response times. That data is only useful if a site owner can *see* it. This blueprint covers **how to visualise it on the existing `Media → BunnyCDN` settings page** without betraying bunnify's identity as a lightweight, single-purpose image-URL rewriter.

The user asked for "helpful graphs, maybe a graph library." The load-bearing design decision here is to say **no to a bundled JS charting library** and render a handful of small visuals as **dependency-free inline SVG + CSS, server-side in PHP**: a stat-tile row, a coverage donut (rewritten vs origin vs skipped), and a response-time sparkline. This costs a few hundred bytes of inline markup instead of ~200 KB of vendored JavaScript, ships nothing to the front end, and keeps the plugin honest for a wordpress.org listing. Rich time-series dashboards, CrUX, and PSI graphs are explicitly out of scope — that is [PagePulse](#non-goals)'s domain, a separate dedicated performance-monitoring plugin. Bunnify shows only lightweight, admin-only, point-in-time delivery diagnostics.

## Motivation / Problem
Today the settings page has exactly one diagnostic surface: a **Debug Log** card (`bunnify-frontend/src/php/Controller/SettingsController.php:516-549`) that reports the log file's size and line count and tells the user to enable categories and load a page. There is no answer to the two questions a CDN user actually asks:

1. **"Is it working?"** — what fraction of images on my pages are actually going through the CDN, versus falling back to origin or being skipped? Right now the only way to know is to enable `bunnify_debug_url_transformation`, load a page, and read a raw SFTP-only log line by line.
2. **"Is it fast?"** — is the CDN edge responding quickly, or slower than origin? There is no timing surface at all.

A raw text log is a poor answer to both. The information is there (or will be, once [[cdn-health-diagnostics]] lands) but it is unaggregated and invisible. A small, glanceable visual on a page the admin already visits turns "read the log" into "look at the donut."

The naive fix — "add Chart.js and draw graphs" — is the wrong fix for **this** plugin, and the tension deserves to be stated plainly rather than assumed away:

- Bunnify is a **lightweight, frontend-only image-URL rewriter**. Its whole pitch is that it does one small thing and adds no weight. A wordpress.org reviewer (and a performance-conscious user) will reasonably ask why an *image CDN* plugin ships 200 KB of charting JavaScript in its admin bundle.
- The visuals genuinely needed here are **trivial**: three or four numbers, one part-to-whole ratio, and one ~20-point trend line. That is squarely inside what plain SVG draws well and squarely below the threshold where a charting library earns its bytes.
- The data is **admin-only and point-in-time**. There is no interactivity, no zoom, no live streaming, no legend toggling that would justify a client-side chart runtime.

So the problem is really two problems: (a) the diagnostics data has no visualisation, and (b) the obvious way to visualise it would compromise the plugin's core value proposition. This blueprint solves both by choosing the lightest tool that does the job.

## Goals
- Render a **stat-tile row** summarising the latest diagnostics snapshot: rewritten / origin-fallback / skipped counts, a rewrite-coverage percentage, and the master state (hostname configured, `is_enabled()`, local-dev mode).
- Render a **coverage donut** (or horizontal stacked bar) showing rewritten vs origin vs skipped as a part-to-whole, as **inline SVG generated in PHP**, with a text fallback and an accessible label.
- Render a **response-time sparkline** from the sampled CDN timings in the snapshot, as inline SVG, with min/median/max shown as text beside it.
- Ship **zero JavaScript** and **zero external requests** for this feature; add only a few hundred bytes of scoped admin CSS (enqueued only on the BunnyCDN screen).
- Keep everything **admin-only, capability-gated, and fully escaped** — the visuals live inside the existing `manage_options`-gated `admin_page()` and every value is passed through `esc_html`/`esc_attr`.
- Degrade gracefully to a **clear empty state** when no snapshot exists yet (fresh install, diagnostics disabled, or [[cdn-health-diagnostics]] not yet present).
- Add the visuals as small, **pure render helpers** (input array → escaped SVG string) so they are unit-testable without WordPress.

## Non-goals
- **No bundled JS charting library** (Chart.js, ApexCharts, Chartist, uPlot, etc.), and no vendored charting anything — not in the admin bundle, and certainly not on the front end. This is the whole point (see Proposed approach for the trade-off analysis).
- **No time-series history, no CrUX, no PSI, no Web Vitals field data, no cron-collected trends.** Those are the domain of **PagePulse**, the author's separate page-speed / CWV monitoring plugin, which is built for exactly that and carries the storage and UI weight it requires. Bunnify shows only *point-in-time* delivery diagnostics for the last sampled render(s). If a user wants dashboards over time, the answer is "install PagePulse," documented as a cross-link, not "grow bunnify into a monitoring plugin."
- **No new data collection.** This blueprint is the *view* layer; the *model* (what is counted, where the snapshot is stored, how sampling is bounded) is owned by [[cdn-health-diagnostics]]. This doc only consumes its output and defines the contract it depends on.
- **No React / `@wordpress/components` / block-editor UI.** This stays on the classic settings screen, consistent with [[data-driven-settings]].
- **No front-end widget or shortcode.** Diagnostics are for the site operator, not visitors.
- **No admin-pointer, dashboard-widget, or Site Health integration** in this pass (a Site Health "info" tab entry is noted as a possible follow-up, not built here).

## Current state
The settings page is rendered by `SettingsController::admin_page()` (`bunnify-frontend/src/php/Controller/SettingsController.php:479-552`), gated on `current_user_can( 'manage_options' )` at `:480-482`. After the settings form it prints a single **Debug Log** card (`:516-549`) showing `size_format()` of the log file and a line count — that is the entire current "observability" surface, and it is textual only.

The data this UI would visualise does **not exist yet**; it is the deliverable of the sibling [[cdn-health-diagnostics]] blueprint. The decision points that blueprint would instrument already exist in code, which is what makes the three-way rewritten/origin/skipped split meaningful:

- **Rewritten vs origin-fallback:** `CDNController::cdn_url()` (`bunnify-frontend/src/php/Controller/CDNController.php:46-90`) either returns a CDN URL (via attachment-ID or `URLTransformer::transform_url()`) or falls back to the original `$image_url`. `init_cdn()` (`bunnify-frontend/src/php/Library/CdnClientTrait.php:44-63`) gates rewriting on the master switch and a configured hostname.
- **Skipped:** the `bunnify_skip_for_url` filter (`CDNController.php:63`) and `bunnify_skip_content_processing` (`bunnify-frontend/src/php/Controller/ContentController.php:98`) short-circuit rewriting; these are the "skipped" bucket.
- **State the tiles report** already has accessors: `SettingsController::is_enabled()` (`:604-612`), `is_local_dev_mode_enabled()` (`:636-650`), the hostname option, and `get_enabled_debug_categories()` (`:414-432`).

No admin CSS or JS is currently enqueued for this page (the markup reuses core `.wrap` / `.card` classes only), so there is no existing asset pipeline to extend — this feature introduces the first scoped admin stylesheet.

**Assumed data contract from [[cdn-health-diagnostics]].** This blueprint is written against a small, bounded snapshot that that blueprint is expected to persist (e.g. an autoloaded option or short-lived transient `bunnify_diagnostics_snapshot`). The exact keys are owned there; this UI needs only:

```php
// Shape this UI renders. Owned/produced by [[cdn-health-diagnostics]].
[
    'counts'    => [ 'rewritten' => 42, 'origin' => 3, 'skipped' => 5 ],
    'samples'   => [ 61, 58, 72, 55, 60, /* … bounded ring, ms */ ],  // may be []
    'sampled_at'=> 1751500000,  // unix ts of the snapshot, or 0/absent
]
```

If the snapshot is absent or `counts` sums to zero, the UI renders the empty state and nothing else.

## Proposed approach

### The trade-off, stated explicitly
Three options were considered for drawing the visuals. The recommendation is **Option A**.

| Option | What it is | Weight added | Fits bunnify's identity? |
| --- | --- | --- | --- |
| **A. Inline SVG + CSS, server-rendered in PHP** *(recommended)* | Build `<svg>` strings in PHP from the snapshot; a few hundred bytes of scoped admin CSS; zero JS. | ~0.5 KB markup per render + tiny CSS, admin-only, no runtime. | **Yes.** Nothing shipped to visitors; nothing to audit as a "library"; matches the "does one small thing" pitch. |
| **B. A tiny vendored chart lib** (e.g. a ~10 KB micro-charting script) | Bundle and `wp_enqueue_script` a small library on the BunnyCDN screen only. | ~10–40 KB JS admin-only, plus a vendored dependency to license-audit, update, and justify to wp.org review. | **Marginal.** Smaller than Chart.js but still "an image plugin that ships a charting library," for visuals SVG draws trivially. |
| **C. Chart.js / ApexCharts** (what "a graph library" usually means) | Full-featured client-side charting. | ~150–250 KB JS. | **No.** Indefensible for three numbers and a sparkline in a lightweight plugin. |

The visuals needed — a part-to-whole ratio and a ~20-point trend line — are exactly the cases where hand-written SVG is *less* code than wiring up a chart library, because there is no dataset config, no options object, no responsive-resize handler, and no enqueue/dependency to manage. A charting library pays off when you have many chart types, interactivity, live data, or a design system to match; bunnify has none of those. **Recommendation: Option A.** Revisit only if a concrete future need (interactive drill-down, many series) appears — and if it ever does, that is a signal the feature belongs in PagePulse, not bunnify.

### Where it renders
Append a new **"CDN Delivery" card** inside `admin_page()`, after the existing Debug Log card (`SettingsController.php:549`), still inside the `manage_options` gate. Read the snapshot once, branch to the empty state if absent, otherwise render three pieces from small pure helpers. Enqueue the scoped stylesheet only on this screen via the `admin_enqueue_scripts` hook, keyed on the page hook suffix returned by `add_submenu_page()`.

```php
// Inside admin_page(), after the Debug Log card.
$snapshot = $this->get_diagnostics_snapshot();      // from [[cdn-health-diagnostics]]; [] if none
?>
<div class="card bunnify-diagnostics" style="width:100%;max-width:unset;">
    <h2><?php esc_html_e( 'CDN Delivery', 'bunnify-frontend' ); ?></h2>
    <?php if ( empty( $snapshot['counts'] ) || 0 === array_sum( $snapshot['counts'] ) ) : ?>
        <p class="description">
            <?php esc_html_e( 'No delivery data yet. Load a front-end page to sample how images are served.', 'bunnify-frontend' ); ?>
        </p>
    <?php else : ?>
        <?php
        // Each helper returns an escaped HTML/SVG string; echo is safe because
        // the helper is the escaping boundary (see note below).
        echo self::render_stat_tiles( $snapshot );        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pre-escaped in helper
        echo self::render_coverage_donut( $snapshot['counts'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        echo self::render_sparkline( $snapshot['samples'] );     // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        ?>
    <?php endif; ?>
</div>
<?php
```

> **Escaping boundary.** Because SVG contains markup, these helpers cannot be echoed through `esc_html`. The rule is: **every dynamic value is escaped at the point it enters the string** (`esc_attr()` for numeric attributes, `esc_html()` for text nodes, `(int)`/`(float)` casts for geometry), and the helper returns a string composed only of a fixed template plus those escaped values. The helpers take primitives (ints/floats/short strings), never raw request or HTML input, so there is no unescaped path. A single documented `phpcs:ignore WordPress.Security.EscapeOutput` sits at the `echo`, justified by the helper being the escaping boundary.

### Visual 1 — stat-tile row
A flex row of small tiles. Pure text and numbers; no SVG needed. This is the highest-value, lowest-effort piece.

```php
private static function render_stat_tiles( array $s ): string {
    $c        = $s['counts'];
    $total    = max( 1, array_sum( $c ) );
    $coverage = (int) round( ( $c['rewritten'] / $total ) * 100 );
    $tiles    = [
        [ __( 'Rewrite coverage', 'bunnify-frontend' ), $coverage . '%' ],
        [ __( 'Rewritten',        'bunnify-frontend' ), (string) (int) $c['rewritten'] ],
        [ __( 'From origin',      'bunnify-frontend' ), (string) (int) $c['origin'] ],
        [ __( 'Skipped',          'bunnify-frontend' ), (string) (int) $c['skipped'] ],
        [ __( 'CDN status',       'bunnify-frontend' ),
            self::is_enabled() && '' !== (string) get_option( 'bunnify_hostname', '' )
                ? __( 'Active', 'bunnify-frontend' ) : __( 'Inactive', 'bunnify-frontend' ) ],
    ];

    $out = '<div class="bunnify-stats">';
    foreach ( $tiles as [ $label, $value ] ) {
        $out .= sprintf(
            '<div class="bunnify-stat"><span class="bunnify-stat__value">%s</span>'
            . '<span class="bunnify-stat__label">%s</span></div>',
            esc_html( $value ),
            esc_html( $label )
        );
    }
    return $out . '</div>';
}
```

### Visual 2 — coverage donut (inline SVG, `stroke-dasharray`)
A three-segment ring drawn with overlaid `<circle>` strokes and `stroke-dasharray`, no path math beyond one circumference constant. Include an accessible `role="img"` + `<title>`, and a text fallback (the segment breakdown) below it so the meaning survives with images/CSS off and for screen readers.

```php
private static function render_coverage_donut( array $c ): string {
    $total = max( 1, array_sum( $c ) );
    $r     = 42;                    // radius
    $circ  = 2 * M_PI * $r;         // circumference
    // Segment order: rewritten, origin, skipped. Each is a dash of length =
    // fraction * circumference, offset by the running total before it.
    $segments = [
        [ 'rewritten', 'var(--bf-ok)',   $c['rewritten'] ],
        [ 'origin',    'var(--bf-warn)', $c['origin'] ],
        [ 'skipped',   'var(--bf-mute)', $c['skipped'] ],
    ];

    $rings  = '';
    $offset = 0.0;
    foreach ( $segments as [ $key, $colour, $n ] ) {
        $len     = ( $n / $total ) * $circ;
        $rings  .= sprintf(
            '<circle cx="60" cy="60" r="%1$d" fill="none" stroke="%2$s" stroke-width="16" '
            . 'stroke-dasharray="%3$s %4$s" stroke-dashoffset="%5$s" transform="rotate(-90 60 60)" />',
            (int) $r,
            esc_attr( $colour ),
            esc_attr( (string) round( $len, 2 ) ),
            esc_attr( (string) round( $circ - $len, 2 ) ),
            esc_attr( (string) round( -$offset, 2 ) )
        );
        $offset += $len;
    }

    $coverage = (int) round( ( $c['rewritten'] / $total ) * 100 );
    /* translators: %d: percentage of images served via the CDN. */
    $title    = sprintf( esc_html__( 'CDN coverage: %d%% of sampled images', 'bunnify-frontend' ), $coverage );

    return sprintf(
        '<figure class="bunnify-donut"><svg viewBox="0 0 120 120" width="120" height="120" '
        . 'role="img" aria-label="%1$s"><title>%1$s</title>%2$s'
        . '<text x="60" y="66" text-anchor="middle" class="bunnify-donut__pct">%3$s%%</text></svg>'
        . '<figcaption>%4$s</figcaption></figure>',
        esc_attr( $title ),
        $rings,                       // pre-escaped above
        esc_html( (string) $coverage ),
        // Text fallback / legend, itself escaped.
        esc_html( sprintf(
            /* translators: 1: rewritten count, 2: origin count, 3: skipped count. */
            __( 'Rewritten %1$d · Origin %2$d · Skipped %3$d', 'bunnify-frontend' ),
            (int) $c['rewritten'], (int) $c['origin'], (int) $c['skipped']
        ) )
    );
}
```

A horizontal stacked bar is an equally valid, arguably simpler alternative (three `<div>`s with percentage widths, no SVG at all). Ship whichever reviews better visually; the donut is sketched here because it reads as "coverage" at a glance. Both are dependency-free.

### Visual 3 — response-time sparkline (inline SVG `<polyline>`)
Normalise the bounded `samples` array (milliseconds) into a fixed viewBox and emit one `<polyline>`. Show min/median/max as escaped text beside it. If there are fewer than two samples, render just the text (a line needs two points).

```php
private static function render_sparkline( array $samples ): string {
    $samples = array_values( array_map( 'floatval', $samples ) );
    if ( count( $samples ) < 2 ) {
        return '<p class="description">' . esc_html__( 'Not enough response-time samples yet.', 'bunnify-frontend' ) . '</p>';
    }
    $w = 200; $h = 40; $min = min( $samples ); $max = max( $samples );
    $span = max( 1.0, $max - $min );                 // avoid divide-by-zero on a flat line
    $step = $w / ( count( $samples ) - 1 );

    $points = '';
    foreach ( $samples as $i => $ms ) {
        $x = round( $i * $step, 1 );
        $y = round( $h - ( ( $ms - $min ) / $span ) * $h, 1 );  // invert: lower ms = higher on chart
        $points .= esc_attr( "$x,$y" ) . ' ';
    }

    $median = self::median( $samples );
    return sprintf(
        '<figure class="bunnify-spark"><svg viewBox="0 0 %1$d %2$d" width="%1$d" height="%2$d" '
        . 'role="img" aria-label="%3$s" preserveAspectRatio="none">'
        . '<polyline fill="none" stroke="var(--bf-ok)" stroke-width="2" points="%4$s" /></svg>'
        . '<figcaption>%5$s</figcaption></figure>',
        (int) $w, (int) $h,
        esc_attr__( 'Sampled CDN response times', 'bunnify-frontend' ),
        trim( $points ),              // pre-escaped per point
        esc_html( sprintf(
            /* translators: 1: min ms, 2: median ms, 3: max ms. */
            __( 'min %1$d · median %2$d · max %3$d ms', 'bunnify-frontend' ),
            (int) $min, (int) $median, (int) $max
        ) )
    );
}
```

### Colour, theme, and CSS
Define three semantic CSS variables (`--bf-ok`, `--bf-warn`, `--bf-mute`) in the scoped stylesheet so the segments read consistently and respect `@media (prefers-color-scheme: dark)` / the admin colour scheme. Do **not** rely on colour alone to convey meaning — the text fallback/legend under each visual carries the same information, satisfying WCAG 1.4.1. The stylesheet is enqueued only on the BunnyCDN hook suffix, so it adds nothing elsewhere in wp-admin.

## Migration & backwards compatibility
- **Purely additive, view-only.** No option is added, renamed, read for writing, or deleted by this blueprint; `uninstall.php` is untouched. It only *reads* the snapshot that [[cdn-health-diagnostics]] owns, plus existing accessors (`is_enabled()`, `bunnify_hostname`).
- **Hard dependency on [[cdn-health-diagnostics]] for real data, soft at runtime.** If that blueprint has not landed (or diagnostics are disabled), `get_diagnostics_snapshot()` returns `[]` and the card shows the empty state. The UI must never fatal or warn when the snapshot is missing — treat absent/short/zero data as "no data yet."
- **No public API change.** No new filters or actions are required for the core feature. A single optional `apply_filters( 'bunnify_diagnostics_cards', [] )` extension point could be added later to let companion code append cards, but it is not needed for v1 and is out of scope here.
- **No consumer impact.** A downstream integration (e.g. a site that only wires `bunnify_allow_non_upload_url` / `bunnify_skip_image`) sees no behavioural change; this is admin-screen chrome.
- **Coordinate the card order with [[data-driven-settings]].** If that refactor reshapes `admin_page()`, land the diagnostics card as an explicit render step so the two changes do not collide over the same method.

## Risks & mitigations
- **Scope creep toward a monitoring dashboard.** The single biggest risk is that "add a sparkline" becomes "add history, then cron collection, then trends" — reinventing PagePulse inside a lightweight image plugin. **Mitigation:** the Non-goals section is normative; anything time-series, CrUX, PSI, or cron-collected is rejected at review and pointed to PagePulse. Bunnify's snapshot is point-in-time by design.
- **Someone reaches for a JS chart library anyway.** **Mitigation:** the trade-off table is in this doc precisely to pre-empt that; adding a charting dependency requires overturning this recommendation with a concrete need SVG cannot meet.
- **Unescaped SVG output.** SVG can't go through `esc_html`, so a careless helper could emit an unescaped value. **Mitigation:** helpers take only primitives, escape every dynamic value at insertion (`esc_attr`/`esc_html`) or cast geometry to `(int)`/`(float)`, and carry the one documented `phpcs:ignore` at the `echo` with the escaping-boundary rationale; unit tests assert no unescaped interpolation.
- **Divide-by-zero / degenerate data.** A zero total, a flat sample line, or a single sample would break naive geometry. **Mitigation:** `max( 1, … )` on totals, `max( 1.0, $max - $min )` on span, and an explicit `< 2 samples` early return, each covered by a test.
- **Misleading a user with a tiny sample.** A donut built from 3 sampled images could imply site-wide truth. **Mitigation:** label it as *sampled* (the caption and empty-state copy say "sampled"), and let [[cdn-health-diagnostics]] decide the sample size; the UI states what it shows, no more.
- **Accessibility / colour-only meaning.** **Mitigation:** `role="img"` + `<title>`/`aria-label` on every SVG, a text legend/fallback under each visual, and no meaning conveyed by colour alone.
- **CSS bleed into wp-admin.** **Mitigation:** enqueue only on the BunnyCDN page hook suffix, scope all rules under `.bunnify-diagnostics`, use CSS variables rather than global element selectors.

## Testing strategy
- **Unit (PHPUnit + Brain Monkey), pure helpers.** The three `render_*` methods take arrays and return strings, so they test without WordPress. Assert: correct segment lengths/offsets for a known count split; `coverage = round(rewritten/total*100)`; the `< 2 samples` branch returns the text-only fallback; a flat sample line does not divide by zero; every numeric attribute in the output is the escaped/cast value (regex the output for the expected `stroke-dasharray`/`points`).
- **Escaping assertions.** Feed hostile-ish values (huge ints, negatives, a crafted label string) and assert the output contains no unescaped `<`/`"` outside the fixed template — the helpers should coerce or escape all of it.
- **Empty-state rendering.** Assert `admin_page()` prints the "No delivery data yet" copy and *none* of the SVG when the snapshot is `[]` or sums to zero (mock `get_diagnostics_snapshot()`).
- **Gating.** Confirm the whole card sits inside the existing `current_user_can( 'manage_options' )` guard (`SettingsController.php:480-482`) and that the stylesheet enqueues only on the BunnyCDN hook suffix (assert the `admin_enqueue_scripts` callback bails on other hooks).
- **Snapshot smoke (integration, optional).** With the [[cdn-health-diagnostics]] collector present, load a fixture page, assert a non-empty snapshot, and assert the rendered card contains a `<svg>` and the expected coverage percentage.
- **Static analysis / lint.** PHPStan level 5 green (helpers are simply typed; add `@param array{counts: array<string,int>, samples: list<float>}` shapes if needed) and phpcs/WPCS clean, with the single escaping `phpcs:ignore` justified inline.

## Rollout plan
1. **Land the data contract first.** This blueprint is gated on [[cdn-health-diagnostics]] providing `get_diagnostics_snapshot()` (or equivalent). Until then, only the empty-state card and the pure helpers (with unit tests over fixture arrays) can ship — which is a fine first step and de-risks the rendering.
2. **Ship the stat-tile row.** Highest value, lowest risk, no SVG. Wire the card + empty state + scoped enqueue, render tiles from the snapshot. Independently useful even before the charts.
3. **Add the coverage donut** (or stacked bar — pick one in review) with its text fallback and accessibility attributes, behind the same card.
4. **Add the response-time sparkline**, including the `< 2 samples` and flat-line handling.
5. **Polish:** dark-mode / admin-colour-scheme CSS variables, copy review for "sampled" framing, and a one-line cross-link in `docs/` pointing power users to PagePulse for time-series monitoring.
6. **(Optional, later)** surface the same coverage number as a WordPress **Site Health → Info** row for at-a-glance support diagnostics; no charts there, just the number.

Each step is independently shippable; steps 2–4 each add one self-contained, tested helper.

## Effort estimate
**S.** Three small pure render helpers, an empty state, a scoped admin stylesheet, and unit tests — all additive, admin-only, no data model of its own, no migration, no public API change, and deliberately no JS/charting dependency to integrate. The only thing keeping it from trivial is doing the SVG escaping and the degenerate-data guards carefully, plus the accessibility fallbacks. Note the real gate is sequencing: the *data* work lives in [[cdn-health-diagnostics]]; this view layer is small precisely because it consumes that snapshot rather than collecting anything itself.
