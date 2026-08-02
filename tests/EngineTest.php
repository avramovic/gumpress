<?php

declare(strict_types=1);

namespace GumPress\V2\Tests;

use GumPress\V2\Config;
use GumPress\V2\Engine;
use GumPress\V2\Notices;
use PHPUnit\Framework\TestCase;

/**
 * Engine::create() end-to-end, one layer below the direct
 * enforce_seal_policy() tests in EnvTest.php. This is what actually stands
 * between a pirate swapping a sealed "gp1" blob for a plain array and a
 * production site honouring whatever they typed.
 */
final class EngineTest extends TestCase
{
    private const PRODUCT = 'acme-plugin';

    private const FILE = '/tmp/fake-plugin/fake-plugin.php';

    protected function setUp(): void
    {
        gumpress_test_reset_store();
        $GLOBALS['__gumpress_test_home_url'] = 'https://example.com';
        $GLOBALS['__gumpress_test_env_type'] = 'production';
        unset($_SERVER['SERVER_ADDR']);
        Notices::reset_for_tests();
    }

    public function test_plain_array_config_is_discarded_in_production(): void
    {
        $module = Engine::create(self::FILE, self::PRODUCT, [
            'license_check_url' => 'https://evil.example/verify',
        ]);

        $this->assertSame(Config::DEFAULT_LICENSE_URL, $module->base_config()->get('license_check_url'));
        $this->assertCount(0, Notices::queued_for_tests());
    }

    /**
     * The regression test that matters most: a caller-supplied '_encrypted'
     * must not be able to impersonate a real seal and dodge the production
     * fallback. Engine::create() must set '_encrypted' from its own local,
     * never from whatever the plain array happened to contain.
     */
    public function test_injected_encrypted_flag_does_not_bypass_the_production_fallback(): void
    {
        $module = Engine::create(self::FILE, self::PRODUCT, [
            '_encrypted' => true,
            'license_check_url' => 'https://evil.example/verify',
        ]);

        $this->assertSame(Config::DEFAULT_LICENSE_URL, $module->base_config()->get('license_check_url'));
        $this->assertCount(0, Notices::queued_for_tests());
    }

    /**
     * The identity carve-out: 'type' survives the production discard (a
     * pirate gains nothing from it, but dropping it can fail a legitimate
     * theme closed — see Engine::enforce_seal_policy()'s docblock), while a
     * policy-relevant key alongside it does not.
     */
    public function test_identity_keys_survive_the_production_fallback(): void
    {
        $module = Engine::create(self::FILE, self::PRODUCT, [
            'type' => 'theme',
            'permalink' => 'acme-pro',
            'license_check_url' => 'https://evil.example/verify',
        ]);

        $config = $module->base_config();

        $this->assertSame('theme', $config->get('type'));
        $this->assertSame('acme-pro', $config->get('permalink'));
        $this->assertSame(Config::DEFAULT_LICENSE_URL, $config->get('license_check_url'));
    }

    public function test_sealed_config_applies_unchanged_in_production(): void
    {
        $blob = \gumpress_seal(['license_check_url' => 'https://proxy.example/verify'], self::PRODUCT);

        $module = Engine::create(self::FILE, self::PRODUCT, ['__gumpress_encrypted' => $blob]);

        $this->assertSame('https://proxy.example/verify', $module->base_config()->get('license_check_url'));
        $this->assertCount(0, Notices::queued_for_tests());
    }

    public function test_tampered_blob_still_warns_in_production(): void
    {
        $module = Engine::create(self::FILE, self::PRODUCT, ['__gumpress_encrypted' => 'gp1not-a-real-sealed-blob']);

        $this->assertSame(Config::DEFAULT_LICENSE_URL, $module->base_config()->get('license_check_url'));

        $queued = Notices::queued_for_tests();
        $this->assertCount(1, $queued);
        $this->assertStringContainsString('tamper check', $queued[0]['content']);
    }

    public function test_plain_array_config_applies_outside_production_with_a_notice(): void
    {
        $GLOBALS['__gumpress_test_env_type'] = 'staging';

        $module = Engine::create(self::FILE, self::PRODUCT, [
            'license_check_url' => 'https://evil.example/verify',
        ]);

        $this->assertSame('https://evil.example/verify', $module->base_config()->get('license_check_url'));

        $queued = Notices::queued_for_tests();
        $this->assertCount(1, $queued);
        $this->assertSame('warning', $queued[0]['level']);
    }

    public function test_all_default_plain_array_is_unaffected_in_production(): void
    {
        $module = Engine::create(self::FILE, self::PRODUCT, []);

        $this->assertSame(Config::DEFAULT_LICENSE_URL, $module->base_config()->get('license_check_url'));
        $this->assertCount(0, Notices::queued_for_tests());
    }
}
