<?php

declare(strict_types=1);

namespace Rasuvaeff\Payments\Internal;

/**
 * @internal
 */
final class Utf8Text
{
    /**
     * Cuts by bytes, then walks back until the result is valid UTF-8 again:
     * a byte cap can land inside a multi-byte sequence, and a broken sequence
     * would make the log record carrying the text unserializable.
     *
     * @param positive-int $maximumBytes
     */
    public static function truncate(string $value, int $maximumBytes): string
    {
        $capped = substr($value, 0, $maximumBytes);

        while (preg_match('//u', $capped) !== 1) {
            $capped = substr($capped, 0, -1);
        }

        return $capped;
    }

    private function __construct() {}
}
