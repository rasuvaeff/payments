<?php

declare(strict_types=1);

namespace Rasuvaeff\Payments;

use Rasuvaeff\Payments\Internal\Assert;

/**
 * @api
 */
final readonly class UnsupportedWebhookEvent implements WebhookProcessingResult
{
    private const int PROVIDER_EVENT_ID_MAXIMUM_LENGTH = 255;
    private const int REASON_MAXIMUM_LENGTH = 1_024;

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
            maximumLength: self::PROVIDER_EVENT_ID_MAXIMUM_LENGTH,
        );
        Assert::nonBlank(
            value: $reason,
            name: 'Unsupported webhook event reason',
            maximumLength: self::REASON_MAXIMUM_LENGTH,
        );

        $this->providerEventId = $providerEventId;
        $this->reason = $reason;
    }
}
