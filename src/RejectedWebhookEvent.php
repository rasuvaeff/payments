<?php

declare(strict_types=1);

namespace Rasuvaeff\Payments;

use Rasuvaeff\Payments\Internal\Assert;
use Rasuvaeff\Payments\Internal\WebhookLimits;

/**
 * A validated event whose payload can never be mapped.
 *
 * The signature was valid, so the event really came from the provider, but its
 * body does not satisfy the adapter's contract — an amount with unexpected
 * precision, an identifier in an unknown format, a negative timestamp. That
 * verdict is a property of the payload, so retrying the same delivery produces
 * the same verdict forever. Treating it as retryable earns nothing and costs a
 * retry storm; providers disable an endpoint that keeps failing, which loses
 * the *following*, healthy events.
 *
 * The claim is therefore kept, not released, and HTTP bridges acknowledge.
 * Record these for a human: a rejection means the adapter and the provider
 * disagree about the payload format.
 *
 * @api
 */
final readonly class RejectedWebhookEvent implements WebhookProcessingResult
{
    /** @var non-empty-string */
    public string $providerEventId;

    /** @var non-empty-string */
    public string $reason;

    public function __construct(
        string $providerEventId,
        string $reason,
        public ?ProviderEventType $type = null,
    ) {
        Assert::nonBlank(
            value: $providerEventId,
            name: 'Provider event id',
            maximumLength: WebhookLimits::PROVIDER_EVENT_ID,
        );
        Assert::nonBlank(
            value: $reason,
            name: 'Rejected webhook event reason',
            maximumLength: WebhookLimits::REASON,
        );

        $this->providerEventId = $providerEventId;
        $this->reason = $reason;
    }
}
