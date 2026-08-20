<?php

declare(strict_types=1);

namespace Rasuvaeff\Payments\Tests;

use Rasuvaeff\Payments\WebhookClaimToken;
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
}
