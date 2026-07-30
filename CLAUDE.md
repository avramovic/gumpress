# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this is

GumPress: a drop-in Gumroad licensing library for WordPress plugins/themes, distributed
as source (`gumpress/` folder, copied into a host plugin/theme) rather than installed via
Composer at runtime. It is currently 2.0, a clean-break rewrite of a 1.x that shipped as a
single file — see `README.md` for the pitch and `MIGRATION.md` for the full list of behavior
changes from 1.x (this matters when reading old code/issues: option names, defaults, and the
`register()` signature all changed).

## Commands

```
composer install
composer test    # phpunit — tests/, bootstrap.php loads hand-rolled WP stubs, no real WordPress needed
composer lint     # php -l on every file under gumpress/
composer stan     # phpstan level 5 with WordPress stubs, gumpress/src only

vendor/bin/phpunit --filter TestName    # run a single test
vendor/bin/phpunit tests/ValidatorTest.php

./build.sh              # produce dist/gumpress/ (namespaced drop-in) and dist/GumPress.php (single-file)
./build.sh --obfuscate   # also produce dist-obfuscated/GumPress.php (requires yakpro-po + sibling ../phpz checkout)
php encrypt.php <gumroad-product-id> '<json-config>'   # produce a sealed "gp1" config blob for register()
```

There is no build step required to use the library from source — `dist/` is only for
distributing to end users. When testing changes, run against `gumpress/`, not `dist/`.

## Architecture

**Everything lives under `gumpress/src/` in namespace `GumPress\V2`.** `bin/build.php`
rewrites that namespace to a version-suffixed one (`GumPress\v2_0_0`) when producing `dist/`,
and also concatenates all of `src/` into the single-file `dist/GumPress.php` in a fixed
require order (see the `$order` array in `bin/build.php`) — if you add a new class, add it
there too, in dependency order.

**Multi-copy coexistence is the central design constraint.** Several plugins/themes on one
WordPress site can each bundle their own copy of GumPress, at different versions. This shapes
the whole class structure:

- `gumpress/gumpress.php` defines the single global `GumPress` facade class, guarded by
  `class_exists('GumPress', false)` — only the *first*-loaded copy's facade wins, but every
  copy still loads and runs its **own** versioned engine (`GumPress\V2\Engine`, registered
  into `$GLOBALS['gumpress']['sources'][$dir]`). There is no "one engine wins" arbitration.
  This file is meant to stay frozen/minimal across releases forever, since it's the one class
  every installed version shares.
- `gumpress/src/load.php` requires every engine class, guarded by
  `class_exists(__NAMESPACE__ . '\Engine', false)` so two plugins bundling the *same*
  GumPress version don't fatal on duplicate class declarations.
- `GumPress::register($file, $product_id, $options)` resolves which bundled copy's engine to
  invoke by walking up from `$file`'s directory (`owning_source_dir()`), and returns a
  `Module` (or a `NullModule` on any failure — see below). Every later bare static call
  (`GumPress::valid()`, etc.) is dispatched via `__callStatic`, which resolves the calling
  module from a `debug_backtrace()` walk matched against each `Module`'s `owned_dirs()`
  (memoized per caller file in `resolve_caller()`). Code outside a module's own directory
  (shared helpers, theme template overrides) must use the explicit `GumPress::for($product_id)`
  escape hatch instead.
- **A licensing library must never fatal.** `NullModule` is the safe stand-in returned
  whenever registration or caller-resolution fails; it always reports not-licensed and
  swallows unknown method calls via `__call`, so a broken engine can never crash an unrelated
  plugin on the same site.

**Request flow through the engine, in dependency order** (mirrors `src/load.php`):

1. `Engine::create()` — decodes an encrypted config blob if `register()` was passed a sealed
   string (see `Config::decode_encrypted`), builds a `Config`, warns (once, non-production
   only) if the config is unsealed and non-default, then builds the `Module`.
