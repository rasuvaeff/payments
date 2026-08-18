<?php

declare(strict_types=1);

namespace Rasuvaeff\Payments\Tests;

use Rasuvaeff\Payments\PaymentReference;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Expect;
use Testo\Test;

#[Test]
#[Covers(PaymentReference::class)]
final class PaymentReferenceTest
{
    public function storesProviderIdAndKind(): void
    {
        $reference = Fixtures::payment();

        Assert::same($reference->provider->value, 'stripe');
        Assert::same($reference->id, 'pi_123');
        Assert::same($reference->kind, 'payment_intent');
    }

    public function rejectsEmptyId(): void
    {
        Expect::exception(\InvalidArgumentException::class);
        new PaymentReference(provider: Fixtures::provider(), id: '');
    }

    public function rejectsPresentEmptyKind(): void
    {
        Expect::exception(\InvalidArgumentException::class);
        new PaymentReference(provider: Fixtures::provider(), id: 'pi_1', kind: '');
    }
}
