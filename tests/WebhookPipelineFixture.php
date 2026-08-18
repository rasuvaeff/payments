<?php

declare(strict_types=1);

namespace Rasuvaeff\Payments\Tests;

use Rasuvaeff\Payments\InvalidWebhook;
use Rasuvaeff\Payments\MalformedResponseException;
use Rasuvaeff\Payments\Money;
use Rasuvaeff\Payments\ObservedPaymentEvent;
use Rasuvaeff\Payments\PaymentProvider;
use Rasuvaeff\Payments\PaymentReference;
use Rasuvaeff\Payments\PaymentState;
use Rasuvaeff\Payments\ProviderEventType;
use Rasuvaeff\Payments\UnsupportedWebhookEventException;
use Rasuvaeff\Payments\ValidWebhook;
use Rasuvaeff\Payments\WebhookEventQueueInterface;
use Rasuvaeff\Payments\WebhookEventRecognizerInterface;
use Rasuvaeff\Payments\WebhookEventStoreInterface;
use Rasuvaeff\Payments\WebhookEventTypeExtractorInterface;
use Rasuvaeff\Payments\WebhookInput;
use Rasuvaeff\Payments\WebhookPayloadMapperInterface;
use Rasuvaeff\Payments\WebhookReconcilerInterface;
use Rasuvaeff\Payments\WebhookValidatorInterface;

final class WebhookPipelineFixture implements
    WebhookValidatorInterface,
    WebhookEventTypeExtractorInterface,
    WebhookEventRecognizerInterface,
    WebhookPayloadMapperInterface,
    WebhookEventStoreInterface,
    WebhookEventQueueInterface,
    WebhookReconcilerInterface
{
    public PaymentProvider $paymentProvider;
    public ValidWebhook|InvalidWebhook $validation;
    public ?string $rawEventType = 'payment.succeeded';
    public ProviderEventType $recognizedType;
    public bool $recognizesEvent = true;
    public ObservedPaymentEvent $mappedEvent;
    public bool $claimResult = true;
    public bool $unsupported = false;
    public bool $malformed = false;
    public string $malformedReason = 'Amount precision is not supported';
    public string $unsupportedReason = 'Event payload version is unsupported';
    public bool $acceptanceFails = false;
    public ?ObservedPaymentEvent $acceptedEvent = null;

    /** @var list<string> */
    public array $calls = [];

    public function __construct()
    {
        $this->paymentProvider = new PaymentProvider(value: 'fixture');
        $this->validation = new ValidWebhook(providerEventId: 'evt_1');
        $this->recognizedType = new ProviderEventType(
            provider: $this->paymentProvider,
            name: 'payment.succeeded',
        );
        $this->mappedEvent = $this->event();
    }

    #[\Override]
    public function provider(): PaymentProvider
    {
        $this->calls[] = 'provider';

        return $this->paymentProvider;
    }

    #[\Override]
    public function validate(WebhookInput $input): ValidWebhook|InvalidWebhook
    {
        $this->calls[] = 'validate';

        return $this->validation;
    }

    #[\Override]
    public function extract(WebhookInput $input): ?string
    {
        $this->calls[] = 'extract';

        return $this->rawEventType;
    }

    #[\Override]
    public function recognize(string $providerEventType): ?ProviderEventType
    {
        $this->calls[] = 'recognize';

        return $this->recognizesEvent ? $this->recognizedType : null;
    }

    #[\Override]
    public function map(WebhookInput $input, ProviderEventType $type): ObservedPaymentEvent
    {
        $this->calls[] = 'map';

        if ($this->unsupported) {
            throw new UnsupportedWebhookEventException($this->unsupportedReason);
        }

        if ($this->malformed) {
            throw new MalformedResponseException($this->malformedReason);
        }

        return $this->mappedEvent;
    }

    #[\Override]
    public function claim(PaymentProvider $provider, string $providerEventId): bool
    {
        $this->calls[] = 'claim';

        return $this->claimResult;
    }

    #[\Override]
    public function complete(PaymentProvider $provider, string $providerEventId): void
    {
        $this->calls[] = 'complete';
    }

    #[\Override]
    public function release(PaymentProvider $provider, string $providerEventId): void
    {
        $this->calls[] = 'release';
    }

    #[\Override]
    public function enqueue(ObservedPaymentEvent $event): void
    {
        $this->calls[] = 'enqueue';
        $this->accept(event: $event);
    }

    #[\Override]
    public function reconcile(ObservedPaymentEvent $event): void
    {
        $this->calls[] = 'reconcile';
        $this->accept(event: $event);
    }

    public function event(
        string $providerEventId = 'evt_1',
        ?ProviderEventType $type = null,
    ): ObservedPaymentEvent {
        $type ??= $this->recognizedType;

        return new ObservedPaymentEvent(
            providerEventId: $providerEventId,
            type: $type,
            payment: new PaymentReference(provider: $type->provider, id: 'pay_1', kind: 'payment'),
            state: PaymentState::Succeeded,
            rawStatus: 'succeeded',
            occurredAt: new \DateTimeImmutable('2026-08-03T12:00:00+00:00'),
            payload: ['amount' => (new Money(minorUnits: 1_200, currency: 'EUR'))->minorUnits],
        );
    }

    private function accept(ObservedPaymentEvent $event): void
    {
        if ($this->acceptanceFails) {
            throw new \RuntimeException('Durable acceptance failed');
        }

        $this->acceptedEvent = $event;
    }
}
