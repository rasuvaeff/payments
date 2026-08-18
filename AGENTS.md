# AGENTS.md — payments

Guidance for AI agents working on this package. Read before changing code.

## What this is

`rasuvaeff/payments` is the framework-free foundation for payment gateway
adapters. Its public namespace is `Rasuvaeff\Payments` and it covers four
layers:

- **Contracts**: `Money` (integer minor units), `OperationId`,
  `PaymentProvider`, payment/refund/customer/payment-method references,
  `PaymentIntent`/`PaymentAttempt`/`RefundAttempt`, `PaymentState`/`RefundState`,
  operation request DTOs, `PaymentGatewayInterface` plus optional ISP interfaces
  (`CaptureGatewayInterface`, `ConfirmGatewayInterface`, `CancelGatewayInterface`,
  `RefundGatewayInterface`), typed `Capability`/`CapabilitySet`, `NextAction`
  implementations, `ObservedPaymentEvent`, `PaymentFailure`,
  `ProviderRequestInfo`.
- **Routing**: `GatewayRegistry`, application-owned
  `GatewaySelectionPolicyInterface` (+ `FixedGatewaySelectionPolicy`),
  `GatewaySelectionContext`, `PaymentGatewayRouter`.
- **Transport**: PSR-18 transport, JSON/form request builders, response
  decoding, typed payment exceptions, `AuthContext`.
- **Webhooks**: validation, replay protection, durable acceptance
  (`WebhookProcessor`), plus `WebhookProcessorRegistry`,
  `WebhookProcessorRegistration` and the PSR-7 `WebhookController`.

The former `rasuvaeff/payments-contracts` package was merged in here; it no
longer exists separately and must not be referenced in docs or composer.json.

## Golden rules

1. **Verification is mandatory.** Never claim "done" without a fresh green
   `composer build`. "Should work" does not count.
2. **No suppressions.** No `@psalm-suppress`, no baseline. Fix the root cause.
3. **Auth stays in memory.** Never serialize, log or put credentials in URLs;
   builders accept only an `AuthContext` of already-selected headers.
4. **Preserve the public contract.** Update README + tests with any API change.

## Commands

No PHP/Composer on the host — run in Docker via the `composer:2` image.

```bash
docker run --rm -v "$PWD":/app -w /app composer:2 composer build
docker run --rm -v "$PWD":/app -w /app composer:2 composer cs:fix
docker run --rm -v "$PWD":/app -w /app composer:2 composer psalm
docker run --rm -v "$PWD":/app -w /app composer:2 composer test
docker run --rm -v "$PWD":/app -w /app composer:2 composer release-check
```

Or with Make:

```bash
make build
make cs-fix
make psalm
make test
make test-coverage
make mutation
make release-check
```

`composer.lock` is gitignored (library).
`make test-coverage` and `make mutation` bootstrap `pcov` inside the
`composer:2` container because the base image has no coverage driver.

## Invariants & gotchas

- **`Money` is integer minor units only.** Non-negative amount + `/^[A-Z]{3}\z/`
  currency; arithmetic requires matching currencies, throws on overflow and on
  negative results, and `multiply()` takes an integer numerator/denominator with
  half-up rounding. Never introduce float math or float-accepting factories.
- **`PaymentIntent` vs `PaymentAttempt`.** The intent is the application-owned
  aggregate (id, amount, attempts with matching currency); an attempt is one
  provider execution carrying `OperationId`, provider-matched references,
  `rawStatus` and `ProviderRequestInfo`. Do not blur the two or add provider
  fields to the intent.
- **`PaymentState::canTransitionTo()` is advisory.** It documents the expected
  flow; it must never be used to discard an accepted webhook observation — the
  provider is authoritative.
- **Terminal states must not absorb recoverable provider statuses.**
  `Succeeded`, `Failed` and `Canceled` return `false` from every outgoing
  transition. So a provider status the customer can still rescue may not map to
  them: Stripe's `requires_payment_method` maps to `RequiresPaymentMethod`,
  because the same intent reaches `succeeded` after a second card. Mapping it to
  `Failed` made a consumer gating on `canTransitionTo()` drop the later success
  — the reason that state exists. Check this before adding any status mapping.
- **`ObservedPaymentEvent` is not authoritative state.** It is a sanitized,
  durable observation: scalar allow-listed payload only, one provider across all
  references, refund reference required for `RefundState` and forbidden for
  `PaymentState`. Reconciliation re-fetches authoritative provider state.
- Optional gateway operations live in ISP interfaces, never in
  `PaymentGatewayInterface`; `PaymentGatewayRouter` checks `instanceof` and
  throws `\LogicException` for unsupported operations. Keep capability claims
  (`CapabilitySet`) and interface implementations consistent in adapters.
