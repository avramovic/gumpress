<?php

declare(strict_types=1);

namespace GumPress\V2\Tests;

use GumPress\V2\Status;
use GumPress\V2\Strings;
use PHPUnit\Framework\TestCase;

final class StringsTest extends TestCase
{
    /** @dataProvider codes */
    public function test_every_status_code_has_a_non_empty_message(string $code): void
    {
        $status = new Status($code, ['days_left' => 3]);

        $this->assertNotSame('', Strings::reason($status, 'gumpress'));
    }

    public static function codes(): array
    {
        return [
            [Status::NO_KEY],
            [Status::UNVERIFIED],
            [Status::VALID_OFFLINE],
            [Status::UNVERIFIABLE],
            [Status::INVALID_KEY],
            [Status::TEST_KEY],
            [Status::REFUNDED],
            [Status::CHARGEBACKED],
            [Status::DISPUTED],
            [Status::SUBSCRIPTION_ENDED],
            [Status::CANCELLED_PENDING_END],
            [Status::PAYMENT_FAILED_GRACE],
            [Status::PAYMENT_FAILED],
            [Status::SEAT_LIMIT],
            [Status::VALID],
            [Status::UNKNOWN],
        ];
    }

    public function test_invalid_key_includes_server_message_when_present(): void
    {
        $status = new Status(Status::INVALID_KEY, ['message' => 'custom reason']);

        $this->assertStringContainsString('custom reason', Strings::reason($status, 'gumpress'));
    }
}
