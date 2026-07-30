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

// Stands in for one site's wp_options / transients. Reset it between tests
// with gumpress_test_reset_store() — two tests sharing an option table would
// leak state the way two separate WordPress installs never would.
$GLOBALS['__gumpress_test_options'] = [];
$GLOBALS['__gumpress_test_transients'] = [];
$GLOBALS['__gumpress_test_salt'] = 'unit-test-salt';

function gumpress_test_reset_store(): void
{
    $GLOBALS['__gumpress_test_options'] = [];
    $GLOBALS['__gumpress_test_transients'] = [];
    $GLOBALS['__gumpress_test_cron'] = [];
    $GLOBALS['__gumpress_test_salt'] = 'unit-test-salt';
}

if (!function_exists('wp_salt')) {
    function wp_salt($scheme = 'auth')
    {
        return $GLOBALS['__gumpress_test_salt'];
    }
}

if (!function_exists('get_option')) {
    function get_option($name, $default = false)
    {
        return $GLOBALS['__gumpress_test_options'][$name] ?? $default;
    }
}

if (!function_exists('update_option')) {
    function update_option($name, $value, $autoload = null)
    {
        $GLOBALS['__gumpress_test_options'][$name] = $value;

        return true;
    }
}

if (!function_exists('delete_option')) {
    function delete_option($name)
    {
        unset($GLOBALS['__gumpress_test_options'][$name]);

        return true;
    }
}

if (!function_exists('get_transient')) {
    function get_transient($name)
    {
        return $GLOBALS['__gumpress_test_transients'][$name] ?? false;
    }
}

if (!function_exists('set_transient')) {
    function set_transient($name, $value, $ttl = 0)
    {
        $GLOBALS['__gumpress_test_transients'][$name] = $value;

        return true;
    }
}

if (!function_exists('delete_transient')) {
    function delete_transient($name)
    {
        unset($GLOBALS['__gumpress_test_transients'][$name]);

        return true;
    }
}

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

if (!function_exists('esc_url')) {
    function esc_url($url)
    {
        return htmlspecialchars((string) $url, ENT_QUOTES);
    }
}

if (!function_exists('add_action')) {
    function add_action(...$args)
    {
        return true;
    }
}

if (!function_exists('wp_normalize_path')) {
    function wp_normalize_path($path)
    {
        return str_replace('\\', '/', (string) $path);
    }
}

if (!function_exists('is_multisite')) {
    function is_multisite()
    {
        return false;
    }
}

if (!function_exists('get_file_data')) {
    /**
     * Cut-down version of WP core's get_file_data(): scans the first 8KB of
     * $file for "Header Name: value" doc-comment lines. Just enough for
     * Module::read_headers()/label() to be testable without a real
     * WordPress checkout.
     */
    function get_file_data($file, $headers)
    {
        $contents = @file_get_contents($file);
        $contents = $contents === false ? '' : str_replace("\r", "\n", substr($contents, 0, 8192));

        $result = [];
        foreach ($headers as $field => $regex) {
            if (preg_match('/^[ \t\/*#@]*' . preg_quote($regex, '/') . ':(.*)$/mi', $contents, $match) && $match[1] !== '') {
                $result[$field] = trim(preg_replace('/\s*(?:\*\/|\?>).*/', '', $match[1]));
            } else {
                $result[$field] = '';
            }
        }

        return $result;
    }
}

$GLOBALS['__gumpress_test_cron'] = [];

if (!function_exists('wp_next_scheduled')) {
    function wp_next_scheduled($hook, $args = [])
    {
        return $GLOBALS['__gumpress_test_cron'][$hook] ?? false;
    }
}

if (!function_exists('wp_schedule_event')) {
    function wp_schedule_event($timestamp, $recurrence, $hook, $args = [])
    {
        $GLOBALS['__gumpress_test_cron'][$hook] = $timestamp;

        return true;
    }
}

if (!function_exists('wp_unschedule_event')) {
    function wp_unschedule_event($timestamp, $hook, $args = [])
    {
        unset($GLOBALS['__gumpress_test_cron'][$hook]);

        return true;
    }
}
