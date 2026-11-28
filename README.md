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

PHP 8.3 – 8.6. A PSR-18 client with PSR-17 factories for outbound provider calls;
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

#### What the contract fixes for adapters

**Minor units are ISO 4217.** `Money::$minorUnits` counts the currency's ISO
4217 minor unit: cents for USD and EUR (exponent 2), whole yen for JPY and
whole krónur for ISK (exponent 0), fils for KWD and BHD (exponent 3). The core
carries no exponent table; it fixes the meaning, so one `Money` is the same
amount for every adapter of the stack. Where a provider's wire format differs,
the adapter converts. Before any HTTP call it refuses an amount it cannot
represent exactly, with `\InvalidArgumentException`, and it converts back when
it maps a response. Known cases:

| Provider | Currencies | Wire format | The adapter must |
|---|---|---|---|
| Stripe | ISK, UGX | two-decimal (`500` charges 5 ISK); ISO exponent 0 | multiply by 100 on the way out, divide on the way back |
| Stripe | BHD, JOD, KWD, OMR, TND | three-decimal, last digit must be `0` | refuse an amount not divisible by 10 |
| PayPal | HUF, TWD | zero-decimal value; ISO exponent 2 | refuse an amount not divisible by 100, send `minorUnits / 100` |

The table lists the cases found by the 2026-11-28 review; check the provider's
current currency list when an adapter adds a currency.

**A zero amount is accepted** by `Money` and `CreatePaymentRequest`, because
the core cannot know a provider's minimum charge. The provider refuses it, and
the adapter raises that refusal as a `PaymentException`. If a clearer error
matters, validate the order total before creating a payment.

**Two amounts: created and captured.** `PaymentAttempt::$amount` is the amount
the payment was created for, as the provider reports it; for an authorization
that is the authorized amount, and a capture does not change it.
`PaymentAttempt::$capturedAmount` is the total the provider has taken so far:

| `capturedAmount` | Meaning |
|---|---|
| `null` | the response does not say (the adapter has nothing to map) |
| zero | nothing captured yet — an authorization in `RequiresCapture`, an unpaid payment |
| equal to `amount` | an automatic or full capture succeeded |
| less than `amount` | a partial capture; the rest of the authorization is released or still capturable, per provider |

Reconcile what was collected against `capturedAmount`, not `amount`: after a
partial capture `$attempt->amount->equals($order->total)` is still true. The
attempt refuses a `capturedAmount` in another currency; it does not cap it at
`amount`, because a provider with overcapture can take more and is
authoritative. Adapters map it from the response:

| Provider | `amount` | `capturedAmount` |
|---|---|---|
| Stripe PaymentIntent | `amount` | `amount_received` (`amount_capturable` is what is still capturable, not captured) |
| PayPal order | `purchase_units[0].amount` | sum of `purchase_units[0].payments.captures[].amount` with status `COMPLETED`; zero when there are none; on a capture response, the captured amount it returns |

An adapter that cannot read the captured amount from a response leaves it
`null` rather than guessing from the state.

**Every create carries a key.** `CreatePaymentRequest::$idempotencyKey` is a
non-empty string. A key you pass is used verbatim; without one the request
mints a random UUID v4 (36 characters). The effective key exists before the
call, so it reaches the adapter (`Idempotency-Key` for Stripe,
`PayPal-Request-Id` for PayPal) and survives the failure that matters — a
timeout, after which there is no attempt to read anything from:

```php
$request = new CreatePaymentRequest(operationId: $op, amount: $total, paymentMethod: $pm);
$store->rememberKey($order, $request->idempotencyKey);   // optional: survive a restart

$attempt = $retry->run(fn () => $gateway->createPayment($request));   // same object, same key
```

Build the request once, outside the retry loop: a closure that calls
`new CreatePaymentRequest(...)` mints a new key on every try, and each try is
a new payment. To retry from another process, pass the remembered key back as
`idempotencyKey:`. For capture, confirm, cancel and refund the key stays
optional and is never generated; the adapters of this family send no key when
it is `null`, and such a request is not retry-safe. See the cookbook section
"OperationId vs idempotency key".

