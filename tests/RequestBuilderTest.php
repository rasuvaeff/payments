<?php

declare(strict_types=1);

namespace Rasuvaeff\Payments\Tests;

use Nyholm\Psr7\Factory\Psr17Factory;
use Rasuvaeff\Payments\AuthContext;
use Rasuvaeff\Payments\FormRequestBuilder;
use Rasuvaeff\Payments\JsonRequestBuilder;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Expect;
use Testo\Test;

#[Test]
#[Covers(JsonRequestBuilder::class)]
#[Covers(FormRequestBuilder::class)]
final class RequestBuilderTest
{
    public function buildsJsonRequestWithAuth(): void
    {
        $factory = new Psr17Factory();
        $request = (new JsonRequestBuilder($factory, $factory))->build(
            method: 'POST',
            uri: 'https://api.example.test/payments',
            data: ['amount' => 1200, 'currency' => 'EUR', 'return_url' => 'https://example.test/return'],
            auth: AuthContext::bearer('token'),
        );

        Assert::same($request->getMethod(), 'POST');
        Assert::same($request->getHeaderLine('Content-Type'), 'application/json');
        Assert::same($request->getHeaderLine('Authorization'), 'Bearer token');
        Assert::same(
            (string) $request->getBody(),
            '{"amount":1200,"currency":"EUR","return_url":"https://example.test/return"}',
        );
    }

    public function buildsRfc3986FormRequest(): void
    {
        $factory = new Psr17Factory();
        $request = (new FormRequestBuilder($factory, $factory))->build(
            method: 'POST',
            uri: 'https://api.example.test/token',
            data: ['scope' => 'payment create', 'enabled' => true],
            auth: AuthContext::basic('client', 'secret'),
        );

        Assert::same($request->getHeaderLine('Content-Type'), 'application/x-www-form-urlencoded');
        Assert::same($request->getHeaderLine('Authorization'), 'Basic Y2xpZW50OnNlY3JldA==');
        Assert::same((string) $request->getBody(), 'scope=payment%20create&enabled=1');
    }

    public function keepsItsOwnMediaTypeOverTheAuthContext(): void
    {
        $factory = new Psr17Factory();
        $auth = AuthContext::fromHeaders(['Content-Type' => 'text/plain', 'X-Key' => 'secret']);

        $json = (new JsonRequestBuilder($factory, $factory))->build(
            method: 'POST',
            uri: 'https://api.example.test/payments',
            data: ['amount' => 1200],
            auth: $auth,
        );
        $form = (new FormRequestBuilder($factory, $factory))->build(
            method: 'POST',
            uri: 'https://api.example.test/token',
            data: ['scope' => 'payment'],
            auth: $auth,
        );

        Assert::same($json->getHeaderLine('Content-Type'), 'application/json');
        Assert::same($form->getHeaderLine('Content-Type'), 'application/x-www-form-urlencoded');
        Assert::same($json->getHeaderLine('X-Key'), 'secret');
        Assert::same($form->getHeaderLine('X-Key'), 'secret');
    }

    public function rejectsDataThatCannotBeEncodedAsJson(): void
    {
        $factory = new Psr17Factory();

        Expect::exception(\JsonException::class);
        (new JsonRequestBuilder($factory, $factory))->build(
            method: 'POST',
            uri: 'https://api.example.test/payments',
            data: ['invalid_utf8' => "\xB1\x31"],
            auth: AuthContext::bearer('token'),
        );
    }

    public function rejectsNestedFormData(): void
    {
        $factory = new Psr17Factory();

        Expect::exception(\InvalidArgumentException::class);
        (new FormRequestBuilder($factory, $factory))->build(
            method: 'POST',
            uri: 'https://api.example.test/token',
            data: ['nested' => ['forbidden']],
            auth: AuthContext::fromHeaders(['X-Key' => 'secret']),
        );
    }

    /**
     * The whole point of the raw variant: bytes in, same bytes out. Decoding
     * and re-encoding would escape the non-ASCII, turn the empty object into
     * an empty array and drop the trailing zero — three silent edits to a
     * document whose signature covers its exact bytes.
     */
    public function passesRawJsonThroughByteForByte(): void
    {
        $factory = new Psr17Factory();
        $raw = '{"note":"Café","custom":{},"rate":1.50}';
        $request = (new JsonRequestBuilder($factory, $factory))->buildRawJson(
            method: 'POST',
            uri: 'https://api.example.test/verify',
            body: $raw,
            auth: AuthContext::bearer('token'),
        );

        Assert::same((string) $request->getBody(), $raw);
        Assert::same($request->getHeaderLine('Content-Type'), 'application/json');
        Assert::same($request->getHeaderLine('Authorization'), 'Bearer token');
        Assert::same($request->getMethod(), 'POST');
    }

