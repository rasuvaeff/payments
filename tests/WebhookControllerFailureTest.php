<?php

declare(strict_types=1);

namespace Rasuvaeff\Payments\Tests;

use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\ServerRequest;
use Psr\Log\LoggerInterface;
use Rasuvaeff\Payments\PaymentProvider;
use Rasuvaeff\Payments\WebhookController;
use Rasuvaeff\Payments\WebhookProcessorInterface;
use Rasuvaeff\Payments\WebhookProcessorRegistration;
use Rasuvaeff\Payments\WebhookProcessorRegistry;
use Rasuvaeff\Understudy\Arg;
use Rasuvaeff\Understudy\Understudy;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

use function Rasuvaeff\Understudy\verify;
use function Rasuvaeff\Understudy\when;

#[Test]
#[Covers(WebhookController::class)]
final class WebhookControllerFailureTest
{
    public function returnsSafeRetryableResponseWhenDurableProcessingFails(): void
    {
        $controller = $this->controller();

        $response = $controller->handle(
            request: new ServerRequest(method: 'POST', uri: '/webhooks/stripe', body: '{}'),
            provider: 'stripe',
        );

        Assert::same($response->getStatusCode(), 503);
        Assert::same($response->getHeaderLine('X-Payments-Webhook-Outcome'), 'processing_failed');
        Assert::same((string) $response->getBody(), '');
    }

    public function recordsTheCauseWhenALoggerIsGiven(): void
    {
        $logger = Understudy::for(LoggerInterface::class);
        $messages = Arg::captor();
        $contexts = Arg::captor();
        when(fn() => $logger->error($messages->capture(), $contexts->capture()));
        $controller = $this->controller(logger: $logger);

        $response = $controller->handle(
            request: new ServerRequest(method: 'POST', uri: '/webhooks/stripe', body: '{}'),
            provider: 'stripe',
        );

        Assert::same($response->getStatusCode(), 503);
        verify(fn() => $logger->error(Arg::any(), Arg::any()), times: 1);
        Understudy::nothingElse($logger);
        Assert::same($messages->all(), ['Webhook processing failed']);
        $exception = $contexts->last()['exception'] ?? null;
        Assert::true($exception instanceof \RuntimeException);
        Assert::same($exception->getMessage(), 'Sensitive persistence failure');
        Assert::same($contexts->last()['provider'], 'stripe');
    }

    private function controller(?LoggerInterface $logger = null): WebhookController
    {
        $provider = new PaymentProvider(value: 'stripe');

        return new WebhookController(
            registry: new WebhookProcessorRegistry(processors: [
                new WebhookProcessorRegistration(
                    provider: $provider,
                    processor: $this->failingProcessor(),
                ),
            ]),
            responseFactory: new Psr17Factory(),
            logger: $logger,
        );
    }

    private function failingProcessor(): WebhookProcessorInterface
    {
        $processor = Understudy::for(WebhookProcessorInterface::class);
        when(fn() => $processor->process(Arg::any()))->throws(new \RuntimeException('Sensitive persistence failure'));

        return $processor;
    }
}
