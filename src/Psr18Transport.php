<?php

declare(strict_types=1);

namespace Rasuvaeff\Payments;

use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * @api
 */
final readonly class Psr18Transport implements TransportInterface
{
    public function __construct(private ClientInterface $client) {}

    #[\Override]
    public function send(RequestInterface $request): ResponseInterface
    {
        try {
            return $this->client->sendRequest($request);
        } catch (ClientExceptionInterface $exception) {
            throw new TransportException('Payment transport failed', previous: $exception);
        }
    }
}
