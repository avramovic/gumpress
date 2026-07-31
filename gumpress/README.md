# GumPress — configuration reference

## Registration

```php
require_once __DIR__ . '/gumpress/gumpress.php';

GumPress::register(__FILE__, 'your-gumroad-product-id', $options);
```

- `__FILE__` must be your plugin's main file, or your theme's `functions.php`.
- The second argument is your product's Gumroad **product_id** — the opaque,
  base64-looking string on your Gumroad product's dashboard (**not** the
  human-readable permalink in its public URL). Gumroad's real verify API
  requires `product_id`, not `product_permalink`, for any product created on
  or after **January 9, 2023** — see the `permalink` option below if you
  still need the permalink for anything.
- `$options` is either the array below, or a string produced by `bin/encrypt.php`
  (see [Obfuscating your config](#obfuscating-your-config)).

Type (`plugin` vs `theme`) is auto-detected from where `__FILE__` lives;
set `type` explicitly only if you have an unusual layout.

## Options

| Key | Default | Meaning |
|---|---|---|
| `type` | auto-detected | `'plugin'` or `'theme'`. |
| `text_domain` | your module's slug | Text domain used to translate GumPress's own UI strings. |
| `disallow_test_keys` | `false` | Reject Gumroad test-mode purchases. |
| `payment_grace` | `7` | Days a subscription stays valid after a failed payment. |
| `max_uses` | `0` (disabled) | Seat limit. **Read this before changing it** — see [Seat limiting](#seat-limiting). |
| `max_uses_policy` | `'block'` | `'block'` invalidates the license when over the limit; `'warn'` only shows a notice. |
| `license_check_url` | Gumroad's API | Point this at your own proxy to add server-side seat enforcement, custom entitlements, etc. |
| `proxy_fallback` | `false` | If your custom `license_check_url` is unreachable, fall back to Gumroad directly. Off by default because a proxy is usually doing enforcement Gumroad-direct can't. |
| `offline_grace` | `14` | Days a previously-valid license stays valid while the license server is unreachable. |
| `offline_policy` | `'grace'` | `'grace'` (above), `'closed'` (never grant offline grace), or `'open'` (always valid when unreachable). |
| `update_check_url` | `null` | Your self-hosted update server. When unset, no updater is registered. |
| `permalink` | `null` | Your product's human-readable Gumroad permalink (`gum.co/...` or `yourname.gumroad.com/l/...`) — cosmetic only, never sent for verification. Powers the license page's "Buy" link (hidden without it — a product_id isn't a valid purchase URL) and the license page's own URL slug (`?page=gumpress-{permalink}`; without it, a short hash of your product_id instead). |
| `lock_config` | `[]` | List of option keys your own `license_check_url` server is never allowed to override, even if it tries — see [Server-controlled overrides](#server-controlled-overrides). |
| `configurator_url` | GumPress's own configurator | Where the non-production unsealed-config notice links to — see [Obfuscating your config](#obfuscating-your-config). |
| `plugins_page_link` | `true` | Add a "License" link on the Plugins list row. |
| `hide_menu_page` | `false` | Don't show a Settings/Appearance menu item for the license page. The page stays reachable at its URL — useful with several GumPress modules on one site, where `plugins_page_link` already gives each one a "License" link on its Plugins list row. |
| `suppress_notices` | `false` | Disable all admin notices. |
| `suppress_key_notice` | `false` | Disable only the "no key entered yet" notice. |
| `hide_owner_email` | `false` | Don't show the purchaser's email on the license page. |
| `hide_custom_fields` | `false` | Don't show Gumroad checkout custom fields on the license page. |
| `license_page_title` | module name + "License" | License settings page `<title>`. |
| `license_page_menu` | module name + "License" | License settings page menu label. |
| `callbacks.license_page_top` | — | `callable(Module $module): void`, rendered above the license form. |
| `callbacks.license_page_bottom` | — | `callable(Module $module): void`, rendered below the status table (replaces the default "Buy" button). |

## Status codes

`GumPress::status()->code()` (or the string `GumPress::status()->code()` from
outside — `status()` returns a `Status` object) is one of:

| Code | Valid? | Meaning |
|---|---|---|
| `no_key` | no | Nothing entered yet. |
| `unverified` | no | Never successfully checked and currently unreachable — a fresh install shouldn't be bricked by a bad DNS day. |
| `valid_offline` | **yes** | Verified previously; server unreachable now, still within `offline_grace`. |
| `unverifiable` | no | Unreachable for longer than `offline_grace`. |
| `invalid_key` | no | The server said the key doesn't exist / isn't valid for this product. |
| `test_key` | no | A Gumroad test-mode purchase, and `disallow_test_keys` is on. |
| `refunded` / `chargebacked` / `disputed` | no | Self-explanatory. `disputed` doesn't apply if the dispute was won. |
| `subscription_ended` | no | The subscription's paid period has actually ended. |
| `cancelled_pending_end` | **yes** | Cancelled, but the customer already paid through the current period. |
| `payment_failed_grace` | **yes** | A subscription payment failed; still inside `payment_grace`. |
| `payment_failed` | no | Payment failed and the grace period elapsed. |
| `seat_limit` | depends on `max_uses_policy` | Over the configured `max_uses`. |
| `valid` | yes | Everything checks out. |
| `unknown` | no | Not a licensing verdict — GumPress couldn't tell which module a bare static call belonged to. Use `GumPress::for($id)` instead. |

`Gumroad returns `success: true` for refunded, disputed, and ended
subscriptions alike — it does not enforce entitlement.` The status codes
above are what actually decides access.

## Seat limiting

Gumroad's `uses` field on a license counts **verification calls**, not active
installs — it never decrements, and there's no way to release a seat without
a seller access token (which a distributed plugin can't hold). `max_uses`
defaults to **disabled** (`0`) because a naive seat count breaks real
workflows: cloning production to staging, restoring a database backup, or a
`search-replace` on the domain all look like a "new site" and would push
`uses` past the limit — locking out a paying customer. If you do set a limit,
being over it invalidates the license by default (`max_uses_policy` is
`'block'`) — set it to `'warn'` if you'd rather just show a notice.

GumPress avoids re-incrementing for the same (license key, site) pair, and
never increments outside of what looks like a production environment
(`wp_get_environment_type()`, `.local`/`.test` hosts, `dev./staging./stage.`
subdomains, RFC1918 addresses, WP-CLI). But none of that survives a fresh
database. If you need real seat enforcement, do it in a self-hosted
`license_check_url` proxy that holds your Gumroad seller token and tracks
seats server-side — `max_uses` against Gumroad directly is advisory only.
`uses` on the response then means *your* proxy's active-seat count, not
Gumroad's verification counter.

A proxy that reports its own seat model in the response takes over entirely:
if `gumpress.seats` carries a `limit` (or `unlimited`), GumPress defers to
your server's decision and stops applying `max_uses` at all — including a
`max_uses` pushed via [Server-controlled overrides](#server-controlled-overrides).
Your server already knows the real count and the real limit, and both can
change after a plugin ships; a stale compiled-in cap second-guessing it is
how a paying customer gets locked out of a seat they're entitled to.
`max_uses` therefore only ever bites when nothing is reporting seats — i.e.
Gumroad direct, or a proxy that omits the block. See
[Seats reported by your server](#seats-reported-by-your-server).

## Server-controlled overrides

A `license_check_url` response can carry a top-level `gumpress` object. Its
`config` key is a set of option values that override whatever this plugin
was compiled with, applied for the rest of that request and cached alongside
the rest of the verify response — including while offline, so a denied site
still sees an accurate reason instead of a bare "unreachable":

```json
{
  "success": true,
  "uses": 2,
  "purchase": { "...": "..." },
  "gumpress": {
    "config": { "max_uses": 3, "max_uses_policy": "block" },
    "recheck_in": 3600
  }
}
```

Note the `max_uses` in that example only takes effect if the same response
carries no `gumpress.seats` block — see
[Seats reported by your server](#seats-reported-by-your-server). If your
server tracks seats, report them there instead of pushing a cap here.

Only a fixed whitelist of keys can ever be pushed this way: `max_uses`,
`max_uses_policy`, `payment_grace`, `offline_grace`, `offline_policy`,
`disallow_test_keys`, `update_check_url` (only when it shares a registrable
domain with your compiled-in `license_check_url`), `hide_owner_email`,
`hide_custom_fields`, `suppress_notices`, `suppress_key_notice`,
`plugins_page_link`, `hide_menu_page`, `license_page_title`,
`license_page_menu`. Every value is type-checked and range-clamped before
it's applied — a malformed override is simply ignored, never trusted
verbatim.

`license_check_url`, `proxy_fallback`, `type`, `text_domain`, `permalink`,
`callbacks`, and the config-seal's own `_encrypted` flag can **never** be
overridden, no matter what a response contains. A response that could
rewrite `license_check_url` would make one bad deploy permanently
unrecoverable — and unlike everything else here, it would survive offline
in the cached payload.

If you don't want your own server to be able to touch a given key at all —
even one on the whitelist — list it in `lock_config`:

```php
GumPress::register(__FILE__, 'your-gumroad-product-id', [
    'license_check_url' => 'https://your-licensing-server.example/l/token/verify',
    'lock_config' => ['max_uses'], // this plugin decides its own seat limit, always.
]);
```

A response can also carry `gumpress.recheck_in` (seconds, clamped to
15 minutes–30 days) to shorten the next check — e.g. so a freed seat is
usable on another site within the hour instead of waiting out the normal
12-hour/7-day cache TTL. Anything else your proxy adds to the response
(`notice`, `license_page_url`, or anything of your own) is passive
pass-through, available the same way as any other extra field:

```php
GumPress::extra('gumpress'); // the whole block, however you shaped it
```

## Seats reported by your server

`gumpress.seats` is the one extra block GumPress acts on rather than just
passing through:

```json
{
  "success": true,
  "uses": 3,
  "purchase": { "...": "..." },
  "gumpress": {
    "seats": { "used": 3, "limit": 5, "unlimited": false }
  }
}
```

Send a numeric `limit`, or `"unlimited": true`. Either one marks your server
as the authority on seats, which has two effects:

- **`max_uses` stops applying** — compiled-in or pushed. Your server has
  already decided; deny the request outright (`success: false`) if a site
  shouldn't be licensed.
- **The license page shows your numbers** — the Activations row renders
  `used / limit` (or `used / unlimited`) straight from the block, instead of
  counting against `max_uses`.

Omit the block (or send one with neither key) and nothing changes: GumPress
falls back to its own `max_uses` check exactly as before. That fallback is
also what a malformed block gets, so a truncated response can't silently
switch enforcement off.

The whole block stays readable as usual, including any extra keys of your
own:

```php
// null when the key has never verified, or the response carried no seats.
GumPress::license()?->server_seats(); // ['used' => 3, 'limit' => 5, ...]
```

## Your server waiving the admin footer credit

The license page normally shows "Protected with ♥ by GumPress" in the
wp-admin footer. A self-hosted licensing server can waive it, per verify
response:

```json
{
  "success": true,
  "uses": 3,
  "purchase": { "...": "..." },
  "gumpress": {
    "white_label": true
  }
}
```

There is deliberately **no local option to turn this off** — it isn't a
config key, it can't be pushed through `gumpress.config`, and it isn't
subject to `lock_config`. That's on purpose: it's your licensing server's
call, not something a config array (yours or a compromised override) should
be able to grant itself. `GumPress::is_white_label()` reports the current
answer.

The flag is sticky, not read fresh off the latest payload — once your server
has sent an explicit `true` or `false`, that answer survives an unreachable
server, an invalid/expired key, or the customer clearing their license key
entirely, and only changes again once your server sends a new explicit
value. Omit the key (or the whole `gumpress` block) and nothing changes:
whatever was last cached stays in effect, same as `gumpress.seats`'
"omit and nothing changes" fallback above — this key is never inferred from
`license_check_url` or anything else about your setup.

## Custom metadata

Gumroad checkout custom fields, in whichever shape the API sends them (a list
of `"Key: Value"` strings, or an object map), are available via:

```php
GumPress::meta('Company');   // one field
GumPress::meta();            // all of them, as an assoc array
```

Anything a self-hosted proxy adds to the response that isn't part of
Gumroad's own shape is available via:

```php
GumPress::extra('seats_max');
GumPress::extra();
```

## Obfuscating your config

`bin/encrypt.php` in the GumPress repo turns a config array into a sealed
`gp1` string you can pass as `$options` instead of a plain array:

```
composer encrypt your-gumroad-product-id '{"max_uses": 0, "update_check_url": "https://example.com/updates"}'
```

(or `php bin/encrypt.php ...` directly, without Composer)

The [licensing server's web configurator](https://gumpress.eu/configurator)
does the same thing without touching a terminal, and can prefill
`license_check_url`/`update_check_url` for products it knows about.

This is tamper-*evidence*, not real security: the key derives only from your
Gumroad product_id, which ships in plaintext inside the plugin itself, so
anyone willing to read `gumpress/src/Config.php` and write a script can still
forge a blob. What it buys you is that the config is no longer readable at
rest (it's AES-256-CBC + HMAC-SHA256, not base64+rot13) and casual editing —
a customer's host provider changing `license_check_url` with a text
editor — no longer works.

**If you skip this**, GumPress will show an admin notice with a link to the
configurator, but *only* outside of production
(`wp_get_environment_type()`, local/staging hosts, WP-CLI), and only once
you've actually set an option — a bare `register($file, $product)` with no
`$options` (or one that only restates a default) has nothing to seal, so it
stays quiet. A real customer's site never sees it either way — it's a
build-time reminder aimed at you, the developer, not a runtime warning aimed
at your customers, and it fires regardless of `suppress_notices` for exactly
that reason.

## What GumPress stores, and what's encrypted

Everything lives in `wp_options` (or `wp_sitemeta` when the plugin is
network-activated), named `gumpress_{product_id}_{suffix}`, all with autoload
off:

| Suffix | Contents | At rest |
|---|---|---|
| `license_key` | the key the customer entered | plaintext |
| `state` | the cached verify response — **including `purchase.email` and checkout custom fields** — plus a sticky `white_label` flag (see "Your server waiving the admin footer credit" below) | encrypted |
| `seat` | a key fingerprint, the site host, an activation timestamp | plaintext |
| `schema` | stored-data version marker, for migrations | plaintext |
| `lock` (transient) | a 60-second mutex, value `1` | plaintext |
| `update_cache` (transient) | the update-check response, including the package URL | encrypted |

The two encrypted entries use AES-256-CBC with a random IV per write, plus an
HMAC-SHA256 verified before decryption. The key derives from `wp_salt()`,
i.e. from `wp-config.php` — deliberately not from the product id (which is
part of the option's own name) or the licence key (which is stored right next
to it). Without `ext-openssl` everything degrades to plaintext rather than
failing.

Be clear about what this does and doesn't buy: it protects against seeing the
database *without* the filesystem — a SQL injection, or a leaked dump. Anyone
holding both can still read everything, which is inherent to at-rest
encryption in WordPress.

`license_key` and `seat` stay readable on purpose. A rotated `wp-config` salt
makes the encrypted entries undecryptable; GumPress treats that as an empty
cache and silently re-verifies. Because the licence key is still there the
site recovers unattended, and because the seat record is still there it
doesn't report itself as a *new* activation — which against Gumroad direct
would permanently bump a counter that never decrements.

Deactivating unschedules the refresh event; uninstalling removes all of the
above.

## More than one licensed product on the same site

Each product resolves calls made from files inside its own directory
automatically — that's what makes `GumPress::valid()` work without repeating
the product id. Code that runs *outside* that directory (a shared helper, a
theme template override) should use the explicit form:

```php
GumPress::for('other-product')->valid();
```
