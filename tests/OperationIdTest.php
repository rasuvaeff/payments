<?php

declare(strict_types=1);

namespace Rasuvaeff\Payments\Tests;

use Rasuvaeff\Payments\OperationId;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Expect;
use Testo\Test;

#[Test]
#[Covers(OperationId::class)]
final class OperationIdTest
{
    public function preservesValueAndStringRepresentation(): void
    {
        $id = new OperationId(value: 'checkout-123');

        Assert::same($id->value, 'checkout-123');
        Assert::same((string) $id, 'checkout-123');
    }

    public function rejectsEmptyValue(): void
    {
        Expect::exception(\InvalidArgumentException::class);
        new OperationId(value: '');
    }
}
