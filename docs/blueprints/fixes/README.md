# Fix prompts — final review pass (2026-07-06)

Findings from a full manual read of every shipped file after the feature work
settled. Each `FIX-0N` file is a **self-contained prompt for an AI session**
(or a human): problem with evidence, root cause, the exact fix, tests to add,
and acceptance criteria. Work them top-down; each is independently shippable.

**None are urgent.** The plugin is correct and production-safe as-is for a
configured install (hostname set, no media offloading). These are polish /
edge-case items deferred deliberately.

| # | Fix | Severity | One-liner |
| --- | --- | --- | --- |
| [FIX-01](FIX-01-bunnify-disable-kill-switch.md) | `BUNNIFY_DISABLE` kill-switch | Medium | readme promises the constant disables the CDN; it only gates 2 of ~14 paths — honour it in `is_enabled()`. |
| [FIX-02](FIX-02-scaled-url-retry.md) | `-scaled` lookup retry is broken | Medium-low | `str_replace('.', '-scaled.', $url)` mangles every dot (`example-scaled.com`) — the fallback never matches. |
| [FIX-03](FIX-03-non-upload-origin-fallback.md) | Size collapse for non-uploads attachments | Low | `get_cdn_url_by_id()`'s `?? $original_url` returns the full-size origin for offloaded/custom-baseurl media. |
| [FIX-04](FIX-04-avif-extension-allowlist.md) | AVIF never rewrites | Low | Two duplicated extension allow-lists, both missing `avif` (WP 6.5+ accepts AVIF uploads). |
| [FIX-05](FIX-05-transform-url-direct-bypass.md) | `transform_url_direct()` bypasses the transformer | Low | Hand-builds the URL, skipping quality, `bunnify_skip_for_url`, `BUNNIFY_DISABLE`, and scheme logic. |
| [FIX-06](FIX-06-minor-cleanups.md) | Minor cleanups batch | Low | Dead duplicate validator, redundant guards, `full`-size srcset edge, uncached metadata call, stale docblock claim. |

## Ground rules for every fix

- `composer check` (PHPCS + PHPStan + PHPUnit) must stay green; add tests with
  each behavioural change.
- **Off-by-default byte-parity holds:** an unconfigured / disabled install's
  output must not change.
- Behaviour changes get a `CHANGELOG.md` entry; new/changed hooks go in
  `docs/HOOKS.md`.
- Re-sync any consumer copies of the plugin after landing (consumers vendor the
  nested `bunnify-frontend/` directory).
- Keep this repo vendor-neutral — no client-specific content.
