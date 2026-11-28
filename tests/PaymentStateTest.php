<?php

declare(strict_types=1);

namespace Rasuvaeff\Payments\Tests;

use Rasuvaeff\Payments\PaymentState;
use Rasuvaeff\PropertyTesting\ArbitraryInterface;
use Rasuvaeff\PropertyTesting\Classify;
use Rasuvaeff\PropertyTesting\Gen;
use Rasuvaeff\PropertyTesting\Property;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Test;

#[Test]
#[Covers(PaymentState::class)]
final class PaymentStateTest
{
    #[DataProvider('transitionProvider')]
    public function exposesAdvisoryTransitions(PaymentState $from, PaymentState $to, bool $allowed): void
    {
        Assert::same($from->canTransitionTo($to), $allowed);
    }

    public static function transitionProvider(): iterable
    {
        yield 'same state is idempotent' => [PaymentState::Succeeded, PaymentState::Succeeded, true];
        yield 'pending may succeed' => [PaymentState::Pending, PaymentState::Succeeded, true];
        yield 'action may return to pending' => [PaymentState::RequiresAction, PaymentState::Pending, true];
        yield 'terminal success stays terminal' => [PaymentState::Succeeded, PaymentState::Failed, false];
        yield 'terminal failure stays terminal' => [PaymentState::Failed, PaymentState::Processing, false];
        yield 'declined card retries into processing' => [PaymentState::RequiresPaymentMethod, PaymentState::Processing, true];
        yield 'authorization settles' => [PaymentState::RequiresCapture, PaymentState::Succeeded, true];
        yield 'authorization cannot ask for another method' => [PaymentState::RequiresCapture, PaymentState::RequiresPaymentMethod, false];
        yield 'processing may need an action' => [PaymentState::Processing, PaymentState::RequiresAction, true];
        yield 'processing never returns to pending' => [PaymentState::Processing, PaymentState::Pending, false];
    }

    #[Property(runs: 300, auto: true)]
    public function terminalStatesOnlyAllowIdempotentObservation(PaymentState $terminal, PaymentState $target): void
    {
        Classify::cover($terminal === PaymentState::Succeeded, 'succeeded terminal', 5);
        Classify::cover($terminal === PaymentState::Failed, 'failed terminal', 5);
        Classify::cover($terminal === PaymentState::Canceled, 'canceled terminal', 5);

        Assert::same($terminal->canTransitionTo($target), $terminal === $target);
    }

    /** @return array<string, ArbitraryInterface> */
    public static function terminalStatesOnlyAllowIdempotentObservationGenerators(): array
    {
        return [
            'terminal' => Gen::elements([PaymentState::Succeeded, PaymentState::Failed, PaymentState::Canceled]),
        ];
    }

    /**
     * Every one of the 64 pairs, against a matrix written out by hand.
     *
     * A sampled property whose oracle restates the `match` in `src/` repeats
     * a wrong cell instead of catching it; this grid is the reviewed
     * specification: row = from, column = to, both in case order. `1`
     * allows the transition.
     */
    #[DataProvider('transitionMatrixProvider')]
    public function transitionTableMatchesTheReviewedMatrix(PaymentState $from, PaymentState $to, bool $allowed): void
    {
        Assert::same($from->canTransitionTo($to), $allowed);
    }

    /** @return iterable<string, array{PaymentState, PaymentState, bool}> */
    public static function transitionMatrixProvider(): iterable
    {
        $order = [
            PaymentState::Pending,
            PaymentState::RequiresPaymentMethod,
            PaymentState::RequiresAction,
            PaymentState::RequiresCapture,
            PaymentState::Processing,
            PaymentState::Succeeded,
            PaymentState::Failed,
            PaymentState::Canceled,
        ];
        //            to: pend rpm  ract rcap proc succ fail canc
        $matrix = [
            'pending' => '1    1    1    1    1    1    1    1',
            'requires_payment_method' => '1    1    1    1    1    1    1    1',
            'requires_action' => '1    1    1    1    1    1    1    1',
            'requires_capture' => '0    0    0    1    1    1    1    1',
            'processing' => '0    1    1    1    1    1    1    1',
            'succeeded' => '0    0    0    0    0    1    0    0',
            'failed' => '0    0    0    0    0    0    1    0',
            'canceled' => '0    0    0    0    0    0    0    1',
        ];

        foreach ($order as $from) {
            $cells = preg_split('/\s+/', $matrix[$from->value]);

            foreach ($order as $column => $to) {
                yield $from->value . ' -> ' . $to->value => [$from, $to, $cells[$column] === '1'];
            }
        }
    }
}
