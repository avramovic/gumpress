<?php

declare(strict_types=1);

namespace GumPress\V2\Tests;

use GumPress\V2\Api;
use GumPress\V2\Config;
use GumPress\V2\Module;
use GumPress\V2\Vault;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Covers what Api persists, which had no test coverage at all before the
 * at-rest encryption work — the bootstrap didn't even load this class.
 *
 * The load-bearing test here is
 * test_a_failed_state_decrypt_does_not_report_a_new_activation(): the reason
 * the seat option is deliberately left unencrypted.
 */
final class ApiTest extends TestCase
{
    private const PRODUCT = 'test-product-id';

    private const KEY = 'AAAA-BBBB-CCCC-DDDD';

    protected function setUp(): void
    {
        gumpress_test_reset_store();
    }

    private function module(): Module
    {
        return Module::create('/tmp/fake-plugin/fake-plugin.php', self::PRODUCT, new Config([
            'type' => 'plugin',
        ]));
    }

    private function optionName(string $suffix): string
    {
        return 'gumpress_' . self::PRODUCT . '_' . $suffix;
    }

    /** should_increment() is private; the flag it returns is the whole point. */
    private function shouldIncrement(Api $api, string $key = self::KEY): bool
    {
        $method = new ReflectionMethod(Api::class, 'should_increment');
        $method->setAccessible(true);

        return $method->invoke($api, $key);
    }

    private function hashKey(Api $api, string $key = self::KEY): string
    {
        $method = new ReflectionMethod(Api::class, 'hash_key');
        $method->setAccessible(true);

        return $method->invoke($api, $key);
    }

    public function test_state_is_written_encrypted_and_reads_back(): void
    {
        $module = $this->module();
        $api = $module->api();

        $module->set_license_key(self::KEY);
        $GLOBALS['__gumpress_test_options'][$this->optionName('state')] = Vault::seal([
            'status' => 'ok',
            'payload' => ['success' => true, 'purchase' => ['email' => 'buyer@example.com']],
        ]);

        $stored = $GLOBALS['__gumpress_test_options'][$this->optionName('state')];
        $this->assertIsString($stored, 'state should be sealed, not a plain array');
        $this->assertStringNotContainsString('buyer@example.com', $stored);

        $this->assertSame('ok', $api->state()['status']);
        $this->assertSame('buyer@example.com', $api->license()?->email());
    }

    public function test_an_undecryptable_state_reads_as_an_empty_cache(): void
    {
        $api = $this->module()->api();

        $GLOBALS['__gumpress_test_options'][$this->optionName('state')] = Vault::seal(['status' => 'ok']);
        $GLOBALS['__gumpress_test_salt'] = 'rotated-salt';

        $state = $api->state();

        $this->assertNull($state['status']);
        $this->assertNull($state['payload']);
        $this->assertNull($api->license());
    }

    /**
     * A wp-config salt rotation makes the state blob unreadable. If the seat
     * option went with it, every install would report a fresh activation —
     * against Gumroad direct that bumps a counter that never decrements, and
     * a customer sitting on their max_uses cap gets hard-blocked. The seat
     * option is therefore stored in the clear on purpose.
     */
    public function test_a_failed_state_decrypt_does_not_report_a_new_activation(): void
    {
        $module = $this->module();
        $api = $module->api();
        $module->set_license_key(self::KEY);

        // A site that already activated: seat recorded for this key + host.
        $GLOBALS['__gumpress_test_options'][$this->optionName('seat')] = [
            'key_hash' => $this->hashKey($api),
            'host' => \GumPress\V2\Env::site_identity(),
            'activated_at' => time(),
        ];
        $GLOBALS['__gumpress_test_options'][$this->optionName('state')] = Vault::seal(['status' => 'ok']);

        $this->assertFalse($this->shouldIncrement($api), 'baseline: already activated');

        $GLOBALS['__gumpress_test_salt'] = 'rotated-salt';

        $this->assertNull($api->state()['status'], 'state should now be unreadable');
        $this->assertFalse(
            $this->shouldIncrement($api),
            'a salt rotation must not make an already-activated site report a new activation'
        );
    }

