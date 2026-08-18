<?php

declare(strict_types=1);

namespace Rasuvaeff\Payments;

use Rasuvaeff\Payments\Internal\Assert;
use Rasuvaeff\Payments\Internal\Types;

/**
 * One request to create a payment at a provider.
 *
 * `paymentMethod` is optional. Leaving it out is the deferred flow: the
 * provider creates the payment with no method attached and hands back what the
 * customer's browser needs to choose one (Stripe's Payment Element with
 * `automatic_payment_methods`, PayPal's approval link). Passing one is the
 * flow where the method already exists — collected client-side beforehand, or
 * stored on file.
 *
 * @api
 *
 * @psalm-import-type ScalarMap from Types
 */
final readonly class CreatePaymentRequest
{
    private const int DESCRIPTION_MAXIMUM_LENGTH = 2048;
    private const int IDEMPOTENCY_KEY_MAXIMUM_LENGTH = 255;

    /** @var non-empty-string|null */
    public ?string $description;

    /** @var ScalarMap */
    public array $metadata;

    /** @var non-empty-string|null */
    public ?string $idempotencyKey;

    /**
     * @param array<array-key, mixed> $metadata
     */
    public function __construct(
        public OperationId $operationId,
        public Money $amount,
        public ?PaymentMethodReference $paymentMethod = null,
        public ?CustomerReference $customer = null,
        public CaptureMethod $captureMethod = CaptureMethod::Automatic,
        public ConfirmationMethod $confirmationMethod = ConfirmationMethod::Automatic,
        ?string $description = null,
        array $metadata = [],
        ?string $idempotencyKey = null,
    ) {
        if ($description !== null) {
            Assert::nonEmpty(value: $description, name: 'Payment description', maximumLength: self::DESCRIPTION_MAXIMUM_LENGTH);
        }

        if ($idempotencyKey !== null) {
            Assert::headerToken(value: $idempotencyKey, name: 'Idempotency key', maximumLength: self::IDEMPOTENCY_KEY_MAXIMUM_LENGTH);
        }

        Assert::scalarMap(value: $metadata, name: 'Payment metadata');

        $this->description = $description;
        $this->metadata = $metadata;
        $this->idempotencyKey = $idempotencyKey;
    }
}
