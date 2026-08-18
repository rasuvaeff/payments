<?php

declare(strict_types=1);

namespace Rasuvaeff\Payments;

/**
 * Outcome of provider signature validation.
 *
 * Consumers narrow with `instanceof`; each outcome carries only the fields that
 * are meaningful for it, so no combination of nullable properties can describe
 * an impossible state.
 *
 * The set is closed, and PHP enforces it: `WebhookValidatorInterface::validate()`
 * declares a native union return type, so a validator can neither widen the
 * signature nor return another class. "Is this signature authentic?" has exactly
 * two answers; provider-specific data belongs to the mapping stage, not here.
 *
 * @api
 */
interface WebhookValidationResult {}
