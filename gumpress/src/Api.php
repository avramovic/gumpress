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

        $stored = $this->option_get($this->state_key(), []);

        return array_merge($defaults, is_array($stored) ? $stored : []);
    }

    public function due(): bool
    {
        $key = $this->module->license_key();
        if ($key === null || $key === '') {
            return false;
        }

        $state = $this->state();
        if (($state['key_hash'] ?? null) !== self::hash_key($key)) {
            return true; // key just changed; check immediately regardless of backoff.
        }

        return time() >= (int) ($state['next_attempt_at'] ?? 0);
    }

    /**
     * Called from a throttled admin_init hook and from the daily refresh
     * cron event. Cheap to call repeatedly: due() and the transient lock
     * make it a no-op outside of its own schedule.
     */
    public function ensure_scheduled(): void
    {
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
            $this->option_set($this->state_key(), [
                'status' => 'no_key',
                'checked_at' => time(),
            ]);
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
        $this->option_set($this->state_key(), $state);
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
        $state['key_hash'] = self::hash_key($key);

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

        if (($seat['key_hash'] ?? null) === self::hash_key($key) && ($seat['host'] ?? null) === $host) {
            return false; // already recorded for this exact key + host.
        }

        return true;
    }

    private function record_activation(string $key): void
    {
        $this->option_set($this->seat_key(), [
            'key_hash' => self::hash_key($key),
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

    public function purge(): void
    {
        $this->option_set($this->state_key(), []);
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

    private static function hash_key(string $key): string
    {
        return md5($key);
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
}
