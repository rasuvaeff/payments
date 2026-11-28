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
 * type), `unsupported`, `malformed`, `long reason` and `blank reason` (malformed
 * with a 2000-byte or a blank message), `transient` (the mapper fails),
 * `queue down` (the queue fails) or `lease lost` (mapped like `ok`, but the
 * claim's lease runs out while mapping: `$whileMapping` runs first). The
 * queue records what it accepted.
 */
final class ScriptedWebhookAdapter implements WebhookValidatorInterface, WebhookEventTypeExtractorInterface, WebhookEventRecognizerInterface, WebhookPayloadMapperInterface, WebhookEventQueueInterface
{
    public const array MODES = ['ok', 'invalid', 'unknown', 'unsupported', 'malformed', 'long reason', 'blank reason', 'transient', 'queue down', 'lease lost'];

    /** @var list<non-empty-string> */
    public array $queued = [];

    /** @var (\Closure(string): void)|null */
    public ?\Closure $whileMapping = null;

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

        if ($this->mode === 'lease lost' && $this->whileMapping !== null) {
            ($this->whileMapping)($id);
        }

        return match ($this->mode) {
            'unsupported' => throw new UnsupportedWebhookEventException('Not handled'),
            'malformed' => throw new MalformedResponseException('Payload cannot be mapped'),
            'long reason' => throw new MalformedResponseException(str_repeat('x', 2_000)),
            'blank reason' => throw new MalformedResponseException(' '),
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
