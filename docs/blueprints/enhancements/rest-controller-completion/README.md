# Complete or remove the REST layer

- **Status:** Proposed
- **Created:** 2026-07-01
- **Owner:** _unassigned_
- **Related:** [[base-framework-standards]] (Base is currently un-linted, which is why this dead controller passes CI), [[consolidate-cdn-init]] (the duplicated `init_cdn()` pattern any real REST implementation would otherwise copy)

## Summary

`RESTController` ships three registered WordPress filter callbacks that do nothing: each validates its input, then returns it unchanged behind a "For now" comment, wrapped in a `try/catch` that can never fire. It adds three hooks to every REST request, imports a `URLTransformer` it never constructs, and carries private `$url_transformer` / `$bunnify_hostname` fields that are never assigned. This blueprint forces a decision: **(a)** implement genuine REST-context CDN rewriting with a real use case, or **(b)** delete the controller and its registration. We recommend **(b) — remove it** — because the plugin is explicitly frontend-only, the sole in-house consumer (caretochange) never touches these hooks, and the one plausible use case for (a) is arguably undesirable.

## Motivation / Problem

The controller is a stub masquerading as a feature. Concretely:

- `set_up()` registers three hooks on every REST request — `rest_request_before_callbacks`, `rest_after_insert_attachment`, and `rest_request_after_callbacks` (`src/php/Controller/RESTController.php:44-46`).
- `should_rest_bunnify_image_downsize()` checks that `$endpoint_data['callback']` exists and then returns `$response` verbatim, commented "For now, always return the original response." (`src/php/Controller/RESTController.php:64-66`).
- `should_rest_bunnify_image_downsize_insert_attachment()` validates the post type and then does nothing, "For now, do nothing." (`src/php/Controller/RESTController.php:87-88`).
- `cleanup_rest_bunnify_image_downsize()` returns `$response` unchanged (`src/php/Controller/RESTController.php:103-105`).
- Every method wraps a no-op in `try { ... } catch ( \Exception $e ) { error_log(...) }` (`src/php/Controller/RESTController.php:67-71`, `89-92`, `106-110`). Nothing in these bodies can throw, so the catch arms and their `error_log()` calls are dead code.
- The class declares `private ?URLTransformer $url_transformer` and `private ?string $bunnify_hostname` (`src/php/Controller/RESTController.php:32-37`) and imports `URLTransformer` (`:20`), none of which are ever used — unlike the sibling controllers that actually build a transformer via `init_cdn()` (`CDNController::init_cdn()` at `src/php/Controller/CDNController.php:52-66`).
- There is a latent signature mismatch: `cleanup_rest_bunnify_image_downsize` is registered for three arguments (`...10, 3`) but declares only `$response` (`src/php/Controller/RESTController.php:46` vs `:101`). Harmless in PHP, but a tell that the callback was never finished.
- It is wired into the boot list at `bunnify-frontend.php:55`, so it loads and hooks on every request even though it can never change output.

The cost is small but real: dead surface area that reviewers must read, three hook registrations on the hot REST path, `error_log` noise potential if anyone "fills in" the body incorrectly, and a false signal in `docs/HOOKS.md`-adjacent expectations that Bunnify has a REST story. Because `src/php/Base` is excluded from phpcs and this controller is thin, nothing in CI flags it as dead.

## Goals

- Reach a definitive, documented decision (implement vs. remove) with acceptance criteria a reviewer can check.
- If removing: `RESTController` no longer exists, is unregistered at `bunnify-frontend.php:55`, and no REST hooks are added by the plugin — verifiable by grepping the tree and by an integration test asserting the three filters have no Bunnify callbacks attached.
- If implementing: `/wp/v2/media` (and any opted-in endpoint) returns CDN-rewritten image URLs when `bunnify_hostname` is set, gated by a public filter defaulting to **off**, with no behavior change when the hostname is empty.
- Either way, remove the never-triggered `try/catch`/`error_log` blocks and the unused `$url_transformer`/`$bunnify_hostname` fields.
- Preserve the existing public filter API used by consumers (`bunnify_url`, `bunnify_args`, etc.) unchanged.

## Non-goals

- Rewriting or extending the frontend rendering path (`image_downsize`, `wp_calculate_image_srcset`, `the_content`) — owned by `ImageController` / `ContentController`.
- Adding new custom REST routes or endpoints of our own.
- Purge/invalidation, upload-time transformation, or writing CDN URLs into stored post/attachment metadata.
- Consolidating the duplicated `init_cdn()` pattern — tracked separately in [[consolidate-cdn-init]] (called out here only because option (a) would otherwise inherit the duplication).

## Current state

