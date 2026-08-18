<?php

declare(strict_types=1);

namespace Rasuvaeff\Payments;

use Rasuvaeff\Payments\Internal\Assert;

/**
 * One provider execution, distinct from the application-owned PaymentIntent.
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
    ) {
        Assert::nonEmpty(value: $rawStatus, name: 'Raw payment status', maximumLength: self::RAW_STATUS_MAXIMUM_LENGTH);

        if ($provider->value !== $payment->provider->value) {
            throw new \InvalidArgumentException('Payment reference provider must match attempt provider');
        }

        if ($updatedAt < $createdAt) {
            throw new \InvalidArgumentException('Payment attempt updated time cannot precede created time');
        }

        $this->rawStatus = $rawStatus;
    }
}
