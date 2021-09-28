<?php

defined('ABSPATH') or die('No script kiddies please!');

/**
 * @method is_valid_license(DynamicArray $license = null)
 * @method is_local()
 * @method cidr_match($ip, $subnet)
 */
class GumPress
{
    private array $config;
    private string $description = "";

    public function __construct($config = [])
    {
        $this->config = $config;
    }

    public function config($key, $default = null)
    {
        return $this->config[$key] ?? $default;
    }

    public function callback($key, $default = null)
    {
        return $this->config['callbacks'][$key] ?? $default;
    }

    public function license($url = null)
    {
        $license_key = $this->license_key();
        if (empty($license_key)) {
            return null;
        }

        $cache_key = $this->module_slug('license_cache');
        $remote    = get_transient($cache_key);
        $url       = $url ?: $this->config('license_check_url', 'https://api.gumroad.com/v2/licenses/verify');
        $hostname  = sanitize_title(parse_url(home_url('/'), PHP_URL_HOST));
        $activated = get_option($this->module_slug('license_keys_'.dh(cr($hostname))), []);

        if (!$remote) {
            $remote = wp_remote_post(
                $url,
                [
                    'timeout' => 10,
                    'headers' => [
                        'Accept'       => 'application/json',
                        'Content-Type' => 'application/x-www-form-urlencoded',
                    ],
                    'body'    => [
                        'license_key'          => $license_key,
                        'product_permalink'    => $this->config('short_id'),
                        'increment_uses_count' => (!in_array($license_key, $activated) && !$this->is_local()) ? 'true' : 'false',
                        'site_url'             => urlencode(get_home_url()),
                        'wp_version'           => get_bloginfo('version'),
                        'version'              => $this->module_data('Version')
                    ],
                ]
            );

            if (
                is_wp_error($remote)
                || empty(wp_remote_retrieve_body($remote))
            ) {

                if ($url !== 'https://api.gumroad.com/v2/licenses/verify') {
                    return $this->license('https://api.gumroad.com/v2/licenses/verify');
                }
                return false;
            }

            if (wp_remote_retrieve_response_code($remote) === 200) {
                set_transient($cache_key, $remote, WEEK_IN_SECONDS);
            }
        }

        $json = json_decode(wp_remote_retrieve_body($remote));
        if (!in_array($license_key, $activated) && wp_remote_retrieve_response_code($remote) === 200) {
            $activated[] = $license_key;
            update_option($this->module_slug('license_keys_'.dh(cr($hostname))), $activated);
        }

        return new DynamicArray((array)$json);
    }

    public function is_recurring($license = null)
    {
        $license = $license ?? $this->license();
        return $license && !empty($license->get('purchase')->get('recurrence'));
    }

    public function license_type($license = null)
    {
        $license = $license ?? $this->license();
        if (!$license) {
            return null;
        }

        return $this->is_recurring($license) ? 'recurring' : 'purchase';
    }

