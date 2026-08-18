<?php

declare(strict_types=1);

namespace Rasuvaeff\Payments\Tests;

use Rasuvaeff\Payments\PaymentMethodReference;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Expect;
use Testo\Test;

#[Test]
#[Covers(PaymentMethodReference::class)]
final class PaymentMethodReferenceTest
{
    public function storesIdAndOptionalKind(): void
    {
        $reference = new PaymentMethodReference(id: 'pm_1', kind: 'card');

        Assert::same($reference->id, 'pm_1');
        Assert::same($reference->kind, 'card');
    }

    public function rejectsEmptyId(): void
    {
        Expect::exception(\InvalidArgumentException::class);
        new PaymentMethodReference(id: '');
    }

    public function rejectsPresentEmptyKind(): void
    {
        Expect::exception(\InvalidArgumentException::class);
        new PaymentMethodReference(id: 'pm_1', kind: '');
    }
}
