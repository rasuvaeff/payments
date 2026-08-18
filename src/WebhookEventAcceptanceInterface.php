<?php

declare(strict_types=1);

namespace Rasuvaeff\Payments;

/**
 * Defines the durable boundary required before a webhook may be acknowledged.
 *
 * @api
 */
interface WebhookEventAcceptanceInterface
{
    public function policy(): WebhookAcknowledgementPolicy;

    public function accept(ObservedPaymentEvent $event): void;
}
