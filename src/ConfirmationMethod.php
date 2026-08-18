<?php

declare(strict_types=1);

namespace Rasuvaeff\Payments;

/**
 * @api
 */
enum ConfirmationMethod: string
{
    case Automatic = 'automatic';
    case Manual = 'manual';
}
