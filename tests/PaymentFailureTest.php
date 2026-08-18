<?php

declare(strict_types=1);

namespace Rasuvaeff\Payments\Tests;

use Rasuvaeff\Payments\PaymentFailure;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Expect;
use Testo\Test;

#[Test]
#[Covers(PaymentFailure::class)]
final class PaymentFailureTest
{
    public function keepsSanitizedFailureDetails(): void
    {
        $failure = new PaymentFailure(code: 'card_declined', message: 'Declined', retryable: false, details: ['reason' => 'do_not_honor']);

        Assert::same($failure->code, 'card_declined');
        Assert::same($failure->details['reason'], 'do_not_honor');
    }

    public function rejectsEmptyCodeAndMessage(): void
    {
        Expect::exception(\InvalidArgumentException::class);
        new PaymentFailure(code: '');
    }

    public function rejectsPresentEmptyMessage(): void
    {
        Expect::exception(\InvalidArgumentException::class);
        new PaymentFailure(code: 'declined', message: '');
    }

    public function rejectsNonScalarDetailValue(): void
    {
        Expect::exception(\InvalidArgumentException::class);
        new PaymentFailure(code: 'declined', details: ['response' => new \stdClass()]);
    }

    public function rejectsNestedDetailValue(): void
    {
        Expect::exception(\InvalidArgumentException::class);
        new PaymentFailure(code: 'declined', details: ['response' => ['code' => 51]]);
    }

    public function rejectsNumericDetailKey(): void
    {
        Expect::exception(\InvalidArgumentException::class);
        new PaymentFailure(code: 'declined', details: [0 => 'do_not_honor']);
    }

    public function rejectsEmptyDetailKey(): void
    {
        Expect::exception(\InvalidArgumentException::class);
        new PaymentFailure(code: 'declined', details: ['' => 'do_not_honor']);
    }

    public function acceptsNullDetailValue(): void
    {
        Assert::null((new PaymentFailure(code: 'declined', details: ['network_code' => null]))->details['network_code']);
    }
}
