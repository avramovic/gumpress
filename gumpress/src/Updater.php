<?php

declare(strict_types=1);

namespace GumPress\V2;

/**
 * Self-hosted update server integration. Fixes, vs. v1:
 *
 *  - `_update_info()` returned `false` instead of the incoming $res on a
 *    non-matching action/slug, at priority 20 — silently discarding any
 *    other plugin's own plugins_api/themes_api result. This always returns
 *    $res unless it actually has an answer.
 *  - Themes were filtered on the write-time `pre_set_site_transient_update_themes`
 *    while plugins used the read-time `site_transient_update_plugins`; the
 *    write-time filter persists an injected update, so it survives the
 *    license going invalid until the next cache refresh. Both now use the
 *    read-time `site_transient_update_{plugins,themes}` filter.
 *  - `$remote->toArray()` didn't exist (only `__toArray()` did), silently
 *    swallowed by __call(), so theme update info always returned null. Theme
 *    info is now returned directly as the decoded array WordPress expects.
 *  - `download_url` vs. `package`: the response can use either key.
 *  - `version_compare(requires, wp_version, '<')` blocked updates on an exact
 *    version match instead of allowing it; now checks the running versions
 *    satisfy the remote's stated requirements (`>=`), the correct direction.
 */
final class Updater
{
    public static function register(Module $module): void
    {
        if ($module->type() === 'plugin') {
            add_filter('plugins_api', static function ($res, $action, $args) use ($module) {
                return self::info($res, $action, $args, $module);
            }, 20, 3);
            add_filter('site_transient_update_plugins', static function ($transient) use ($module) {
                return self::inject($transient, $module);
            });
        } else {
            add_filter('themes_api', static function ($res, $action, $args) use ($module) {
                return self::info($res, $action, $args, $module);
            }, 20, 3);
            add_filter('site_transient_update_themes', static function ($transient) use ($module) {
                return self::inject($transient, $module);
            });
        }

        add_action('upgrader_process_complete', static function ($upgrader, $options) use ($module) {
            if (
                ($options['action'] ?? null) === 'update'
                && in_array($options['type'] ?? null, ['plugin', 'theme'], true)
            ) {
                delete_transient($module->option_name('update_cache'));
            }
        }, 10, 2);
    }

    private static function fetch(Module $module): ?array
    {
        $cache_key = $module->option_name('update_cache');
        $cached = Vault::open(get_transient($cache_key));
        if (is_array($cached)) {
            return $cached;
        }

        $url = $module->config()->get('update_check_url');
        if (!$url) {
            return null;
        }

        $response = wp_remote_get(add_query_arg([
            'license_key' => (string) $module->license_key(),
            // See Api.php's post() — product_id, not product_permalink, is
            // what Gumroad's real API and our own compat routes resolve by.
            'product_id' => $module->product_id(),
            'site_url' => home_url('/'),
            'wp_version' => get_bloginfo('version'),
            'version' => (string) $module->module_data('Version'),
        ], $url), [
            'timeout' => 10,
            'headers' => ['Accept' => 'application/json'],
        ]);

        if (is_wp_error($response)) {
            return null;
        }

        $code = wp_remote_retrieve_response_code($response);
        $body = json_decode((string) wp_remote_retrieve_body($response), true);

        // A server denying the update (e.g. "your license isn't valid for this
        // domain") can still carry a notice explaining why — surface it even
        // though the response itself, per the contract below, is never cached.
        if (is_array($body) && !empty($body['notice']) && is_array($body['notice'])) {
            self::queue_notice($module, $body['notice']);
        }

        // Any non-200 means "no update, and don't cache that" — the opposite of
        // the verify endpoint's rule. This is what makes the very next check
        // after a customer fixes their license work immediately, with no stale
        // transient to wait out.
        if ($code !== 200 || !is_array($body)) {
            return null;
        }

        // Sealed like the verify state: this body carries the package URL,
        // which on a licensing server is often a signed/tokenized download
        // link. An unreadable blob just means a refetch on the next check.
        set_transient($cache_key, Vault::seal($body), 6 * HOUR_IN_SECONDS);

        return $body;
    }

    private static function queue_notice(Module $module, array $notice): void
    {
        $text = isset($notice['text']) && is_string($notice['text']) && $notice['text'] !== ''
            ? $notice['text']
            : null;
        if ($text === null) {
            return;
        }

        $level = isset($notice['level']) && is_string($notice['level']) ? $notice['level'] : 'warning';
        $url = isset($notice['url']) && is_string($notice['url']) && $notice['url'] !== ''
            ? $notice['url']
            : $module->license_page_link();

        $html = sprintf(
            '%s: %s <a href="%s">%s</a>',
            esc_html((string) $module->module_data('Name')),
            esc_html($text),
            esc_url($url),
            esc_html__('Manage license', $module->text_domain())
        );

        Notices::queue_html($html, $level);
    }

    /**
     * @param mixed $res
     * @param mixed $args
     * @return mixed
     */
    private static function info($res, string $action, $args, Module $module)
    {
        if (!in_array($action, ['plugin_information', 'theme_information'], true)) {
            return $res;
        }

        $slug = is_object($args) ? ($args->slug ?? null) : ($args['slug'] ?? null);
        if ($slug !== $module->slug()) {
            return $res;
        }

        $remote = self::fetch($module);
        if ($remote === null) {
            return $res;
        }

        if ($module->type() === 'theme') {
            return $remote;
        }

        $data = (object) [
            'name' => $remote['name'] ?? $module->module_data('Name'),
            'slug' => $module->slug(),
            'version' => $remote['version'] ?? null,
            'author' => $remote['author'] ?? null,
            'requires' => $remote['requires'] ?? null,
            'tested' => $remote['tested'] ?? null,
            'requires_php' => $remote['requires_php'] ?? null,
            'download_link' => $remote['package'] ?? $remote['download_url'] ?? null,
            'sections' => (array) ($remote['sections'] ?? []),
        ];

        if (!empty($remote['banners'])) {
            $data->banners = (array) $remote['banners'];
        }

        return $data;
    }

    /**
     * @param mixed $transient
     * @return mixed
     */
    private static function inject($transient, Module $module)
    {
        if (empty($transient->checked)) {
            return $transient;
        }

        $remote = self::fetch($module);
        if (!$remote || empty($remote['version'])) {
            return $transient;
        }

        $current_version = (string) $module->module_data('Version');
        $satisfies_wp = empty($remote['requires']) || version_compare(get_bloginfo('version'), (string) $remote['requires'], '>=');
        $satisfies_php = empty($remote['requires_php']) || version_compare(PHP_VERSION, (string) $remote['requires_php'], '>=');
        $is_newer = version_compare($current_version, (string) $remote['version'], '<');

        if (!$is_newer || !$satisfies_wp || !$satisfies_php) {
            return $transient;
        }

        $package = $remote['package'] ?? $remote['download_url'] ?? '';

        // The plugin/theme update transient is a plain stdClass with dynamic
        // properties — no declared class in WordPress core.
        if ($module->type() === 'plugin') {
            $transient->response[$module->module_basename()] = (object) [
                'slug' => $module->slug(),
                'plugin' => $module->module_basename(),
                'new_version' => $remote['version'],
                'url' => $remote['url'] ?? '',
                'package' => $package,
                'tested' => $remote['tested'] ?? '',
            ];
        } else {
            $transient->response[$module->slug()] = [
                'theme' => $module->slug(),
                'new_version' => $remote['version'],
                'url' => $remote['url'] ?? '',
                'package' => $package,
            ];
        }

        return $transient;
    }
}
