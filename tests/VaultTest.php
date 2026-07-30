<?php

declare(strict_types=1);

namespace GumPress\V2\Tests;

use GumPress\V2\Vault;
use PHPUnit\Framework\TestCase;

final class VaultTest extends TestCase
{
    protected function setUp(): void
    {
        gumpress_test_reset_store();
    }

    public function test_round_trips_an_array(): void
    {
        $value = ['status' => 'ok', 'payload' => ['purchase' => ['email' => 'buyer@example.com']]];

        $sealed = Vault::seal($value);

        $this->assertIsString($sealed);
        $this->assertStringStartsWith('gpo1', $sealed);
        $this->assertSame($value, Vault::open($sealed));
    }

    /** The entire point: the PII must not be readable in the stored row. */
    public function test_the_sealed_blob_does_not_leak_its_contents(): void
    {
        $sealed = Vault::seal(['payload' => ['purchase' => ['email' => 'buyer@example.com']]]);

        $this->assertStringNotContainsString('buyer@example.com', $sealed);
        $this->assertStringNotContainsString('purchase', $sealed);
    }

    public function test_a_tampered_blob_is_rejected(): void
    {
        $sealed = Vault::seal(['status' => 'ok']);

        // Flip a byte in the payload, leaving the prefix and MAC length intact.
        $tampered = substr($sealed, 0, 8) . ($sealed[8] === 'A' ? 'B' : 'A') . substr($sealed, 9);

        $this->assertNull(Vault::open($tampered));
    }

    public function test_a_truncated_blob_is_rejected(): void
    {
        $this->assertNull(Vault::open('gpo1'));
        $this->assertNull(Vault::open('gpo1short'));
    }

    public function test_a_blob_from_another_salt_is_rejected(): void
    {
        $sealed = Vault::seal(['status' => 'ok']);

        // Exactly what a wp-config salt rotation looks like from here.
        $GLOBALS['__gumpress_test_salt'] = 'a-completely-different-salt';

        $this->assertNull(Vault::open($sealed));
    }

    /** Anything not carrying our prefix is not ours to interpret. */
    public function test_unrecognised_values_are_rejected(): void
    {
        $this->assertNull(Vault::open('gp1somethingelse'));
        $this->assertNull(Vault::open(''));
        $this->assertNull(Vault::open(null));
        $this->assertNull(Vault::open(42));
    }

    /**
     * Options written before this class existed are plain arrays. They must
     * keep working untouched — the next write seals them, so the migration
     * is passive rather than a rewrite pass.
     */
    public function test_a_legacy_plaintext_array_passes_through(): void
    {
        $legacy = ['status' => 'ok', 'key_hash' => 'abc'];

        $this->assertSame($legacy, Vault::open($legacy));
    }

    /**
     * Without a salt there is nothing to key off, so seal() must hand back
     * the plaintext array rather than fail — a site missing ext-openssl or
     * running before WordPress defines its salts still has to work.
     */
    public function test_it_degrades_to_plaintext_when_unavailable(): void
    {
        $GLOBALS['__gumpress_test_salt'] = '';

        $this->assertFalse(Vault::available());

        $value = ['status' => 'ok'];
        $this->assertSame($value, Vault::seal($value));
        $this->assertSame($value, Vault::open($value));
    }

    /** Random IV per write: the same input twice must not produce the same row. */
    public function test_repeated_seals_of_the_same_value_differ(): void
    {
        $value = ['status' => 'ok'];

        $this->assertNotSame(Vault::seal($value), Vault::seal($value));
    }

    public function test_survives_a_fuzz_of_random_payloads(): void
    {
        for ($i = 0; $i < 200; $i++) {
            $value = [
                'status' => bin2hex(random_bytes(random_int(1, 12))),
                'uses' => random_int(0, 1000),
                'payload' => ['nested' => [bin2hex(random_bytes(8)), null, true]],
            ];

            $this->assertSame($value, Vault::open(Vault::seal($value)), "iteration {$i}");
        }
    }
}
