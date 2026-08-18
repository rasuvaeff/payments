<?php

declare(strict_types=1);

namespace Rasuvaeff\Payments;

/**
 * @api
 */
interface WebhookProcessorInterface
{
    /**
     * The return type stays open on purpose. A decorating processor may
     * short-circuit with its own outcome — throttled, tenant suspended, feature
     * disabled — without waiting for a release of this package. Code that
     * depends on the concrete {@see WebhookProcessor} gets the narrower union
     * and can narrow exhaustively; code that depends on this interface must
     * handle an unknown outcome.
     */
    public function process(WebhookInput $input): WebhookProcessingResult;
}
