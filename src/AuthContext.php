<?php

declare(strict_types=1);

namespace Rasuvaeff\Payments;

use Rasuvaeff\Payments\Internal\Types;

/**
 * Already-selected authentication headers for one provider call.
 *
 * It holds a live credential, so it keeps out of dumps and refuses
 * serialization: `var_dump()`/`print_r()` and Symfony VarDumper (`dump()`,
 * `dd()`) show the header names with redacted values, and `serialize()`
 * throws. `var_export()` and a bare `(array)` cast read the private property
 * directly and are not covered, nor is a dumper that ignores
 * `__debugInfo()` — do not use them on this object.
 *
 * @api
 *
 * @psalm-import-type AuthHeaderMap from Types
 */
final readonly class AuthContext
{
    private const string REDACTED = '[REDACTED]';

    /**
     * @param AuthHeaderMap $headers
     */
    private function __construct(
        #[\SensitiveParameter]
        private array $headers,
    ) {}

    /**
     * @return AuthHeaderMap
     */
    public function headers(): array
    {
        return $this->headers;
    }

    public static function bearer(
        #[\SensitiveParameter]
        string $token,
    ): self {
        if ($token === '') {
            throw new \InvalidArgumentException('Bearer token cannot be empty');
        }

        return new self(headers: ['Authorization' => 'Bearer ' . $token]);
    }

    public static function basic(
        string $username,
        #[\SensitiveParameter]
        string $password,
    ): self {
        if ($username === '' || $password === '') {
            throw new \InvalidArgumentException('Basic authentication credentials cannot be empty');
        }

        // RFC 7617: the server splits the decoded pair at the first colon, so
        // one in the user-id would silently move its tail into the password.
        if (str_contains($username, ':')) {
            throw new \InvalidArgumentException('Basic authentication username must not contain a colon');
        }

        return new self(headers: ['Authorization' => 'Basic ' . base64_encode($username . ':' . $password)]);
    }

    /**
     * @param array<array-key, string> $headers
     */
    public static function fromHeaders(
        #[\SensitiveParameter]
        array $headers,
    ): self {
        $normalized = [];

        foreach ($headers as $name => $value) {
            if (!is_string($name) || $name === '' || $value === '') {
                throw new \InvalidArgumentException('Authentication headers must contain non-empty names and values');
            }

            $normalized[$name] = $value;
        }

        return new self(headers: $normalized);
    }

    /**
     * The key is the private property's mangled name (`"\0" . class . "\0"
     * . property`), the one an `(array)` cast produces. Symfony VarDumper
     * reads the object through that cast and overlays `__debugInfo()` on
     * it: a matching key replaces the property, a plain `headers` key would
     * be shown beside the unredacted one.
     *
     * @return array<non-empty-string, array<string, string>>
     */
    public function __debugInfo(): array
    {
        return ["\0" . self::class . "\0headers" => array_map(static fn(string $value): string => self::REDACTED, $this->headers)];
    }

    public function __serialize(): array
    {
        throw new \LogicException('AuthContext must not be serialized');
    }

    /**
     * @param array<array-key, mixed> $data
     */
    public function __unserialize(array $data): void
    {
        throw new \LogicException('AuthContext must not be unserialized');
    }
}
