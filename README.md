# rasuvaeff/payments

[![Latest Stable Version](https://poser.pugx.org/rasuvaeff/payments/v)](https://packagist.org/packages/rasuvaeff/payments)
[![Total Downloads](https://poser.pugx.org/rasuvaeff/payments/downloads)](https://packagist.org/packages/rasuvaeff/payments)
[![Build](https://github.com/rasuvaeff/payments/actions/workflows/build.yml/badge.svg)](https://github.com/rasuvaeff/payments/actions/workflows/build.yml)
[![Static analysis](https://github.com/rasuvaeff/payments/actions/workflows/static-analysis.yml/badge.svg)](https://github.com/rasuvaeff/payments/actions/workflows/static-analysis.yml)
[![Psalm level](https://img.shields.io/badge/psalm-level_1-blue.svg)](https://github.com/rasuvaeff/payments/actions/workflows/static-analysis.yml)
[![License](https://img.shields.io/badge/license-BSD--3--Clause-blue.svg)](LICENSE.md)

Provider-neutral payment contracts, PSR-18 transport and webhook pipeline for
payment gateway adapters: value objects (`Money`, references, states), gateway
interfaces with typed capabilities, gateway registry/router, request builders,
response decoding with typed exceptions, and a validated, replay-protected
webhook ingress with a PSR-7 controller. The package deliberately does not
choose HTTP clients, retries, credentials storage, queues, persistence, or
provider-specific status semantics.

> Using an AI coding assistant? [llms.txt](llms.txt) contains a compact API reference.

## Requirements

PHP 8.3+. A PSR-18 client with PSR-17 factories for outbound provider calls;
PSR-7/PSR-17 messages for the webhook controller.

## Installation

```bash
composer require rasuvaeff/payments
```

## Usage

### Money and identity value objects

`Money` is non-negative integer minor units plus a three-letter uppercase ISO
currency — never floats:

```php
$price = Money::minorUnits(1_200, 'EUR');
$total = $price->add(Money::minorUnits(300, 'EUR'));   // 1500 EUR minor units
$rest  = $total->subtract($price);                     // throws below zero
$fee   = $total->multiply(3, 100);                     // rational factor, half-up rounding
$total->isZero();                                      // false
$total->equals($order->total);                         // amount AND currency
$total->isGreaterThan($price);                         // same currency required
```

Arithmetic requires matching currencies and throws `\OverflowException` instead
of wrapping. `multiply()` takes an integer numerator/denominator, so percentage
math never touches floating point.

Use `equals()` when reconciliation compares the authoritative provider amount
against the order. It compares the currency too: `$attempt->amount->minorUnits
=== $order->total` accepts 1000 JPY for a 1000 USD order, and that is the half
that gets forgotten when the comparison is hand-rolled.

| Value object | Meaning |
|---|---|
| `OperationId` | Application-issued id for one logical payment command (max 255 bytes) |
| `PaymentProvider` | Lowercase provider key, `/^[a-z][a-z0-9_-]{0,63}\z/` |
| `PaymentReference` / `RefundReference` | Provider + provider-side id (+ optional `kind`) |
| `CustomerReference` | Application customer id + optional provider customer id |
| `PaymentMethodReference` | Payment method id + optional `kind` |
| `RefundReason` | Non-empty reason string (max 128 bytes) |
| `ProviderEventType` | Provider + raw event name |
| `ProviderRequestInfo` | Allow-listed response diagnostics: `receivedAt`, `requestId`, `rateLimitRemaining`, `retryAfterSeconds` |
| `PaymentFailure` | Sanitized failure: `code`, `message`, `retryable`, scalar `details` |

### Intents, attempts and states

`PaymentIntent` is the application-owned business intent; `PaymentAttempt` is
one provider execution. An intent aggregates attempts and rejects an attempt
whose currency differs from the intent amount. `RefundAttempt` carries the
requested and actual refunded amounts.

```php
$attempt = new PaymentAttempt(
    operationId: new OperationId('op-1'),
    provider: new PaymentProvider('stripe'),
    payment: new PaymentReference(provider: new PaymentProvider('stripe'), id: 'pi_1'),
    amount: Money::minorUnits(1_200, 'EUR'),
    state: PaymentState::RequiresAction,
    rawStatus: 'requires_action',
    createdAt: $now,
    updatedAt: $now,
    requestInfo: new ProviderRequestInfo(receivedAt: $now),
    nextAction: new RedirectToUrl('https://pay.example.test/redirect'),
);
```

`PaymentState` (`Pending`, `RequiresPaymentMethod`, `RequiresAction`,
`RequiresCapture`, `Processing`, `Succeeded`, `Failed`, `Canceled`) offers
`canTransitionTo()` — an **advisory** transition map. It must not be used to
discard an accepted webhook observation; the provider is authoritative.

`Succeeded`, `Failed` and `Canceled` are terminal. A recoverable provider state
is therefore never folded into one of them: a declined card returns a Stripe
PaymentIntent to `requires_payment_method`, the customer pays it with another
card and the same intent reaches `succeeded`, so that status maps to
`RequiresPaymentMethod` — with the decline carried alongside in `failure` —
rather than to `Failed`. `RefundState` is `Pending`, `Succeeded`,
`Failed`, `Canceled`. `CaptureMethod` and `ConfirmationMethod` are
`Automatic`/`Manual`.

`NextAction` is an open interface (`type(): string`) with three shipped
implementations: `RedirectToUrl` (HTTPS-only URL), `UseSdk` (SDK name + scalar
payload) and `DisplayInstructions` (instruction type + text + scalar metadata).

`JsonRequestBuilder` also implements `RawJsonRequestBuilderInterface`, whose
`buildRawJson()` sends an already-encoded body byte for byte. Use it wherever a
document signed elsewhere has to be forwarded for verification: decoding and
re-encoding it does not round-trip in PHP — non-ASCII gets escaped, `{}` becomes
`[]`, `1.50` becomes `1.5` — so whatever the remote verifier then attests, it is
no longer the document that was received.

### Gateway contracts and capabilities

`PaymentGatewayInterface` is the mandatory core; optional operations live in
separate ISP interfaces so a capability can never claim an unsupported method:

| Interface | Methods |
|---|---|
| `PaymentGatewayInterface` | `provider()`, `capabilities()`, `createPayment(CreatePaymentRequest)`, `retrievePayment(RetrievePaymentRequest)` |
| `CaptureGatewayInterface` | `capturePayment(CapturePaymentRequest)` |
| `ConfirmGatewayInterface` | `confirmPayment(PaymentOperationRequest)` |
| `CancelGatewayInterface` | `cancelPayment(PaymentOperationRequest)` |
| `RefundGatewayInterface` | `createRefund(CreateRefundRequest)`, `retrieveRefund(RetrieveRefundRequest)` |

Every request DTO carries an `OperationId` and, where relevant, an optional
`idempotencyKey`. `CreatePaymentRequest` adds amount, **optional** payment
method, optional customer, capture/confirmation methods, description and scalar
metadata.

Omitting `paymentMethod` selects the deferred flow: the provider creates the
payment with no method attached and returns what the customer's browser needs
to choose one. Passing one selects the flow where the method already exists —
collected client-side beforehand, or stored on file.

An `idempotencyKey` travels verbatim in a provider request header, so it is
validated as a header token: non-empty, at most 255 bytes, no control
characters or spaces. Rejecting it at construction beats a PSR-7 error thrown
deep inside the transport that names neither the field nor the request.

Capabilities are typed values in an immutable `CapabilitySet`:

```php
$capabilities = CapabilitySet::of(
    new PartialRefundCapability(maximumRefunds: 5),
    new ThreeDsCapability(versions: ['2.2.0']),
    new WebhookCapability(signatureRequired: true),
    new SandboxCapability(),
);

$capabilities->has(PartialRefundCapability::class); // true
$capabilities->get(ThreeDsCapability::class)?->versions; // ['2.2.0']
```

Duplicate capability types are rejected. Implement the `Capability` marker to
ship provider-specific capabilities.

### Registry and router

`GatewayRegistry` indexes gateways by provider key and rejects duplicates.
`PaymentGatewayRouter` selects a provider **only for creation** via an
application-owned `GatewaySelectionPolicyInterface`; every other operation
routes by the provider already embedded in the payment/refund reference:

```php
$registry = new GatewayRegistry([$stripeGateway, $adyenGateway]);
$router = new PaymentGatewayRouter(
    registry: $registry,
    selectionPolicy: new FixedGatewaySelectionPolicy(new PaymentProvider('stripe')),
);

$attempt = $router->createPayment(new GatewaySelectionContext(
    request: $createRequest,
    tenantId: 'tenant-1',
    riskLevel: 'low',
));

$refund = $router->createRefund($createRefundRequest); // routes by $request->payment->provider
```

The package ships no implicit default policy — routing is a business decision.
`FixedGatewaySelectionPolicy` is the explicit single-provider policy. Calling
an optional operation on a gateway that does not implement the matching ISP
interface throws `\LogicException`; an unknown provider throws
`\OutOfBoundsException`. `GatewayRegistry::capability()` answers "does provider
X support Y" without touching the gateway.

### Transport

```php
$request = (new JsonRequestBuilder($requestFactory, $streamFactory))
    ->build('POST', 'https://api.example.test/payments', ['amount' => 1200], AuthContext::bearer($token));
$response = (new Psr18Transport($client))->send($request);
$payload = (new JsonResponseDecoder())->decode($response);
```

`Psr18Transport` only delegates to the injected client. Retry and timeout
policies remain application decorators. `FormRequestBuilder` encodes
`application/x-www-form-urlencoded` bodies from scalar-only data.
`AuthContext` (`bearer()`, `basic()`, `fromHeaders()`) is an in-memory header
handoff. `JsonResponseDecoder` maps HTTP and provider error payloads to typed
`PaymentException` subclasses and never logs credentials or raw headers:

| Condition | Exception |
|---|---|
| 401 | `UnauthorizedException` |
| 403 | `ForbiddenException` |
| 404 | `NotFoundException` |
| 429 | `RateLimitedException` |
| 5xx | `ServerException` |
| `refund_error` type / refund code | `RefundFailedException` |
| `card_error` / `decline` type | `ProviderDeclinedException` |
| Other non-2xx | `PaymentException` |
| Not a JSON object | `MalformedResponseException` |
| PSR-18 failure | `TransportException` |

`PaymentException` exposes sanitized `providerCode`, `providerType`,
`providerParameter` and allow-listed scalar `details`.

### Webhooks

`WebhookProcessor` enforces this ingress order:

```text
validate signature -> claim event id -> recognize and map -> durable acceptance
```

Adapters provide validator, event-type extractor, recognizer and payload mapper
implementations. The application provides an atomic event store plus one of two
acceptance modes:

```php
$acceptance = new QueuedWebhookEventAcceptance($queue); // AfterValidation
// or: new SynchronousWebhookEventAcceptance($reconciler); // AfterPersistence

$processor = new WebhookProcessor(
    validator: $validator,
    eventTypeExtractor: $extractor,
    eventRecognizer: $recognizer,
    payloadMapper: $mapper,
    eventStore: $eventStore,
    eventAcceptance: $acceptance,
);

$result = $processor->process(new WebhookInput(
    rawBody: $rawBody,
    provider: $provider,
    headers: $headers,
    requestMetadata: ['request_id' => $requestId],
));
```

`AfterValidation` means acknowledgement only after successful validation and
durable queue acceptance. `AfterPersistence` runs reconciliation synchronously
and acknowledges only after authoritative state has been re-fetched and
persisted. An acceptance exception is propagated and the incomplete claim is
released so the provider can retry.

Processing returns a sealed `WebhookProcessingResult`: `ProcessedWebhook`,
`WebhookValidationFailed`, `UnknownWebhookEvent`, `UnsupportedWebhookEvent`,
`RejectedWebhookEvent`, or `ReplayedWebhookEvent`. Narrow with `instanceof` — each outcome exposes only the
fields that are valid for it, so a processed result always carries its event and
acknowledgement policy, and a failure always carries its reason. A replay never
invokes recognition or mapping again.

The mapper produces `ObservedPaymentEvent` — a sanitized **observation**, not
authoritative provider state. It pairs a payment (or refund) reference with a
provider-neutral state, the raw provider status and an allow-listed scalar
payload; nested provider data is rejected because observations are durable.

### Webhook HTTP endpoint

`WebhookProcessorRegistry` maps providers to processors;
`WebhookController` is a PSR-7 adapter over the registry:

```php
$registry = new WebhookProcessorRegistry([
    new WebhookProcessorRegistration(provider: new PaymentProvider('stripe'), processor: $stripeProcessor),
]);
$controller = new WebhookController(registry: $registry, responseFactory: $responseFactory);

$response = $controller->handle($serverRequest, 'stripe');
```

Mount the webhook route **before** any body-parsing middleware. Signature
checks cover the exact received bytes, and a stream another middleware already
consumed reads back as an empty string, which would fail every check with a
correct secret and a correct signature. That case gets its own `empty_body`
outcome so it is diagnosable from the response instead of looking like a wrong
secret.

The controller never includes provider payloads or validation reasons in
responses; the outcome is exposed only in the `X-Payments-Webhook-Outcome`
header:

| Result | Status | Outcome header |
|---|---|---|
| Unknown/invalid provider key | 404 | `provider_not_found` |
| `ProcessedWebhook` | 204 | `processed` |
| `ReplayedWebhookEvent` | 204 | `replayed` |
| `UnknownWebhookEvent` | 204 | `ignored_unknown` |
| `UnsupportedWebhookEvent` | 204 | `ignored_unsupported` |
| `RejectedWebhookEvent` | 204 | `rejected_payload` |
| `WebhookValidationFailed` | 400 | `validation_failed` |
| Empty request body | 400 | `empty_body` |
| Processing exception | 503 | `processing_failed` (retryable) |
| Foreign result type | 500 | `processing_failed` |

### Public API

| Area | Types |
|---|---|
| Value objects | `Money`, `OperationId`, `PaymentProvider`, `PaymentReference`, `RefundReference`, `CustomerReference`, `PaymentMethodReference`, `RefundReason`, `ProviderEventType`, `ProviderRequestInfo`, `PaymentFailure` |
| Enums | `PaymentState`, `RefundState`, `CaptureMethod`, `ConfirmationMethod`, `WebhookAcknowledgementPolicy` |
| Domain models | `PaymentIntent`, `PaymentAttempt`, `RefundAttempt`, `ObservedPaymentEvent` |
| Operation requests | `CreatePaymentRequest`, `CapturePaymentRequest`, `PaymentOperationRequest`, `CreateRefundRequest`, `RetrievePaymentRequest`, `RetrieveRefundRequest` |
| Gateway contracts | `PaymentGatewayInterface`, `CaptureGatewayInterface`, `ConfirmGatewayInterface`, `CancelGatewayInterface`, `RefundGatewayInterface` |
| Capabilities | `Capability`, `CapabilitySet`, `PartialRefundCapability`, `SandboxCapability`, `ThreeDsCapability`, `WebhookCapability` |
| Next actions | `NextAction`, `RedirectToUrl`, `UseSdk`, `DisplayInstructions` |
| Routing | `GatewayRegistry`, `GatewaySelectionPolicyInterface`, `FixedGatewaySelectionPolicy`, `GatewaySelectionContext`, `PaymentGatewayRouter` |
| Transport | `TransportInterface`, `Psr18Transport`, `TransportException` |
| Request building | `RequestBuilderInterface`, `RawJsonRequestBuilderInterface`, `JsonRequestBuilder`, `FormRequestBuilder`, `AuthContext` |
| Response decoding | `ResponseDecoderInterface`, `JsonResponseDecoder`, `MalformedResponseException` |
| Provider failures | `PaymentException`, `UnauthorizedException`, `ForbiddenException`, `NotFoundException`, `RateLimitedException`, `ProviderDeclinedException`, `RefundFailedException`, `ServerException` |
| Webhook input/results | `WebhookInput`, `WebhookValidationResult`, `ValidWebhook`, `InvalidWebhook`, `WebhookProcessingResult`, `ProcessedWebhook`, `WebhookValidationFailed`, `UnknownWebhookEvent`, `UnsupportedWebhookEvent`, `RejectedWebhookEvent`, `ReplayedWebhookEvent`, `WebhookAcknowledgementPolicy` |
| Webhook adapter contracts | `WebhookValidatorInterface`, `WebhookEventTypeExtractorInterface`, `WebhookEventRecognizerInterface`, `WebhookPayloadMapperInterface`, `UnsupportedWebhookEventException` |
| Webhook persistence contracts | `WebhookEventStoreInterface`, `WebhookEventQueueInterface`, `WebhookReconcilerInterface`, `WebhookEventAcceptanceInterface` |
| Webhook orchestration | `WebhookProcessorInterface`, `WebhookProcessor`, `QueuedWebhookEventAcceptance`, `SynchronousWebhookEventAcceptance` |
| Webhook HTTP | `WebhookProcessorRegistry`, `WebhookProcessorRegistration`, `WebhookController` |

The two webhook hierarchies differ on purpose.

`WebhookValidationResult` is **closed, and PHP enforces it**:
`WebhookValidatorInterface::validate()` declares the native union
`ValidWebhook|InvalidWebhook`, so an implementation can neither widen the
signature (fatal error at class declaration) nor return another class
(`TypeError` naming the offending value). A signature is either authentic or
not; provider-specific data belongs to the mapping stage.

`WebhookProcessingResult` stays **open**. A decorating processor may add its own
outcome — throttled, tenant suspended, feature disabled — without waiting for a
release of this package. `WebhookProcessor::process()` narrows its return type
to the six outcomes shipped here, so calling the concrete processor allows an
exhaustive `instanceof` chain; calling through `WebhookProcessorInterface` means
handling an unknown outcome.

`WebhookInput::header()` requires a non-empty header name.

## What your application implements

Core deliberately ships no persistence, queueing, routing policy or token
caching. For a first working integration expect to write (or reuse from your
stack):

| You provide | Why it is yours |
|---|---|
| `WebhookEventStoreInterface` | Atomic `claim()`/`complete()`/`release()` over your database or Redis — replay protection is a storage decision |
| `WebhookEventQueueInterface` **or** `WebhookReconcilerInterface` | The durable boundary: a queue for `AfterValidation`, an authoritative re-fetch + persistence flow for `AfterPersistence` |
| Intent/attempt persistence | `PaymentIntent`/`PaymentAttempt` projections; adapters only return attempts |
| `GatewaySelectionPolicyInterface` | Business routing (or `FixedGatewaySelectionPolicy` for a single provider) |
| Token cache for OAuth providers | E.g. PayPal — `rasuvaeff/payments-paypal` ships `PayPalCachedTokenProvider`, so only wiring remains |
| Retry/timeout decorators | Transport stays dumb by design; retry only idempotent operations |

## Security

`AuthContext` is an in-memory handoff, not a serializable credentials DTO.
Credentials must never be placed in URLs or logs. Error decoding retains only
the provider code/type/parameter plus allow-listed request and retry metadata;
raw response headers and arbitrary payload values are discarded, and provider
text is capped at 1024 bytes before it travels on inside an exception message.

One leak path is worth naming because it bypasses everything above:
`Psr18Transport` keeps the PSR-18 exception as `previous`, and some clients
retain the request object — including its `Authorization` header — on that
exception. An error reporter that serializes exception chains or attaches the
failing request can therefore publish a live API key. Configure the reporter not
to attach request objects, and scrub `Authorization` in its before-send hook.

Every identity value object validates its input with `\z`-anchored patterns and
byte-length caps, so a provider key, currency code or reference id containing a
trailing newline is rejected instead of silently travelling on. `Money` uses
only integer arithmetic — no float rounding drift in amounts.
`RedirectToUrl` accepts HTTPS URLs only. `PaymentFailure`, `ObservedPaymentEvent`
and request metadata accept only scalar, allow-listed values.

`WebhookInput` keeps exact body bytes and validation headers in memory. Never
log or serialize `rawBody`, `headers()` or unsanitized request metadata; use
`sanitizedHeaders()` and `sanitizedRequestMetadata()` for short-lived audit
records. Both redact by name against a denial list of credential classes
(authorization, cookie, signature, secret, token, api key, credential,
password, bearer, session, private) — it is a best-effort filter, not a
guarantee: an unknown vendor prefix can still pass, so the logging policy stays
yours. Enforce a request-body size limit at the HTTP boundary. The durable
queue receives only `ObservedPaymentEvent`, whose payload must already be
sanitized by the provider mapper.

`WebhookEventStoreInterface` has three calls, and all three matter. `claim()`
must be atomic. `complete()` marks the event final. `release()` makes ordinary
processing failures retryable.

`complete()` is what makes stale-claim recovery possible. A process can die
between `claim()` and the end of processing without reaching `release()` —
SIGKILL, the OOM killer, `max_execution_time`, a fatal error, a pod restart.
Without a completion signal a store cannot tell an in-flight claim from a
finished one, and both available choices are wrong: expire claims and an
already-processed event is processed again on the next provider retry; never
expire them and the interrupted event is answered with a replay outcome — HTTP
204 — so the provider stops retrying and the event is lost with no error
anywhere. With `complete()` the rule is unambiguous: a claim that was never
completed and whose lease expired is stale and must be reclaimable; a completed
claim is never handed out again.

If the durable queue lives in the same database, there is a simpler option:
commit the claim row and the queue row in one transaction, so that "a claim
exists" already implies "the event is durable". That works only for a
same-database queue — a broker (SQS, Redis) cannot join the transaction, and
those deployments need the lease plus `complete()`.

A permanently unmappable payload is not retryable either. `WebhookProcessor`
turns `MalformedResponseException` from a mapper into `RejectedWebhookEvent`,
completes the claim and lets the bridge acknowledge: the same bytes would fail
the same way forever, and providers disable an endpoint that keeps failing,
which loses the *following*, healthy events. Record rejections for a human —
they mean the adapter and the provider disagree about the payload format.
Queue implementations must be idempotent by provider and provider event id so
a process failure around the durable boundary cannot duplicate business work.
Webhook observations never update payment state directly:
`PaymentState::canTransitionTo()` is advisory, and reconciliation must re-fetch
authoritative provider state before persistence or event publication.
`WebhookController` leaks nothing about validation internals to the caller —
only a status code and an outcome token.

## Examples

See [examples/](examples/) for runnable snippets.

| Script | Shows | Needs server? |
|---|---|---|
| `build-json-request.php` | JSON request encoding and bearer authentication | No |
| `process-webhook.php` | Validation, replay claim and durable queue acceptance | No |

## Cookbook and security

- [Cookbook](docs/cookbook.md) — wiring Stripe/PayPal gateways, routing,
  refunds, webhook ingestion, acknowledgement policies, retries.
- [Security and retention](docs/security-retention.md) — credential handling,
  webhook validation boundaries, retention by data class.

## Development

```bash
docker run --rm -v "$PWD":/app -w /app composer:2 composer build
```

## License

BSD-3-Clause
