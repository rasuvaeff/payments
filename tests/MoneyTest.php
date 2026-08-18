<?php

declare(strict_types=1);

namespace Rasuvaeff\Payments\Tests;

use Rasuvaeff\Payments\Money;
use Rasuvaeff\PropertyTesting\Property;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Expect;
use Testo\Test;

#[Test]
#[Covers(Money::class)]
final class MoneyTest
{
    public function validatesCurrencyAndAmount(): void
    {
        Expect::exception(\InvalidArgumentException::class)->withMessageContaining('minor units');
        new Money(minorUnits: -1, currency: 'EUR');
    }

    #[DataProvider('invalidCurrencyProvider')]
    public function rejectsInvalidCurrency(string $currency): void
    {
        Expect::exception(\InvalidArgumentException::class);
        new Money(minorUnits: 1, currency: $currency);
    }

    public static function invalidCurrencyProvider(): iterable
    {
        yield 'lowercase' => ['eur'];
        yield 'wrong length' => ['EURO'];
        yield 'trailing newline' => ["EUR\n"];
    }

    public function addsAndSubtractsSameCurrency(): void
    {
        $money = new Money(minorUnits: 500, currency: 'EUR');

        Assert::same($money->add(new Money(minorUnits: 250, currency: 'EUR'))->minorUnits, 750);
        Assert::same($money->subtract(new Money(minorUnits: 250, currency: 'EUR'))->minorUnits, 250);
    }

    public function rejectsCurrencyMismatchAndNegativeResult(): void
    {
        Expect::exception(\InvalidArgumentException::class)->withMessageContaining('currencies');
        (new Money(minorUnits: 1, currency: 'EUR'))->add(new Money(minorUnits: 1, currency: 'USD'));
    }

    public function rejectsCurrencyMismatchOnSubtraction(): void
    {
        Expect::exception(\InvalidArgumentException::class)->withMessageContaining('currencies');
        (new Money(minorUnits: 2, currency: 'EUR'))->subtract(new Money(minorUnits: 1, currency: 'USD'));
    }

    public function rejectsNegativeSubtractionResult(): void
    {
        Expect::exception(\InvalidArgumentException::class)->withMessageContaining('Money subtraction cannot');
        (new Money(minorUnits: 1, currency: 'EUR'))->subtract(new Money(minorUnits: 2, currency: 'EUR'));
    }

    public function detectsAdditionOverflowButAcceptsMaximum(): void
    {
        $maximum = new Money(minorUnits: PHP_INT_MAX, currency: 'EUR');

        Assert::same($maximum->add(new Money(minorUnits: 0, currency: 'EUR'))->minorUnits, PHP_INT_MAX);

        Expect::exception(\OverflowException::class);
        $maximum->add(new Money(minorUnits: 1, currency: 'EUR'));
    }

    #[DataProvider('roundingProvider')]
    public function multipliesWithIntegerHalfUpRounding(int $amount, int $numerator, int $denominator, int $expected): void
    {
        Assert::same((new Money(minorUnits: $amount, currency: 'EUR'))->multiply($numerator, $denominator)->minorUnits, $expected);
    }

    public static function roundingProvider(): iterable
    {
        yield 'down' => [5, 1, 2, 3];
        yield 'up' => [7, 1, 2, 4];
        yield 'exact' => [10, 1, 2, 5];
        yield 'exact half rounds up' => [1, 1, 2, 1];
        yield 'odd denominator half boundary' => [3, 1, 5, 1];
        yield 'odd denominator above boundary' => [4, 1, 5, 1];
        yield 'odd denominator composite' => [9, 2, 5, 4];
    }

    public function rejectsInvalidMultiplier(): void
    {
        Expect::exception(\InvalidArgumentException::class);
        (new Money(minorUnits: 1, currency: 'EUR'))->multiply(numerator: 1, denominator: 0);
    }

    public function rejectsNegativeNumeratorAndDenominator(): void
    {
        Expect::exception(\InvalidArgumentException::class)->withMessageContaining('numerator');
        (new Money(minorUnits: 1, currency: 'EUR'))->multiply(numerator: -1);
    }

    public function rejectsNegativeDenominator(): void
    {
        Expect::exception(\InvalidArgumentException::class)->withMessageContaining('denominator');
        (new Money(minorUnits: 1, currency: 'EUR'))->multiply(numerator: 1, denominator: -1);
    }

    public function supportsLargeIntermediateWithoutFloat(): void
    {
        $result = (new Money(minorUnits: PHP_INT_MAX, currency: 'EUR'))->multiply(numerator: 1, denominator: 2);

        Assert::same($result->minorUnits, intdiv(PHP_INT_MAX, 2) + 1);
    }

    public function handlesExactRemainderCarry(): void
    {
        Assert::same((new Money(minorUnits: 1, currency: 'EUR'))->multiply(numerator: 2, denominator: 2)->minorUnits, 1);
        Assert::same((new Money(minorUnits: 1, currency: 'EUR'))->multiply(numerator: 6, denominator: 2)->minorUnits, 3);
    }

    public function acceptsMaximumRepresentableProduct(): void
    {
        Assert::same(
            (new Money(minorUnits: PHP_INT_MAX, currency: 'EUR'))->multiply(numerator: PHP_INT_MAX, denominator: PHP_INT_MAX)->minorUnits,
            PHP_INT_MAX,
        );
    }

