<?php

declare(strict_types=1);

namespace Rasuvaeff\Payments;

use Rasuvaeff\Payments\Internal\Assert;

/**
 * Application-owned business intent aggregating one or more provider attempts.
 *
 * @api
 */
final readonly class PaymentIntent
{
    private const int ID_MAXIMUM_LENGTH = 255;

    /** @var non-empty-string */
    public string $id;

    /** @var list<PaymentAttempt> */
    public array $attempts;

    /**
     * @param array<array-key, PaymentAttempt> $attempts
     */
    public function __construct(
        string $id,
        public Money $amount,
        public \DateTimeImmutable $createdAt,
        array $attempts = [],
    ) {
        Assert::nonEmpty(value: $id, name: 'Payment intent id', maximumLength: self::ID_MAXIMUM_LENGTH);

        if (!array_is_list($attempts)) {
            throw new \InvalidArgumentException('Payment intent attempts must be a list');
        }

        foreach ($attempts as $attempt) {
            if ($attempt->amount->currency !== $amount->currency) {
                throw new \InvalidArgumentException('Payment attempt currency must match intent currency');
            }
        }

        $this->id = $id;
        $this->attempts = $attempts;
    }
}
