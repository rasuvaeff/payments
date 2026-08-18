<?php

declare(strict_types=1);

namespace Rasuvaeff\Payments;

/**
 * Application-owned policy. The package intentionally provides no implicit default.
 *
 * @api
 */
interface GatewaySelectionPolicyInterface
{
    /**
     * @param list<PaymentProvider> $availableProviders
     */
    public function select(GatewaySelectionContext $context, array $availableProviders): PaymentProvider;
}