    public function detectsMultiplicationOverflow(): void
    {
        Expect::exception(\OverflowException::class);
        (new Money(minorUnits: PHP_INT_MAX, currency: 'EUR'))->multiply(numerator: 2);
    }

    public function detectsOverflowAfterCarryAtMaximum(): void
    {
        Expect::exception(\OverflowException::class);
        (new Money(minorUnits: PHP_INT_MAX, currency: 'EUR'))->multiply(
            numerator: PHP_INT_MAX,
            denominator: PHP_INT_MAX - 1,
        );
    }

    public function handlesSeveralBinaryMultiplierBits(): void
    {
        Assert::same((new Money(minorUnits: 7, currency: 'EUR'))->multiply(numerator: 4, denominator: 2)->minorUnits, 14);
    }

    public function exposesFactoryAndZeroPredicate(): void
    {
        Assert::true(Money::minorUnits(amount: 0, currency: 'EUR')->isZero());
        Assert::false(Money::minorUnits(amount: 1, currency: 'EUR')->isZero());
    }

    /**
     * @param int<0, 1000000> $amount
     */
    #[Property(runs: 100, auto: true)]
    public function zeroIsIdentityForAddition(int $amount): void
    {
        $money = new Money(minorUnits: $amount, currency: 'EUR');

        Assert::same($money->add(new Money(minorUnits: 0, currency: 'EUR'))->minorUnits, $amount);
    }

    /**
     * @param int<0, 1000000> $left
     * @param int<0, 1000000> $right
     */
    #[Property(runs: 300, auto: true)]
    public function additionIsCommutative(int $left, int $right): void
    {
        $a = new Money(minorUnits: $left, currency: 'EUR');
        $b = new Money(minorUnits: $right, currency: 'EUR');

        Assert::same($a->add($b)->minorUnits, $b->add($a)->minorUnits);
    }

    /**
     * @param int<0, 1000000> $amount
     * @param int<0, 1000000> $delta
     */
    #[Property(runs: 300, auto: true)]
    public function additionAndSubtractionRoundTrip(int $amount, int $delta): void
    {
        $money = new Money(minorUnits: $amount, currency: 'EUR');
        $added = $money->add(new Money(minorUnits: $delta, currency: 'EUR'));

        Assert::same($added->subtract(new Money(minorUnits: $delta, currency: 'EUR'))->minorUnits, $amount);
    }

    /**
     * @param int<0, 1000000> $amount
     * @param int<0, 1000> $numerator
     * @param int<1, 1000> $denominator
     */
    #[Property(runs: 300, auto: true)]
    public function ratioUsesHalfUpRounding(int $amount, int $numerator, int $denominator): void
    {
        $actual = (new Money(minorUnits: $amount, currency: 'EUR'))->multiply($numerator, $denominator)->minorUnits;
        $expected = intdiv($amount * $numerator + intdiv($denominator, 2), $denominator);

        Assert::same($actual, $expected);
    }

    /** @return iterable<string, array{int, int, int}> */
    public static function ratioUsesHalfUpRoundingExamples(): iterable
    {
        yield 'zero amount' => [0, 999, 7];
        yield 'zero numerator' => [12345, 0, 7];
        yield 'half rounds up' => [1, 1, 2];
        yield 'exact division' => [10, 1, 2];
        yield 'denominator one' => [987654, 3, 1];
    }

    /**
     * Equality covers the currency too. Comparing minor units alone accepts
     * 1000 JPY for a 1000 USD order — which is why reconciliation is given a
     * primitive instead of being left to hand-roll one.
     */
    public function equalityComparesAmountAndCurrency(): void
    {
        $money = new Money(minorUnits: 1000, currency: 'USD');

        Assert::true($money->equals(new Money(minorUnits: 1000, currency: 'USD')));
        Assert::false($money->equals(new Money(minorUnits: 1000, currency: 'JPY')));
        Assert::false($money->equals(new Money(minorUnits: 999, currency: 'USD')));
    }

    public function comparesAmountsOfTheSameCurrency(): void
    {
        $money = new Money(minorUnits: 1000, currency: 'USD');

        Assert::true($money->isGreaterThan(new Money(minorUnits: 999, currency: 'USD')));
        Assert::false($money->isGreaterThan(new Money(minorUnits: 1000, currency: 'USD')));
        Assert::true($money->isLessThan(new Money(minorUnits: 1001, currency: 'USD')));
        Assert::false($money->isLessThan(new Money(minorUnits: 1000, currency: 'USD')));
    }

    public function refusesToOrderAmountsInDifferentCurrencies(): void
    {
        Expect::exception(\InvalidArgumentException::class)->withMessage('Money currencies must match');
        (new Money(minorUnits: 1000, currency: 'USD'))->isGreaterThan(new Money(minorUnits: 1000, currency: 'EUR'));
    }

    public function refusesToOrderLessThanAcrossCurrencies(): void
    {
        Expect::exception(\InvalidArgumentException::class)->withMessage('Money currencies must match');
        (new Money(minorUnits: 1000, currency: 'USD'))->isLessThan(new Money(minorUnits: 1000, currency: 'EUR'));
    }

}
