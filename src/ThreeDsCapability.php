<?php

declare(strict_types=1);

namespace Rasuvaeff\Payments;

/**
 * @api
 */
final readonly class ThreeDsCapability implements Capability
{
    /** @var list<non-empty-string> */
    public array $versions;

    /**
     * @param array<array-key, string> $versions
     */
    public function __construct(array $versions = [])
    {
        if (!array_is_list($versions)) {
            throw new \InvalidArgumentException('3-D Secure versions must be a list');
        }

        $validated = [];

        foreach ($versions as $version) {
            if ($version === '') {
                throw new \InvalidArgumentException('3-D Secure version cannot be empty');
            }

            $validated[] = $version;
        }

        $this->versions = $validated;
    }
}