`RESTController extends Controller` and is instantiated in the boot array in `bunnify-frontend.php` (`:55`), alongside CDN/Content/Image/Settings controllers. `Application::setup_controllers()` runs on `plugins_loaded` and calls `set_up()` on each (`src/php/Base/Main/Application.php:95-114`).

`RESTController::set_up()` attaches three callbacks (`src/php/Controller/RESTController.php:42-47`):

| Hook | Callback | Behavior today |
| --- | --- | --- |
| `rest_request_before_callbacks` | `should_rest_bunnify_image_downsize` | returns `$response` unchanged (`:64-66`) |
| `rest_after_insert_attachment` | `should_rest_bunnify_image_downsize_insert_attachment` | returns nothing (`:87-88`) |
| `rest_request_after_callbacks` | `cleanup_rest_bunnify_image_downsize` | returns `$response` unchanged (`:103-105`) |

The naming implies an intended design in which a `before_callbacks` filter would toggle Bunnify's `image_downsize` behavior for the duration of a REST request and `after_callbacks` would tear it back down — a guard/cleanup pair around REST-context rewriting. None of that logic was ever written.

The only model the controller references, `Attachment` (`src/php/Model/PostType/Attachment.php`), is a thin read-only wrapper over core functions — `get_attachment_url()` (`:40-45`), `get_metadata()` (`:52-57`), `is_image()` (`:64-66`), `get_dimensions()` (`:104-118`). It is fully usable but currently unused by this controller.

Consumer reality: the caretochange mu-plugin integrates with Bunnify **only** through the `bunnify_url` filter — e.g. `apply_filters( 'bunnify_url', $img[0], $cdn_args )` in `caretochange-core/src/php/Library/ImageRenderer.php:168` — plus the standard frontend `image_downsize`/`srcset` path. A repo-wide grep of caretochange for the REST callbacks (`should_rest_bunnify_*`, `rest_request_before_callbacks` with Bunnify context) returns nothing outside a database dump. **No consumer depends on this controller.**

## Proposed approach

### Recommended — Option (b): remove the REST layer

1. Delete `src/php/Controller/RESTController.php`.
2. Remove the `new \BunnifyFrontend\Controller\RESTController(),` entry at `bunnify-frontend.php:55`.
3. Regenerate the composer classmap/autoloader (the runtime autoloader under `build-tools/vendor` is PSR-4, so no code change is needed, but re-dump to drop any cached classmap entry).
4. Add a short note to the plugin's REST posture in `docs/` (see Rollout) so the absence is intentional and documented, and reserve the hook names in case a future need arises.

Rationale: the plugin's own header describes it as "Lightweight, frontend-only BunnyCDN image delivery." A REST-response rewriter is, by definition, not frontend-only — it changes what the block editor and API clients see. YAGNI applies: there is no current demand, no consumer, and deleting ~110 lines plus three hot-path hook registrations is strictly less surface to maintain, test, and secure.

### Alternative — Option (a): implement genuine REST rewriting

The one concrete, defensible use case is the **block editor media experience**: `GET /wp/v2/media` (and `/wp/v2/media/<id>`) returns `source_url` and `media_details.sizes.*.source_url` built largely from `wp_get_attachment_url()` and stored metadata, which do **not** consistently pass through `ImageController`'s `image_downsize` rewriting in REST context. An editor author inserting an image therefore previews the origin URL, not the Bunny pull-zone URL. Rewriting those fields would make editor previews match production delivery.

If pursued, prefer a single, targeted `rest_prepare_attachment` filter over the current three-hook before/after guard dance:

```php
// Illustrative only.
add_filter( 'rest_prepare_attachment', [ $this, 'rewrite_attachment_links' ], 10, 3 );

public function rewrite_attachment_links( WP_REST_Response $response, WP_Post $post, WP_REST_Request $request ): WP_REST_Response {
    // Off by default; frontend-only plugin opting into an editor convenience.
    if ( ! apply_filters( 'bunnify_rest_rewrite_media', false, $post, $request ) ) {
        return $response;
    }
    if ( Attachment::POST_TYPE !== $post->post_type ) {
        return $response;
    }

    $data = $response->get_data();
    if ( ! empty( $data['source_url'] ) ) {
        $data['source_url'] = apply_filters( 'bunnify_url', $data['source_url'] );
    }
    foreach ( $data['media_details']['sizes'] ?? [] as $name => $size ) {
        if ( ! empty( $size['source_url'] ) ) {
            $data['media_details']['sizes'][ $name ]['source_url'] =
                apply_filters( 'bunnify_url', $size['source_url'], [ 'width' => $size['width'] ?? null ] );
        }
    }
    $response->set_data( $data );

    return $response;
}
```

Key design points if (a) is chosen: reuse the existing `bunnify_url` filter rather than constructing a private `URLTransformer` (avoids inheriting the duplicated `init_cdn()`); gate behind `bunnify_rest_rewrite_media` defaulting to **false**; drop the dead `try/catch` blocks and unused fields; and treat this as a deliberate, documented exception to "frontend-only."

