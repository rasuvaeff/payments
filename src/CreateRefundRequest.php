<?php

declare(strict_types=1);

namespace Rasuvaeff\Payments;

use Rasuvaeff\Payments\Internal\Assert;

/**
 * @api
 */
final readonly class CreateRefundRequest
{
    private const int IDEMPOTENCY_KEY_MAXIMUM_LENGTH = 255;

    /** @var non-empty-string|null */
    public ?string $idempotencyKey;

    public function __construct(
        public OperationId $operationId,
        public PaymentReference $payment,
        public ?Money $amount = null,
        public ?RefundReason $reason = null,
        ?string $idempotencyKey = null,
    ) {
        if ($idempotencyKey !== null) {
            Assert::headerToken(value: $idempotencyKey, name: 'Idempotency key', maximumLength: self::IDEMPOTENCY_KEY_MAXIMUM_LENGTH);
        }

        $this->idempotencyKey = $idempotencyKey;
    }
}
