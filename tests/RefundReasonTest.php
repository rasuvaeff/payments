<?php

declare(strict_types=1);

namespace Rasuvaeff\Payments\Tests;

use Rasuvaeff\Payments\RefundReason;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Expect;
use Testo\Test;

#[Test]
#[Covers(RefundReason::class)]
final class RefundReasonTest
{
    public function isStringable(): void
    {
        Assert::same((string) new RefundReason(value: 'requested_by_customer'), 'requested_by_customer');
    }

    public function rejectsEmptyReason(): void
    {
        Expect::exception(\InvalidArgumentException::class);
        new RefundReason(value: '');
    }
}
