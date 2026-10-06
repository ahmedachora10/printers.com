<?php

namespace App\Support;

use App\Jobs\BuildMediaZipJob;
use Illuminate\Http\RedirectResponse;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use ZipArchive;

/**
 * Bundles media files into one ZIP download — the transfer receipts of the
 * sales report (task 106) and the expense attachments (task 161).
 *
 * Up to SYNC_LIMIT files the ZIP is built in the request; above it a queued
 * job builds it and the requester gets a bell notification with the link.
 */
class MediaZip
{
    public const SYNC_LIMIT = 500;

    /**
     * @param  array<int, array{0: Media, 1: string}>  $files  [media, basename without extension]
     */
    public static function download(array $files, string $zipName): BinaryFileResponse|RedirectResponse
    {
        if (count($files) > self::SYNC_LIMIT) {
            BuildMediaZipJob::dispatch(
                array_map(fn (array $f) => [$f[0]->id, $f[1]], $files),
                $zipName,
                auth()->id(),
            );

            return back()->with('success', 'عدد الملفات '.count($files).' — جارٍ تجهيز الملف، وسيصلك إشعار حين يجهز.');
        }

        $path = tempnam(sys_get_temp_dir(), 'mediazip');
        self::build($files, $path);

        return response()->download($path, $zipName)->deleteFileAfterSend();
    }

    /**
     * @param  array<int, array{0: Media, 1: string}>  $files
     */
    public static function build(array $files, string $path): void
    {
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);

        $used = [];
        foreach ($files as [$media, $name]) {
            $name = str_replace(['/', '\\', ':', '*', '?', '"', '<', '>', '|'], '-', $name);
            $n = $used[$name] = ($used[$name] ?? 0) + 1;
            $zip->addFile($media->getPath(), $name.($n > 1 ? " ({$n})" : '').'.'.$media->extension);
        }
        $zip->close();
    }
}
