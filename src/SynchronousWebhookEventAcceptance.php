<?php

declare(strict_types=1);

namespace Rasuvaeff\Payments;

/**
 * Completes reconciliation before allowing an acknowledgement.
 *
 * @api
 */
final readonly class SynchronousWebhookEventAcceptance implements WebhookEventAcceptanceInterface
{
    public function __construct(private WebhookReconcilerInterface $reconciler) {}

    #[\Override]
    public function policy(): WebhookAcknowledgementPolicy
    {
        return WebhookAcknowledgementPolicy::AfterPersistence;
    }

    #[\Override]
    public function accept(ObservedPaymentEvent $event): void
    {
        $this->reconciler->reconcile(event: $event);
    }
}
