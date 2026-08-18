<?php

declare(strict_types=1);

namespace Rasuvaeff\Payments;

/**
 * @api
 */
enum RefundState: string
{
    case Pending = 'pending';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    case Canceled = 'canceled';
}
