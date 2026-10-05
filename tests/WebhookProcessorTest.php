<?php

declare(strict_types=1);

namespace Rasuvaeff\Payments\Tests;

use Rasuvaeff\Payments\InvalidWebhook;
use Rasuvaeff\Payments\MalformedResponseException;
use Rasuvaeff\Payments\ObservedPaymentEvent;
use Rasuvaeff\Payments\PaymentProvider;
use Rasuvaeff\Payments\PaymentReference;
use Rasuvaeff\Payments\PaymentState;
use Rasuvaeff\Payments\ProcessedWebhook;
use Rasuvaeff\Payments\ProviderEventType;
use Rasuvaeff\Payments\QueuedWebhookEventAcceptance;
use Rasuvaeff\Payments\RejectedWebhookEvent;
use Rasuvaeff\Payments\ReplayedWebhookEvent;
use Rasuvaeff\Payments\SynchronousWebhookEventAcceptance;
use Rasuvaeff\Payments\UnknownWebhookEvent;
use Rasuvaeff\Payments\UnsupportedWebhookEvent;
use Rasuvaeff\Payments\UnsupportedWebhookEventException;
use Rasuvaeff\Payments\ValidWebhook;
use Rasuvaeff\Payments\WebhookAcknowledgementPolicy;
use Rasuvaeff\Payments\WebhookClaimToken;
use Rasuvaeff\Payments\WebhookEventAcceptanceInterface;
use Rasuvaeff\Payments\WebhookEventQueueInterface;
use Rasuvaeff\Payments\WebhookEventRecognizerInterface;
use Rasuvaeff\Payments\WebhookEventStoreInterface;
use Rasuvaeff\Payments\WebhookEventTypeExtractorInterface;
use Rasuvaeff\Payments\WebhookInput;
use Rasuvaeff\Payments\WebhookPayloadMapperInterface;
use Rasuvaeff\Payments\WebhookProcessor;
use Rasuvaeff\Payments\WebhookReconcilerInterface;
use Rasuvaeff\Payments\WebhookValidationFailed;
use Rasuvaeff\Payments\WebhookValidatorInterface;
use Rasuvaeff\Understudy\Arg;
use Rasuvaeff\Understudy\Understudy;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Expect;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;

use function Rasuvaeff\Understudy\verify;
use function Rasuvaeff\Understudy\when;

#[Test]
#[Covers(WebhookProcessor::class)]
#[Covers(QueuedWebhookEventAcceptance::class)]
#[Covers(SynchronousWebhookEventAcceptance::class)]
#[Covers(UnsupportedWebhookEventException::class)]
final class WebhookProcessorTest
{
    private WebhookValidatorInterface $validator;

    private WebhookEventTypeExtractorInterface $extractor;

    private WebhookEventRecognizerInterface $recognizer;

    private WebhookPayloadMapperInterface $mapper;

    private WebhookEventStoreInterface $store;

    private WebhookEventQueueInterface $queue;

    private WebhookReconcilerInterface $reconciler;

    private PaymentProvider $provider;

    private ProviderEventType $type;

    private ObservedPaymentEvent $mappedEvent;

    private WebhookClaimToken $token;

    #[BeforeTest]
    public function setUp(): void
    {
        $this->validator = Understudy::for(WebhookValidatorInterface::class);
        $this->extractor = Understudy::for(WebhookEventTypeExtractorInterface::class);
        $this->recognizer = Understudy::for(WebhookEventRecognizerInterface::class);
        $this->mapper = Understudy::for(WebhookPayloadMapperInterface::class);
        $this->store = Understudy::for(WebhookEventStoreInterface::class);
        $this->queue = Understudy::for(WebhookEventQueueInterface::class);
        $this->reconciler = Understudy::for(WebhookReconcilerInterface::class);
        $this->provider = new PaymentProvider(value: 'fixture');
        $this->type = new ProviderEventType(provider: $this->provider, name: 'payment.succeeded');
        $this->token = WebhookClaimToken::generate();
    }

