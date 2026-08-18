<?php

declare(strict_types=1);

namespace Rasuvaeff\Payments;

/**
 * @api
 */
final readonly class WebhookProcessorRegistration
{
    public function __construct(
        public PaymentProvider $provider,
        public WebhookProcessorInterface $processor,
    ) {}
}
