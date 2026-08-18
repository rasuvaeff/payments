<?php

declare(strict_types=1);

namespace Rasuvaeff\Payments\Tests;

use Rasuvaeff\Payments\SandboxCapability;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Expect;
use Testo\Test;

#[Test]
#[Covers(SandboxCapability::class)]
final class SandboxCapabilityTest
{
    public function exposesEnvironmentMetadata(): void
    {
        Assert::same((new SandboxCapability())->environment, 'sandbox');
    }

    public function rejectsEmptyEnvironment(): void
    {
        Expect::exception(\InvalidArgumentException::class);
        new SandboxCapability(environment: '');
    }
}
