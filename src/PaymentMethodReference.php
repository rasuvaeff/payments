<?php

declare(strict_types=1);

namespace Rasuvaeff\Payments;

use Rasuvaeff\Payments\Internal\Assert;

/**
 * @api
 */
final readonly class PaymentMethodReference
{
    private const int ID_MAXIMUM_LENGTH = 255;
    private const int KIND_MAXIMUM_LENGTH = 64;

    /** @var non-empty-string */
    public string $id;

    /** @var non-empty-string|null */
    public ?string $kind;

    public function __construct(
        string $id,
        ?string $kind = null,
    ) {
        Assert::nonEmpty(value: $id, name: 'Payment method id', maximumLength: self::ID_MAXIMUM_LENGTH);

        if ($kind !== null) {
            Assert::nonEmpty(value: $kind, name: 'Payment method kind', maximumLength: self::KIND_MAXIMUM_LENGTH);
        }

        $this->id = $id;
        $this->kind = $kind;
    }
}
