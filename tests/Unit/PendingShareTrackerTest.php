<?php

declare(strict_types=1);

use Kuweh\TikTokUpload\Support\PendingShareTracker;

it('counts uploads in a sliding 24 hour window', function (): void {
    $tracker = new PendingShareTracker(cache()->store(), 2);

    $tracker->record('acc');
    expect($tracker->count('acc'))->toBe(1)->and($tracker->isLimitReached('acc'))->toBeFalse();

    $this->travel(12)->hours();
    $tracker->record('acc');
    expect($tracker->isLimitReached('acc'))->toBeTrue()
        ->and($tracker->count('other'))->toBe(0);

    $this->travel(13)->hours();
    expect($tracker->count('acc'))->toBe(1);

    $tracker->clear('acc');
    expect($tracker->count('acc'))->toBe(0);
});
