<?php

declare(strict_types=1);

namespace Rasuvaeff\Payments;

/**
 * @api
 */
final readonly class RetrieveRefundRequest
{
    public function __construct(
        public OperationId $operationId,
        public RefundReference $refund,
    ) {}
}
