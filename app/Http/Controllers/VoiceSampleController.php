<?php

namespace App\Http\Controllers;

use App\Models\VoiceSample;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use Symfony\Component\HttpFoundation\Response;

class VoiceSampleController extends Controller
{
    /** Public landing page. */
    public function landing(): InertiaResponse
    {
        return Inertia::render('Landing', [
            'samples' => VoiceSample::where('is_active', true)->orderBy('sort')->orderBy('id')->get()->map->forPlayer(),
        ]);
    }

    /**
     * Streams a sample for the in-page player only: signed + expiring URL, never cached, and the bytes are
     * XOR-scrambled so saving the response gives an unplayable file. The page unscrambles it in memory.
     */
    public function play(Request $request, VoiceSample $sample): Response
    {
        abort_unless($sample->is_active || $request->user()?->is_admin, 404);
        abort_unless(Storage::exists($sample->path), 404);

        $key = hex2bin($sample->scrambleKey());
        $data = Storage::get($sample->path);
        $scrambled = $data ^ str_repeat($key, intdiv(strlen($data), strlen($key)) + 1);

        return response(substr($scrambled, 0, strlen($data)), 200, [
            'Content-Type' => 'application/octet-stream',
            'Content-Disposition' => 'inline; filename="sample.bin"',
            'Cache-Control' => 'no-store, private',
            'X-Content-Type-Options' => 'nosniff',
            'X-Robots-Tag' => 'noindex',
        ]);
    }
}
