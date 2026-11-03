<?php

declare(strict_types=1);

namespace Rasuvaeff\Payments\Tests;

use Rasuvaeff\Payments\PaymentException;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

#[Test]
#[Covers(PaymentException::class)]
final class PaymentExceptionTest
{
    public function carriesTheMessageTheCauseAndTheProviderDetails(): void
    {
        $cause = new \RuntimeException('connection reset');

        $exception = new PaymentException(
            message: 'Card declined',
            providerCode: 'card_declined',
            providerType: 'card_error',
            providerParameter: 'number',
            details: ['attempt' => 2],
            previous: $cause,
        );

        Assert::same($exception->getMessage(), 'Card declined');
        Assert::same($exception->getPrevious(), $cause);
        Assert::same($exception->getCode(), 0);
        Assert::same($exception->providerCode, 'card_declined');
        Assert::same($exception->providerType, 'card_error');
        Assert::same($exception->providerParameter, 'number');
        Assert::same($exception->details, ['attempt' => 2]);
    }

    public function providerDetailsAreOptional(): void
    {
        $exception = new PaymentException('Oops');

        Assert::same($exception->getMessage(), 'Oops');
        Assert::null($exception->getPrevious());
        Assert::null($exception->providerCode);
        Assert::null($exception->providerType);
        Assert::null($exception->providerParameter);
        Assert::same($exception->details, []);
    }
}
