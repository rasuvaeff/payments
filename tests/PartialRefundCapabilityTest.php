<?php

declare(strict_types=1);

namespace Rasuvaeff\Payments\Tests;

use Rasuvaeff\Payments\PartialRefundCapability;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Expect;
use Testo\Test;

#[Test]
#[Covers(PartialRefundCapability::class)]
final class PartialRefundCapabilityTest
{
    public function exposesOptionalRefundLimit(): void
    {
        Assert::same((new PartialRefundCapability(maximumRefunds: 4))->maximumRefunds, 4);
    }

    public function acceptsOneAndRejectsZero(): void
    {
        Assert::same((new PartialRefundCapability(maximumRefunds: 1))->maximumRefunds, 1);

        Expect::exception(\InvalidArgumentException::class);
        new PartialRefundCapability(maximumRefunds: 0);
    }
}
