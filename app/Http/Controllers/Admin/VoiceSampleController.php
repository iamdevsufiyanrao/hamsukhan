<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\VoiceSample;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;

/** Super admin: the sample voices visitors can listen to on the landing page. */
class VoiceSampleController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Admin/VoiceSamples', [
            'samples' => VoiceSample::orderBy('sort')->orderBy('id')->get()
                ->map(fn (VoiceSample $s) => $s->forPlayer() + ['isActive' => $s->is_active, 'sort' => $s->sort]),
            'languages' => array_keys(config('hamsukhan.languages')),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:60'],
            'label' => ['nullable', 'string', 'max:120'],
            'language' => ['required', 'string', 'max:30'],
            'audio' => ['required', 'file', 'mimes:mp3,wav,m4a,ogg,webm,aac', 'max:10240'],
        ]);

        VoiceSample::create([
            'name' => $data['name'],
            'label' => $data['label'] ?? null,
            'language' => $data['language'],
            'path' => $request->file('audio')->store('voice-samples'), // private disk, never public
            'sort' => (int) VoiceSample::max('sort') + 1,
        ]);

        return back()->with('success', 'Sample voice added');
    }

    public function update(Request $request, VoiceSample $sample): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:60'],
            'label' => ['sometimes', 'nullable', 'string', 'max:120'],
            'is_active' => ['sometimes', 'boolean'],
            'sort' => ['sometimes', 'integer', 'min:0', 'max:999'],
        ]);
        $sample->update($data);

        return back()->with('success', 'Sample voice updated');
    }

    public function destroy(VoiceSample $sample): RedirectResponse
    {
        Storage::delete($sample->path);
        $sample->delete();

        return back()->with('success', 'Sample voice deleted');
    }
}
