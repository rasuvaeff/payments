<?php

declare(strict_types=1);

namespace Rasuvaeff\Payments\Tests;

use Rasuvaeff\Payments\ObservedPaymentEvent;
use Rasuvaeff\Payments\PaymentProvider;
use Rasuvaeff\Payments\PaymentReference;
use Rasuvaeff\Payments\PaymentState;
use Rasuvaeff\Payments\ProcessedWebhook;
use Rasuvaeff\Payments\ProviderEventType;
use Rasuvaeff\Payments\RejectedWebhookEvent;
use Rasuvaeff\Payments\ReplayedWebhookEvent;
use Rasuvaeff\Payments\UnknownWebhookEvent;
use Rasuvaeff\Payments\UnsupportedWebhookEvent;
use Rasuvaeff\Payments\WebhookAcknowledgementPolicy;
use Rasuvaeff\Payments\WebhookProcessingResult;
use Rasuvaeff\Payments\WebhookValidationFailed;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Expect;
use Testo\Test;

#[Test]
#[Covers(ProcessedWebhook::class)]
#[Covers(WebhookValidationFailed::class)]
#[Covers(UnknownWebhookEvent::class)]
#[Covers(UnsupportedWebhookEvent::class)]
#[Covers(ReplayedWebhookEvent::class)]
#[Covers(RejectedWebhookEvent::class)]
#[Covers(WebhookAcknowledgementPolicy::class)]
final class WebhookProcessingResultTest
{
    public function processedResultKeepsEventAndPolicy(): void
    {
        $event = self::event();
        $result = new ProcessedWebhook(
            event: $event,
            acknowledgementPolicy: WebhookAcknowledgementPolicy::AfterValidation,
        );

        Assert::instanceOf($result, WebhookProcessingResult::class);
        Assert::same($result->event, $event);
        Assert::same($result->event->providerEventId, 'evt_1');
        Assert::same($result->event->type->name, 'payment.succeeded');
        Assert::same($result->acknowledgementPolicy, WebhookAcknowledgementPolicy::AfterValidation);
    }

    public function rejectionCarriesItsReasonAndOptionalType(): void
    {
        $type = self::type();
        $typed = new RejectedWebhookEvent(
            providerEventId: 'evt_1',
            reason: 'Amount precision is not supported',
            type: $type,
        );
        $untyped = new RejectedWebhookEvent(providerEventId: 'evt_1', reason: 'Body is not an object');

        Assert::instanceOf($typed, WebhookProcessingResult::class);
        Assert::same($typed->providerEventId, 'evt_1');
        Assert::same($typed->reason, 'Amount precision is not supported');
        Assert::same($typed->type, $type);
        Assert::null($untyped->type);
    }

    public function nonProcessedOutcomesCarryTheirOwnFields(): void
    {
        $type = self::type();
        $validationFailed = new WebhookValidationFailed(reason: 'Invalid signature');
        $unknown = new UnknownWebhookEvent(providerEventId: 'evt_1', providerEventType: 'new.event');
        $unsupported = new UnsupportedWebhookEvent(
            providerEventId: 'evt_1',
            type: $type,
            reason: 'Unsupported version',
        );
        $replayed = new ReplayedWebhookEvent(providerEventId: 'evt_1');

        Assert::same($validationFailed->reason, 'Invalid signature');
        Assert::same($unknown->providerEventType, 'new.event');
        Assert::same($unsupported->reason, 'Unsupported version');
        Assert::same($unsupported->type, $type);
        Assert::same($replayed->providerEventId, 'evt_1');
    }

    public function acceptsMaximumValuesAndAbsentRawType(): void
    {
        $eventId = str_repeat('e', 255);
        $eventType = str_repeat('t', 255);
        $reason = str_repeat('r', 1_024);
        $validation = new WebhookValidationFailed(reason: $reason);
        $unknown = new UnknownWebhookEvent(providerEventId: $eventId, providerEventType: $eventType);
        $unknownWithoutType = new UnknownWebhookEvent(providerEventId: $eventId);
        $unsupported = new UnsupportedWebhookEvent(
            providerEventId: $eventId,
            type: self::type(),
            reason: $reason,
        );
        $replayed = new ReplayedWebhookEvent(providerEventId: $eventId);

        Assert::same($validation->reason, $reason);
        Assert::same($unknown->providerEventId, $eventId);
        Assert::same($unknown->providerEventType, $eventType);
        Assert::null($unknownWithoutType->providerEventType);
        Assert::same($unsupported->providerEventId, $eventId);
        Assert::same($unsupported->reason, $reason);
        Assert::same($replayed->providerEventId, $eventId);
    }

