<?php

declare(strict_types=1);

namespace Rasuvaeff\Payments\Tests\Support;

use Rasuvaeff\Payments\ProcessedWebhook;
use Rasuvaeff\Payments\QueuedWebhookEventAcceptance;
use Rasuvaeff\Payments\RejectedWebhookEvent;
use Rasuvaeff\Payments\ReplayedWebhookEvent;
use Rasuvaeff\Payments\UnknownWebhookEvent;
use Rasuvaeff\Payments\UnsupportedWebhookEvent;
use Rasuvaeff\Payments\WebhookClaimToken;
use Rasuvaeff\Payments\WebhookInput;
use Rasuvaeff\Payments\WebhookProcessor;
use Rasuvaeff\Payments\WebhookValidationFailed;
use Rasuvaeff\PropertyTesting\ArbitraryInterface;
use Rasuvaeff\PropertyTesting\Gen;
use Rasuvaeff\PropertyTesting\StateMachine\Invariant;
use Rasuvaeff\PropertyTesting\StateMachine\Precondition;
use Rasuvaeff\PropertyTesting\StateMachine\Rule;
use Testo\Assert;

/**
 * Model of the claim lifecycle `WebhookProcessor` drives: deliveries of a
 * few event ids, each answered by the adapter in one of the scripted modes,
 * interleaved with another worker holding and dropping a claim.
 *
 * The model is the delivery guarantee, not the processor's code: an event
 * reaches the queue at most once; a terminal verdict (processed, unknown,
 * unsupported, rejected — whatever the length of the mapper's message)
 * completes the claim and every later delivery is a replay; a failure
 * releases it so the next delivery runs again; a bad signature never touches
 * the store; a delivery while another worker holds the claim is a replay and
 * finalises nothing.
 *
 * A lease that runs out while the processor is mapping (`lease lost`) lets
 * another worker claim the event. The processor's token is then stale: its
 * `complete()` must leave the new owner's claim exactly as it is, neither
 * completing nor deleting it. The event was already queued by then, so it
 * may be queued once more per lost lease — the at-least-once fallback a
 * lease shorter than processing buys.
 */
final class WebhookLifecycleMachine
{
    /** @var array<non-empty-string, true> */
    public array $seen = [];

    private readonly ScriptedWebhookAdapter $adapter;
    private readonly InMemoryWebhookEventStore $store;
    private readonly WebhookProcessor $processor;

    /** @var array<string, true> */
    private array $completed = [];

    /** @var array<string, WebhookClaimToken> */
    private array $held = [];

    /** @var array<string, int> */
    private array $leasesLost = [];

    public function __construct()
    {
        $this->adapter = new ScriptedWebhookAdapter();
        $this->store = new InMemoryWebhookEventStore();
        $this->processor = new WebhookProcessor(
            validator: $this->adapter,
            eventTypeExtractor: $this->adapter,
            eventRecognizer: $this->adapter,
            payloadMapper: $this->adapter,
            eventStore: $this->store,
            eventAcceptance: new QueuedWebhookEventAcceptance(queue: $this->adapter),
        );
        // Another worker claims the event the moment the lease runs out.
        $this->adapter->whileMapping = function (string $id): void {
            $this->store->expire($id);
            $token = $this->store->claim(ScriptedWebhookAdapter::scriptedProvider(), $id);
            Assert::instanceOf($token, WebhookClaimToken::class);
            $this->held[$id] = $token;
            $this->leasesLost[$id] = ($this->leasesLost[$id] ?? 0) + 1;
        };
    }

    /** @return array<string, ArbitraryInterface> */
    public static function deliverGenerators(): array
    {
        return [
            'id' => self::id(),
            'mode' => Gen::elements(ScriptedWebhookAdapter::MODES),
        ];
    }

    /** @return array<string, ArbitraryInterface> */
    public static function holdClaimGenerators(): array
    {
        return ['id' => self::id()];
    }

