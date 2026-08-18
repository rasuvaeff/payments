<?php

declare(strict_types=1);

namespace Rasuvaeff\Payments\Tests;

use Rasuvaeff\Payments\CaptureMethod;
use Rasuvaeff\Payments\ConfirmationMethod;
use Rasuvaeff\Payments\CreatePaymentRequest;
use Rasuvaeff\Payments\PaymentMethodReference;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Expect;
use Testo\Test;

#[Test]
#[Covers(CreatePaymentRequest::class)]
final class CreatePaymentRequestTest
{
    public function defaultsToAutomaticProcessing(): void
    {
        $request = new CreatePaymentRequest(
            operationId: Fixtures::operation(),
            amount: Fixtures::money(),
            paymentMethod: new PaymentMethodReference(id: 'pm_123'),
        );

        Assert::same($request->captureMethod, CaptureMethod::Automatic);
        Assert::same($request->confirmationMethod, ConfirmationMethod::Automatic);
        Assert::null($request->idempotencyKey);
    }

    public function rejectsEmptyIdempotencyKey(): void
    {
        Expect::exception(\InvalidArgumentException::class);
        new CreatePaymentRequest(
            operationId: Fixtures::operation(),
            amount: Fixtures::money(),
            paymentMethod: new PaymentMethodReference(id: 'pm_123'),
            idempotencyKey: '',
        );
    }

    public function rejectsEmptyDescription(): void
    {
        Expect::exception(\InvalidArgumentException::class);
        $this->request(description: '');
    }

    public function keepsScalarMetadata(): void
    {
        $request = $this->request(metadata: ['order_id' => '42', 'retry' => false, 'note' => null]);

        Assert::same($request->metadata['order_id'], '42');
        Assert::false($request->metadata['retry']);
        Assert::null($request->metadata['note']);
    }

    public function rejectsNonScalarMetadataValue(): void
    {
        Expect::exception(\InvalidArgumentException::class);
        $this->request(metadata: ['customer' => new \stdClass()]);
    }

    public function rejectsNestedMetadataValue(): void
    {
        Expect::exception(\InvalidArgumentException::class);
        $this->request(metadata: ['items' => ['sku' => 1]]);
    }

    public function rejectsNumericMetadataKey(): void
    {
        Expect::exception(\InvalidArgumentException::class);
        $this->request(metadata: [0 => 'value']);
    }

    public function rejectsEmptyMetadataKey(): void
    {
        Expect::exception(\InvalidArgumentException::class);
        $this->request(metadata: ['' => 'value']);
    }

    /**
     * @param array<array-key, mixed> $metadata
     */
    private function request(?string $description = null, array $metadata = []): CreatePaymentRequest
    {
        return new CreatePaymentRequest(
            operationId: Fixtures::operation(),
            amount: Fixtures::money(),
            paymentMethod: new PaymentMethodReference(id: 'pm_123'),
            description: $description,
            metadata: $metadata,
        );
    }

    /**
     * The key is forwarded verbatim into an `Idempotency-Key` header. A value
     * carrying a line break or a control byte is refused here, at the boundary
     * that owns it, instead of surfacing later as a PSR-7 transport error that
     * names neither the field nor the request.
     *
     * @param non-empty-string $key
     */
    #[DataProvider('headerUnsafeKeyProvider')]
    public function rejectsHeaderUnsafeIdempotencyKeys(string $key): void
    {
        Expect::exception(\InvalidArgumentException::class);
        new CreatePaymentRequest(
            operationId: Fixtures::operation(),
            amount: Fixtures::money(),
            paymentMethod: new PaymentMethodReference(id: 'pm_123'),
            idempotencyKey: $key,
        );
    }

    /** @return iterable<string, array{string}> */
    public static function headerUnsafeKeyProvider(): iterable
    {
        yield 'carriage return' => ["key\r\nX-Injected: 1"];
        yield 'newline' => ["key\nvalue"];
        yield 'null byte' => ["key\0value"];
        yield 'space' => ['key value'];
        yield 'delete byte' => ["key\x7Fvalue"];
    }

    /**
     * Omitting the payment method is the deferred flow — the provider creates
     * the payment with nothing attached and the customer picks a method
     * client-side.
     */
    public function acceptsARequestWithoutAPaymentMethod(): void
    {
        $request = new CreatePaymentRequest(
            operationId: Fixtures::operation(),
            amount: Fixtures::money(),
        );

        Assert::null($request->paymentMethod);
    }

}
