<?php

declare(strict_types=1);

namespace GumPress\V2\Tests;

use GumPress\V2\Config;
use GumPress\V2\License;
use GumPress\V2\Overrides;
use GumPress\V2\Status;
use GumPress\V2\Validator;
use PHPUnit\Framework\TestCase;

final class ValidatorTest extends TestCase
{
    private static function load(string $fixture): License
    {
        return License::from_json(file_get_contents(__DIR__ . '/fixtures/' . $fixture . '.json'));
    }

    /**
     * Validator never sees "no key at all" — Module::status() short-circuits to
     * Status::NO_KEY before Validator is even called. So license=null here means
     * "a key is stored but has never been successfully verified", and a fresh
     * install whose very first check fails must not be treated as invalid.
     */
    public function test_unreachable_with_no_verification_history_is_unverified_not_bricked(): void
    {
        $status = Validator::evaluate(null, false, null, new Config());

        $this->assertSame(Status::UNVERIFIED, $status->code());
        $this->assertFalse($status->is_valid());
    }

    public function test_unreachable_within_offline_grace_stays_valid(): void
    {
        $now = strtotime('2024-06-01T00:00:00Z');
        $valid_at = $now - (5 * DAY_IN_SECONDS); // 5 days ago, default grace is 14

        $status = Validator::evaluate(self::load('valid-onetime'), false, $valid_at, new Config(), $now);

        $this->assertSame(Status::VALID_OFFLINE, $status->code());
        $this->assertTrue($status->is_valid());
    }

    public function test_unreachable_past_offline_grace_is_unverifiable(): void
    {
        $now = strtotime('2024-06-01T00:00:00Z');
        $valid_at = $now - (15 * DAY_IN_SECONDS); // past the default 14-day grace

        $status = Validator::evaluate(self::load('valid-onetime'), false, $valid_at, new Config(), $now);

        $this->assertSame(Status::UNVERIFIABLE, $status->code());
        $this->assertFalse($status->is_valid());
    }

    public function test_offline_policy_closed_never_grants_grace(): void
    {
        $now = strtotime('2024-06-01T00:00:00Z');
        $valid_at = $now - DAY_IN_SECONDS; // 1 day ago, would be within grace otherwise

        $status = Validator::evaluate(
            self::load('valid-onetime'),
            false,
            $valid_at,
            new Config(['offline_policy' => 'closed']),
            $now
        );

        $this->assertSame(Status::UNVERIFIABLE, $status->code());
    }

    public function test_offline_policy_open_always_valid_regardless_of_history(): void
    {
        $status = Validator::evaluate(null, false, null, new Config(['offline_policy' => 'open']));

        $this->assertSame(Status::VALID_OFFLINE, $status->code());
        $this->assertTrue($status->is_valid());
    }

    public function test_reachable_success_is_valid(): void
    {
        $status = Validator::evaluate(self::load('valid-onetime'), true, time(), new Config());

        $this->assertSame(Status::VALID, $status->code());
        $this->assertTrue($status->is_valid());
    }

    public function test_reachable_but_key_not_found_is_invalid(): void
    {
        $status = Validator::evaluate(self::load('invalid-key'), true, null, new Config());

        $this->assertSame(Status::INVALID_KEY, $status->code());
        $this->assertFalse($status->is_valid());
    }

    public function test_test_key_rejected_only_when_configured(): void
    {
        $license = self::load('test-key');

        $allowed = Validator::evaluate($license, true, null, new Config());
        $this->assertSame(Status::VALID, $allowed->code());

        $disallowed = Validator::evaluate($license, true, null, new Config(['disallow_test_keys' => true]));
        $this->assertSame(Status::TEST_KEY, $disallowed->code());
    }

    public function test_refunded_disputed_chargebacked_are_invalid(): void
    {
        $this->assertSame(Status::REFUNDED, Validator::evaluate(self::load('refunded'), true, null, new Config())->code());
        $this->assertSame(Status::CHARGEBACKED, Validator::evaluate(self::load('chargebacked'), true, null, new Config())->code());
        $this->assertSame(Status::DISPUTED, Validator::evaluate(self::load('disputed'), true, null, new Config())->code());
    }

    public function test_dispute_won_overrides_disputed_flag(): void
    {
        $status = Validator::evaluate(self::load('dispute-won'), true, null, new Config());

        $this->assertSame(Status::VALID, $status->code());
    }

    /**
     * The central regression test: v1's `elseif ($max = max_uses)` cascade —
     * checked first, with max_uses defaulting truthy — made refunds and
     * every subscription state unreachable dead code. Refund must win even
     * when max_uses is set (2.0 still defaults it non-zero, but only after
     * moving the check last).
     */
    public function test_refund_is_checked_even_when_max_uses_configured(): void
    {
        $status = Validator::evaluate(
            self::load('refunded'),
            true,
            null,
            new Config(['max_uses' => 1, 'max_uses_policy' => 'block'])
        );

        $this->assertSame(Status::REFUNDED, $status->code());
    }

