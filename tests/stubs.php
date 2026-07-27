<?php

/**
 * Minimal hand-rolled stand-ins for the WordPress functions/constants the
 * pure-logic classes touch (Env, Validator via time constants, Strings via
 * translation functions). Deliberately not a full WP test harness — the
 * point is that Config/Env/License/Status/Validator/Strings are testable
 * with zero WordPress and zero Gumroad account.
 */

if (!defined('MINUTE_IN_SECONDS')) {
    define('MINUTE_IN_SECONDS', 60);
}
if (!defined('HOUR_IN_SECONDS')) {
    define('HOUR_IN_SECONDS', 3600);
}
if (!defined('DAY_IN_SECONDS')) {
    define('DAY_IN_SECONDS', 86400);
}
if (!defined('WEEK_IN_SECONDS')) {
    define('WEEK_IN_SECONDS', 604800);
}

$GLOBALS['__gumpress_test_home_url'] = 'https://example.com';
$GLOBALS['__gumpress_test_env_type'] = 'production';

if (!function_exists('home_url')) {
    function home_url($path = '/')
    {
        return rtrim($GLOBALS['__gumpress_test_home_url'], '/') . $path;
    }
}

if (!function_exists('wp_get_environment_type')) {
    function wp_get_environment_type()
    {
        return $GLOBALS['__gumpress_test_env_type'];
    }
}

if (!function_exists('__')) {
    function __($text, $domain = null)
    {
        return $text;
    }
}

if (!function_exists('_n')) {
    function _n($single, $plural, $number, $domain = null)
    {
        return $number == 1 ? $single : $plural;
    }
}

if (!function_exists('esc_html')) {
    function esc_html($text)
    {
        return htmlspecialchars((string) $text, ENT_QUOTES);
    }
}

if (!function_exists('add_action')) {
    function add_action(...$args)
    {
        return true;
    }
}
