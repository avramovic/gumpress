<?php

declare(strict_types=1);

namespace GumPress\V2;

/**
 * License settings page, admin notices, and the plugins-list action link.
 *
 * Fixes vs. v1: the revalidate/save action is a nonce'd, capability-checked
 * POST (v1 acted on a bare `?revalidate=true` GET with neither, and never
 * called exit() after wp_redirect()); every dynamic value is escaped on
 * output (v1 echoed the license key into `value=`, and the owner email,
 * variants, and custom fields, all unescaped — a stored-XSS path via a
 * crafted checkout field or a compromised license/proxy response); the menu
 * parent is options-general.php, not options.php (the Settings API's form
 * handler, not a page).
 */
final class Admin
{
    public static function register(Module $module): void
    {
        add_action('admin_menu', static function () use ($module) {
            self::add_page($module);
        });

        add_action('admin_init', static function () use ($module) {
            self::handle_submission($module);
        });

        if (!$module->config()->get('suppress_notices')) {
            add_action('admin_notices', static function () use ($module) {
                self::render_notice($module);
            });
        }

        if ($module->config()->get('plugins_page_link') && $module->type() === 'plugin') {
            add_filter('plugin_action_links_' . $module->module_basename(), static function ($links) use ($module) {
                return self::add_plugin_link($links, $module);
            });

            if ($module->is_network_wide()) {
                add_filter(
                    'network_admin_plugin_action_links_' . $module->module_basename(),
                    static function ($links) use ($module) {
                        return self::add_plugin_link($links, $module);
                    }
                );
            }
        }

        if (!$module->base_config()->is_white_label()) {
            add_action('load-' . self::hook_suffix($module), static function () {
                add_filter('admin_footer_text', [self::class, 'footer_text']);
            });
        }

        if ($module->config()->get('hide_menu_page')) {
            add_action('admin_head', static function () use ($module) {
                self::hide_menu_item($module);
            });
        }
    }

    /**
     * Hides the menu item with CSS rather than remove_submenu_page(): removing
     * the entry from $submenu breaks WordPress's own get_admin_page_title()
     * lookup for the page (it walks $submenu to find the title), leaving the
     * $title global null and producing a strip_tags(null) deprecation notice
     * in admin-header.php. The page itself stays registered and reachable —
     * only its row in the nav is hidden.
     */
    private static function hide_menu_item(Module $module): void
    {
        printf(
            '<style>#adminmenu a[href="%s"]{display:none}</style>',
            esc_attr($module->license_page_link())
        );
    }

    private static function hook_suffix(Module $module): string
    {
        $parent = $module->type() === 'plugin' ? 'settings_page_' : 'appearance_page_';

        return $parent . $module->page_slug();
    }

    public static function footer_text(): string
    {
        return '<em>' . sprintf(
            /* translators: %s: link to the GumPress project. */
            __('Protected with &hearts; by %s', 'gumpress'),
            '<a href="https://gumpress.eu" target="_blank" rel="noopener noreferrer">GumPress</a>'
        ) . '</em>';
    }

    private static function add_page(Module $module): void
    {
        $parent = $module->type() === 'plugin' ? 'options-general.php' : 'themes.php';
        $default_label = $module->module_data('Name') . ' ' . __('License', $module->text_domain());
        $title = (string) ($module->config()->get('license_page_title') ?? $default_label);
        $menu = (string) ($module->config()->get('license_page_menu') ?? $default_label);

        add_submenu_page(
            $parent,
            $title,
            $menu,
            $module->manage_capability(),
            $module->page_slug(),
            static function () use ($module) {
                self::render_page($module);
            }
        );
    }

    private static function handle_submission(Module $module): void
    {
        if (!isset($_POST['gumpress_action']) || $_POST['gumpress_action'] !== $module->product_id()) {
            return;
        }

        if (!current_user_can($module->manage_capability())) {
            return;
        }

        check_admin_referer('gumpress_' . $module->product_id());

        if (isset($_POST['gumpress_license_key'])) {
            $module->set_license_key(sanitize_text_field(wp_unslash($_POST['gumpress_license_key'])));
            $module->api()->force_refresh();
        } elseif (isset($_POST['gumpress_revalidate'])) {
            $module->api()->force_refresh();
        }

        wp_safe_redirect($module->license_page_link());
        exit;
    }

    private static function render_notice(Module $module): void
    {
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if ($screen && $screen->id === self::hook_suffix($module)) {
            return; // the license page itself already shows full status detail.
        }

        $domain = $module->text_domain();
        $name = esc_html((string) $module->module_data('Name'));
        $manage_link = sprintf(
            ' <a href="%s">%s</a>',
            esc_url($module->license_page_link()),
            esc_html__('Manage license', $domain)
        );

        if ($module->license_key() === null) {
            if ($module->config()->get('suppress_key_notice')) {
                return;
            }
            printf(
                '<div class="notice notice-info"><p>%s: %s%s</p></div>',
                $name,
                esc_html__('No license key found.', $domain),
                $manage_link
            );

            return;
        }

        $status = $module->status();

        if (!$status->is_valid()) {
            printf(
                '<div class="notice notice-error"><p>%s: %s%s</p></div>',
                $name,
                esc_html($module->reason()),
                $manage_link
            );

            return;
        }

        $warn_codes = [Status::PAYMENT_FAILED_GRACE, Status::CANCELLED_PENDING_END, Status::VALID_OFFLINE];
        if (in_array($status->code(), $warn_codes, true) || $module->seat_over_limit()) {
            printf(
                '<div class="notice notice-warning"><p>%s: %s%s</p></div>',
                $name,
                esc_html($module->reason()),
                $manage_link
            );
        }
    }

