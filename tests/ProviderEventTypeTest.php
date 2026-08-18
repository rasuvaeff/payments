<?php

declare(strict_types=1);

namespace Rasuvaeff\Payments\Tests;

use Rasuvaeff\Payments\ProviderEventType;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Expect;
use Testo\Test;

#[Test]
#[Covers(ProviderEventType::class)]
final class ProviderEventTypeTest
{
    public function keepsProviderAndRawName(): void
    {
        $type = new ProviderEventType(provider: Fixtures::provider(), name: 'payment_intent.succeeded');

        Assert::same($type->provider->value, 'stripe');
        Assert::same($type->name, 'payment_intent.succeeded');
    }

    public function rejectsEmptyRawName(): void
    {
        Expect::exception(\InvalidArgumentException::class);
        new ProviderEventType(provider: Fixtures::provider(), name: '');
    }
}
