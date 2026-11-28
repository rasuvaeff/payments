<?php

declare(strict_types=1);

namespace Rasuvaeff\Payments;

/**
 * The provider refused an idempotency key (Stripe `idempotency_error`).
 *
 * Usually the key was reused with a request that differs from the first one:
 * a fault of the caller. Retrying with the same key fails the same way, and
 * retrying with a new key repeats the operation. The exception is
 * `providerCode` `idempotency_key_in_use`: a concurrent request with the same
 * key is still running, and retrying later with the same key returns its
 * result.
 *
 * @api
 */
final class IdempotencyConflictException extends PaymentException {}
