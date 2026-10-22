<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

/** Terms and Privacy. If the super admin set an external URL in Settings, that one wins. */
class LegalController extends Controller
{
    public function terms(): Response|RedirectResponse
    {
        return $this->page('terms', config('hamsukhan.site.terms_url'));
    }

    public function privacy(): Response|RedirectResponse
    {
        return $this->page('privacy', config('hamsukhan.site.privacy_url'));
    }

    private function page(string $doc, ?string $external): Response|RedirectResponse
    {
        if ($external) {
            return redirect()->away($external);
        }

        return Inertia::render('Legal', [
            'doc' => $doc,
            'site' => config('hamsukhan.site.name'),
            'supportEmail' => config('hamsukhan.site.support_email'),
            'supportPhone' => config('hamsukhan.site.support_phone'),
            'updated' => '21 October 2026',
        ]);
    }
}
