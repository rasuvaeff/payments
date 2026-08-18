<?php

declare(strict_types=1);

namespace Rasuvaeff\Payments;

/**
 * Provider-neutral payment state.
 *
 * `Succeeded`, `Failed` and `Canceled` are terminal here. A provider state
 * that is recoverable must therefore not be folded into one of them:
 * Stripe returns a declined PaymentIntent to `requires_payment_method`, the
 * customer supplies another card, and the same intent reaches `succeeded`.
 * That is what {@see self::RequiresPaymentMethod} exists for — mapping it to
 * `Failed` would model a recoverable decline as the end of the payment.
 *
 * Transitions are advisory and must not be used to discard an accepted
 * webhook observation: providers deliver out of order, and reconciliation
 * re-fetches authoritative state anyway.
 *
 * @api
 */
enum PaymentState: string
{
    case Pending = 'pending';
    case RequiresPaymentMethod = 'requires_payment_method';
    case RequiresAction = 'requires_action';
    case RequiresCapture = 'requires_capture';
    case Processing = 'processing';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    case Canceled = 'canceled';

    public function canTransitionTo(self $to): bool
    {
        if ($this === $to) {
            return true;
        }

        return match ($this) {
            self::Pending => true,
            self::RequiresPaymentMethod => in_array($to, [self::Pending, self::RequiresAction, self::Processing, self::RequiresCapture, self::Succeeded, self::Failed, self::Canceled], strict: true),
            self::RequiresAction => in_array($to, [self::Pending, self::RequiresPaymentMethod, self::Processing, self::RequiresCapture, self::Succeeded, self::Failed, self::Canceled], strict: true),
            self::RequiresCapture => in_array($to, [self::Processing, self::Succeeded, self::Failed, self::Canceled], strict: true),
            self::Processing => in_array($to, [self::RequiresPaymentMethod, self::RequiresAction, self::RequiresCapture, self::Succeeded, self::Failed, self::Canceled], strict: true),
            self::Succeeded, self::Failed, self::Canceled => false,
        };
    }
}
