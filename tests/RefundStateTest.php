<?php

declare(strict_types=1);

namespace Rasuvaeff\Payments\Tests;

use Rasuvaeff\Payments\RefundState;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

#[Test]
#[Covers(RefundState::class)]
final class RefundStateTest
{
    public function hasStableUnifiedValues(): void
    {
        Assert::same(array_column(RefundState::cases(), 'value'), ['pending', 'succeeded', 'failed', 'canceled']);
    }
}
