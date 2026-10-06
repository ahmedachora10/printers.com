<?php

namespace App\Jobs;

use App\Models\User;
use App\Notifications\MediaZipReadyNotification;
use App\Support\MediaZip;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * A ZIP over MediaZip::SYNC_LIMIT files, built off the request. It lands on the
 * private disk under `zips/{user}/{uuid}/`, so the download route proves
 * ownership by path alone; the scheduler prunes it after 24 hours.
 */
class BuildMediaZipJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 600;

    /**
     * @param  array<int, array{0: int, 1: string}>  $files  [media id, basename without extension]
     */
    public function __construct(
        public readonly array $files,
        public readonly string $zipName,
        public readonly int $userId,
    ) {}

    public function handle(): void
    {
        $media = Media::query()->findMany(array_column($this->files, 0))->keyBy('id');
        $files = [];
        foreach ($this->files as [$id, $name]) {
            if (isset($media[$id]) && is_file($media[$id]->getPath())) {
                $files[] = [$media[$id], $name];
            }
        }

        $uuid = (string) Str::uuid();
        $dir = "zips/{$this->userId}/{$uuid}";
        Storage::disk('local')->makeDirectory($dir);
        MediaZip::build($files, Storage::disk('local')->path("{$dir}/{$this->zipName}"));

        User::find($this->userId)?->notify(new MediaZipReadyNotification($this->zipName, $uuid));
    }

    public function failed(): void
    {
        User::find($this->userId)?->notify(new MediaZipReadyNotification($this->zipName));
    }
}