The core caps a key at 255 bytes, Stripe's limit. A provider may accept less:
PayPal's `PayPal-Request-Id` is at most 108 characters. An adapter whose
provider has a lower cap must reject a longer key with
`\InvalidArgumentException` before the HTTP call, and state its cap in its
README. The core limit stays at 255, so a key valid for one adapter can be
refused by another; if your application routes between providers, keep keys
within the smallest cap.

**Mappers convert value-object errors.** A response or webhook mapper that
builds core value objects from provider data catches their
`\InvalidArgumentException` (an unknown currency, an oversized id) and rethrows
it as `MalformedResponseException`. `WebhookProcessor` treats only
`MalformedResponseException` and `UnsupportedWebhookEventException` from
`map()` as terminal; any other exception releases the claim and is answered
503, so an unconverted value-object error makes the same payload redeliver
forever. The event-type extractor and the recognizer run outside that
handling: for a body they cannot read they return `null`, which becomes
`UnknownWebhookEvent`, instead of throwing.

**Refund states have no transition map.** A Stripe refund can move from
`succeeded` to `failed` later. Re-fetch a refund before acting on its outcome.

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
| `idempotency_error` type | `IdempotencyConflictException` |
| `refund_error` type / refund code | `RefundFailedException` |
| `card_error` / `decline` type | `ProviderDeclinedException` |
| Other non-2xx | `PaymentException` |
| 2xx body that is not a JSON object | `MalformedResponseException` |
| PSR-18 failure | `TransportException` |

The status decides before the body is read. An error answer whose body is not a
JSON object — the HTML or empty page a load balancer, CDN or WAF serves during
an outage — still raises the exception of its status row, with the message
`Payment provider request failed` and no provider metadata. So a 5xx stays a
retryable `ServerException`; `MalformedResponseException` only means a
successful answer could not be read.

`PaymentException` exposes sanitized `providerCode`, `providerType`,
`providerParameter` and allow-listed scalar `details`: `request_id` and
`retry_after` from the top level of the body, `decline_code` and
`payment_intent` (the id of `error.payment_intent`) from the error object. A
card declined while a create was confirming leaves the payment behind at the
provider, and `details['payment_intent']` is how to retry on it instead of
creating a second one. The decoder reads no response headers, so a provider
that sends its request id or `Retry-After` only as a header leaves those keys
empty — read them from the response in the adapter.

`IdempotencyConflictException` usually means the key was reused with a request
that differs from the first: a caller bug. Retrying with the same key fails the
same way and retrying with a new key repeats the operation, so neither is a
retry policy. One case is retryable: `providerCode` `idempotency_key_in_use`
(Stripe answers 409) means a concurrent request with the same key is still
running, and retrying later with the same key returns its result. The refund row matches any provider code containing `refund`, which also
catches codes such as `charge_already_refunded`; an adapter that needs a finer
split classifies by `providerCode` itself.

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
| Provider failures | `PaymentException`, `UnauthorizedException`, `ForbiddenException`, `NotFoundException`, `RateLimitedException`, `ProviderDeclinedException`, `RefundFailedException`, `IdempotencyConflictException`, `ServerException` |
| Webhook input/results | `WebhookInput`, `WebhookValidationResult`, `ValidWebhook`, `InvalidWebhook`, `WebhookProcessingResult`, `ProcessedWebhook`, `WebhookValidationFailed`, `UnknownWebhookEvent`, `UnsupportedWebhookEvent`, `RejectedWebhookEvent`, `ReplayedWebhookEvent`, `WebhookAcknowledgementPolicy` |
| Webhook adapter contracts | `WebhookValidatorInterface`, `WebhookEventTypeExtractorInterface`, `WebhookEventRecognizerInterface`, `WebhookPayloadMapperInterface`, `UnsupportedWebhookEventException` |
| Webhook persistence contracts | `WebhookEventStoreInterface`, `WebhookClaimToken`, `WebhookEventQueueInterface`, `WebhookReconcilerInterface`, `WebhookEventAcceptanceInterface` |
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

