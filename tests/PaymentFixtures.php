<?php

declare(strict_types=1);

namespace Rasuvaeff\Payments\Tests;

use Rasuvaeff\Payments\CreatePaymentRequest;
use Rasuvaeff\Payments\Money;
use Rasuvaeff\Payments\OperationId;
use Rasuvaeff\Payments\PaymentMethodReference;

final readonly class PaymentFixtures
{
    public static function createRequest(): CreatePaymentRequest
    {
        return new CreatePaymentRequest(
            operationId: new OperationId(value: 'op_1'),
            amount: new Money(minorUnits: 100, currency: 'USD'),
            paymentMethod: new PaymentMethodReference(id: 'pm_1', kind: 'card'),
            idempotencyKey: 'attempt_1',
        );
    }
}
