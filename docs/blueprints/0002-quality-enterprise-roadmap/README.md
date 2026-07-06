# 0002 — Quality & enterprise roadmap

- **Status:** Accepted (living roadmap)
- **Created:** 2026-07-03
- **Owner:** Anthony Thorne

## Summary

A single place that states what "done" and "enterprise-grade" mean for Bunnify
Frontend, and sequences the work to get there — from the wordpress.org
submission-readiness pass (largely complete) through performance, Core Web
Vitals, delivery diagnostics, and the remaining architecture blueprints. It
does **not** introduce new behaviour itself; it decides scope, priority, and the
guardrails every enhancement is held to, and links out to the individual
[enhancement blueprints](../enhancements/README.md) that carry the detail.

## What this plugin is (and is not)

Bunnify Frontend is a **lightweight, frontend-only Bunny CDN image-URL
rewriter**. Every decision on this roadmap is measured against that identity:

- **Lightweight beats feature-rich.** New surface must earn its weight. Prefer
  dependency-free, opt-in, filter-gated features over always-on machinery. No
  bundled JS frameworks or heavy libraries in a public wp.org image plugin.
- **Frontend delivery, not monitoring.** Bunnify's job is to emit fast, correct,
  CDN-backed image markup. **Deep page-speed / CWV field monitoring, CrUX/PSI
  history, console-error capture, and rich time-series dashboards belong to the
  author's separate _PagePulse_ plugin — not here.** Where this roadmap touches
  "diagnostics" or "graphs," it is strictly the point-in-time, image/CDN-delivery
  slice (is the pull zone healthy? are these URLs actually rewritten?), and it
  cross-references PagePulse rather than duplicating it.
- **Public and vendor-neutral.** Nothing client-specific ships; the plugin is
  wordpress.org-bound (see [[wporg-runtime-autoloader]] and [0001](../0001-enterprise-restructure/README.md)).

## Definition of done (the bar every change clears)

- `composer check` green: PHPCS (WordPress standards), PHPStan level 5, PHPUnit.
- Behaviour-preserving by default: new features are opt-in and filter-gated; the
  no-config, no-CDN, and disabled paths are unchanged and fast.
- Covered by tests at the right layer (unit for pure logic; integration for the
  WordPress media/filter pipeline — see [[full-test-coverage]]).
- Documented: `CHANGELOG.md`, `readme.txt`, and the relevant `docs/` page.
- Ships clean: no dev artefacts in the zip; Plugin Check clean before submission.
- **Never publishes by accident:** the wordpress.org deploy is gated behind
  three independent guardrails (manual dispatch, typed confirmation, and the
  `WPORG_RELEASE_ENABLED` repository variable) — see `.github/workflows/deploy.yml`.

## Already delivered (context)

- **0001 — Enterprise restructure:** repo reshape, dev toolchain, CI, packaging,
  standards pass.
- **Known-issue fix wave:** master-switch semantics, image-size collapse, crop
  passthrough, recursive trait detection.
- **Runtime autoloader:** `build-tools/vendor` replaced by a hand-written
  plugin-root `autoload.php` ([[wporg-runtime-autoloader]], implemented).
- **REST posture:** the no-op `RESTController` removed; the REST-rewriting
  behaviour documented as intentional ([[rest-controller-completion]], implemented).
- **Media-library picker fix + automatic local-dev mode** (env-detected via
  `wp_get_environment_type()`, `local`/`development` only).
- **wordpress.org submission-readiness pass:** i18n across the admin UI,
  `sanitize_callback` on every setting, debug-log path fixed and the log
  directory hardened, `var_dump` removed, request/superglobal reads sanitised,
  `image_exists_locally()` cached per request, staging left to exercise the CDN.

## Roadmap (prioritised)

Waves are ordered so each unblocks the next. Cross-cutting quality
([[full-test-coverage]]) runs alongside.

### Wave 1 — Correctness & coverage of the delivery path

| # | Blueprint | Why now |
| --- | --- | --- |
| 1 | [[url-surface-coverage]] | The biggest remaining hole: bare attachment URLs (ACF/REST `source_url`/theme/inline `background-image`) still leak the origin. Needs the re-entrancy-safe `wp_get_attachment_url` design before anything builds on "everything is rewritten". |
| 2 | [[full-test-coverage]] | Stand up the wp-phpunit integration layer and the filter-contract suite **before** the refactors below, so the public hook contract is frozen. |

### Wave 2 — Performance & Core Web Vitals

| # | Blueprint | Why now |
| --- | --- | --- |
| 3 | [[performance-benchmarking]] | Bunnify runs per-image on every request; measure and cap its own overhead, memoise the hot lookups (attachment-id-from-URL, options), and prove queries don't scale with duplicate images. |
| 4 | [[cwv-image-delivery]] | **Declined** — WordPress 7.0 core already emits width/height + `fetchpriority`; CWV is the developer/theme's responsibility, not this plugin's. Built then reverted. |
| 5 | [[format-negotiation]] | WebP/AVIF and quality via Bunny Optimizer params, off by default, with `is_cdn_url()` detection kept intact. |

### Wave 3 — Delivery diagnostics (bunnify-scoped, not PagePulse)

| # | Blueprint | Why now |
| --- | --- | --- |
| 6 | [[cdn-health-diagnostics]] | An on-demand self-test (hostname resolves? CDN URL returns 200 with the right cache headers?) and rewrite-coverage stats for a sampled page, logged for review. Strictly CDN/image-delivery scope. |
| 7 | [[observability-diagnostics-ui]] | Present that data with dependency-free inline SVG (coverage donut, response-time sparkline, stat tiles) — no bundled chart library. The lightweight visual layer for wave 3. |

### Wave 4 — Architecture & framework

| # | Blueprint | Why now |
| --- | --- | --- |
| 8 | [[data-driven-settings]] | Schema-driven settings; the config-drift the master-switch and local-dev work kept hitting is the argument. |
| 9 | [[di-container-service-layer]] | One injected `CdnService` replaces the static singleton + per-controller trait; do it once the filter-contract tests exist. |
| 10 | [[base-framework-standards]] | Bring `src/php/Base` up to WPCS/PHPStan (the superglobal reads were sanitised ahead of this as a readiness necessity); optionally extract the framework. |

## Guardrails carried by every wave

- **Opt-in, filter-gated, off by default.** A fresh install behaves exactly as
  1.0.0 until an admin opts in.
- **No new runtime dependencies** without an explicit, recorded justification —
  especially no client-side libraries. Inline SVG over a chart library; core WP
  APIs over vendored packages.
- **Remote requests** (diagnostics) use `wp_safe_remote_*`, short timeouts, a
  capability + nonce, and only on an explicit button press — never on page load.
- **PagePulse boundary respected.** If a proposal starts to look like page/CWV
  field monitoring, it belongs in PagePulse; link, don't build.

## Non-goals for this roadmap

- Replacing or competing with PagePulse (page monitoring, CrUX, PSI, RUM).
- Upload-time transformation, purge/invalidation, or writing CDN URLs into
  stored content — Bunnify decorates output, it does not persist.
- A settings-heavy "suite" UI. Diagnostics stay a single, lightweight panel.
