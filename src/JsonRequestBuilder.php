<?php

declare(strict_types=1);

namespace Rasuvaeff\Payments;

use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\StreamFactoryInterface;

/**
 * @api
 */
final readonly class JsonRequestBuilder implements RawJsonRequestBuilderInterface
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
        $encoded = json_encode($data === [] ? new \stdClass() : $data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        return $this->buildRawJson($method, $uri, $encoded, $auth);
    }

    #[\Override]
    public function buildRawJson(string $method, string $uri, string $body, AuthContext $auth): RequestInterface
    {
        if ($body === '') {
            throw new \InvalidArgumentException('Raw JSON body cannot be empty');
        }

        $request = $this->requestFactory->createRequest($method, $uri);
        $request = $request->withBody($this->streamFactory->createStream($body));

        return $this->withHeaders($request, $auth, 'application/json');
    }

    private function withHeaders(RequestInterface $request, AuthContext $auth, string $contentType): RequestInterface
    {
        foreach ($auth->headers() as $name => $value) {
            $request = $request->withHeader($name, $value);
        }

        // The media type is the builder's, not the caller's: an authentication
        // context carrying Content-Type must not decide how the body is read.
        return $request->withHeader('Content-Type', $contentType);
    }
}
