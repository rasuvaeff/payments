<?php

declare(strict_types=1);

namespace Rasuvaeff\Payments\Tests;

use Rasuvaeff\Payments\PaymentProvider;
use Rasuvaeff\Payments\WebhookInput;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Expect;
use Testo\Test;

#[Test]
#[Covers(WebhookInput::class)]
final class WebhookInputTest
{
    public function preservesRawBodyAndNormalizesHeaders(): void
    {
        $input = new WebhookInput(
            rawBody: "{\"id\":\"evt_1\"}\n",
            provider: new PaymentProvider(value: 'fixture'),
            headers: [
                'X-Event' => 'first',
                'x-event' => ['second'],
            ],
            requestMetadata: ['request_id' => 'request-1', 'attempt' => 2],
        );

        Assert::same($input->rawBody, "{\"id\":\"evt_1\"}\n");
        Assert::same($input->headers(), ['x-event' => ['first', 'second']]);
        Assert::same($input->header('X-Event'), ['first', 'second']);
        Assert::same($input->header('Missing'), []);
        Assert::same($input->requestMetadata(), ['request_id' => 'request-1', 'attempt' => 2]);
    }

    public function redactsSensitiveHeadersAndMetadata(): void
    {
        $input = new WebhookInput(
            rawBody: '{}',
            provider: new PaymentProvider(value: 'fixture'),
            headers: [
                'Authorization' => 'Bearer secret',
                'Stripe-Signature' => 'signature',
                'PayPal-Transmission-Sig' => 'signature',
                'Content-Type' => 'application/json',
            ],
            requestMetadata: [
                'API_TOKEN' => 'secret',
                'request_id' => 'request-1',
            ],
        );

        Assert::same($input->sanitizedHeaders(), [
            'authorization' => ['[REDACTED]'],
            'stripe-signature' => ['[REDACTED]'],
            'paypal-transmission-sig' => ['[REDACTED]'],
            'content-type' => ['application/json'],
        ]);
        Assert::same($input->sanitizedRequestMetadata(), [
            'API_TOKEN' => '[REDACTED]',
            'request_id' => 'request-1',
        ]);
    }

    public function redactsVendorCredentialHeaders(): void
    {
        $input = new WebhookInput(
            rawBody: '{}',
            provider: new PaymentProvider(value: 'fixture'),
            headers: [
                'X-Amz-Credential' => 'AKIA/20260818/eu-central-1',
                'X-Session-Id' => 'session-1',
                'X-Vendor-Password' => 'hunter2',
                'User-Agent' => 'provider-webhooks/1.0',
            ],
        );

        Assert::same($input->sanitizedHeaders(), [
            'x-amz-credential' => ['[REDACTED]'],
            'x-session-id' => ['[REDACTED]'],
            'x-vendor-password' => ['[REDACTED]'],
            'user-agent' => ['provider-webhooks/1.0'],
        ]);
    }

    public function acceptsMaximumMetadataKeyLength(): void
    {
        $key = str_repeat('a', 128);
        $input = new WebhookInput(
            rawBody: '{}',
            provider: new PaymentProvider(value: 'fixture'),
            requestMetadata: [$key => 'value'],
        );

        Assert::same($input->requestMetadata(), [$key => 'value']);
    }

    #[DataProvider('invalidInputProvider')]
    public function rejectsInvalidHeadersAndMetadata(array $headers, array $metadata): void
    {
        Expect::exception(\InvalidArgumentException::class);
        new WebhookInput(
            rawBody: '{}',
            provider: new PaymentProvider(value: 'fixture'),
            headers: $headers,
            requestMetadata: $metadata,
        );
    }

    /**
     * @return iterable<string, array{array<array-key, mixed>, array<array-key, mixed>}>
     */
    public static function invalidInputProvider(): iterable
    {
        yield 'invalid header name' => [['bad header' => 'value'], []];
        yield 'carriage return injection' => [['x-value' => "value\rinjected"], []];
        yield 'line feed injection' => [['x-value' => "value\ninjected"], []];
        yield 'header injection' => [['x-value' => "value\r\ninjected"], []];
        yield 'non-list header values' => [['x-value' => [1 => 'value']], []];
        yield 'empty header value list' => [['x-value' => []], []];
        yield 'non-string header value' => [['x-value' => [1]], []];
        yield 'numeric metadata key' => [[], [0 => 'value']];
        yield 'empty metadata key' => [[], ['' => 'value']];
        yield 'oversized metadata key' => [[], [str_repeat('a', 129) => 'value']];
        yield 'nested metadata' => [[], ['request' => ['value']]];
    }
}