    public function rejectsAnEmptyRawJsonBody(): void
    {
        $factory = new Psr17Factory();

        Expect::exception(\InvalidArgumentException::class)->withMessage('Raw JSON body cannot be empty');
        (new JsonRequestBuilder($factory, $factory))->buildRawJson(
            method: 'POST',
            uri: 'https://api.example.test/verify',
            body: '',
            auth: AuthContext::bearer('token'),
        );
    }

    /**
     * RFC 9110 gives a GET, HEAD or DELETE body no defined semantics, and a
     * spec-validating server (Prism on PayPal's OpenAPI) answers 415 to a GET
     * carrying `{}` with `Content-Type: application/json`. With nothing to send
     * such a request has no body and no media type — also not one smuggled in
     * through the authentication context.
     */
    #[DataProvider('bodylessMethodProvider')]
    public function sendsNoBodyAndNoMediaTypeForAnEmptyBodylessRequest(string $method): void
    {
        $factory = new Psr17Factory();
        $auth = AuthContext::fromHeaders(['Content-Type' => 'text/plain', 'X-Key' => 'secret']);

        $json = (new JsonRequestBuilder($factory, $factory))->build(
            method: $method,
            uri: 'https://api.example.test/payments/pay_1',
            data: [],
            auth: $auth,
        );
        $form = (new FormRequestBuilder($factory, $factory))->build(
            method: $method,
            uri: 'https://api.example.test/payments/pay_1',
            data: [],
            auth: $auth,
        );

        Assert::same($json->getMethod(), $method);
        Assert::same((string) $json->getBody(), '');
        Assert::false($json->hasHeader('Content-Type'));
        Assert::same($json->getHeaderLine('X-Key'), 'secret');
        Assert::same($form->getMethod(), $method);
        Assert::same((string) $form->getBody(), '');
        Assert::false($form->hasHeader('Content-Type'));
        Assert::same($form->getHeaderLine('X-Key'), 'secret');
    }

    public static function bodylessMethodProvider(): iterable
    {
        yield 'GET' => ['GET'];
        yield 'HEAD' => ['HEAD'];
        yield 'DELETE' => ['DELETE'];
    }

    /**
     * An empty JSON object is a meaningful body for a method that carries
     * one, and method names are case-sensitive (RFC 9110 §9.1): `get` is
     * not GET.
     */
    #[DataProvider('bodyMethodProvider')]
    public function keepsTheEmptyBodyOfAMethodThatCarriesOne(string $method, string $jsonBody): void
    {
        $factory = new Psr17Factory();
        $auth = AuthContext::bearer('token');

        $json = (new JsonRequestBuilder($factory, $factory))->build(
            method: $method,
            uri: 'https://api.example.test/payments/pay_1',
            data: [],
            auth: $auth,
        );
        $form = (new FormRequestBuilder($factory, $factory))->build(
            method: $method,
            uri: 'https://api.example.test/payments/pay_1',
            data: [],
            auth: $auth,
        );

        Assert::same((string) $json->getBody(), $jsonBody);
        Assert::same($json->getHeaderLine('Content-Type'), 'application/json');
        Assert::same((string) $form->getBody(), '');
        Assert::same($form->getHeaderLine('Content-Type'), 'application/x-www-form-urlencoded');
    }

    public static function bodyMethodProvider(): iterable
    {
        yield 'POST' => ['POST', '{}'];
        yield 'PUT' => ['PUT', '{}'];
        yield 'PATCH' => ['PATCH', '{}'];
        yield 'lower-case get is another method' => ['get', '{}'];
    }

    /**
     * Only an empty request goes bodyless: data given to a GET is still sent
     * the way it always was.
     */
    public function keepsTheBodyOfABodylessMethodGivenData(): void
    {
        $factory = new Psr17Factory();
        $auth = AuthContext::bearer('token');

        $json = (new JsonRequestBuilder($factory, $factory))->build(
            method: 'GET',
            uri: 'https://api.example.test/payments',
            data: ['limit' => 3],
            auth: $auth,
        );
        $form = (new FormRequestBuilder($factory, $factory))->build(
            method: 'DELETE',
            uri: 'https://api.example.test/payments',
            data: ['limit' => 3],
            auth: $auth,
        );

        Assert::same((string) $json->getBody(), '{"limit":3}');
        Assert::same($json->getHeaderLine('Content-Type'), 'application/json');
        Assert::same((string) $form->getBody(), 'limit=3');
        Assert::same($form->getHeaderLine('Content-Type'), 'application/x-www-form-urlencoded');
    }
}
