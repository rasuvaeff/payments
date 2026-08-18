<?php

declare(strict_types=1);

use Nyholm\Psr7\Factory\Psr17Factory;
use Rasuvaeff\Payments\AuthContext;
use Rasuvaeff\Payments\JsonRequestBuilder;

require dirname(__DIR__) . '/vendor/autoload.php';

$factory = new Psr17Factory();
$request = (new JsonRequestBuilder($factory, $factory))->build(
    method: 'POST',
    uri: 'https://api.example.test/payments',
    data: ['amount' => 1_200, 'currency' => 'EUR'],
    auth: AuthContext::bearer('example-token'),
);

echo $request->getMethod() . ' ' . $request->getUri() . PHP_EOL;
echo $request->getHeaderLine('Content-Type') . PHP_EOL;
echo $request->getBody() . PHP_EOL;
