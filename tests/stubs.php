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

// Real hook registry (not a no-op) — needed now that Admin.php's license
// page fires actual do_action()/apply_filters() calls that tests assert
// against. $tag => $priority => list of ['cb' => callable, 'args' => int].
$GLOBALS['__gumpress_test_hooks'] = [];

// Queued wp_remote_post()/wp_remote_get() responses + a record of every
// request made — see gumpress_test_dispatch_http() below.
$GLOBALS['__gumpress_test_http'] = ['queue' => [], 'requests' => []];

function gumpress_test_reset_store(): void
{
    $GLOBALS['__gumpress_test_options'] = [];
    $GLOBALS['__gumpress_test_transients'] = [];
    $GLOBALS['__gumpress_test_cron'] = [];
    $GLOBALS['__gumpress_test_salt'] = 'unit-test-salt';
    $GLOBALS['__gumpress_test_hooks'] = [];
    $GLOBALS['__gumpress_test_http'] = ['queue' => [], 'requests' => []];
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

if (!function_exists('esc_attr')) {
    function esc_attr($text)
    {
        return htmlspecialchars((string) $text, ENT_QUOTES);
    }
}

if (!function_exists('esc_html__')) {
    function esc_html__($text, $domain = null)
    {
        return esc_html(__($text, $domain));
    }
}

if (!function_exists('esc_url')) {
    function esc_url($url)
    {
        return htmlspecialchars((string) $url, ENT_QUOTES);
    }
}

if (!function_exists('wp_nonce_field')) {
    function wp_nonce_field($action = -1, $name = '_wpnonce', $referer = true, $echo = true)
    {
        $field = '<input type="hidden" name="' . esc_attr($name) . '" value="test-nonce">';
        if ($echo) {
            echo $field;
        }

        return $field;
    }
}

if (!function_exists('submit_button')) {
    function submit_button($text = null, $type = 'primary', $name = 'submit', $wrap = true, $other_attributes = null)
    {
        printf(
            '<button type="submit" name="%s" class="button button-%s">%s</button>',
            esc_attr($name),
            esc_attr($type),
            esc_html($text ?? 'Save Changes')
        );
    }
}

/**
 * Real hook registry, not a no-op — Admin.php's license page fires actual
 * do_action()/apply_filters() calls that tests assert against (see
 * $GLOBALS['__gumpress_test_hooks'] above). ksort() mirrors WP core's
 * priority ordering; array_slice($args, 0, $accepted_args) mirrors
 * WP_Hook::apply_filters()'s accepted-args truncation, which is itself
 * part of the contract AdminHooksTest pins (a filter/action declares how
 * many args it accepts; extra args passed by do_action/apply_filters are
 * silently dropped, never an error).
 */
if (!function_exists('add_action')) {
    function add_action($tag, $callback, $priority = 10, $accepted_args = 1)
    {
        $GLOBALS['__gumpress_test_hooks'][$tag][$priority][] = ['cb' => $callback, 'args' => $accepted_args];

        return true;
    }
}

if (!function_exists('add_filter')) {
    function add_filter($tag, $callback, $priority = 10, $accepted_args = 1)
    {
        return add_action($tag, $callback, $priority, $accepted_args);
    }
}

if (!function_exists('do_action')) {
    function do_action($tag, ...$args)
    {
        $hooks = $GLOBALS['__gumpress_test_hooks'][$tag] ?? [];
        ksort($hooks);

        foreach ($hooks as $callbacks) {
            foreach ($callbacks as $hook) {
                call_user_func_array($hook['cb'], array_slice($args, 0, $hook['args']));
            }
        }
    }
}

if (!function_exists('apply_filters')) {
    function apply_filters($tag, $value, ...$args)
    {
        $hooks = $GLOBALS['__gumpress_test_hooks'][$tag] ?? [];
        ksort($hooks);

        $all_args = array_merge([$value], $args);
        foreach ($hooks as $callbacks) {
            foreach ($callbacks as $hook) {
                $all_args[0] = call_user_func_array($hook['cb'], array_slice($all_args, 0, $hook['args']));
            }
        }

        return $all_args[0];
    }
}

if (!function_exists('has_action')) {
    function has_action($tag, $callback = false)
    {
        $hooks = $GLOBALS['__gumpress_test_hooks'][$tag] ?? [];
        if ($callback === false) {
            return !empty($hooks);
        }

        foreach ($hooks as $priority => $callbacks) {
            foreach ($callbacks as $hook) {
                if ($hook['cb'] === $callback) {
                    return $priority;
                }
            }
        }

        return false;
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

/**
 * Minimal HTTP layer stand-in for Api::verify_now()'s network path
 * (wp_remote_post/wp_remote_get + the is_wp_error/response-code/body
 * readers). $GLOBALS['__gumpress_test_http']['queue'] holds responses to
 * hand out in order — either a WP_Error or a ['response' => ['code' =>
 * int], 'body' => string] array, mirroring wp_remote_post()'s real return
 * shape. Each call also records itself in ['requests'], so a test can
 * assert exactly how many requests fired and what body each one carried
 * (e.g. increment_uses_count) — the whole point of testing probe-then-claim.
 * An empty queue means "unexpected extra request": it fails loudly with a
 * WP_Error rather than silently returning something request-shaped, so a
 * test that under-queues responses gets a clear failure instead of a
 * misleadingly "successful" transport_error interpretation.
 */
if (!class_exists('WP_Error')) {
    class WP_Error
    {
        public $errors = [];

        public function __construct($code = '', $message = '', $data = null)
        {
            if ($code !== '') {
                $this->errors[$code][] = $message;
            }
        }

        public function get_error_message()
        {
            $first = reset($this->errors);

            return $first ? reset($first) : '';
        }
    }
}

if (!function_exists('is_wp_error')) {
    function is_wp_error($thing)
    {
        return $thing instanceof WP_Error;
    }
}

if (!function_exists('wp_remote_retrieve_response_code')) {
    function wp_remote_retrieve_response_code($response)
    {
        if (is_wp_error($response)) {
            return 0;
        }

        return (int) ($response['response']['code'] ?? 0);
    }
}

if (!function_exists('wp_remote_retrieve_body')) {
    function wp_remote_retrieve_body($response)
    {
        if (is_wp_error($response)) {
            return '';
        }

        return (string) ($response['body'] ?? '');
    }
}

function gumpress_test_dispatch_http($method, $url, $args)
{
    $GLOBALS['__gumpress_test_http']['requests'][] = ['method' => $method, 'url' => $url, 'args' => $args];

    $queue = &$GLOBALS['__gumpress_test_http']['queue'];
    if (empty($queue)) {
        return new WP_Error('http_request_failed', 'gumpress_test_dispatch_http: no response queued');
    }

    return array_shift($queue);
}

if (!function_exists('wp_remote_post')) {
    function wp_remote_post($url, $args = [])
    {
        return gumpress_test_dispatch_http('post', $url, $args);
    }
}

if (!function_exists('wp_remote_get')) {
    function wp_remote_get($url, $args = [])
    {
        return gumpress_test_dispatch_http('get', $url, $args);
    }
}
