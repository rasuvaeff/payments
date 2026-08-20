<?php

declare(strict_types=1);

namespace Rasuvaeff\Payments;

use Random\RandomException;
use Rasuvaeff\Payments\Internal\Assert;

/**
 * Opaque fencing token proving ownership of one webhook event store claim.
 *
 * `WebhookEventStoreInterface::claim()` returns a fresh token with every
 * successful claim, and both finalisers require the token that is currently
 * stored for the claim. A worker whose claim was revoked by lease expiry
 * holds a stale token, so its `complete()` or `release()` becomes a no-op
 * instead of finalising an attempt that no longer owns the event.
 *
 * The value is opaque to the processor; a store generates it
 * (`::generate()` gives 128 bits of entropy), persists it next to the claim
 * and compares it before acting. Implementations embedding their own token
 * format only need it to be non-empty, URL-safe and unique per claim.
 *
 * @api
 */
final readonly class WebhookClaimToken implements \Stringable
{
    private const string VALUE_PATTERN = '/^[A-Za-z0-9_-]{1,128}\z/';

    /** @var non-empty-string */
    public string $value;

    public function __construct(string $value)
    {
        Assert::matches(
            value: $value,
            pattern: self::VALUE_PATTERN,
            message: 'Claim token must be a non-empty URL-safe string of at most 128 bytes',
        );

        $this->value = $value;
    }

    /**
     * @throws RandomException when the system entropy source fails
     */
    public static function generate(): self
    {
        return new self(bin2hex(random_bytes(16)));
    }

    public function equals(self $other): bool
    {
        return hash_equals($this->value, $other->value);
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
