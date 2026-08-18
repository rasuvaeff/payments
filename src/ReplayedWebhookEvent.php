<?php

declare(strict_types=1);

namespace Rasuvaeff\Payments;

use Rasuvaeff\Payments\Internal\Assert;

/**
 * @api
 */
final readonly class ReplayedWebhookEvent implements WebhookProcessingResult
{
    private const int PROVIDER_EVENT_ID_MAXIMUM_LENGTH = 255;

    /** @var non-empty-string */
    public string $providerEventId;

    public function __construct(string $providerEventId)
    {
        Assert::nonBlank(
            value: $providerEventId,
            name: 'Provider event id',
            maximumLength: self::PROVIDER_EVENT_ID_MAXIMUM_LENGTH,
        );

        $this->providerEventId = $providerEventId;
    }
}
