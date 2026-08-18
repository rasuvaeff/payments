<?php

declare(strict_types=1);

namespace Rasuvaeff\Payments;

/**
 * Explicit outcome used by HTTP bridges to choose a safe acknowledgement.
 *
 * Consumers narrow with `instanceof`; each outcome carries only the fields that
 * are meaningful for it, so a processed result always has its event and a
 * failed one always has its reason.
 *
 * The set is deliberately open: a decorating processor may add its own outcome.
 * `WebhookProcessor::process()` narrows its return type to the five outcomes
 * this package ships, so calling the concrete processor allows an exhaustive
 * `instanceof` chain; calling through `WebhookProcessorInterface` does not.
 *
 * @api
 */
interface WebhookProcessingResult {}
