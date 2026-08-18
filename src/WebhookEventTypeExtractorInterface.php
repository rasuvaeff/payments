<?php

declare(strict_types=1);

namespace Rasuvaeff\Payments;

/**
 * Extracts the provider's raw event type from a validated input.
 *
 * @api
 */
interface WebhookEventTypeExtractorInterface
{
    public function extract(WebhookInput $input): ?string;
}
