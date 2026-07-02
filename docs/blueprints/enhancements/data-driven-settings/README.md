# Data-driven settings & Settings API refactor

- **Status:** Proposed
- **Created:** 2026-07-01
- **Owner:** _unassigned_
- **Related:** [[centralize-cdn-config]] (the effective-enabled / hostname resolution this blueprint depends on), [[base-phpcs-boundary]] (where framework-vs-plugin lint boundaries are decided)

> **Update (2026-07-02):** rollout step 5 landed ahead of this blueprint —
> `bunnify_enabled` is now read at runtime via `SettingsController::is_enabled()`
> (missing option = enabled, per the backward-compatible default below). The
> schema refactor, sanitization, and derived category list (steps 1–4, 6)
> remain open, and references below to the option being "never read" describe
> the pre-fix state.
>
> **Second update (same day):** the initial wiring treated a stored `''` as
> disabled, which violated this blueprint's own backward-compat rule —
> `options.php` writes `''` for a whitelisted checkbox absent from POST, so
> every pre-switch settings save left `''` behind without the admin choosing
> anything. `is_enabled()` now treats `''` (and a missing option) as enabled;
> a deliberate disable stores an explicit `'0'` via a hidden field that now
> accompanies the checkbox, and the checkbox renders the *effective* state so
> legacy installs self-heal to `'1'` on save. The schema refactor must
> preserve exactly these semantics.

## Summary
`SettingsController` hand-writes ~11 near-identical `add_settings_field` + `*_field_callback` pairs and registers every option with no `sanitize_callback`, so the class is ~200 lines of copy-paste that grows linearly with each new toggle. This blueprint proposes replacing that with a single **data-driven schema** — an array of field definitions that drives generic `register`, `render`, and `sanitize` callbacks — and using the same schema to derive the debug-category list that is currently hardcoded in two places. It also resolves the `bunnify_enabled` option, which today is registered, rendered, and deleted on uninstall but **never read at runtime**, by wiring it up as a real master switch (with a backward-compatible default) rather than leaving a dead toggle in the UI.

## Motivation / Problem
The controller has three concrete, measurable problems today.

1. **Duplication that scales with every setting.** Each option needs a `register_setting` line (`src/php/Controller/SettingsController.php:52-64`), an `add_settings_field` registration (`:73-167`), and a bespoke `*_field_callback` method (`:187-346`). Eight of the eleven callbacks are byte-for-byte identical apart from the option name and description string — a checkbox, `checked()`, and a `<p class="description">`. Adding one debug flag currently means editing four separate regions of the file.

2. **No input sanitization.** All eleven `register_setting` calls omit the third `$args` argument, so **not one option has a `sanitize_callback`, `type`, or `default`** (`:52-64`). `bunnify_hostname` is stored verbatim from a raw text input (`:198-205`); `bunnify_debug_refreshes` advertises a `1-100` range in the UI (`:224`) but nothing enforces it server-side, so any integer (or non-integer) that reaches `update_option` is persisted and later consumed by the log-rotation logic in `DebugTrait` (`src/php/Base/Traits/DebugTrait.php:141`).

3. **A dead toggle and a duplicated category list.** `bunnify_enabled` is registered (`:52`), rendered (`:73-79`, `:187-193`), and deleted on uninstall (`../bunnify-frontend/uninstall.php:25`), but no runtime code path ever calls `get_option('bunnify_enabled')` — the only read is the checkbox rendering its own state (`:188`). The plugin's *actual* enable gate is "is `bunnify_hostname` non-empty", checked independently in `URLTransformer.php:394`/`:444`, `CDNController.php:57`, and `ContentController.php:73`. Separately, the debug-category list is hardcoded in `get_enabled_debug_categories()` (`:317-325`) and re-encoded implicitly by the per-category `register_setting` calls, so the two can drift.

## Goals
- Replace the eleven `*_field_callback` methods and their `add_settings_field`/`register_setting` calls with a single schema array plus one generic `render_field` and one generic `sanitize_field`.
- Give **every** option an explicit `sanitize_callback`, `type`, and `default` via the `register_setting` args array, verifiable by asserting a bad value in is coerced to a safe value out.
- Derive the debug-category list (`get_enabled_debug_categories()`) from the schema so there is a single source of truth; `is_debug_enabled_for_category()` keeps its current signature and return contract.
- Resolve `bunnify_enabled`: either wire it into the effective-enabled decision or remove it — with a decision recorded here (recommendation: **wire it up**, see Proposed approach) and zero behaviour change for existing installs.
- Keep all option names, the settings group (`bunnify_frontend_options`), the page slug (`bunnify-frontend`), and section IDs byte-identical so no DB migration or consumer breakage is required.
- Net reduction of the controller by roughly 150 lines while keeping it inside the phpcs-linted plugin tree (not `src/php/Base`, which is excluded).

