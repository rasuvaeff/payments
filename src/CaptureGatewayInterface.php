<?php

declare(strict_types=1);

namespace Rasuvaeff\Payments;

/**
 * @api
 */
interface CaptureGatewayInterface
{
    public function capturePayment(CapturePaymentRequest $request): PaymentAttempt;
}
