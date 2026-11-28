<?php

declare(strict_types=1);

namespace Rasuvaeff\Payments\Tests\Support;

use Rasuvaeff\Payments\InvalidWebhook;
use Rasuvaeff\Payments\MalformedResponseException;
use Rasuvaeff\Payments\ObservedPaymentEvent;
use Rasuvaeff\Payments\PaymentProvider;
use Rasuvaeff\Payments\PaymentReference;
use Rasuvaeff\Payments\PaymentState;
use Rasuvaeff\Payments\ProviderEventType;
use Rasuvaeff\Payments\UnsupportedWebhookEventException;
use Rasuvaeff\Payments\ValidWebhook;
use Rasuvaeff\Payments\WebhookEventQueueInterface;
use Rasuvaeff\Payments\WebhookEventRecognizerInterface;
use Rasuvaeff\Payments\WebhookEventTypeExtractorInterface;
use Rasuvaeff\Payments\WebhookInput;
use Rasuvaeff\Payments\WebhookPayloadMapperInterface;
use Rasuvaeff\Payments\WebhookValidatorInterface;

/**
 * A provider adapter whose every answer is scripted by the body,
 * `<event id>|<mode>`: `ok`, `invalid` (bad signature), `unknown` (no event
 * type), `unsupported`, `malformed`, `transient` (the mapper fails) or
 * `queue down` (the queue fails). The queue records what it accepted.
 */
final class ScriptedWebhookAdapter implements WebhookValidatorInterface, WebhookEventTypeExtractorInterface, WebhookEventRecognizerInterface, WebhookPayloadMapperInterface, WebhookEventQueueInterface
{
    public const array MODES = ['ok', 'invalid', 'unknown', 'unsupported', 'malformed', 'transient', 'queue down'];

    /** @var list<non-empty-string> */
    public array $queued = [];

    private ?string $mode = null;

    public static function scriptedProvider(): PaymentProvider
    {
        return new PaymentProvider(value: 'scripted');
    }

    #[\Override]
    public function provider(): PaymentProvider
    {
        return self::scriptedProvider();
    }

    #[\Override]
    public function validate(WebhookInput $input): ValidWebhook|InvalidWebhook
    {
        [$id, $this->mode] = explode('|', $input->rawBody, 2);

        return $this->mode === 'invalid' ? new InvalidWebhook(reason: 'Bad signature') : new ValidWebhook(providerEventId: $id);
    }

    #[\Override]
    public function extract(WebhookInput $input): ?string
    {
        return $this->mode === 'unknown' ? null : 'payment.succeeded';
    }

    #[\Override]
    public function recognize(string $providerEventType): \Rasuvaeff\Payments\ProviderEventType
    {
        return new ProviderEventType(provider: self::scriptedProvider(), name: $providerEventType);
    }

    #[\Override]
    public function map(WebhookInput $input, ProviderEventType $type): ObservedPaymentEvent
    {
        [$id] = explode('|', $input->rawBody, 2);

        return match ($this->mode) {
            'unsupported' => throw new UnsupportedWebhookEventException('Not handled'),
            'malformed' => throw new MalformedResponseException('Payload cannot be mapped'),
            'transient' => throw new \RuntimeException('Mapper unavailable'),
            default => new ObservedPaymentEvent(
                providerEventId: $id,
                type: $type,
                payment: new PaymentReference(provider: self::scriptedProvider(), id: 'pay_' . $id),
                state: PaymentState::Succeeded,
                rawStatus: 'succeeded',
                occurredAt: new \DateTimeImmutable('2026-01-01T00:00:00+00:00'),
            ),
        };
    }

    #[\Override]
    public function enqueue(ObservedPaymentEvent $event): void
    {
        if ($this->mode === 'queue down') {
            throw new \RuntimeException('Queue unavailable');
        }

        $this->queued[] = $event->providerEventId;
    }
}
