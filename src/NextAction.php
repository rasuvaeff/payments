<?php

declare(strict_types=1);

namespace Rasuvaeff\Payments;

/**
 * Open extension point for provider-specific customer actions.
 *
 * @api
 */
interface NextAction
{
    public function type(): string;
}
