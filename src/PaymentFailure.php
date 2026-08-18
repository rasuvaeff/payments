<?php

declare(strict_types=1);

namespace Rasuvaeff\Payments;

use Rasuvaeff\Payments\Internal\Assert;
use Rasuvaeff\Payments\Internal\Types;

/**
 * Sanitized provider failure details. It must never contain credentials, card
 * data, arbitrary headers, or unredacted provider payloads.
 *
 * @api
 *
 * @psalm-import-type ScalarMap from Types
 */
final readonly class PaymentFailure
{
    private const int CODE_MAXIMUM_LENGTH = 128;
    private const int MESSAGE_MAXIMUM_LENGTH = 1024;

    /** @var non-empty-string */
    public string $code;

    /** @var non-empty-string|null */
    public ?string $message;

    /** @var ScalarMap */
    public array $details;

    /**
     * @param array<array-key, mixed> $details
     */
    public function __construct(
        string $code,
        ?string $message = null,
        public bool $retryable = false,
        array $details = [],
    ) {
        Assert::nonEmpty(value: $code, name: 'Failure code', maximumLength: self::CODE_MAXIMUM_LENGTH);

        if ($message !== null) {
            Assert::nonEmpty(value: $message, name: 'Failure message', maximumLength: self::MESSAGE_MAXIMUM_LENGTH);
        }

        Assert::scalarMap(value: $details, name: 'Failure details');

        $this->code = $code;
        $this->message = $message;
        $this->details = $details;
    }
}
