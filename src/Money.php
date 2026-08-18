<?php

declare(strict_types=1);

namespace Rasuvaeff\Payments;

use Rasuvaeff\Payments\Internal\Assert;

/**
 * Non-negative money in integer minor units.
 *
 * @api
 */
final readonly class Money
{
    private const string CURRENCY_PATTERN = '/^[A-Z]{3}\z/';

    /** @var int<0, max> */
    public int $minorUnits;

    /** @var non-empty-string */
    public string $currency;

    public function __construct(
        int $minorUnits,
        string $currency,
    ) {
        Assert::nonNegative(value: $minorUnits, name: 'Money minor units');
        Assert::matches(
            value: $currency,
            pattern: self::CURRENCY_PATTERN,
            message: 'Money currency must be a three-letter uppercase ISO code',
        );

        $this->minorUnits = $minorUnits;
        $this->currency = $currency;
    }

    public static function minorUnits(int $amount, string $currency): self
    {
        return new self(minorUnits: $amount, currency: $currency);
    }

    public function add(self $other): self
    {
        $this->assertSameCurrency(other: $other);

        if ($this->minorUnits > PHP_INT_MAX - $other->minorUnits) {
            throw new \OverflowException('Money addition overflow');
        }

        return new self(minorUnits: $this->minorUnits + $other->minorUnits, currency: $this->currency);
    }

    public function subtract(self $other): self
    {
        $this->assertSameCurrency(other: $other);

        if ($other->minorUnits > $this->minorUnits) {
            throw new \InvalidArgumentException('Money subtraction cannot produce a negative amount');
        }

        return new self(minorUnits: $this->minorUnits - $other->minorUnits, currency: $this->currency);
    }

    public function multiply(int $numerator, int $denominator = 1): self
    {
        Assert::nonNegative(value: $numerator, name: 'Money multiplier numerator');
        Assert::positive(value: $denominator, name: 'Money multiplier denominator');

        return new self(
            minorUnits: $this->multiplyAndDivide(amount: $this->minorUnits, numerator: $numerator, denominator: $denominator),
            currency: $this->currency,
        );
    }

    public function isZero(): bool
    {
        return $this->minorUnits === 0;
    }

    /**
     * Compares amount **and** currency.
     *
     * Reconciliation must confirm that the authoritative provider amount is
     * the amount the order was placed for. Comparing `minorUnits` alone
     * accepts 1000 JPY for a 1000 USD order, so equality is offered here
     * rather than left to be hand-rolled at every call site.
     */
    public function equals(self $other): bool
    {
        return $this->minorUnits === $other->minorUnits && $this->currency === $other->currency;
    }

    /**
     * @throws \InvalidArgumentException when the currencies differ
     */
    public function isGreaterThan(self $other): bool
    {
        $this->assertSameCurrency(other: $other);

        return $this->minorUnits > $other->minorUnits;
    }

    /**
     * @throws \InvalidArgumentException when the currencies differ
     */
    public function isLessThan(self $other): bool
    {
        $this->assertSameCurrency(other: $other);

        return $this->minorUnits < $other->minorUnits;
    }

    private function assertSameCurrency(self $other): void
    {
        if ($this->currency !== $other->currency) {
            throw new \InvalidArgumentException('Money currencies must match');
        }
    }

    private function multiplyAndDivide(int $amount, int $numerator, int $denominator): int
    {
        $quotient = 0;
        $remainder = 0;
        $termQuotient = intdiv($amount, $denominator);
        $termRemainder = $amount % $denominator;
        $bit = 0;

        while ($numerator) {
            if ($bit > 0) {
                [$termQuotient, $termRemainder] = $this->addParts(
                    quotient: $termQuotient,
                    remainder: $termRemainder,
                    addedQuotient: $termQuotient,
                    addedRemainder: $termRemainder,
                    denominator: $denominator,
                );
            }

            if ($numerator % 2 === 1) {
                [$quotient, $remainder] = $this->addParts(
                    quotient: $quotient,
                    remainder: $remainder,
                    addedQuotient: $termQuotient,
                    addedRemainder: $termRemainder,
                    denominator: $denominator,
                );
            }

            $numerator = intdiv($numerator, 2);
            ++$bit;
        }

        $roundUpAt = intdiv($denominator, 2) + ($denominator % 2);

        if ($remainder >= $roundUpAt) {
            if ($quotient === PHP_INT_MAX) {
                throw new \OverflowException('Money multiplication overflow');
            }

            ++$quotient;
        }

        return $quotient;
    }

    /**
     * @return array{int, int}
     */
    private function addParts(
        int $quotient,
        int $remainder,
        int $addedQuotient,
        int $addedRemainder,
        int $denominator,
    ): array {
        $carry = $remainder >= $denominator - $addedRemainder ? 1 : 0;

        if ($addedQuotient > PHP_INT_MAX - $quotient - $carry) {
            throw new \OverflowException('Money multiplication overflow');
        }

        $nextRemainder = $carry === 1
            ? $remainder - ($denominator - $addedRemainder)
            : $remainder + $addedRemainder;

        return [$quotient + $addedQuotient + $carry, $nextRemainder];
    }
}
