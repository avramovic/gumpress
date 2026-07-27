<?php

declare(strict_types=1);

namespace GumPress\V2;

/**
 * Immutable status value object. Carries a machine-readable code plus enough
 * context for Strings::reason() to build a message, without ever holding a
 * translated string itself (translating before `init` trips WordPress 6.7+'s
 * "translation loading was triggered too early" notice).
 */
final class Status
{
    public const NO_KEY = 'no_key';
    public const UNVERIFIED = 'unverified';
    public const VALID_OFFLINE = 'valid_offline';
    public const UNVERIFIABLE = 'unverifiable';
    public const INVALID_KEY = 'invalid_key';
    public const TEST_KEY = 'test_key';
    public const REFUNDED = 'refunded';
    public const CHARGEBACKED = 'chargebacked';
    public const DISPUTED = 'disputed';
    public const SUBSCRIPTION_ENDED = 'subscription_ended';
    public const CANCELLED_PENDING_END = 'cancelled_pending_end';
    public const PAYMENT_FAILED_GRACE = 'payment_failed_grace';
    public const PAYMENT_FAILED = 'payment_failed';
    public const SEAT_LIMIT = 'seat_limit';
    public const VALID = 'valid';
    /** Module resolution failure. Never a real licensing verdict. */
    public const UNKNOWN = 'unknown';

    private const VALID_CODES = [
        self::VALID_OFFLINE,
        self::CANCELLED_PENDING_END,
        self::PAYMENT_FAILED_GRACE,
        self::VALID,
    ];

    private string $code;
    private array $context;

    public function __construct(string $code, array $context = [])
    {
        $this->code = $code;
        $this->context = $context;
    }

    public function code(): string
    {
        return $this->code;
    }

    /**
     * @param mixed $default
     * @return mixed
     */
    public function context(string $key, $default = null)
    {
        return $this->context[$key] ?? $default;
    }

    public function is_valid(): bool
    {
        return in_array($this->code, self::VALID_CODES, true);
    }
}
