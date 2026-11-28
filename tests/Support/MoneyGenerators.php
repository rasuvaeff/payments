<?php

declare(strict_types=1);

namespace Rasuvaeff\Payments\Tests\Support;

use Rasuvaeff\Payments\Money;
use Rasuvaeff\PropertyTesting\ArbitraryInterface;
use Rasuvaeff\PropertyTesting\Gen;

/**
 * Amounts of a few currencies, small enough that sums never overflow, and
 * drawn from few currencies so that equal currencies are common.
 */
final class MoneyGenerators
{
    /** @return ArbitraryInterface<Money> */
    public static function money(): ArbitraryInterface
    {
        return Gen::map(
            Gen::tuple(Gen::intBetween(0, 1_000_000), Gen::elements(['EUR', 'USD', 'JPY'])),
            static fn(array $pair): Money => new Money(minorUnits: $pair[0], currency: $pair[1]),
        );
    }

    /** @return ArbitraryInterface<Money> */
    public static function euros(): ArbitraryInterface
    {
        return Gen::map(Gen::intBetween(0, 1_000_000), static fn(int $amount): Money => new Money(minorUnits: $amount, currency: 'EUR'));
    }

    /** @return array<string, ArbitraryInterface> */
    public static function threeEuros(): array
    {
        return ['a' => self::euros(), 'b' => self::euros(), 'c' => self::euros()];
    }

    /** @return array<string, ArbitraryInterface> */
    public static function twoAmounts(): array
    {
        return ['a' => self::money(), 'b' => self::money()];
    }

    private function __construct() {}
}