2. `Config` — all defaults live here once (`DEFAULTS` const). Holds both the sealed-config
   codec (AES-256-CBC + HMAC-SHA256, prefix `gp1`) and `is_white_label()` (derived from
   whether `license_check_url`'s host contains `gumpress`, not a config flag).
3. `Module` — one instance per registered product, created once and living for the rest of
   the request (unlike 1.x, which reconstructed state on every call). Owns the module's
   `base_config()` (compiled-in) vs. `config()` (base + server-pushed `Overrides` applied,
   lazily built and memoized — see `forget_effective_config()`). Delegates network/caching to
   `Api`, validity logic to `Validator`, and human-readable reasons to `Strings`.
4. `Api` — the only class that does network I/O (`wp_remote_post`/`wp_remote_get`) and reads
   Gumroad's `/v2/licenses/verify`. **Verification is never triggered from `valid()`/`status()`
   themselves** — only from a throttled `admin_init` hook or a twice-daily cron event
   (`Module::boot_hooks()`), each guarded by a transient lock and a stored backoff
   (`due()`/`ensure_scheduled()`). Caches both success *and* failure responses (a 1.x bug
   cached only HTTP 200, making an invalid key trigger a blocking HTTP request on every admin
   page load). `should_increment()` decides whether a verify call counts as a real seat
   activation, using `Env` to avoid burning a seat on local/staging/WP-CLI environments.
5. `Validator::evaluate()` — the validity state machine: **ordered, independent guard
   clauses** (test key → refunded → chargebacked → disputed → subscription state → seat
   limit → valid), each returning early. This ordering is deliberate and is "the actual
   product" per its own docblock — a 1.x bug had seat-limit checked first with a truthy
   default, making every guard below it (refunds, disputes, subscription state) unreachable
   dead code. Gumroad's `success: true` does not imply entitlement; it's `true` for refunded/
   disputed/ended subscriptions too. Offline behavior (`evaluate_offline`) is a separate path
   keyed on `offline_policy`/`offline_grace`, driven by the last confirmed-valid timestamp
   rather than fresh network I/O.
6. `Overrides::apply()` — layers a verify response's `gumpress.config` object on top of the
   integrator's own config. Hard whitelist (`OVERRIDABLE` const) + the integrator's own
   `lock_config` opt-out + per-key type-checking/clamping in `sanitize()`. A fixed set of keys
   (`license_check_url`, `proxy_fallback`, `type`, `text_domain`, `permalink`, `callbacks`,
   `_encrypted`) can never be overridden regardless of whitelist/lock_config, because a
   response that could rewrite `license_check_url` would be permanently unrecoverable and
   would persist even while offline.
7. `Status`/`Strings` — `Status` is a plain code + context value object, deliberately never
   holding a translated string (translating too early trips WP 6.7+'s early-translation-
   loading notice); `Strings::reason()` is the only place that calls `__()`/`_n()`, and only
   ever from admin-hooked code paths.
8. `Admin` / `Updater` — WordPress integration glue (settings page, admin notices, plugin
   list link, `plugins_api`/`themes_api` + update transient filters for a self-hosted
   `update_check_url`). All read/write via `Module`/`Api`/`Config`, never touch Gumroad
   directly.

**Testing**: `tests/bootstrap.php` loads `tests/stubs.php` (hand-rolled WordPress function
stubs — no real WordPress or Gumroad account needed) plus the subset of `gumpress/src/*.php`
that doesn't need a real `Module` (no `Admin`/`Api`/`Updater`/`Engine`'s `Module`-dependent
path). Keep new pure-logic classes (state machines, value objects, codecs) testable this way;
anything that needs a real `Module` instance is presently outside unit-test coverage.

**`GumPress::for('id')` / `product_id`**: since GumPress 2.0, the second argument to
`register()` is the Gumroad **product_id** (opaque dashboard string), not the permalink —
Gumroad's real verify API requires `product_id` for products created on/after Jan 9, 2023.
The permalink is now a separate, purely cosmetic `permalink` option (Buy link + license page
URL slug only) and is never sent to any verify/update endpoint. `encrypt.php`'s CLI help text
still calls its first argument "permalink" — that's stale wording from before this change;
the value it actually seals under is the product_id, and it must match whatever `register()`
is called with for `Config::decode_encrypted()`'s key derivation to succeed.