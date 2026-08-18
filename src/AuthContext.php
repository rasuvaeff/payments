<?php

declare(strict_types=1);

namespace Rasuvaeff\Payments;

use Rasuvaeff\Payments\Internal\Types;

/**
 * @api
 *
 * @psalm-import-type AuthHeaderMap from Types
 */
final readonly class AuthContext
{
    /**
     * @param AuthHeaderMap $headers
     */
    private function __construct(private array $headers) {}

    /**
     * @return AuthHeaderMap
     */
    public function headers(): array
    {
        return $this->headers;
    }

    public static function bearer(string $token): self
    {
        if ($token === '') {
            throw new \InvalidArgumentException('Bearer token cannot be empty');
        }

        return new self(headers: ['Authorization' => 'Bearer ' . $token]);
    }

    public static function basic(string $username, string $password): self
    {
        if ($username === '' || $password === '') {
            throw new \InvalidArgumentException('Basic authentication credentials cannot be empty');
        }

        return new self(headers: ['Authorization' => 'Basic ' . base64_encode($username . ':' . $password)]);
    }

    /**
     * @param array<array-key, string> $headers
     */
    public static function fromHeaders(array $headers): self
    {
        $normalized = [];

        foreach ($headers as $name => $value) {
            if (!is_string($name) || $name === '' || $value === '') {
                throw new \InvalidArgumentException('Authentication headers must contain non-empty names and values');
            }

            $normalized[$name] = $value;
        }

        return new self(headers: $normalized);
    }
}