    public function processesInRequiredOrderAndDurablyEnqueues(): void
    {
        $this->stubPipeline();
        $result = $this->queuedProcessor()->process(input: $this->input());

        Assert::instanceOf($result, ProcessedWebhook::class);
        Assert::same($result->event, $this->mappedEvent);
        Assert::same($result->acknowledgementPolicy, WebhookAcknowledgementPolicy::AfterValidation);
        Understudy::verifySequence(
            fn() => $this->validator->provider(),
            fn() => $this->validator->validate(Arg::any()),
            fn() => $this->store->claim(Arg::any(), Arg::any()),
            fn() => $this->extractor->extract(Arg::any()),
            fn() => $this->recognizer->recognize(Arg::any()),
            fn() => $this->mapper->map(Arg::any(), Arg::any()),
            fn() => $this->queue->enqueue($this->mappedEvent),
            fn() => $this->store->complete(Arg::any(), Arg::any(), $this->token),
        );
    }

    public function rejectsWrongProviderBeforeValidation(): void
    {
        $this->stubPipeline();
        $result = $this->queuedProcessor()->process(input: $this->input(provider: new PaymentProvider(value: 'other')));

        Assert::instanceOf($result, WebhookValidationFailed::class);
        Assert::same($result->reason, 'Webhook validator does not support the requested provider');
        Understudy::verifySequence(
            fn() => $this->validator->provider(),
        );
    }

    public function validationFailureDoesNotClaimEvent(): void
    {
        $this->stubPipeline();
        when(fn() => $this->validator->validate(Arg::any()))->returns(new InvalidWebhook(reason: 'Invalid signature'));
        $result = $this->queuedProcessor()->process(input: $this->input());

        Assert::instanceOf($result, WebhookValidationFailed::class);
        Assert::same($result->reason, 'Invalid signature');
        Understudy::verifySequence(
            fn() => $this->validator->provider(),
            fn() => $this->validator->validate(Arg::any()),
        );
    }

    public function replayDoesNotRecognizeOrMapAgain(): void
    {
        $this->stubPipeline();
        when(fn() => $this->store->claim(Arg::any(), Arg::any()))->returns(null);
        $result = $this->queuedProcessor()->process(input: $this->input());

        Assert::instanceOf($result, ReplayedWebhookEvent::class);
        Assert::same($result->providerEventId, 'evt_1');
        Understudy::verifySequence(
            fn() => $this->validator->provider(),
            fn() => $this->validator->validate(Arg::any()),
            fn() => $this->store->claim(Arg::any(), Arg::any()),
        );
    }

    public function reportsMissingAndUnrecognizedEventTypes(): void
    {
        $this->stubPipeline();
        when(fn() => $this->extractor->extract(Arg::any()))->returns(null);
        $missing = $this->queuedProcessor()->process(input: $this->input());

        Assert::instanceOf($missing, UnknownWebhookEvent::class);
        Understudy::verifySequence(
            fn() => $this->validator->provider(),
            fn() => $this->validator->validate(Arg::any()),
            fn() => $this->store->claim(Arg::any(), Arg::any()),
            fn() => $this->extractor->extract(Arg::any()),
            fn() => $this->store->complete(Arg::any(), Arg::any(), $this->token),
        );

        Understudy::checkpoint();

        when(fn() => $this->extractor->extract(Arg::any()))->returns('payment.succeeded');
        when(fn() => $this->recognizer->recognize(Arg::any()))->returns(null);
        $unknown = $this->queuedProcessor()->process(input: $this->input());

        Assert::instanceOf($unknown, UnknownWebhookEvent::class);
        Assert::same($unknown->providerEventType, 'payment.succeeded');
        Understudy::verifySequence(
            fn() => $this->validator->provider(),
            fn() => $this->validator->validate(Arg::any()),
            fn() => $this->store->claim(Arg::any(), Arg::any()),
            fn() => $this->extractor->extract(Arg::any()),
            fn() => $this->recognizer->recognize(Arg::any()),
            fn() => $this->store->complete(Arg::any(), Arg::any(), $this->token),
        );
    }

