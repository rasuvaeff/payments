<?php

declare(strict_types=1);

namespace Rasuvaeff\Payments;

use Psr\Http\Message\RequestInterface;

/**
 * Builds a JSON request whose body is passed through byte for byte.
 *
 * Needed wherever a document that was signed elsewhere has to be forwarded for
 * verification. Decoding it and re-encoding it does not round-trip: PHP escapes
 * non-ASCII (`Café` becomes `Café`), renders an empty JSON object as an
 * empty array (`{}` becomes `[]`) and drops a trailing zero (`1.50` becomes
 * `1.5`). Whatever a verifier then attests, it is not the document that was
 * received — and the document that is acted upon is decoded separately, so the
 * two can differ.
 *
 * @api
 */
interface RawJsonRequestBuilderInterface extends RequestBuilderInterface
{
    /**
     * @param string $body already-encoded JSON, sent verbatim; must not be empty
     * @throws \InvalidArgumentException when the body is empty
     */
    public function buildRawJson(string $method, string $uri, string $body, AuthContext $auth): RequestInterface;
}
