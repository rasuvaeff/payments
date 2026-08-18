<?php

declare(strict_types=1);

namespace Rasuvaeff\Payments\Tests;

use Rasuvaeff\Payments\CaptureMethod;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

#[Test]
#[Covers(CaptureMethod::class)]
final class CaptureMethodTest
{
    public function hasAutomaticAndManualValues(): void
    {
        Assert::same(array_column(CaptureMethod::cases(), 'value'), ['automatic', 'manual']);
    }
}
