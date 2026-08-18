<?php

declare(strict_types=1);

namespace Rasuvaeff\Payments\Tests;

use Rasuvaeff\Payments\PaymentProvider;
use Rasuvaeff\Payments\Tests\Support\FakeWebhookProcessor;
use Rasuvaeff\Payments\WebhookProcessorRegistration;
use Rasuvaeff\Payments\WebhookProcessorRegistry;
use Rasuvaeff\Payments\WebhookValidationFailed;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Expect;
use Testo\Test;

#[Test]
#[Covers(WebhookProcessorRegistration::class)]
#[Covers(WebhookProcessorRegistry::class)]
final class WebhookProcessorRegistryTest
{
    public function indexesProcessorByProvider(): void
    {
        $provider = new PaymentProvider(value: 'stripe');
        $processor = new FakeWebhookProcessor(result: new WebhookValidationFailed(reason: 'test'));
        $registry = new WebhookProcessorRegistry(processors: [
            new WebhookProcessorRegistration(provider: $provider, processor: $processor),
        ]);

        Assert::true($registry->has(provider: $provider));
        Assert::same($registry->get(provider: $provider), $processor);
    }

    public function rejectsDuplicateAndUnknownProviders(): void
    {
        $provider = new PaymentProvider(value: 'stripe');
        $registration = new WebhookProcessorRegistration(
            provider: $provider,
            processor: new FakeWebhookProcessor(result: new WebhookValidationFailed(reason: 'test')),
        );

        Expect::exception(\InvalidArgumentException::class)->withMessage('Duplicate webhook processor for provider "stripe"');
        new WebhookProcessorRegistry(processors: [$registration, $registration]);
    }

    public function rejectsUnknownProvider(): void
    {
        $registry = new WebhookProcessorRegistry();

        Assert::false($registry->has(provider: new PaymentProvider(value: 'stripe')));
        Expect::exception(\OutOfBoundsException::class)->withMessage('Webhook processor "stripe" is not registered');
        $registry->get(provider: new PaymentProvider(value: 'stripe'));
    }
}
