<?php

declare(strict_types=1);

namespace Rasuvaeff\Payments\Tests;

use Rasuvaeff\Payments\InvalidWebhook;
use Rasuvaeff\Payments\PaymentProvider;
use Rasuvaeff\Payments\ProcessedWebhook;
use Rasuvaeff\Payments\ProviderEventType;
use Rasuvaeff\Payments\QueuedWebhookEventAcceptance;
use Rasuvaeff\Payments\RejectedWebhookEvent;
use Rasuvaeff\Payments\ReplayedWebhookEvent;
use Rasuvaeff\Payments\SynchronousWebhookEventAcceptance;
use Rasuvaeff\Payments\UnknownWebhookEvent;
use Rasuvaeff\Payments\UnsupportedWebhookEvent;
use Rasuvaeff\Payments\UnsupportedWebhookEventException;
use Rasuvaeff\Payments\WebhookAcknowledgementPolicy;
use Rasuvaeff\Payments\WebhookEventAcceptanceInterface;
use Rasuvaeff\Payments\WebhookInput;
use Rasuvaeff\Payments\WebhookProcessor;
use Rasuvaeff\Payments\WebhookValidationFailed;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Expect;
use Testo\Test;

#[Test]
#[Covers(WebhookProcessor::class)]
#[Covers(QueuedWebhookEventAcceptance::class)]
#[Covers(SynchronousWebhookEventAcceptance::class)]
#[Covers(UnsupportedWebhookEventException::class)]
final class WebhookProcessorTest
{
    public function processesInRequiredOrderAndDurablyEnqueues(): void
    {
        $fixture = new WebhookPipelineFixture();
        $result = $this->processor(
            fixture: $fixture,
            acceptance: new QueuedWebhookEventAcceptance(queue: $fixture),
        )->process(input: $this->input());

        Assert::instanceOf($result, ProcessedWebhook::class);
        Assert::same($result->event, $fixture->mappedEvent);
        Assert::same($result->acknowledgementPolicy, WebhookAcknowledgementPolicy::AfterValidation);
        Assert::same($fixture->acceptedEvent, $fixture->mappedEvent);
        Assert::same($fixture->calls, [
            'provider',
            'validate',
            'claim',
            'extract',
            'recognize',
            'map',
            'enqueue',
            'complete',
        ]);
    }

    public function rejectsWrongProviderBeforeValidation(): void
    {
        $fixture = new WebhookPipelineFixture();
        $result = $this->processor(
            fixture: $fixture,
            acceptance: new QueuedWebhookEventAcceptance(queue: $fixture),
        )->process(input: $this->input(provider: new PaymentProvider(value: 'other')));

        Assert::instanceOf($result, WebhookValidationFailed::class);
        Assert::same($fixture->calls, ['provider']);
    }

    public function validationFailureDoesNotClaimEvent(): void
    {
        $fixture = new WebhookPipelineFixture();
        $fixture->validation = new InvalidWebhook(reason: 'Invalid signature');
        $result = $this->processor(
            fixture: $fixture,
            acceptance: new QueuedWebhookEventAcceptance(queue: $fixture),
        )->process(input: $this->input());

        Assert::instanceOf($result, WebhookValidationFailed::class);
        Assert::same($result->reason, 'Invalid signature');
        Assert::same($fixture->calls, ['provider', 'validate']);
    }

    public function replayDoesNotRecognizeOrMapAgain(): void
    {
        $fixture = new WebhookPipelineFixture();
        $fixture->claimResult = false;
        $result = $this->processor(
            fixture: $fixture,
            acceptance: new QueuedWebhookEventAcceptance(queue: $fixture),
        )->process(input: $this->input());

        Assert::instanceOf($result, ReplayedWebhookEvent::class);
        Assert::same($result->providerEventId, 'evt_1');
        Assert::same($fixture->calls, ['provider', 'validate', 'claim']);
    }

    public function reportsMissingAndUnrecognizedEventTypes(): void
    {
        $fixture = new WebhookPipelineFixture();
        $fixture->rawEventType = null;
        $missing = $this->processor(
            fixture: $fixture,
            acceptance: new QueuedWebhookEventAcceptance(queue: $fixture),
        )->process(input: $this->input());

        Assert::instanceOf($missing, UnknownWebhookEvent::class);
        Assert::same($fixture->calls, ['provider', 'validate', 'claim', 'extract', 'complete']);

        $fixture = new WebhookPipelineFixture();
        $fixture->recognizesEvent = false;
        $unknown = $this->processor(
            fixture: $fixture,
            acceptance: new QueuedWebhookEventAcceptance(queue: $fixture),
        )->process(input: $this->input());

        Assert::instanceOf($unknown, UnknownWebhookEvent::class);
        Assert::same($unknown->providerEventType, 'payment.succeeded');
        Assert::same($fixture->calls, ['provider', 'validate', 'claim', 'extract', 'recognize', 'complete']);
    }

