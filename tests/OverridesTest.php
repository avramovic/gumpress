<?php

declare(strict_types=1);

namespace GumPress\V2\Tests;

use GumPress\V2\Config;
use GumPress\V2\Overrides;
use PHPUnit\Framework\TestCase;

final class OverridesTest extends TestCase
{
    public function test_no_overrides_returns_the_same_config_instance(): void
    {
        $base = new Config(['max_uses' => 2]);

        $this->assertSame($base, Overrides::apply($base, []));
    }

    public function test_whitelisted_keys_are_applied(): void
    {
        $base = new Config(['max_uses' => 0]);

        $effective = Overrides::apply($base, ['max_uses' => 5, 'max_uses_policy' => 'block']);

        $this->assertSame(5, $effective->get('max_uses'));
        $this->assertSame('block', $effective->get('max_uses_policy'));
    }

    public function test_never_overridable_keys_are_ignored(): void
    {
        $base = new Config(['license_check_url' => 'https://mine.example/verify']);

        $effective = Overrides::apply($base, [
            'license_check_url' => 'https://evil.example/verify',
            'proxy_fallback' => true,
            'type' => 'theme',
            'text_domain' => 'hijacked',
            '_encrypted' => true,
        ]);

        $this->assertSame('https://mine.example/verify', $effective->get('license_check_url'));
        $this->assertFalse($effective->get('proxy_fallback'));
        $this->assertNull($effective->get('type'));
        $this->assertNull($effective->get('text_domain'));
        $this->assertFalse($effective->get('_encrypted'));
    }

    public function test_lock_config_opts_a_key_out_of_being_overridden(): void
    {
        $base = new Config(['max_uses' => 1, 'lock_config' => ['max_uses']]);

        $effective = Overrides::apply($base, ['max_uses' => 999]);

        $this->assertSame(1, $effective->get('max_uses'));
    }

    public function test_only_the_locked_key_is_affected(): void
    {
        $base = new Config(['max_uses' => 1, 'payment_grace' => 7, 'lock_config' => ['max_uses']]);

        $effective = Overrides::apply($base, ['max_uses' => 999, 'payment_grace' => 3]);

        $this->assertSame(1, $effective->get('max_uses'));
        $this->assertSame(3, $effective->get('payment_grace'));
    }

    public function test_invalid_enum_values_are_rejected_and_the_base_value_kept(): void
    {
        $base = new Config(['max_uses_policy' => 'warn', 'offline_policy' => 'grace']);

        $effective = Overrides::apply($base, [
            'max_uses_policy' => 'nonsense',
            'offline_policy' => 'also-nonsense',
            'disallow_test_keys' => 'not-a-bool',
        ]);

        $this->assertSame('warn', $effective->get('max_uses_policy'));
        $this->assertSame('grace', $effective->get('offline_policy'));
        $this->assertFalse($effective->get('disallow_test_keys'));
    }

    public function test_ints_are_clamped_to_a_sane_range(): void
    {
        $effective = Overrides::apply(new Config(), ['payment_grace' => -5, 'offline_grace' => 999999]);

        $this->assertSame(0, $effective->get('payment_grace'));
        $this->assertSame(3650, $effective->get('offline_grace'));
    }

    public function test_update_check_url_override_requires_a_matching_registrable_domain(): void
    {
        $base = new Config([
            'license_check_url' => 'https://ls.example.com/l/token/verify',
            'update_check_url' => 'https://ls.example.com/l/token/update',
        ]);

        $rejected = Overrides::apply($base, ['update_check_url' => 'https://evil.example.net/update']);
        $this->assertSame('https://ls.example.com/l/token/update', $rejected->get('update_check_url'));

        $accepted = Overrides::apply($base, ['update_check_url' => 'https://ls.example.com/l/other-token/update']);
        $this->assertSame('https://ls.example.com/l/other-token/update', $accepted->get('update_check_url'));
    }

    public function test_unrecognised_override_keys_are_ignored(): void
    {
        $base = new Config();

        $effective = Overrides::apply($base, ['not_a_real_option' => 'whatever']);

        $this->assertNull($effective->get('not_a_real_option'));
    }
}
