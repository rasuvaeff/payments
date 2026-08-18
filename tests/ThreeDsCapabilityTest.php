<?php

declare(strict_types=1);

namespace Rasuvaeff\Payments\Tests;

use Rasuvaeff\Payments\ThreeDsCapability;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Expect;
use Testo\Test;

#[Test]
#[Covers(ThreeDsCapability::class)]
final class ThreeDsCapabilityTest
{
    public function preservesSupportedVersions(): void
    {
        Assert::same((new ThreeDsCapability(versions: ['2.1.0']))->versions, ['2.1.0']);
    }

    public function rejectsEmptyVersion(): void
    {
        Expect::exception(\InvalidArgumentException::class);
        new ThreeDsCapability(versions: ['2.1.0', '']);
    }

    public function rejectsKeyedVersionArray(): void
    {
        Expect::exception(\InvalidArgumentException::class);
        new ThreeDsCapability(versions: ['v2' => '2.1.0']);
    }
}
