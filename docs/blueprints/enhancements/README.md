# Enhancement blueprints

Designed-but-not-yet-scheduled improvements. Each is a self-contained design doc
(problem → approach → migration → risks → testing → rollout). Status is
**Proposed** until the work is scheduled.

Many of these were surfaced by the enterprise restructure
([0001](../0001-enterprise-restructure/README.md)); the newer entries are
sequenced by the [quality & enterprise roadmap
(0002)](../0002-quality-enterprise-roadmap/README.md), which is the place to
start for priority and the PagePulse boundary.

| # | Blueprint | Wave | Status | Recommendation |
| --- | --- | --- | --- | --- |
| 1 | [URL-surface coverage](url-surface-coverage/README.md) | 1 | Proposed | Rewrite the surfaces that emit a bare attachment URL (ACF/REST `source_url`, theme, inline `background-image`) via a re-entrancy-safe `wp_get_attachment_url` filter — the biggest remaining coverage gap. |
| 2 | [Comprehensive test coverage](full-test-coverage/README.md) | 1 | Phase 0–1 done | Add a `wp-phpunit` integration layer and filter-contract tests (freeze the hook contract before the wave-4 refactors); coverage gate + PHP × WP matrix. |
| 3 | [Performance & benchmarking](performance-benchmarking/README.md) | 2 | Proposed | Cap per-image overhead; memoise the hot lookups (attachment-id-from-URL, options); prove queries don't scale with duplicate images. |
| 4 | [Core Web Vitals image delivery](cwv-image-delivery/README.md) | 2 | Proposed | Turn PageSpeed image opportunities into opt-in features: explicit width/height (CLS), `fetchpriority`/preload for the LCP image, lazy-load coordination. |
| 5 | [Format negotiation](format-negotiation/README.md) | 2 | Proposed | WebP/AVIF + quality via Bunny Optimizer params, off by default, `is_cdn_url()` detection intact. |
| 6 | [CDN health diagnostics](cdn-health-diagnostics/README.md) | 3 | Proposed | On-demand self-test (hostname resolves? CDN returns 200 + right cache headers?) and rewrite-coverage stats for a sampled page, logged for review. CDN-delivery scope only. |
| 7 | [Observability diagnostics UI](observability-diagnostics-ui/README.md) | 3 | Proposed | Present the diagnostics with dependency-free inline SVG (coverage donut, response-time sparkline, stat tiles) — no bundled chart library. |
| 8 | [Data-driven settings](data-driven-settings/README.md) | 4 | Partially done | Replace repetitive field registration with a schema array (the missing `sanitize_callback`s were added ahead of this in the readiness pass). |
| 9 | [DI & shared CDN service layer](di-container-service-layer/README.md) | 4 | Proposed | Replace the duplicated `init_cdn()` / `URLTransformer` static singleton with one lazily-resolved `CdnService`, behind the unchanged `bunnify_url` filter. |
| 10 | [Base framework standards](base-framework-standards/README.md) | 4 | Proposed | Bring `src/php/Base` up to WPCS/PHPStan and include it in linting; optionally extract it into a versioned Composer package. |
| — | [Complete or remove the REST layer](rest-controller-completion/README.md) | — | Implemented | The no-op `RESTController` was removed; REST-rewriting posture documented. |
| — | [wp.org runtime autoloader](wporg-runtime-autoloader/README.md) | — | Implemented | `build-tools/vendor` replaced by a hand-written plugin-root `autoload.php`. |

## Adding a new enhancement

Copy the shape of an existing folder: create
`enhancements/<slug>/README.md` with the standard sections (Summary, Motivation,
Goals, Non-goals, Current state, Proposed approach, Migration, Risks, Testing,
Rollout, Effort), set **Status: Proposed**, and add a row above.
