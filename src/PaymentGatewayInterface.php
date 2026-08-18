<?php

declare(strict_types=1);

namespace Rasuvaeff\Payments;

/**
 * Base provider gateway. Optional operations are represented by separate ISP
 * interfaces so capabilities cannot claim unsupported methods.
 *
 * @api
 */
interface PaymentGatewayInterface
{
    public function provider(): PaymentProvider;

    public function capabilities(): CapabilitySet;

    public function createPayment(CreatePaymentRequest $request): PaymentAttempt;

    public function retrievePayment(RetrievePaymentRequest $request): PaymentAttempt;
}
