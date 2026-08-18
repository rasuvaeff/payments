<?php

declare(strict_types=1);

namespace Rasuvaeff\Payments\Internal;

/**
 * @internal
 */
final class Assert
{
    /**
     * @psalm-assert non-empty-string $value
     */
    public static function nonEmpty(string $value, string $name, int $maximumLength): void
    {
        if ($value === '') {
            throw new \InvalidArgumentException($name . ' must not be empty');
        }

        if (strlen($value) > $maximumLength) {
            throw new \InvalidArgumentException($name . ' must not exceed ' . $maximumLength . ' bytes');
        }
    }

    /**
     * @psalm-assert non-empty-string $value
     */
    public static function nonBlank(string $value, string $name, int $maximumLength): void
    {
        if (trim($value) === '' || strlen($value) > $maximumLength) {
            throw new \InvalidArgumentException($name . ' must be non-empty and at most ' . $maximumLength . ' bytes');
        }
    }

    /**
     * Validates a value that is forwarded verbatim into an HTTP header.
     *
     * Header-bound values must not carry control bytes, spaces or DEL: a
     * PSR-7 implementation rejects them deep inside the transport, which
     * surfaces as a surprising transport error instead of a domain error at
     * the boundary that owns the value.
     *
     * @psalm-assert non-empty-string $value
     */
    public static function headerToken(string $value, string $name, int $maximumLength): void
    {
        if ($value === '' || strlen($value) > $maximumLength || preg_match('/[\\x00-\\x20\\x7F]/', $value) === 1) {
            throw new \InvalidArgumentException(
                $name . ' must be a non-empty token of at most ' . $maximumLength . ' bytes without control characters or spaces',
            );
        }
    }

    /**
     * @param non-empty-string $pattern
     * @psalm-assert non-empty-string $value
     */
    public static function matches(string $value, string $pattern, string $message): void
    {
        // The empty check is what makes the non-empty-string assertion sound:
        // a pattern that accepts '' would otherwise widen the caller's type.
        if ($value === '' || preg_match($pattern, $value) !== 1) {
            throw new \InvalidArgumentException($message);
        }
    }

    /**
     * @psalm-assert non-empty-string $value
     */
    public static function httpsUrl(string $value, string $name, int $maximumLength): void
    {
        $parts = parse_url($value);

        if (
            $parts === false
            || ($parts['scheme'] ?? null) !== 'https'
            || ($parts['host'] ?? null) === null
            || strlen($value) > $maximumLength
        ) {
            throw new \InvalidArgumentException($name . ' must be an absolute HTTPS URL');
        }
    }

    /**
     * @psalm-assert int<0, max> $value
     */
    public static function nonNegative(int $value, string $name): void
    {
        if ($value < 0) {
            throw new \InvalidArgumentException($name . ' cannot be negative');
        }
    }

    /**
     * @psalm-assert positive-int $value
     */
    public static function positive(int $value, string $name): void
    {
        if ($value < 1) {
            throw new \InvalidArgumentException($name . ' must be positive');
        }
    }

    /**
     * @param array<array-key, mixed> $value
     * @psalm-assert array<non-empty-string, scalar|null> $value
     */
    public static function scalarMap(array $value, string $name): void
    {
        foreach ($value as $key => $item) {
            if (!is_string($key) || $key === '') {
                throw new \InvalidArgumentException($name . ' keys must be non-empty strings');
            }

            if (!is_scalar($item) && $item !== null) {
                throw new \InvalidArgumentException($name . ' must contain scalar values');
            }
        }
    }

    private function __construct() {}
}
