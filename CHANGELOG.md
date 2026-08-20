# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## 0.2.0 — 2026-08-20

### Changed

- **Breaking:** `WebhookEventStoreInterface::claim()` now returns
  `?WebhookClaimToken` instead of `bool`, and `complete()`/`release()` require
  the token as a third argument. Both finalisers must be no-ops when the token
  no longer matches the stored claim. This fences claim ownership: with
  lease-based expiry a stalled worker whose claim was taken over could
  otherwise finalise an attempt it no longer owns — and its `release()` would
  delete the new owner's live claim, letting a third delivery start processing
  concurrently. `WebhookProcessor` threads the token from `claim()` through to
  both finalisers; implementations mint a token per claim
  (`WebhookClaimToken::generate()`) and compare it before acting. Same-store
  claims with no expiry semantics are unaffected beyond the signature change.

### Added

- `WebhookClaimToken`: opaque claim-ownership token — non-empty URL-safe value
  of at most 128 bytes, `generate()` factory (128 bits of entropy) and
  constant-time `equals()`.

## 0.1.0 — 2026-08-19

Initial release.

### Added

- Provider-neutral payment contracts: `PaymentGatewayInterface` with the
  optional `Capture`, `Cancel`, `Confirm` and `Refund` gateway interfaces, the
  request objects they take, and `PaymentIntent`, `PaymentAttempt`,
  `RefundAttempt`, `PaymentFailure` and `ObservedPaymentEvent` as results.
- A minor-unit `Money` value object with `equals()`, `isGreaterThan()` and
  `isLessThan()` — `equals()` compares currency as well as amount, which is
  what reconciliation needs when checking a provider amount against an order.
- `PaymentState` and `RefundState` with an advisory `canTransitionTo()` map.
  `RequiresPaymentMethod` models a recoverable decline: a terminal state must
  not absorb a status the provider can still move to `succeeded`.
- PSR-18 transport (`Psr18Transport`), `JsonRequestBuilder` including
  `buildRawJson()` for forwarding a signed document byte for byte,
  `FormRequestBuilder` and `JsonResponseDecoder`, with a typed exception
  hierarchy over the HTTP boundary.
- `GatewayRegistry`, `PaymentGatewayRouter` and a selection policy, so an
  application routes by capability instead of by hard-wired provider name.
- A webhook pipeline: validation, replay protection through a claim/complete
  event store, event recognition, payload mapping, redaction, and either
  durable queue acceptance or synchronous reconciliation.
- Sealed webhook outcome types — `ProcessedWebhook`, `ReplayedWebhookEvent`,
  `WebhookValidationFailed`, `UnknownWebhookEvent`, `UnsupportedWebhookEvent`
  and `RejectedWebhookEvent` — so an HTTP bridge picks its acknowledgement by
  `instanceof` rather than by guessing.
- `WebhookController`, a PSR-7 adapter that answers with a status and an
  outcome token only, never with a payload or a validation reason, and takes an
  optional PSR-3 logger so a `503` failure leaves a trace.
- `docs/cookbook.md` / `docs/cookbook.ru.md` and
  `docs/security-retention.md` / `docs/security-retention.ru.md` covering
  end-to-end recipes, credential handling and webhook retention boundaries.

### Security

- A permanently unmappable payload is terminal, not retryable: a
  `MalformedResponseException` from a mapper becomes `RejectedWebhookEvent`
  (HTTP 204), so one poison payload cannot drive the retry storm that gets an
  endpoint disabled and loses the deliveries behind it.
- A claim is finalised explicitly through `WebhookEventStoreInterface::complete()`.
  Without a completion signal a store cannot tell an in-flight claim from a
  finished one: expire claims and an already-handled event is processed again on
  the next provider retry; never expire them and a claim abandoned by a killed
  process answers every later delivery with a replay outcome, so the provider
  stops retrying and the event is lost with no error anywhere.
- An empty request body is reported as its own `empty_body` outcome rather than
  as a signature failure, so a body-parsing middleware mounted ahead of the
  webhook route is diagnosable from the response alone.
- `sanitizedHeaders()` and `sanitizedRequestMetadata()` redact credential
  classes by name. The list is best-effort and does not replace a logging
  policy.
- Provider error text is capped at 1024 bytes, cut back to a character
  boundary, before it travels inside an exception message; `idempotencyKey` is
  validated as a header token at the boundary that owns it, and it is never
  generated for the caller.
