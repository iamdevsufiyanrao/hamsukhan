<?php

namespace App\Http\Controllers;

use App\Models\KnowledgeFile;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class KnowledgeFileController extends Controller
{
    public function store(Request $request): RedirectResponse
    {
        $request->validate([
            'files' => ['required', 'array', 'max:10'],
            'files.*' => ['file', 'max:10240', 'mimes:pdf,txt,docx,csv,md'],
        ]);

        $bot = $request->user()->bot;
        foreach ($request->file('files') as $file) {
            $bot->knowledgeFiles()->create([
                'name' => $file->getClientOriginalName(),
                'path' => $file->store("knowledge/{$bot->id}"),
                'size_bytes' => $file->getSize(),
                'mime' => $file->getMimeType(),
                // Uploading to Vapi's knowledge base happens here once the Vapi key is set.
                'status' => 'indexed',
            ]);
        }

        return back()->with('success', count($request->file('files')).' file(s) added');
    }

    public function destroy(Request $request, KnowledgeFile $file): RedirectResponse
    {
        abort_unless($file->bot_id === $request->user()->bot->id, 404);
        Storage::delete($file->path);
        $file->delete();

        return back()->with('success', 'File removed');
    }
}
