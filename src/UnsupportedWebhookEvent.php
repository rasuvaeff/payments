<?php

declare(strict_types=1);

namespace Rasuvaeff\Payments;

use Rasuvaeff\Payments\Internal\Assert;
use Rasuvaeff\Payments\Internal\WebhookLimits;

/**
 * @api
 */
final readonly class UnsupportedWebhookEvent implements WebhookProcessingResult
{
    /** @var non-empty-string */
    public string $providerEventId;

    /** @var non-empty-string */
    public string $reason;

    public function __construct(
        string $providerEventId,
        public ProviderEventType $type,
        string $reason,
    ) {
        Assert::nonBlank(
            value: $providerEventId,
            name: 'Provider event id',
            maximumLength: WebhookLimits::PROVIDER_EVENT_ID,
        );
        Assert::nonBlank(
            value: $reason,
            name: 'Unsupported webhook event reason',
            maximumLength: WebhookLimits::REASON,
        );

        $this->providerEventId = $providerEventId;
        $this->reason = $reason;
    }
}
