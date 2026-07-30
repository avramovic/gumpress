<?php

declare(strict_types=1);

namespace GumPress\V2;

/**
 * One licensed plugin or theme. Unlike v1's GumPress::for($id), which
 * constructed a brand-new instance on every call (so a status computed by
 * one call was thrown away by the next, and license() was re-derived three
 * or four times per admin page load), a Module is created once by
 * Engine::create() and lives for the rest of the request.
 */
final class Module
{
    private string $file;
    private string $product;
    private Config $base_config;
    private ?Config $effective_config_cache = null;
    private string $type;
    private array $owned_dirs;
    private bool $network_wide;
    private Api $api;
    private ?Status $status_cache = null;

    private function __construct(string $file, string $product, Config $config)
    {
        $this->file = $file;
        $this->product = $product;
        $this->base_config = $config;
        $this->type = $this->detect_type();
        $this->owned_dirs = $this->detect_owned_dirs();
        $this->network_wide = is_multisite() && $this->detect_network_activation();
        $this->api = new Api($this);
    }

    public static function create(string $file, string $product, Config $config): self
    {
        return new self($file, $product, $config);
    }

    public function product_id(): string
    {
        return $this->product;
    }

    /** Base config (defaults + register() options), never with server overrides applied. */
    public function base_config(): Config
    {
        return $this->base_config;
    }

    /**
     * Effective config: base_config() with the server's own overrides
     * (see Overrides::apply()) layered on top, lazily built from the last
     * verify response and memoized for the rest of the request.
     */
    public function config(): Config
    {
        if ($this->effective_config_cache === null) {
            $this->effective_config_cache = Overrides::apply($this->base_config, $this->api->overrides());
        }

        return $this->effective_config_cache;
    }

    /** Called by Api after a fresh verification, and on a license key change. */
    public function forget_effective_config(): void
    {
        $this->effective_config_cache = null;
        $this->status_cache = null;
    }

    public function api(): Api
    {
        return $this->api;
    }

    public function type(): string
    {
        return $this->type;
    }

    public function owned_dirs(): array
    {
        return $this->owned_dirs;
    }

    public function is_network_wide(): bool
    {
        return $this->network_wide;
    }

    /** Unschedules this module's refresh event. Called on deactivation. */
    public function unschedule(): void
    {
        $hook = $this->cron_hook();

        while (($timestamp = wp_next_scheduled($hook)) !== false) {
            wp_unschedule_event($timestamp, $hook);
        }
    }

    /**
     * Removes every trace of this module from the database. Called on
     * uninstall — before this existed, all of it (plus the cron event)
     * survived plugin deletion forever.
     */
    public function uninstall(): void
    {
        $this->unschedule();
        $this->api->purge();

        foreach (['license_key', 'schema'] as $suffix) {
            $name = $this->option_name($suffix);

            if ($this->network_wide) {
                delete_site_option($name);
            } else {
                delete_option($name);
            }
        }

        delete_transient($this->option_name('update_cache'));
    }

    private function cron_hook(): string
    {
        return 'gumpress_refresh_' . $this->product;
    }

    private function detect_type(): string
    {
        return self::type_for($this->file, $this->base_config->get('type'));
    }

    /**
     * @param mixed $configured
     */
    private static function type_for(string $file, $configured): string
    {
        if (in_array($configured, ['plugin', 'theme'], true)) {
            return $configured;
        }

        // Auto-detect from the file's location rather than v1's
        // strpos($file, '/themes/'), which silently misclassifies every theme
        // as a plugin on Windows (backslash paths never contain '/themes/').
        $file = wp_normalize_path($file);
        if (function_exists('get_theme_root') && str_starts_with($file, wp_normalize_path(get_theme_root()) . '/')) {
            return 'theme';
        }

        return 'plugin';
    }

