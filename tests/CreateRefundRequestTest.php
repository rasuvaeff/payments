<?php

declare(strict_types=1);

namespace Rasuvaeff\Payments\Tests;

use Rasuvaeff\Payments\CreateRefundRequest;
use Rasuvaeff\Payments\RefundReason;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Expect;
use Testo\Test;

#[Test]
#[Covers(CreateRefundRequest::class)]
final class CreateRefundRequestTest
{
    public function supportsPartialRefundAndExtensibleReason(): void
    {
        $request = new CreateRefundRequest(
            operationId: Fixtures::operation(),
            payment: Fixtures::payment(),
            amount: Fixtures::money(minorUnits: 250),
            reason: new RefundReason(value: 'duplicate'),
            idempotencyKey: 'refund-1',
        );

        Assert::same($request->amount?->minorUnits, 250);
        Assert::same($request->reason?->value, 'duplicate');
    }

    public function rejectsEmptyIdempotencyKey(): void
    {
        Expect::exception(\InvalidArgumentException::class);
        new CreateRefundRequest(operationId: Fixtures::operation(), payment: Fixtures::payment(), idempotencyKey: '');
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
        new CreateRefundRequest(operationId: Fixtures::operation(), payment: Fixtures::payment(), idempotencyKey: $key);
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
