<?php

declare(strict_types=1);

namespace Rasuvaeff\Payments\Tests;

use Nyholm\Psr7\Response;
use Rasuvaeff\Payments\ForbiddenException;
use Rasuvaeff\Payments\JsonResponseDecoder;
use Rasuvaeff\Payments\MalformedResponseException;
use Rasuvaeff\Payments\NotFoundException;
use Rasuvaeff\Payments\PaymentException;
use Rasuvaeff\Payments\ProviderDeclinedException;
use Rasuvaeff\Payments\RateLimitedException;
use Rasuvaeff\Payments\RefundFailedException;
use Rasuvaeff\Payments\ServerException;
use Rasuvaeff\Payments\UnauthorizedException;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Expect;
use Testo\Test;

#[Test]
#[Covers(JsonResponseDecoder::class)]
final class JsonResponseDecoderTest
{
    public function decodesSuccessfulObject(): void
    {
        $standard = (new JsonResponseDecoder())->decode(new Response(status: 200, body: '{"id":"pay_1"}'));
        $decoded = (new JsonResponseDecoder())->decode(new Response(
            status: 299,
            body: '{"id":"pay_1","status":"pending"}',
        ));

        Assert::same($standard, ['id' => 'pay_1']);
        Assert::same($decoded, ['id' => 'pay_1', 'status' => 'pending']);
    }

    public function preservesAllowListedErrorMetadata(): void
    {
        try {
            (new JsonResponseDecoder())->decode(new Response(
                status: 400,
                body: '{"error":{"code":"bad_request","type":"invalid_request","param":"amount","message":"Bad amount"},"request_id":"req_1","retry_after":30,"authorization":"secret"}',
            ));
        } catch (PaymentException $exception) {
            Assert::same($exception->getMessage(), 'Bad amount');
            Assert::same($exception->providerCode, 'bad_request');
            Assert::same($exception->providerType, 'invalid_request');
            Assert::same($exception->providerParameter, 'amount');
            Assert::same($exception->details, ['request_id' => 'req_1', 'retry_after' => 30]);

            return;
        }

        Assert::fail('Expected PaymentException');
    }

    public function discardsNonScalarDiagnosticsAndMalformedErrorShape(): void
    {
        try {
            (new JsonResponseDecoder())->decode(new Response(
                status: 400,
                body: '{"error":"not-an-object","request_id":false,"retry_after":1.5}',
            ));
        } catch (PaymentException $exception) {
            Assert::same($exception::class, PaymentException::class);
            Assert::same($exception->providerCode, null);
            Assert::same($exception->details, []);

            return;
        }

        Assert::fail('Expected PaymentException');
    }

    public function discardsNonStringErrorFields(): void
    {
        try {
            (new JsonResponseDecoder())->decode(new Response(
                status: 400,
                body: '{"error":{"code":12,"type":false,"param":[],"message":null}}',
            ));
        } catch (PaymentException $exception) {
            Assert::null($exception->providerCode);
            Assert::null($exception->providerType);
            Assert::null($exception->providerParameter);
            Assert::same($exception->getMessage(), 'Payment provider request failed');

            return;
        }

        Assert::fail('Expected PaymentException');
    }

    #[DataProvider('statusProvider')]
    public function mapsTypedFailures(int $status, string $body, string $expected): void
    {
        Expect::exception($expected);
        (new JsonResponseDecoder())->decode(new Response(status: $status, body: $body));
    }

