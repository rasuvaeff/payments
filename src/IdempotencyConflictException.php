<?php

declare(strict_types=1);

namespace Rasuvaeff\Payments;

/**
 * The provider refused an idempotency key: it was reused with a request that
 * differs from the first one (Stripe `idempotency_error`).
 *
 * This is a fault of the caller, not of the provider. Retrying with the same
 * key fails the same way; retrying with a new key repeats the operation.
 *
 * @api
 */
final class IdempotencyConflictException extends PaymentException {}
