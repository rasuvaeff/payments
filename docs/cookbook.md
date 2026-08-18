# Cookbook

Practical recipes for the `rasuvaeff/payments` stack. All examples assume the
packages from [README.md](../README.md); runnable scripts live in
[examples/](../examples/) and in the adapter packages.

## Table of contents

1. [Wire a Stripe gateway](#wire-a-stripe-gateway)
2. [Wire a PayPal gateway](#wire-a-paypal-gateway)
3. [Route between several gateways](#route-between-several-gateways)
4. [Refund (full and partial)](#refund-full-and-partial)
5. [Receive webhooks with the PSR-7 controller](#receive-webhooks-with-the-psr-7-controller)
6. [Implement the event store](#implement-the-event-store)
7. [Choose an acknowledgement policy](#choose-an-acknowledgement-policy)
8. [OperationId vs idempotency key](#operationid-vs-idempotency-key)
9. [Test an application against fakes](#test-an-application-against-fakes)
10. [Retry safely](#retry-safely)

## Wire a Stripe gateway

```php
use Rasuvaeff\PaymentsStripe\StripeCredentials;
use Rasuvaeff\PaymentsStripe\StripeGatewayFactory;

$gateway = StripeGatewayFactory::create(
    credentials: new StripeCredentials(secretKey: \getenv('STRIPE_SECRET_KEY')),
    client: $httpClient,                                         // any PSR-18 client
    requestFactory: $psr17Factory,                               // request+stream factory
    clock: $psr20Clock,                                          // any PSR-20 clock
);

$attempt = $gateway->createPayment(new \Rasuvaeff\Payments\CreatePaymentRequest(
    operationId: new \Rasuvaeff\Payments\OperationId(value: 'order-42-charge'),
    amount: new \Rasuvaeff\Payments\Money(minorUnits: 2599, currency: 'USD'),
    paymentMethod: new \Rasuvaeff\Payments\PaymentMethodReference(id: 'pm_123'),
    idempotencyKey: 'order-42-charge-attempt-1',
));
```

The attempt carries a normalized `PaymentState`, the provider `rawStatus` and
sanitized `ProviderRequestInfo`. Never branch on `rawStatus` — map from
`state` and keep `rawStatus` for diagnostics.

When the intent still needs the customer, watch for the client-side
confirmation action and hand its secret to the frontend:

```php
use Rasuvaeff\PaymentsStripe\ConfirmOnClient;

if ($attempt->nextAction instanceof ConfirmOnClient) {
    $response->json(['client_secret' => $attempt->nextAction->clientSecret]);
}
```

## Wire a PayPal gateway

```php
use Rasuvaeff\PaymentsPayPal\PayPalCredentials;
use Rasuvaeff\PaymentsPayPal\PayPalGatewayFactory;
use Rasuvaeff\PaymentsPayPal\PayPalGatewayConfig;

$gateway = PayPalGatewayFactory::withCachedOAuth(
    credentials: new PayPalCredentials(clientId: \getenv('PAYPAL_CLIENT_ID'), clientSecret: \getenv('PAYPAL_CLIENT_SECRET')),
    client: $httpClient,
    requestFactory: $psr17Factory,
    clock: $psr20Clock,
    config: new PayPalGatewayConfig(apiBaseUri: 'https://api-m.sandbox.paypal.com', sandbox: true),
);
```

`withCachedOAuth` exchanges credentials once per token lifetime
(`PayPalCachedTokenProvider` refreshes a safety margin before the expiry
PayPal reported). PayPal orders are created
with `CAPTURE` intent: a fresh order maps to `PaymentState::Pending`; once the
buyer approves it (`status: APPROVED`) the state becomes `RequiresCapture`,
and `capturePayment()` completes it.

## Route between several gateways

```php
use Rasuvaeff\Payments\GatewayRegistry;
use Rasuvaeff\Payments\FixedGatewaySelectionPolicy;
use Rasuvaeff\Payments\PaymentGatewayRouter;

$registry = new GatewayRegistry($stripe, $paypal);
$router = new PaymentGatewayRouter(
    registry: $registry,
    selectionPolicy: new FixedGatewaySelectionPolicy(provider: $stripe->provider()),
);

$attempt = $router->createPayment($request);          // policy picks the provider
$attempt = $router->capturePayment($captureRequest);  // routed by the reference's provider
```

Creation is the only operation the policy chooses; every later operation routes
by the provider embedded in the `PaymentReference`. The registry never picks a
gateway implicitly — write your own `GatewaySelectionPolicyInterface` for
method/currency/tenant-aware routing.

## Refund (full and partial)

```php
use Rasuvaeff\Payments\CreateRefundRequest;
use Rasuvaeff\Payments\Money;
use Rasuvaeff\Payments\OperationId;
use Rasuvaeff\Payments\RefundReason;

// Full refund of a capture
$refund = $refundGateway->createRefund(new CreateRefundRequest(
    operationId: new OperationId(value: 'order-42-refund'),
    payment: $captureAttempt->payment,      // the capture reference
));

// Partial refund with a reason
$refund = $refundGateway->createRefund(new CreateRefundRequest(
    operationId: new OperationId(value: 'order-42-refund-partial-1'),
    payment: $captureAttempt->payment,
    amount: new Money(minorUnits: 1000, currency: 'USD'),
    reason: new RefundReason(value: 'damaged item'),
    idempotencyKey: 'order-42-refund-partial-1',
));
```

Two equal partial refunds need two distinct operation ids and idempotency keys;
identity is never derived from payment + amount + reason.

## Receive webhooks with the PSR-7 controller

Build the processors with the adapter factories — the durable boundary (event
store and acceptance) is the part you own:

```php
use Rasuvaeff\Payments\QueuedWebhookEventAcceptance;
use Rasuvaeff\Payments\WebhookController;
use Rasuvaeff\Payments\WebhookProcessorRegistry;
use Rasuvaeff\Payments\WebhookProcessorRegistration;
use Rasuvaeff\PaymentsStripe\StripeWebhookProcessorFactory;
use Rasuvaeff\PaymentsStripe\StripeWebhookSecret;
use Rasuvaeff\PaymentsPayPal\PayPalWebhookProcessorFactory;

$stripeProcessor = StripeWebhookProcessorFactory::create(
    secret: new StripeWebhookSecret(value: \getenv('STRIPE_WEBHOOK_SECRET')),
    clock: $clock,
    eventStore: $eventStore,
    eventAcceptance: new QueuedWebhookEventAcceptance(queue: $queue),
);
$paypalProcessor = PayPalWebhookProcessorFactory::create(
    webhookId: \getenv('PAYPAL_WEBHOOK_ID'),
    accessTokenProvider: $tokenCache,
    client: $httpClient,
    requestFactory: $psr17Factory,
    clock: $clock,
    eventStore: $eventStore,
    eventAcceptance: new QueuedWebhookEventAcceptance(queue: $queue),
);

$processors = new WebhookProcessorRegistry(
    new WebhookProcessorRegistration(processor: $stripeProcessor, provider: $stripeProvider),
    new WebhookProcessorRegistration(processor: $paypalProcessor, provider: $paypalProvider),
);

$controller = new WebhookController(
    registry: $processors,
    responseFactory: $psr17Factory,
);
```

`WebhookController::handle($request, 'stripe')` takes the provider key from
your route — mount one endpoint per provider (`/webhooks/stripe`,
`/webhooks/paypal`) and pass the segment to `handle()`; an unknown or
malformed key answers 404 without touching a processor:

```php
$response = $controller->handle($serverRequest, 'stripe');
```

If you consume the open `WebhookProcessorInterface` (a decorating processor
may return a foreign outcome), close the `match` with a `default` arm — an
unhandled outcome would otherwise escalate into an `UnhandledMatchError`
and a 500 from the controller:

```php
$outcome = match (true) {
    $result instanceof \Rasuvaeff\Payments\ProcessedWebhook => 'processed',
    $result instanceof \Rasuvaeff\Payments\WebhookValidationFailed => 'validation_failed',
    $result instanceof \Rasuvaeff\Payments\ReplayedWebhookEvent => 'replayed',
    $result instanceof \Rasuvaeff\Payments\UnknownWebhookEvent => 'ignored_unknown',
    $result instanceof \Rasuvaeff\Payments\UnsupportedWebhookEvent => 'ignored_unsupported',
    $result instanceof \Rasuvaeff\Payments\RejectedWebhookEvent => 'rejected_payload',
    default => 'processing_failed', // foreign outcome from a decorating processor
};
```

Mount the webhook route **before** any body-parsing middleware. The signature
covers the exact received bytes, and a stream a middleware already consumed
reads back as an empty string — every check then fails with a correct secret
and a correct signature. The controller reports that as `empty_body` rather
than `validation_failed` so it is diagnosable, but the fix is the mount order.

Phase 1 (synchronous): verify signature → atomically `claim()` the provider
event id → recognize and map the event → durably enqueue → `complete()` the
claim. Phase 2 (reconciliation): re-fetch the authoritative provider state,
compare the amount with the order, persist the attempt projection, then emit
application events. The webhook payload is only an observation; authoritative
state always comes from the re-fetch.

```php
$attempt = $gateway->retrievePayment(new RetrievePaymentRequest(
    operationId: new OperationId('reconcile-' . $event->providerEventId),
    payment: $event->payment,
));

// Compare amount AND currency. `minorUnits === $order->total` alone accepts
// 1000 JPY for a 1000 USD order.
if ($attempt->state === PaymentState::Succeeded && $attempt->amount->equals($order->total)) {
    $order->markPaid();
}
```

## Implement the event store

The store owns replay protection, and its contract has three calls:

```php
final class DbWebhookEventStore implements WebhookEventStoreInterface
{
    private const int LEASE_SECONDS = 300;

    public function claim(PaymentProvider $provider, string $providerEventId): bool
    {
        // INSERT ... ON CONFLICT DO UPDATE ... WHERE completed_at IS NULL
        //   AND claimed_at < now() - lease, RETURNING id
        // -> true when the row was inserted or a stale claim was taken over.
    }

    public function complete(PaymentProvider $provider, string $providerEventId): void
    {
        // UPDATE ... SET completed_at = now()
    }

    public function release(PaymentProvider $provider, string $providerEventId): void
    {
        // DELETE ... WHERE completed_at IS NULL
    }
}
```

`complete()` is not bookkeeping. A process can die between `claim()` and the
end of processing without ever reaching `release()` — SIGKILL, the OOM killer,
`max_execution_time`, a fatal error, a pod restart. Without a completion signal
the store cannot tell an in-flight claim from a finished one, and both choices
are wrong: expire claims and an already-processed event runs again on the next
provider retry; never expire them and the interrupted event is answered with a
replay outcome — HTTP 204 — so the provider stops retrying and the event is
lost with no error anywhere.

If the queue lives in the same database, there is a simpler option: commit the
claim row and the queue row in **one transaction**, so "a claim exists" already
implies "the event is durable". A broker (SQS, Redis) cannot join that
transaction, so those deployments need the lease plus `complete()`.

## Choose an acknowledgement policy

| Policy | Acknowledge after | Use when |
|---|---|---|
| `AfterValidation` | signature valid + event claimed + durably queued | queue-backed processing, provider retries are cheap |
| `AfterPersistence` | phase 2 persisted the authoritative state | synchronous processing, strict at-least-once |

Never acknowledge after validation alone: if the claimed event was not durably
recorded, the provider will never retry and the event is lost.

A `RejectedWebhookEvent` is acknowledged on purpose. The signature was valid,
so the event is genuine, but its payload cannot be mapped — an amount with
unexpected precision, an id in an unknown format. That verdict belongs to the
payload, so redelivery produces it again forever; retrying earns nothing and
providers disable an endpoint that keeps failing, which loses the *following*,
healthy events. Alert on rejections: they mean the adapter and the provider
disagree about the format.

## OperationId vs idempotency key

| Concept | Scope | Lifetime |
|---|---|---|
| `OperationId` | one logical business command | stable across retries, providers and failover attempts |
| `idempotencyKey` | one provider-side call attempt | stable across transport retries of that exact call; new value when policy starts a new attempt |

The adapter forwards both; persistence and replay enforcement live in your
application (or the future Yii3 bridge).

## Test an application against fakes

```php
use Rasuvaeff\Payments\PaymentState;
use Rasuvaeff\PaymentsTesting\FakeGatewayConfig;
use Rasuvaeff\PaymentsTesting\FakePaymentGateway;
use Rasuvaeff\PaymentsTesting\PaymentGatewayAssertions;

// Deterministic outcome is chosen by FakeGatewayConfig::createState
$succeeding = new FakePaymentGateway();   // default config: PaymentState::Succeeded
$failing = new FakePaymentGateway(new FakeGatewayConfig(createState: PaymentState::Failed));

$attempt = $succeeding->createPayment($request);

// Contract assertions verify the gateway contract itself (idempotency replay,
// reference provider matching, capability/interface consistency):
PaymentGatewayAssertions::assertCreatePayment(gateway: $succeeding, request: $request);
PaymentGatewayAssertions::assertCreatePaymentIdempotency(gateway: $succeeding, request: $request);
```

The fakes implement the same contracts as real adapters, so router and webhook
code can be exercised without network access. Optional interfaces
(capture/refund) are represented by separate optional fakes that honestly
report the missing capability.

## Retry safely

Core ships no retry policy on purpose. Decorate the transport (or the gateway)
with `rasuvaeff/retry` and retry only explicitly idempotent operations:

- retrievals (`retrievePayment`, `retrieveRefund`);
- creates carrying a stable idempotency key;
- never blind `POST`s without a key.

Map transport failures to `TransportException`/`ServerException` and let the
caller's policy decide; do not hide a decline inside a retry loop.
