<?php
/**
 * GumPress — drop-in Gumroad licensing for WordPress plugins and themes.
 *
 * Usage (the only place your product id appears):
 *
 *     require_once __DIR__ . '/gumpress/gumpress.php';
 *     GumPress::register(__FILE__, 'your-gumroad-permalink', $options);
 *
 * Everywhere else in your module:
 *
 *     if (GumPress::valid()) { ... }
 *     GumPress::reason();
 *     GumPress::license_key();
 *     GumPress::for('other-product')->valid(); // explicit escape hatch
 *
 * See gumpress/README.md for the full config reference and MIGRATION.md if
 * you are moving off GumPress 1.x.
 *
 * ---
 *
 * This file is intentionally small and must stay compatible with every
 * future GumPress release: several plugins/themes on one WordPress install
 * may each bundle their own copy, at different versions, and only one of
 * them can define the global `GumPress` class. Every copy always loads and
 * runs its OWN engine (see src/load.php) — there is no "one shared engine
 * wins" arbitration. Only this thin, contested facade class is shared, and
 * it never needs to know anything about a specific engine's internals: each
 * registered product is bound, at register() time, to the engine that
 * shipped alongside it.
 */

defined('ABSPATH') || die;

require_once __DIR__ . '/src/load.php';

$GLOBALS['gumpress']['sources'][wp_normalize_path(__DIR__)] = [
    'version' => '2.0.0',
    'create' => [\GumPress\V2\Engine::class, 'create'],
];

if (class_exists('GumPress', false)) {
    if (!(defined('GumPress::API') && \GumPress::API >= 2)) {
        \GumPress\V2\Notices::queue(
            'GumPress: an older, incompatible copy of GumPress is already active on this site '
            . 'and is blocking this one (' . wp_normalize_path(__DIR__) . '). '
            . 'Update every plugin/theme that bundles GumPress to the same version — see MIGRATION.md.'
        );
    }

    return;
}

/**
 * Frozen facade. Do not add functionality here beyond what's needed to queue
 * a registration and dispatch a call to the right product's Module — real
 * behaviour belongs in the versioned engine each copy loads for itself.
 */
final class GumPress
{
    const API = 2;

    /** @var array<string, object> product id => Module (or NullModule) instance. */
    private static array $modules = [];

    /** @var array<string, string|null> normalized caller file => resolved product id, incl. misses. */
    private static array $resolved = [];

    /** @var array<string, bool> product id => hooks already booted. */
    private static array $booted = [];

    private static bool $hooked = false;

    /**
     * @param array|string $options An options array, or an encrypted string
     *                               produced by this repo's encrypt.php tool.
     * @return object Module|NullModule
     */
    public static function register(string $file, string $product, $options = [])
    {
        if (func_num_args() > 3) {
            // v1's register($file, $id, $options, $callbacks) — v2 has no callbacks
            // parameter, so a 4th argument means an old call site, not new config.
            \GumPress\V2\Notices::queue(sprintf(
                'GumPress: "%s" was registered using an incompatible GumPress 1.x call signature '
                . 'and was not loaded. Update it to GumPress 2 — see MIGRATION.md.',
                $product
            ));

            return new \GumPress\V2\NullModule($product);
        }

        $dir = self::owning_source_dir($file);
        if ($dir === null) {
            \GumPress\V2\Notices::queue(sprintf(
                'GumPress: could not determine which bundled copy of GumPress "%s" belongs to.',
                $product
            ));

            return new \GumPress\V2\NullModule($product);
        }

        $create = $GLOBALS['gumpress']['sources'][$dir]['create'];
        $engine_options = is_string($options) ? ['__gumpress_encrypted' => $options] : (array) $options;

        try {
            $module = call_user_func($create, $file, $product, $engine_options);
        } catch (\Throwable $e) {
            error_log('GumPress: failed to initialize module "' . $product . '": ' . $e->getMessage());
            \GumPress\V2\Notices::queue(sprintf('GumPress: "%s" failed to initialize and has been disabled.', $product));

            return new \GumPress\V2\NullModule($product);
        }

        self::$modules[$product] = $module;
        self::hook_boot();

        if (did_action('after_setup_theme')) {
            // Late registration (e.g. during plugin activation, which runs after
            // after_setup_theme has already fired) — boot immediately rather than
            // waiting for a hook that has already run.
            self::boot_one($product, $module);
        }

        return $module;
    }