    /**
     * @param value-of<ScriptedWebhookAdapter::MODES> $mode
     */
    #[Rule]
    public function deliver(string $id, string $mode): void
    {
        $queuedBefore = $this->adapter->queued;
        $deliver = fn(): object => $this->processor->process(new WebhookInput(
            rawBody: $id . '|' . $mode,
            provider: ScriptedWebhookAdapter::scriptedProvider(),
        ));

        if ($mode === 'invalid') {
            Assert::instanceOf($deliver(), WebhookValidationFailed::class);
            $this->seen['invalid'] = true;

            return;
        }

        if (isset($this->completed[$id]) || isset($this->held[$id])) {
            Assert::instanceOf($deliver(), ReplayedWebhookEvent::class);
            $this->seen[isset($this->held[$id]) ? 'held elsewhere' : 'replayed'] = true;
            Assert::same($this->adapter->queued, $queuedBefore);

            return;
        }

        if (\in_array($mode, ['transient', 'queue down'], strict: true)) {
            try {
                $deliver();
                Assert::fail('A failing ' . $mode . ' delivery must propagate');
            } catch (\RuntimeException $failure) {
                Assert::same($failure->getMessage(), $mode === 'transient' ? 'Mapper unavailable' : 'Queue unavailable');
            }

            Assert::same($this->adapter->queued, $queuedBefore);
            $this->seen['released'] = true;

            return;
        }

        $result = $deliver();
        $this->seen[$mode] = true;

        if ($mode === 'lease lost') {
            // The stale token finalised nothing: the event stays claimed by
            // the worker that took it over.
            Assert::instanceOf($result, ProcessedWebhook::class);
            Assert::same($this->adapter->queued, [...$queuedBefore, $id]);

            return;
        }

        $this->completed[$id] = true;

        if ($result instanceof RejectedWebhookEvent) {
            Assert::same($result->reason, match ($mode) {
                'long reason' => str_repeat('x', 1_024),
                'blank reason' => 'Webhook payload cannot be mapped',
                default => 'Payload cannot be mapped',
            });
        }

        Assert::instanceOf($result, match ($mode) {
            'ok' => ProcessedWebhook::class,
            'unknown' => UnknownWebhookEvent::class,
            'unsupported' => UnsupportedWebhookEvent::class,
            default => RejectedWebhookEvent::class,
        });
        Assert::same($this->adapter->queued, $mode === 'ok' ? [...$queuedBefore, $id] : $queuedBefore);
    }

    /**
     * Another worker claims the event and is still processing it.
     */
    #[Rule]
    public function holdClaim(string $id): void
    {
        $token = $this->store->claim(ScriptedWebhookAdapter::scriptedProvider(), $id);

        if (isset($this->completed[$id]) || isset($this->held[$id])) {
            Assert::null($token);

            return;
        }

        Assert::instanceOf($token, WebhookClaimToken::class);
        $this->held[$id] = $token;
    }

    /**
     * The other worker fails and releases its claim.
     */
    #[Rule]
    #[Precondition('holdsAClaim')]
    public function dropHeldClaim(): void
    {
        $id = array_key_first($this->held);
        \assert($id !== null);

        $this->store->release(ScriptedWebhookAdapter::scriptedProvider(), $id, $this->held[$id]);
        unset($this->held[$id]);
    }

    public function holdsAClaim(): bool
    {
        return $this->held !== [];
    }

    #[Invariant]
    public function eachEventIsQueuedAtMostOncePerLease(): void
    {
        foreach (array_count_values($this->adapter->queued) as $id => $times) {
            Assert::true($times <= 1 + ($this->leasesLost[$id] ?? 0));
        }
    }

    /**
     * Compared token by token: a stale finaliser that deleted the live claim
     * and a worker that claimed it again would leave the same keys behind.
     */
    #[Invariant]
    public function theStoreMatchesTheModel(): void
    {
        Assert::same(array_keys($this->store->completed), array_keys($this->completed));
        Assert::same(array_keys($this->store->claims), array_keys($this->held));

        foreach ($this->held as $id => $token) {
            Assert::true($this->store->claims[$id]->equals($token));
        }
    }

    /** @return ArbitraryInterface<non-empty-string> */
    private static function id(): ArbitraryInterface
    {
        return Gen::elements(['evt_1', 'evt_2', 'evt_3']);
    }
}
