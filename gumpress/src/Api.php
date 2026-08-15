<?php

declare(strict_types=1);

namespace GumPress\V2;

/**
 * Network + caching layer. Nothing in this class runs during a normal page
 * load: verification is only ever triggered from admin_init (throttled) or a
 * scheduled cron event (Module::boot_hooks()), never from valid()/status()
 * themselves. This is the fix for v1's worst offline behaviour: it cached
 * only HTTP 200 responses, so an invalid key meant a blocking HTTP request on
 * every single admin page load, and `_render_license_page()` even deleted its
 * own cache whenever the license was invalid, guaranteeing the next request
 * would do it again.
 */
final class Api
{
    /** Bumped whenever the shape or encoding of a stored option changes. */
    private const SCHEMA = 2;

    private Module $module;

    public function __construct(Module $module)
    {
        $this->module = $module;
    }

    public function state(): array
    {
        $defaults = [
            'key_hash' => null,
            'status' => null,
            'checked_at' => null,
            'valid_at' => null,
            'attempts' => 0,
            'next_attempt_at' => 0,
            'uses' => 0,
            'payload' => null,
        ];

        // Vault::open() returns null for a blob that can't be decrypted —
        // most plausibly because the site's wp-config salts were rotated.
        // That is deliberately indistinguishable from "never checked": the
        // defaults below make due() fire a fresh verify, which restores the
        // cache silently. Note the seat option (should_increment()) is NOT
        // encrypted precisely so it survives that, and no site reports a
        // spurious activation just because its salts changed.
        $stored = Vault::open($this->option_get($this->state_key(), []));

        return array_merge($defaults, $stored ?? []);
    }

    public function due(): bool
    {
        $key = $this->module->license_key();
        if ($key === null || $key === '') {
            return false;
        }

        $state = $this->state();
        if (($state['key_hash'] ?? null) !== $this->hash_key($key)) {
            return true; // key just changed; check immediately regardless of backoff.
        }

        return time() >= (int) ($state['next_attempt_at'] ?? 0);
    }

    /**
     * Brings stored options up to SCHEMA. Runs from the two entry points
     * that can lead to a verify, and must run BEFORE due() and before
     * should_increment() — the whole point is that an install upgrading to
     * this release neither reports a new activation nor forces an immediate
     * re-check just because the fingerprint algorithm changed.
     *
     * Schema 2: key_hash moved from md5() to hash_key()'s HMAC-SHA256. The
     * license key is still on hand, so the old value can be recomputed and
     * rewritten in place rather than left for the comparison paths to
     * tolerate forever. A key_hash belonging to some *other* key won't match
     * the legacy value and is deliberately left alone, so a genuine key
     * change still registers as one.
     */
    public function migrate(): void
    {
        $marker = $this->module->option_name('schema');

        if ((int) $this->option_get($marker, 0) >= self::SCHEMA) {
            return;
        }

        $key = $this->module->license_key();

        if ($key !== null && $key !== '') {
            $legacy = md5($key);
            $current = $this->hash_key($key);

            $state = Vault::open($this->option_get($this->state_key(), []));
            if (is_array($state) && ($state['key_hash'] ?? null) === $legacy) {
                $state['key_hash'] = $current;
                $this->option_set($this->state_key(), Vault::seal($state));
            }

            $seat = $this->option_get($this->seat_key(), []);
            if (is_array($seat) && ($seat['key_hash'] ?? null) === $legacy) {
                $seat['key_hash'] = $current;
                $this->option_set($this->seat_key(), $seat);
            }
        }

        $this->option_set($marker, self::SCHEMA);
    }

    /**
     * Called from a throttled admin_init hook and from the daily refresh
     * cron event. Cheap to call repeatedly: due() and the transient lock
     * make it a no-op outside of its own schedule.
     */
    public function ensure_scheduled(): void
    {
        $this->migrate();

        if (!$this->due()) {
            return;
        }

        $lock = $this->lock_key();
        if (get_transient($lock)) {
            return;
        }

        set_transient($lock, 1, MINUTE_IN_SECONDS);
        $this->verify_now();
        delete_transient($lock);
    }

