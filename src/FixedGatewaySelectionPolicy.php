<?php

declare(strict_types=1);

namespace Rasuvaeff\Payments;

/**
 * Explicit fixed-provider policy for applications that do not need dynamic routing.
 *
 * @api
 */
final readonly class FixedGatewaySelectionPolicy implements GatewaySelectionPolicyInterface
{
    public function __construct(private PaymentProvider $provider) {}

    #[\Override]
    public function select(GatewaySelectionContext $context, array $availableProviders): PaymentProvider
    {
        foreach ($availableProviders as $provider) {
            if ($provider == $this->provider) {
                return $this->provider;
            }
        }

        throw new \OutOfBoundsException(sprintf('Fixed payment provider "%s" is not available', $this->provider->value));
    }
}
