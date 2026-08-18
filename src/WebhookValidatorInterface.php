<?php

declare(strict_types=1);

namespace Rasuvaeff\Payments;

/**
 * @api
 */
interface WebhookValidatorInterface
{
    public function provider(): PaymentProvider;

    /**
     * The native union return type is the contract: an implementation cannot
     * widen it to `WebhookValidationResult`, and returning any other class
     * raises a `TypeError` naming the offending value.
     */
    public function validate(WebhookInput $input): ValidWebhook|InvalidWebhook;
}
