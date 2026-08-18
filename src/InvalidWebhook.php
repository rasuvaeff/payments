<?php

declare(strict_types=1);

namespace Rasuvaeff\Payments;

use Rasuvaeff\Payments\Internal\Assert;

/**
 * @api
 */
final readonly class InvalidWebhook implements WebhookValidationResult
{
    private const int REASON_MAXIMUM_LENGTH = 1_024;

    /** @var non-empty-string */
    public string $reason;

    public function __construct(string $reason)
    {
        Assert::nonBlank(
            value: $reason,
            name: 'Webhook validation failure reason',
            maximumLength: self::REASON_MAXIMUM_LENGTH,
        );

        $this->reason = $reason;
    }
}
