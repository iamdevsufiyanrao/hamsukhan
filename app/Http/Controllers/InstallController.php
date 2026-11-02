<?php

namespace App\Http\Controllers;

use App\Models\Bot;
use App\Services\Integrations\UrlGuard;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use ZipArchive;

/**
 * Getting the call button onto the owner's site without a developer:
 * a ready WordPress plugin with their key inside, install steps emailed to whoever runs the site,
 * and a "check my website" that looks for the widget on their page.
 */
class InstallController extends Controller
{
    public static function snippet(Bot $bot): string
    {
        return '<script src="'.url('/widget.js').'" data-bot="'.$bot->public_key.'" defer></script>';
    }

    /** A WordPress plugin zip with this store's key built in: Plugins → Add New → Upload → Activate. */
    public function wordpressPlugin(Request $request): BinaryFileResponse
    {
        $bot = $request->user()->bot;
        $slug = 'hamsukhan-voice-receptionist';
        $php = str_replace(
            ['{{KEY}}', '{{HOST}}', '{{SITE}}'],
            [$bot->public_key, rtrim(url('/'), '/'), (string) config('hamsukhan.site.name')],
            (string) file_get_contents(resource_path('install/wordpress-plugin.php.txt')),
        );
        $readme = str_replace('{{SITE}}', (string) config('hamsukhan.site.name'), (string) file_get_contents(resource_path('install/wordpress-readme.txt')));

        $path = tempnam(sys_get_temp_dir(), 'hswp');
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::OVERWRITE);
        $zip->addFromString("{$slug}/{$slug}.php", $php);
        $zip->addFromString("{$slug}/readme.txt", $readme);
        $zip->close();

        return response()->download($path, "{$slug}.zip", ['Content-Type' => 'application/zip'])->deleteFileAfterSend();
    }

    /** Emails the code and the steps for every platform to the person who runs the website. */
    public function email(Request $request): RedirectResponse
    {
        $data = $request->validate(['email' => ['required', 'email', 'max:150'], 'note' => ['nullable', 'string', 'max:300']]);
        $bot = $request->user()->bot;
        $site = config('hamsukhan.site.name');
        $from = $request->user()->name.($bot->business_name ? " ({$bot->business_name})" : '');

        $text = implode("\n", array_filter([
            "Hi,\n\n{$from} would like the {$site} call button (an AI receptionist that answers website visitors) added to the website. It takes about 2 minutes.",
            $data['note'] ?? null,
            "\nTHE CODE (one line, put it just before </body> on every page):\n\n".self::snippet($bot),
            "\nWORDPRESS: install the plugin {$from} can download from {$site} → Bot Studio → Add to website → WordPress (Plugins → Add New Plugin → Upload Plugin → Activate). Or paste the line with any \"header & footer scripts\" plugin.",
            'SHOPIFY: Online Store → Themes → Customize → Footer → Add section → Custom Liquid → paste the line → Save.',
            'WIX: Settings → Custom code → + Add Custom Code → paste → All pages · Body - end → Apply.',
            'SQUARESPACE: Settings → Advanced → Code Injection → Footer → paste → Save.',
            'WEBFLOW: Site settings → Custom code → Footer code → paste → Save → Publish.',
            'ANY OTHER SITE / CUSTOM CODE: paste the line before </body> in the shared footer or layout template.',
            "\nWhen done, open the website once: a \"Talk to us\" button appears in the corner. Full guide: ".url('/docs#install'),
            "\nThanks!\n— sent from {$site} on behalf of {$from}",
        ]));

        try {
            Mail::raw($text, fn ($m) => $m->to($data['email'])->replyTo($request->user()->email, $request->user()->name)->subject("Please add the {$site} call button to our website"));
        } catch (\Throwable $e) {
            report($e);

            return back()->with('error', 'The email could not be sent right now. Copy the code and send it on WhatsApp instead.');
        }

        return back()->with('success', "Instructions sent to {$data['email']}");
    }

    /** Opens the owner's page and looks for this store's call button on it. */
    public function check(Request $request): JsonResponse
    {
        $bot = $request->user()->bot;
        $url = trim((string) $request->validate(['url' => ['required', 'string', 'max:300']])['url']);
        if (! preg_match('~^https?://~i', $url)) {
            $url = 'https://'.$url;
        }

        $html = null;
        $error = null;
        for ($hop = 0; $hop < 4 && $html === null && $error === null; $hop++) {
            // Every hop is checked: a redirect must not lead us into a private network (SSRF).
            if ($error = UrlGuard::check($url)) {
                break;
            }
            try {
                $res = Http::withoutRedirecting()->timeout(10)->connectTimeout(5)->withUserAgent('Mozilla/5.0 (compatible; HamsukhanInstallCheck/1.0)')->get($url);
            } catch (\Throwable) {
                $error = 'We could not open that website. Check the address.';
                break;
            }
            if ($res->redirect() && ($next = $res->header('Location'))) {
                $url = str_starts_with($next, 'http') ? $next : rtrim((string) preg_replace('~^(https?://[^/]+).*$~', '$1', $url), '/').'/'.ltrim($next, '/');

                continue;
            }
            $html = $res->successful() ? substr($res->body(), 0, 3_000_000) : null;
            $error = $html === null ? "The website answered with an error ({$res->status()})." : null;
        }

        $found = $html !== null && str_contains($html, $bot->public_key);
        $host = parse_url($url, PHP_URL_HOST);
        if ($found && ! $bot->installed_at) {
            $bot->forceFill(['installed_at' => now(), 'website' => $bot->website ?? $host])->save();
        }

        return response()->json([
            'found' => $found,
            'installed' => $bot->fresh()->installed_at !== null,
            'message' => match (true) {
                $found => 'Found it! The call button is on '.$host.'.',
                $error !== null => $error,
                $bot->installed_at !== null => 'We could not see the code in the page, but your call button has already loaded on your site, so it works. (Shopify adds it while the page loads.)',
                default => 'The code is not on that page yet. If you just added it, clear your site cache or wait a minute and check again.',
            },
        ]);
    }
}