    public function test_payment_failed_grace_boundaries(): void
    {
        $failed_at = strtotime('2024-01-01T00:00:00Z');
        $config = new Config(['payment_grace' => 7]);
        $license = self::load('payment-failed');

        // Day 6: 1 day left in the grace window, still valid.
        $day6 = Validator::evaluate($license, true, null, $config, $failed_at + (6 * DAY_IN_SECONDS));
        $this->assertSame(Status::PAYMENT_FAILED_GRACE, $day6->code());
        $this->assertTrue($day6->is_valid());
        $this->assertSame(1, $day6->context('days_left'));

        // Day 7: grace has just elapsed.
        $day7 = Validator::evaluate($license, true, null, $config, $failed_at + (7 * DAY_IN_SECONDS));
        $this->assertSame(Status::PAYMENT_FAILED, $day7->code());
        $this->assertFalse($day7->is_valid());

        // Day 8: unambiguously past grace.
        $day8 = Validator::evaluate($license, true, null, $config, $failed_at + (8 * DAY_IN_SECONDS));
        $this->assertSame(Status::PAYMENT_FAILED, $day8->code());
        $this->assertFalse($day8->is_valid());
    }

    public function test_cancelled_pending_end_stays_valid_until_ended_at(): void
    {
        // subscription_cancelled_at set, subscription_ended_at still null: Gumroad
        // hasn't reached the end of the paid period yet, so this must stay valid.
        $cancelled_at = strtotime('2024-01-01T00:00:00Z');
        $status = Validator::evaluate(
            self::load('cancelled-pending'),
            true,
            null,
            new Config(),
            $cancelled_at + (10 * DAY_IN_SECONDS)
        );

        $this->assertSame(Status::CANCELLED_PENDING_END, $status->code());
        $this->assertTrue($status->is_valid());
    }

    public function test_cancelled_pending_end_expires_past_the_safety_cap(): void
    {
        $cancelled_at = strtotime('2024-01-01T00:00:00Z');
        // Monthly recurrence cap is 31 + 7 = 38 days; go well past it with no ended_at ever arriving.
        $status = Validator::evaluate(
            self::load('cancelled-pending'),
            true,
            null,
            new Config(),
            $cancelled_at + (60 * DAY_IN_SECONDS)
        );

        $this->assertSame(Status::SUBSCRIPTION_ENDED, $status->code());
        $this->assertFalse($status->is_valid());
    }

    public function test_subscription_ended_in_the_past_is_invalid(): void
    {
        $ended_at = strtotime('2024-02-01T00:00:00Z');
        $status = Validator::evaluate(self::load('ended'), true, null, new Config(), $ended_at + DAY_IN_SECONDS);

        $this->assertSame(Status::SUBSCRIPTION_ENDED, $status->code());
        $this->assertFalse($status->is_valid());
    }

    public function test_seat_limit_blocks_only_when_policy_is_block(): void
    {
        $license = self::load('valid-subscription'); // uses: 3

        $warn = Validator::evaluate($license, true, null, new Config(['max_uses' => 1, 'max_uses_policy' => 'warn']));
        $this->assertSame(Status::VALID, $warn->code());
        $this->assertTrue(Validator::seat_over_limit($license, new Config(['max_uses' => 1])));

        $block = Validator::evaluate($license, true, null, new Config(['max_uses' => 1, 'max_uses_policy' => 'block']));
        $this->assertSame(Status::SEAT_LIMIT, $block->code());
        $this->assertFalse($block->is_valid());
    }

    public function test_seat_limit_applies_by_default(): void
    {
        $license = self::load('valid-subscription'); // uses: 3, no max_uses configured

        $status = Validator::evaluate($license, true, null, new Config());

        $this->assertSame(Status::SEAT_LIMIT, $status->code());
    }

    public function test_seat_limit_disabled_when_max_uses_is_zero(): void
    {
        $license = self::load('valid-subscription'); // uses: 3

        $status = Validator::evaluate($license, true, null, new Config(['max_uses' => 0]));

        $this->assertSame(Status::VALID, $status->code());
    }

    /**
     * Historically "the entire point of the server override channel": a
     * licensing server's own seat count tightening what the plugin was
     * compiled with, without a new release. In production this override
     * channel is now moot for a real gumpress licensing server, since any
     * response it sends also carries a `gumpress.seats` block, which makes
     * evaluate() defer to the server's own seat check instead (see
     * test_server_seats_disable_the_local_max_uses_block below) — tightening
     * a customer's seat cap is done via the product's default_seat_limit,
     * not by pushing max_uses. This test still pins the raw Config/Overrides
     * mechanics for the case where no `seats` block is present (e.g. a
     * bespoke proxy that doesn't send one).
     */
    public function test_server_override_can_tighten_the_seat_limit(): void
    {
        $license = self::load('valid-subscription'); // uses: 3
        // Base starts disabled (max_uses => 0) so the override is the only
        // thing doing the tightening, not the compiled-in default.
        $effective = Overrides::apply(new Config(['max_uses' => 0]), ['max_uses' => 1, 'max_uses_policy' => 'block']);

        $status = Validator::evaluate($license, true, null, $effective);

        $this->assertSame(Status::SEAT_LIMIT, $status->code());
    }

