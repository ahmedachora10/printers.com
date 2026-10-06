<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** ZIP بنته BuildMediaZipJob — مجلده تحت معرّف صاحبه، فلا يصل إليه غيره. */
class MediaZipController extends Controller
{
    public function show(Request $request, string $uuid): StreamedResponse
    {
        $file = Storage::disk('local')->files("zips/{$request->user()->id}/{$uuid}")[0] ?? null;
        abort_if($file === null, 404);

        return Storage::disk('local')->download($file);
    }
}
