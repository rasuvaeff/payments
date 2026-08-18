<?php

declare(strict_types=1);

namespace Rasuvaeff\Payments;

use Rasuvaeff\Payments\Internal\Assert;

/**
 * @api
 */
final readonly class CustomerReference
{
    private const int MAXIMUM_LENGTH = 255;

    /** @var non-empty-string */
    public string $id;

    /** @var non-empty-string|null */
    public ?string $providerId;

    public function __construct(
        string $id,
        ?string $providerId = null,
    ) {
        Assert::nonEmpty(value: $id, name: 'Customer id', maximumLength: self::MAXIMUM_LENGTH);

        if ($providerId !== null) {
            Assert::nonEmpty(value: $providerId, name: 'Provider customer id', maximumLength: self::MAXIMUM_LENGTH);
        }

        $this->id = $id;
        $this->providerId = $providerId;
    }
}
