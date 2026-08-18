<?php

declare(strict_types=1);

namespace Rasuvaeff\Payments;

use Psr\Http\Message\ResponseInterface;

/**
 * Decodes JSON objects and maps status/error types without retaining headers.
 *
 * @api
 */
final readonly class JsonResponseDecoder implements ResponseDecoderInterface
{
    private const int HTTP_SUCCESS_MINIMUM = 200;
    private const int HTTP_SUCCESS_MAXIMUM = 299;
    private const int HTTP_UNAUTHORIZED = 401;
    private const int HTTP_FORBIDDEN = 403;
    private const int HTTP_NOT_FOUND = 404;
    private const int HTTP_TOO_MANY_REQUESTS = 429;
    private const int HTTP_SERVER_ERROR_MINIMUM = 500;
    private const int MESSAGE_MAXIMUM_LENGTH = 1_024;

    #[\Override]
    public function decode(ResponseInterface $response): array
    {
        $body = $response->getBody()->getContents();

        try {
            $decoded = json_decode($body, associative: false, depth: 512, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new MalformedResponseException('Payment response is not valid JSON', previous: $exception);
        }

        if (!$decoded instanceof \stdClass) {
            throw new MalformedResponseException('Payment response must be a JSON object');
        }

        $decoded = get_object_vars($decoded);

        if ($response->getStatusCode() >= self::HTTP_SUCCESS_MINIMUM && $response->getStatusCode() <= self::HTTP_SUCCESS_MAXIMUM) {
            return $decoded;
        }

        throw $this->exceptionFor($response->getStatusCode(), $decoded);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function exceptionFor(int $status, array $payload): PaymentException
    {
        $error = isset($payload['error']) && $payload['error'] instanceof \stdClass
            ? get_object_vars($payload['error'])
            : [];
        $code = $this->stringValue($error, 'code');
        $type = $this->stringValue($error, 'type');
        $parameter = $this->stringValue($error, 'param');
        $message = $this->stringValue($error, 'message') ?? 'Payment provider request failed';
        /** @var array<string, scalar|null> $details */
        $details = [];

        foreach (['request_id', 'retry_after'] as $key) {
            if (isset($payload[$key]) && (is_string($payload[$key]) || is_int($payload[$key]))) {
                $details[$key] = $payload[$key];
            }
        }

        /** @var class-string<PaymentException> $exceptionClass */
        if ($status === self::HTTP_UNAUTHORIZED) {
            $exceptionClass = UnauthorizedException::class;
        } elseif ($status === self::HTTP_FORBIDDEN) {
            $exceptionClass = ForbiddenException::class;
        } elseif ($status === self::HTTP_NOT_FOUND) {
            $exceptionClass = NotFoundException::class;
        } elseif ($status === self::HTTP_TOO_MANY_REQUESTS) {
            $exceptionClass = RateLimitedException::class;
        } elseif ($status >= self::HTTP_SERVER_ERROR_MINIMUM) {
            $exceptionClass = ServerException::class;
        } elseif ($type === 'refund_error' || str_contains($code ?? '', 'refund')) {
            $exceptionClass = RefundFailedException::class;
        } elseif ($type === 'card_error' || $type === 'decline') {
            $exceptionClass = ProviderDeclinedException::class;
        } else {
            $exceptionClass = PaymentException::class;
        }

        return new $exceptionClass(
            message: $message,
            providerCode: $code,
            providerType: $type,
            providerParameter: $parameter,
            details: $details,
        );
    }

    /**
     * Provider error text ends up in an exception message, and exception
     * messages end up in logs and error reports. It echoes request fields back
     * and carries no documented length bound, so it is capped before being
     * carried any further.
     *
     * The cut is by bytes, then walked back until the result is valid UTF-8
     * again: a byte cap can land inside a multi-byte sequence, and a broken
     * sequence would make the log record itself unserializable.
     */
    private function truncate(string $value): string
    {
        $capped = substr($value, 0, self::MESSAGE_MAXIMUM_LENGTH);

        while (preg_match('//u', $capped) !== 1) {
            $capped = substr($capped, 0, -1);
        }

        return $capped;
    }

    /**
     * @param array<string, mixed> $value
     */
    private function stringValue(array $value, string $key): ?string
    {
        return isset($value[$key]) && is_string($value[$key]) ? $this->truncate($value[$key]) : null;
    }
}
