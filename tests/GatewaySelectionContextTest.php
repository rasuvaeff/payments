<?php

declare(strict_types=1);

namespace Rasuvaeff\Payments\Tests;

use Rasuvaeff\Payments\GatewaySelectionContext;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Expect;
use Testo\Test;

#[Test]
#[Covers(GatewaySelectionContext::class)]
final class GatewaySelectionContextTest
{
    #[DataProvider('invalidValueProvider')]
    public function rejectsInvalidOptionalRoutingInputs(?string $tenantId, ?string $riskLevel, string $message): void
    {
        Expect::exception(\InvalidArgumentException::class)->withMessage($message);
        new GatewaySelectionContext(
            request: PaymentFixtures::createRequest(),
            tenantId: $tenantId,
            riskLevel: $riskLevel,
        );
    }

    /** @return iterable<string, array{?string, ?string, string}> */
    public static function invalidValueProvider(): iterable
    {
        yield 'blank tenant' => [' ', null, 'Tenant id must be non-blank and at most 255 bytes'];
        yield 'long tenant' => [str_repeat('t', 256), null, 'Tenant id must be non-blank and at most 255 bytes'];
        yield 'long risk' => [null, str_repeat('r', 256), 'Risk level must be non-blank and at most 255 bytes'];
        yield 'blank risk' => [null, ' ', 'Risk level must be non-blank and at most 255 bytes'];
    }

    public function acceptsBoundaryLengthRoutingInputs(): void
    {
        $context = new GatewaySelectionContext(
            request: PaymentFixtures::createRequest(),
            tenantId: str_repeat('t', 255),
            riskLevel: 'high',
        );

        Assert::same($context->tenantId, str_repeat('t', 255));
        Assert::same($context->riskLevel, 'high');
    }
}
