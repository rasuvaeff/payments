<?php

declare(strict_types=1);

namespace Rasuvaeff\Payments;

use Rasuvaeff\Payments\Internal\Assert;

/**
 * @api
 */
final readonly class UnknownWebhookEvent implements WebhookProcessingResult
{
    private const int PROVIDER_EVENT_ID_MAXIMUM_LENGTH = 255;
    private const int PROVIDER_EVENT_TYPE_MAXIMUM_LENGTH = 255;

    /** @var non-empty-string */
    public string $providerEventId;

    /** @var non-empty-string|null */
    public ?string $providerEventType;

    public function __construct(string $providerEventId, ?string $providerEventType = null)
    {
        Assert::nonBlank(
            value: $providerEventId,
            name: 'Provider event id',
            maximumLength: self::PROVIDER_EVENT_ID_MAXIMUM_LENGTH,
        );

        if ($providerEventType !== null) {
            Assert::nonBlank(
                value: $providerEventType,
                name: 'Provider event type',
                maximumLength: self::PROVIDER_EVENT_TYPE_MAXIMUM_LENGTH,
            );
        }

        $this->providerEventId = $providerEventId;
        $this->providerEventType = $providerEventType;
    }
}
