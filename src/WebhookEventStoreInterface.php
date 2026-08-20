<?php

declare(strict_types=1);

namespace Rasuvaeff\Payments;

/**
 * Atomically claims provider event identifiers for replay protection.
 *
 * The three calls form one lifecycle per provider event id:
 *
 * ```text
 * claim() -> token ->  processing  ->  complete(token)  (terminal: never replayed)
 *                                  \-> release(token)   (retryable: may be claimed again)
 * claim() -> null   ->  already claimed by a completed or in-flight attempt
 * ```
 *
 * `claim()` returns a fresh `WebhookClaimToken` with every successful claim.
 * The token is the fencing device: once a lease expires and another worker
 * takes the claim over, the stored token changes, and every finaliser call
 * carrying the old token is a no-op. Without that fence a stalled worker
 * waking up after a takeover could `complete()` an already-final row or —
 * worse — `release()` a live claim, deleting the new owner's reservation and
 * letting a third delivery start processing concurrently.
 *
 * `complete()` exists because a process can die between `claim()` and the end
 * of processing without ever reaching `release()` — SIGKILL, the OOM killer,
 * `max_execution_time`, a fatal error, a pod restart. Without a completion
 * signal an implementation cannot tell an in-flight claim from a finished one,
 * and both available choices are wrong: expire claims and an already-processed
 * event is reprocessed on the next provider retry; never expire them and the
 * interrupted event is answered with a replay outcome — HTTP 204 — so the
 * provider stops retrying and the event is lost with no error anywhere.
 *
 * With `complete()` the rule is unambiguous: a claim that was never completed
 * and whose lease expired is stale and must be reclaimable; a completed claim
 * must never be handed out again.
 *
 * Implementations that keep the durable queue in the same database can, as an
 * alternative, commit the claim row and the queue row in one transaction; then
 * "a claim exists" already implies "the event is durable". That works only for
 * a same-database queue — see the storage section of the README.
 *
 * @api
 */
interface WebhookEventStoreInterface
{
    /**
     * Atomically reserves the event id and returns a token proving ownership
     * of the claim. Returns null when the id is already reserved by a
     * completed attempt or by a live (non-expired) claim.
     *
     * @param non-empty-string $providerEventId
     */
    public function claim(PaymentProvider $provider, string $providerEventId): ?WebhookClaimToken;

    /**
     * Marks the claim final: the event was durably accepted and must never be
     * processed again, however long the record lives. A no-op when the token
     * no longer matches the stored one — the claim was revoked or taken over.
     *
     * @param non-empty-string $providerEventId
     */
    public function complete(PaymentProvider $provider, string $providerEventId, WebhookClaimToken $token): void;

    /**
     * Releases an incomplete claim after a retryable processing failure. A
     * no-op when the token no longer matches the stored one, so a worker
     * whose lease expired cannot delete a claim another worker now owns.
     *
     * @param non-empty-string $providerEventId
     */
    public function release(PaymentProvider $provider, string $providerEventId, WebhookClaimToken $token): void;
}
