<?php

namespace App\Http\Controllers\Concerns;

use App\Support\Import\DryRunRollback;
use App\Support\Import\ImportReport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Maatwebsite\Excel\Facades\Excel;
use Throwable;

/**
 * The two-step import every import screen shares: **preview**, then
 * **commit**.
 *
 * Preview parks the uploaded sheet in private storage and runs the real
 * import inside a transaction it then rolls back. Running the same code as
 * the commit — rather than a second, "read-only" copy of the parsing rules —
 * is what makes the preview trustworthy: there is no second implementation to
 * drift out of step with the one that writes.
 *
 * Commit names the parked file by token, so the sheet that is written is
 * exactly the sheet that was shown, without a second upload. The token is
 * scoped to the uploading user's own folder; nobody can commit someone else's.
 */
trait RunsExcelImports
{
    /** Sheets are parked under the uploader's own folder and swept after this. */
    private const UPLOAD_TTL_HOURS = 6;

    /**
     * Park an upload and describe what importing it would do.
     *
     * @param  callable(bool): object  $factory  builds the import for a given dry-run flag
     */
    protected function previewImport(Request $request, callable $factory): JsonResponse
    {
        $request->validate([
            'file' => ['required', 'file', 'mimes:xlsx,xls,csv', 'max:10240'],
        ]);

        /** @var UploadedFile $file */
        $file = $request->file('file');

        $this->pruneStaleUploads($request);

        $token = Str::uuid()->toString().'.'.($file->getClientOriginalExtension() ?: 'xlsx');
        $file->storeAs($this->uploadDirectory($request), $token, 'local');

        $report = $this->runImport($factory(true), $file, dryRun: true);

        return response()->json([
            'token' => $token,
            'fileName' => $file->getClientOriginalName(),
            ...$report->toArray(),
        ]);
    }

    /**
     * Import the sheet a preview parked. Falls back to a freshly posted file
     * so the endpoint keeps working for a client that has no token — a direct
     * POST, or a test.
     *
     * @param  callable(bool): object  $factory
     */
    protected function commitImport(Request $request, callable $factory): JsonResponse
    {
        $request->validate([
            'token' => ['nullable', 'string', 'max:100'],
            'file' => ['required_without:token', 'file', 'mimes:xlsx,xls,csv', 'max:10240'],
        ]);

        $file = $request->filled('token')
            ? $this->parkedUpload($request, (string) $request->input('token'))
            : $request->file('file');

        $report = $this->runImport($factory(false), $file, dryRun: false);

        if ($request->filled('token')) {
            Storage::disk('local')->delete($this->uploadDirectory($request).'/'.basename((string) $request->input('token')));
        }

        return response()->json([
            'fileName' => $request->string('fileName')->value() ?: $file->getFilename(),
            ...$report->toArray(),
        ]);
    }

    /**
     * A dry run is a real run inside a transaction that never commits. The
     * rollback is deliberate, so the exception that triggers it is not an
     * error and must not escape.
     *
     * @param  object{report: ImportReport}  $import
     */
    private function runImport(object $import, UploadedFile $file, bool $dryRun): ImportReport
    {
        if (! $dryRun) {
            Excel::import($import, $file);

            return $import->report;
        }

        try {
            DB::transaction(function () use ($import, $file) {
                Excel::import($import, $file);

                throw new DryRunRollback;
            });
        } catch (DryRunRollback) {
            // expected: the preview wrote nothing
        }

        return $import->report;
    }

    private function uploadDirectory(Request $request): string
    {
        return 'imports/'.$request->user()->id;
    }

    private function parkedUpload(Request $request, string $token): UploadedFile
    {
        $path = $this->uploadDirectory($request).'/'.basename($token);

        if (! Storage::disk('local')->exists($path)) {
            throw ValidationException::withMessages([
                'token' => 'انتهت صلاحية الملف المرفوع، يرجى رفعه من جديد.',
            ]);
        }

        // $test = true: the file is ours, not a fresh HTTP upload, so the
        // is_uploaded_file() check would reject it.
        return new UploadedFile(Storage::disk('local')->path($path), basename($token), null, null, true);
    }

    /** Sheets whose preview was never confirmed do not live in storage forever. */
    private function pruneStaleUploads(Request $request): void
    {
        $disk = Storage::disk('local');
        $cutoff = now()->subHours(self::UPLOAD_TTL_HOURS)->getTimestamp();

        foreach ($disk->files($this->uploadDirectory($request)) as $path) {
            try {
                if ($disk->lastModified($path) < $cutoff) {
                    $disk->delete($path);
                }
            } catch (Throwable) {
                // a file that vanished under us needs no sweeping
            }
        }
    }
}
