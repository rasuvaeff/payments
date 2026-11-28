<?php

declare(strict_types=1);

namespace Rasuvaeff\Payments\Tests;

use Rasuvaeff\Payments\Money;
use Rasuvaeff\Payments\PaymentAttempt;
use Rasuvaeff\Payments\PaymentFailure;
use Rasuvaeff\Payments\PaymentProvider;
use Rasuvaeff\Payments\PaymentReference;
use Rasuvaeff\Payments\PaymentState;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Expect;
use Testo\Test;

#[Test]
#[Covers(PaymentAttempt::class)]
final class PaymentAttemptTest
{
    public function carriesProviderExecutionAndRawState(): void
    {
        $attempt = new PaymentAttempt(
            operationId: Fixtures::operation(),
            provider: Fixtures::provider(),
            payment: Fixtures::payment(),
            amount: Fixtures::money(),
            state: PaymentState::Succeeded,
            rawStatus: 'succeeded',
            createdAt: new \DateTimeImmutable('2026-01-01T00:00:00+00:00'),
            updatedAt: new \DateTimeImmutable('2026-01-01T00:00:01+00:00'),
            requestInfo: Fixtures::requestInfo(),
        );

        Assert::same($attempt->state, PaymentState::Succeeded);
        Assert::same($attempt->rawStatus, 'succeeded');
        Assert::null($attempt->failure);
    }

    public function preservesFailureAlongsideFailedState(): void
    {
        $attempt = new PaymentAttempt(
            operationId: Fixtures::operation(),
            provider: Fixtures::provider(),
            payment: Fixtures::payment(),
            amount: Fixtures::money(),
            state: PaymentState::Failed,
            rawStatus: 'requires_payment_method',
            createdAt: new \DateTimeImmutable('2026-01-01T00:00:00+00:00'),
            updatedAt: new \DateTimeImmutable('2026-01-01T00:00:01+00:00'),
            requestInfo: Fixtures::requestInfo(),
            failure: new PaymentFailure(code: 'declined'),
        );

        Assert::same($attempt->failure?->code, 'declined');
    }

    public function rejectsMismatchedReferenceProvider(): void
    {
        Expect::exception(\InvalidArgumentException::class);
        new PaymentAttempt(
            operationId: Fixtures::operation(),
            provider: Fixtures::provider(),
            payment: new PaymentReference(
                provider: new PaymentProvider(value: 'paypal'),
                id: 'order_1',
            ),
            amount: Fixtures::money(),
            state: PaymentState::Pending,
            rawStatus: 'created',
            createdAt: new \DateTimeImmutable(),
            updatedAt: new \DateTimeImmutable(),
            requestInfo: Fixtures::requestInfo(),
        );
    }

    public function allowsEqualTimestamps(): void
    {
        $time = new \DateTimeImmutable('2026-01-01T00:00:00+00:00');
        $attempt = new PaymentAttempt(
            operationId: Fixtures::operation(),
            provider: Fixtures::provider(),
            payment: Fixtures::payment(),
            amount: Fixtures::money(),
            state: PaymentState::Pending,
            rawStatus: 'created',
            createdAt: $time,
            updatedAt: $time,
            requestInfo: Fixtures::requestInfo(),
        );

        Assert::same($attempt->createdAt, $attempt->updatedAt);
    }

    public function rejectsEmptyRawStatus(): void
    {
        Expect::exception(\InvalidArgumentException::class);
        new PaymentAttempt(
            operationId: Fixtures::operation(),
            provider: Fixtures::provider(),
            payment: Fixtures::payment(),
            amount: Fixtures::money(),
            state: PaymentState::Pending,
            rawStatus: '',
            createdAt: new \DateTimeImmutable(),
            updatedAt: new \DateTimeImmutable(),
            requestInfo: Fixtures::requestInfo(),
        );
    }

    public function rejectsReverseTimestamps(): void
    {
        Expect::exception(\InvalidArgumentException::class);
        new PaymentAttempt(
            operationId: Fixtures::operation(),
            provider: Fixtures::provider(),
            payment: Fixtures::payment(),
            amount: Fixtures::money(),
            state: PaymentState::Pending,
            rawStatus: 'created',
            createdAt: new \DateTimeImmutable('2026-01-02T00:00:00+00:00'),
            updatedAt: new \DateTimeImmutable('2026-01-01T00:00:00+00:00'),
            requestInfo: Fixtures::requestInfo(),
        );
    }

    public function reportsNoCapturedAmountUnlessGiven(): void
    {
        Assert::null($this->attempt()->capturedAmount);
    }

    public function carriesCapturedAmountApartFromAuthorizedAmount(): void
    {
        $attempt = $this->attempt(capturedAmount: Fixtures::money(minorUnits: 600));

        Assert::same($attempt->amount->minorUnits, 1_000);
        Assert::same($attempt->capturedAmount?->minorUnits, 600);
        Assert::same($attempt->capturedAmount?->currency, 'EUR');
    }

    public function acceptsCapturedAmountAboveAuthorizedAmount(): void
    {
        // Stripe overcapture: the provider is authoritative for what it took.
        $attempt = $this->attempt(capturedAmount: Fixtures::money(minorUnits: 1_200));

        Assert::same($attempt->capturedAmount?->minorUnits, 1_200);
    }

    public function rejectsCapturedAmountInAnotherCurrency(): void
    {
        Expect::exception(\InvalidArgumentException::class)
            ->withMessage('Captured amount currency must match payment amount currency');
        $this->attempt(capturedAmount: new Money(minorUnits: 1_000, currency: 'USD'));
    }

    private function attempt(?Money $capturedAmount = null): PaymentAttempt
    {
        return new PaymentAttempt(
            operationId: Fixtures::operation(),
            provider: Fixtures::provider(),
            payment: Fixtures::payment(),
            amount: Fixtures::money(),
            state: PaymentState::Succeeded,
            rawStatus: 'succeeded',
            createdAt: new \DateTimeImmutable('2026-01-01T00:00:00+00:00'),
            updatedAt: new \DateTimeImmutable('2026-01-01T00:00:01+00:00'),
            requestInfo: Fixtures::requestInfo(),
            capturedAmount: $capturedAmount,
        );
    }
}
