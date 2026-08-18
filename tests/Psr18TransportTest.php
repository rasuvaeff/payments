<?php

declare(strict_types=1);

namespace Rasuvaeff\Payments\Tests;

use Nyholm\Psr7\Request;
use Nyholm\Psr7\Response;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Rasuvaeff\Payments\Psr18Transport;
use Rasuvaeff\Payments\TransportException;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Expect;
use Testo\Test;

#[Test]
#[Covers(Psr18Transport::class)]
final class Psr18TransportTest
{
    public function delegatesExactlyOnce(): void
    {
        $response = new Response(status: 201);
        $client = new class ($response) implements ClientInterface {
            public int $calls = 0;

            public function __construct(private readonly ResponseInterface $response) {}

            #[\Override]
            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                ++$this->calls;

                return $this->response;
            }
        };

        $actual = (new Psr18Transport($client))->send(new Request(method: 'POST', uri: 'https://example.test'));

        Assert::same($actual, $response);
        Assert::same($client->calls, 1);
    }

    public function wrapsPsrTransportFailureWithoutRequestDetails(): void
    {
        $client = new class implements ClientInterface {
            #[\Override]
            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                throw new class ('socket exposed secret') extends \RuntimeException implements ClientExceptionInterface {};
            }
        };

        Expect::exception(TransportException::class)->withMessage('Payment transport failed');
        (new Psr18Transport($client))->send(new Request(method: 'GET', uri: 'https://example.test'));
    }
}
