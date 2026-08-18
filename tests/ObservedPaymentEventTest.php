<?php

declare(strict_types=1);

namespace Rasuvaeff\Payments\Tests;

use Rasuvaeff\Payments\ObservedPaymentEvent;
use Rasuvaeff\Payments\PaymentProvider;
use Rasuvaeff\Payments\PaymentReference;
use Rasuvaeff\Payments\PaymentState;
use Rasuvaeff\Payments\ProviderEventType;
use Rasuvaeff\Payments\RefundReference;
use Rasuvaeff\Payments\RefundState;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Expect;
use Testo\Test;

#[Test]
#[Covers(ObservedPaymentEvent::class)]
final class ObservedPaymentEventTest
{
    public function storesSanitizedPaymentObservation(): void
    {
        $event = new ObservedPaymentEvent(
            providerEventId: 'evt_1',
            type: new ProviderEventType(provider: Fixtures::provider(), name: 'payment_intent.succeeded'),
            payment: Fixtures::payment(),
            state: PaymentState::Succeeded,
            rawStatus: 'succeeded',
            occurredAt: new \DateTimeImmutable(),
            payload: ['amount' => 1000],
        );

        Assert::same($event->providerEventId, 'evt_1');
        Assert::same($event->payload['amount'], 1000);
    }

    public function requiresRefundReferenceForRefundState(): void
    {
        Expect::exception(\InvalidArgumentException::class);
        new ObservedPaymentEvent(
            providerEventId: 'evt_2',
            type: new ProviderEventType(provider: Fixtures::provider(), name: 'charge.refunded'),
            payment: Fixtures::payment(),
            state: RefundState::Succeeded,
            rawStatus: 'succeeded',
            occurredAt: new \DateTimeImmutable(),
        );
    }

    public function carriesRefundReferenceForRefundObservation(): void
    {
        $event = new ObservedPaymentEvent(
            providerEventId: 'evt_3',
            type: new ProviderEventType(provider: Fixtures::provider(), name: 'refund.created'),
            payment: Fixtures::payment(),
            state: RefundState::Pending,
            rawStatus: 'pending',
            occurredAt: new \DateTimeImmutable(),
            refund: new RefundReference(provider: Fixtures::provider(), id: 're_1'),
        );

        Assert::same($event->refund?->id, 're_1');
    }

    public function rejectsEmptyEventId(): void
    {
        Expect::exception(\InvalidArgumentException::class);
        $this->event(providerEventId: '');
    }

    public function rejectsEmptyRawStatus(): void
    {
        Expect::exception(\InvalidArgumentException::class);
        $this->event(rawStatus: '');
    }

    public function rejectsEventTypeProviderMismatch(): void
    {
        Expect::exception(\InvalidArgumentException::class);
        $this->event(type: new ProviderEventType(provider: new PaymentProvider(value: 'paypal'), name: 'event'));
    }

    public function rejectsRefundProviderMismatch(): void
    {
        Expect::exception(\InvalidArgumentException::class);
        $this->event(
            state: RefundState::Pending,
            refund: new RefundReference(provider: new PaymentProvider(value: 'paypal'), id: 're_1'),
        );
    }

    public function rejectsRefundReferenceForPaymentState(): void
    {
        Expect::exception(\InvalidArgumentException::class);
        $this->event(refund: new RefundReference(provider: Fixtures::provider(), id: 're_1'));
    }

    public function acceptsMaximumReferenceAndStatusLengths(): void
    {
        $event = $this->event(
            providerEventId: str_repeat('e', 255),
            rawStatus: str_repeat('s', 255),
        );

        Assert::same(strlen($event->providerEventId), 255);
        Assert::same(strlen($event->rawStatus), 255);
    }

    public function rejectsEventIdPastBoundary(): void
    {
        Expect::exception(\InvalidArgumentException::class);
        $this->event(providerEventId: str_repeat('e', 256));
    }

    public function rejectsStatusPastBoundary(): void
    {
        Expect::exception(\InvalidArgumentException::class);
        $this->event(rawStatus: str_repeat('s', 256));
    }

    public function rejectsObjectPayloadValue(): void
    {
        Expect::exception(\InvalidArgumentException::class);
        $this->event(payload: ['provider' => new \stdClass()]);
    }

    public function rejectsNestedArrayPayloadValue(): void
    {
        Expect::exception(\InvalidArgumentException::class);
        $this->event(payload: ['charge' => ['id' => 'ch_1']]);
    }

    public function rejectsResourcePayloadValue(): void
    {
        $handle = fopen('php://memory', 'rb');

        try {
            Expect::exception(\InvalidArgumentException::class);
            $this->event(payload: ['stream' => $handle]);
        } finally {
            if (is_resource($handle)) {
                fclose($handle);
            }
        }
    }

    public function rejectsListPayload(): void
    {
        Expect::exception(\InvalidArgumentException::class);
        $this->event(payload: ['first', 'second']);
    }

    public function rejectsEmptyPayloadKey(): void
    {
        Expect::exception(\InvalidArgumentException::class);
        $this->event(payload: ['' => 'value']);
    }

    public function acceptsNullPayloadValue(): void
    {
        Assert::null($this->event(payload: ['declined_reason' => null])->payload['declined_reason']);
    }

    /**
     * @param array<array-key, mixed> $payload
     */
    private function event(
        string $providerEventId = 'evt_1',
        ?ProviderEventType $type = null,
        PaymentState|RefundState $state = PaymentState::Pending,
        string $rawStatus = 'pending',
        ?RefundReference $refund = null,
        array $payload = [],
    ): ObservedPaymentEvent {
        return new ObservedPaymentEvent(
            providerEventId: $providerEventId,
            type: $type ?? new ProviderEventType(provider: Fixtures::provider(), name: 'event'),
            payment: new PaymentReference(provider: Fixtures::provider(), id: 'pi_1'),
            state: $state,
            rawStatus: $rawStatus,
            occurredAt: new \DateTimeImmutable(),
            refund: $refund,
            payload: $payload,
        );
    }
}
