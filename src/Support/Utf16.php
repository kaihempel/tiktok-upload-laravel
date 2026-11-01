<?php

declare(strict_types=1);

namespace Kuweh\TikTokUpload\Support;

final class Utf16
{
    /**
     * Number of UTF-16 code units, the unit TikTok uses for text limits.
     * Emoji outside the BMP count as two units, umlauts as one.
     */
    public static function length(string $value): int
    {
        if ($value === '') {
            return 0;
        }

        return intdiv(mb_strlen(mb_convert_encoding($value, 'UTF-16LE', 'UTF-8'), '8bit'), 2);
    }
}
