<?php

declare(strict_types=1);

use Rasuvaeff\Payments\InvalidWebhook;
use Rasuvaeff\Payments\ObservedPaymentEvent;
use Rasuvaeff\Payments\PaymentProvider;
use Rasuvaeff\Payments\PaymentReference;
use Rasuvaeff\Payments\PaymentState;
use Rasuvaeff\Payments\ProcessedWebhook;
use Rasuvaeff\Payments\ProviderEventType;
use Rasuvaeff\Payments\QueuedWebhookEventAcceptance;
use Rasuvaeff\Payments\RejectedWebhookEvent;
use Rasuvaeff\Payments\ReplayedWebhookEvent;
use Rasuvaeff\Payments\UnknownWebhookEvent;
use Rasuvaeff\Payments\UnsupportedWebhookEvent;
use Rasuvaeff\Payments\ValidWebhook;
use Rasuvaeff\Payments\WebhookEventQueueInterface;
use Rasuvaeff\Payments\WebhookEventRecognizerInterface;
use Rasuvaeff\Payments\WebhookEventStoreInterface;
use Rasuvaeff\Payments\WebhookEventTypeExtractorInterface;
use Rasuvaeff\Payments\WebhookInput;
use Rasuvaeff\Payments\WebhookPayloadMapperInterface;
use Rasuvaeff\Payments\WebhookProcessor;
use Rasuvaeff\Payments\WebhookValidationFailed;
use Rasuvaeff\Payments\WebhookValidatorInterface;

require dirname(__DIR__) . '/vendor/autoload.php';

$provider = new PaymentProvider(value: 'example');
$validator = new readonly class ($provider) implements WebhookValidatorInterface {
    public function __construct(private PaymentProvider $provider) {}

    #[\Override]
    public function provider(): PaymentProvider
    {
        return $this->provider;
    }

    #[\Override]
    public function validate(WebhookInput $input): ValidWebhook|InvalidWebhook
    {
        $signature = $input->header('x-signature')[0] ?? '';

        return hash_equals('test-signature', $signature)
            ? new ValidWebhook(providerEventId: 'evt_1')
            : new InvalidWebhook(reason: 'Invalid signature');
    }
};
$extractor = new readonly class implements WebhookEventTypeExtractorInterface {
    #[\Override]
    public function extract(WebhookInput $input): ?string
    {
        return 'payment.succeeded';
    }
};
$recognizer = new readonly class ($provider) implements WebhookEventRecognizerInterface {
    public function __construct(private PaymentProvider $provider) {}

    #[\Override]
    public function recognize(string $providerEventType): ?ProviderEventType
    {
        return $providerEventType === 'payment.succeeded'
            ? new ProviderEventType(provider: $this->provider, name: $providerEventType)
            : null;
    }
};
$mapper = new readonly class ($provider) implements WebhookPayloadMapperInterface {
    public function __construct(private PaymentProvider $provider) {}

    #[\Override]
    public function map(WebhookInput $input, ProviderEventType $type): ObservedPaymentEvent
    {
        return new ObservedPaymentEvent(
            providerEventId: 'evt_1',
            type: $type,
            payment: new PaymentReference(provider: $this->provider, id: 'pay_1', kind: 'payment'),
            state: PaymentState::Succeeded,
            rawStatus: 'succeeded',
            occurredAt: new DateTimeImmutable('2026-08-03T12:00:00+00:00'),
            payload: ['object_id' => 'pay_1'],
        );
    }
};
// A store must distinguish three things: never seen, in flight, and finished.
// A claim that was never completed and whose lease expired belongs to a process
// that died without releasing it — that one is retried. A completed claim is
// never handed out again, however old it is. In a real application this is one
// row with a unique key on (provider, provider_event_id).
$eventStore = new class implements WebhookEventStoreInterface {
    private const int LEASE_SECONDS = 300;

    /** @var array<string, array{completed: bool, claimedAt: int}> */
    private array $claims = [];

    #[\Override]
    public function claim(PaymentProvider $provider, string $providerEventId): bool
    {
        $key = $provider->value . ':' . $providerEventId;
        $claim = $this->claims[$key] ?? null;

        if ($claim !== null && ($claim['completed'] || $claim['claimedAt'] + self::LEASE_SECONDS > time())) {
            return false;
        }

        $this->claims[$key] = ['completed' => false, 'claimedAt' => time()];

        return true;
    }

    #[\Override]
    public function complete(PaymentProvider $provider, string $providerEventId): void
    {
        $this->claims[$provider->value . ':' . $providerEventId] = ['completed' => true, 'claimedAt' => time()];
    }

    #[\Override]
    public function release(PaymentProvider $provider, string $providerEventId): void
    {
        unset($this->claims[$provider->value . ':' . $providerEventId]);
    }
};
$queue = new class implements WebhookEventQueueInterface {
    #[\Override]
    public function enqueue(ObservedPaymentEvent $event): void
    {
        echo 'Queued ' . $event->providerEventId . PHP_EOL;
    }
};
$processor = new WebhookProcessor(
    validator: $validator,
    eventTypeExtractor: $extractor,
    eventRecognizer: $recognizer,
    payloadMapper: $mapper,
    eventStore: $eventStore,
    eventAcceptance: new QueuedWebhookEventAcceptance(queue: $queue),
);
$result = $processor->process(new WebhookInput(
    rawBody: '{"id":"evt_1","type":"payment.succeeded"}',
    provider: $provider,
    headers: ['X-Signature' => 'test-signature'],
    requestMetadata: ['request_id' => 'request-1'],
));

echo 'Result: ' . match (true) {
    $result instanceof ProcessedWebhook => 'processed ' . $result->event->providerEventId
        . ' (' . $result->acknowledgementPolicy->value . ')',
    $result instanceof WebhookValidationFailed => 'validation failed: ' . $result->reason,
    $result instanceof UnknownWebhookEvent => 'unknown event ' . $result->providerEventId,
    $result instanceof UnsupportedWebhookEvent => 'unsupported event: ' . $result->reason,
    $result instanceof RejectedWebhookEvent => 'rejected payload: ' . $result->reason,
    $result instanceof ReplayedWebhookEvent => 'replayed ' . $result->providerEventId,
} . PHP_EOL;