    /**
     * @return object Module|NullModule
     */
    public static function for(string $product)
    {
        return self::$modules[$product] ?? new \GumPress\V2\NullModule($product);
    }

    /**
     * @param array<int,mixed> $args
     * @return mixed
     */
    public static function __callStatic(string $method, array $args)
    {
        $product = self::resolve_caller();
        if ($product === null) {
            return (new \GumPress\V2\NullModule('unknown'))->$method(...$args);
        }

        return self::$modules[$product]->$method(...$args);
    }

    /**
     * Deterministic, backtrace-free resolution used by register(): finds the
     * registered gumpress/ source directory that is a direct child of the
     * calling file's own directory (the conventional {module}/gumpress/
     * layout). Falls back to the sole registered source, or the
     * highest-versioned one if the layout is non-standard.
     */
    private static function owning_source_dir(string $file): ?string
    {
        $sources = $GLOBALS['gumpress']['sources'] ?? [];
        if (!$sources) {
            return null;
        }

        $parent = wp_normalize_path(dirname($file));
        foreach ($sources as $dir => $source) {
            if (wp_normalize_path(dirname($dir)) === $parent) {
                return $dir;
            }
        }

        if (count($sources) === 1) {
            return array_key_first($sources);
        }

        uasort($sources, static fn ($a, $b) => version_compare($a['version'], $b['version']));

        return array_key_last($sources);
    }

    /**
     * Backtrace-based resolution used by every bare static call after
     * register(): walks frames until one carries a 'file' (call_user_func /
     * array_map frames sometimes don't), strips PHP's eval()'d-code suffix,
     * normalizes for Windows, then longest-prefix-matches against every
     * registered module's owned directories. Memoized per caller file,
     * including misses, so the cost (a few microseconds) is paid once per
     * call site rather than once per call.
     */
    private static function resolve_caller(): ?string
    {
        $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 8);

        $file = null;
        foreach ($trace as $frame) {
            if (!empty($frame['file'])) {
                $file = $frame['file'];
                break;
            }
        }

        if ($file === null) {
            return self::sole_module();
        }

        $eval_pos = strpos($file, '(');
        if ($eval_pos !== false && str_contains($file, "eval()'d code")) {
            $file = substr($file, 0, $eval_pos);
        }

        $file = wp_normalize_path($file);
        if (\DIRECTORY_SEPARATOR === '\\') {
            $file = strtolower($file);
        }

        if (array_key_exists($file, self::$resolved)) {
            return self::$resolved[$file];
        }

        $best = null;
        $best_len = 0;
        foreach (self::$modules as $product => $module) {
            foreach ($module->owned_dirs() as $dir) {
                if (\DIRECTORY_SEPARATOR === '\\') {
                    $dir = strtolower($dir);
                }
                if (($file === $dir || str_starts_with($file, $dir . '/')) && strlen($dir) > $best_len) {
                    $best = $product;
                    $best_len = strlen($dir);
                }
            }
        }

        if ($best === null) {
            $best = self::sole_module();
        }

        if ($best === null && function_exists('_doing_it_wrong')) {
            _doing_it_wrong(
                'GumPress::(bare static call)',
                'Could not resolve which registered module this call belongs to. '
                . 'Use GumPress::for($product_id) instead, or call from within the module\'s own directory.',
                '2.0.0'
            );
        }

        return self::$resolved[$file] = $best;
    }

    private static function sole_module(): ?string
    {
        return count(self::$modules) === 1 ? array_key_first(self::$modules) : null;
    }

    private static function hook_boot(): void
    {
        if (self::$hooked) {
            return;
        }
        self::$hooked = true;
        add_action('after_setup_theme', [self::class, 'boot_all'], 0);
    }

    public static function boot_all(): void
    {
        foreach (self::$modules as $product => $module) {
            self::boot_one($product, $module);
        }
    }

    private static function boot_one(string $product, $module): void
    {
        if (!empty(self::$booted[$product])) {
            return;
        }
        self::$booted[$product] = true;

        try {
            $module->boot_hooks();
        } catch (\Throwable $e) {
            // Never let a broken engine's hook registration escape — WordPress's
            // fatal-error handler blames whichever plugin file is on the stack,
            // which could easily be an unrelated, innocent plugin.
            error_log('GumPress: "' . $product . '" failed while registering hooks: ' . $e->getMessage());
        }
    }
}
