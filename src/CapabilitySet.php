<?php

declare(strict_types=1);

namespace Rasuvaeff\Payments;

/**
 * Immutable set of typed capability values.
 *
 * @api
 */
final readonly class CapabilitySet
{
    /**
     * @param list<Capability> $capabilities
     */
    private function __construct(private array $capabilities) {}

    public static function of(Capability ...$capabilities): self
    {
        /** @var array<class-string<Capability>, Capability> $seen */
        $seen = [];

        foreach ($capabilities as $capability) {
            $class = $capability::class;

            if (isset($seen[$class])) {
                throw new \InvalidArgumentException('Capability set cannot contain duplicate capability types');
            }

            $seen[$class] = $capability;
        }

        /** @var list<Capability> $capabilities */
        return new self(capabilities: $capabilities);
    }

    /**
     * @template T of Capability
     * @param class-string<T> $class
     * @return T|null
     */
    public function get(string $class): ?Capability
    {
        foreach ($this->capabilities as $capability) {
            if ($capability instanceof $class) {
                /** @var T $capability */
                return $capability;
            }
        }

        return null;
    }

    /**
     * @param class-string<Capability> $class
     */
    public function has(string $class): bool
    {
        return $this->get($class) instanceof Capability;
    }

    /**
     * @return list<Capability>
     */
    public function all(): array
    {
        return $this->capabilities;
    }
}
