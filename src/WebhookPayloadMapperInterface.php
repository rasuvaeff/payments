<?php

declare(strict_types=1);

namespace Rasuvaeff\Payments;

/**
 * @api
 */
interface WebhookPayloadMapperInterface
{
    /**
     * @throws UnsupportedWebhookEventException When the recognized event cannot be mapped.
     * @throws MalformedResponseException When the payload can never map and the delivery must not be retried.
     */
    public function map(WebhookInput $input, ProviderEventType $type): ObservedPaymentEvent;
}
