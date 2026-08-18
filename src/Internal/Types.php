<?php

declare(strict_types=1);

namespace Rasuvaeff\Payments\Internal;

/**
 * Psalm type aliases shared across the contracts, transport and webhook primitives.
 *
 * @internal
 *
 * @psalm-type ScalarMap = array<non-empty-string, scalar|null>
 * @psalm-type NonEmptyId = non-empty-string
 * @psalm-type HeaderMap = array<non-empty-string, list<string>>
 * @psalm-type AuthHeaderMap = array<non-empty-string, non-empty-string>
 * @psalm-type RequestMetadata = array<non-empty-string, scalar|null>
 */
final class Types
{
    private function __construct() {}
}