    public function __call($method, $args)
    {
        if ($method == 'is_valid_license') {
            $license = $args[0] ?? $this->license();

            if (false === $license) {
                $this->description = __('Unable to load license information!', 'gumpress');
                return false;
            } elseif (is_null($license)) {
                $this->description = __('No license key found.', 'gumpress');
                return false;
            } elseif (!$license->get('success')) {
                $this->description = __($license->get('message'), 'gumpress');
                return false;
            } elseif ($license->get('purchase')->get('test') && $this->config('disallow_test_keys')) {
                $this->description = __('This is a testing license key and those are not allowed!', 'gumpress');
                return false;
            } elseif ($max = $this->config('max_uses')) {
                $uses = $license->get('uses');
                if ($uses > $max) {
                    $this->description = sprintf(__('Maximum number of activations reached! %d / %d', 'gumpress'), $uses, $max);
                    return false;
                }

            } elseif ($license->get('purchase')->get('refunded')) {
                $this->description = __('Your purchase was refunded!', 'gumpress');
                return false;
            } elseif ($license->get('purchase')->get('disputed') && !$license->get('purchase')->get('dispute_won')) {
                $this->description = __('Your purchase was disputed!', 'gumpress');
                return false;
            } elseif ($license->get('purchase')->get('subscription_failed_at')) {
                $date  = new \DateTime($license->get('purchase')->get('subscription_failed_at'));
                $grace = $this->config('grace_period', 7);
                if ($grace > 0) {
                    $now  = new \DateTime();
                    $diff = (int)($now - $date)->format('%d');
                    $left = $grace - $diff;

                    if ($left > 0) {
                        $this->description = sprintf(__('Your subscription payment failed on %s. Your license will be deactivated in %d days.', 'gumpress'), $date->format('Y-m-d (H:i)'), $left);
                        return true;
                    }
                }

                $this->description = sprintf(__('Your subscription payment failed on %s', 'gumpress'), $date->format('Y-m-d (H:i)'));
                return false;
            } elseif ($license->get('purchase')->get('subscription_ended_at')) {
                $date              = new \DateTime($license->get('purchase')->get('subscription_ended_at'));
                $this->description = sprintf(__('Your subscription ended on %s', 'gumpress'), $date->format('Y-m-d (H:i)'));
                return false;
            } elseif ($license->get('purchase')->get('subscription_cancelled_at')) {
                $date              = new \DateTime($license->get('purchase')->get('subscription_cancelled_at'));
                $this->description = sprintf(__('Your subscription was cancelled on', 'gumpress'), $date->format('Y-m-d (H:i)'));
                return false;
            }

            $this->description = __('Your license is valid!', 'gumpress');
            return true;
        } elseif ($method == 'is_local') {
            $domain = parse_url(get_home_url(), PHP_URL_HOST);
            $ip     = $_SERVER["SERVER_ADDR"];

            if (str_ends_with($domain, '.local') || str_ends_with($domain, '.test') || $domain == 'localhost') {
                return true;
            }

            return ($ip === '127.0.0.1') || $this->cidr_match($ip, '10.0.0.0/8') || $this->cidr_match($ip, '172.16.0.0/12') || $this->cidr_match($ip, '192.168.0.0/16');
        } elseif ($method == 'cidr_match') {
            $ip    = $args[0];
            $range = $args[1];
            list ($subnet, $bits) = explode('/', $range);
            if ($bits === null) {
                $bits = 32;
            }
            $ip     = ip2long($ip);
            $subnet = ip2long($subnet);
            $mask   = -1 << (32 - $bits);
            $subnet &= $mask;
            return ($ip & $mask) == $subnet;
        }

        throw new BadMethodCallException(sprintf("Method %s not found in %s", $method, get_class($this)));
    }

    public function license_description()
    {
        return $this->description;
    }

    public function module_slug($suffix = null, $prefix = null)
    {
        $prefix = $prefix ? $prefix.'-' : '';
        $suffix = $suffix ? '-'.$suffix : '';

        return $prefix.dirname($this->module_file()).$suffix;
    }

    public function module_file()
    {
        $type        = $this->config('type');
        $plugin_file = $this->config('plugin_file');
        return ($type == 'plugin') ? plugin_basename($plugin_file) : $plugin_file;
    }

    public function module_name()
    {
        return $this->module_data('Name');
    }

    public function license_key()
    {
        return get_option($this->module_slug('license_key'));
    }

    public function module_data($key = null)
    {
        $type = $this->config('type');
        if ($type == 'plugin' && function_exists('get_plugin_data')) {
            $data = get_plugin_data($this->config('plugin_file'), false);
        } else {
            $theme = wp_get_theme($this->module_slug());
            $data  = [
                'Name'        => $theme->__get('name'),
                'Version'     => $theme->__get('version'),
                'Author'      => strip_tags($theme->__get('author')),
                'Description' => $theme->__get('description'),
                'Parent'      => $theme->__get('parent_theme'),
            ];
        }

        if (is_null($key)) {
            return $data;
        }

        return $data[$key];
    }

