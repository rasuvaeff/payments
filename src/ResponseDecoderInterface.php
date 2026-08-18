<?php

declare(strict_types=1);

namespace Rasuvaeff\Payments;

use Psr\Http\Message\ResponseInterface;

/**
 * @api
 */
interface ResponseDecoderInterface
{
    /**
     * @return array<string, mixed>
     */
    public function decode(ResponseInterface $response): array;
}
