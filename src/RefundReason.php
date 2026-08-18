<?php

declare(strict_types=1);

namespace Rasuvaeff\Payments;

use Rasuvaeff\Payments\Internal\Assert;

/**
 * @api
 */
final readonly class RefundReason implements \Stringable
{
    private const int MAXIMUM_LENGTH = 128;

    /** @var non-empty-string */
    public string $value;

    public function __construct(string $value)
    {
        Assert::nonEmpty(value: $value, name: 'Refund reason', maximumLength: self::MAXIMUM_LENGTH);

        $this->value = $value;
    }

    /**
     * @return non-empty-string
     */
    #[\Override]
    public function __toString(): string
    {
        return $this->value;
    }
}
