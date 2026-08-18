<?php

declare(strict_types=1);

namespace Rasuvaeff\Payments\Tests;

use Rasuvaeff\Payments\RetrievePaymentRequest;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

#[Test]
#[Covers(RetrievePaymentRequest::class)]
final class RetrievePaymentRequestTest
{
    public function carriesOperationAndReference(): void
    {
        $request = new RetrievePaymentRequest(operationId: Fixtures::operation(), payment: Fixtures::payment());

        Assert::same($request->operationId->value, 'checkout-123');
        Assert::same($request->payment->id, 'pi_123');
    }
}
