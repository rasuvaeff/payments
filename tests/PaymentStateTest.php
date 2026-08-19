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

    #[Property(runs: 500, auto: true)]
    public function transitionTableMatchesDocumentedOracle(PaymentState $from, PaymentState $to): void
    {
        $expected = match ($from) {
            PaymentState::Pending => true,
            PaymentState::RequiresPaymentMethod => in_array($to, [
                PaymentState::Pending,
                PaymentState::RequiresPaymentMethod,
                PaymentState::RequiresAction,
                PaymentState::Processing,
                PaymentState::RequiresCapture,
                PaymentState::Succeeded,
                PaymentState::Failed,
                PaymentState::Canceled,
            ], strict: true),
            PaymentState::RequiresAction => in_array($to, [
                PaymentState::Pending,
                PaymentState::RequiresPaymentMethod,
                PaymentState::RequiresAction,
                PaymentState::Processing,
                PaymentState::RequiresCapture,
                PaymentState::Succeeded,
                PaymentState::Failed,
                PaymentState::Canceled,
            ], strict: true),
            PaymentState::RequiresCapture => in_array($to, [
                PaymentState::RequiresCapture,
                PaymentState::Processing,
                PaymentState::Succeeded,
                PaymentState::Failed,
                PaymentState::Canceled,
            ], strict: true),
            PaymentState::Processing => in_array($to, [
                PaymentState::Processing,
                PaymentState::RequiresPaymentMethod,
                PaymentState::RequiresAction,
                PaymentState::RequiresCapture,
                PaymentState::Succeeded,
                PaymentState::Failed,
                PaymentState::Canceled,
            ], strict: true),
            PaymentState::Succeeded, PaymentState::Failed, PaymentState::Canceled => $from === $to,
        };

        Classify::when($from === PaymentState::Pending, 'pending origin');
        Classify::cover(
            in_array($from, [PaymentState::Succeeded, PaymentState::Failed, PaymentState::Canceled], strict: true),
            'terminal origin',
            5,
        );

        Assert::same($from->canTransitionTo($to), $expected);
    }

    /** @return iterable<string, array{PaymentState, PaymentState}> */
    public static function transitionTableMatchesDocumentedOracleExamples(): iterable
    {
        yield 'succeeded to failed' => [PaymentState::Succeeded, PaymentState::Failed];
        yield 'failed to processing' => [PaymentState::Failed, PaymentState::Processing];
        yield 'pending to succeeded' => [PaymentState::Pending, PaymentState::Succeeded];
        yield 'same state' => [PaymentState::Processing, PaymentState::Processing];
        yield 'declined card retried on the same intent' => [PaymentState::RequiresPaymentMethod, PaymentState::Succeeded];
        yield 'decline returns an actionable intent' => [PaymentState::RequiresAction, PaymentState::RequiresPaymentMethod];
    }
}
