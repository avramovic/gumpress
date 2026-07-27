<?php

declare(strict_types=1);

namespace GumPress\V2;

/**
 * Applies server-pushed config overrides — the `gumpress.config` object a
 * verify response can carry — on top of the integrator's own (defaults +
 * register() options) config. This is what makes `max_seats` set on the
 * licensing server win over whatever `max_uses` a developer compiled in.
 *
 * Every value is filtered through a fixed whitelist, the integrator's own
 * `lock_config` opt-out, and type-cast / clamped to a sane range before
 * being trusted — a malformed or hostile override must never reach Config
 * verbatim. `license_check_url`, `proxy_fallback`, `type`, `text_domain`,
 * `callbacks`, and `_encrypted` are never overridable: a response that could
 * rewrite `license_check_url` would make one bad deploy permanently
 * unrecoverable, and — unlike everything else here — it would survive
 * offline in the cached payload.
 */
final class Overrides
{
    private const OVERRIDABLE = [
        'max_uses',
        'max_uses_policy',
        'payment_grace',
        'offline_grace',
        'offline_policy',
        'disallow_test_keys',
        'update_check_url',
        'white_label',
        'hide_owner_email',
        'hide_custom_fields',
        'suppress_notices',
        'suppress_key_notice',
        'plugins_page_link',
        'hide_menu_page',
        'license_page_title',
        'license_page_menu',
    ];

    public static function apply(Config $base, array $overrides): Config
    {
        if ($overrides === []) {
            return $base;
        }

        $locked = array_flip(array_map('strval', (array) $base->get('lock_config', [])));
        $data = $base->all();

        foreach (self::OVERRIDABLE as $key) {
            if (isset($locked[$key]) || !array_key_exists($key, $overrides)) {
                continue;
            }

            $value = self::sanitize($key, $overrides[$key]);
            if ($value === null) {
                continue; // failed sanitisation (or genuinely null) — keep the integrator's own value.
            }

            if ($key === 'update_check_url' && !self::same_registrable_domain(
                (string) $base->get('license_check_url', ''),
                (string) $value
            )) {
                continue;
            }

            $data[$key] = $value;
        }

        return new Config($data);
    }

    /**
     * @param mixed $value
     * @return mixed null means "reject — keep the integrator's own value."
     */
    private static function sanitize(string $key, $value)
    {
        switch ($key) {
            case 'max_uses':
                if (!is_int($value) && !is_numeric($value)) {
                    return null;
                }

                return max(0, min((int) $value, 1000000));

            case 'payment_grace':
            case 'offline_grace':
                if (!is_int($value) && !is_numeric($value)) {
                    return null;
                }

                return max(0, min((int) $value, 3650));

            case 'max_uses_policy':
                return in_array($value, ['warn', 'block'], true) ? $value : null;

            case 'offline_policy':
                return in_array($value, ['grace', 'closed', 'open'], true) ? $value : null;

            case 'disallow_test_keys':
            case 'white_label':
            case 'hide_owner_email':
            case 'hide_custom_fields':
            case 'suppress_notices':
            case 'suppress_key_notice':
            case 'plugins_page_link':
            case 'hide_menu_page':
                return is_bool($value) ? $value : null;

            case 'license_page_title':
            case 'license_page_menu':
                return (is_string($value) && $value !== '') ? substr($value, 0, 200) : null;

            case 'update_check_url':
                return (is_string($value) && filter_var($value, FILTER_VALIDATE_URL)) ? $value : null;

            default:
                return null;
        }
    }

    /**
     * Not a real Public Suffix List lookup — the shim stays dependency-free
     * by design. This is a sanity bound, not the enforcement: the licensing
     * server's own domain matching (which does use a real PSL) is what
     * actually decides whether an update URL is legitimate. This just stops
     * an override from silently repointing updates at an unrelated host.
     */
    private static function same_registrable_domain(string $a, string $b): bool
    {
        $host_a = self::host_only($a);
        $host_b = self::host_only($b);

        if ($host_a === '' || $host_b === '') {
            return false;
        }

        return self::registrable_part($host_a) === self::registrable_part($host_b);
    }

    private static function host_only(string $url): string
    {
        $host = parse_url($url, PHP_URL_HOST);

        return is_string($host) ? strtolower($host) : '';
    }

    private static function registrable_part(string $host): string
    {
        $labels = explode('.', $host);
        if (count($labels) <= 2) {
            return $host;
        }

        $two_part_tlds = ['co.uk', 'com.au', 'co.nz', 'co.jp', 'com.br', 'co.za'];
        if (in_array(implode('.', array_slice($labels, -2)), $two_part_tlds, true)) {
            return implode('.', array_slice($labels, -3));
        }

        return implode('.', array_slice($labels, -2));
    }
}
