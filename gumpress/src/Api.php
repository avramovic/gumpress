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

            $this->option_set($this->state_key(), Vault::seal($fresh));
            $this->module->forget_effective_config();

            return;
        }

        $state = $this->state();

        // license_check_url and proxy_fallback are never part of the server
        // override channel (see Overrides::OVERRIDABLE) — read them from
        // base_config(), not config(), so a compromised override can never
        // redirect the very channel that delivers overrides.
        $config = $this->module->base_config();
        $url = (string) $config->get('license_check_url', Config::DEFAULT_LICENSE_URL);

        $response = $this->post($url, $key);

        if (
            $this->is_transport_failure($response)
            && $url !== Config::DEFAULT_LICENSE_URL
            && $config->get('proxy_fallback')
        ) {
            // A custom proxy is unreachable and the integrator opted in to falling
            // back to Gumroad direct. v1 did this unconditionally, which silently
            // defeats any server-side seat enforcement the proxy was doing — so
            // here it's opt-in and limited to one retry.
            $response = $this->post(Config::DEFAULT_LICENSE_URL, $key);
        }

        $state = $this->interpret($response, $state, $key);
        $this->option_set($this->state_key(), Vault::seal($state));
        $this->module->forget_effective_config();
    }

    private function post(string $url, string $key)
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
                'increment_uses_count' => $this->should_increment($key) ? 'true' : 'false',
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

    private function interpret($response, array $state, string $key): array
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
        if ($this->should_increment($key)) {
            $this->record_activation($key);
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

    private function should_increment(string $key): bool
    {
        if (Env::is_non_production()) {
            return false;
        }

        $seat = $this->option_get($this->seat_key(), []);
        $host = Env::site_identity();

        if (($seat['key_hash'] ?? null) === $this->hash_key($key) && ($seat['host'] ?? null) === $host) {
            return false; // already recorded for this exact key + host.
        }

        return true;
    }

    private function record_activation(string $key): void
    {
        $this->option_set($this->seat_key(), [
            'key_hash' => $this->hash_key($key),
            'host' => Env::site_identity(),
            'activated_at' => time(),
        ]);
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
