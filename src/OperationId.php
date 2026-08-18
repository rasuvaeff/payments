<?php

declare(strict_types=1);

namespace Rasuvaeff\Payments;

use Rasuvaeff\Payments\Internal\Assert;

/**
 * Application-issued identifier for one logical payment command.
 *
 * @api
 */
final readonly class OperationId implements \Stringable
{
    private const int MAXIMUM_LENGTH = 255;

    /** @var non-empty-string */
    public string $value;

    public function __construct(string $value)
    {
        Assert::nonEmpty(value: $value, name: 'Operation id', maximumLength: self::MAXIMUM_LENGTH);

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
