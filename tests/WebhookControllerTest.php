<?php

declare(strict_types=1);

namespace Rasuvaeff\Payments\Tests;

use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\ServerRequest;
use Rasuvaeff\Payments\ObservedPaymentEvent;
use Rasuvaeff\Payments\PaymentProvider;
use Rasuvaeff\Payments\PaymentReference;
use Rasuvaeff\Payments\PaymentState;
use Rasuvaeff\Payments\ProcessedWebhook;
use Rasuvaeff\Payments\ProviderEventType;
use Rasuvaeff\Payments\RejectedWebhookEvent;
use Rasuvaeff\Payments\ReplayedWebhookEvent;
use Rasuvaeff\Payments\Tests\Support\FakeWebhookProcessor;
use Rasuvaeff\Payments\Tests\Support\UnknownProcessingResult;
use Rasuvaeff\Payments\UnknownWebhookEvent;
use Rasuvaeff\Payments\UnsupportedWebhookEvent;
use Rasuvaeff\Payments\WebhookAcknowledgementPolicy;
use Rasuvaeff\Payments\WebhookController;
use Rasuvaeff\Payments\WebhookProcessingResult;
use Rasuvaeff\Payments\WebhookProcessorRegistration;
use Rasuvaeff\Payments\WebhookProcessorRegistry;
use Rasuvaeff\Payments\WebhookValidationFailed;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Test;

#[Test]
#[Covers(WebhookController::class)]
final class WebhookControllerTest
{
    #[DataProvider('resultProvider')]
    public function mapsProcessingResultsToSafeResponses(WebhookProcessingResult $result, int $status, string $outcome): void
    {
        $provider = new PaymentProvider(value: 'stripe');
        $processor = new FakeWebhookProcessor(result: $result);
        $controller = $this->controller(provider: $provider, processor: $processor);
        $request = (new ServerRequest(method: 'POST', uri: '/webhooks/stripe', body: '{"id":"evt_1"}'))
            ->withHeader('Stripe-Signature', 'secret-signature');

        $response = $controller->handle(request: $request, provider: 'stripe');

        Assert::same($response->getStatusCode(), $status);
        Assert::same($response->getHeaderLine('X-Payments-Webhook-Outcome'), $outcome);
        Assert::same((string) $response->getBody(), '');
        Assert::same($processor->input?->rawBody, '{"id":"evt_1"}');
        Assert::same($processor->input?->header(name: 'Stripe-Signature'), ['secret-signature']);
        Assert::same($processor->input?->requestMetadata()['path'] ?? null, '/webhooks/stripe');
        Assert::same($processor->input?->requestMetadata()['method'] ?? null, 'POST');
    }

    /**
     * A body-parsing middleware that consumed the stream leaves an empty one
     * behind. Reporting that as a signature failure would send an operator
     * hunting for a wrong secret, so it gets its own outcome — and the
     * processor is never reached, since there is nothing to verify.
     */
    public function reportsAnEmptyBodyAsItsOwnOutcome(): void
    {
        $provider = new PaymentProvider(value: 'stripe');
        $processor = new FakeWebhookProcessor(result: new ReplayedWebhookEvent(providerEventId: 'evt_1'));
        $controller = $this->controller(provider: $provider, processor: $processor);

        $response = $controller->handle(
            request: new ServerRequest(method: 'POST', uri: '/webhooks/stripe', body: ''),
            provider: 'stripe',
        );

        Assert::same($response->getStatusCode(), 400);
        Assert::same($response->getHeaderLine('X-Payments-Webhook-Outcome'), 'empty_body');
        Assert::null($processor->input);
    }

    public function returnsNotFoundWithoutInvokingAProcessor(): void
    {
        $controller = new WebhookController(
            registry: new WebhookProcessorRegistry(),
            responseFactory: new Psr17Factory(),
        );
        $request = new ServerRequest(method: 'POST', uri: '/webhooks/missing');

        $missing = $controller->handle(request: $request, provider: 'missing');
        $invalid = $controller->handle(request: $request, provider: 'INVALID');

        Assert::same($missing->getStatusCode(), 404);
        Assert::same($invalid->getStatusCode(), 404);
    }

    /** @return iterable<string, array{WebhookProcessingResult, int, string}> */
    public static function resultProvider(): iterable
    {
        yield 'validation failed' => [new WebhookValidationFailed(reason: 'do not leak me'), 400, 'validation_failed'];
        yield 'processed' => [
            new ProcessedWebhook(
                event: new ObservedPaymentEvent(
                    providerEventId: 'evt_1',
                    type: new ProviderEventType(provider: new PaymentProvider(value: 'stripe'), name: 'payment_intent.succeeded'),
                    payment: new PaymentReference(provider: new PaymentProvider(value: 'stripe'), id: 'pi_1', kind: 'payment_intent'),
                    state: PaymentState::Succeeded,
                    rawStatus: 'succeeded',
                    occurredAt: new \DateTimeImmutable(timezone: new \DateTimeZone('UTC')),
                ),
                acknowledgementPolicy: WebhookAcknowledgementPolicy::AfterPersistence,
            ),
            204,
            'processed',
        ];
        yield 'replayed' => [new ReplayedWebhookEvent(providerEventId: 'evt_1'), 204, 'replayed'];
        yield 'unknown' => [new UnknownWebhookEvent(providerEventId: 'evt_1'), 204, 'ignored_unknown'];
        yield 'unsupported' => [
            new UnsupportedWebhookEvent(
                providerEventId: 'evt_1',
                type: new ProviderEventType(provider: new PaymentProvider(value: 'stripe'), name: 'payment_intent.forever'),
                reason: 'not mapped',
            ),
            204,
            'ignored_unsupported',
        ];
        yield 'rejected' => [
            new RejectedWebhookEvent(providerEventId: 'evt_1', reason: 'amount precision is wrong'),
            204,
            'rejected_payload',
        ];
        yield 'extension result' => [new UnknownProcessingResult(), 500, 'processing_failed'];
    }

    private function controller(PaymentProvider $provider, FakeWebhookProcessor $processor): WebhookController
    {
        return new WebhookController(
            registry: new WebhookProcessorRegistry(processors: [
                new WebhookProcessorRegistration(provider: $provider, processor: $processor),
            ]),
            responseFactory: new Psr17Factory(),
        );
    }
}