Why we still prefer (b) over (a): rewriting editor previews to a resized CDN URL invites its own problems — the editor may cache a transformed derivative, local-dev/HTTPS mismatches can surface as mixed content, and the origin URL is generally the safer thing for authoring and for tools that round-trip `source_url` back into saved content. The benefit is a cosmetic preview improvement for a single opt-in scenario; the frontend already delivers correctly regardless.

## Migration & backwards compatibility

- **Public filter API:** unchanged under both options. `bunnify_url`, `bunnify_pre_image_url`, `bunnify_pre_args`, `bunnify_skip_for_url`, `bunnify_replace_attachment_srcs`, and the `image_downsize`/`srcset` path all live in other controllers and are untouched. This matters because the caretochange consumer's `ImageRenderer` (`caretochange-core/src/php/Library/ImageRenderer.php:168`) depends on `bunnify_url` and nothing else Bunnify-specific.
- **caretochange impact:** none. The consumer never references the REST callbacks; removing them changes no observable behavior for it. Its `/wp/v2/media` responses already return origin URLs today (the callbacks are no-ops), so removal is behavior-preserving.
- **Third-party stability:** the `should_rest_bunnify_*` method names and the three hook attachments were never functional, so no external integrator could reasonably have depended on their effects. To be safe, the removal note in `docs/` should record that these hooks were withdrawn as no-ops. No deprecation shim is warranted for code that never did anything.
- If (a) is later chosen, it is purely additive (a new opt-in filter, default off) and remains backwards compatible.

## Risks & mitigations

- **Risk:** an unknown downstream relies on the mere presence of the hooks (e.g. `has_filter( 'rest_request_before_callbacks', ... )`). **Mitigation:** these are core WP hooks with many callers; presence of *ours* is not a contract. Document the removal; the change is a no-op behaviorally.
- **Risk (option a):** rewriting `source_url` breaks tools that expect origin URLs, or leaks CDN URLs into saved content. **Mitigation:** default the feature off behind `bunnify_rest_rewrite_media`; scope strictly to `attachment`; never persist — only decorate the response.
- **Risk (option a):** REST runs with `is_admin() === false`, so rewriting here can diverge from `ImageController`'s admin guards. **Mitigation:** route through the same `bunnify_url` filter so one code path governs transformation.
- **Risk:** autoloader/classmap staleness after deleting the file. **Mitigation:** re-dump the runtime autoloader and run the smoke test asserting the plugin boots without `RESTController`.

## Testing strategy

- **Removal (option b):**
  - Static: grep the tree for `RESTController`, `should_rest_bunnify_`, and the three hook strings — assert zero non-doc hits.
  - Unit/integration (PHPUnit + Brain Monkey): after `plugins_loaded`, assert `has_filter( 'rest_request_before_callbacks', ... )` etc. contain no Bunnify callback; assert `Application::setup_controllers()` boots with the trimmed controller list.
  - Regression: existing frontend rewriting tests (`image_downsize`, `bunnify_url`) stay green, proving the removal is isolated.
- **Implementation (option a):**
  - Simulate a `WP_REST_Response` for an attachment; assert `source_url` and each `media_details.sizes.*.source_url` are rewritten to the pull-zone host when `bunnify_hostname` is set and `bunnify_rest_rewrite_media` returns true.
  - Assert **no** rewriting when the hostname is empty, when the filter is default (false), or when `post_type !== attachment`.
  - Assert the `bunnify_url` filter is the single transformation seam (spy on it).

## Rollout plan

1. **Decide & record (this doc):** adopt option (b). Land this blueprint under `docs/blueprints/enhancements/rest-controller-completion/`.
2. **Remove code:** delete `src/php/Controller/RESTController.php`; drop line `bunnify-frontend.php:55`; re-dump the runtime autoloader.
3. **Document the posture:** add a one-line "No REST rewriting (frontend-only)" note to the REST/hooks documentation so the absence is intentional and the withdrawn hook names are on record.
4. **Guard with a test:** add the "no Bunnify REST hooks" assertion so the surface cannot silently return.
5. **Ship** in the next patch release; changelog entry: "Removed non-functional REST controller (no behavior change)."
6. **Escape hatch:** if the block-editor preview use case is ever prioritized, reintroduce as option (a) — a single opt-in `rest_prepare_attachment` filter, default off — reusing the `bunnify_url` seam. That work is additive and does not reopen this decision.

## Effort estimate

**S.** Option (b) is a delete-plus-test change touching two files and an autoloader re-dump; the analysis (consumer has zero dependence) is already done. Option (a), if ever chosen, would be **M** (new opt-in filter, response-shape handling, and REST-context test coverage).
