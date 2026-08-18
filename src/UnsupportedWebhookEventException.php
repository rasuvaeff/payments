<?php

declare(strict_types=1);

namespace Rasuvaeff\Payments;

/**
 * Signals that a recognized provider event is intentionally unsupported.
 *
 * @api
 */
final class UnsupportedWebhookEventException extends \RuntimeException {}
