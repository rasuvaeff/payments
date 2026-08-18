<?php

declare(strict_types=1);

namespace Rasuvaeff\Payments\Tests;

use Rasuvaeff\Payments\RefundReference;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Expect;
use Testo\Test;

#[Test]
#[Covers(RefundReference::class)]
final class RefundReferenceTest
{
    public function storesProviderIdAndKind(): void
    {
        $reference = new RefundReference(provider: Fixtures::provider(), id: 're_123', kind: 'refund');

        Assert::same($reference->id, 're_123');
        Assert::same($reference->kind, 'refund');
    }

    public function rejectsEmptyIdAndPresentEmptyKind(): void
    {
        Expect::exception(\InvalidArgumentException::class);
        new RefundReference(provider: Fixtures::provider(), id: '');
    }

    public function rejectsPresentEmptyKind(): void
    {
        Expect::exception(\InvalidArgumentException::class);
        new RefundReference(provider: Fixtures::provider(), id: 're_1', kind: '');
    }
}
