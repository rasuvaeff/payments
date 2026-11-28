<?php

declare(strict_types=1);

namespace Rasuvaeff\Payments;

use Rasuvaeff\Payments\Internal\Assert;

/**
 * One provider execution, distinct from the application-owned PaymentIntent.
 *
 * `amount` is the amount the payment was created for — for an authorization,
 * the authorized amount — and does not change when the payment is captured.
 * `capturedAmount` is the total the provider has taken so far: `null` when
 * the response does not say, zero before a capture, the full amount once an
 * automatic or full capture succeeded, less after a partial capture. It may
 * exceed `amount` where the provider supports overcapture; the provider is
 * authoritative. Reconcile what was collected against `capturedAmount`.
 *
 * @api
 */
final readonly class PaymentAttempt
{
    private const int RAW_STATUS_MAXIMUM_LENGTH = 255;

    /** @var non-empty-string */
    public string $rawStatus;

    public function __construct(
        public OperationId $operationId,
        public PaymentProvider $provider,
        public PaymentReference $payment,
        public Money $amount,
        public PaymentState $state,
        string $rawStatus,
        public \DateTimeImmutable $createdAt,
        public \DateTimeImmutable $updatedAt,
        public ProviderRequestInfo $requestInfo,
        public ?PaymentFailure $failure = null,
        public ?NextAction $nextAction = null,
        public ?Money $capturedAmount = null,
    ) {
        Assert::nonEmpty(value: $rawStatus, name: 'Raw payment status', maximumLength: self::RAW_STATUS_MAXIMUM_LENGTH);

        if ($provider->value !== $payment->provider->value) {
            throw new \InvalidArgumentException('Payment reference provider must match attempt provider');
        }

        if ($updatedAt < $createdAt) {
            throw new \InvalidArgumentException('Payment attempt updated time cannot precede created time');
        }

        if ($capturedAmount instanceof Money && $capturedAmount->currency !== $amount->currency) {
            throw new \InvalidArgumentException('Captured amount currency must match payment amount currency');
        }

        $this->rawStatus = $rawStatus;
    }
}