    public function treatsBlankAndOversizedRawEventTypesAsUnknown(): void
    {
        $this->stubPipeline();
        when(fn() => $this->extractor->extract(Arg::any()))->returns('   ');
        $blank = $this->queuedProcessor()->process(input: $this->input());

        Assert::instanceOf($blank, UnknownWebhookEvent::class);
        Understudy::verifySequence(
            fn() => $this->validator->provider(),
            fn() => $this->validator->validate(Arg::any()),
            fn() => $this->store->claim(Arg::any(), Arg::any()),
            fn() => $this->extractor->extract(Arg::any()),
            fn() => $this->store->complete(Arg::any(), Arg::any(), $this->token),
        );

        Understudy::checkpoint();

        when(fn() => $this->extractor->extract(Arg::any()))->returns(str_repeat('t', 256));
        $oversized = $this->queuedProcessor()->process(input: $this->input());

        Assert::instanceOf($oversized, UnknownWebhookEvent::class);
        Understudy::verifySequence(
            fn() => $this->validator->provider(),
            fn() => $this->validator->validate(Arg::any()),
            fn() => $this->store->claim(Arg::any(), Arg::any()),
            fn() => $this->extractor->extract(Arg::any()),
            fn() => $this->store->complete(Arg::any(), Arg::any(), $this->token),
        );
    }

    public function acceptsMaximumRawEventTypeLength(): void
    {
        $this->stubPipeline();
        when(fn() => $this->extractor->extract(Arg::any()))->returns(str_repeat('t', 255));
        $result = $this->queuedProcessor()->process(input: $this->input());

        Assert::instanceOf($result, ProcessedWebhook::class);
        Understudy::verifySequence(
            fn() => $this->validator->provider(),
            fn() => $this->validator->validate(Arg::any()),
            fn() => $this->store->claim(Arg::any(), Arg::any()),
            fn() => $this->extractor->extract(Arg::any()),
            fn() => $this->recognizer->recognize(Arg::any()),
            fn() => $this->mapper->map(Arg::any(), Arg::any()),
            fn() => $this->queue->enqueue($this->mappedEvent),
            fn() => $this->store->complete(Arg::any(), Arg::any(), $this->token),
        );
    }

    public function reportsIntentionallyUnsupportedMapping(): void
    {
        $this->stubPipeline();
        when(fn() => $this->mapper->map(Arg::any(), Arg::any()))
            ->throws(new UnsupportedWebhookEventException('Event payload version is unsupported'));
        $result = $this->queuedProcessor()->process(input: $this->input());

        Assert::instanceOf($result, UnsupportedWebhookEvent::class);
        Assert::same($result->reason, 'Event payload version is unsupported');
        verify(fn() => $this->queue->enqueue(Arg::any()), never: true);
    }

    /**
     * A payload the adapter cannot map will not map on the next delivery
     * either. Retrying it earns nothing and providers disable endpoints that
     * keep failing, so the outcome is terminal: the claim is completed, never
     * released, and the HTTP bridge acknowledges.
     */
    public function rejectsPermanentlyUnmappablePayloadsWithoutRetry(): void
    {
        $this->stubPipeline();
        when(fn() => $this->mapper->map(Arg::any(), Arg::any()))
            ->throws(new MalformedResponseException('Amount precision is not supported'));
        $result = $this->queuedProcessor()->process(input: $this->input());

        Assert::instanceOf($result, RejectedWebhookEvent::class);
        Assert::same($result->reason, 'Amount precision is not supported');
        Assert::same($result->type?->name, 'payment.succeeded');
        Assert::same($result->providerEventId, 'evt_1');
        verify(fn() => $this->queue->enqueue(Arg::any()), never: true);
        Understudy::verifySequence(
            fn() => $this->validator->provider(),
            fn() => $this->validator->validate(Arg::any()),
            fn() => $this->store->claim(Arg::any(), Arg::any()),
            fn() => $this->extractor->extract(Arg::any()),
            fn() => $this->recognizer->recognize(Arg::any()),
            fn() => $this->mapper->map(Arg::any(), Arg::any()),
            fn() => $this->store->complete(Arg::any(), Arg::any(), $this->token),
        );
    }

    public function suppliesSafeFallbackForEmptyMalformedReason(): void
    {
        $this->stubPipeline();
        when(fn() => $this->mapper->map(Arg::any(), Arg::any()))->throws(new MalformedResponseException(''));
        $result = $this->queuedProcessor()->process(input: $this->input());

        Assert::instanceOf($result, RejectedWebhookEvent::class);
        Assert::same($result->reason, 'Webhook payload cannot be mapped');
    }

