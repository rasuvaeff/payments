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

        // The media type is the builder's, not the caller's: an authentication
        // context carrying Content-Type must not decide how the body is read.
        return $request->withHeader('Content-Type', 'application/x-www-form-urlencoded');
    }
}
