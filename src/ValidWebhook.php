<?php

declare(strict_types=1);

namespace Rasuvaeff\Payments;

use Rasuvaeff\Payments\Internal\Assert;
use Rasuvaeff\Payments\Internal\WebhookLimits;

/**
 * @api
 */
final readonly class ValidWebhook implements WebhookValidationResult
{
    /** @var non-empty-string */
    public string $providerEventId;

    public function __construct(string $providerEventId)
    {
        Assert::nonBlank(
            value: $providerEventId,
            name: 'Provider event id',
            maximumLength: WebhookLimits::PROVIDER_EVENT_ID,
        );

        $this->providerEventId = $providerEventId;
    }
}
