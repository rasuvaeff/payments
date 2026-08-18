<?php

declare(strict_types=1);

namespace Rasuvaeff\Payments\Tests\Support;

use Rasuvaeff\Payments\WebhookInput;
use Rasuvaeff\Payments\WebhookProcessingResult;
use Rasuvaeff\Payments\WebhookProcessorInterface;

final readonly class ThrowingWebhookProcessor implements WebhookProcessorInterface
{
    #[\Override]
    public function process(WebhookInput $input): WebhookProcessingResult
    {
        throw new \RuntimeException('Sensitive persistence failure');
    }
}