    private static function add_plugin_link(array $links, Module $module): array
    {
        array_unshift($links, sprintf(
            '<a href="%s">%s</a>',
            esc_url($module->license_page_link()),
            esc_html__('License', $module->text_domain())
        ));

        return $links;
    }

    private static function render_page(Module $module): void
    {
        $domain = $module->text_domain();
        $status = $module->status();
        $license = $module->license();

        echo '<div class="wrap">';
        echo '<h1>' . esc_html((string) $module->module_data('Name')) . ' &bull; ' . esc_html__('License', $domain) . '</h1>';

        if ($top = $module->config()->callback('license_page_top')) {
            call_user_func($top, $module);
        }

        echo '<form method="post">';
        wp_nonce_field('gumpress_' . $module->product_id());
        echo '<input type="hidden" name="gumpress_action" value="' . esc_attr($module->product_id()) . '">';
        echo '<table class="form-table"><tr>';
        echo '<th scope="row"><label for="gumpress_license_key">' . esc_html__('License key', $domain) . '</label></th>';
        echo '<td><input type="text" id="gumpress_license_key" name="gumpress_license_key" class="regular-text" autocomplete="off" value="'
            . esc_attr((string) $module->license_key()) . '"></td>';
        echo '</tr></table>';
        submit_button(__('Save key', $domain), 'primary', 'submit', false);
        echo ' <button type="submit" name="gumpress_revalidate" value="1" class="button">'
            . esc_html__('Re-validate', $domain) . '</button>';
        echo '</form>';

        echo '<h2>' . esc_html__('Status', $domain) . '</h2>';
        echo '<table class="widefat" style="max-width:600px">';

        self::row(
            esc_html__('Status', $domain),
            $status->is_valid()
                ? '<span style="color:green">' . esc_html__('VALID', $domain) . '</span>'
                : '<span style="color:red">' . esc_html__('INVALID', $domain) . '</span>'
        );
        self::row(esc_html__('Reason', $domain), esc_html($module->reason()));

        // Guard on the key itself, not just $license !== null: a cached payload
        // from a previously-removed key must never render alongside "no license
        // key found" above it.
        if ($license !== null && $module->license_key() !== null) {
            if ($license->is_subscription()) {
                $plan = trim(((string) ($license->recurrence() ?? '')) . ' ' . ((string) ($license->tier() ?? '')));
                self::row(esc_html__('Plan', $domain), esc_html($plan));
            }

            $server_seats = $license->server_seats();
            if ($license->has_server_seats()) {
                $limit = !empty($server_seats['unlimited'])
                    ? esc_html__('unlimited', $domain)
                    : esc_html((string) ($server_seats['limit'] ?? 0));

                self::row(
                    esc_html__('Activations', $domain),
                    esc_html((string) ($server_seats['used'] ?? $license->uses())) . ' / ' . $limit
                );
            } else {
                $max = (int) $module->config()->get('max_uses', 0);
                if ($max > 0) {
                    self::row(
                        esc_html__('Activations', $domain),
                        esc_html((string) $license->uses()) . ' / ' . esc_html((string) $max)
                    );
                } elseif ($license->uses() > 0) {
                    self::row(esc_html__('Activations recorded', $domain), esc_html((string) $license->uses()));
                }
            }

            if (!$module->config()->get('hide_owner_email') && $license->email()) {
                self::row(esc_html__('Owner', $domain), esc_html($license->email()));
            }

            if (!$module->config()->get('hide_custom_fields')) {
                foreach ($license->meta() as $key => $value) {
                    self::row(esc_html($key), esc_html($value));
                }
            }
        }

        echo '</table>';

        $permalink = $module->base_config()->get('permalink');

        if ($bottom = $module->config()->callback('license_page_bottom')) {
            call_user_func($bottom, $module);
        } elseif (!$status->is_valid() && $permalink !== null) {
            // No Buy link at all without a permalink — product_id is not a
            // valid gumroad.com/l/... path, so there's nothing safe to link to.
            printf(
                '<hr /><a class="button button-primary" href="%s" target="_blank" rel="noopener noreferrer">%s</a>',
                esc_url('https://gumroad.com/l/' . rawurlencode($permalink)),
                esc_html(sprintf(
                    /* translators: %s: plugin or theme name. */
                    __('Buy %s', $domain),
                    (string) $module->module_data('Name')
                ))
            );
        }

        echo '</div>';
    }

    private static function row(string $label_html, string $value_html): void
    {
        echo '<tr><th style="text-align:left;width:160px">' . $label_html . '</th><td>' . $value_html . '</td></tr>';
    }
}
