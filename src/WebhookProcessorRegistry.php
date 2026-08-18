<?php

declare(strict_types=1);

namespace Rasuvaeff\Payments;

/**
 * @api
 */
final readonly class WebhookProcessorRegistry
{
    /** @var array<non-empty-string, WebhookProcessorInterface> */
    private array $processors;

    /** @param iterable<WebhookProcessorRegistration> $processors */
    public function __construct(iterable $processors = [])
    {
        $indexed = [];

        foreach ($processors as $registration) {
            $key = $registration->provider->value;

            if (isset($indexed[$key])) {
                throw new \InvalidArgumentException(sprintf('Duplicate webhook processor for provider "%s"', $key));
            }

            $indexed[$key] = $registration->processor;
        }

        $this->processors = $indexed;
    }

    public function has(PaymentProvider $provider): bool
    {
        return isset($this->processors[$provider->value]);
    }

    public function get(PaymentProvider $provider): WebhookProcessorInterface
    {
        return $this->processors[$provider->value]
            ?? throw new \OutOfBoundsException(sprintf('Webhook processor "%s" is not registered', $provider->value));
    }
}
