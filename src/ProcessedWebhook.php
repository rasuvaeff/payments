<?php

declare(strict_types=1);

namespace Rasuvaeff\Payments;

/**
 * @api
 */
final readonly class ProcessedWebhook implements WebhookProcessingResult
{
    public function __construct(
        public ObservedPaymentEvent $event,
        public WebhookAcknowledgementPolicy $acknowledgementPolicy,
    ) {}
}
