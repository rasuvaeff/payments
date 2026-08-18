<?php

declare(strict_types=1);

namespace Rasuvaeff\Payments;

use Rasuvaeff\Payments\Internal\Assert;
use Rasuvaeff\Payments\Internal\WebhookLimits;

/**
 * @api
 */
final readonly class UnknownWebhookEvent implements WebhookProcessingResult
{
    /** @var non-empty-string */
    public string $providerEventId;

    /** @var non-empty-string|null */
    public ?string $providerEventType;

    public function __construct(string $providerEventId, ?string $providerEventType = null)
    {
        Assert::nonBlank(
            value: $providerEventId,
            name: 'Provider event id',
            maximumLength: WebhookLimits::PROVIDER_EVENT_ID,
        );

        if ($providerEventType !== null) {
            Assert::nonBlank(
                value: $providerEventType,
                name: 'Provider event type',
                maximumLength: WebhookLimits::PROVIDER_EVENT_TYPE,
            );
        }

        $this->providerEventId = $providerEventId;
        $this->providerEventType = $providerEventType;
    }
}