- The selection policy chooses a provider **only for creation**; every other
  router operation routes by the provider embedded in the reference. The
  package ships no implicit default policy — `FixedGatewaySelectionPolicy` is
  the explicit opt-in.
- `GatewayRegistry` and `WebhookProcessorRegistry` reject duplicate providers
  at construction and throw `\OutOfBoundsException` on unknown lookups.
- `WebhookController` never exposes provider payloads or validation reasons in
  responses — only a status code and the `X-Payments-Webhook-Outcome` header.
  Processing exceptions map to 503 (retryable), foreign result types to 500.
- Response decoding preserves provider error metadata while exposing only
  sanitized scalar details, capped at 1024 bytes and cut back to a character
  boundary so a truncated message cannot break log serialization.
- **A value bound for an HTTP header is validated as a header token.**
  `idempotencyKey` uses `Assert::headerToken()` — no control bytes, no spaces.
  The failure it prevents is not injection (PSR-7 rejects CRLF) but a transport
  error that names neither the field nor the request.
- Webhooks are validated before `claim()`. Mapping runs at most once for an
  accepted provider event id; incomplete failures release their claim.
- **The event store contract is three calls, and `complete()` is load-bearing.**
  `claim()` reserves, `complete()` finalises after durable acceptance,
  `release()` makes a failure retryable. Without a completion signal an
  implementation cannot separate an in-flight claim from a finished one, and a
  process killed between claim and acceptance either gets reprocessed (short
  lease) or is answered with a replay 204 forever and lost silently (no lease).
  Every outcome `process()` returns — including the ignoring ones — completes
  the claim; only a thrown exception releases it. Do not add a return path that
  skips both.
- **Permanent mapping failures are terminal, not retryable.**
  `UnsupportedWebhookEventException` and `MalformedResponseException` from a
  mapper become `UnsupportedWebhookEvent` / `RejectedWebhookEvent` and are
  acknowledged. The same bytes yield the same verdict forever, and a retry storm
  gets the endpoint disabled by the provider, which loses the *following*,
  healthy events. Only infrastructure failures (transport, database, queue) may
  propagate.
- `AfterValidation` still requires durable queue acceptance. `AfterPersistence`
  requires authoritative re-fetch and persistence before acknowledgement.
- Raw webhook bodies and validation headers never cross the durable boundary;
  only sanitized `ObservedPaymentEvent` values may be queued or reconciled.
- **`WebhookValidationResult` is closed, `WebhookProcessingResult` is open.**
  `WebhookValidatorInterface::validate()` declares the native union
  `ValidWebhook|InvalidWebhook`; PHP enforces it, which is why
  `WebhookProcessor` reads `ValidWebhook::$providerEventId` with no runtime
  guard. Do not relax that union — a third answer to "is this signature
  authentic" does not exist, and a consumer cannot patch vendor code to add one.
  `WebhookProcessorInterface::process()` deliberately returns the open interface
  so a decorating processor can short-circuit with its own outcome; only the
  concrete `WebhookProcessor` narrows to the six shipped ones. Adding an
  outcome here means updating that narrowed union, the README tables (EN + RU)
  and `llms.txt` in the same change.
- Code: `declare(strict_types=1)`, `final readonly class`, `#[\Override]`,
  explicit types.
- **Validation patterns anchor with `\z`, never `$`.** In PCRE `$` also matches
  immediately before a trailing newline, so `/^…$/` silently accepts one `\n` —
  the value the whitelist was supposed to reject then travels on as if it were
  clean. `/D` does the same job. Cover it with a data-provider case carrying
  `"value\n"`, or the regression comes back unnoticed. This applies to every
  identity value object (`PaymentProvider`, `Money` currency, header-name
  checks in `WebhookInput`), not just webhook code.
- `examples/` is part of the public contract: keep scripts runnable and update
  `examples/README.md` when example usage changes.
- **CI workflows are SHA-pinned.** Every `uses:` in `.github/workflows/*.yml`
  references a 40-char commit SHA with a `# vN` trailing comment
  (e.g. `actions/checkout@<sha> # v4`). Never revert to floating `@vN` tags.
  Updates go through Dependabot, which bumps the SHA and preserves the comment.
  Workflows also carry `permissions: { contents: read }` at workflow level and
  `persist-credentials: false` on every `actions/checkout` step. Verify with
  `zizmor --persona=auditor .github/` — must report no `unpinned-uses`,
  `excessive-permissions`, or `artipacked` findings.

## When you finish

- Update `README.md` and `README.ru.md` together (and `examples/` if usage
  changed); update `CHANGELOG.md` when releasing.
- Re-run `composer build`; if the change affects the public API or release
  process, also run `make release-check`. Paste the output.