    public function test_lock_config_keeps_the_integrators_own_seat_limit_despite_an_override(): void
    {
        $license = self::load('valid-subscription'); // uses: 3
        $base = new Config(['max_uses' => 10, 'lock_config' => ['max_uses']]);
        $effective = Overrides::apply($base, ['max_uses' => 1, 'max_uses_policy' => 'block']);

        $status = Validator::evaluate($license, true, null, $effective);

        $this->assertSame(Status::VALID, $status->code());
    }

    /**
     * The regression this whole change fixes: a stale, locally-sealed
     * max_uses must not override a licensing server that just reported the
     * license as within its (possibly since-changed) seat limit.
     */
    public function test_server_seats_disable_the_local_max_uses_block(): void
    {
        $license = self::load('valid-with-server-seats'); // uses: 3, gumpress.seats.limit: 5

        $status = Validator::evaluate($license, true, null, new Config(['max_uses' => 1, 'max_uses_policy' => 'block']));

        $this->assertSame(Status::VALID, $status->code());
    }

    public function test_server_seats_disable_the_over_limit_warning(): void
    {
        $license = self::load('valid-with-server-seats'); // uses: 3, gumpress.seats.limit: 5

        $this->assertFalse(Validator::seat_over_limit($license, new Config(['max_uses' => 1])));
    }

    public function test_server_seats_disable_the_local_max_uses_block_when_unlimited(): void
    {
        $license = self::load('valid-with-server-seats-unlimited'); // uses: 12, gumpress.seats.unlimited: true

        $status = Validator::evaluate($license, true, null, new Config(['max_uses' => 1, 'max_uses_policy' => 'block']));

        $this->assertSame(Status::VALID, $status->code());
        $this->assertFalse(Validator::seat_over_limit($license, new Config(['max_uses' => 1])));
    }

    /**
     * Pins the Gumroad-direct path explicitly: without a `gumpress.seats`
     * block, max_uses still applies exactly as before this change.
     */
    public function test_max_uses_still_applies_without_a_server_seats_block(): void
    {
        $license = self::load('valid-subscription'); // uses: 3, no gumpress block at all

        $status = Validator::evaluate($license, true, null, new Config(['max_uses' => 1, 'max_uses_policy' => 'block']));

        $this->assertSame(Status::SEAT_LIMIT, $status->code());
    }

    /**
     * A seat this site already claimed within the cap survives the global
     * `uses` counter growing past max_uses afterwards — the whole point of
     * threading seat_ordinal through, see Api::seat_ordinal().
     */
    public function test_seat_ordinal_within_cap_survives_uses_growing_past_max(): void
    {
        $license = self::load('valid-subscription'); // uses: 3
        $config = new Config(['max_uses' => 2, 'max_uses_policy' => 'block']);

        $status = Validator::evaluate($license, true, null, $config, null, 2);

        $this->assertSame(Status::VALID, $status->code());
    }

    public function test_seat_ordinal_past_cap_is_blocked(): void
    {
        $license = self::load('valid-subscription'); // uses: 3
        $config = new Config(['max_uses' => 2, 'max_uses_policy' => 'block']);

        $status = Validator::evaluate($license, true, null, $config, null, 3);

        $this->assertSame(Status::SEAT_LIMIT, $status->code());
    }

    public function test_null_seat_ordinal_falls_back_to_the_legacy_uses_check(): void
    {
        $license = self::load('valid-subscription'); // uses: 3
        $config = new Config(['max_uses' => 2, 'max_uses_policy' => 'block']);

        $status = Validator::evaluate($license, true, null, $config, null, null);

        $this->assertSame(Status::SEAT_LIMIT, $status->code());
    }

    public function test_seat_ordinal_sentinel_zero_never_blocks(): void
    {
        $license = self::load('valid-subscription'); // uses: 3
        $config = new Config(['max_uses' => 2, 'max_uses_policy' => 'block']);

        $status = Validator::evaluate($license, true, null, $config, null, 0);

        $this->assertSame(Status::VALID, $status->code());
    }

    public function test_seat_ordinal_is_ignored_when_the_server_reports_its_own_seats(): void
    {
        $license = self::load('valid-with-server-seats'); // uses: 3, gumpress.seats.limit: 5
        $config = new Config(['max_uses' => 1, 'max_uses_policy' => 'block']);

        $status = Validator::evaluate($license, true, null, $config, null, 99);

        $this->assertSame(Status::VALID, $status->code());
    }
}