    /**
     * The claim outlives a crash that never reaches `release()`. Only a
     * completion signal lets a store tell an abandoned claim from a finished
     * one, so the processor must emit it exactly once, after acceptance.
     */
    public function completesTheClaimOnlyAfterDurableAcceptance(): void
    {
        $this->stubPipeline();
        when(fn() => $this->queue->enqueue(Arg::any()))->throws(new \RuntimeException('Durable acceptance failed'));

        try {
            $this->queuedProcessor()->process(input: $this->input());
        } catch (\RuntimeException) {
            verify(fn() => $this->store->complete(Arg::any(), Arg::any(), Arg::any()), never: true);
            verify(fn() => $this->store->release(Arg::any(), Arg::any(), $this->token), times: 1);

            return;
        }

        Assert::fail('Expected durable acceptance failure');
    }

    public function doesNotCompleteAClaimItDidNotWin(): void
    {
        $this->stubPipeline();
        when(fn() => $this->store->claim(Arg::any(), Arg::any()))->returns(null);
        $result = $this->queuedProcessor()->process(input: $this->input());

        Assert::instanceOf($result, ReplayedWebhookEvent::class);
        verify(fn() => $this->store->complete(Arg::any(), Arg::any(), Arg::any()), never: true);
        verify(fn() => $this->store->release(Arg::any(), Arg::any(), Arg::any()), never: true);
    }

    public function suppliesSafeFallbackForEmptyUnsupportedReason(): void
    {
        $this->stubPipeline();
        when(fn() => $this->mapper->map(Arg::any(), Arg::any()))->throws(new UnsupportedWebhookEventException(''));
        $result = $this->queuedProcessor()->process(input: $this->input());

        Assert::instanceOf($result, UnsupportedWebhookEvent::class);
        Assert::same($result->reason, 'Webhook event is unsupported');
    }

    public function rejectsRecognizerProviderMismatch(): void
    {
        $this->stubPipeline();
        when(fn() => $this->recognizer->recognize(Arg::any()))->returns(
            new ProviderEventType(
                provider: new PaymentProvider(value: 'other'),
                name: 'payment.succeeded',
            ),
        );
        $this->forbidAcceptance();

        Expect::exception(\LogicException::class)->withMessage('Recognized webhook event type uses another provider');
        $this->queuedProcessor()->process(input: $this->input());
    }

    public function rejectsMappedEventIdentityMismatch(): void
    {
        $this->stubPipeline();
        when(fn() => $this->mapper->map(Arg::any(), Arg::any()))->returns($this->event(providerEventId: 'evt_other'));
        $this->forbidAcceptance();

        Expect::exception(\LogicException::class)->withMessage('Mapped webhook event id does not match validated event id');
        $this->queuedProcessor()->process(input: $this->input());
    }

    public function rejectsMappedEventTypeMismatch(): void
    {
        $this->stubPipeline();
        $mappedType = new ProviderEventType(
            provider: $this->provider,
            name: 'payment.processing',
        );
        when(fn() => $this->mapper->map(Arg::any(), Arg::any()))->returns($this->event(type: $mappedType));
        $this->forbidAcceptance();

        Expect::exception(\LogicException::class)->withMessage('Mapped webhook event type does not match recognized event type');
        $this->queuedProcessor()->process(input: $this->input());
    }

    public function doesNotReturnProcessedWhenDurableAcceptanceFails(): void
    {
        $this->stubPipeline();
        when(fn() => $this->queue->enqueue(Arg::any()))->throws(new \RuntimeException('Durable acceptance failed'));

        try {
            $this->queuedProcessor()->process(input: $this->input());
        } catch (\RuntimeException $exception) {
            Assert::same($exception->getMessage(), 'Durable acceptance failed');
            Understudy::verifySequence(
                fn() => $this->validator->provider(),
                fn() => $this->validator->validate(Arg::any()),
                fn() => $this->store->claim(Arg::any(), Arg::any()),
                fn() => $this->extractor->extract(Arg::any()),
                fn() => $this->recognizer->recognize(Arg::any()),
                fn() => $this->mapper->map(Arg::any(), Arg::any()),
                fn() => $this->queue->enqueue($this->mappedEvent),
                fn() => $this->store->release(Arg::any(), Arg::any(), $this->token),
            );

            return;
        }

        Assert::fail('Expected durable acceptance failure');
    }

