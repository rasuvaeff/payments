# Security and retention

What `rasuvaeff/payments` guarantees, what it deliberately does not, and how
long each kind of data may live on your side. Read together with
[security-retention.ru.md](security-retention.ru.md) (Russian mirror).

## Credentials

| Rule | Detail |
|---|---|
| In-memory only | `StripeCredentials`, `PayPalCredentials` and OAuth tokens live in immutable objects; core never serializes them |
| Headers, not URLs | Authentication material is passed through `AuthContext` into request headers; it never appears in a URI, query string or log line |
| No generic auth escape hatch | There is no `extra` auth bag; each adapter owns its credential class |
| SensitiveParameter | Credential constructors are marked `#[\SensitiveParameter]`, so PHP itself redacts them from stack traces |

## Card data and VOs

PAN/CVC values never enter any value object, webhook payload, diagnostic or
log. `Money` is integer minor units; the stack has no field that could carry
raw card data. If your application handles raw cards, it does so outside this
stack, under your own PCI scope.

One credential class is deliberately different: the Stripe PaymentIntent
`client_secret`. Stripe designed it for the customer's browser —
`stripe.js`/Elements needs it to confirm the payment — so exposing it to the
frontend *for that one intent* is the intended flow, and the
`rasuvaeff/payments-stripe` adapter surfaces it only through the
`ConfirmOnClient` next action while the payment still needs the customer.
Everywhere else it is treated like any credential: no logs, no persistence
beyond the checkout session, no webhook payloads or observations.

## Webhook validation

| Rule | Detail |
|---|---|
| Signature is mandatory in production | IP allow-lists are never a substitute; Stripe uses constant-time HMAC over the exact raw bytes, PayPal uses its network verification endpoint |
| Replay protection | `WebhookEventStoreInterface::claim()` is atomic and called only after validation; duplicate provider event ids return a replay outcome and never invoke mapping twice |
| Only a thrown exception releases the claim | `release()` runs when processing throws, so the provider retry can succeed. Every outcome that returns — including the terminal `RejectedWebhookEvent` and `UnsupportedWebhookEvent` — calls `complete()` instead: the verdict is final and re-processing the same bytes would only repeat it |
| Completed claims are final | `complete()` is called once the event is durably accepted. Without it a store cannot distinguish an in-flight claim from a finished one: a process killed between claim and acceptance would leave a claim that either expires (reprocessing an already-handled event) or never does (the retry gets a 204 replay outcome and the event is lost silently) |
| Unmappable payloads are terminal | A `MalformedResponseException` from a mapper becomes `RejectedWebhookEvent`: same bytes, same verdict forever, and a retry storm gets the endpoint disabled by the provider — which loses the following, healthy events |
| Signature covers the received bytes | Verification uses the exact body as received, never a decode/re-encode round trip: PHP escapes non-ASCII, renders `{}` as `[]` and drops a trailing zero, so a re-encoded document is not the one that was signed — nor the one the mapper acts on |
| Body must reach the endpoint intact | Mount the route before body-parsing middleware; a consumed stream reads back empty and is reported as the `empty_body` outcome |
| Provider event id required | A valid signature without a provider event id is rejected — it cannot be deduplicated |

## Credentials in dumps and error reports

| Rule | Detail |
|---|---|
| `#[\SensitiveParameter]` is not enough | It redacts stack traces only. Credential carriers additionally define `__debugInfo()` returning `[REDACTED]`, and refuse `__serialize()`/`__unserialize()` outright, so a dump or a cache write cannot carry the value |
| Exception chains can carry the request | `Psr18Transport` keeps the PSR-18 exception as `previous`, and some clients retain the failing request — including its `Authorization` header — on it. Configure the error reporter not to attach request objects and to scrub `Authorization` before sending |
| Provider text is bounded | Error messages from a provider are capped at 1024 bytes before they travel on inside an exception, cut back to a character boundary so the log record stays serializable |

## What crosses the durable boundary

`ObservedPaymentEvent` is the only value that may be queued or reconciled:

- scalar allow-listed payload fields only (`array<string, scalar|null>`);
- no raw body bytes, no validation headers, no `client_secret`;
- one provider across all references; refund reference required for refund
  states and forbidden for payment states.

Raw webhook bodies may be retained only for a short, configured audit window
and must be redacted (Authorization headers, signature secrets, card data)
before storage. Validation reasons never appear in controller responses —
`WebhookController` answers with a status code and the
`X-Payments-Webhook-Outcome` header only.

## Retention by data class

| Data class | Retention | Notes |
|---|---|---|
| Operational event metadata (`OperationId`, references, states, timestamps) | Documented audit period | Drives reconciliation and support |
| Sanitized `ObservedPaymentEvent` payloads | Same audit period | Scalars only |
| Raw webhook bodies + validation headers | Short window (hours–days), then deleted | Redact before writing |
| Credentials, tokens, signature secrets | Never stored | In-memory only |
| PAN/CVC | Never stored | Out of scope by design |

Retention enforcement lives in your application (persistence layer and the
future DB package); the contracts here make the boundary testable — redaction
and allow-list behaviour are covered by mutation-gated tests.

## Exceptions and diagnostics

`PaymentException` and its subclasses carry sanitized provider metadata only:
code, type, parameter and scalar `details`. `ProviderRequestInfo` may carry the
provider request id, rate-limit remaining and retry-after — never auth headers
or arbitrary response headers. A malformed provider response fails as
`MalformedResponseException` with the parse error attached, not with the raw
body echoed back.

## Identifier validation

| Surface | Check |
|---|---|
| Provider keys, currencies, header names | Whitelist patterns anchored with `\z` (a trailing newline never sneaks through) |
| Provider references (`pi_*`, `re_*`, order/capture ids) | Adapter-level grammar; core checks non-empty + maximum length |
| Webhook event ids | Non-empty, `[A-Za-z0-9_-]+`, at most 255 bytes |

## Reporting

Suspect a vulnerability in this stack? Open a private security advisory on
GitHub (Security → Report a vulnerability) instead of a public issue.
