<?php

declare(strict_types=1);

namespace Rasuvaeff\Payments\Tests;

use Rasuvaeff\Payments\ProviderRequestInfo;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Expect;
use Testo\Test;

#[Test]
#[Covers(ProviderRequestInfo::class)]
final class ProviderRequestInfoTest
{
    public function storesAllowListedDiagnostics(): void
    {
        $info = new ProviderRequestInfo(
            receivedAt: new \DateTimeImmutable(),
            requestId: 'req_1',
            rateLimitRemaining: 19,
            retryAfterSeconds: 3,
        );

        Assert::same($info->requestId, 'req_1');
        Assert::same($info->rateLimitRemaining, 19);
        Assert::same($info->retryAfterSeconds, 3);
    }

    public function allowsAbsentDiagnostics(): void
    {
        $info = new ProviderRequestInfo(receivedAt: new \DateTimeImmutable());

        Assert::null($info->requestId);
        Assert::null($info->rateLimitRemaining);
        Assert::null($info->retryAfterSeconds);
    }

    public function rejectsNegativeRetryAfter(): void
    {
        Expect::exception(\InvalidArgumentException::class);
        new ProviderRequestInfo(receivedAt: new \DateTimeImmutable(), retryAfterSeconds: -1);
    }

    public function acceptsZeroLimits(): void
    {
        $info = new ProviderRequestInfo(receivedAt: new \DateTimeImmutable(), rateLimitRemaining: 0, retryAfterSeconds: 0);

        Assert::same($info->rateLimitRemaining, 0);
        Assert::same($info->retryAfterSeconds, 0);
    }

    public function rejectsNegativeRateLimit(): void
    {
        Expect::exception(\InvalidArgumentException::class);
        new ProviderRequestInfo(receivedAt: new \DateTimeImmutable(), rateLimitRemaining: -1);
    }

    public function rejectsEmptyRequestId(): void
    {
        Expect::exception(\InvalidArgumentException::class);
        new ProviderRequestInfo(receivedAt: new \DateTimeImmutable(), requestId: '');
    }
}
