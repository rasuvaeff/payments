<?php

declare(strict_types=1);

namespace Rasuvaeff\Payments\Internal;

/**
 * Byte limits shared by the webhook primitives.
 *
 * The processor guard and the result constructors validate the same provider
 * strings, so the values have to stay equal; keeping one copy is what makes
 * that guarantee structural rather than a convention.
 *
 * @internal
 */
final class WebhookLimits
{
    public const int PROVIDER_EVENT_ID = 255;
    public const int PROVIDER_EVENT_TYPE = 255;
    public const int REASON = 1_024;

    private function __construct() {}
}
