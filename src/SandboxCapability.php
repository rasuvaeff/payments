<?php

declare(strict_types=1);

namespace Rasuvaeff\Payments;

/**
 * @api
 */
final readonly class SandboxCapability implements Capability
{
    /** @var non-empty-string */
    public string $environment;

    public function __construct(string $environment = 'sandbox')
    {
        if ($environment === '') {
            throw new \InvalidArgumentException('Sandbox environment cannot be empty');
        }

        $this->environment = $environment;
    }
}
