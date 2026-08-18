<?php

declare(strict_types=1);

namespace Rasuvaeff\Payments;

/**
 * @api
 */
interface WebhookEventRecognizerInterface
{
    public function recognize(string $providerEventType): ?ProviderEventType;
}
