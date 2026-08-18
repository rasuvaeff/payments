<?php

declare(strict_types=1);

namespace Rasuvaeff\Payments\Tests\Support;

use Rasuvaeff\Payments\WebhookInput;
use Rasuvaeff\Payments\WebhookProcessingResult;
use Rasuvaeff\Payments\WebhookProcessorInterface;

final class FakeWebhookProcessor implements WebhookProcessorInterface
{
    public ?WebhookInput $input = null;

    public function __construct(private readonly WebhookProcessingResult $result) {}

    #[\Override]
    public function process(WebhookInput $input): WebhookProcessingResult
    {
        $this->input = $input;

        return $this->result;
    }
}