    /** Bypasses backoff. Used by the explicit "Re-validate" button. */
    public function force_refresh(): void
    {
        $this->migrate();
        $this->verify_now();
    }

    private function verify_now(): void
    {
        $key = $this->module->license_key();

        if ($key === null || $key === '') {
            // Reset entirely rather than patching in place — otherwise the
            // previous license's payload (plan, owner, custom fields) stays
            // cached and keeps showing on the license page even though the
            // key was removed and there is nothing to display anymore.
            // white_label is deliberately carried forward, not reset: this
            // clears the previous CUSTOMER's license data, but branding is
            // an entitlement of the DEVELOPER, not of whichever key was
            // last entered — clearing it here would show our credit on
            // every site the instant a customer cleared their key.
            $fresh = [
                'status' => 'no_key',
                'checked_at' => time(),
            ];
            $whiteLabel = $this->state()['white_label'] ?? null;
            if ($whiteLabel !== null) {
                $fresh['white_label'] = $whiteLabel;
            }

            $this->save_state($fresh);

            return;
        }

        $state = $this->state();

        // license_check_url and proxy_fallback are never part of the server
        // override channel (see Overrides::OVERRIDABLE) — read them from
        // base_config(), not config(), so a compromised override can never
        // redirect the very channel that delivers overrides.
        $config = $this->module->base_config();
        $url = (string) $config->get('license_check_url', Config::DEFAULT_LICENSE_URL);

        $claim = $this->should_increment($key);

        // Claiming a seat and denying it are two different questions: the
        // wire flag below only says "count this call" — Gumroad applies it
        // and increments `uses` before this code ever sees whether the site
        // was actually over the local max_uses cap. A rejected site would
        // otherwise still consume the seat it was just refused, poisoning
        // the count for every genuinely-activated site on the next re-check
        // (see the "seat claiming" section of README.md's Seat limiting).
        // So when the local cap can bite, probe first with
        // increment_uses_count=false, decide locally whether there's room,
        // and only then send the real, incrementing call. A custom
        // license_check_url is exempt: it enforces its own seat model (see
        // License::has_server_seats()) and a probe would only double its
        // traffic for no benefit.
        $probe = $claim && $url === Config::DEFAULT_LICENSE_URL && $this->local_cap_applies();

        if ($probe) {
            $response = $this->post($url, $key, false);
            $state = $this->interpret($response, $state, $key, false);
            $this->save_state($state);

            if (!$this->seat_available()) {
                // Known gap: if $url were a custom proxy that then fell back
                // to Gumroad direct, that fallback call still can't be
                // probed — the probe decision has to be made before the
                // first request goes out, or the proxy's own accounting
                // would lose its increment. Doesn't apply here: $probe is
                // only true when $url is already Gumroad direct.
                return;
            }

            $state = $this->state();
        }

        $response = $this->post($url, $key, $claim);

        if (
            $this->is_transport_failure($response)
            && $url !== Config::DEFAULT_LICENSE_URL
            && $config->get('proxy_fallback')
        ) {
            // A custom proxy is unreachable and the integrator opted in to falling
            // back to Gumroad direct. v1 did this unconditionally, which silently
            // defeats any server-side seat enforcement the proxy was doing — so
            // here it's opt-in and limited to one retry.
            $response = $this->post(Config::DEFAULT_LICENSE_URL, $key, $claim);
        }

        $state = $this->interpret($response, $state, $key, $claim);
        $this->save_state($state);
    }

    private function save_state(array $state): void
    {
        $this->option_set($this->state_key(), Vault::seal($state));
        $this->module->forget_effective_config();
    }

