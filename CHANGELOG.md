# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## Unreleased

### Security

- `WebhookEventStoreInterface` gained `complete()`. A claim is now explicitly
  finalised after durable acceptance, which is what lets an implementation
  recover a claim abandoned by a process that died before `release()` — without
  it, such an event was answered with a replay outcome (204) forever and lost
  silently, while the alternative (expiring claims) reprocessed events that had
  already been handled.
- Permanently unmappable payloads no longer look retryable: a
  `MalformedResponseException` from a mapper becomes the new
  `RejectedWebhookEvent` outcome (HTTP 204), so one poison payload can no longer
  drive a retry storm that gets the endpoint disabled and loses the following
  events.
- `WebhookController` reports an empty request body as its own `empty_body`
  outcome instead of a signature failure, so a body-parsing middleware mounted
  ahead of the webhook route is diagnosable from the response.
- Provider error text is capped at 1024 bytes, cut back to a character
  boundary, before it travels on inside an exception message.
- `idempotencyKey` is validated as a header token — no control characters or
  spaces — at the boundary that owns it, rather than failing deep inside a
  PSR-7 transport.

### Added

- `PaymentState::RequiresPaymentMethod` for a recoverable decline. Terminal
  states must not absorb a status the provider can still move to `succeeded`.
- `Money::equals()`, `isGreaterThan()` and `isLessThan()`. `equals()` compares
  currency as well as amount, which is what reconciliation needs when comparing
  an authoritative provider amount against an order.
- `RawJsonRequestBuilderInterface::buildRawJson()`, implemented by
  `JsonRequestBuilder`, for forwarding a signed document byte for byte.
- `CreatePaymentRequest::$paymentMethod` is now optional, enabling the deferred
  flow where the customer selects a method client-side.
- Provider-neutral webhook validation, replay protection, mapping, redaction,
  durable queue acceptance and synchronous reconciliation contracts.
- Sealed webhook outcome types: `ValidWebhook`, `InvalidWebhook`,
  `ProcessedWebhook`, `WebhookValidationFailed`, `UnknownWebhookEvent`,
  `UnsupportedWebhookEvent`, `RejectedWebhookEvent`, `ReplayedWebhookEvent`.
- `docs/cookbook.md` / `docs/cookbook.ru.md` and
  `docs/security-retention.md` / `docs/security-retention.ru.md` covering
  end-to-end recipes, credential handling and webhook retention boundaries.

### Changed

- `WebhookProcessor` compares providers and event types by value instead of
  PHP's loose object `!=`, which a strict-comparison rector rule would
  silently turn into an instance check; `PaymentAttempt`, `RefundAttempt` and
  `ObservedPaymentEvent` do the same for their cross-reference consistency
  checks.
- README now carries a "What your application implements" checklist (event
  store, queue or reconciler, persistence, routing policy, token cache,
  retry decorators).
- `WebhookValidationResult` and `WebhookProcessingResult` are now interfaces
  instead of single classes with nullable fields. Consumers narrow with
  `instanceof`; the `status` constants and the `valid`/`reason`/`event` nullable
  properties are gone.
- `WebhookValidatorInterface::validate()` declares the native union return type
  `ValidWebhook|InvalidWebhook`, so PHP itself rejects a widened signature or a
  foreign result class. `WebhookProcessorInterface::process()` stays open; only
  `WebhookProcessor::process()` narrows to the five shipped outcomes.
- `AuthContext::headers()`, `WebhookInput::headers()` and
  `WebhookInput::requestMetadata()` export refined Psalm types
  (`non-empty-string` keys, `non-empty-string` auth values).
- `WebhookInput::header()` requires a non-empty header name.
- `WebhookEventStoreInterface::claim()` and `release()` document
  `non-empty-string` event identifiers.
- Mutation gate raised to `minMsi: 100`; the JSON nesting limit is now covered
  on both sides of the boundary.
- Property tests migrated from the frozen `rasuvaeff/property-testing` 2.x to
  `rasuvaeff/property-testing-testo` and now use signature-derived generators
  (`auto`), named example tuples and `Classify::cover()` distribution gates.
- `rasuvaeff/rector-named-literals` gates named-literal arguments in `src/`
  and `tests/`.
