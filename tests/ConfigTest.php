<?php

declare(strict_types=1);

namespace GumPress\V2\Tests;

use GumPress\V2\Config;
use PHPUnit\Framework\TestCase;

final class ConfigTest extends TestCase
{
    public function test_defaults(): void
    {
        $config = new Config();

        $this->assertSame(1, $config->get('max_uses'));
        $this->assertSame('block', $config->get('max_uses_policy'));
        $this->assertSame(7, $config->get('payment_grace'));
        $this->assertSame(14, $config->get('offline_grace'));
        $this->assertSame(Config::DEFAULT_LICENSE_URL, $config->get('license_check_url'));
        $this->assertFalse($config->get('disallow_test_keys'));
        $this->assertFalse($config->get('white_label'));
    }

    public function test_options_override_defaults(): void
    {
        $config = new Config(['max_uses' => 5, 'payment_grace' => 3]);

        $this->assertSame(5, $config->get('max_uses'));
        $this->assertSame(3, $config->get('payment_grace'));
        // untouched defaults survive the merge.
        $this->assertSame('block', $config->get('max_uses_policy'));
    }

    public function test_get_returns_default_for_unknown_key(): void
    {
        $config = new Config();

        $this->assertSame('fallback', $config->get('does_not_exist', 'fallback'));
    }

    public function test_non_defaults_is_empty_for_an_all_default_config(): void
    {
        $config = new Config();

        $this->assertSame([], $config->non_defaults());
    }

    public function test_non_defaults_only_includes_changed_options(): void
    {
        $config = new Config(['max_uses' => 5, 'payment_grace' => 3, 'offline_grace' => 14]);

        $this->assertSame(['payment_grace' => 3, 'max_uses' => 5], $config->non_defaults());
    }

    public function test_non_defaults_excludes_non_reproducible_keys(): void
    {
        $config = new Config([
            'lock_config' => ['max_uses'],
            'configurator_url' => 'https://example.test/configurator',
            '_encrypted' => true,
            'type' => 'plugin',
            'text_domain' => 'acme',
            'max_uses' => 5,
        ]);

        $this->assertSame(['max_uses' => 5], $config->non_defaults());
    }

    public function test_decode_encrypted_round_trip(): void
    {
        $product = 'acme-plugin';
        $original = ['max_uses' => 2, 'grace_period' => 5];

        $blob = \gumpress_seal($original, $product);

        $decoded = Config::decode_encrypted($blob, $product);

        $this->assertSame($original, $decoded);
    }

    public function test_decode_encrypted_rejects_tampered_payload(): void
    {
        $product = 'acme-plugin';
        $blob = \gumpress_seal(['max_uses' => 1], $product);

        // Flip the MAC so it no longer matches.
        $tampered = substr($blob, 0, -16) . str_repeat('0', 16);

        $this->assertNull(Config::decode_encrypted($tampered, $product));
    }

    public function test_decode_encrypted_rejects_wrong_product(): void
    {
        $blob = \gumpress_seal(['max_uses' => 1], 'acme-plugin');

        $this->assertNull(Config::decode_encrypted($blob, 'a-different-product'));
    }

    public function test_decode_encrypted_rejects_too_short_blob(): void
    {
        $this->assertNull(Config::decode_encrypted('short', 'acme-plugin'));
    }

    public function test_decode_encrypted_rejects_a_blob_without_the_gp1_prefix(): void
    {
        $this->assertNull(Config::decode_encrypted('xx1notasealedblobatall', 'acme-plugin'));
    }

    /**
     * The regression this format exists to fix: CRC32 rendered as
     * dechex(crc32(...)) is 1-8 hex characters depending on the value, while
     * the old decoder always read exactly the last 8 — silently corrupting
     * ~6.25% of generated configs. HMAC-SHA256 truncated to a fixed 16 hex
     * characters can't recur that bug by construction; assert it across a
     * wide spread of configs and product ids, not just one lucky case.
     *
     * Runs against bin/encrypt.php's actual gumpress_seal(), so this also
     * pins the CLI's output format, not a hand-maintained lookalike of it.
     */
    public function test_seal_round_trips_across_many_random_configs(): void
    {
        for ($i = 0; $i < 10000; $i++) {
            $product = 'product-' . $i;
            $config = ['n' => $i, 'flag' => ($i % 2 === 0), 'label' => 'label-' . $i];

            $blob = \gumpress_seal($config, $product);

            $this->assertSame(16, strlen(substr($blob, -16)), "MAC was not 16 chars at iteration {$i}");
            $this->assertSame($config, Config::decode_encrypted($blob, $product), "round-trip failed at iteration {$i}");
        }
    }
}
