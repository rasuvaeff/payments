<?php

declare(strict_types=1);

namespace Rasuvaeff\Payments\Tests;

use Rasuvaeff\Payments\InvalidWebhook;
use Rasuvaeff\Payments\ValidWebhook;
use Rasuvaeff\Payments\WebhookValidationResult;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Expect;
use Testo\Test;

#[Test]
#[Covers(ValidWebhook::class)]
#[Covers(InvalidWebhook::class)]
final class WebhookValidationResultTest
{
    public function eachOutcomeCarriesOnlyItsOwnFields(): void
    {
        $valid = new ValidWebhook(providerEventId: 'evt_1');
        $invalid = new InvalidWebhook(reason: 'Invalid signature');

        Assert::instanceOf($valid, WebhookValidationResult::class);
        Assert::instanceOf($invalid, WebhookValidationResult::class);
        Assert::same($valid->providerEventId, 'evt_1');
        Assert::same($invalid->reason, 'Invalid signature');
    }

    public function acceptsMaximumLengths(): void
    {
        $eventId = str_repeat('e', 255);
        $reason = str_repeat('r', 1_024);

        Assert::same(new ValidWebhook(providerEventId: $eventId)->providerEventId, $eventId);
        Assert::same(new InvalidWebhook(reason: $reason)->reason, $reason);
    }

    public function rejectsEmptyEventId(): void
    {
        Expect::exception(\InvalidArgumentException::class)
            ->withMessage('Provider event id must be non-empty and at most 255 bytes');
        new ValidWebhook(providerEventId: '');
    }

    public function rejectsBlankEventId(): void
    {
        Expect::exception(\InvalidArgumentException::class);
        new ValidWebhook(providerEventId: '   ');
    }

    public function rejectsBlankReason(): void
    {
        Expect::exception(\InvalidArgumentException::class)
            ->withMessage('Webhook validation failure reason must be non-empty and at most 1024 bytes');
        new InvalidWebhook(reason: '   ');
    }

    public function rejectsOversizedEventId(): void
    {
        Expect::exception(\InvalidArgumentException::class);
        new ValidWebhook(providerEventId: str_repeat('a', 256));
    }

    public function rejectsOversizedReason(): void
    {
        Expect::exception(\InvalidArgumentException::class);
        new InvalidWebhook(reason: str_repeat('a', 1_025));
    }
}
