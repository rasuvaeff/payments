<?php

declare(strict_types=1);

namespace Rasuvaeff\Payments\Tests;

use Rasuvaeff\Payments\CapabilitySet;
use Rasuvaeff\Payments\PartialRefundCapability;
use Rasuvaeff\Payments\SandboxCapability;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Expect;
use Testo\Test;

#[Test]
#[Covers(CapabilitySet::class)]
final class CapabilitySetTest
{
    public function indexesTypedCapabilities(): void
    {
        $partialRefund = new PartialRefundCapability(maximumRefunds: 3);
        $set = CapabilitySet::of($partialRefund, new SandboxCapability());

        Assert::true($set->has(PartialRefundCapability::class));
        Assert::same($set->get(PartialRefundCapability::class), $partialRefund);
        Assert::same(count($set->all()), 2);
    }

    public function rejectsDuplicateCapabilityTypes(): void
    {
        Expect::exception(\InvalidArgumentException::class);
        CapabilitySet::of(new SandboxCapability(), new SandboxCapability());
    }
}
