<?php

declare(strict_types=1);

namespace Rasuvaeff\Payments;

/**
 * @api
 */
class PaymentException extends \RuntimeException
{
    /**
     * @param array<string, scalar|null> $details
     */
    public function __construct(
        string $message,
        public readonly ?string $providerCode = null,
        public readonly ?string $providerType = null,
        public readonly ?string $providerParameter = null,
        public readonly array $details = [],
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, previous: $previous);
    }
}
