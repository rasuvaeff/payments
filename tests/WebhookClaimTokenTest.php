<?php

declare(strict_types=1);

namespace Rasuvaeff\Payments\Tests;

use Rasuvaeff\Payments\WebhookClaimToken;
use Rasuvaeff\PropertyTesting\ArbitraryInterface;
use Rasuvaeff\PropertyTesting\Classify;
use Rasuvaeff\PropertyTesting\Gen;
use Rasuvaeff\PropertyTesting\Property;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Test;

#[Test]
#[Covers(WebhookClaimToken::class)]
final class WebhookClaimTokenTest
{
    public function acceptsAUrlSafeToken(): void
    {
        $token = new WebhookClaimToken('aB3-_');

        Assert::same($token->value, 'aB3-_');
        Assert::same((string) $token, 'aB3-_');
    }

    #[DataProvider('invalidValueProvider')]
    public function rejectsInvalidValues(string $value): void
    {
        try {
            new WebhookClaimToken($value);
        } catch (\InvalidArgumentException $exception) {
            Assert::same(
                $exception->getMessage(),
                'Claim token must be a non-empty URL-safe string of at most 128 bytes',
            );

            return;
        }

        Assert::fail('Expected an invalid claim token to be rejected');
    }

    public static function invalidValueProvider(): iterable
    {
        yield 'empty' => [''];
        yield 'space' => ['a b'];
        yield 'trailing newline' => ["abc\n"];
        yield 'trailing carriage return' => ["abc\r"];
        yield 'nul byte' => ["a\0b"];
        yield 'base64 padding' => ['abc=='];
        yield 'uuid braces' => ['{550e8400-e29b-41d4-a716-446655440000}'];
        yield 'oversized' => [str_repeat('a', 129)];
    }

    public function generatesOpaqueUniqueTokens(): void
    {
        $first = WebhookClaimToken::generate();
        $second = WebhookClaimToken::generate();

        Assert::same(strlen($first->value), 32);
        Assert::same(strlen($second->value), 32);
        Assert::true(preg_match('/^[A-Za-z0-9_-]{1,128}\z/', $first->value) === 1);
        Assert::false($first->equals($second));
        Assert::false($first->value === $second->value);
    }

    public function comparesByValue(): void
    {
        $token = new WebhookClaimToken('evt-claim-token');
        $same = new WebhookClaimToken('evt-claim-token');
        $other = new WebhookClaimToken('other-claim-token');

        Assert::true($token->equals($same));
        Assert::true($same->equals($token));
        Assert::false($token->equals($other));
    }

    public function acceptsMaximumLength(): void
    {
        $value = str_repeat('t', 128);

        Assert::same((new WebhookClaimToken($value))->value, $value);
    }

    /**
     * Accepted exactly when the value is 1-128 URL-safe characters: the
     * generator builds both sides from an alphabet that includes the
     * characters next to the allowed ones.
     */
    #[Property(runs: 300)]
    public function acceptsExactlyUrlSafeValuesUpTo128Bytes(string $value): void
    {
        $valid = preg_match('/^[A-Za-z0-9_-]{1,128}\z/', $value) === 1;
        // Expected shares from the generator weights: valid about 74 %,
        // invalid about 26 % (sd about 2.5 pp over 300 runs). The gates sit at
        // half of that, so they fail only when a branch is really gone — at 20 %
        // the invalid gate failed about once in a hundred runs (2026-11-28).
        Classify::cover($valid, 'valid', 35);
        Classify::cover(!$valid, 'invalid', 13);

        try {
            Assert::same((new WebhookClaimToken($value))->value, $value);
            Assert::true($valid);
        } catch (\InvalidArgumentException) {
            Assert::false($valid);
        }
    }

    /** @return array<string, ArbitraryInterface> */
    public static function acceptsExactlyUrlSafeValuesUpTo128BytesGenerators(): array
    {
        return ['value' => Gen::frequency([
            [3, Gen::stringFrom('aZ09_-', 0, 130)],
            [1, Gen::stringFrom("aZ09_-=+/. \n", 0, 12)],
            [1, Gen::stringFrom('a', 126, 130)],
        ])];
    }

    /** @return iterable<string, array{string}> */
    public static function acceptsExactlyUrlSafeValuesUpTo128BytesExamples(): iterable
    {
        yield 'empty' => [''];
        yield 'trailing newline' => ["abc\n"];
        yield 'maximum' => [str_repeat('a', 128)];
        yield 'one over' => [str_repeat('a', 129)];
    }

    #[Property(runs: 100)]
    public function generatedTokensAreValidAndDistinct(int $count): void
    {
        $tokens = [];

        for ($i = 0; $i < $count; ++$i) {
            $token = WebhookClaimToken::generate();
            Assert::true($token->equals(new WebhookClaimToken($token->value)));
            $tokens[$token->value] = true;
        }

        Assert::same(\count($tokens), $count);
    }

    /** @return array<string, ArbitraryInterface> */
    public static function generatedTokensAreValidAndDistinctGenerators(): array
    {
        return ['count' => Gen::intBetween(1, 20)];
    }
}
