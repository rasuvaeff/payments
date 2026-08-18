<?php

declare(strict_types=1);

namespace Rasuvaeff\Payments\Tests;

use Nyholm\Psr7\Factory\Psr17Factory;
use Rasuvaeff\Payments\AuthContext;
use Rasuvaeff\Payments\JsonRequestBuilder;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

#[Test]
#[Covers(JsonRequestBuilder::class)]
final class EmptyJsonRequestBuilderTest
{
    public function encodesEmptyObjectShapeAsJsonObject(): void
    {
        $factory = new Psr17Factory();
        $request = (new JsonRequestBuilder($factory, $factory))->build(
            method: 'POST',
            uri: 'https://api.example.test/capture',
            data: [],
            auth: AuthContext::fromHeaders([]),
        );

        Assert::same((string) $request->getBody(), '{}');
    }
}
