<?php

declare(strict_types=1);

namespace Rasuvaeff\Payments;

use Rasuvaeff\Payments\Internal\Assert;

/**
 * Allow-listed, sanitized diagnostics from one provider response.
 *
 * @api
 */
final readonly class ProviderRequestInfo
{
    private const int REQUEST_ID_MAXIMUM_LENGTH = 255;

    /** @var non-empty-string|null */
    public ?string $requestId;

    /** @var int<0, max>|null */
    public ?int $rateLimitRemaining;

    /** @var int<0, max>|null */
    public ?int $retryAfterSeconds;

    public function __construct(
        public \DateTimeImmutable $receivedAt,
        ?string $requestId = null,
        ?int $rateLimitRemaining = null,
        ?int $retryAfterSeconds = null,
    ) {
        if ($requestId !== null) {
            Assert::nonEmpty(value: $requestId, name: 'Provider request id', maximumLength: self::REQUEST_ID_MAXIMUM_LENGTH);
        }

        if ($rateLimitRemaining !== null) {
            Assert::nonNegative(value: $rateLimitRemaining, name: 'Rate limit remaining');
        }

        if ($retryAfterSeconds !== null) {
            Assert::nonNegative(value: $retryAfterSeconds, name: 'Retry after');
        }

        $this->requestId = $requestId;
        $this->rateLimitRemaining = $rateLimitRemaining;
        $this->retryAfterSeconds = $retryAfterSeconds;
    }
}
