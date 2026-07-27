<?php

declare(strict_types=1);

namespace GumPress\V2;

/**
 * Status code -> translated human message. Kept separate from Validator so
 * nothing ever calls __()/_n() before `init` — v1 built its message inside
 * is_valid_license(), which it called while registering hooks at plugin-load
 * time, tripping WordPress 6.7+'s early-translation-loading notice. It also
 * translated a raw API value directly (`__($license->get('message'))`),
 * which gettext tooling can't extract and which is a no-op regardless.
 */
final class Strings
{
    public static function reason(Status $status, string $domain): string
    {
        switch ($status->code()) {
            case Status::NO_KEY:
                return __('No license key found.', $domain);

            case Status::UNVERIFIED:
                return __('License could not be verified yet. It will be checked again shortly.', $domain);

            case Status::VALID_OFFLINE:
                return __('License was verified previously; the license server is currently unreachable.', $domain);

            case Status::UNVERIFIABLE:
                return __('Unable to reach the license server for an extended period.', $domain);

            case Status::INVALID_KEY:
                $message = $status->context('message');

                return $message
                    /* translators: %s: message returned by the license server. */
                    ? sprintf(__('License is invalid: %s', $domain), $message)
                    : __('License key is invalid.', $domain);

            case Status::TEST_KEY:
                return __('This is a test license key and those are not allowed.', $domain);

            case Status::REFUNDED:
                return __('Your purchase was refunded.', $domain);

            case Status::CHARGEBACKED:
                return __('Your purchase was charged back.', $domain);

            case Status::DISPUTED:
                return __('Your purchase is under dispute.', $domain);

            case Status::SUBSCRIPTION_ENDED:
                return __('Your subscription has ended.', $domain);

            case Status::CANCELLED_PENDING_END:
                return __('Your subscription is cancelled but stays active until the end of the current billing period.', $domain);

            case Status::PAYMENT_FAILED_GRACE:
                $days = (int) $status->context('days_left', 0);

                /* translators: %d: number of days left in the grace period. */
                return sprintf(
                    _n(
                        'Your last subscription payment failed. Your license will be deactivated in %d day.',
                        'Your last subscription payment failed. Your license will be deactivated in %d days.',
                        $days,
                        $domain
                    ),
                    $days
                );

            case Status::PAYMENT_FAILED:
                return __('Your subscription payment failed and the grace period has ended.', $domain);

            case Status::SEAT_LIMIT:
                return __('Maximum number of activations reached.', $domain);

            case Status::VALID:
                return __('Your license is valid.', $domain);

            case Status::UNKNOWN:
                return __('Unable to determine which module this request belongs to.', $domain);

            default:
                return __('Unknown license status.', $domain);
        }
    }
}
