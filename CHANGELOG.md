# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## Unreleased

- **Fixed:** a mapper's `MalformedResponseException` or `UnsupportedWebhookEventException` stays terminal whatever its message. `WebhookProcessor` passed the message verbatim as the outcome `reason`, and the `RejectedWebhookEvent`/`UnsupportedWebhookEvent` constructors refuse a reason longer than 1024 bytes or blank (`" "`): such a message escaped as `InvalidArgumentException`, released the claim and was answered 503, so the same payload was redelivered forever — the retry storm the terminal outcomes exist to prevent. The reason is now capped at 1024 bytes on a UTF-8 character boundary, and a blank one gets the fixed fallback the empty message already had. The byte cap shared with `JsonResponseDecoder` moved to `Internal\Utf8Text`.
- **Fixed:** `JsonResponseDecoder` classifies an error answer by its status before reading the body, as the README table always promised. A 5xx, 429 or 401 whose body is HTML, empty or not a JSON object — the page a load balancer, CDN or WAF serves during an outage — raised `MalformedResponseException`, which the package treats as permanent, instead of `ServerException`/`RateLimitedException`/`UnauthorizedException`; a retry policy keyed on `ServerException` never fired on the most common outage, and on a 5xx the charge outcome is unknown. Such an answer now raises its status exception with the message `Payment provider request failed` and no provider metadata; `MalformedResponseException` is raised only for a 2xx body. **Behaviour change** for callers that caught `MalformedResponseException` around a non-2xx call: they now receive the status exception (for a 4xx outside 401/403/404/429, a plain `PaymentException`).
- **Added:** `IdempotencyConflictException` (extends `PaymentException`). `JsonResponseDecoder` raises it for an error of type `idempotency_error` — a key reused with a different request — which used to be a plain `PaymentException`, indistinguishable from any other client error for a retry policy. Status rows (401/403/404/429/5xx) still win. Code catching `PaymentException` is unaffected.
- **Added:** `JsonResponseDecoder` keeps `decline_code` and the id of `error.payment_intent` in the exception `details`. A card declined during a confirming create (Stripe answers 402 with the PaymentIntent) leaves a payment at the provider, and its id was lost: the caller could only retry with a new key, which created a second payment.
- **Fixed:** `JsonResponseDecoder` rewinds a seekable body before reading it. A logging middleware that had already read the stream left it at its end, and a successful answer became `MalformedResponseException` "not valid JSON".
- **Fixed:** `WebhookInput` accepts every RFC 9110 header field name. It allowed only `[A-Za-z0-9_-]`, so a name with another `tchar` — `X-Forwarded.For`, `X-Trace!Id` — or an all-digit name (which PHP stores as an int array key) threw `InvalidArgumentException`, and `WebhookController` answered every delivery carrying it with 503 `processing_failed`. Infrastructure that adds such a header to each request (a load balancer, CDN or WAF) made the provider retry every event until it disabled the endpoint. Names outside `tchar` are still rejected, and that rejection still maps to 503.
- **Security:** `AuthContext` no longer leaks the credential it carries. `serialize()`/`unserialize()` throw `\LogicException` (the README always called it "not a serializable credentials DTO", but it serialized with the secret in clear), `__debugInfo()` redacts header values for `var_dump()`/`print_r()`, and the credential parameters of the constructor, `bearer()`, `basic()` and `fromHeaders()` carry `#[\SensitiveParameter]`, so a factory that throws on one bad header no longer puts the others into the stack trace. `var_export()` and an `(array)` cast still read the private property. The adapters' own credential objects already did this; they all hand their secret to `AuthContext`, which did not.
- **Fixed:** `AuthContext::basic()` rejects a username containing `:`. RFC 7617 forbids it, and the server splits the decoded pair at the first colon, so the tail of the username silently became part of the password.

## 0.2.1 — 2026-11-22

- `composer mutation` now runs Infection with `--with-uncovered`, so code the tests execute without naming it in `#[Covers]` is mutated and counted (overall MSI was 83% when measured this way, now 100% against `minMsi` 100). Added `AssertTest` (every guard in `Internal\Assert`, boundaries and exact messages), `PaymentExceptionTest`, a `Money::multiply()` round-up overflow case, a numeric webhook header name case, and unsupported confirmation/cancellation/refund cases for `PaymentGatewayRouter`. `infection.json5` gained the template's signature-default ignore regex and disabled `PublicVisibility`/`ProtectedVisibility`; the existing `addParts` ignore is kept. `Money::multiply()` and `WebhookCapability::__construct()` now list their parameters one per line (no behaviour change) so the ignore regex matches their defaults.
- **Changed:** PHP 8.6 is supported: the `php` constraint is widened to
  `8.3 - 8.6` (#12). CI runs PHP 8.6 as a regular matrix entry; the
  backward-compatibility job runs on PHP 8.5.
- **Fixed:** psalm 7.15.0 compatibility in `JsonResponseDecoder`: its stricter
  `get_object_vars()` stub infers `array<array-key, mixed>`, which the decoder's
  documented `array<string, mixed>` payload shape now asserts explicitly at both
  decode sites (annotation-only, no runtime change).

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
