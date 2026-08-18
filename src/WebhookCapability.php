<?php

declare(strict_types=1);

namespace Rasuvaeff\Payments;

/**
 * @api
 */
final readonly class WebhookCapability implements Capability
{
    public function __construct(public bool $signatureRequired = true) {}
}
