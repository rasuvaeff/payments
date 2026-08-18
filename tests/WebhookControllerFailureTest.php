<?php

declare(strict_types=1);

namespace Rasuvaeff\Payments\Tests;

use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\ServerRequest;
use Rasuvaeff\Payments\PaymentProvider;
use Rasuvaeff\Payments\Tests\Support\ThrowingWebhookProcessor;
use Rasuvaeff\Payments\WebhookController;
use Rasuvaeff\Payments\WebhookProcessorRegistration;
use Rasuvaeff\Payments\WebhookProcessorRegistry;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

#[Test]
#[Covers(WebhookController::class)]
final class WebhookControllerFailureTest
{
    public function returnsSafeRetryableResponseWhenDurableProcessingFails(): void
    {
        $provider = new PaymentProvider(value: 'stripe');
        $controller = new WebhookController(
            registry: new WebhookProcessorRegistry(processors: [
                new WebhookProcessorRegistration(
                    provider: $provider,
                    processor: new ThrowingWebhookProcessor(),
                ),
            ]),
            responseFactory: new Psr17Factory(),
        );

        $response = $controller->handle(
            request: new ServerRequest(method: 'POST', uri: '/webhooks/stripe', body: '{}'),
            provider: 'stripe',
        );

        Assert::same($response->getStatusCode(), 503);
        Assert::same($response->getHeaderLine('X-Payments-Webhook-Outcome'), 'processing_failed');
        Assert::same((string) $response->getBody(), '');
    }
}
