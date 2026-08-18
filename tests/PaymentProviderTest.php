<?php

declare(strict_types=1);

namespace Rasuvaeff\Payments\Tests;

use Rasuvaeff\Payments\PaymentProvider;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Expect;
use Testo\Test;

#[Test]
#[Covers(PaymentProvider::class)]
final class PaymentProviderTest
{
    public function acceptsLowercaseProviderKey(): void
    {
        Assert::same((string) new PaymentProvider(value: 'pay-pal_2'), 'pay-pal_2');
    }

    public function rejectsUppercaseKeys(): void
    {
        Expect::exception(\InvalidArgumentException::class);
        new PaymentProvider(value: 'Stripe');
    }

    public function rejectsEmptyKeys(): void
    {
        Expect::exception(\InvalidArgumentException::class);
        new PaymentProvider(value: '');
    }
}
