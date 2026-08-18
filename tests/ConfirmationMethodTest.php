<?php

declare(strict_types=1);

namespace Rasuvaeff\Payments\Tests;

use Rasuvaeff\Payments\ConfirmationMethod;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

#[Test]
#[Covers(ConfirmationMethod::class)]
final class ConfirmationMethodTest
{
    public function hasAutomaticAndManualValues(): void
    {
        Assert::same(array_column(ConfirmationMethod::cases(), 'value'), ['automatic', 'manual']);
    }
}
