<?php

declare(strict_types=1);

namespace GumPress\V2;

/**
 * Environment detection used to decide whether a verification call should
 * count as a real activation. v1's is_local() read $_SERVER['SERVER_ADDR']
 * unguarded (a warning under WP-CLI/cron) and only recognised RFC1918 IPs and
 * a few local hostnames, missing staging subdomains and WP's own
 * wp_get_environment_type() entirely.
 */
final class Env
{
    public static function type(): string
    {
        if (function_exists('wp_get_environment_type')) {
            return wp_get_environment_type();
        }

        return 'production';
    }

    public static function is_non_production(): bool
    {
        if (self::type() !== 'production') {
            return true;
        }

        return self::looks_local_or_staging();
    }

    private static function looks_local_or_staging(): bool
    {
        $host = strtolower(self::site_identity());

        if ($host === '') {
            return false;
        }

        if (
            $host === 'localhost'
            || str_ends_with($host, '.local')
            || str_ends_with($host, '.test')
            || str_ends_with($host, '.localhost')
        ) {
            return true;
        }

        foreach (['dev.', 'staging.', 'stage.'] as $prefix) {
            if (str_starts_with($host, $prefix)) {
                return true;
            }
        }

        if (defined('WP_CLI') && \WP_CLI) {
            return true;
        }

        $ip = (string) ($_SERVER['SERVER_ADDR'] ?? '');
        if ($ip === '127.0.0.1') {
            return true;
        }

        if (
            $ip !== ''
            && (self::cidr_match($ip, '10.0.0.0/8')
                || self::cidr_match($ip, '172.16.0.0/12')
                || self::cidr_match($ip, '192.168.0.0/16'))
        ) {
            return true;
        }

        return false;
    }

    public static function cidr_match(string $ip, string $range): bool
    {
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return false; // IPv4 only, same scope as v1; documented rather than silently wrong.
        }

        [$subnet, $bits] = array_pad(explode('/', $range, 2), 2, '32');
        $bits = (int) $bits;

        $ip_long = ip2long($ip);
        $subnet_long = ip2long($subnet);
        if ($ip_long === false || $subnet_long === false) {
            return false;
        }

        $mask = $bits === 0 ? 0 : (-1 << (32 - $bits));
        $subnet_long &= $mask;

        return ($ip_long & $mask) === $subnet_long;
    }

    public static function site_identity(): string
    {
        if (!function_exists('home_url')) {
            return '';
        }

        return (string) parse_url(home_url('/'), PHP_URL_HOST);
    }
}
