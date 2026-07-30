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

        $this->assertSame(0, $config->get('max_uses'));
        $this->assertSame('block', $config->get('max_uses_policy'));
        $this->assertSame(7, $config->get('payment_grace'));
        $this->assertSame(14, $config->get('offline_grace'));
        $this->assertSame(Config::DEFAULT_LICENSE_URL, $config->get('license_check_url'));
        $this->assertFalse($config->get('disallow_test_keys'));
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

    public function test_is_white_label_false_by_default(): void
    {
        $this->assertFalse((new Config())->is_white_label());
    }

    public function test_is_white_label_false_when_explicitly_set_to_the_default_url(): void
    {
        $config = new Config(['license_check_url' => Config::DEFAULT_LICENSE_URL]);

        $this->assertFalse($config->is_white_label());
    }

    /** @dataProvider white_label_url_provider */
    public function test_is_white_label_derives_from_the_license_check_url(string $url, bool $expected): void
    {
        $config = new Config(['license_check_url' => $url]);

        $this->assertSame($expected, $config->is_white_label());
    }

    public static function white_label_url_provider(): array
    {
        return [
            'gumpress subdomain' => ['https://api.gumpress.eu/verify', true],
            'gumpress subdomain, mixed case' => ['https://API.GumPress.EU/verify', true],
            'gumpress label under a bigger host' => ['https://gumpress.example.com/verify', true],
            'hyphenated lookalike' => ['https://gumpress-mirror.net/verify', false],
            'prefix lookalike' => ['https://notgumpress.com/verify', false],
            'unrelated custom host' => ['https://ls.example.com/verify', false],
            'not a url' => ['not a url', false],
            'empty string' => ['', false],
        ];
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
            'callbacks' => ['license_page_top' => static fn () => 'x'],
            'lock_config' => ['max_uses'],
            'configurator_url' => 'https://example.test/configurator',
            '_encrypted' => true,
            'type' => 'plugin',
            'text_domain' => 'acme',
            'max_uses' => 5,
        ]);

        $this->assertSame(['max_uses' => 5], $config->non_defaults());
    }

    public function test_callback_returns_default_when_not_callable(): void
    {
        $config = new Config(['callbacks' => ['license_page_top' => 'not callable and not a function']]);

        $this->assertSame('fallback', $config->callback('license_page_top', 'fallback'));
    }

    public function test_callback_returns_registered_callable(): void
    {
        $fn = static fn () => 'called';
        $config = new Config(['callbacks' => ['license_page_top' => $fn]]);

        $this->assertSame($fn, $config->callback('license_page_top'));
    }

    public function test_decode_encrypted_round_trip(): void
    {
        $product = 'acme-plugin';
        $original = ['max_uses' => 2, 'grace_period' => 5];

        $blob = self::seal($original, $product);

        $decoded = Config::decode_encrypted($blob, $product);

        $this->assertSame($original, $decoded);
    }

    public function test_decode_encrypted_rejects_tampered_payload(): void
    {
        $product = 'acme-plugin';
        $blob = self::seal(['max_uses' => 1], $product);

        // Flip the MAC so it no longer matches.
        $tampered = substr($blob, 0, -16) . str_repeat('0', 16);

        $this->assertNull(Config::decode_encrypted($tampered, $product));
    }

    public function test_decode_encrypted_rejects_wrong_product(): void
    {
        $blob = self::seal(['max_uses' => 1], 'acme-plugin');

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
     */
    public function test_seal_round_trips_across_many_random_configs(): void
    {
        for ($i = 0; $i < 10000; $i++) {
            $product = 'product-' . $i;
            $config = ['n' => $i, 'flag' => ($i % 2 === 0), 'label' => 'label-' . $i];

            $blob = self::seal($config, $product);

            $this->assertSame(16, strlen(substr($blob, -16)), "MAC was not 16 chars at iteration {$i}");
            $this->assertSame($config, Config::decode_encrypted($blob, $product), "round-trip failed at iteration {$i}");
        }
    }

    /** Mirrors encrypt.php's own "gp1" encoding, used here purely to build test fixtures. */
    private static function seal(array $config, string $product): string
    {
        $json = json_encode($config);
        $deflated = gzdeflate($json, 9);

        $key = hash('sha256', 'gumpress|' . $product, true);
        $iv = substr(hash('sha256', 'iv|' . $product, true), 0, 16);

        $ciphertext = openssl_encrypt($deflated, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv);
        $payload = rtrim(strtr(base64_encode($ciphertext), '+/', '-_'), '=');
        $mac = substr(hash_hmac('sha256', $payload, $key), 0, 16);

        return 'gp1' . $payload . $mac;
    }
}
