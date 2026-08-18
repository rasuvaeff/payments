<?php

declare(strict_types=1);

namespace Rasuvaeff\Payments\Tests;

use Rasuvaeff\Payments\CustomerReference;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Expect;
use Testo\Test;

#[Test]
#[Covers(CustomerReference::class)]
final class CustomerReferenceTest
{
    public function storesApplicationOrProviderReference(): void
    {
        $reference = new CustomerReference(id: 'customer-1', providerId: 'cus_123');

        Assert::same($reference->id, 'customer-1');
        Assert::same($reference->providerId, 'cus_123');
    }

    public function rejectsEmptyApplicationId(): void
    {
        Expect::exception(\InvalidArgumentException::class);
        new CustomerReference(id: '');
    }

    public function rejectsEmptyProviderId(): void
    {
        Expect::exception(\InvalidArgumentException::class);
        new CustomerReference(id: 'customer-1', providerId: '');
    }
}
