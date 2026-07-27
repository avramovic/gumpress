<?php

declare(strict_types=1);

namespace GumPress\V2;

/**
 * The validity state machine. v1 used an if/elseif cascade where the
 * `max_uses` branch defaulted to a truthy `1`, making every check below it
 * (refund, dispute, every subscription state) unreachable dead code — so
 * refunded and cancelled customers kept working forever. This is ordered,
 * independent guard clauses instead, with seat limiting evaluated last and
 * only when explicitly enabled, so it can never mask the checks above it.
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
     */
    public static function evaluate(
        ?License $license,
        bool $reachable,
        ?int $valid_at,
        Config $config,
        ?int $now = null
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

        $max = (int) $config->get('max_uses', 0);
        if ($max > 0 && $license->uses() > $max && $config->get('max_uses_policy', 'warn') === 'block') {
            return new Status(Status::SEAT_LIMIT, ['uses' => $license->uses(), 'max' => $max]);
        }

        return new Status(Status::VALID);
    }

    /**
     * Whether the license is currently over its configured seat limit,
     * regardless of whether that's configured to block or merely warn. Kept
     * separate from evaluate() so the admin UI can surface a "warn" notice
     * even when the overall status is VALID.
     */
    public static function seat_over_limit(?License $license, Config $config): bool
    {
        if ($license === null) {
            return false;
        }

        $max = (int) $config->get('max_uses', 0);

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