    /**
     * Whether the shim's own max_uses cap is even in a position to reject
     * this verify — i.e. whether probing before claiming is worth the extra
     * request. Deliberately silent on has_server_seats(): that can only be
     * known from a response, which is exactly what the probe is for.
     */
    private function local_cap_applies(): bool
    {
        $config = $this->module->config();

        if ($config->get('max_uses_policy', 'block') !== 'block') {
            return false; // 'warn' never blocks, so it always needs the real seat.
        }

        return ((int) $config->get('max_uses')) > 0;
    }

    /**
     * Reads the probe response just cached by verify_now() and decides
     * whether this site should go on to claim a seat. Mirrors, but does not
     * reuse, Validator's own seat-limit guard: that one blocks strictly
     * *over* max_uses (uses() > max) because it's judging a license that
     * already includes this site's own claim; here uses() is the count
     * *before* this site's activation, so room exists at uses() < max, not
     * uses() <= max.
     */
    private function seat_available(): bool
    {
        $license = $this->license();
        if ($license === null) {
            return false; // transport failure / unparsable probe response.
        }

        $config = $this->module->config();
        $status = Validator::evaluate($license, $this->last_reachable(), $this->valid_at(), $config, null, null);
        if (!$status->is_valid()) {
            // Refunded, disputed, chargebacked, or an ended subscription
            // doesn't get a seat either — no point claiming one for a site
            // that's about to be denied for an unrelated reason.
            return false;
        }

        if ($license->has_server_seats()) {
            return true; // the server's own seat model is authoritative.
        }

        $max = (int) $config->get('max_uses');

        return $max <= 0 || $license->uses() < $max;
    }

    private function post(string $url, string $key, bool $increment)
    {
        return wp_remote_post($url, [
            'timeout' => 10,
            'headers' => [
                'Accept' => 'application/json',
                'Content-Type' => 'application/x-www-form-urlencoded',
            ],
            'body' => [
                'license_key' => $key,
                // Gumroad's real API requires product_id (not
                // product_permalink) for any product created on or after
                // Jan 9, 2023 — see README.md. The optional `permalink`
                // config option is cosmetic only (Buy link, page slug) and
                // deliberately never sent here: the licensing server
                // resolves by URL (endpoint_token) and real Gumroad only
                // needs product_id.
                'product_id' => $this->module->product_id(),
                'increment_uses_count' => $increment ? 'true' : 'false',
                // Per-domain enforcement (seat limits, domain locks) is impossible
                // without the domain — without this, a licensing server's only
                // signal is the WP User-Agent.
                'site_url' => home_url('/'),
            ],
        ]);
    }

    private function is_transport_failure($response): bool
    {
        return is_wp_error($response)
            || wp_remote_retrieve_response_code($response) >= 500
            || wp_remote_retrieve_response_code($response) === 0
            || empty(wp_remote_retrieve_body($response));
    }

