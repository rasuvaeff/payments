<?php

declare(strict_types=1);

namespace Rasuvaeff\Payments;

/**
 * Completes phase one by durably enqueueing a sanitized observation.
 *
 * @api
 */
final readonly class QueuedWebhookEventAcceptance implements WebhookEventAcceptanceInterface
{
    public function __construct(private WebhookEventQueueInterface $queue) {}

    #[\Override]
    public function policy(): WebhookAcknowledgementPolicy
    {
        return WebhookAcknowledgementPolicy::AfterValidation;
    }

    #[\Override]
    public function accept(ObservedPaymentEvent $event): void
    {
        // Positional on purpose: the parameter name belongs to the
        // implementation, and a named argument would break an implementor
        // that calls it anything else.
        $this->queue->enqueue($event);
    }
}
