# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## Unreleased

- **Dependencies:** development constraints raised to the latest releases, one major each: `rasuvaeff/property-testing-testo` ^1.3 → ^1.4.1, `rasuvaeff/rector-named-literals` ^1.0 → ^1.0.2, `rasuvaeff/understudy-testo` ^1.0 → ^1.2.1, `testo/bridge-infection` ^0.1.6 → ^0.1.9, `testo/testo` ^0.10.25 → ^0.10.62, `vimeo/psalm` ^7.0 → ^7.23. The new versions flag nothing. `.github/dependabot.yml` is added from the monorepo template (weekly grouped updates, `rasuvaeff/*` moved by hand). The Makefile mounts a persistent `COMPOSER_HOME` (template `DOCKER :=` line).
- **Tests:** more property tests, in the modern forms (provider classes in `tests/Support/` with callable `generators:`, `auto: true`, `<method>Examples()`, `Classify::cover` gates calibrated to about half of the measured share): a model-based test of the webhook claim lifecycle (`Gen::rules` over `tests/Support/WebhookLifecycleMachine.php` with an in-memory fenced claim store and an adapter scripted per delivery — processed, unknown, unsupported, malformed, bad signature, a failing mapper or queue — interleaved with another worker holding and dropping a claim; invariants: an event is queued at most once, the store's completed set and live claims match the model); `Money` associativity, a total order within one currency and refusal across currencies, arithmetic refusing another currency, `multiply(n, n)` identity and monotonicity in the numerator; `PaymentAttempt` accepting a captured amount exactly in the payment currency, overcapture included; `CreatePaymentRequest` keeping a valid key verbatim and refusing every other (naughty strings, control bytes, 255/256 bytes); `WebhookClaimToken` accept/reject against its format and generated tokens valid and distinct; `AuthContext` never showing a bearer, Basic or custom-header credential in `print_r()`/`var_dump()`.
- **Breaking:** `CreatePaymentRequest::$idempotencyKey` is a non-empty `string`, never `null`. Without a key the request generates a random UUID v4 (36 characters, `random_bytes`, no new dependency); a given key is kept verbatim and still validated (header token, ≤ 255 bytes). A create without a key was not retry-safe: a retry after a timeout charged twice, and the only consumer derived its own fallback. Because the key exists before the call, every adapter receives it and the caller can retry by resending the same request object or by persisting `$request->idempotencyKey`. Code that checked the create key for `null` (`$request->idempotencyKey ?? …`) must drop the check. A test that built two equal requests without keys now gets two different keys. Capture, confirm, cancel and refund keys stay optional.
- **Changed:** `CreatePaymentRequest`'s `captureMethod` and `confirmationMethod` parameters accept `null` (meaning `Automatic`, the old default); the properties stay non-null. The enum defaults in promoted parameters made roave report `[BC] SKIPPED` on every run.
- **Added:** `PaymentAttempt::$capturedAmount` (`?Money`, last constructor argument, default `null`) — the total the provider has captured, apart from `amount`, which stays the created/authorized amount. After a partial capture the only amount on the attempt was the full authorization, so `$attempt->amount->equals($order->total)` passed although less was collected. `null` means the response did not say; zero means nothing captured yet; a `capturedAmount` in another currency than `amount` is refused with `\InvalidArgumentException`, a larger one is accepted (overcapture). The README tells adapters what to map: Stripe `amount_received`, PayPal the sum of `COMPLETED` captures. Existing constructor calls compile unchanged; an adapter that does not map it reports `null`.
- **Fixed:** a mapper's `MalformedResponseException` or `UnsupportedWebhookEventException` stays terminal whatever its message. `WebhookProcessor` passed the message verbatim as the outcome `reason`, and the `RejectedWebhookEvent`/`UnsupportedWebhookEvent` constructors refuse a reason longer than 1024 bytes or blank (`" "`): such a message escaped as `InvalidArgumentException`, released the claim and was answered 503, so the same payload was redelivered forever — the retry storm the terminal outcomes exist to prevent. The reason is now capped at 1024 bytes on a UTF-8 character boundary, and a blank one gets the fixed fallback the empty message already had. The byte cap shared with `JsonResponseDecoder` moved to `Internal\Utf8Text`.
- **Fixed:** `JsonResponseDecoder` classifies an error answer by its status before reading the body, as the README table always promised. A 5xx, 429 or 401 whose body is HTML, empty or not a JSON object — the page a load balancer, CDN or WAF serves during an outage — raised `MalformedResponseException`, which the package treats as permanent, instead of `ServerException`/`RateLimitedException`/`UnauthorizedException`; a retry policy keyed on `ServerException` never fired on the most common outage, and on a 5xx the charge outcome is unknown. Such an answer now raises its status exception with the message `Payment provider request failed` and no provider metadata; `MalformedResponseException` is raised only for a 2xx body. **Behaviour change** for callers that caught `MalformedResponseException` around a non-2xx call: they now receive the status exception (for a 4xx outside 401/403/404/429, a plain `PaymentException`).
- **Docs:** the README states what the contract fixes for adapters. `Money::$minorUnits` is the ISO 4217 minor unit, and an adapter converts it to its provider's wire format: Stripe ISK and UGX are two-decimal, Stripe BHD/JOD/KWD/OMR/TND need a last digit 0, PayPal HUF and TWD are zero-decimal. Before this the meaning was undefined, and the Stripe adapter charged 50 ISK for `Money(5000, 'ISK')`. Also stated: a zero amount is the provider's to refuse; `PaymentAttempt::$amount` is the created/authorized amount, and `capturedAmount` (below) what was captured; a create always carries an idempotency key (generated when not given, below), while a capture, confirm, cancel or refund without one is not retry-safe; the 255-byte key cap is the core's, and an adapter with a lower provider cap (PayPal `PayPal-Request-Id` ≤ 108) rejects a longer key before the call; a mapper converts value-object `InvalidArgumentException` into `MalformedResponseException`, because nothing else is terminal; `RefundState` has no transition map. The cookbook, README and security notes mark `rasuvaeff/payments-stripe` and `rasuvaeff/payments-paypal` as not published yet.
- **Tests:** property tests for the byte cap shared by error messages and webhook reasons (any bytes, including invalid UTF-8 and naughty strings: within the cap, valid UTF-8, a prefix; on valid text the longest whole-character prefix, with examples at every UTF-8 width) and for the decoder's status-before-body classification across statuses 200–599 and outage-style bodies, each with `Classify::cover` gates. `rasuvaeff/property-testing-testo` in `require-dev` is raised to `^1.3`: `Gen::naughtyString()` arrived in core 1.3.0, and the prefer-lowest job would otherwise resolve adapter 1.0.0 with core 1.0.0. `PaymentState` transitions are checked exhaustively — all 64 pairs — against a hand-written matrix instead of a sampled oracle that restated the implementation. `composer bc-check` treats a report made only of roave `[BC] SKIPPED` entries as a pass, as the CI step already did (the enum defaults in `CreatePaymentRequest` are reported that way on every run); static analysis runs Rector; `actions/cache` is pinned to v6.1.0; `.github/CODEOWNERS` is added.
- **Added:** `IdempotencyConflictException` (extends `PaymentException`). `JsonResponseDecoder` raises it for an error of type `idempotency_error` — a key reused with a different request — which used to be a plain `PaymentException`, indistinguishable from any other client error for a retry policy. `providerCode` `idempotency_key_in_use` (a concurrent request with the same key, Stripe 409) is the one retryable case and is documented as such; it is classified by the code alone, whatever its type, because Stripe documents the code without one (https://docs.stripe.com/error-codes). Status rows (401/403/404/429/5xx) still win. Code catching `PaymentException` is unaffected.
- **Added:** `JsonResponseDecoder` keeps `decline_code` and the id of `error.payment_intent` in the exception `details`. A card declined during a confirming create (Stripe answers 402 with the PaymentIntent) leaves a payment at the provider, and its id was lost: the caller could only retry with a new key, which created a second payment.
- **Fixed:** `JsonResponseDecoder` replaces an empty or blank `error.message` with `Payment provider request failed`; the exception carried an empty message. (Invalid UTF-8 cannot reach the message: `json_decode()` refuses such a body, and the error is then classified by its status alone.)
- **Fixed:** `JsonResponseDecoder` rewinds a seekable body before reading it. A logging middleware that had already read the stream left it at its end, and a successful answer became `MalformedResponseException` "not valid JSON".
- **Fixed:** `WebhookInput` accepts every RFC 9110 header field name. It allowed only `[A-Za-z0-9_-]`, so a name with another `tchar` — `X-Forwarded.For`, `X-Trace!Id` — or an all-digit name (which PHP stores as an int array key) threw `InvalidArgumentException`, and `WebhookController` answered every delivery carrying it with 503 `processing_failed`. Infrastructure that adds such a header to each request (a load balancer, CDN or WAF) made the provider retry every event until it disabled the endpoint. Names outside `tchar` are still rejected, and that rejection still maps to 503. An all-digit name stays an int key in `headers()` and `sanitizedHeaders()` (PHP cannot hold `"123"` as a string key; the `HeaderMap` type says `int|non-empty-string`), and `sanitizedHeaders()` redacts by the name cast to a string — it threw `TypeError` on such a name, which a webhook wrapper that logs the sanitized headers turned into the same 503 loop.
- **Security:** `AuthContext` no longer leaks the credential it carries. `serialize()`/`unserialize()` throw `\LogicException` (the README always called it "not a serializable credentials DTO", but it serialized with the secret in clear), `__debugInfo()` redacts header values for `var_dump()`/`print_r()` and, because it returns them under the private property's mangled name, for Symfony VarDumper (`dump()`/`dd()`, which overlays `__debugInfo()` on an `(array)` cast — a plain `headers` key would have been shown beside the clear one; checked with var-dumper 8.2), and the credential parameters of the constructor, `bearer()`, `basic()` and `fromHeaders()` carry `#[\SensitiveParameter]`, so a factory that throws on one bad header no longer puts the others into the stack trace. `var_export()` and an `(array)` cast still read the private property. The adapters' own credential objects already did this; they all hand their secret to `AuthContext`, which did not.
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