## Non-goals
- No change to the public filter API in `docs/HOOKS.md` (`bunnify_url`, `bunnify_pre_args`, `bunnify_skip_for_url`, `bunnify_local_dev_mode_check`, etc.). The consumer wires only filters (`bunnify_allow_non_upload_url`, `bunnify_skip_image`).
- No redesign of the admin page chrome, the debug-log viewer card (`:394-411`), or the "Test Configuration" card (`:413-429`).
- No move to a React/`@wordpress/components` settings screen — this stays on the classic Settings API.
- No consolidation of the eleven discrete options into one serialized option array (that is a larger, migration-heavy change; out of scope here).
- Not the CDN-config-accessor extraction itself — that is the sibling [[centralize-cdn-config]] blueprint; this doc only *consumes* whatever effective-enabled accessor it lands.

## Current state
`set_up()` hooks `admin_menu` -> `add_admin_menu()` and `admin_init` -> `init_settings()` (`:29-32`). `init_settings()` (`:51-168`) does three things in sequence: eleven `register_setting` calls (no args), two `add_settings_section` calls (`:66-71`, `:98-103`), and eleven `add_settings_field` calls each pointing at a dedicated callback. The callbacks (`:187-346`) fall into three shapes: one text input (`hostname`, `:198-205`), one number input (`debug_refreshes`, `:221-227`), and nine checkboxes (`enabled`, `local_dev_mode`, and seven debug flags). The page renders via `do_settings_sections('bunnify-frontend')` inside a standard `options.php` form (`:386-392`).

Two static helpers sit alongside the render code: `is_debug_enabled_for_category()` (`:301-310`, global-gate-then-category-gate) and `get_enabled_debug_categories()` (`:317-335`, hardcoded six-element list). Uninstall enumerates all eleven option names to delete (`../bunnify-frontend/uninstall.php:23-35`). The base `Controller` already carries a `Config` instance (`src/php/Base/Main/Controller.php:24`, `get_config()`), which is a candidate home for the schema if we want it shared, though a plugin-local static method is simpler.

## Proposed approach
Introduce a **schema** — an ordered list of field definitions plus a small sections map — and three generic methods that consume it. Keep it in the plugin tree (e.g. a `SettingsSchema` provider in `src/php/Library/`, or a private static on `SettingsController`) so it stays within phpcs coverage; `src/php/Base` is deliberately excluded from linting and is the wrong home for plugin-specific config.

A field definition is a plain array. Option names are the array keys, so they are impossible to drift from the registered setting:

```php
// Illustrative only.
private function get_sections(): array {
    return [
        'main'  => [ 'title' => 'BunnyCDN Configuration', 'blurb' => '...' ],
        'debug' => [ 'title' => 'Debug Logging',          'blurb' => '...' ],
    ];
}

private function get_fields(): array {
    return [
        'bunnify_hostname' => [
            'section'  => 'main',
            'label'    => 'BunnyCDN Hostname',
            'type'     => 'text',
            'default'  => '',
            'sanitize' => 'hostname',                    // strategy key, resolved below
            'desc'     => 'Your BunnyCDN hostname (e.g., cdn.example.com).',
            'attrs'    => [ 'class' => 'regular-text' ],
        ],
        'bunnify_debug_refreshes' => [
            'section'  => 'debug',
            'label'    => 'Debug Refreshes to Keep',
            'type'     => 'number',
            'default'  => 10,
            'sanitize' => 'int_range',
            'min'      => 1, 'max' => 100,
            'desc'     => 'Number of page refreshes to keep in the debug log (1-100).',
        ],
        'bunnify_debug_url_transformation' => [
            'section'        => 'debug',
            'label'          => 'URL Transformation',
            'type'           => 'checkbox',
            'default'        => 0,
            'sanitize'       => 'bool',
            'debug_category' => 'url_transformation', // drives get_enabled_debug_categories()
            'desc'           => 'Enable logging for URL transformation logic.',
        ],
        // ... remaining debug flags, local_dev_mode, enabled ...
    ];
}
```

