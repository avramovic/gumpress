<?php

declare(strict_types=1);

namespace GumPress\V2\Tests;

use GumPress\V2\Config;
use GumPress\V2\Module;
use PHPUnit\Framework\TestCase;

/**
 * Module::page_slug() used to track the optional `permalink` config option
 * (falling back to a hash of the product_id when unset), which produced an
 * ugly URL by default and one that *moved* the moment `permalink` was later
 * added or edited — a value that has nothing to do with WordPress. It now
 * always derives from the plugin/theme's own folder, regardless of
 * `permalink`, so the license page URL is human-readable and stable from
 * first registration.
 */
final class PageSlugTest extends TestCase
{
    private const PRODUCT = 'test-product-id';

    protected function setUp(): void
    {
        gumpress_test_reset_store();
    }

    private function module(string $file, array $options = []): Module
    {
        return Module::create($file, self::PRODUCT, new Config($options));
    }

    public function test_plugin_slug_derives_from_its_folder(): void
    {
        $module = $this->module('/tmp/fake-plugin/fake-plugin.php', ['type' => 'plugin']);

        $this->assertSame('fake-plugin-license', $module->page_slug());
    }

    public function test_permalink_no_longer_affects_the_page_slug(): void
    {
        $module = $this->module('/tmp/fake-plugin/fake-plugin.php', [
            'type' => 'plugin',
            'permalink' => 'acme-pro',
        ]);

        $this->assertSame('fake-plugin-license', $module->page_slug());
    }

    public function test_theme_slug_derives_from_its_folder(): void
    {
        $module = $this->module('/tmp/themes/acme-theme/functions.php', ['type' => 'theme']);

        $this->assertSame('acme-theme-license', $module->page_slug());
    }

    public function test_folder_with_unsafe_characters_is_sanitized(): void
    {
        $module = $this->module('/tmp/plugins/Acme Pro!/acme-pro.php', ['type' => 'plugin']);

        $this->assertSame('acme-pro-license', $module->page_slug());
    }

    public function test_license_page_link_uses_the_page_slug(): void
    {
        $plugin = $this->module('/tmp/fake-plugin/fake-plugin.php', ['type' => 'plugin']);
        $theme = $this->module('/tmp/themes/acme-theme/functions.php', ['type' => 'theme']);

        $this->assertSame('options-general.php?page=fake-plugin-license', $plugin->license_page_link());
        $this->assertSame('themes.php?page=acme-theme-license', $theme->license_page_link());
    }
}
