<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use KaiHempel\TikTokUpload\Data\MediaType;
use KaiHempel\TikTokUpload\Data\PublishStatus;
use KaiHempel\TikTokUpload\Events\TikTokUploadInitiated;
use KaiHempel\TikTokUpload\Exceptions\MediaValidationException;
use KaiHempel\TikTokUpload\Exceptions\UrlOwnershipUnverifiedException;
use KaiHempel\TikTokUpload\Facades\TikTok;
use KaiHempel\TikTokUpload\Media\PhotoSet;

const PHOTO_INIT_URL = 'open.tiktokapis.com/v2/post/publish/content/init/';

beforeEach(function (): void {
    Storage::fake('public');
    Event::fake([TikTokUploadInitiated::class]);
});

function putImage(string $path, int $width = 1080, int $height = 1350, string $format = 'jpeg'): void
{
    $image = imagecreatetruecolor($width, $height);
    ob_start();
    match ($format) {
        'png' => imagepng($image),
        'webp' => imagewebp($image),
        default => imagejpeg($image),
    };
    Storage::disk('public')->put($path, (string) ob_get_clean());
}

it('uploads a photo slideshow with title and description', function (): void {
    Http::fake([PHOTO_INIT_URL => Http::response(tiktokFixture('photo_init'))]);
    putImage('a.jpg');
    putImage('b.webp', format: 'webp');

    $result = TikTok::forAccount($accountId = createAccount()->id)->uploadPhotos(
        PhotoSet::fromDisk('public', ['a.jpg', 'b.webp'])
            ->title('Herbst am See')
            ->description('Ein ruhiger Morgen #herbst #natur')
            ->cover(1),
        reference: 'target-7',
    );

    expect($result->publishId)->toBe('p_pub_url~v2.123456789')
        ->and($result->status)->toBe(PublishStatus::ProcessingDownload)
        ->and($result->mediaType)->toBe(MediaType::Photo)
        ->and($result->reference)->toBe('target-7')
        ->and($result->captionForUser)->toBeNull()
        ->and($result->mediaUrls)->toHaveCount(2);

    Http::assertSent(function (Request $request) use ($result): bool {
        return $request->url() === 'https://open.tiktokapis.com/v2/post/publish/content/init/'
            && $request->data() === [
                'post_mode' => 'MEDIA_UPLOAD',
                'media_type' => 'PHOTO',
                'post_info' => [
                    'title' => 'Herbst am See',
                    'description' => 'Ein ruhiger Morgen #herbst #natur',
                ],
                'source_info' => [
                    'source' => 'PULL_FROM_URL',
                    'photo_cover_index' => 1,
                    'photo_images' => $result->mediaUrls,
                ],
            ];
    });

    expect($result->mediaUrls[0])->toStartWith('https://media.example.de/tiktok/media/')->toContain('.jpg?')
        ->and($result->mediaUrls[1])->toContain('.webp?');

    Event::assertDispatched(TikTokUploadInitiated::class, fn ($e): bool => $e->accountId === $accountId && $e->mediaType === MediaType::Photo);
});

it('omits empty post_info fields', function (): void {
    Http::fake([PHOTO_INIT_URL => Http::response(tiktokFixture('photo_init'))]);
    putImage('a.jpg');

    TikTok::forAccount(createAccount()->id)->uploadPhotos(PhotoSet::fromDisk('public', ['a.jpg']));

    Http::assertSent(fn (Request $request): bool => $request['post_info'] === []);
});

it('accepts photos that are already public', function (): void {
    Http::fake([PHOTO_INIT_URL => Http::response(tiktokFixture('photo_init'))]);

    TikTok::forAccount(createAccount()->id)->uploadPhotos(PhotoSet::fromUrls(['https://cdn.example.de/a.jpg']));

    Http::assertSent(fn (Request $request): bool => $request['source_info']['photo_images'] === ['https://cdn.example.de/a.jpg']);
});

it('rejects invalid photo sets before sending a request', function (): void {
    Http::fake();
    putImage('a.png', format: 'png');
    putImage('huge.jpg', 2000, 2000);

    try {
        TikTok::forAccount(createAccount()->id)->uploadPhotos(
            PhotoSet::fromDisk('public', ['a.png', 'huge.jpg', 'missing.jpg'])
                ->title(str_repeat('😀', 46))
                ->description(str_repeat('a', 4001))
                ->cover(3),
        );
        $this->fail('Expected exception');
    } catch (MediaValidationException $e) {
        $all = implode("\n", $e->violations);

        expect($e->violations)->toHaveCount(6)
            ->and($all)->toContain('cover index [3] is out of range')
            ->and($all)->toContain('title must not exceed 90 UTF-16 code units (92 given)')
            ->and($all)->toContain('description must not exceed 4000')
            ->and($all)->toContain('PNG is not supported')
            ->and($all)->toContain('2000x2000 exceeds 1080p')
            ->and($all)->toContain('does not exist');
    }

    Http::assertNothingSent();
});

it('accepts a title of exactly 90 UTF-16 code units', function (): void {
    Http::fake([PHOTO_INIT_URL => Http::response(tiktokFixture('photo_init'))]);
    putImage('a.jpg');

    TikTok::forAccount(createAccount()->id)->uploadPhotos(
        PhotoSet::fromDisk('public', ['a.jpg'])->title(str_repeat('😀', 45)),
    );

    Http::assertSentCount(1);
});

it('surfaces unverified media domains', function (): void {
    Http::fake([PHOTO_INIT_URL => Http::response(tiktokFixture('error_url_ownership_unverified'), 403)]);
    putImage('a.jpg');

    expect(fn () => TikTok::forAccount(createAccount()->id)->uploadPhotos(PhotoSet::fromDisk('public', ['a.jpg'])))
        ->toThrow(UrlOwnershipUnverifiedException::class);
});

it('keeps photo sets immutable', function (): void {
    $base = PhotoSet::fromDisk('public', ['a.jpg']);
    $titled = $base->title('Titel')->cover(0);

    expect($base->getTitle())->toBeNull()
        ->and($titled->getTitle())->toBe('Titel')
        ->and($titled->count())->toBe(1);
});