    public function treatsBlankAndOversizedRawEventTypesAsUnknown(): void
    {
        $fixture = new WebhookPipelineFixture();
        $fixture->rawEventType = '   ';
        $blank = $this->processor(
            fixture: $fixture,
            acceptance: new QueuedWebhookEventAcceptance(queue: $fixture),
        )->process(input: $this->input());

        Assert::instanceOf($blank, UnknownWebhookEvent::class);
        Assert::same($fixture->calls, ['provider', 'validate', 'claim', 'extract', 'complete']);

        $fixture = new WebhookPipelineFixture();
        $fixture->rawEventType = str_repeat('t', 256);
        $oversized = $this->processor(
            fixture: $fixture,
            acceptance: new QueuedWebhookEventAcceptance(queue: $fixture),
        )->process(input: $this->input());

        Assert::instanceOf($oversized, UnknownWebhookEvent::class);
        Assert::same($fixture->calls, ['provider', 'validate', 'claim', 'extract', 'complete']);
    }

    public function acceptsMaximumRawEventTypeLength(): void
    {
        $fixture = new WebhookPipelineFixture();
        $fixture->rawEventType = str_repeat('t', 255);
        $result = $this->processor(
            fixture: $fixture,
            acceptance: new QueuedWebhookEventAcceptance(queue: $fixture),
        )->process(input: $this->input());

        Assert::instanceOf($result, ProcessedWebhook::class);
        Assert::same($fixture->calls[4], 'recognize');
    }

    public function reportsIntentionallyUnsupportedMapping(): void
    {
        $fixture = new WebhookPipelineFixture();
        $fixture->unsupported = true;
        $result = $this->processor(
            fixture: $fixture,
            acceptance: new QueuedWebhookEventAcceptance(queue: $fixture),
        )->process(input: $this->input());

        Assert::instanceOf($result, UnsupportedWebhookEvent::class);
        Assert::same($result->reason, 'Event payload version is unsupported');
        Assert::null($fixture->acceptedEvent);
    }

    /**
     * A payload the adapter cannot map will not map on the next delivery
     * either. Retrying it earns nothing and providers disable endpoints that
     * keep failing, so the outcome is terminal: the claim is completed, never
     * released, and the HTTP bridge acknowledges.
     */
    public function rejectsPermanentlyUnmappablePayloadsWithoutRetry(): void
    {
        $fixture = new WebhookPipelineFixture();
        $fixture->malformed = true;
        $result = $this->processor(
            fixture: $fixture,
            acceptance: new QueuedWebhookEventAcceptance(queue: $fixture),
        )->process(input: $this->input());

        Assert::instanceOf($result, RejectedWebhookEvent::class);
        Assert::same($result->reason, 'Amount precision is not supported');
        Assert::same($result->type?->name, 'payment.succeeded');
        Assert::same($result->providerEventId, 'evt_1');
        Assert::null($fixture->acceptedEvent);
        Assert::same($fixture->calls, ['provider', 'validate', 'claim', 'extract', 'recognize', 'map', 'complete']);
        Assert::false(in_array('release', $fixture->calls, strict: true));
    }

    public function suppliesSafeFallbackForEmptyMalformedReason(): void
    {
        $fixture = new WebhookPipelineFixture();
        $fixture->malformed = true;
        $fixture->malformedReason = '';
        $result = $this->processor(
            fixture: $fixture,
            acceptance: new QueuedWebhookEventAcceptance(queue: $fixture),
        )->process(input: $this->input());

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
        $fixture = new WebhookPipelineFixture();
        $fixture->acceptanceFails = true;

        try {
            $this->processor(
                fixture: $fixture,
                acceptance: new QueuedWebhookEventAcceptance(queue: $fixture),
            )->process(input: $this->input());
        } catch (\RuntimeException) {
            Assert::false(in_array('complete', $fixture->calls, strict: true));
            Assert::true(in_array('release', $fixture->calls, strict: true));

            return;
        }

        Assert::fail('Expected durable acceptance failure');
    }

