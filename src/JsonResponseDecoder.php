<?php

declare(strict_types=1);

namespace Rasuvaeff\Payments;

use Psr\Http\Message\ResponseInterface;
use Rasuvaeff\Payments\Internal\Utf8Text;

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

    /**
     * The status decides before the body is read. Only a 2xx answer must be a
     * JSON object; an error answer whose body is not one — the HTML or empty
     * page a load balancer, CDN or WAF serves during an outage — is still
     * classified by its status, so a 5xx stays a retryable `ServerException`
     * rather than a `MalformedResponseException`.
     */
    #[\Override]
    public function decode(ResponseInterface $response): array
    {
        $status = $response->getStatusCode();
        $body = $response->getBody()->getContents();

        if ($status >= self::HTTP_SUCCESS_MINIMUM && $status <= self::HTTP_SUCCESS_MAXIMUM) {
            return $this->object(body: $body);
        }

        try {
            $payload = $this->object(body: $body);
        } catch (MalformedResponseException) {
            $payload = [];
        }

        throw $this->exceptionFor($status, $payload);
    }

    /**
     * @return array<string, mixed>
     */
    private function object(string $body): array
    {
        try {
            $decoded = json_decode($body, associative: false, depth: 512, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new MalformedResponseException('Payment response is not valid JSON', previous: $exception);
        }

        if (!$decoded instanceof \stdClass) {
            throw new MalformedResponseException('Payment response must be a JSON object');
        }

        /** @var array<string, mixed> $decoded JSON object property names are strings. */
        $decoded = get_object_vars($decoded);

        return $decoded;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function exceptionFor(int $status, array $payload): PaymentException
    {
        /** @var array<string, mixed> $error JSON object property names are strings. */
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
     */
    private function truncate(string $value): string
    {
        return Utf8Text::truncate(value: $value, maximumBytes: self::MESSAGE_MAXIMUM_LENGTH);
    }

    /**
     * @param array<string, mixed> $value
     */
    private function stringValue(array $value, string $key): ?string
    {
        return isset($value[$key]) && is_string($value[$key]) ? $this->truncate($value[$key]) : null;
    }
}
