<?php

declare(strict_types=1);

namespace GumPress\V2;

/**
 * The validity state machine. v1 used an if/elseif cascade with `max_uses`
 * checked *first* and a truthy default, making every check below it
 * (refund, dispute, every subscription state) unreachable dead code — so
 * refunded and cancelled customers kept working forever, and every clone or
 * staging copy burned a permanent seat with no way to release it. This is
 * ordered, independent guard clauses instead, with seat limiting evaluated
 * last — 2.0 still defaults max_uses to a non-zero value, but only after
 * fixing the ordering (so it can never mask the checks above it) and the
 * activation accounting (Api::should_increment() de-dupes by license key +
 * host and skips non-production entirely) that made the old default unsafe.
 *
 * Gumroad's `success: true` does not mean "entitled" — it returns success
 * for refunded, disputed, and ended subscriptions alike. This evaluation
 * order is the actual product.
 */
final class Validator
{
    /**
     * @param License|null $license  Last known decoded response, or null if
     *                                there has never been a successful check.
     * @param bool $reachable        Whether the last verification attempt got
     *                                an authoritative answer from the server.
     * @param int|null $valid_at     Unix timestamp of the last confirmed-valid
     *                                check, or null if never confirmed.
     * @param int|null $seat_ordinal This site's 1-based position among the
     *                                license's activations at the time it
     *                                claimed its seat (Api::seat_ordinal()),
     *                                0 meaning "never claims, never block",
     *                                or null if this site never claimed one
     *                                (including: claimed before this field
     *                                existed and not yet backfilled).
     */
    public static function evaluate(
        ?License $license,
        bool $reachable,
        ?int $valid_at,
        Config $config,
        ?int $now = null,
        ?int $seat_ordinal = null
    ): Status {
        $now = $now ?? time();

        if (!$reachable) {
            return self::evaluate_offline($valid_at, $config, $now);
        }

        // $reachable === true from here: the server gave us an authoritative answer.
        if ($license === null || !$license->success()) {
            return new Status(Status::INVALID_KEY, ['message' => $license?->message()]);
        }

        if ($license->is_test() && $config->get('disallow_test_keys')) {
            return new Status(Status::TEST_KEY);
        }

        if ($license->is_refunded()) {
            return new Status(Status::REFUNDED);
        }

        if ($license->is_chargebacked()) {
            return new Status(Status::CHARGEBACKED);
        }

        if ($license->is_disputed() && !$license->dispute_won()) {
            return new Status(Status::DISPUTED);
        }

        if ($license->is_subscription()) {
            $subscription_status = self::evaluate_subscription($license, $config, $now);
            if ($subscription_status !== null) {
                return $subscription_status;
            }
        }

        // A licensing server reporting its own seat model is authoritative:
        // its `uses`/limit can move independently of whatever max_uses was
        // sealed into this config at build time (a raised or lowered
        // default_seat_limit, a per-license override, etc.), so the shim's
        // own cap must stand down rather than second-guess it.
        if (!$license->has_server_seats()) {
            $max = (int) $config->get('max_uses');
            if ($max > 0 && $config->get('max_uses_policy', 'block') === 'block') {
                // Gumroad's `uses` is a single global counter with no site
                // identity, and it never decrements — a third site's
                // rejected attempt, a clone, a restored backup, or a site
                // still on an older GumPress that claims without probing
                // can all push it past max after this site already claimed
                // its seat fairly. Once claimed within the cap, a seat is
                // never taken back solely because the counter grew later.
                //
                // $seat_ordinal !== null means this site's own eligibility
                // is already known (its claimed position, or an explicit
                // "confirmed no room" sentinel from Api::seat_ordinal()) and
                // is judged on its own, not on the live `uses` count — a
                // site that never claimed is not itself represented in
                // `uses`, so comparing `uses` to `max` for it would read
                // "exactly full" as "room for one more" and never actually
                // block anyone. Only when nothing is known about this site's
                // own position (null — the pre-2.0.1 default, where a claim
                // always happened before this check ever ran, so `uses`
                // already includes it) does the plain `uses() > max` compare
                // apply, exactly as it always has.
                $blocked = $seat_ordinal !== null
                    ? $seat_ordinal > $max
                    : $license->uses() > $max;

                if ($blocked) {
                    return new Status(Status::SEAT_LIMIT, ['uses' => $license->uses(), 'max' => $max]);
                }
            }
        }

        return new Status(Status::VALID);
    }

