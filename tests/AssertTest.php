<?php

declare(strict_types=1);

namespace Rasuvaeff\Payments\Tests;

use Rasuvaeff\Payments\Internal\Assert as Guard;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Test;

#[Test]
#[Covers(Guard::class)]
final class AssertTest
{
    #[DataProvider('nonEmptyProvider')]
    public function nonEmptyChecksPresenceAndLength(string $value, ?string $message): void
    {
        $this->check(static fn() => Guard::nonEmpty($value, 'Field', 3), $message);
    }

    public static function nonEmptyProvider(): iterable
    {
        yield 'empty' => ['', 'Field must not be empty'];
        yield 'one over the limit' => ['abcd', 'Field must not exceed 3 bytes'];
        yield 'multibyte counted in bytes' => ['éé', 'Field must not exceed 3 bytes'];
        yield 'exactly the limit' => ['abc', null];
        yield 'a single byte' => ['a', null];
        yield 'whitespace is content here' => [' ', null];
    }

    #[DataProvider('nonBlankProvider')]
    public function nonBlankRejectsBlankAndOversizedValues(string $value, ?string $message): void
    {
        $this->check(static fn() => Guard::nonBlank($value, 'Field', 5), $message);
    }

    public static function nonBlankProvider(): iterable
    {
        $message = 'Field must be non-empty and at most 5 bytes';

        yield 'empty' => ['', $message];
        yield 'only spaces' => ['   ', $message];
        yield 'only a newline' => ["\n", $message];
        yield 'one over the limit' => ['abcdef', $message];
        yield 'exactly the limit' => ['abcde', null];
        yield 'padded but not blank' => [' a ', null];
    }

    #[DataProvider('headerTokenProvider')]
    public function headerTokenRejectsWhatCannotTravelInAHeader(string $value, ?string $message): void
    {
        $this->check(static fn() => Guard::headerToken($value, 'Header', 6), $message);
    }

    public static function headerTokenProvider(): iterable
    {
        $message = 'Header must be a non-empty token of at most 6 bytes without control characters or spaces';

        yield 'empty' => ['', $message];
        yield 'one over the limit' => ['abcdefg', $message];
        yield 'a space' => ['ab cd', $message];
        yield 'a tab' => ["ab\tcd", $message];
        yield 'a NUL byte' => ["ab\x00cd", $message];
        yield 'the last control byte below the space' => ["ab\x1Fcd", $message];
        yield 'DEL' => ["ab\x7Fcd", $message];
        yield 'a newline' => ["ab\ncd", $message];
        yield 'exactly the limit' => ['abcdef', null];
        yield 'punctuation is fine' => ['a-b_c.', null];
        yield 'the first byte above DEL' => ["ab\x80", null];
    }

    #[DataProvider('matchesProvider')]
    public function matchesRequiresANonEmptyValueThatFitsThePattern(string $value, string $pattern, ?string $message): void
    {
        $this->check(static fn() => Guard::matches($value, $pattern, 'Does not fit'), $message);
    }

    public static function matchesProvider(): iterable
    {
        yield 'empty even when the pattern accepts it' => ['', '/\A.*\z/', 'Does not fit'];
        yield 'no match' => ['abc', '/\A\d+\z/', 'Does not fit'];
        yield 'a match' => ['123', '/\A\d+\z/', null];
    }

    #[DataProvider('httpsUrlProvider')]
    public function httpsUrlRequiresAnAbsoluteHttpsUrlWithinTheLimit(string $value, ?string $message): void
    {
        $this->check(static fn() => Guard::httpsUrl($value, 'Endpoint', 20), $message);
    }

    public static function httpsUrlProvider(): iterable
    {
        $message = 'Endpoint must be an absolute HTTPS URL';

        yield 'plain http' => ['http://example.com', $message];
        yield 'no scheme' => ['//example.com', $message];
        yield 'no host' => ['https:/path', $message];
        yield 'unparsable' => ['https:///x', $message];
        yield 'empty' => ['', $message];
        yield 'one over the limit' => ['https://example.com/a', $message];
        yield 'exactly the limit' => ['https://example.com/', null];
        yield 'short' => ['https://a.io', null];
    }

    #[DataProvider('nonNegativeProvider')]
    public function nonNegativeAcceptsZeroAndAbove(int $value, ?string $message): void
    {
        $this->check(static fn() => Guard::nonNegative($value, 'Amount'), $message);
    }

    public static function nonNegativeProvider(): iterable
    {
        yield 'minus one' => [-1, 'Amount cannot be negative'];
        yield 'zero' => [0, null];
        yield 'one' => [1, null];
    }

    #[DataProvider('positiveProvider')]
    public function positiveRequiresAtLeastOne(int $value, ?string $message): void
    {
        $this->check(static fn() => Guard::positive($value, 'Count'), $message);
    }

    public static function positiveProvider(): iterable
    {
        yield 'minus one' => [-1, 'Count must be positive'];
        yield 'zero' => [0, 'Count must be positive'];
        yield 'one' => [1, null];
        yield 'two' => [2, null];
    }

    /**
     * @param array<array-key, mixed> $value
     */
    #[DataProvider('scalarMapProvider')]
    public function scalarMapRequiresNamedKeysAndScalarValues(array $value, ?string $message): void
    {
        $this->check(static fn() => Guard::scalarMap($value, 'Map'), $message);
    }

    public static function scalarMapProvider(): iterable
    {
        $keys = 'Map keys must be non-empty strings';
        $values = 'Map must contain scalar values';

        yield 'empty' => [[], null];
        yield 'every scalar kind and null' => [['a' => 1, 'b' => 'x', 'c' => 1.5, 'd' => true, 'e' => null], null];
        yield 'a list' => [['x'], $keys];
        yield 'an empty key' => [['' => 1], $keys];
        yield 'a numeric key after a good one' => [['ok' => 1, 5 => 2], $keys];
        yield 'an array value' => [['a' => []], $values];
        yield 'an object value' => [['a' => new \stdClass()], $values];
        yield 'a bad value after a good one' => [['ok' => 1, 'bad' => []], $values];
    }

    /**
     * @param callable(): void $call
     */
    private function check(callable $call, ?string $message): void
    {
        try {
            $call();
        } catch (\InvalidArgumentException $e) {
            Assert::same($e->getMessage(), $message ?? 'no exception expected');

            return;
        }

        Assert::null($message, 'an exception was expected');
    }
}
