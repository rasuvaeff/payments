<?php

declare(strict_types=1);

namespace Rasuvaeff\Payments\Tests;

use Rasuvaeff\Payments\AuthContext;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Expect;
use Testo\Test;

#[Test]
#[Covers(AuthContext::class)]
final class AuthContextTest
{
    public function createsBearerAndBasicHeaders(): void
    {
        Assert::same(AuthContext::bearer('secret')->headers(), ['Authorization' => 'Bearer secret']);
        Assert::same(AuthContext::basic('user', 'pass')->headers(), ['Authorization' => 'Basic dXNlcjpwYXNz']);
    }

    public function preservesSelectedHeaders(): void
    {
        Assert::same(AuthContext::fromHeaders(['X-Api-Key' => 'secret'])->headers(), ['X-Api-Key' => 'secret']);
    }

    #[DataProvider('emptyBasicCredentialProvider')]
    public function rejectsEachEmptyBasicCredential(string $username, string $password): void
    {
        Expect::exception(\InvalidArgumentException::class);
        AuthContext::basic(username: $username, password: $password);
    }

    /** @return iterable<string, array{string, string}> */
    public static function emptyBasicCredentialProvider(): iterable
    {
        yield 'empty username' => ['', 'pass'];
        yield 'empty password' => ['user', ''];
    }

    public function rejectsEmptyBearerCredential(): void
    {
        Expect::exception(\InvalidArgumentException::class);
        AuthContext::bearer('');
    }

    public function rejectsEmptyHeaderName(): void
    {
        Expect::exception(\InvalidArgumentException::class);
        AuthContext::fromHeaders(['' => 'secret']);
    }
}