    /**
     * Whether the license is currently over its configured seat limit,
     * regardless of whether that's configured to block or merely warn. Kept
     * separate from evaluate() so the admin UI can surface a "warn" notice
     * even when the overall status is VALID.
     *
     * Deliberately false whenever a licensing server is reporting its own
     * seat model — see evaluate() for why max_uses stands down in that case.
     */
    public static function seat_over_limit(?License $license, Config $config): bool
    {
        if ($license === null || $license->has_server_seats()) {
            return false;
        }

        $max = (int) $config->get('max_uses');

        return $max > 0 && $license->uses() > $max;
    }

    private static function evaluate_offline(?int $valid_at, Config $config, int $now): Status
    {
        $policy = $config->get('offline_policy', 'grace');

        if ($policy === 'open') {
            return new Status(Status::VALID_OFFLINE, ['reason' => 'policy_open']);
        }

        if ($valid_at === null) {
            // Never verified and currently unreachable: don't brick a fresh install
            // over a bad DNS day on the customer's host.
            return new Status(Status::UNVERIFIED);
        }

        if ($policy === 'closed') {
            return new Status(Status::UNVERIFIABLE, ['valid_at' => $valid_at]);
        }

        $offline_grace = ((int) $config->get('offline_grace', 14)) * DAY_IN_SECONDS;
        if (($now - $valid_at) < $offline_grace) {
            return new Status(Status::VALID_OFFLINE, ['valid_at' => $valid_at]);
        }

        return new Status(Status::UNVERIFIABLE, ['valid_at' => $valid_at]);
    }

    private static function evaluate_subscription(License $license, Config $config, int $now): ?Status
    {
        $ended = $license->subscription_ended_at();
        if ($ended !== null && $ended <= $now) {
            return new Status(Status::SUBSCRIPTION_ENDED, ['ended_at' => $ended]);
        }

        $cancelled = $license->subscription_cancelled_at();
        if ($cancelled !== null) {
            // Gumroad sets subscription_cancelled_at at request time and only sets
            // subscription_ended_at once the paid period actually elapses (often
            // null until then). Revoking immediately would cut off customers who
            // already paid through the period, so stay valid until ended_at — or,
            // if Gumroad never sends one, until a safety cap based on the billing
            // interval so this can't grant access forever.
            $cap_days = self::recurrence_days($license->recurrence()) + 7;
            $cap = $cancelled + ($cap_days * DAY_IN_SECONDS);

            if (($ended === null || $ended > $now) && $now < $cap) {
                return new Status(Status::CANCELLED_PENDING_END, ['ends_at' => $ended, 'cancelled_at' => $cancelled]);
            }

            return new Status(Status::SUBSCRIPTION_ENDED, ['cancelled_at' => $cancelled]);
        }

        $failed = $license->subscription_failed_at();
        if ($failed !== null) {
            $grace_days = (int) $config->get('payment_grace', 7);
            $days_left = $grace_days - (int) floor(($now - $failed) / DAY_IN_SECONDS);

            if ($days_left > 0) {
                return new Status(Status::PAYMENT_FAILED_GRACE, ['failed_at' => $failed, 'days_left' => $days_left]);
            }

            return new Status(Status::PAYMENT_FAILED, ['failed_at' => $failed]);
        }

        return null; // no subscription-specific state; fall through to the seat-limit / valid checks.
    }

    private static function recurrence_days(?string $recurrence): int
    {
        return match ($recurrence) {
            'monthly' => 31,
            'quarterly' => 93,
            'biannually' => 186,
            'yearly' => 366,
            default => 31,
        };
    }
}