    public function _register_hooks()
    {
        add_action('admin_init', [$this, '_plugin_settings_init']);
        add_action('admin_menu', [$this, '_register_license_page']);

        $on_license_page = isset($_GET['page']) && $_GET['page'] == $this->module_slug('license');

        if (!$this->config('white_label') && $on_license_page) {
            add_filter('admin_footer_text', [$this, '_replace_footer_admin']);
        }

        if (!$this->config('suppress_notices') && !$on_license_page) {
            add_action('admin_notices', [$this, '_license_admin_notice']);
        }

        if ($this->config('plugins_page_link') && ($this->config('type') == 'plugin')) {
            add_action('plugin_action_links_'.$this->module_file(), [$this, '_plugins_page_add_license_page_link']);
        }

        if ($this->config('update_check_url') && (!$this->config('deny_update_without_license') || $this->is_valid_license())) {

            if ($this->config('type') == 'plugin') {
                add_filter('plugins_api', array($this, '_update_info'), 20, 3);
                add_filter('site_transient_update_plugins', array($this, '_update_process'));
            } else {
                add_filter('themes_api', array($this, '_update_info'), 20, 3);
                add_filter('pre_set_site_transient_update_themes', array($this, '_update_process'));
            }
            add_action('upgrader_process_complete', array($this, '_update_purge'), 10, 2);
        }
    }

    function _license_admin_notice()
    {
        if (!$this->license_key()) {

            if (!$this->config('suppress_key_notice')) {
                $class   = 'notice notice-info';
                $message = sprintf(__('%s: %s', 'gumpress'), $this->module_name(), __('No license key found.', 'gumpress'));
                printf('<div class="%1$s"><p>%2$s <a href="'.esc_url($this->license_page_link()).'">%3$s</a></p></div>', esc_attr($class), esc_html($message), __('Manage license', 'gumpress'));
            }

        } elseif (!$this->is_valid_license()) {
            $class   = 'notice notice-error';
            $message = sprintf(__('%s: %s', 'gumpress'), $this->module_name(), $this->license_description());
            printf('<div class="%1$s"><p>%2$s <a href="'.esc_url($this->license_page_link()).'">%3$s</a></p></div>', esc_attr($class), esc_html($message), __('Manage license', 'gumpress'));
        } elseif ($this->is_recurring() && $this->license()->get('purchase')->get('subscription_failed_at')) {
            $class   = 'notice notice-warning';
            $message = sprintf(__('%s: %s', 'gumpress'), $this->module_name(), $this->license_description());
            printf('<div class="%1$s"><p>%2$s <a href="'.esc_url($this->license_page_link()).'">%3$s</a></p></div>', esc_attr($class), esc_html($message), __('Manage license', 'gumpress'));
        }
    }


    public function _plugin_settings_init()
    {
        add_settings_section(
            $this->module_slug('license'),
            $this->config('license_page_title', $this->module_name().' &bull; '.__('License management', 'gumpress')),
            [$this, '_license_settings_callback'],
            $this->module_slug('license')
        );

        add_settings_field(
            $this->module_slug('license_key'),
            $this->config('label_license_key', __('License key', 'gumpress')),
            [$this, '_license_fields_markup'],
            $this->module_slug('license'),
            $this->module_slug('license')
        );

        register_setting($this->module_slug('license'), $this->module_slug('license_key'));
    }

    public function _license_settings_callback()
    {
        if ($callback = $this->callback('license_page_top')) {
            call_user_func($callback, $this);
        } else {
            echo __('Enter your license key below to validate your purchase.', 'gumpress');
        }
    }

    public function _license_fields_markup()
    {

        echo <<<MARKUP
        <input type="text" id="{$this->module_slug('license_key')}"
               name="{$this->module_slug('license_key')}"
               value="{$this->license_key()}" autocomplete="off">
        MARKUP;
    }

    public function license_page_link()
    {
        return (($this->config('type') == 'plugin') ? 'options.php' : 'themes.php').'?page='.$this->module_slug('license');
    }

