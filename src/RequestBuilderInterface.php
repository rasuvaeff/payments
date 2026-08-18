<?php

declare(strict_types=1);

namespace Rasuvaeff\Payments;

use Psr\Http\Message\RequestInterface;

/**
 * @api
 */
interface RequestBuilderInterface
{
    /**
     * @param array<string, mixed> $data
     */
    public function build(string $method, string $uri, array $data, AuthContext $auth): RequestInterface;
}