    /**
     * @return iterable<string, array{int, string, class-string<PaymentException>}>
     */
    public static function statusProvider(): iterable
    {
        yield 'unauthorized' => [401, '{}', UnauthorizedException::class];
        yield 'forbidden' => [403, '{}', ForbiddenException::class];
        yield 'missing' => [404, '{}', NotFoundException::class];
        yield 'rate limited' => [429, '{}', RateLimitedException::class];
        yield 'server boundary' => [500, '{}', ServerException::class];
        yield 'server' => [503, '{}', ServerException::class];
        yield 'decline' => [402, '{"error":{"type":"card_error"}}', ProviderDeclinedException::class];
        yield 'explicit decline type' => [400, '{"error":{"type":"decline"}}', ProviderDeclinedException::class];
        yield 'refund' => [400, '{"error":{"type":"refund_error"}}', RefundFailedException::class];
        yield 'refund code' => [400, '{"error":{"code":"refund_failed"}}', RefundFailedException::class];
        yield 'generic 402' => [402, '{}', PaymentException::class];
        yield 'generic client error' => [400, '{"error":{"type":"other"}}', PaymentException::class];
    }

    #[DataProvider('malformedProvider')]
    public function rejectsMalformedPayload(string $body, string $message): void
    {
        Expect::exception(MalformedResponseException::class)->withMessage($message);
        (new JsonResponseDecoder())->decode(new Response(status: 200, body: $body));
    }

    public static function malformedProvider(): iterable
    {
        yield 'invalid JSON' => ['{', 'Payment response is not valid JSON'];
        yield 'list instead of object' => ['[]', 'Payment response must be a JSON object'];
        yield 'scalar instead of object' => ['true', 'Payment response must be a JSON object'];
    }

    public function acceptsPayloadAtTheNestingLimit(): void
    {
        $decoded = (new JsonResponseDecoder())->decode(
            new Response(status: 200, body: $this->nested(levels: 511)),
        );

        Assert::true(array_key_exists('a', $decoded));
    }

    public function rejectsPayloadPastTheNestingLimit(): void
    {
        Expect::exception(MalformedResponseException::class)->withMessage('Payment response is not valid JSON');
        (new JsonResponseDecoder())->decode(new Response(status: 200, body: $this->nested(levels: 512)));
    }

    private function nested(int $levels): string
    {
        $json = '1';

        for ($level = 0; $level < $levels; ++$level) {
            $json = '{"a":' . $json . '}';
        }

        return $json;
    }

    /**
     * Provider error text has no documented length bound and lands in logs
     * through the exception message, so it is capped. The cap counts
     * characters, not bytes: cutting a multi-byte sequence in half would make
     * the log record itself unserializable.
     */
    public function capsUnboundedProviderText(): void
    {
        $message = str_repeat('a', 2_000);
        $code = str_repeat('b', 2_000);

        try {
            (new JsonResponseDecoder())->decode(new Response(
                status: 400,
                body: json_encode(['error' => ['message' => $message, 'code' => $code]], JSON_THROW_ON_ERROR),
            ));
        } catch (PaymentException $exception) {
            Assert::same(strlen($exception->getMessage()), 1_024);
            Assert::same(strlen($exception->providerCode ?? ''), 1_024);

            return;
        }

        Assert::fail('Expected a payment exception');
    }

    /**
     * The 1024th byte falls inside a two-byte sequence here, so the cut walks
     * back one byte. Leaving the split sequence in place would produce a
     * string `json_encode()` refuses — the log record carrying the error would
     * fail to serialize because of the error text.
     */
    public function walksTheCapBackToACharacterBoundary(): void
    {
        try {
            (new JsonResponseDecoder())->decode(new Response(
                status: 400,
                body: json_encode(['error' => ['message' => 'x' . str_repeat('é', 1_000)]], JSON_THROW_ON_ERROR),
            ));
        } catch (PaymentException $exception) {
            $capped = $exception->getMessage();

            Assert::same($capped, 'x' . str_repeat('é', 511));
            Assert::same(strlen($capped), 1_023);
            Assert::same(preg_match('//u', $capped), 1);

            return;
        }

        Assert::fail('Expected a payment exception');
    }

    public function leavesShortProviderTextUntouched(): void
    {
        try {
            (new JsonResponseDecoder())->decode(new Response(
                status: 400,
                body: '{"error":{"message":"Short enough"}}',
            ));
        } catch (PaymentException $exception) {
            Assert::same($exception->getMessage(), 'Short enough');

            return;
        }

        Assert::fail('Expected a payment exception');
    }

}