    private function interpret($response, array $state, string $key, bool $claimed): array
    {
        $now = time();
        $state['checked_at'] = $now;
        $state['key_hash'] = $this->hash_key($key);

        if ($this->is_transport_failure($response)) {
            $state['attempts'] = (int) ($state['attempts'] ?? 0) + 1;
            $state['next_attempt_at'] = $now + $this->backoff_seconds((int) $state['attempts']);
            $state['status'] = 'transport_error';
            // Deliberately untouched: valid_at and payload. A transport error
            // never invalidates a previously-valid license; only an authoritative
            // response does. Offline grace is evaluated from these two fields.
            return $state;
        }

        $code = wp_remote_retrieve_response_code($response);
        $state['attempts'] = 0;

        if ($code >= 400 && $code < 500) {
            // Gumroad returns 404 for a key that doesn't exist for this product.
            // This must be cached — v1 only cached HTTP 200, so an invalid key
            // meant a fresh HTTP request on every single admin page load.
            $state['status'] = 'not_found';
            $state['payload'] = null;
            $state['next_attempt_at'] = $now + (12 * HOUR_IN_SECONDS);

            return $state;
        }

        if ($code !== 200) {
            $state['status'] = 'transport_error';
            $state['next_attempt_at'] = $now + $this->backoff_seconds(1);

            return $state;
        }

        $license = License::from_json((string) wp_remote_retrieve_body($response));
        if ($license === null) {
            $state['status'] = 'transport_error';
            $state['next_attempt_at'] = $now + $this->backoff_seconds(1);

            return $state;
        }

        $state['payload'] = $license->raw();
        $state['uses'] = $license->uses();

        // Written here, before the success() check below, so a denial
        // (success:false — e.g. an expired/invalid key) still carries a
        // fresh white_label answer, not just an outright valid response —
        // see the sticky-state rationale on License::white_label(). Only
        // written when the response actually addressed it (not null) —
        // Gumroad direct, an older server, or a foreign proxy leave
        // whatever was already cached untouched rather than clearing it.
        $white_label = $license->white_label();
        if ($white_label !== null) {
            $state['white_label'] = $white_label;
        }

        // A licensing server can shorten (never lengthen beyond the normal caps)
        // the next check via gumpress.recheck_in — e.g. so a freed seat is
        // usable elsewhere within the hour instead of after the full 12h/7d TTL.
        $recheck_override = $this->recheck_override($license);

        if (!$license->success()) {
            $state['status'] = 'not_found';
            $state['next_attempt_at'] = $now + ($recheck_override ?? (12 * HOUR_IN_SECONDS));

            return $state;
        }

        $state['valid_at'] = $now;
        if ($claimed) {
            $this->record_activation($key, $license->uses());
        } else {
            // Not claiming a new seat this call — but a site that already
            // holds one from before 2.0.1 (marker present, no ordinal yet)
            // gets one filled in now, provided it's still within the cap.
            // Nothing is backfilled once a site is already over the limit:
            // there is no way to tell a genuinely-poisoned counter apart
            // from an actual over-limit activation, so a stale marker stays
            // exactly as blocked as it is today rather than being amnestied.
            $this->backfill_ordinal($key, $license);
        }

        // Gumroad nulls subscription_failed_at on a successful retry, so shorten
        // the TTL whenever it's set — otherwise a recovered customer could stay
        // locked out for up to a week.
        $ttl = $recheck_override ?? (
            $license->subscription_failed_at() !== null
                ? 6 * HOUR_IN_SECONDS
                : ($license->is_subscription() ? DAY_IN_SECONDS : (7 * DAY_IN_SECONDS))
        );

        $state['status'] = 'ok';
        $state['next_attempt_at'] = $now + $ttl;

        return $state;
    }

    /** gumpress.recheck_in from the response, clamped to [15 minutes, 30 days]. */
    private function recheck_override(License $license): ?int
    {
        $gumpress = $license->extra('gumpress');
        $value = is_array($gumpress) ? ($gumpress['recheck_in'] ?? null) : null;

        if (!is_int($value) && !is_numeric($value)) {
            return null;
        }

        return max(900, min((int) $value, 2592000));
    }

    /** The `gumpress.config` object from the last verify response, or []. */
    public function overrides(): array
    {
        $gumpress = $this->license()?->extra('gumpress');
        $config = is_array($gumpress) ? ($gumpress['config'] ?? null) : null;

        return is_array($config) ? $config : [];
    }

    /**
     * The sticky white_label flag written by interpret() — null when a
     * licensing server has never addressed it (never verified yet, or
     * every verify so far went to Gumroad direct/a foreign proxy). See
     * Module::is_white_label() for how that null falls back to the
     * compiled-in pre-activation hint.
     */
    public function white_label(): ?bool
    {
        $value = $this->state()['white_label'] ?? null;

        return is_bool($value) ? $value : null;
    }