    public function doesNotCompleteAClaimItDidNotWin(): void
    {
        $fixture = new WebhookPipelineFixture();
        $fixture->claimResult = false;
        $result = $this->processor(
            fixture: $fixture,
            acceptance: new QueuedWebhookEventAcceptance(queue: $fixture),
        )->process(input: $this->input());

        Assert::instanceOf($result, ReplayedWebhookEvent::class);
        Assert::same($fixture->calls, ['provider', 'validate', 'claim']);
    }

    public function suppliesSafeFallbackForEmptyUnsupportedReason(): void
    {
        $fixture = new WebhookPipelineFixture();
        $fixture->unsupported = true;
        $fixture->unsupportedReason = '';
        $result = $this->processor(
            fixture: $fixture,
            acceptance: new QueuedWebhookEventAcceptance(queue: $fixture),
        )->process(input: $this->input());

        Assert::instanceOf($result, UnsupportedWebhookEvent::class);
        Assert::same($result->reason, 'Webhook event is unsupported');
    }

    public function rejectsRecognizerProviderMismatch(): void
    {
        $fixture = new WebhookPipelineFixture();
        $fixture->recognizedType = new ProviderEventType(
            provider: new PaymentProvider(value: 'other'),
            name: 'payment.succeeded',
        );

        Expect::exception(\LogicException::class)->withMessage('Recognized webhook event type uses another provider');
        $this->processor(
            fixture: $fixture,
            acceptance: new QueuedWebhookEventAcceptance(queue: $fixture),
        )->process(input: $this->input());
    }

    public function rejectsMappedEventIdentityMismatch(): void
    {
        $fixture = new WebhookPipelineFixture();
        $fixture->mappedEvent = $fixture->event(providerEventId: 'evt_other');

        Expect::exception(\LogicException::class)->withMessage('Mapped webhook event id does not match validated event id');
        $this->processor(
            fixture: $fixture,
            acceptance: new QueuedWebhookEventAcceptance(queue: $fixture),
        )->process(input: $this->input());
    }

    public function rejectsMappedEventTypeMismatch(): void
    {
        $fixture = new WebhookPipelineFixture();
        $mappedType = new ProviderEventType(
            provider: $fixture->paymentProvider,
            name: 'payment.processing',
        );
        $fixture->mappedEvent = $fixture->event(type: $mappedType);

        Expect::exception(\LogicException::class)->withMessage('Mapped webhook event type does not match recognized event type');
        $this->processor(
            fixture: $fixture,
            acceptance: new QueuedWebhookEventAcceptance(queue: $fixture),
        )->process(input: $this->input());
    }

    public function doesNotReturnProcessedWhenDurableAcceptanceFails(): void
    {
        $fixture = new WebhookPipelineFixture();
        $fixture->acceptanceFails = true;

        try {
            $this->processor(
                fixture: $fixture,
                acceptance: new QueuedWebhookEventAcceptance(queue: $fixture),
            )->process(input: $this->input());
        } catch (\RuntimeException $exception) {
            Assert::same($exception->getMessage(), 'Durable acceptance failed');
            Assert::same($fixture->calls[7], 'release');

            return;
        }

        Assert::fail('Expected durable acceptance failure');
    }

    public function supportsAcknowledgementAfterSynchronousPersistence(): void
    {
        $fixture = new WebhookPipelineFixture();
        $result = $this->processor(
            fixture: $fixture,
            acceptance: new SynchronousWebhookEventAcceptance(reconciler: $fixture),
        )->process(input: $this->input());

        Assert::instanceOf($result, ProcessedWebhook::class);
        Assert::same($result->acknowledgementPolicy, WebhookAcknowledgementPolicy::AfterPersistence);
        Assert::same($fixture->calls[6], 'reconcile');
    }

    private function processor(
        WebhookPipelineFixture $fixture,
        WebhookEventAcceptanceInterface $acceptance,
    ): WebhookProcessor {
        return new WebhookProcessor(
            validator: $fixture,
            eventTypeExtractor: $fixture,
            eventRecognizer: $fixture,
            payloadMapper: $fixture,
            eventStore: $fixture,
            eventAcceptance: $acceptance,
        );
    }

    private function input(?PaymentProvider $provider = null): WebhookInput
    {
        return new WebhookInput(
            rawBody: '{"id":"evt_1","type":"payment.succeeded"}',
            provider: $provider ?? new PaymentProvider(value: 'fixture'),
            headers: ['X-Signature' => 'test-signature'],
            requestMetadata: ['request_id' => 'request-1'],
        );
    }
}
