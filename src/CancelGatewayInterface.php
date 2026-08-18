<?php

declare(strict_types=1);

namespace Rasuvaeff\Payments;

/**
 * @api
 */
interface CancelGatewayInterface
{
    public function cancelPayment(PaymentOperationRequest $request): PaymentAttempt;
}
