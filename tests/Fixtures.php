<?php

declare(strict_types=1);

namespace Rasuvaeff\Payments\Tests;

use Rasuvaeff\Payments\Money;
use Rasuvaeff\Payments\OperationId;
use Rasuvaeff\Payments\PaymentProvider;
use Rasuvaeff\Payments\PaymentReference;
use Rasuvaeff\Payments\PaymentState;
use Rasuvaeff\Payments\ProviderRequestInfo;

final class Fixtures
{
    public static function provider(): PaymentProvider
    {
        return new PaymentProvider(value: 'stripe');
    }

    public static function payment(): PaymentReference
    {
        return new PaymentReference(provider: self::provider(), id: 'pi_123', kind: 'payment_intent');
    }

    public static function money(int $minorUnits = 1_000): Money
    {
        return new Money(minorUnits: $minorUnits, currency: 'EUR');
    }

    public static function requestInfo(): ProviderRequestInfo
    {
        return new ProviderRequestInfo(receivedAt: new \DateTimeImmutable('2026-01-01T00:00:00+00:00'));
    }

    public static function operation(): OperationId
    {
        return new OperationId(value: 'checkout-123');
    }

    public static function state(): PaymentState
    {
        return PaymentState::Succeeded;
    }

    private function __construct() {}
}
