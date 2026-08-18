<?php

declare(strict_types=1);

namespace Rasuvaeff\Payments\Tests;

use Rasuvaeff\Payments\CapturePaymentRequest;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Expect;
use Testo\Test;

#[Test]
#[Covers(CapturePaymentRequest::class)]
final class CapturePaymentRequestTest
{
    public function supportsPartialCaptureAmount(): void
    {
        $request = new CapturePaymentRequest(
            operationId: Fixtures::operation(),
            payment: Fixtures::payment(),
            amount: Fixtures::money(minorUnits: 400),
            idempotencyKey: 'capture-1',
        );

        Assert::same($request->amount?->minorUnits, 400);
        Assert::same($request->idempotencyKey, 'capture-1');
    }

    public function rejectsEmptyIdempotencyKey(): void
    {
        Expect::exception(\InvalidArgumentException::class);
        new CapturePaymentRequest(operationId: Fixtures::operation(), payment: Fixtures::payment(), idempotencyKey: '');
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
        new CapturePaymentRequest(operationId: Fixtures::operation(), payment: Fixtures::payment(), idempotencyKey: $key);
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

}
