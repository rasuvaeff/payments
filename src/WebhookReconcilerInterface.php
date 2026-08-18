<?php

declare(strict_types=1);

namespace Rasuvaeff\Payments;

/**
 * Re-fetches authoritative state and persists the resulting projection.
 *
 * @api
 */
interface WebhookReconcilerInterface
{
    public function reconcile(ObservedPaymentEvent $event): void;
}
