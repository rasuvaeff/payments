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

    /**
     * RFC 7617: the user-id cannot contain a colon, since the server splits
     * the decoded credentials at the first one.
     */
    public function rejectsColonInBasicUsername(): void
    {
        Expect::exception(\InvalidArgumentException::class)
            ->withMessage('Basic authentication username must not contain a colon');
        AuthContext::basic(username: 'client:id', password: 'pass');
    }

    public function acceptsColonInBasicPassword(): void
    {
        Assert::same(
            AuthContext::basic(username: 'user', password: 'pa:ss')->headers(),
            ['Authorization' => 'Basic ' . base64_encode('user:pa:ss')],
        );
    }

    #[DataProvider('contextProvider')]
    public function dumpRedactsHeaderValues(AuthContext $context, string $secret): void
    {
        $dump = print_r($context, return: true);

        Assert::false(str_contains($dump, $secret));
        Assert::same($context->__debugInfo(), ['headers' => ['Authorization' => '[REDACTED]']]);
    }

    #[DataProvider('contextProvider')]
    public function refusesSerialization(AuthContext $context): void
    {
        Expect::exception(\LogicException::class)->withMessage('AuthContext must not be serialized');
        serialize($context);
    }

    public function refusesUnserialization(): void
    {
        Expect::exception(\LogicException::class)->withMessage('AuthContext must not be unserialized');
        unserialize(sprintf('O:%d:"%s":1:{s:7:"headers";a:0:{}}', strlen(AuthContext::class), AuthContext::class));
    }

    /** @return iterable<string, array{AuthContext, string}> */
    public static function contextProvider(): iterable
    {
        yield 'bearer' => [AuthContext::bearer('sk_live_secret'), 'sk_live_secret'];
        yield 'basic' => [AuthContext::basic('client', 'TOPSECRET'), base64_encode('client:TOPSECRET')];
        yield 'headers' => [AuthContext::fromHeaders(['Authorization' => 'Bearer sk_live_secret']), 'sk_live_secret'];
    }

    /**
     * A credential passed to a factory that then throws must not reach the
     * stack trace's argument list.
     *
     * @param non-empty-string $method
     * @param non-empty-string $parameter
     */
    #[DataProvider('sensitiveParameterProvider')]
    public function marksCredentialParametersSensitive(string $method, string $parameter): void
    {
        $attributes = [];

        foreach ((new \ReflectionMethod(AuthContext::class, $method))->getParameters() as $candidate) {
            if ($candidate->getName() === $parameter) {
                $attributes = $candidate->getAttributes(\SensitiveParameter::class);
            }
        }

        Assert::same(count($attributes), 1);
    }

    /** @return iterable<string, array{non-empty-string, non-empty-string}> */
    public static function sensitiveParameterProvider(): iterable
    {
        yield 'bearer token' => ['bearer', 'token'];
        yield 'basic password' => ['basic', 'password'];
        yield 'header map' => ['fromHeaders', 'headers'];
        yield 'constructor header map' => ['__construct', 'headers'];
    }
}
