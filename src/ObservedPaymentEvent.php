<?php

declare(strict_types=1);

namespace Rasuvaeff\Payments;

use Rasuvaeff\Payments\Internal\Assert;
use Rasuvaeff\Payments\Internal\Types;

/**
 * Sanitized webhook observation. It is not authoritative provider state.
 *
 * The payload carries small, allow-listed scalar diagnostics only. Nested
 * provider data, objects and resources are rejected: an observation is durable,
 * and anything stored here outlives the request that produced it.
 *
 * @api
 *
 * @psalm-import-type ScalarMap from Types
 */
final readonly class ObservedPaymentEvent
{
    private const int PROVIDER_EVENT_ID_MAXIMUM_LENGTH = 255;
    private const int RAW_STATUS_MAXIMUM_LENGTH = 255;

    /** @var non-empty-string */
    public string $providerEventId;

    /** @var non-empty-string */
    public string $rawStatus;

    /** @var ScalarMap */
    public array $payload;

    /**
     * @param array<array-key, mixed> $payload
     */
    public function __construct(
        string $providerEventId,
        public ProviderEventType $type,
        public PaymentReference $payment,
        public PaymentState|RefundState $state,
        string $rawStatus,
        public \DateTimeImmutable $occurredAt,
        public ?RefundReference $refund = null,
        array $payload = [],
    ) {
        Assert::nonEmpty(value: $providerEventId, name: 'Provider event id', maximumLength: self::PROVIDER_EVENT_ID_MAXIMUM_LENGTH);
        Assert::nonEmpty(value: $rawStatus, name: 'Observed raw status', maximumLength: self::RAW_STATUS_MAXIMUM_LENGTH);
        Assert::scalarMap(value: $payload, name: 'Observed event payload');

        if ($type->provider->value !== $payment->provider->value) {
            throw new \InvalidArgumentException('Event type and payment reference must use the same provider');
        }

        if ($refund instanceof RefundReference && $refund->provider->value !== $payment->provider->value) {
            throw new \InvalidArgumentException('Refund reference and payment reference must use the same provider');
        }

        if ($state instanceof RefundState && !$refund instanceof RefundReference) {
            throw new \InvalidArgumentException('Refund state requires a refund reference');
        }

        if ($state instanceof PaymentState && $refund instanceof RefundReference) {
            throw new \InvalidArgumentException('Payment state forbids a refund reference');
        }

        $this->providerEventId = $providerEventId;
        $this->rawStatus = $rawStatus;
        $this->payload = $payload;
    }
}
