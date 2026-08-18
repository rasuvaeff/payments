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
     */
    public function map(WebhookInput $input, ProviderEventType $type): ObservedPaymentEvent;
}