    public function test_migrate_rewrites_a_legacy_md5_fingerprint(): void
    {
        $module = $this->module();
        $api = $module->api();
        $module->set_license_key(self::KEY);

        $legacy = md5(self::KEY);
        $GLOBALS['__gumpress_test_options'][$this->optionName('state')] = ['key_hash' => $legacy, 'status' => 'ok'];
        $GLOBALS['__gumpress_test_options'][$this->optionName('seat')] = [
            'key_hash' => $legacy,
            'host' => \GumPress\V2\Env::site_identity(),
            'activated_at' => time(),
        ];

        $api->migrate();

        $expected = $this->hashKey($api);
        $this->assertSame($expected, $api->state()['key_hash']);
        $this->assertSame($expected, $GLOBALS['__gumpress_test_options'][$this->optionName('seat')]['key_hash']);
    }

    /** The migration is what keeps the algorithm change from costing a seat. */
    public function test_migrate_keeps_an_upgraded_site_from_reporting_a_new_activation(): void
    {
        $module = $this->module();
        $api = $module->api();
        $module->set_license_key(self::KEY);

        $GLOBALS['__gumpress_test_options'][$this->optionName('seat')] = [
            'key_hash' => md5(self::KEY), // as written by the previous release
            'host' => \GumPress\V2\Env::site_identity(),
            'activated_at' => time(),
        ];

        $this->assertTrue($this->shouldIncrement($api), 'pre-migration the legacy hash cannot match');

        $api->migrate();

        $this->assertFalse($this->shouldIncrement($api));
    }

    public function test_migrate_leaves_a_fingerprint_from_a_different_key_alone(): void
    {
        $module = $this->module();
        $api = $module->api();
        $module->set_license_key(self::KEY);

        $GLOBALS['__gumpress_test_options'][$this->optionName('seat')] = [
            'key_hash' => md5('SOME-OTHER-KEY'),
            'host' => \GumPress\V2\Env::site_identity(),
            'activated_at' => time(),
        ];

        $api->migrate();

        // Untouched, so the genuine "the key changed" signal still fires.
        $this->assertSame(
            md5('SOME-OTHER-KEY'),
            $GLOBALS['__gumpress_test_options'][$this->optionName('seat')]['key_hash']
        );
        $this->assertTrue($this->shouldIncrement($api));
    }

    public function test_migrate_runs_once(): void
    {
        $module = $this->module();
        $api = $module->api();
        $module->set_license_key(self::KEY);

        $api->migrate();
        $this->assertSame(2, $GLOBALS['__gumpress_test_options'][$this->optionName('schema')]);

        // A legacy value appearing after the marker is set is not re-migrated.
        $GLOBALS['__gumpress_test_options'][$this->optionName('seat')] = ['key_hash' => md5(self::KEY)];
        $api->migrate();

        $this->assertSame(
            md5(self::KEY),
            $GLOBALS['__gumpress_test_options'][$this->optionName('seat')]['key_hash']
        );
    }

    public function test_purge_removes_state_and_seat(): void
    {
        $module = $this->module();
        $api = $module->api();

        $GLOBALS['__gumpress_test_options'][$this->optionName('state')] = Vault::seal(['status' => 'ok']);
        $GLOBALS['__gumpress_test_options'][$this->optionName('seat')] = ['key_hash' => 'x'];

        $api->purge();

        $this->assertArrayNotHasKey($this->optionName('state'), $GLOBALS['__gumpress_test_options']);
        $this->assertArrayNotHasKey($this->optionName('seat'), $GLOBALS['__gumpress_test_options']);
    }

    public function test_uninstall_removes_every_option_it_owns(): void
    {
        $module = $this->module();
        $module->set_license_key(self::KEY);
        $module->api()->migrate();
        $GLOBALS['__gumpress_test_options'][$this->optionName('state')] = Vault::seal(['status' => 'ok']);
        $GLOBALS['__gumpress_test_transients'][$this->optionName('update_cache')] = Vault::seal(['version' => '1.0']);

        $module->uninstall();

        foreach (['state', 'seat', 'license_key', 'schema'] as $suffix) {
            $this->assertArrayNotHasKey($this->optionName($suffix), $GLOBALS['__gumpress_test_options'], $suffix);
        }
        $this->assertSame([], $GLOBALS['__gumpress_test_transients']);
    }
}