    public function _register_license_page()
    {
        add_submenu_page(
            $this->config('type') == 'plugin' ? 'options.php' : 'themes.php',
            $this->config('license_page_title', $this->module_name().' &bull; '.__('License management', 'gumpress')),
            $this->config('license_page_menu', $this->module_name().' '.__('license', 'gumpress')),
            $this->config('type') == 'plugin' ? 'manage_options' : 'switch_themes',
            $this->module_slug('license'),
            [$this, '_render_license_page'],
        );
    }

    public function _replace_footer_admin()
    {
        echo '<em>Protected with &hearts; by <a href="https://wordpress.org/plugins/wooplatnica/" target="_blank">GumPress</a></em>. ';
    }

    public function _render_license_page()
    {
        if (isset($_GET['revalidate']) && $_GET['revalidate'] == 'true') {
            delete_transient($this->module_slug('license_cache'));
            wp_redirect($this->license_page_link());
        }

        if ($callbackCss = $this->callback('license_page_css')) {
            echo "<style>";
            call_user_func($callbackCss, $this);
            echo "</style>";
        } else {

            echo <<<GUMPRESS_CSS
            <style>
                .form-table th {
                    width: 100px;
                    text-align: left;
                }
                .form-table td input {
                    width: 400px;
                }
                #license {
                    border: 1px black solid;
                    border-collapse: collapse;
                }
                #license th, #license td {
                    border: 1px black solid;
                    padding: 0 5px 0 5px;
                }
            </style>
            GUMPRESS_CSS;
        }


        echo <<<FORM_OPEN
        <table width="80%">
            <tr>
                <td>
                    <form method="POST" id="{$this->module_slug('license-form')}" action="options.php">
        FORM_OPEN;

        settings_fields($this->module_slug('license'));
        do_settings_sections($this->module_slug('license'));
        echo "<p>";
        submit_button($this->config('save_key_button', __('Save key', 'gumpress')), 'primary large', 'submit', false);
        echo ' <a href="?page='.$this->module_slug('license').'&revalidate=true" class="button">'.$this->config('revalidate_button', __('Re-validate', 'gumpress')).'</a>';
        echo "</p>";

        echo <<<FORM_CLOSE
                    </form>
                </td>
                <td>
                    <table id="license">
        FORM_CLOSE;

        if (!$this->is_valid_license()) {
            echo '<tr><th>'.$this->config('label_status', __('Status', 'gumpress')).'</th><td><span style="color:red">'.$this->config('label_invalid', __('INVALID', 'gumpress')).'</span></td></tr>';
            echo '<tr><th>'.$this->config('label_reason', __('Reason', 'gumpress')).'</th><td>'.$this->license_description().'</td></tr>';
            delete_transient($this->module_slug('license_cache'));
        } else {
            $license = $this->license();
            echo '<tr><th>'.$this->config('label_status', __('Status', 'gumpress')).'</th><td><span style="color:green">'.$this->config('label_valid', __('VALID', 'gumpress')).'</span></td></tr>';
            if ($this->is_recurring()) {
                echo '<tr><th>'.$this->config('label_plan', __('Plan', 'gumpress')).'</th><td>'.$license->get('purchase')->get('recurrence').' '.$license->get('purchase')->get('variants').'</td></tr>';
            }
            if ($max = $this->config('max_uses')) {
                echo '<tr><th>'.$this->config('label_uses', __('Uses', 'gumpress')).'</th><td>'.$license->get('uses').'/'.$max.'</td></tr>';
            }

            if (!$this->config('hide_owner_email')) {
                echo '<tr><th>'.$this->config('label_owner', __('Owner', 'gumpress'))."</th><td>{$license->get('purchase')->get('email')}</td></tr>";
            }

            if (!$this->config('hide_custom_fields') && $this->license() && !empty($this->license()->get('purchase')->get('custom_fields'))) {
                foreach ((array)$this->license()->get('purchase')->get('custom_fields') as $line) {
                    $colon_pos = strpos($line, ':');
                    $key       = substr($line, 0, $colon_pos);
                    $val       = substr($line, $colon_pos + 2);
                    if (!empty($val) || is_numeric($val)) {
                        echo "<tr><th>{$key}</th><td>{$val}</td></tr>";
                    }
                }
            }
        }

        echo <<<CLOSE_TABLE
                    </table>

                </td>
            </tr>
        </table>
        CLOSE_TABLE;

        if ($callback = $this->callback('license_page_bottom')) {
            call_user_func($callback, $this);
        } elseif (!$this->is_valid_license()) {
            echo <<<GUMROAD
            <hr />
            <script src="https://gumroad.com/js/gumroad.js"></script>
            <a class="gumroad-button" href="https://gumroad.com/l/{$this->config('short_id')}" data-gumroad-single-product="true">Buy {$this->module_name()}</a>
            GUMROAD;
        }
    }

    public function _plugins_page_add_license_page_link($links)
    {
        $links = array_merge(array(
            '<a href="'.esc_url($this->license_page_link()).'">'.$this->config('license_page_label', __('License', 'gumpress')).'</a>'
        ), $links);

        return $links;
    }


    /**
     * @return DynamicArray|false
     */
    public function _update_server_request()
    {
        $license_key = $this->license_key();
        $cache_key   = $this->module_slug('update_cache');
        $remote      = get_transient($cache_key);

        if (false === $remote) {

            $remote = wp_remote_get(
                $this->config('update_check_url').'?license_key='.$license_key.'&site_url='.urlencode(get_home_url()).'&product_permalink='.$this->config('short_id').'&wp_version='.get_bloginfo('version').'&version='.$this->module_data('Version'),
                [
                    'timeout' => 10,
                    'headers' => [
                        'Accept' => 'application/json',
                    ],
                ]
            );

            if (
                is_wp_error($remote)
                || 200 !== wp_remote_retrieve_response_code($remote)
                || empty(wp_remote_retrieve_body($remote))
            ) {
                return false;
            }

            set_transient($cache_key, $remote, DAY_IN_SECONDS);
        }

        $remote = new DynamicArray((array)json_decode(wp_remote_retrieve_body($remote)));

        return $remote;
    }

    public function _update_info($res, $action, $args)
    {
        if (!in_array($action, ['plugin_information', 'theme_information'])) {
            return false;
        }

        if ($this->module_slug() !== ((array)$args)['slug']) {
            return false;
        }

        $remote = $this->_update_server_request();

        if (!$remote) {
            return false;
        }

        if ($this->config('type') == 'theme') {
            return $remote->toArray();
        }

        $remote->__set('sections', [
            'description'  => $remote->get('sections')->get('description'),
            'installation' => $remote->get('sections')->get('installation'),
            'changelog'    => $remote->get('sections')->get('changelog'),
        ]);

        if (!empty($remote->get('banners'))) {
            $remote->__set('banners', [
                'low'  => $remote->get('banners')->get('low'),
                'high' => $remote->get('banners')->get('high'),
            ]);
        }

        return (object)$remote->data;
    }

    public function _update_process($transient)
    {
        if (empty(((array)$transient)['checked'])) {
            return $transient;
        }

        $remote = $this->_update_server_request();

        if (
            $remote
            && version_compare($this->module_data('Version'), $remote->get('version'), '<')
            && version_compare($remote->get('requires'), get_bloginfo('version'), '<')
            && version_compare($remote->get('requires_php'), PHP_VERSION, '<')
        ) {
            $res = [
                'slug'        => $remote->get('slug'),
                'plugin'      => $remote->get('plugin'),
                'new_version' => $remote->get('version'),
                'tested'      => $remote->get('tested'),
                'package'     => $remote->get('download_url'),
            ];

            $transientArr                             = ((array)$transient);
            $transientArr['response'][$res['plugin']] = $this->config('type') == 'plugin' ? (object)$res : $res;
            return (object)$transientArr;
        }

        return $transient;
    }

    public function _update_purge($that, $options)
    {

        if ('update' === $options['action']
            && in_array($options['type'], ['plugin', 'theme'])
        ) {
            delete_transient($this->module_slug('update_cache'));
        }

    }

    public function was_config_encrypted()
    {
        return (bool)$this->config('_encrypted', false);
    }


    public static array $plugins = [];

    public static function register($plugin_file, $short_id, $options = [], $callbacks = [])
    {
        if (is_string($options)) {
            $checksum = substr($options, -8);
            $options  = substr($options, 0, -8);
            if (dh(cr($options.$short_id)) !== $checksum) {
                error_reporting(0);
                wp_die(dirname(plugin_basename($plugin_file)).": Config was tampered with. Please reinstall the plugin!");
            }
            $options               = @jd(r(gi(bd(r($options))))) ?: [];
            $options['_encrypted'] = true;
        }

        $type = 'plugin';

        if (strpos($plugin_file, '/themes/') !== false) {
            $type        = 'theme';
            $plugin_file = basename(dirname($plugin_file)).'/style.css';
        }

        if (!isset(static::$plugins[$short_id])) {
            static::$plugins[$short_id] = [
                'plugin_file' => $plugin_file,
                'short_id'    => $short_id,
                'type'        => $type,
            ];
        } else {
            static::$plugins[$short_id]['plugin_file'] = $plugin_file;
            static::$plugins[$short_id]['short_id']    = $short_id;
        }

        $options = array_merge([
            'cache_time'        => WEEK_IN_SECONDS,
            'plugins_page_link' => true,
            'max_uses'          => 1,
            '_encrypted'        => false,
        ], $options);

        $options['callbacks'] = $callbacks;

        static::$plugins[$short_id] = array_merge(static::$plugins[$short_id], $options);

        //register wp hooks
        static::for($short_id)->_register_hooks();
    }

    public static function for($short_id)
    {
        if (isset(static::$plugins[$short_id])) {
            return new static(static::$plugins[$short_id]);
        }

        wp_die(sprintf(__("<b>GumPress</b>: No module with short ID \"%s\" is registered!", 'gumpress'), $short_id));
    }

}

