<?php

declare(strict_types=1);

namespace Rasuvaeff\Payments\Tests;

use Rasuvaeff\Payments\WebhookCapability;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

#[Test]
#[Covers(WebhookCapability::class)]
final class WebhookCapabilityTest
{
    public function defaultsToMandatorySignatureValidation(): void
    {
        Assert::true((new WebhookCapability())->signatureRequired);
    }
}
