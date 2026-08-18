<?php

declare(strict_types=1);

namespace Rasuvaeff\Payments\Tests;

use Rasuvaeff\Payments\UseSdk;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Expect;
use Testo\Test;

#[Test]
#[Covers(UseSdk::class)]
final class UseSdkTest
{
    public function carriesScalarSdkPayload(): void
    {
        $action = new UseSdk(sdkName: 'provider.js', payload: ['session_token' => 'client-token', 'attempt' => 1]);

        Assert::same($action->type(), 'use_sdk');
        Assert::same($action->payload['attempt'], 1);
    }

    public function validatesNameAndPayloadShape(): void
    {
        Expect::exception(\InvalidArgumentException::class);
        new UseSdk(sdkName: '');
    }

    public function rejectsNumericPayloadKey(): void
    {
        Expect::exception(\InvalidArgumentException::class);
        new UseSdk(sdkName: 'provider.js', payload: [0 => 'value']);
    }

    public function rejectsNonScalarPayloadValue(): void
    {
        Expect::exception(\InvalidArgumentException::class);
        new UseSdk(sdkName: 'provider.js', payload: ['nested' => []]);
    }

    public function rejectsEmptyPayloadKey(): void
    {
        Expect::exception(\InvalidArgumentException::class);
        new UseSdk(sdkName: 'provider.js', payload: ['' => 'value']);
    }

    public function enforcesNameLengthBoundary(): void
    {
        Assert::same(strlen((new UseSdk(sdkName: str_repeat('x', 128)))->sdkName), 128);

        Expect::exception(\InvalidArgumentException::class);
        new UseSdk(sdkName: str_repeat('x', 129));
    }
}