class DynamicArray implements ArrayAccess
{
    public array $data = [];

    public function __construct(array $data)
    {
        $this->data = $data;
    }

    public function __call($field, $args = [])
    {
        return $this->get($field);
    }

    public function get($field)
    {
        return isset($this->data[$field]) && (is_object($this->data[$field])) ? new self((array)$this->data[$field]) : $this->data[$field] ?? null;
    }

    public function __get($field)
    {
        return $this->get($field);
    }

    public function __set($field, $value)
    {
        return $this->data[$field] = $value;
    }

    public function __toArray(): array
    {
        return $this->data;
    }

    public function offsetSet($offset, $value)
    {
        if (is_null($offset)) {
            $this->data[] = $value;
        } else {
            $this->data[$offset] = $value;
        }
    }

    public function offsetExists($offset)
    {
        return isset($this->data[$offset]);
    }

    public function offsetUnset($offset)
    {
        unset($this->data[$offset]);
    }

    public function offsetGet($offset)
    {
        return $this->data[$offset] ?? null;
    }
}

class GumroadLicense extends DynamicArray
{
}

function jd($s)
{
    return json_decode($s, true);
}

function gi($s)
{
    return gzinflate($s);
}

function bd($s)
{
    return base64_decode($s);
}

function cr($s)
{
    return sprintf('%u', crc32($s));
}

function dh($s)
{
    return dechex($s);
}

function r($s)
{
    return str_rot13($s);
}

function t($s)
{
    return trim($s, '=');
}

if (!function_exists('str_starts_with')) {
    function str_starts_with($haystack, $needle, $case = true)
    {
        if ($case) {
            return strpos($haystack, $needle, 0) === 0;
        }
        return stripos($haystack, $needle, 0) === 0;
    }
}

if (!function_exists('str_ends_with')) {
    function str_ends_with($haystack, $needle, $case = true)
    {
        $expectedPosition = strlen($haystack) - strlen($needle);
        if ($case) {
            return strrpos($haystack, $needle, 0) === $expectedPosition;
        }
        return strripos($haystack, $needle, 0) === $expectedPosition;
    }
}