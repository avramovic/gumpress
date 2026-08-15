<?php

declare(strict_types=1);

namespace GumPress\V2\Tests;

use GumPress\V2\Api;
use GumPress\V2\Config;
use GumPress\V2\Env;
use GumPress\V2\Module;
use PHPUnit\Framework\TestCase;

/**
 * Covers Api::verify_now()'s network path end to end via the wp_remote_post
 * stub in stubs.php — the whole point of "probe-then-claim": a site that
 * gets rejected must never send an incrementing request, and a site that
 * legitimately claimed a seat must survive the counter growing later (a
 * rejected third site, a clone, a site still on an older GumPress that
 * claims without probing).
 */
final class VerifyFlowTest extends TestCase
{
    private const PRODUCT = 'test-product-id';

    private const KEY = 'AAAA-BBBB-CCCC-DDDD';

    protected function setUp(): void
    {
        gumpress_test_reset_store();
        $GLOBALS['__gumpress_test_env_type'] = 'production';
    }

    private function module(array $options = []): Module
    {
        $module = Module::create('/tmp/fake-plugin/fake-plugin.php', self::PRODUCT, new Config(array_merge([
            'type' => 'plugin',
        ], $options)));
        $module->set_license_key(self::KEY);

        return $module;
    }

    private function optionName(string $suffix): string
    {
        return 'gumpress_' . self::PRODUCT . '_' . $suffix;
    }

    private function queueOk(int $uses, array $purchase = []): void
    {
        $this->queue(200, json_encode([
            'success' => true,
            'uses' => $uses,
            'purchase' => array_merge([
                'email' => 'buyer@example.com',
                'test' => false,
                'refunded' => false,
                'disputed' => false,
                'chargebacked' => false,
                'recurrence' => null,
            ], $purchase),
            'license_key' => self::KEY,
        ]));
    }

    private function queue(int $code, string $body): void
    {
        $GLOBALS['__gumpress_test_http']['queue'][] = [
            'response' => ['code' => $code],
            'body' => $body,
        ];
    }

    /** @return array<int, array> */
    private function requests(): array
    {
        return $GLOBALS['__gumpress_test_http']['requests'];
    }

    private function incrementFlag(array $request): string
    {
        return $request['args']['body']['increment_uses_count'];
    }

    public function test_rejected_site_sends_only_the_non_incrementing_probe(): void
    {
        $module = $this->module(['max_uses' => 2, 'max_uses_policy' => 'block']);
        $this->queueOk(2); // already at the cap before this site's own claim.

        $module->api()->force_refresh();

        $requests = $this->requests();
        $this->assertCount(1, $requests, 'a rejected site must never send the incrementing call');
        $this->assertSame('false', $this->incrementFlag($requests[0]));
        $this->assertSame([], $GLOBALS['__gumpress_test_options'][$this->optionName('seat')] ?? [], 'no seat marker for a rejected site');
        $this->assertSame(\GumPress\V2\Status::SEAT_LIMIT, $module->status()->code());
    }

    public function test_accepted_site_probes_then_claims_and_records_its_ordinal(): void
    {
        $module = $this->module(['max_uses' => 2, 'max_uses_policy' => 'block']);
        $this->queueOk(1); // room for one more.
        $this->queueOk(2); // the claiming call lands as the 2nd activation.

        $module->api()->force_refresh();

        $requests = $this->requests();
        $this->assertCount(2, $requests);
        $this->assertSame('false', $this->incrementFlag($requests[0]));
        $this->assertSame('true', $this->incrementFlag($requests[1]));

        $seat = $GLOBALS['__gumpress_test_options'][$this->optionName('seat')];
        $this->assertSame(2, $seat['ordinal']);
        $this->assertSame(2, $module->api()->seat_ordinal());
        $this->assertTrue($module->valid());
    }

    public function test_seat_survives_the_counter_growing_past_max_afterwards(): void
    {
        $module = $this->module(['max_uses' => 2, 'max_uses_policy' => 'block']);
        $this->queueOk(1);
        $this->queueOk(2);
        $module->api()->force_refresh();
        $this->assertTrue($module->valid());

        // Simulate a rejected third site (or a clone, or a site on an older
        // GumPress) pushing the global counter past the cap.
        $this->queueOk(3);
        $module->api()->force_refresh();

        $this->assertTrue($module->valid(), 'a seat legitimately claimed within the cap must not be revoked later');
        $this->assertSame(2, $module->api()->seat_ordinal());
    }

