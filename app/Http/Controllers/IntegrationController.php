<?php

namespace App\Http\Controllers;

use App\Models\Booking;
use App\Models\Integration;
use App\Models\IntegrationDelivery;
use App\Services\Integrations\IntegrationDispatcher;
use App\Services\Integrations\PayloadBuilder;
use App\Services\Integrations\UrlGuard;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use ZipArchive;

class IntegrationController extends Controller
{
    public function __construct(private IntegrationDispatcher $dispatcher) {}

    public function index(Request $request): Response
    {
        $bot = $request->user()->bot;
        $integrations = $bot->integrations()->latest()->get();

        return Inertia::render('Integrations', [
            'integrations' => $integrations->map(fn (Integration $i) => [
                'id' => $i->id,
                'name' => $i->name,
                'kind' => $i->kind,
                'url' => $i->url,
                'events' => $i->events,
                'fieldMap' => $i->field_map ?? [],
                'isActive' => $i->is_active,
                'lastStatus' => $i->last_status,
                'lastDeliveryAt' => $i->last_delivery_at?->toIso8601String(),
                'secret' => $i->secret,
            ]),
            'deliveries' => IntegrationDelivery::whereIn('integration_id', $integrations->pluck('id'))->with('integration:id,name')->latest()->limit(30)->get()
                ->map(fn (IntegrationDelivery $d) => [
                    'id' => $d->id,
                    'integration' => $d->integration->name,
                    'event' => $d->event,
                    'isTest' => $d->is_test,
                    'status' => $d->status,
                    'attempts' => $d->attempts,
                    'code' => $d->response_code,
                    'response' => $d->response_body,
                    'reference' => $d->external_reference,
                    'customer' => $d->payload['booking']['customer_name'] ?? null,
                    'nextAttemptAt' => $d->next_attempt_at?->toIso8601String(),
                    'createdAt' => $d->created_at->toIso8601String(),
                ]),
            'sources' => PayloadBuilder::sources($bot->capture_fields ?? []),
            'events' => Integration::EVENTS,
            'max' => config('hamsukhan.integrations.max_per_bot'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $bot = $request->user()->bot;
        if ($bot->integrations()->count() >= config('hamsukhan.integrations.max_per_bot')) {
            return back()->with('error', 'You can have up to '.config('hamsukhan.integrations.max_per_bot').' connectors.');
        }
        $bot->integrations()->create($this->validated($request));

        return back()->with('success', 'Connector added, send a test to check it');
    }

    public function update(Request $request, Integration $integration): RedirectResponse
    {
        $this->authorizeOwner($request, $integration);
        $integration->update($this->validated($request));

        return back()->with('success', 'Connector saved');
    }

    public function toggle(Request $request, Integration $integration): RedirectResponse
    {
        $this->authorizeOwner($request, $integration);
        $integration->update(['is_active' => ! $integration->is_active]);

        return back()->with('success', $integration->is_active ? 'Connector turned on' : 'Connector paused');
    }

    public function rotateSecret(Request $request, Integration $integration): RedirectResponse
    {
        $this->authorizeOwner($request, $integration);
        $integration->update(['secret' => Integration::newSecret()]);

        return back()->with('success', 'New secret created, update it in your software');
    }

    public function destroy(Request $request, Integration $integration): RedirectResponse
    {
        $this->authorizeOwner($request, $integration);
        $integration->delete();

        return back()->with('success', 'Connector deleted');
    }

    /** Sends a realistic sample booking/order so the customer can check their side. */
    public function test(Request $request, Integration $integration): RedirectResponse
    {
        $this->authorizeOwner($request, $integration);
        $bot = $request->user()->bot;
        $type = str_starts_with($integration->events[0] ?? 'booking.created', 'order') ? 'order' : (str_starts_with($integration->events[0] ?? '', 'callback') ? 'callback' : 'booking');

        $sample = $bot->bookings()->where('type', $type)->latest()->first() ?? $bot->bookings()->make([
            'type' => $type, 'customer_name' => 'Test Customer', 'phone' => '0300 1234567', 'request' => 'Test '.$type.' from Hamsukhan',
            'preferred_time' => 'Tomorrow, 11:00 am', 'status' => 'new',
            'details' => collect($bot->capture_fields ?? [])->mapWithKeys(fn ($f) => [$f['key'] => 'sample '.$f['label']])->all() ?: null,
        ])->forceFill(['id' => 0]);
        $sample->setRelation('bot', $bot);

        $delivery = $this->dispatcher->sendTest($integration, $sample);

        return $delivery->status === 'delivered'
            ? back()->with('success', "Test delivered, your server answered {$delivery->response_code}".($delivery->external_reference ? " (ref {$delivery->external_reference})" : ''))
            : back()->with('error', 'Test failed'.($delivery->response_code ? ", HTTP {$delivery->response_code}" : '').': '.mb_strimwidth((string) $delivery->response_body, 0, 140, '…'));
    }

    public function retry(Request $request, IntegrationDelivery $delivery): RedirectResponse
    {
        $this->authorizeOwner($request, $delivery->integration);
        $delivery = $this->dispatcher->retryNow($delivery);

        return back()->with($delivery->status === 'delivered' ? 'success' : 'error', $delivery->status === 'delivered' ? 'Delivered' : 'Still failing, see the response below');
    }

    /** The Laravel/PHP package as a zip, for customers who don't use Composer. */
    public function package(): BinaryFileResponse
    {
        $dir = base_path('packages/hamsukhan-connector');
        $zipPath = storage_path('app/hamsukhan-connector.zip');

        $zip = new ZipArchive;
        $zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            $zip->addFile($file->getPathname(), 'hamsukhan-connector/'.str_replace('\\', '/', substr($file->getPathname(), strlen($dir) + 1)));
        }
        $zip->close();

        return response()->download($zipPath, 'hamsukhan-connector.zip')->deleteFileAfterSend();
    }

    private function validated(Request $request): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:60'],
            'kind' => ['required', Rule::in(Integration::KINDS)],
            'url' => ['required', 'url', 'max:500'],
            'events' => ['required', 'array', 'min:1'],
            'events.*' => [Rule::in(Integration::EVENTS)],
            'fieldMap' => ['nullable', 'array', 'max:30'],
            'fieldMap.*.target' => ['required', 'string', 'max:60'],
            'fieldMap.*.source' => ['nullable', 'string', 'max:60'],
            'fieldMap.*.value' => ['nullable', 'string', 'max:200'],
        ]);

        if (! config('hamsukhan.integrations.enabled')) {
            throw \Illuminate\Validation\ValidationException::withMessages(['url' => 'Connectors are turned off on this platform.']);
        }
        if ($data['kind'] === 'form' && ! config('hamsukhan.integrations.form_enabled')) {
            throw \Illuminate\Validation\ValidationException::withMessages(['kind' => 'Form connectors are not allowed, use a webhook.']);
        }
        if ($error = UrlGuard::check($data['url'])) {
            throw \Illuminate\Validation\ValidationException::withMessages(['url' => $error]);
        }
        if ($data['kind'] === 'form' && empty($data['fieldMap'])) {
            throw \Illuminate\Validation\ValidationException::withMessages(['fieldMap' => 'Map at least one form field.']);
        }

        return [
            'name' => $data['name'],
            'kind' => $data['kind'],
            'url' => $data['url'],
            'events' => array_values(array_unique($data['events'])),
            'field_map' => $data['kind'] === 'form' ? array_values($data['fieldMap']) : null,
        ];
    }

    private function authorizeOwner(Request $request, Integration $integration): void
    {
        abort_unless($integration->bot_id === $request->user()->bot->id, 404);
    }
}