`init_settings()` collapses to two loops:

```php
public function init_settings(): void {
    foreach ( $this->get_sections() as $id => $s ) {
        add_settings_section(
            "bunnify_frontend_{$id}_section",
            $s['title'],
            fn() => printf( '<p>%s</p>', esc_html( $s['blurb'] ) ),
            'bunnify-frontend'
        );
    }

    foreach ( $this->get_fields() as $name => $field ) {
        register_setting( 'bunnify_frontend_options', $name, [
            'type'              => $field['type'] === 'checkbox' ? 'boolean' : ( $field['type'] === 'number' ? 'integer' : 'string' ),
            'default'           => $field['default'],
            'sanitize_callback' => fn( $value ) => $this->sanitize_field( $field, $value ),
            'show_in_rest'      => false,
        ] );

        add_settings_field(
            "{$name}_field",
            $field['label'],
            [ $this, 'render_field' ],
            'bunnify-frontend',
            "bunnify_frontend_{$field['section']}_section",
            $field + [ 'name' => $name ]   // passed straight into render_field's $args
        );
    }
}
```

`render_field( array $args )` switches on `type` and emits one of three small partials (text / number / checkbox), reusing `checked()`, `esc_attr()`, and the description block. `sanitize_field( array $field, $value )` resolves the `sanitize` strategy to a concrete coercion — this is where the currently-missing validation lives:

- `bool` -> `empty( $value ) ? 0 : 1` (explicit `0` when a checkbox is absent from POST).
- `int_range` -> `min( max( (int) $value, $field['min'] ), $field['max'] )`.
- `hostname` -> `sanitize_text_field`, then strip scheme and trailing slash so downstream `URLTransformer` gets a bare host (matching what `URLTransformer.php:394` expects today).
- `text` -> `sanitize_text_field`.

`get_enabled_debug_categories()` (`:317-335`) is rewritten to derive its list from the schema — `array_column`-style over fields carrying a `debug_category` key — eliminating the hardcoded array. `is_debug_enabled_for_category()` (`:301-310`) is unchanged in signature and behaviour.

**Extensibility hook.** Wrap the field list in a filter, e.g. `apply_filters( 'bunnify_settings_fields', $fields )`, so downstream plugins can add or hide toggles without touching core. This is additive and does not alter existing filters.

**`bunnify_enabled` — recommendation: wire it up, do not remove.** It already exists in production databases and in the uninstall list, so deleting it is itself a (small) migration with no user-facing upside, and users reasonably expect a checkbox labelled "Enable BunnyCDN" to do something. Wire it into the effective-enabled decision that the sibling [[centralize-cdn-config]] blueprint centralizes:

```php
// Conceptually, wherever effective-enabled is resolved:
$enabled = get_option( 'bunnify_enabled', true ) !== false // treat unset/legacy as ON
        && '' !== (string) get_option( 'bunnify_hostname', '' );
```

The critical subtlety is the default: because the option is unread today, many installs have it stored as `''`/`0` or absent while CDN rewriting works purely off the hostname. If we naively did `enabled = bunnify_enabled AND hostname`, every one of those sites would silently turn off. So the resolution must **treat absent/legacy values as enabled** and only honour an explicit "off", which preserves today's behaviour for all existing installs while giving new users a working master switch. If the team prefers the smaller footprint, the fallback is to delete `bunnify_enabled` from the schema and from `uninstall.php` — but that discards a genuinely useful "pause the CDN without clearing my hostname" control, so wiring it up is the recommendation.

## Migration & backwards compatibility
- **No DB migration.** Option names, defaults semantics, the `bunnify_frontend_options` group, the `bunnify-frontend` page slug, and section IDs are preserved. Existing stored values continue to load. (Section HTML IDs stay `bunnify_frontend_main_section` / `bunnify_frontend_debug_section` — verify the generated IDs match the current literals at `:67`/`:99` so any CSS/anchors keep working.)
- **Consumer (`caretochange`) impact: none.** The consumer's `PluginBunnifyExtController` hooks only the public filters `bunnify_allow_non_upload_url` and `bunnify_skip_image`; it never reads `bunnify_enabled` or any settings option directly (grep across `wp-content/mu-plugins` returns no option reads). The filter API in `docs/HOOKS.md` is untouched.
- **Public filter stability.** Existing filters are unchanged. The one *new* filter (`bunnify_settings_fields`) is additive and optional.
- **`bunnify_enabled` behaviour.** Under the recommended wiring, existing installs see no change because unset/legacy values resolve to "enabled". Only a user who explicitly unticks the box after this ships will see rewriting pause — which is the intended, documented behaviour. Document this in `docs/HOOKS.md`/settings help and the plugin changelog.
- **Sanitization is now enforced.** Values that were previously accepted raw (an out-of-range `debug_refreshes`, a hostname with a scheme) will be normalized on next save. This is a behaviour change only for already-invalid stored data and only takes effect when the user re-saves; it cannot corrupt valid existing values.

