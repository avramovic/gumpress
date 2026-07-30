# GumPress

Drop-in Gumroad licensing for WordPress plugins and themes.

## What's new in 2.0

GumPress 2.0 is a full rewrite. If you used 1.x, read [MIGRATION.md](MIGRATION.md)
first — this is a clean break, not a compatible upgrade.

- **The product ID is declared once**, at `register()`, and never repeated.
  Every check afterwards is a bare static call: `GumPress::valid()`.
- **`register()`'s 2nd argument is now your Gumroad product_id**, not the
  permalink — Gumroad's real verify API requires `product_id` for any
  product created on or after Jan 9, 2023. The permalink becomes an
  optional, purely cosmetic `permalink` setting (Buy link, license page URL
  slug) — see [gumpress/README.md](gumpress/README.md#registration).
- **Subscriptions are properly supported**: grace periods, cancellation that
  stays valid through the paid period, tiers parsed from Gumroad's
  `variants`, and Gumroad checkout custom fields.
- **A self-hosted `license_check_url` proxy can now push config to the
  plugin**, not just answer verify calls — a whitelisted set of options
  (seat limit, grace periods, admin-UI toggles) can be overridden per
  license without a new release, with a per-key opt-out
  (`lock_config`) for anything you never want a server touching. See
  [Server-controlled overrides](gumpress/README.md#server-controlled-overrides).
- **Config obfuscation is AES-256-CBC + HMAC-SHA256** (`gp1`), replacing the
  old CRC32 scheme, whose checksum could render as anywhere from 1 to 8 hex
  characters while the decoder always read 8 — silently corrupting roughly
  1 in 16 generated configs. Still tamper-*evidence*, not real security —
  see [Obfuscating your config](gumpress/README.md#obfuscating-your-config).
- **Distributed as a `gumpress/` folder**, not a single file — several
  plugins/themes on one site can each bundle their own copy, at different
  versions, without conflicting. A single-file build is still produced for
  anyone who prefers that shape (`dist/GumPress.php` after `composer build`).
- **PHP 8.0+.**
- A long list of correctness and security fixes — see below.

## Quick start

Copy the `gumpress/` folder into your plugin or theme, next to its main file,
then add two lines at the very top of that file:

```php
require_once __DIR__ . '/gumpress/gumpress.php';

GumPress::register(__FILE__, 'your-gumroad-product-id', [
    'payment_grace' => 7,
]);
```

That's it for setup. It registers a License settings page (under
Settings → License for a plugin, Appearance → License for a theme), an admin
notice when the key is missing or invalid, and — if `update_check_url` is
set — a self-hosted update checker.

Enforcement is up to you, same as before:

```php
if (GumPress::valid()) {
    // pro features
}
```

Anywhere else in your module, no ID needed:

```php
GumPress::status();          // machine code, e.g. 'payment_failed_grace'
GumPress::reason();          // translated human message
GumPress::license_key();
GumPress::is_subscription();
GumPress::tier();             // 'Pro' — parsed from Gumroad `variants`
GumPress::has_tier('Pro');
GumPress::meta('Company');    // a Gumroad checkout custom field
GumPress::meta();             // all custom fields, as an assoc array
GumPress::license();          // the full License value object
```

If you have more than one GumPress-licensed product and need to check one
from outside its own directory (a theme template override, a shared
mu-plugin helper), use the explicit form instead of the bare static calls:

```php
GumPress::for('other-product')->valid();
```

See [gumpress/README.md](gumpress/README.md) for the full configuration
reference.

## Repository layout

```
gumpress/           The drop-in library. Copy this folder into your plugin/theme.
tests/              PHPUnit suite + hand-rolled WP stubs — no WordPress or
                    Gumroad account required to run it.
bin/build.php       Lints, then builds both dist/ artifacts. Run it with
                    `composer build`.
bin/lint.php        Portable `php -l` over a directory tree.
encrypt.php         CLI tool to obfuscate a config array for GumPress::register().
```

## Development

```
composer install
composer test     # phpunit
composer lint     # php -l on every source file
composer stan     # phpstan, WordPress-aware stubs
composer build    # produce dist/gumpress/ and dist/GumPress.php
```

Everything above is plain PHP — no shell script, so it all works on Windows
too.

## License

MIT.