    private function detect_owned_dirs(): array
    {
        $dirs = [];

        if ($this->type === 'theme') {
            // Child themes load functions.php before the parent theme's, so a
            // module registered from the parent must still resolve calls made
            // from the child's files: claim both roles.
            if (function_exists('get_template_directory')) {
                $dirs[] = wp_normalize_path(get_template_directory());
            }
            if (function_exists('get_stylesheet_directory')) {
                $dirs[] = wp_normalize_path(get_stylesheet_directory());
            }
        }

        $dirs[] = wp_normalize_path(dirname($this->file));

        return array_values(array_unique($dirs));
    }

    private function detect_network_activation(): bool
    {
        if ($this->type !== 'plugin' || !function_exists('is_plugin_active_for_network')) {
            return false;
        }

        return is_plugin_active_for_network($this->module_basename());
    }

    public function module_basename(): string
    {
        return $this->type === 'plugin' && function_exists('plugin_basename')
            ? plugin_basename($this->file)
            : wp_normalize_path($this->file);
    }

    public function slug(): string
    {
        return $this->type === 'theme'
            ? basename(dirname($this->module_basename()))
            : dirname($this->module_basename());
    }

    public function text_domain(): string
    {
        return (string) ($this->base_config->get('text_domain') ?? $this->slug());
    }

    /**
     * @return array|string|null
     */
    public function module_data(?string $key = null)
    {
        $data = $this->read_module_data();

        return $key === null ? $data : ($data[$key] ?? null);
    }

    private function read_module_data(): array
    {
        return self::read_headers($this->file, $this->type);
    }

    /**
     * The plugin/theme header lookup behind module_data(), factored out as a
     * static so bootstrap-time code (Engine, the gumpress.php facade) can
     * identify a module by name before — or without — a Module ever being
     * constructed. See label() below.
     */
    public static function read_headers(string $file, ?string $type = null): array
    {
        static $cache = [];

        $type = $type ?? self::type_for($file, null);
        $cache_key = $file . '|' . $type;
        if (isset($cache[$cache_key])) {
            return $cache[$cache_key];
        }

        if ($type === 'plugin') {
            // get_file_data() lives in wp-includes/functions.php, which core loads
            // unconditionally — unlike get_plugin_data(), it's always available,
            // including on front-end requests. v1 used get_plugin_data() guarded by
            // function_exists(), which silently fell through to reading THEME
            // headers on the frontend, reporting a plugin's own name/version wrong.
            $headers = get_file_data($file, [
                'Name' => 'Plugin Name',
                'Version' => 'Version',
                'Author' => 'Author',
                'Description' => 'Description',
            ]);
        } else {
            $theme = wp_get_theme(basename(dirname(wp_normalize_path($file))));
            $headers = [
                'Name' => (string) $theme->get('Name'),
                'Version' => (string) $theme->get('Version'),
                'Author' => wp_strip_all_tags((string) $theme->get('Author')),
                'Description' => (string) $theme->get('Description'),
            ];
        }

        return $cache[$cache_key] = $headers;
    }

    /**
     * Human-readable identification for bootstrap notices, which only have a
     * file path and an opaque Gumroad product_id to work with — no Module
     * exists yet at the point most of these fire. Never throws: a licensing
     * library must not fatal, least of all while reporting an error, so an
     * unreadable header just falls back to the bare id, exactly as before
     * this existed.
     */
    public static function label(string $file, string $product, ?string $type = null): string
    {
        if (!function_exists('get_file_data')) {
            return sprintf('"%s"', $product);
        }

        try {
            $name = (string) (self::read_headers($file, $type)['Name'] ?? '');
        } catch (\Throwable $e) {
            $name = '';
        }

        return $name === ''
            ? sprintf('"%s"', $product)
            : sprintf('"%s"', $name);
    }

    public function license_key(): ?string
    {
        $key = $this->option_get($this->option_name('license_key'), '');

        return $key === '' ? null : (string) $key;
    }

