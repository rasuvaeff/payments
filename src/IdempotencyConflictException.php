<?php

declare(strict_types=1);

namespace Rasuvaeff\Payments;

/**
 * The provider refused an idempotency key: an error of type
 * `idempotency_error`, or of code `idempotency_key_in_use` whatever its type
 * (Stripe documents that code without naming its type).
 *
 * Usually the key was reused with a request that differs from the first one:
 * a fault of the caller. Retrying with the same key fails the same way, and
 * retrying with a new key repeats the operation. The exception is the
 * provider's in-flight code — `providerCode` `idempotency_key_in_use` for
 * Stripe, `PREVIOUS_REQUEST_IN_PROGRESS` for PayPal (raised by
 * rasuvaeff/payments-paypal): a concurrent request with the same key is still
 * running, and retrying later with the same key returns its result.
 *
 * @api
 */
final class IdempotencyConflictException extends PaymentException {}