    private function backoff_seconds(int $attempts): int
    {
        $base = (5 * MINUTE_IN_SECONDS) * (2 ** max(0, $attempts - 1));
        $capped = min($base, 6 * HOUR_IN_SECONDS);

        return (int) $capped + random_int(0, 300);
    }

    /**
     * Called once per verify, in verify_now(), to decide the
     * increment_uses_count value threaded through post()/interpret() for
     * that call (and, when the local cap can bite, to decide whether a
     * probe runs first — see local_cap_applies()/seat_available()).
     *
     * Reads config() (effective, server-override-aware), not
     * base_config(): skip_local_seats is a normal Overrides::OVERRIDABLE
     * key, unlike license_check_url/proxy_fallback which deliberately
     * never are (see Overrides.php's class docblock). This does mean the
     * very first verify a site ever makes can't have a pushed override
     * applied yet (config() only has the compiled-in default until a
     * response arrives), so a developer relying on a server-pushed
     * `skip_local_seats: false` sees it take effect from the SECOND verify
     * onward. Self-correcting either way — the seat marker is never
     * written on that first call, so the next verify still increments.
     */
    private function should_increment(string $key): bool
    {
        if ($this->module->config()->get('skip_local_seats', true) && Env::is_non_production()) {
            return false;
        }

        $seat = $this->option_get($this->seat_key(), []);
        $host = Env::site_identity();

        if (($seat['key_hash'] ?? null) === $this->hash_key($key) && ($seat['host'] ?? null) === $host) {
            return false; // already recorded for this exact key + host.
        }

        return true;
    }

    /**
     * This site's 1-based position among the license's activations, as of
     * the call that claimed its seat; a value guaranteed > max_uses when
     * this site is confirmed to hold no seat and the cap is confirmed full;
     * or null when nothing about this site's own position is known (see
     * below). Validator::evaluate() compares this against max_uses instead
     * of the license's current (global, ever-growing) `uses` count, so a
     * seat this site legitimately claimed can never be taken away by uses
     * growing later — a third site's rejected attempt, a clone, a restored
     * backup, or another site still running an older GumPress that claims
     * without probing.
     *
     * 0 is a sentinel meaning "holds a place, never block on it" — the same
     * skip_local_seats + non-production condition that keeps should_increment()
     * from ever claiming a seat for this site in the first place.
     *
     * null is the pre-2.0.1 default (a seat marker with no ordinal, or no
     * marker at all): Validator falls back to comparing `uses()` to max_uses
     * directly. That fallback assumes `uses()` already counts this site's
     * own claim, which was always true before probing existed (every verify
     * claimed before validating) — so it stays correct for a legacy marker.
     * It is NOT assumed for a site that holds no marker at all: such a site
     * was never counted in `uses()`, so seeing `uses() === max_uses` there
     * means the cap is exactly full, not that this site fits within it —
     * the branch below turns that into an explicit over-max value so
     * Validator blocks it instead of reading "not yet over" as "fine".
     */
    public function seat_ordinal(): ?int
    {
        if ($this->module->config()->get('skip_local_seats', true) && Env::is_non_production()) {
            return 0;
        }

        $seat = $this->option_get($this->seat_key(), []);
        $host = Env::site_identity();
        $key = $this->module->license_key();

        if ($key !== null && ($seat['key_hash'] ?? null) === $this->hash_key($key) && ($seat['host'] ?? null) === $host) {
            return isset($seat['ordinal']) ? (int) $seat['ordinal'] : null;
        }

        // No marker at all: this site has never claimed a seat. If the most
        // recently cached response already shows the cap reached (or
        // exceeded) without this site's own claim counted in it, there is
        // definitely no room for it either.
        $license = $this->license();
        if ($license === null || $license->has_server_seats()) {
            return null;
        }

        $config = $this->module->config();
        if ($config->get('max_uses_policy', 'block') !== 'block') {
            return null;
        }

        $max = (int) $config->get('max_uses');

        return ($max > 0 && $license->uses() >= $max) ? ($max + 1) : null;
    }

