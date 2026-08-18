<?php

declare(strict_types=1);

namespace Rasuvaeff\Payments\Tests;

use Rasuvaeff\Payments\Money;
use Rasuvaeff\Payments\PaymentAttempt;
use Rasuvaeff\Payments\PaymentIntent;
use Rasuvaeff\Payments\PaymentState;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Expect;
use Testo\Test;

#[Test]
#[Covers(PaymentIntent::class)]
final class PaymentIntentTest
{
    public function aggregatesAttemptsWithoutReplacingTheirReferences(): void
    {
        $attempt = new PaymentAttempt(
            operationId: Fixtures::operation(),
            provider: Fixtures::provider(),
            payment: Fixtures::payment(),
            amount: Fixtures::money(),
            state: PaymentState::Processing,
            rawStatus: 'processing',
            createdAt: new \DateTimeImmutable('2026-01-01T00:00:00+00:00'),
            updatedAt: new \DateTimeImmutable('2026-01-01T00:00:01+00:00'),
            requestInfo: Fixtures::requestInfo(),
        );
        $intent = new PaymentIntent(id: 'intent-1', amount: Fixtures::money(), createdAt: new \DateTimeImmutable(), attempts: [$attempt]);

        Assert::same(count($intent->attempts), 1);
        Assert::same($intent->attempts[0]->payment->id, 'pi_123');
    }

    public function rejectsEmptyIntentId(): void
    {
        Expect::exception(\InvalidArgumentException::class);
        new PaymentIntent(id: '', amount: Fixtures::money(), createdAt: new \DateTimeImmutable());
    }

    public function rejectsAttemptInDifferentCurrency(): void
    {
        $attempt = new PaymentAttempt(
            operationId: Fixtures::operation(),
            provider: Fixtures::provider(),
            payment: Fixtures::payment(),
            amount: new Money(minorUnits: 1, currency: 'USD'),
            state: PaymentState::Pending,
            rawStatus: 'created',
            createdAt: new \DateTimeImmutable(),
            updatedAt: new \DateTimeImmutable(),
            requestInfo: Fixtures::requestInfo(),
        );

        Expect::exception(\InvalidArgumentException::class);
        new PaymentIntent(id: 'intent-1', amount: Fixtures::money(), createdAt: new \DateTimeImmutable(), attempts: [$attempt]);
    }

    public function rejectsSparseAttemptArray(): void
    {
        $attempt = new PaymentAttempt(
            operationId: Fixtures::operation(),
            provider: Fixtures::provider(),
            payment: Fixtures::payment(),
            amount: Fixtures::money(),
            state: PaymentState::Pending,
            rawStatus: 'created',
            createdAt: new \DateTimeImmutable(),
            updatedAt: new \DateTimeImmutable(),
            requestInfo: Fixtures::requestInfo(),
        );

        Expect::exception(\InvalidArgumentException::class);
        new PaymentIntent(id: 'intent-1', amount: Fixtures::money(), createdAt: new \DateTimeImmutable(), attempts: [3 => $attempt]);
    }
}
