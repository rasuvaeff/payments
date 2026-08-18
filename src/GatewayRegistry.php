<?php

declare(strict_types=1);

namespace Rasuvaeff\Payments;

/**
 * @api
 */
final readonly class GatewayRegistry
{
    /** @var array<non-empty-string, PaymentGatewayInterface> */
    private array $gateways;

    /**
     * @param iterable<PaymentGatewayInterface> $gateways
     */
    public function __construct(iterable $gateways = [])
    {
        $indexed = [];

        foreach ($gateways as $gateway) {
            $key = $gateway->provider()->value;

            if (isset($indexed[$key])) {
                throw new \InvalidArgumentException(sprintf('Duplicate payment gateway for provider "%s"', $key));
            }

            $indexed[$key] = $gateway;
        }

        $this->gateways = $indexed;
    }

    public function has(PaymentProvider $provider): bool
    {
        return isset($this->gateways[$provider->value]);
    }

    public function get(PaymentProvider $provider): PaymentGatewayInterface
    {
        return $this->gateways[$provider->value]
            ?? throw new \OutOfBoundsException(sprintf('Payment gateway "%s" is not registered', $provider->value));
    }

    /**
     * @template T of Capability
     * @param class-string<T> $capability
     * @return T|null
     */
    public function capability(PaymentProvider $provider, string $capability): ?Capability
    {
        return $this->get(provider: $provider)->capabilities()->get(class: $capability);
    }

    /** @return list<PaymentProvider> */
    public function providers(): array
    {
        return array_map(
            static fn(PaymentGatewayInterface $gateway): PaymentProvider => $gateway->provider(),
            array_values($this->gateways),
        );
    }

    /** @return list<PaymentGatewayInterface> */
    public function all(): array
    {
        return array_values($this->gateways);
    }
}
