<?php

declare(strict_types=1);

namespace Rasuvaeff\Payments;

/**
 * @api
 */
interface RefundGatewayInterface
{
    public function createRefund(CreateRefundRequest $request): RefundAttempt;

    public function retrieveRefund(RetrieveRefundRequest $request): RefundAttempt;
}