## Risks & mitigations
- **Checkbox-absent-from-POST sanitize gap.** On the `options.php` flow, an unchecked box submits no key; ensure the `bool` strategy coerces missing/empty to explicit `0` and confirm WordPress still runs the registered `sanitize_callback` for that option in the group. Mitigation: unit test the "unchecked" path; if WP skips absent keys, fall back to normalizing in an `pre_update_option_{$name}` or a single group-level pass.
- **Silently disabling live sites via `bunnify_enabled`.** Covered above — the `get_option('bunnify_enabled', true) !== false` default is the guard. Mitigation: an explicit test asserting "hostname set + option absent => enabled".
- **Generated section/field IDs drift from the current literals**, breaking deep links or bespoke CSS. Mitigation: derive IDs from the same string constants and assert equality against the current values in a test.
- **Schema placed in `src/php/Base`** would fall outside phpcs coverage and outside the plugin's own quality gate. Mitigation: keep the schema and generic callbacks in `src/php/Controller` or `src/php/Library`.
- **Over-abstraction.** A schema that grows renderer plugins/closures for three input types would be heavier than the duplication it replaces. Mitigation: cap `render_field` at the three concrete types in use; add types only when a real field needs one.

## Testing strategy
- **Unit (PHPUnit 9 + Brain Monkey).** Assert `init_settings()` calls `register_setting`/`add_settings_section`/`add_settings_field` once per schema entry with the expected names (Brain Monkey `expect()` on the WP functions). Assert `sanitize_field` coercions: `int_range` clamps `0`->`1` and `500`->`100`; `bool` maps `'on'`/`'1'`/`null` correctly; `hostname` strips `https://` and trailing `/`.
- **Schema/derived-list parity.** Assert `get_enabled_debug_categories()` returns exactly the schema's `debug_category` values, and that every registered debug option has a matching schema entry (guards drift).
- **`bunnify_enabled` resolution matrix.** Table test over {option absent, `0`, `1`} x {hostname empty, set} asserting the effective-enabled result, with the "absent + hostname set => enabled" backward-compat case called out.
- **Rendering smoke test.** Capture `render_field` output for each type and assert the input `name`, `checked`/`value`, and description are present and escaped.
- **Static analysis.** Keep PHPStan level 5 green (the schema arrays are simple; add array shapes or a small `@phpstan-type` if the analyzer complains) and phpcs/WPCS clean on the refactored controller.

## Rollout plan
1. **Introduce the schema behind the existing behaviour.** Add `get_sections()`/`get_fields()` and the generic `render_field`/`sanitize_field`, but do not yet delete the old methods — land the schema and its unit tests first.
2. **Cut `init_settings()` over to the loops**, migrating one section at a time (main, then debug) and diffing the rendered admin HTML before/after to prove parity. Remove the per-field callbacks as each is covered.
3. **Add `sanitize_callback`/`type`/`default`** via the schema-driven `register_setting` args and land the sanitization tests.
4. **Derive `get_enabled_debug_categories()` from the schema**; delete the hardcoded list.
5. **Wire `bunnify_enabled`** into the effective-enabled accessor from [[centralize-cdn-config]] with the backward-compatible default; document the behaviour change in `docs/HOOKS.md` and the changelog.
6. **Add the `bunnify_settings_fields` filter** and document it. Optional/last.

Each step is independently shippable and reversible.

## Effort estimate
**M.** The schema + generic callbacks are mechanical and net-negative on line count, but doing it safely — enforcing sanitization without regressing saved values, deriving the category list, and wiring `bunnify_enabled` with a backward-compatible default plus the tests that prove no live site flips off — is what pushes it past a trivial S.
