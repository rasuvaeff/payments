<?php

declare(strict_types=1);

namespace Rasuvaeff\Payments;

use Rasuvaeff\Payments\Internal\Assert;

/**
 * @api
 */
final readonly class PartialRefundCapability implements Capability
{
    /** @var positive-int|null */
    public ?int $maximumRefunds;

    public function __construct(?int $maximumRefunds = null)
    {
        if ($maximumRefunds !== null) {
            Assert::positive(value: $maximumRefunds, name: 'Maximum refunds');
        }

        $this->maximumRefunds = $maximumRefunds;
    }
}
