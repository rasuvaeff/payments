<?php

declare(strict_types=1);

namespace Rasuvaeff\Payments;

use Rasuvaeff\Payments\Internal\Assert;

/**
 * @api
 */
final readonly class RefundAttempt
{
    private const int RAW_STATUS_MAXIMUM_LENGTH = 255;

    /** @var non-empty-string */
    public string $rawStatus;

    public function __construct(
        public OperationId $operationId,
        public PaymentProvider $provider,
        public RefundReference $refund,
        public PaymentReference $payment,
        public Money $requestedAmount,
        public ?Money $actualAmount,
        public RefundState $state,
        string $rawStatus,
        public \DateTimeImmutable $createdAt,
        public \DateTimeImmutable $updatedAt,
        public ProviderRequestInfo $requestInfo,
        public ?PaymentFailure $failure = null,
    ) {
        Assert::nonEmpty(value: $rawStatus, name: 'Raw refund status', maximumLength: self::RAW_STATUS_MAXIMUM_LENGTH);

        if ($provider->value !== $refund->provider->value || $provider->value !== $payment->provider->value) {
            throw new \InvalidArgumentException('Refund and payment reference providers must match attempt provider');
        }

        if ($actualAmount instanceof Money && $actualAmount->currency !== $requestedAmount->currency) {
            throw new \InvalidArgumentException('Actual refund currency must match requested currency');
        }

        if ($actualAmount instanceof Money && $actualAmount->minorUnits > $requestedAmount->minorUnits) {
            throw new \InvalidArgumentException('Actual refund amount cannot exceed the requested amount');
        }

        if ($updatedAt < $createdAt) {
            throw new \InvalidArgumentException('Refund attempt updated time cannot precede created time');
        }

        $this->rawStatus = $rawStatus;
    }
}
