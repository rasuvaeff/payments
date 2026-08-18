<?php

declare(strict_types=1);

namespace Rasuvaeff\Payments;

use Rasuvaeff\Payments\Internal\Assert;

/**
 * @api
 */
final readonly class PaymentProvider implements \Stringable
{
    private const string KEY_PATTERN = '/^[a-z][a-z0-9_-]{0,63}\z/';

    /** @var non-empty-string */
    public string $value;

    public function __construct(string $value)
    {
        Assert::matches(
            value: $value,
            pattern: self::KEY_PATTERN,
            message: 'Payment provider must be a lowercase provider key',
        );

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