    private function record_activation(string $key, int $ordinal): void
    {
        $this->option_set($this->seat_key(), [
            'key_hash' => $this->hash_key($key),
            'host' => Env::site_identity(),
            'activated_at' => time(),
            'ordinal' => $ordinal,
        ]);
    }

    /**
     * A site that already holds a seat marker from before 2.0.1 (no
     * ordinal recorded) gets one filled in the first time a non-claiming
     * verify sees it's still within the cap — protecting it from now on
     * without needing a fresh activation. Deliberately narrow: nothing is
     * backfilled once uses() is already past max, since a poisoned counter
     * and a real over-limit activation are indistinguishable from here, and
     * granting the benefit of the doubt would silently amnesty every
     * already-blocked site on the next re-check.
     */
    private function backfill_ordinal(string $key, License $license): void
    {
        if ($license->has_server_seats()) {
            return;
        }

        $max = (int) $this->module->config()->get('max_uses');
        if ($max <= 0 || $license->uses() > $max) {
            return;
        }

        $seat = $this->option_get($this->seat_key(), []);
        $host = Env::site_identity();

        if (($seat['key_hash'] ?? null) !== $this->hash_key($key) || ($seat['host'] ?? null) !== $host) {
            return; // no marker for this site to backfill.
        }

        if (array_key_exists('ordinal', $seat)) {
            return; // already has one (including the non-production sentinel).
        }

        $seat['ordinal'] = $license->uses();
        $this->option_set($this->seat_key(), $seat);
    }

    public function license(): ?License
    {
        $payload = $this->state()['payload'] ?? null;

        return is_array($payload) ? new License($payload) : null;
    }

    public function valid_at(): ?int
    {
        $value = $this->state()['valid_at'] ?? null;

        return is_int($value) ? $value : null;
    }

    public function last_reachable(): bool
    {
        return in_array($this->state()['status'] ?? null, ['ok', 'not_found'], true);
    }

    /**
     * Drops every trace of this module's licensing state. Called from the
     * uninstall hook (see the GumPress facade) and safe to call directly —
     * the next verify rebuilds whatever is still needed.
     */
    public function purge(): void
    {
        $this->option_delete($this->state_key());
        $this->option_delete($this->seat_key());
        delete_transient($this->lock_key());
    }

    private function state_key(): string
    {
        return $this->module->option_name('state');
    }

    private function lock_key(): string
    {
        return $this->module->option_name('lock');
    }

    private function seat_key(): string
    {
        return $this->module->option_name('seat');
    }

    /**
     * A fingerprint for equality comparison only — it detects that the
     * stored key changed, and makes activation reporting idempotent. It is
     * not a confidentiality measure: the raw key sits in a neighbouring
     * option, and Gumroad keys carry enough entropy that no digest here is
     * brute-forceable anyway.
     *
     * Scoped by product rather than by wp_salt() on purpose. A salt-derived
     * fingerprint would change under a salt rotation, and a changed
     * fingerprint is exactly what makes should_increment() report a new
     * activation — see migrate() for why that matters.
     */
    private function hash_key(string $key): string
    {
        return hash_hmac('sha256', $key, 'gumpress|seat|' . $this->module->product_id());
    }

    /**
     * @param mixed $default
     * @return mixed
     */
    private function option_get(string $name, $default)
    {
        return $this->module->is_network_wide() ? get_site_option($name, $default) : get_option($name, $default);
    }

    /**
     * @param mixed $value
     */
    private function option_set(string $name, $value): void
    {
        if ($this->module->is_network_wide()) {
            update_site_option($name, $value);
        } else {
            update_option($name, $value, false);
        }
    }

    private function option_delete(string $name): void
    {
        if ($this->module->is_network_wide()) {
            delete_site_option($name);
        } else {
            delete_option($name);
        }
    }
}
