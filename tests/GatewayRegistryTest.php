<?php

declare(strict_types=1);

namespace Rasuvaeff\Payments\Tests;

use Rasuvaeff\Payments\CapabilitySet;
use Rasuvaeff\Payments\GatewayRegistry;
use Rasuvaeff\Payments\PaymentGatewayInterface;
use Rasuvaeff\Payments\PaymentProvider;
use Rasuvaeff\Payments\WebhookCapability;
use Rasuvaeff\Understudy\Understudy;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Expect;
use Testo\Test;

use function Rasuvaeff\Understudy\when;

#[Test]
#[Covers(GatewayRegistry::class)]
final class GatewayRegistryTest
{
    public function indexesGatewaysAndCapabilitiesByProvider(): void
    {
        $stripe = new PaymentProvider(value: 'stripe');
        $gateway = $this->gateway(provider: $stripe);
        when(fn() => $gateway->capabilities())->returns(CapabilitySet::of(new WebhookCapability()));
        $registry = new GatewayRegistry(gateways: [$gateway]);

        Assert::true($registry->has(provider: $stripe));
        Assert::same($registry->get(provider: $stripe), $gateway);
        Assert::instanceOf($registry->capability(provider: $stripe, capability: WebhookCapability::class), WebhookCapability::class);
        Assert::same($registry->providers()[0], $stripe);
        Assert::same($registry->all()[0], $gateway);
    }

    public function rejectsDuplicateProviders(): void
    {
        $provider = new PaymentProvider(value: 'stripe');

        Expect::exception(\InvalidArgumentException::class)->withMessage('Duplicate payment gateway for provider "stripe"');
        new GatewayRegistry(gateways: [$this->gateway(provider: $provider), $this->gateway(provider: $provider)]);
    }

    public function rejectsUnknownProvider(): void
    {
        $registry = new GatewayRegistry();

        Assert::false($registry->has(provider: new PaymentProvider(value: 'stripe')));
        Expect::exception(\OutOfBoundsException::class)->withMessage('Payment gateway "stripe" is not registered');
        $registry->get(provider: new PaymentProvider(value: 'stripe'));
    }

    private function gateway(PaymentProvider $provider): PaymentGatewayInterface
    {
        $gateway = Understudy::for(PaymentGatewayInterface::class);
        when(fn() => $gateway->provider())->returns($provider);

        return $gateway;
    }
}