    public function keepsTheProcessingFailureWhenReleasingTheClaimAlsoFails(): void
    {
        $this->stubPipeline();
        when(fn() => $this->queue->enqueue(Arg::any()))->throws(new \RuntimeException('Durable acceptance failed'));
        when(fn() => $this->store->release(Arg::any(), Arg::any(), Arg::any()))
            ->throws(new \RuntimeException('Releasing the claim failed'));

        try {
            $this->queuedProcessor()->process(input: $this->input());
        } catch (\RuntimeException $exception) {
            Assert::same($exception->getMessage(), 'Releasing the webhook claim failed: Releasing the claim failed');
            Assert::same($exception->getPrevious()?->getMessage(), 'Durable acceptance failed');

            return;
        }

        Assert::fail('Expected the release failure to surface with the original cause attached');
    }

    public function supportsAcknowledgementAfterSynchronousPersistence(): void
    {
        $this->stubPipeline();
        $result = $this->synchronousProcessor()->process(input: $this->input());

        Assert::instanceOf($result, ProcessedWebhook::class);
        Assert::same($result->acknowledgementPolicy, WebhookAcknowledgementPolicy::AfterPersistence);
        Understudy::verifySequence(
            fn() => $this->validator->provider(),
            fn() => $this->validator->validate(Arg::any()),
            fn() => $this->store->claim(Arg::any(), Arg::any()),
            fn() => $this->extractor->extract(Arg::any()),
            fn() => $this->recognizer->recognize(Arg::any()),
            fn() => $this->mapper->map(Arg::any(), Arg::any()),
            fn() => $this->reconciler->reconcile($this->mappedEvent),
            fn() => $this->store->complete(Arg::any(), Arg::any(), $this->token),
        );
    }

    /**
     * The pipeline defaults answer the happy path: a validator for the
     * fixture provider, a valid `evt_1`, a recognized `payment.succeeded`
     * that maps to the default event, and a won claim. Every test narrows
     * these stubs for its own scenario; a stub registered later for the same
     * call wins.
     */
    private function stubPipeline(): void
    {
        $this->mappedEvent = $this->event();
        when(fn() => $this->validator->provider())->returns($this->provider);
        when(fn() => $this->validator->validate(Arg::any()))->returns(new ValidWebhook(providerEventId: 'evt_1'));
        when(fn() => $this->extractor->extract(Arg::any()))->returns('payment.succeeded');
        when(fn() => $this->recognizer->recognize(Arg::any()))->returns($this->type);
        when(fn() => $this->mapper->map(Arg::any(), Arg::any()))->returns($this->mappedEvent);
        when(fn() => $this->store->claim(Arg::any(), Arg::any()))->returns($this->token);
    }

    /**
     * The body of a mismatch test ends in the expected `LogicException`, so
     * no verify can run afterwards: the acceptance collaborators become
     * strict instead, and a call the pipeline is not allowed to make fails
     * at the call itself.
     */
    private function forbidAcceptance(): void
    {
        Understudy::strict($this->queue);
        Understudy::strict($this->reconciler);
    }

    private function queuedProcessor(): WebhookProcessor
    {
        return $this->processor(new QueuedWebhookEventAcceptance(queue: $this->queue));
    }

    private function synchronousProcessor(): WebhookProcessor
    {
        return $this->processor(new SynchronousWebhookEventAcceptance(reconciler: $this->reconciler));
    }

    private function processor(WebhookEventAcceptanceInterface $acceptance): WebhookProcessor
    {
        return new WebhookProcessor(
            validator: $this->validator,
            eventTypeExtractor: $this->extractor,
            eventRecognizer: $this->recognizer,
            payloadMapper: $this->mapper,
            eventStore: $this->store,
            eventAcceptance: $acceptance,
        );
    }

    private function input(?PaymentProvider $provider = null): WebhookInput
    {
        return new WebhookInput(
            rawBody: '{"id":"evt_1","type":"payment.succeeded"}',
            provider: $provider ?? $this->provider,
            headers: ['X-Signature' => 'test-signature'],
            requestMetadata: ['request_id' => 'request-1'],
        );
    }

    private function event(
        string $providerEventId = 'evt_1',
        ?ProviderEventType $type = null,
    ): ObservedPaymentEvent {
        $type ??= $this->type;

        return new ObservedPaymentEvent(
            providerEventId: $providerEventId,
            type: $type,
            payment: new PaymentReference(provider: $type->provider, id: 'pay_1', kind: 'payment'),
            state: PaymentState::Succeeded,
            rawStatus: 'succeeded',
            occurredAt: new \DateTimeImmutable('2026-08-03T12:00:00+00:00'),
            payload: ['amount' => 1_200],
        );
    }
}