    public function test_a_refunded_probe_response_does_not_claim_a_seat(): void
    {
        $module = $this->module(['max_uses' => 2, 'max_uses_policy' => 'block']);
        $this->queueOk(1, ['refunded' => true]);

        $module->api()->force_refresh();

        $requests = $this->requests();
        $this->assertCount(1, $requests, 'a refunded license is not entitled to a seat, so no claim call is made');
        $this->assertSame('false', $this->incrementFlag($requests[0]));
        $this->assertSame([], $GLOBALS['__gumpress_test_options'][$this->optionName('seat')] ?? []);
    }

    public function test_custom_license_check_url_skips_the_probe(): void
    {
        $module = $this->module([
            'max_uses' => 2,
            'max_uses_policy' => 'block',
            'license_check_url' => 'https://example.com/verify',
        ]);
        $this->queueOk(5); // would be over cap, but a custom server owns its own seat model.

        $module->api()->force_refresh();

        $requests = $this->requests();
        $this->assertCount(1, $requests, 'a custom license_check_url is never probed');
        $this->assertSame('true', $this->incrementFlag($requests[0]));
    }

    public function test_warn_policy_skips_the_probe(): void
    {
        $module = $this->module(['max_uses' => 1, 'max_uses_policy' => 'warn']);
        $this->queueOk(5);

        $module->api()->force_refresh();

        $requests = $this->requests();
        $this->assertCount(1, $requests, "'warn' never blocks, so the real seat is always claimed directly");
        $this->assertSame('true', $this->incrementFlag($requests[0]));
    }

    public function test_max_uses_zero_skips_the_probe(): void
    {
        $module = $this->module(['max_uses' => 0]);
        $this->queueOk(5);

        $module->api()->force_refresh();

        $requests = $this->requests();
        $this->assertCount(1, $requests);
        $this->assertSame('true', $this->incrementFlag($requests[0]));
    }

    public function test_non_production_site_never_probes_or_claims(): void
    {
        $GLOBALS['__gumpress_test_env_type'] = 'local';
        $module = $this->module(['max_uses' => 2, 'max_uses_policy' => 'block']);
        $this->queueOk(5);

        $module->api()->force_refresh();

        $requests = $this->requests();
        $this->assertCount(1, $requests);
        $this->assertSame('false', $this->incrementFlag($requests[0]));
        $this->assertSame([], $GLOBALS['__gumpress_test_options'][$this->optionName('seat')] ?? []);
        $this->assertSame(0, $module->api()->seat_ordinal(), 'non-production sentinel: holds a place, never blocks');
        $this->assertTrue($module->valid(), 'skip_local_seats must not itself make the site seat_limit-blocked');
    }

    public function test_probe_transport_failure_makes_no_second_request(): void
    {
        $module = $this->module(['max_uses' => 2, 'max_uses_policy' => 'block']);
        $GLOBALS['__gumpress_test_http']['queue'][] = new \WP_Error('http_request_failed', 'timeout');

        $module->api()->force_refresh();

        $this->assertCount(1, $this->requests());
        $this->assertSame(1, $module->api()->state()['attempts']);
    }

    public function test_ordinal_is_backfilled_for_a_pre_2_0_1_seat_marker_within_cap(): void
    {
        $module = $this->module(['max_uses' => 2, 'max_uses_policy' => 'block']);
        $api = $module->api();

        $ref = new \ReflectionMethod(Api::class, 'hash_key');
        $ref->setAccessible(true);

        // A seat marker as written by a pre-2.0.1 release: no ordinal.
        $GLOBALS['__gumpress_test_options'][$this->optionName('seat')] = [
            'key_hash' => $ref->invoke($api, self::KEY),
            'host' => Env::site_identity(),
            'activated_at' => time(),
        ];

        $this->queueOk(2); // this site's own already-claimed activation; still within cap.
        $module->api()->force_refresh();

        $this->assertCount(1, $this->requests(), 'already recorded for this key+host: no new claim, just a probe-shaped re-check');
        $this->assertSame('false', $this->incrementFlag($this->requests()[0]));
        $this->assertSame(2, $module->api()->seat_ordinal());
    }

    public function test_ordinal_is_not_backfilled_once_already_over_cap(): void
    {
        $module = $this->module(['max_uses' => 2, 'max_uses_policy' => 'block']);
        $api = $module->api();

        $ref = new \ReflectionMethod(Api::class, 'hash_key');
        $ref->setAccessible(true);

        $GLOBALS['__gumpress_test_options'][$this->optionName('seat')] = [
            'key_hash' => $ref->invoke($api, self::KEY),
            'host' => Env::site_identity(),
            'activated_at' => time(),
        ];

        $this->queueOk(3); // already past max_uses=2 by the time this test runs.
        $module->api()->force_refresh();

        $this->assertNull($module->api()->seat_ordinal(), 'no amnesty for a marker already over the cap');
    }
}
