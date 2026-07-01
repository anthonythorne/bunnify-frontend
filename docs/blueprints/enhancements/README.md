# Enhancement blueprints

Designed-but-not-yet-scheduled improvements. Each is a self-contained design doc
(problem → approach → migration → risks → testing → rollout). Status is
**Proposed** until the work is scheduled.

Many of these were surfaced by the enterprise restructure
([0001](../0001-enterprise-restructure/README.md)); that blueprint's *Known
issues* section links back here.

| # | Blueprint | Recommendation |
| --- | --- | --- |
| 1 | [DI & shared CDN service layer](di-container-service-layer/README.md) | Replace the duplicated `init_cdn()` / `URLTransformer` static singleton with one lazily-resolved `CdnService` injected into controllers, behind the unchanged `bunnify_url` filter. |
| 2 | [Comprehensive test coverage](full-test-coverage/README.md) | Add a `wp-phpunit` integration layer on top of the Brain Monkey unit layer, pinned by filter-contract tests, with a coverage gate and a PHP × WP CI matrix. |
| 3 | [Complete or remove the REST layer](rest-controller-completion/README.md) | Remove the no-op `RESTController`; keep an opt-in `rest_prepare_attachment` rewriter as a documented future escape hatch. |
| 4 | [Data-driven settings](data-driven-settings/README.md) | Replace ~200 lines of repetitive field registration with a schema array (adding the missing `sanitize_callback`s) and wire up the dead `bunnify_enabled` toggle safely. |
| 5 | [Base framework standards](base-framework-standards/README.md) | Bring `src/php/Base` up to WPCS/PHPStan and include it in linting; optionally extract it into a versioned Composer package. |
| 6 | [wp.org runtime autoloader](wporg-runtime-autoloader/README.md) | Replace `build-tools/vendor` with a clean, zero-Composer-footprint PSR-4 loader at the plugin root for a tidier wordpress.org package. |

## Adding a new enhancement

Copy the shape of an existing folder: create
`enhancements/<slug>/README.md` with the standard sections (Summary, Motivation,
Goals, Non-goals, Current state, Proposed approach, Migration, Risks, Testing,
Rollout, Effort), set **Status: Proposed**, and add a row above.
