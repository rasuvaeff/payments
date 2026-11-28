<?php

declare(strict_types=1);

namespace Rasuvaeff\Payments;

use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\StreamFactoryInterface;

/**
 * @api
 */
final readonly class FormRequestBuilder implements RequestBuilderInterface
{
    public function __construct(
        private RequestFactoryInterface $requestFactory,
        private StreamFactoryInterface $streamFactory,
    ) {}

    /**
     * @param array<string, mixed> $data
     */
    #[\Override]
    public function build(string $method, string $uri, array $data, AuthContext $auth): RequestInterface
    {
        foreach ($data as $value) {
            if (!is_scalar($value) && $value !== null) {
                throw new \InvalidArgumentException('Form data values must be scalar or null');
            }
        }

        $request = $this->requestFactory->createRequest($method, $uri)
            ->withBody($this->streamFactory->createStream(http_build_query($data, '', '&', PHP_QUERY_RFC3986)));

        foreach ($auth->headers() as $name => $value) {
            $request = $request->withHeader($name, $value);
        }

        // A GET, HEAD or DELETE with nothing to send has no body, so no media
        // type either (RFC 9110 gives such a body no defined semantics).
        if ($data === [] && $this->hasNoBodyByConvention(method: $method)) {
            return $request->withoutHeader('Content-Type');
        }

        // The media type is the builder's, not the caller's: an authentication
        // context carrying Content-Type must not decide how the body is read.
        return $request->withHeader('Content-Type', 'application/x-www-form-urlencoded');
    }

    /**
     * RFC 9110 §9.3.1, §9.3.2, §9.3.5: a GET, HEAD or DELETE body has no
     * defined semantics. Method names are case-sensitive (§9.1).
     */
    private function hasNoBodyByConvention(string $method): bool
    {
        return match ($method) {
            'GET', 'HEAD', 'DELETE' => true,
            default => false,
        };
    }
}
