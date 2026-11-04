<?php

declare(strict_types=1);

use KaiHempel\TikTokUpload\Support\Utf16;

it('counts UTF-16 code units', function (string $value, int $expected): void {
    expect(Utf16::length($value))->toBe($expected);
})->with([
    'empty' => ['', 0],
    'ascii' => ['Herbst am See', 13],
    'umlauts count as one unit' => ['Über Größe', 10],
    'emoji outside the BMP count as two units' => ['😀', 2],
    'family emoji (ZWJ sequence)' => ['👨‍👩‍👧', 8],
    'hashtags with emoji' => ['#herbst 🍂', 10],
    'BMP symbol' => ['€', 1],
]);

it('differs from strlen and mb_strlen', function (): void {
    $value = 'Grüße 😀';

    expect(strlen($value))->toBe(12)
        ->and(mb_strlen($value))->toBe(7)
        ->and(Utf16::length($value))->toBe(8);
});
