<?php

declare(strict_types=1);

namespace GumPress\V2\Tests;

use GumPress\V2\Config;
use GumPress\V2\Engine;
use GumPress\V2\Env;
use GumPress\V2\Notices;
use PHPUnit\Framework\TestCase;

final class EnvTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['__gumpress_test_home_url'] = 'https://example.com';
        $GLOBALS['__gumpress_test_env_type'] = 'production';
        unset($_SERVER['SERVER_ADDR']);
        Notices::reset_for_tests();
    }

    public function test_production_public_host_is_not_non_production(): void
    {
        $this->assertFalse(Env::is_non_production());
    }

    public function test_wp_environment_type_staging_is_non_production(): void
    {
        $GLOBALS['__gumpress_test_env_type'] = 'staging';

        $this->assertTrue(Env::is_non_production());
    }

    /** @dataProvider local_hostnames */
    public function test_local_hostnames_are_non_production(string $host): void
    {
        $GLOBALS['__gumpress_test_home_url'] = 'https://' . $host;

        $this->assertTrue(Env::is_non_production());
    }

    public static function local_hostnames(): array
    {
        return [
            ['localhost'],
            ['mysite.local'],
            ['mysite.test'],
            ['mysite.localhost'],
            ['staging.example.com'],
            ['dev.example.com'],
            ['stage.example.com'],
        ];
    }

    public function test_rfc1918_server_addr_is_non_production(): void
    {
        $_SERVER['SERVER_ADDR'] = '192.168.1.50';

        $this->assertTrue(Env::is_non_production());
    }

    public function test_public_server_addr_is_production(): void
    {
        $_SERVER['SERVER_ADDR'] = '203.0.113.10';

        $this->assertFalse(Env::is_non_production());
    }

    public function test_cidr_match_boundaries(): void
    {
        $this->assertTrue(Env::cidr_match('10.0.0.1', '10.0.0.0/8'));
        $this->assertTrue(Env::cidr_match('10.255.255.255', '10.0.0.0/8'));
        $this->assertFalse(Env::cidr_match('11.0.0.1', '10.0.0.0/8'));
        $this->assertTrue(Env::cidr_match('172.16.0.1', '172.16.0.0/12'));
        $this->assertFalse(Env::cidr_match('172.32.0.1', '172.16.0.0/12'));
        $this->assertTrue(Env::cidr_match('192.168.0.1', '192.168.0.0/16'));
        $this->assertFalse(Env::cidr_match('193.168.0.1', '192.168.0.0/16'));
    }

    public function test_cidr_match_rejects_non_ipv4(): void
    {
        $this->assertFalse(Env::cidr_match('::1', '10.0.0.0/8'));
        $this->assertFalse(Env::cidr_match('not-an-ip', '10.0.0.0/8'));
    }

    public function test_unsealed_config_warns_outside_production(): void
    {
        $GLOBALS['__gumpress_test_env_type'] = 'staging';

        Engine::enforce_seal_policy(new Config(['max_uses' => 5]), 'acme-plugin');

        $queued = Notices::queued_for_tests();
        $this->assertCount(1, $queued);
        $this->assertSame('warning', $queued[0]['level']);
        $this->assertStringContainsString('acme-plugin', $queued[0]['content']);
        $this->assertStringContainsString(Config::DEFAULT_CONFIGURATOR_URL, $queued[0]['content']);
    }

    public function test_unsealed_config_notice_identifies_the_module_by_name_when_a_file_is_given(): void
    {
        $GLOBALS['__gumpress_test_env_type'] = 'staging';

        Engine::enforce_seal_policy(
            new Config(['max_uses' => 5]),
            'acme-plugin',
            __DIR__ . '/fixtures/acme-plugin/acme-plugin.php'
        );

        $content = Notices::queued_for_tests()[0]['content'];

        $this->assertStringContainsString('Acme Pro', $content);
        $this->assertStringContainsString('acme-plugin', $content);
        // The configurator link must still carry the raw product_id, not the name.
        $this->assertStringContainsString('product_id=acme-plugin', $content);
    }

    public function test_unsealed_config_notice_falls_back_to_the_product_id_when_the_name_cant_be_read(): void
    {
        $GLOBALS['__gumpress_test_env_type'] = 'staging';

        Engine::enforce_seal_policy(
            new Config(['max_uses' => 5]),
            'acme-plugin',
            __DIR__ . '/fixtures/no-name-plugin/no-name-plugin.php'
        );

        $content = Notices::queued_for_tests()[0]['content'];

        $this->assertStringContainsString('acme-plugin', $content);
        $this->assertStringNotContainsString('Acme Pro', $content);
    }

    public function test_unsealed_config_stays_silent_in_production(): void
    {
        Engine::enforce_seal_policy(new Config([]), 'acme-plugin');

        $this->assertCount(0, Notices::queued_for_tests());
    }

    public function test_sealed_config_stays_silent_even_outside_production(): void
    {
        $GLOBALS['__gumpress_test_env_type'] = 'staging';

        Engine::enforce_seal_policy(new Config(['_encrypted' => true]), 'acme-plugin');

        $this->assertCount(0, Notices::queued_for_tests());
    }

    public function test_unsealed_config_notice_honours_a_custom_configurator_url(): void
    {
        $GLOBALS['__gumpress_test_env_type'] = 'staging';

        Engine::enforce_seal_policy(
            new Config(['configurator_url' => 'https://example.com/my-configurator']),
            'acme-plugin'
        );

        $this->assertStringContainsString(
            'https://example.com/my-configurator',
            Notices::queued_for_tests()[0]['content']
        );
    }

    public function test_default_config_stays_silent_outside_production(): void
    {
        $GLOBALS['__gumpress_test_env_type'] = 'staging';

        Engine::enforce_seal_policy(new Config([]), 'acme-plugin');

        $this->assertCount(0, Notices::queued_for_tests());
    }

    public function test_config_matching_defaults_stays_silent(): void
    {
        $GLOBALS['__gumpress_test_env_type'] = 'staging';

        Engine::enforce_seal_policy(new Config(['payment_grace' => 7]), 'acme-plugin');

        $this->assertCount(0, Notices::queued_for_tests());
    }

    /**
     * The notice's link carries the product id and every non-default option
     * over as a query parameter, so following it from the WP admin lands on
     * the configurator pre-filled instead of blank — see
     * Config::non_defaults().
     */
    public function test_unsealed_config_notice_link_carries_the_product_id_and_non_defaults(): void
    {
        $GLOBALS['__gumpress_test_env_type'] = 'staging';

        Engine::enforce_seal_policy(
            new Config(['max_uses' => 5, 'disallow_test_keys' => true, 'payment_grace' => 7]),
            'acme-plugin'
        );

        $content = Notices::queued_for_tests()[0]['content'];

        $this->assertStringContainsString('<a href="', $content);
        $this->assertStringContainsString('product_id=acme-plugin', $content);
        $this->assertStringContainsString('max_uses=5', $content);
        $this->assertStringContainsString('disallow_test_keys=1', $content);
        // payment_grace restates its own default (7) — must NOT appear.
        $this->assertStringNotContainsString('payment_grace=', $content);
    }

    public function test_unsealed_config_notice_link_omits_array_valued_options(): void
    {
        $GLOBALS['__gumpress_test_env_type'] = 'staging';

        Engine::enforce_seal_policy(
            new Config(['max_uses' => 5, 'lock_config' => ['max_uses']]),
            'acme-plugin'
        );

        $content = Notices::queued_for_tests()[0]['content'];

        $this->assertStringContainsString('max_uses=5', $content);
        $this->assertStringNotContainsString('lock_config', $content);
    }
}
