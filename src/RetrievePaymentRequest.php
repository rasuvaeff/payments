<?php

declare(strict_types=1);

namespace Rasuvaeff\Payments;

/**
 * @api
 */
final readonly class RetrievePaymentRequest
{
    public function __construct(
        public OperationId $operationId,
        public PaymentReference $payment,
    ) {}
}
