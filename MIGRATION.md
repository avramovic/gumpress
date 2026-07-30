# Migrating from GumPress 1.x

GumPress 2.0 is a clean break, not a compatible upgrade. There is no shim for
`GumPress::for($id)->is_valid_license()` or any other 1.x method name.

## Why

1.x's call syntax repeated the product ID at every check, several of its
default behaviours were actively harmful (a default `max_uses => 1` that
invalidates a customer's production license the moment they clone to
staging; a validity check that made refunds and subscription states
unreachable dead code), and it has PHP 8 fatals in the subscription grace
path. Patching all of that onto the 1.x shape wasn't worth it — see the
project README for the full rewrite rationale.

## What to change

1. **Replace the file.** Delete your old `GumPress.php`, add the `gumpress/`
   folder instead.

2. **Update the integration lines** at the top of your main plugin file /
   theme `functions.php`. Note the second argument's meaning changed within
   2.0 itself, before any 1.x install would have adopted it: it's now your
   Gumroad **product_id** (the opaque string on your Gumroad product
   dashboard), not a permalink — Gumroad's real verify API requires
   `product_id` for any product created on or after Jan 9, 2023. If you
   still need the permalink (for the license page's Buy link, for example),
   pass it as the new `permalink` option instead.

   ```php
   // before
   if (!class_exists('GumPress')) {
       require_once dirname(__FILE__) . '/GumPress.php';
   }
   GumPress::register(__FILE__, 'YOUR_GUMROAD_ID');

   // after
   require_once __DIR__ . '/gumpress/gumpress.php';
   GumPress::register(__FILE__, 'YOUR_GUMROAD_PRODUCT_ID', [
       'permalink' => 'your-gumroad-permalink', // optional — Buy link + license page URL slug
   ]);
   ```

3. **Update every call site.** The product ID no longer needs to be repeated:

   ```php
   // before
   if (GumPress::for('YOUR_GUMROAD_ID')->is_valid_license()) { ... }
   GumPress::for('YOUR_GUMROAD_ID')->license_description();

   // after
   if (GumPress::valid()) { ... }
   GumPress::reason();
   ```

4. **Re-check your config options.** Some names and defaults changed:

   | 1.x | 2.0 |
   |---|---|
   | `grace_period` | `payment_grace` |
   | `max_uses` defaulted to `1` | defaults to `0` (disabled) — see the seat-limiting section in `gumpress/README.md` before turning it back on |
   | `cache_time` (accepted but ignored) | removed; caching is now handled internally with sane, non-configurable TTLs |
   | — | `offline_grace` / `offline_policy` (new: previously-valid licenses survive a license-server outage) |
   | — | `lock_config` / `configurator_url` (new — see [Server-controlled overrides](gumpress/README.md#server-controlled-overrides)) |
   | `deny_update_without_license` (declared, never actually read by anything) | removed outright |

5. **If you obfuscated your config with `encrypt.php`** (now `bin/encrypt.php`), re-run it. The
   sealed format changed from CRC32 (readable at rest, and prone to a
   truncation bug that silently corrupted about 1 in 16 generated configs)
   to AES-256-CBC + HMAC-SHA256 (`gp1`). There is no compatibility path — a
   1.x- or 2.0-pre-`gp1`-sealed blob will fail its tamper check and be
   ignored, falling back to defaults. Nobody using GumPress in production
   was relying on the old format; if you were, re-seal your config before
   upgrading.

6. **If you ship an update server**, its response for a **theme** must now be
   the plain decoded array WordPress's `themes_api` filter expects directly
   (1.x silently returned `null` here due to a bug — theme self-updates never
   actually worked). Plugin responses now read `package` first, falling back
   to `download_url`. It can also include a top-level `notice` object
   (`{"text": "...", "url": "...", "level": "warning"}`) to explain a denied
   update in the WP admin, and it now receives `site_url` on every verify
   call (see [Server-controlled overrides](gumpress/README.md#server-controlled-overrides)),
   so per-domain entitlement no longer has to be inferred from the User-Agent.

## If you maintain more than one plugin/theme with GumPress bundled

Only one of them can define the global `GumPress` class — whichever loads
first. If some of your products still bundle 1.x while others bundle 2.0 on
the same site, you'll get an admin notice, but you should not rely on that
crossover working correctly. **Move every product you maintain to 2.0 in the
same release cycle.** Products from other authors bundling their own
(possibly 1.x) copy of GumPress are unaffected either way, unless they
happen to load before yours and win the class name — in that case you'll
see the same admin notice, and both products should update.