`WebhookInput::header()` requires a non-empty header name. Header names given
to `WebhookInput` must be RFC 9110 tokens (letters, digits and
``!#$%&'*+-.^_`|~``); an all-digit name that PHP turned into an int array key
is accepted as its string. It stays an int key in `headers()` and
`sanitizedHeaders()` (PHP cannot hold `"123"` as a string key), so cast the key
with `(string)` before passing it on as a name. Anything else throws `\InvalidArgumentException`,
which `WebhookController` answers like any processing exception — 503
`processing_failed`, logged. A PSR-7 request cannot carry such a name, so from
the controller this only fires on a broken message implementation.

## What your application implements

Core deliberately ships no persistence, queueing, routing policy or token
caching. For a first working integration expect to write (or reuse from your
stack):

| You provide | Why it is yours |
|---|---|
| `WebhookEventStoreInterface` | Atomic `claim()`/`complete()`/`release()` fenced by a `WebhookClaimToken` over your database or Redis — replay protection is a storage decision |
| `WebhookEventQueueInterface` **or** `WebhookReconcilerInterface` | The durable boundary: a queue for `AfterValidation`, an authoritative re-fetch + persistence flow for `AfterPersistence` |
| Intent/attempt persistence | `PaymentIntent`/`PaymentAttempt` projections; adapters only return attempts |
| `GatewaySelectionPolicyInterface` | Business routing (or `FixedGatewaySelectionPolicy` for a single provider) |
| Token cache for OAuth providers | E.g. PayPal — the `rasuvaeff/payments-paypal` adapter (not published yet) ships `PayPalCachedTokenProvider`, so only wiring remains |
| Retry/timeout decorators | Transport stays dumb by design; retry only idempotent operations |

## Security

`AuthContext` is an in-memory handoff, not a serializable credentials DTO.
`serialize()` throws `\LogicException`, `var_dump()`/`print_r()` show the header
names with `[REDACTED]` values, and the credential parameters of `bearer()`,
`basic()` and `fromHeaders()` are `#[\SensitiveParameter]`, so a factory that
throws does not put the secret into the stack trace. `var_export()` and an
`(array)` cast read the private property directly; do not use them on it.
`basic()` rejects a username containing `:` (RFC 7617 splits the pair at the
first colon). Credentials must never be placed in URLs or logs. Error decoding retains only
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
must be atomic and returns a `WebhookClaimToken`; `complete()` marks the event
final; `release()` makes ordinary processing failures retryable.

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

The token is the fence that makes lease expiry safe. `complete()` and
`release()` must compare it against the stored claim and do nothing on a
mismatch: a worker whose lease expired and whose claim was taken over holds a
stale token, and its `release()` must never delete the live claim of the new
owner — that would let a third delivery start processing concurrently.

If the durable queue lives in the same database, there is a simpler option:
commit the claim row and the queue row in one transaction, so that "a claim
exists" already implies "the event is durable". That works only for a
same-database queue — a broker (SQS, Redis) cannot join the transaction, and
those deployments need the lease plus `complete()`.

A permanently unmappable payload is not retryable either. `WebhookProcessor`
turns `MalformedResponseException` from a mapper into `RejectedWebhookEvent`,
completes the claim and lets the bridge acknowledge: the same bytes would fail
the same way forever, and providers disable an endpoint that keeps failing,
which loses the *following*, healthy events. The same holds for
`UnsupportedWebhookEventException` (→ `UnsupportedWebhookEvent`). The outcome's
`reason` is the exception message capped at 1024 bytes on a character boundary;
a blank message gets a fixed fallback, so no message text can turn the terminal
verdict back into a retryable failure. Record rejections for a human —
they mean the adapter and the provider disagree about the payload format.
Queue implementations must be idempotent by provider and provider event id so
a process failure around the durable boundary cannot duplicate business work.
Webhook observations never update payment state directly:
`PaymentState::canTransitionTo()` is advisory, and reconciliation must re-fetch
authoritative provider state before persistence or event publication.
`WebhookController` leaks nothing about validation internals to the caller —
only a status code and an outcome token. That opacity is also why the
controller takes an optional PSR-3 logger: a `503 processing_failed` otherwise
leaves no trace of its cause, and detection rests on whoever watches the 503
rate.

```php
$controller = new WebhookController(
    registry: $registry,
    responseFactory: $responseFactory,
    logger: $logger, // optional; the response contract does not change
);
```

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
