<?php

declare(strict_types=1);

namespace Rasuvaeff\Payments;

/**
 * Durably records a sanitized observation for asynchronous reconciliation.
 *
 * @api
 */
interface WebhookEventQueueInterface
{
    public function enqueue(ObservedPaymentEvent $event): void;
}
