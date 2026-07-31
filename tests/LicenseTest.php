<?php

declare(strict_types=1);

namespace GumPress\V2\Tests;

use GumPress\V2\License;
use PHPUnit\Framework\TestCase;

final class LicenseTest extends TestCase
{
    private static function load(string $fixture): License
    {
        $path = __DIR__ . '/fixtures/' . $fixture . '.json';

        return License::from_json(file_get_contents($path));
    }

    public function test_basic_fields(): void
    {
        $license = self::load('valid-onetime');

        $this->assertTrue($license->success());
        $this->assertSame(1, $license->uses());
        $this->assertSame('buyer@example.com', $license->email());
        $this->assertFalse($license->is_subscription());
    }

    public function test_custom_fields_string_form(): void
    {
        $license = self::load('custom-fields-string-form');

        $meta = $license->meta();
        $this->assertSame('Acme Inc', $meta['Company']);
        $this->assertSame('5', $meta['Seats']);
        $this->assertArrayNotHasKey('malformed field with no colon', $meta);
    }

    public function test_custom_fields_object_form(): void
    {
        $license = self::load('custom-fields-object-form');

        $meta = $license->meta();
        $this->assertSame('Acme Inc', $meta['Company']);
        $this->assertSame('5', $meta['Seats']);
    }

    public function test_meta_field_helper(): void
    {
        $license = self::load('custom-fields-object-form');

        $this->assertSame('Acme Inc', $license->meta_field('Company'));
        $this->assertNull($license->meta_field('Missing'));
        $this->assertSame('fallback', $license->meta_field('Missing', 'fallback'));
    }

    public function test_tier_parsing(): void
    {
        $license = self::load('valid-subscription');

        $this->assertSame('Tier 1', $license->tier());
        $this->assertTrue($license->has_tier('tier 1')); // case-insensitive
        $this->assertFalse($license->has_tier('Tier 2'));
    }

    public function test_no_tier_when_variants_empty(): void
    {
        $license = self::load('valid-onetime');

        $this->assertNull($license->tier());
        $this->assertFalse($license->has_tier('anything'));
    }

    public function test_is_subscription_detected_via_subscription_id_or_recurrence(): void
    {
        $this->assertTrue(self::load('valid-subscription')->is_subscription());
        $this->assertFalse(self::load('valid-onetime')->is_subscription());
    }

    public function test_subscription_timestamps_parsed_as_unix(): void
    {
        $license = self::load('payment-failed');

        $this->assertSame(strtotime('2024-01-01T00:00:00Z'), $license->subscription_failed_at());
    }

    public function test_malformed_timestamp_treated_as_absent(): void
    {
        $license = self::load('malformed-timestamp');

        $this->assertNull($license->subscription_failed_at());
    }

    public function test_extra_exposes_proxy_pass_through_fields(): void
    {
        $license = new License([
            'success' => true,
            'uses' => 1,
            'purchase' => ['email' => 'a@b.com'],
            'seats_max' => 5,
            'seats_used' => 2,
        ]);

        $this->assertSame(['seats_max' => 5, 'seats_used' => 2], $license->extra());
        $this->assertSame(5, $license->extra('seats_max'));
        $this->assertNull($license->extra('missing'));
    }

    public function test_from_json_returns_null_for_invalid_json(): void
    {
        $this->assertNull(License::from_json('not json'));
    }

    public function test_flags(): void
    {
        $this->assertTrue(self::load('refunded')->is_refunded());
        $this->assertTrue(self::load('chargebacked')->is_chargebacked());
        $this->assertTrue(self::load('disputed')->is_disputed());
        $this->assertFalse(self::load('disputed')->dispute_won());
        $this->assertTrue(self::load('dispute-won')->dispute_won());
        $this->assertTrue(self::load('test-key')->is_test());
    }

    private static function withGumpressBlock(?array $gumpress): License
    {
        $raw = ['success' => true, 'uses' => 1, 'purchase' => []];
        if ($gumpress !== null) {
            $raw['gumpress'] = $gumpress;
        }

        return new License($raw);
    }

    public function test_white_label_null_when_theres_no_gumpress_block_at_all(): void
    {
        $this->assertNull(self::withGumpressBlock(null)->white_label());
    }

    public function test_white_label_null_when_the_gumpress_block_omits_the_key(): void
    {
        $this->assertNull(self::withGumpressBlock(['seats' => ['used' => 1]])->white_label());
    }

    public function test_white_label_true(): void
    {
        $this->assertTrue(self::withGumpressBlock(['white_label' => true])->white_label());
    }

    public function test_white_label_false(): void
    {
        $this->assertFalse(self::withGumpressBlock(['white_label' => false])->white_label());
    }

    /**
     * The safe failure direction is "show the credit" — a malformed value
     * (e.g. a hand-rolled proxy sending the string "true") must read as
     * null (server never really addressed it), not as truthy.
     */
    public function test_white_label_null_for_a_non_bool_value(): void
    {
        $this->assertNull(self::withGumpressBlock(['white_label' => 'true'])->white_label());
        $this->assertNull(self::withGumpressBlock(['white_label' => 1])->white_label());
        $this->assertNull(self::withGumpressBlock(['white_label' => null])->white_label());
    }
}
