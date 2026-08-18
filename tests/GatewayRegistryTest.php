<?php

declare(strict_types=1);

namespace Rasuvaeff\Payments\Tests;

use Rasuvaeff\Payments\GatewayRegistry;
use Rasuvaeff\Payments\PaymentProvider;
use Rasuvaeff\Payments\Tests\Support\FakeGateway;
use Rasuvaeff\Payments\WebhookCapability;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Expect;
use Testo\Test;

#[Test]
#[Covers(GatewayRegistry::class)]
final class GatewayRegistryTest
{
    public function indexesGatewaysAndCapabilitiesByProvider(): void
    {
        $stripe = new PaymentProvider(value: 'stripe');
        $gateway = new FakeGateway(paymentProvider: $stripe);
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
        new GatewayRegistry(gateways: [new FakeGateway($provider), new FakeGateway($provider)]);
    }

    public function rejectsUnknownProvider(): void
    {
        $registry = new GatewayRegistry();

        Assert::false($registry->has(provider: new PaymentProvider(value: 'stripe')));
        Expect::exception(\OutOfBoundsException::class)->withMessage('Payment gateway "stripe" is not registered');
        $registry->get(provider: new PaymentProvider(value: 'stripe'));
    }
}
