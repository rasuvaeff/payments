<?php

declare(strict_types=1);

namespace Rasuvaeff\Payments\Tests;

use Rasuvaeff\Payments\Money;
use Rasuvaeff\Payments\PaymentProvider;
use Rasuvaeff\Payments\PaymentReference;
use Rasuvaeff\Payments\RefundAttempt;
use Rasuvaeff\Payments\RefundReference;
use Rasuvaeff\Payments\RefundState;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Expect;
use Testo\Test;

#[Test]
#[Covers(RefundAttempt::class)]
final class RefundAttemptTest
{
    public function keepsRequestedAndActualAmountsSeparate(): void
    {
        $attempt = new RefundAttempt(
            operationId: Fixtures::operation(),
            provider: Fixtures::provider(),
            refund: new RefundReference(provider: Fixtures::provider(), id: 're_123'),
            payment: Fixtures::payment(),
            requestedAmount: Fixtures::money(minorUnits: 300),
            actualAmount: Fixtures::money(minorUnits: 300),
            state: RefundState::Succeeded,
            rawStatus: 'succeeded',
            createdAt: new \DateTimeImmutable('2026-01-01T00:00:00+00:00'),
            updatedAt: new \DateTimeImmutable('2026-01-01T00:00:01+00:00'),
            requestInfo: Fixtures::requestInfo(),
        );

        Assert::same($attempt->requestedAmount->minorUnits, 300);
        Assert::same($attempt->actualAmount?->minorUnits, 300);
    }

    public function rejectsEmptyRawStatus(): void
    {
        Expect::exception(\InvalidArgumentException::class);
        $this->create(rawStatus: '');
    }

    public function rejectsActualAmountAboveRequested(): void
    {
        Expect::exception(\InvalidArgumentException::class);
        $this->create(actualAmount: Fixtures::money(minorUnits: 400));
    }

    public function rejectsMismatchedRefundProvider(): void
    {
        Expect::exception(\InvalidArgumentException::class);
        $this->create(refund: new RefundReference(provider: new PaymentProvider(value: 'paypal'), id: 're_1'));
    }

    public function rejectsMismatchedPaymentProvider(): void
    {
        Expect::exception(\InvalidArgumentException::class);
        $this->create(payment: new PaymentReference(
            provider: new PaymentProvider(value: 'paypal'),
            id: 'order_1',
        ));
    }

    public function rejectsActualAmountInDifferentCurrency(): void
    {
        Expect::exception(\InvalidArgumentException::class);
        $this->create(actualAmount: new Money(minorUnits: 300, currency: 'USD'));
    }

    public function allowsPendingAttemptWithoutActualAmount(): void
    {
        $attempt = new RefundAttempt(
            operationId: Fixtures::operation(),
            provider: Fixtures::provider(),
            refund: new RefundReference(provider: Fixtures::provider(), id: 're_123'),
            payment: Fixtures::payment(),
            requestedAmount: Fixtures::money(minorUnits: 300),
            actualAmount: null,
            state: RefundState::Pending,
            rawStatus: 'pending',
            createdAt: new \DateTimeImmutable('2026-01-01T00:00:00+00:00'),
            updatedAt: new \DateTimeImmutable('2026-01-01T00:00:01+00:00'),
            requestInfo: Fixtures::requestInfo(),
        );

        Assert::null($attempt->actualAmount);
    }

    public function allowsEqualTimestamps(): void
    {
        $time = new \DateTimeImmutable('2026-01-01T00:00:00+00:00');
        $attempt = $this->create(createdAt: $time, updatedAt: $time);

        Assert::same($attempt->createdAt, $attempt->updatedAt);
    }

    public function rejectsReverseTimestamps(): void
    {
        Expect::exception(\InvalidArgumentException::class);
        $this->create(
            createdAt: new \DateTimeImmutable('2026-01-02T00:00:00+00:00'),
            updatedAt: new \DateTimeImmutable('2026-01-01T00:00:00+00:00'),
        );
    }

    private function create(
        ?RefundReference    $refund = null,
        ?PaymentReference   $payment = null,
        ?Money              $actualAmount = null,
        string              $rawStatus = 'pending',
        ?\DateTimeImmutable $createdAt = null,
        ?\DateTimeImmutable $updatedAt = null,
    ): RefundAttempt {
        return new RefundAttempt(
            operationId: Fixtures::operation(),
            provider: Fixtures::provider(),
            refund: $refund ?? new RefundReference(provider: Fixtures::provider(), id: 're_123'),
            payment: $payment ?? Fixtures::payment(),
            requestedAmount: Fixtures::money(minorUnits: 300),
            actualAmount: $actualAmount ?? Fixtures::money(minorUnits: 300),
            state: RefundState::Pending,
            rawStatus: $rawStatus,
            createdAt: $createdAt ?? new \DateTimeImmutable('2026-01-01T00:00:00+00:00'),
            updatedAt: $updatedAt ?? new \DateTimeImmutable('2026-01-01T00:00:01+00:00'),
            requestInfo: Fixtures::requestInfo(),
        );
    }
}
