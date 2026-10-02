<?php

namespace App\Support;

use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use ZipArchive;

/**
 * Bundles media files into one ZIP download — the transfer receipts of the
 * sales report (task 106) and the expense attachments (task 161).
 */
class MediaZip
{
    /**
     * @param  array<int, array{0: Media, 1: string}>  $files  [media, basename without extension]
     */
    public static function download(array $files, string $zipName): BinaryFileResponse
    {
        $path = tempnam(sys_get_temp_dir(), 'mediazip');
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::OVERWRITE);

        $used = [];
        foreach ($files as [$media, $name]) {
            $name = str_replace(['/', '\\', ':', '*', '?', '"', '<', '>', '|'], '-', $name);
            $n = $used[$name] = ($used[$name] ?? 0) + 1;
            $zip->addFile($media->getPath(), $name.($n > 1 ? " ({$n})" : '').'.'.$media->extension);
        }
        $zip->close();

        return response()->download($path, $zipName)->deleteFileAfterSend();
    }
}
