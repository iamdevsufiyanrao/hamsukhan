<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AdminAction;
use App\Services\PlatformSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * Super admin: brand media on the public disk — the logo (sidebar, home page, favicon) and the home page's
 * intro video with its cover image. Removing one falls back to the brand-kit file in public/brand.
 */
class MediaController extends Controller
{
    /** setting key => upload rules. SVG is not accepted: it can carry scripts and is served from our own domain. */
    private const KINDS = [
        'site_logo' => ['required', 'file', 'mimes:png,jpg,jpeg,webp', 'max:2048'],
        'intro_video' => ['required', 'file', 'mimes:mp4,webm', 'mimetypes:video/mp4,video/webm', 'max:204800'],
        'intro_poster' => ['required', 'file', 'mimes:png,jpg,jpeg,webp', 'max:4096'],
    ];

    public function __construct(private PlatformSettings $settings) {}

    public function store(Request $request, string $kind): RedirectResponse
    {
        abort_unless(isset(self::KINDS[$kind]), 404);
        $file = $request->validate(['file' => self::KINDS[$kind]], [
            'file.max' => $kind === 'intro_video' ? 'The video must be under 200 MB. For a longer video, use a YouTube link instead.' : 'The image is too big.',
            'file.uploaded' => 'The upload failed, most likely the file is bigger than the server allows. Try a smaller file or a YouTube link.',
        ])['file'];

        $this->deleteOld($kind);
        $path = $file->storeAs('site', $kind.'-'.now()->format('YmdHis').'.'.strtolower($file->extension()), 'public');
        $this->settings->save([$kind => Storage::disk('public')->url($path)], $request->user());
        AdminAction::record($request->user(), null, 'upload_media', ['kind' => $kind, 'size' => $file->getSize()]);

        return back()->with('success', ['site_logo' => 'Logo updated', 'intro_video' => 'Intro video uploaded', 'intro_poster' => 'Video cover updated'][$kind]);
    }

    public function destroy(Request $request, string $kind): RedirectResponse
    {
        abort_unless(isset(self::KINDS[$kind]), 404);
        $this->deleteOld($kind);
        $this->settings->save([$kind => null], $request->user());
        config([PlatformSettings::SCHEMA[$kind][0] => null]); // apply() skips empty values, so clear this request's copy too
        AdminAction::record($request->user(), null, 'remove_media', ['kind' => $kind]);

        return back()->with('success', 'Removed, the default brand file is used again');
    }

    /** Deletes our previous upload for this slot (never anything outside storage/app/public/site). */
    private function deleteOld(string $kind): void
    {
        $url = (string) config(PlatformSettings::SCHEMA[$kind][0]);
        $prefix = rtrim(Storage::disk('public')->url('site/'), '/').'/';
        if (str_starts_with($url, $prefix)) {
            Storage::disk('public')->delete('site/'.basename($url));
        }
    }
}
