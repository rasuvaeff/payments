<?php

declare(strict_types=1);

namespace Rasuvaeff\Payments;

use Rasuvaeff\Payments\Internal\Assert;
use Rasuvaeff\Payments\Internal\WebhookLimits;

/**
 * @api
 */
final readonly class WebhookValidationFailed implements WebhookProcessingResult
{
    /** @var non-empty-string */
    public string $reason;

    public function __construct(string $reason)
    {
        Assert::nonBlank(
            value: $reason,
            name: 'Webhook validation failure reason',
            maximumLength: WebhookLimits::REASON,
        );

        $this->reason = $reason;
    }
}
