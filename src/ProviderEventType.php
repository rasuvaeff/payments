<?php

declare(strict_types=1);

namespace Rasuvaeff\Payments;

use Rasuvaeff\Payments\Internal\Assert;

/**
 * @api
 */
final readonly class ProviderEventType
{
    private const int MAXIMUM_LENGTH = 255;

    /** @var non-empty-string */
    public string $name;

    public function __construct(
        public PaymentProvider $provider,
        string $name,
    ) {
        Assert::nonEmpty(value: $name, name: 'Provider event type', maximumLength: self::MAXIMUM_LENGTH);

        $this->name = $name;
    }
}
