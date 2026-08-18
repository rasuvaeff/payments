<?php

declare(strict_types=1);

namespace Rasuvaeff\Payments\Tests;

use Rasuvaeff\Payments\RefundReference;
use Rasuvaeff\Payments\RetrieveRefundRequest;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

#[Test]
#[Covers(RetrieveRefundRequest::class)]
final class RetrieveRefundRequestTest
{
    public function carriesOperationAndRefundReference(): void
    {
        $request = new RetrieveRefundRequest(
            operationId: Fixtures::operation(),
            refund: new RefundReference(provider: Fixtures::provider(), id: 're_123'),
        );

        Assert::same($request->refund->id, 're_123');
    }
}
