<?php

declare(strict_types=1);

namespace Rasuvaeff\Payments;

/**
 * Validates, claims, maps and durably accepts provider webhook events.
 *
 * Every outcome of `process()` is final for the claimed event id and completes
 * the claim — including the ones that ignore the event (unknown, unsupported,
 * rejected), because repeating them would produce the same verdict. Only a
 * thrown exception releases the claim, which is what makes the delivery
 * retryable.
 *
 * @api
 */
final readonly class WebhookProcessor implements WebhookProcessorInterface
{
    private const int EVENT_TYPE_MAXIMUM_LENGTH = 255;

    public function __construct(
        private WebhookValidatorInterface $validator,
        private WebhookEventTypeExtractorInterface $eventTypeExtractor,
        private WebhookEventRecognizerInterface $eventRecognizer,
        private WebhookPayloadMapperInterface $payloadMapper,
        private WebhookEventStoreInterface $eventStore,
        private WebhookEventAcceptanceInterface $eventAcceptance,
    ) {}

    #[\Override]
    public function process(
        WebhookInput $input,
    ): ProcessedWebhook|WebhookValidationFailed|UnknownWebhookEvent|UnsupportedWebhookEvent|RejectedWebhookEvent|ReplayedWebhookEvent {
        if ($this->validator->provider()->value !== $input->provider->value) {
            return new WebhookValidationFailed(
                reason: 'Webhook validator does not support the requested provider',
            );
        }

        $validation = $this->validator->validate(input: $input);

        if ($validation instanceof InvalidWebhook) {
            return new WebhookValidationFailed(reason: $validation->reason);
        }

        $providerEventId = $validation->providerEventId;

        if (!$this->eventStore->claim(provider: $input->provider, providerEventId: $providerEventId)) {
            return new ReplayedWebhookEvent(providerEventId: $providerEventId);
        }

        try {
            $result = $this->processClaimed(input: $input, providerEventId: $providerEventId);
        } catch (\Throwable $exception) {
            $this->eventStore->release(provider: $input->provider, providerEventId: $providerEventId);

            throw $exception;
        }

        $this->eventStore->complete(provider: $input->provider, providerEventId: $providerEventId);

        return $result;
    }

    /**
     * @param non-empty-string $providerEventId
     */
    private function processClaimed(
        WebhookInput $input,
        string $providerEventId,
    ): ProcessedWebhook|UnknownWebhookEvent|UnsupportedWebhookEvent|RejectedWebhookEvent {
        $rawEventType = $this->eventTypeExtractor->extract(input: $input);

        if ($rawEventType === null || trim($rawEventType) === '') {
            return new UnknownWebhookEvent(providerEventId: $providerEventId);
        }

        if (strlen($rawEventType) > self::EVENT_TYPE_MAXIMUM_LENGTH) {
            return new UnknownWebhookEvent(providerEventId: $providerEventId);
        }

        $eventType = $this->eventRecognizer->recognize(providerEventType: $rawEventType);

        if (!$eventType instanceof ProviderEventType) {
            return new UnknownWebhookEvent(
                providerEventId: $providerEventId,
                providerEventType: $rawEventType,
            );
        }

        if ($eventType->provider->value !== $input->provider->value) {
            throw new \LogicException('Recognized webhook event type uses another provider');
        }

        try {
            $event = $this->payloadMapper->map(input: $input, type: $eventType);
        } catch (UnsupportedWebhookEventException $exception) {
            return new UnsupportedWebhookEvent(
                providerEventId: $providerEventId,
                type: $eventType,
                reason: $exception->getMessage() !== '' ? $exception->getMessage() : 'Webhook event is unsupported',
            );
        } catch (MalformedResponseException $exception) {
            return new RejectedWebhookEvent(
                providerEventId: $providerEventId,
                reason: $exception->getMessage() !== '' ? $exception->getMessage() : 'Webhook payload cannot be mapped',
                type: $eventType,
            );
        }

        $this->assertMappedEvent(event: $event, eventType: $eventType, providerEventId: $providerEventId);
        $this->eventAcceptance->accept(event: $event);

        return new ProcessedWebhook(
            event: $event,
            acknowledgementPolicy: $this->eventAcceptance->policy(),
        );
    }

    /**
     * @param non-empty-string $providerEventId
     */
    private function assertMappedEvent(
        ObservedPaymentEvent $event,
        ProviderEventType $eventType,
        string $providerEventId,
    ): void {
        if ($event->providerEventId !== $providerEventId) {
            throw new \LogicException('Mapped webhook event id does not match validated event id');
        }

        if ($event->type->provider->value !== $eventType->provider->value || $event->type->name !== $eventType->name) {
            throw new \LogicException('Mapped webhook event type does not match recognized event type');
        }
    }
}
