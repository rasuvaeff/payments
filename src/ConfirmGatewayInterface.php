<?php

declare(strict_types=1);

namespace Rasuvaeff\Payments;

/**
 * @api
 */
interface ConfirmGatewayInterface
{
    public function confirmPayment(PaymentOperationRequest $request): PaymentAttempt;
}
