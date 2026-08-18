<?php

declare(strict_types=1);

namespace Rasuvaeff\Payments;

use Rasuvaeff\Payments\Internal\Assert;
use Rasuvaeff\Payments\Internal\Types;

/**
 * Exact webhook body plus request-scoped data needed for validation.
 *
 * @api
 *
 * @psalm-import-type HeaderMap from Types
 * @psalm-import-type RequestMetadata from Types
 */
final readonly class WebhookInput
{
    private const string REDACTED = '[REDACTED]';
    private const string HEADER_NAME_PATTERN = '/^[A-Za-z0-9_-]+\z/';
    private const int METADATA_KEY_MAXIMUM_LENGTH = 128;

    /** @var HeaderMap */
    private array $headers;

    /** @var RequestMetadata */
    private array $requestMetadata;

    /**
     * @param array<array-key, mixed> $headers
     * @param array<array-key, mixed> $requestMetadata
     */
    public function __construct(
        public string $rawBody,
        public PaymentProvider $provider,
        array $headers = [],
        array $requestMetadata = [],
    ) {
        $this->headers = $this->normalizeHeaders(headers: $headers);
        $this->requestMetadata = $this->normalizeMetadata(metadata: $requestMetadata);
    }

    /**
     * @return HeaderMap
     */
    public function headers(): array
    {
        return $this->headers;
    }

    /**
     * @param non-empty-string $name
     * @return list<string>
     */
    public function header(string $name): array
    {
        return $this->headers[strtolower($name)] ?? [];
    }

    /**
     * @return RequestMetadata
     */
    public function requestMetadata(): array
    {
        return $this->requestMetadata;
    }

    /**
     * @return HeaderMap
     */
    public function sanitizedHeaders(): array
    {
        $headers = $this->headers;

        foreach (array_keys($headers) as $name) {
            if ($this->isSensitiveName(name: $name)) {
                $headers[$name] = [self::REDACTED];
            }
        }

        return $headers;
    }

    /**
     * @return RequestMetadata
     */
    public function sanitizedRequestMetadata(): array
    {
        $metadata = $this->requestMetadata;

        foreach (array_keys($metadata) as $name) {
            if ($this->isSensitiveName(name: $name)) {
                $metadata[$name] = self::REDACTED;
            }
        }

        return $metadata;
    }

    /**
     * @param array<array-key, mixed> $headers
     * @return HeaderMap
     */
    private function normalizeHeaders(array $headers): array
    {
        $normalized = [];

        foreach (array_keys($headers) as $name) {
            if (!is_string($name)) {
                throw new \InvalidArgumentException('Webhook header names must be non-empty tokens');
            }

            Assert::matches(
                value: $name,
                pattern: self::HEADER_NAME_PATTERN,
                message: 'Webhook header names must be non-empty tokens',
            );

            $headerName = $name;
            $name = strtolower($name);

            if (is_string($headers[$headerName])) {
                $headerValues = [$headers[$headerName]];
            } elseif ($headers[$headerName] === []) {
                // An empty list would silently drop the header, and header()
                // would then answer exactly as it does for an absent one.
                throw new \InvalidArgumentException('Webhook header value lists must not be empty');
            } elseif (is_array($headers[$headerName]) && array_is_list($headers[$headerName])) {
                /** @var list<string> $headerValues */
                $headerValues = [];

                foreach (array_keys($headers[$headerName]) as $index) {
                    /** @var mixed $value */
                    $value = $headers[$headerName][$index];

                    if (!is_string($value)) {
                        throw new \InvalidArgumentException('Webhook header values must be strings or lists of strings');
                    }

                    $headerValues[] = $value;
                }
            } else {
                throw new \InvalidArgumentException('Webhook header values must be strings or lists of strings');
            }

            foreach ($headerValues as $value) {
                if (str_contains($value, "\r") || str_contains($value, "\n")) {
                    throw new \InvalidArgumentException('Webhook header values must be strings without line breaks');
                }

                $normalized[$name][] = $value;
            }
        }

        return $normalized;
    }

    /**
     * @param array<array-key, mixed> $metadata
     * @return RequestMetadata
     */
    private function normalizeMetadata(array $metadata): array
    {
        $normalized = [];

        foreach ($metadata as $name => $value) {
            if (!is_string($name) || $name === '' || strlen($name) > self::METADATA_KEY_MAXIMUM_LENGTH) {
                throw new \InvalidArgumentException('Webhook request metadata requires non-empty string keys');
            }

            if (!is_scalar($value) && $value !== null) {
                throw new \InvalidArgumentException('Webhook request metadata values must be scalar or null');
            }

            $normalized[$name] = $value;
        }

        return $normalized;
    }

    private function isSensitiveName(string $name): bool
    {
        // Best-effort denial list, not a guarantee: it names the credential
        // classes seen on payment webhooks, and an unknown vendor prefix can
        // still slip through. Logging policy stays the caller's job.
        return preg_match(
            '/authorization|cookie|signature|(?:^|[-_])sig(?:$|[-_])|secret|token|api[-_]?key|credential|password|passwd|bearer|session|private/i',
            $name,
        ) === 1;
    }
}