    #[DataProvider('invalidOutcomeProvider')]
    public function rejectsInvalidOutcomeValues(\Closure $factory, string $message): void
    {
        Expect::exception(\InvalidArgumentException::class)->withMessage($message);
        $factory();
    }

    /**
     * @return iterable<string, array{\Closure(): WebhookProcessingResult, string}>
     */
    public static function invalidOutcomeProvider(): iterable
    {
        $eventIdMessage = 'Provider event id must be non-empty and at most 255 bytes';
        $typeMessage = 'Provider event type must be non-empty and at most 255 bytes';
        $reasonMessage = 'Unsupported webhook event reason must be non-empty and at most 1024 bytes';

        yield 'blank validation reason' => [
            static fn(): WebhookProcessingResult => new WebhookValidationFailed(reason: '   '),
            'Webhook validation failure reason must be non-empty and at most 1024 bytes',
        ];
        yield 'oversized validation reason' => [
            static fn(): WebhookProcessingResult => new WebhookValidationFailed(reason: str_repeat('r', 1_025)),
            'Webhook validation failure reason must be non-empty and at most 1024 bytes',
        ];
        yield 'empty unknown event id' => [
            static fn(): WebhookProcessingResult => new UnknownWebhookEvent(providerEventId: ''),
            $eventIdMessage,
        ];
        yield 'oversized unknown event id' => [
            static fn(): WebhookProcessingResult => new UnknownWebhookEvent(providerEventId: str_repeat('e', 256)),
            $eventIdMessage,
        ];
        yield 'blank unknown event type' => [
            static fn(): WebhookProcessingResult => new UnknownWebhookEvent(
                providerEventId: 'evt_1',
                providerEventType: '   ',
            ),
            $typeMessage,
        ];
        yield 'oversized unknown event type' => [
            static fn(): WebhookProcessingResult => new UnknownWebhookEvent(
                providerEventId: 'evt_1',
                providerEventType: str_repeat('t', 256),
            ),
            $typeMessage,
        ];
        yield 'empty unsupported event id' => [
            static fn(): WebhookProcessingResult => new UnsupportedWebhookEvent(
                providerEventId: '',
                type: self::type(),
                reason: 'Unsupported',
            ),
            $eventIdMessage,
        ];
        yield 'blank unsupported reason' => [
            static fn(): WebhookProcessingResult => new UnsupportedWebhookEvent(
                providerEventId: 'evt_1',
                type: self::type(),
                reason: '   ',
            ),
            $reasonMessage,
        ];
        yield 'oversized unsupported reason' => [
            static fn(): WebhookProcessingResult => new UnsupportedWebhookEvent(
                providerEventId: 'evt_1',
                type: self::type(),
                reason: str_repeat('r', 1_025),
            ),
            $reasonMessage,
        ];
        yield 'empty rejected event id' => [
            static fn(): WebhookProcessingResult => new RejectedWebhookEvent(providerEventId: '', reason: 'why'),
            $eventIdMessage,
        ];
        yield 'oversized rejected event id' => [
            static fn(): WebhookProcessingResult => new RejectedWebhookEvent(
                providerEventId: str_repeat('e', 256),
                reason: 'why',
            ),
            $eventIdMessage,
        ];
        yield 'blank rejected reason' => [
            static fn(): WebhookProcessingResult => new RejectedWebhookEvent(providerEventId: 'evt_1', reason: '  '),
            'Rejected webhook event reason must be non-empty and at most 1024 bytes',
        ];
        yield 'oversized rejected reason' => [
            static fn(): WebhookProcessingResult => new RejectedWebhookEvent(
                providerEventId: 'evt_1',
                reason: str_repeat('r', 1_025),
            ),
            'Rejected webhook event reason must be non-empty and at most 1024 bytes',
        ];
        yield 'empty replay event id' => [
            static fn(): WebhookProcessingResult => new ReplayedWebhookEvent(providerEventId: ''),
            $eventIdMessage,
        ];
        yield 'oversized replay event id' => [
            static fn(): WebhookProcessingResult => new ReplayedWebhookEvent(providerEventId: str_repeat('e', 256)),
            $eventIdMessage,
        ];
    }

    private static function type(): ProviderEventType
    {
        return new ProviderEventType(
            provider: new PaymentProvider(value: 'fixture'),
            name: 'payment.succeeded',
        );
    }

    private static function event(): ObservedPaymentEvent
    {
        return new ObservedPaymentEvent(
            providerEventId: 'evt_1',
            type: self::type(),
            payment: new PaymentReference(provider: self::type()->provider, id: 'pay_1', kind: 'payment'),
            state: PaymentState::Succeeded,
            rawStatus: 'succeeded',
            occurredAt: new \DateTimeImmutable('2026-08-03T12:00:00+00:00'),
            payload: ['amount' => 1_200],
        );
    }
}
