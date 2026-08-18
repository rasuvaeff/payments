<?php

declare(strict_types=1);

namespace Rasuvaeff\Payments;

use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * @api
 */
interface TransportInterface
{
    public function send(RequestInterface $request): ResponseInterface;
}
