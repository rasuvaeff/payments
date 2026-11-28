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
 * `idempotencyKey` is never empty. Without one the request mints a random
 * UUID v4 (36 characters, inside every provider's cap), so the effective key
 * is known before the call and reaches the adapter: retry by sending the
 * same request object again, or persist `$request->idempotencyKey` and build
 * the retry with it. A request built anew inside a retry loop gets a new key
 * and is a new payment.
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

    public CaptureMethod $captureMethod;
    public ConfirmationMethod $confirmationMethod;

    /** @var non-empty-string */
    public string $idempotencyKey;

    /**
     * @param array<array-key, mixed> $metadata
     */
    public function __construct(
        public OperationId $operationId,
        public Money $amount,
        public ?PaymentMethodReference $paymentMethod = null,
        public ?CustomerReference $customer = null,
        ?CaptureMethod $captureMethod = null,
        ?ConfirmationMethod $confirmationMethod = null,
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

        $this->captureMethod = $captureMethod ?? CaptureMethod::Automatic;
        $this->confirmationMethod = $confirmationMethod ?? ConfirmationMethod::Automatic;
        $this->description = $description;
        $this->metadata = $metadata;
        $this->idempotencyKey = $idempotencyKey ?? $this->generateIdempotencyKey();
    }

    /**
     * A random (version 4) UUID.
     *
     * @return non-empty-string
     */
    private function generateIdempotencyKey(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = \chr(\ord($bytes[6]) & 0x0F | 0x40);
        $bytes[8] = \chr(\ord($bytes[8]) & 0x3F | 0x80);

        $hex = bin2hex($bytes);

        return \sprintf('%s-%s-%s-%s-%s', substr($hex, 0, 8), substr($hex, 8, 4), substr($hex, 12, 4), substr($hex, 16, 4), substr($hex, 20));
    }
}
