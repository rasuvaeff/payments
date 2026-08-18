<?php

declare(strict_types=1);

namespace Rasuvaeff\Payments\Tests\Support;

use Rasuvaeff\Payments\CapabilitySet;
use Rasuvaeff\Payments\CreatePaymentRequest;
use Rasuvaeff\Payments\PaymentAttempt;
use Rasuvaeff\Payments\PaymentGatewayInterface;
use Rasuvaeff\Payments\PaymentProvider;
use Rasuvaeff\Payments\RetrievePaymentRequest;

final readonly class BaseFakeGateway implements PaymentGatewayInterface
{
    private FakeGateway $delegate;

    public function __construct(PaymentProvider $provider)
    {
        $this->delegate = new FakeGateway(paymentProvider: $provider);
    }

    #[\Override]
    public function provider(): PaymentProvider
    {
        return $this->delegate->provider();
    }

    #[\Override]
    public function capabilities(): CapabilitySet
    {
        return CapabilitySet::of();
    }

    #[\Override]
    public function createPayment(CreatePaymentRequest $request): PaymentAttempt
    {
        return $this->delegate->createPayment(request: $request);
    }

    #[\Override]
    public function retrievePayment(RetrievePaymentRequest $request): PaymentAttempt
    {
        return $this->delegate->retrievePayment(request: $request);
    }
}
