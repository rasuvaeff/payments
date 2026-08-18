<?php

declare(strict_types=1);

namespace Rasuvaeff\Payments;

use Rasuvaeff\Payments\Internal\Assert;
use Rasuvaeff\Payments\Internal\Types;

/**
 * @api
 *
 * @psalm-import-type ScalarMap from Types
 */
final readonly class UseSdk implements NextAction
{
    private const int SDK_NAME_MAXIMUM_LENGTH = 128;

    /** @var non-empty-string */
    public string $sdkName;

    /** @var ScalarMap */
    public array $payload;

    /**
     * @param array<array-key, mixed> $payload
     */
    public function __construct(
        string $sdkName,
        array $payload = [],
    ) {
        Assert::nonEmpty(value: $sdkName, name: 'SDK name', maximumLength: self::SDK_NAME_MAXIMUM_LENGTH);
        Assert::scalarMap(value: $payload, name: 'SDK payload');

        $this->sdkName = $sdkName;
        $this->payload = $payload;
    }

    /**
     * @return 'use_sdk'
     */
    #[\Override]
    public function type(): string
    {
        return 'use_sdk';
    }
}