    public function set_license_key(string $key): void
    {
        $this->option_set($this->option_name('license_key'), $key);
        $this->forget_effective_config();
    }

    public function option_name(string $suffix): string
    {
        return 'gumpress_' . $this->product . '_' . $suffix;
    }

    /**
     * @param mixed $default
     * @return mixed
     */
    private function option_get(string $name, $default)
    {
        return $this->network_wide ? get_site_option($name, $default) : get_option($name, $default);
    }

    /**
     * @param mixed $value
     */
    private function option_set(string $name, $value): void
    {
        if ($this->network_wide) {
            update_site_option($name, $value);
        } else {
            update_option($name, $value, false);
        }
    }

    public function status(): Status
    {
        if ($this->status_cache !== null) {
            return $this->status_cache;
        }

        if ($this->license_key() === null) {
            return $this->status_cache = new Status(Status::NO_KEY);
        }

        // Never performs network I/O — verification is scheduled separately
        // via Api::ensure_scheduled() / the daily refresh cron event.
        $license = $this->api->license();
        $reachable = $this->api->last_reachable();

        return $this->status_cache = Validator::evaluate($license, $reachable, $this->api->valid_at(), $this->config());
    }

    public function valid(): bool
    {
        return $this->status()->is_valid();
    }

    public function reason(): string
    {
        return Strings::reason($this->status(), $this->text_domain());
    }

    public function license(): ?License
    {
        return $this->api->license();
    }

    public function is_subscription(): bool
    {
        return (bool) $this->license()?->is_subscription();
    }

    public function tier(): ?string
    {
        return $this->license()?->tier();
    }

    public function has_tier(string $tier): bool
    {
        return (bool) $this->license()?->has_tier($tier);
    }

    /**
     * @return array|string|null
     */
    public function meta(?string $key = null)
    {
        $license = $this->license();
        if ($license === null) {
            return $key === null ? [] : null;
        }

        return $key === null ? $license->meta() : $license->meta_field($key);
    }

    /**
     * @return mixed
     */
    public function extra(?string $key = null)
    {
        return $this->license()?->extra($key);
    }

    public function seat_over_limit(): bool
    {
        return Validator::seat_over_limit($this->license(), $this->config())
            && $this->config()->get('max_uses_policy', 'block') === 'warn';
    }

    public function license_page_link(): string
    {
        $base = $this->type === 'plugin' ? 'options-general.php' : 'themes.php';

        return $base . '?page=' . rawurlencode($this->page_slug());
    }

    /**
     * `$this->product` is now a Gumroad product_id — an opaque, often
     * base64-looking string that isn't guaranteed URL/slug-safe (it can
     * contain characters like `/`, `+`, `=`). The optional `permalink`
     * config option, when set, keeps this human-readable and stable
     * (`?page=gumpress-acme-pro`, exactly as before this option existed);
     * otherwise fall back to a short hash of the product_id — still stable
     * per-product, always URL-safe, just not pretty until `permalink` is set.
     */
    public function page_slug(): string
    {
        $permalink = $this->base_config->get('permalink');

        return 'gumpress-' . ($permalink ?? substr(hash('sha256', $this->product), 0, 16));
    }

    public function manage_capability(): string
    {
        return $this->type === 'plugin' ? 'manage_options' : 'switch_themes';
    }

    public function boot_hooks(): void
    {
        Admin::register($this);

        if ($this->config()->get('update_check_url')) {
            Updater::register($this);
        }

        $cron_hook = $this->cron_hook();
        add_action($cron_hook, function () {
            $this->api->force_refresh();
        });
        if (!wp_next_scheduled($cron_hook)) {
            wp_schedule_event(time() + wp_rand(0, HOUR_IN_SECONDS), 'twicedaily', $cron_hook);
        }

        add_action('admin_init', function () {
            if (is_admin() && !wp_doing_ajax() && current_user_can($this->manage_capability())) {
                $this->api->ensure_scheduled();
            }
        });
    }
}
