<?php

declare(strict_types=1);

namespace Rasuvaeff\Payments\Tests\Support;

use Rasuvaeff\Payments\PaymentProvider;
use Rasuvaeff\Payments\WebhookClaimToken;
use Rasuvaeff\Payments\WebhookEventStoreInterface;

/**
 * A claim store as the interface describes one: a claim per event id, a
 * fresh token per claim, finalisers that act only for the current token.
 */
final class InMemoryWebhookEventStore implements WebhookEventStoreInterface
{
    /** @var array<string, WebhookClaimToken> */
    public array $claims = [];

    /** @var array<string, true> */
    public array $completed = [];

    #[\Override]
    public function claim(PaymentProvider $provider, string $providerEventId): ?WebhookClaimToken
    {
        if (isset($this->completed[$providerEventId]) || isset($this->claims[$providerEventId])) {
            return null;
        }

        return $this->claims[$providerEventId] = WebhookClaimToken::generate();
    }

    #[\Override]
    public function complete(PaymentProvider $provider, string $providerEventId, WebhookClaimToken $token): void
    {
        if ($this->owns($providerEventId, $token)) {
            unset($this->claims[$providerEventId]);
            $this->completed[$providerEventId] = true;
        }
    }

    #[\Override]
    public function release(PaymentProvider $provider, string $providerEventId, WebhookClaimToken $token): void
    {
        if ($this->owns($providerEventId, $token)) {
            unset($this->claims[$providerEventId]);
        }
    }

    private function owns(string $providerEventId, WebhookClaimToken $token): bool
    {
        return isset($this->claims[$providerEventId]) && $this->claims[$providerEventId]->equals($token);
    }
}
