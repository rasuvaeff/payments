<?php

declare(strict_types=1);

namespace Rasuvaeff\Payments;

/**
 * Determines which durable boundary must complete before acknowledgement.
 *
 * @api
 */
enum WebhookAcknowledgementPolicy: string
{
    case AfterValidation = 'after_validation';
    case AfterPersistence = 'after_persistence';
}
