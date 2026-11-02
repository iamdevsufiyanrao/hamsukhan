<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AdminAction;
use App\Models\Call;
use App\Models\TopUp;
use App\Models\User;
use App\Models\Bot;
use App\Services\SubscriptionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

/** Super admin: every customer account, with impersonation, suspension and setting their plan by hand. */
class AccountController extends Controller
{
    public function overview(): Response
    {
        $monthStart = now()->startOfMonth();

        return Inertia::render('Admin/Overview', [
            'stats' => [
                'accounts' => User::where('is_admin', false)->count(),
                'newThisMonth' => User::where('is_admin', false)->where('created_at', '>=', $monthStart)->count(),
                'suspended' => User::whereNotNull('suspended_at')->count(),
                'callsToday' => Call::where('started_at', '>=', now()->startOfDay())->count(),
                'minutesThisMonth' => (int) round(Call::where('started_at', '>=', $monthStart)->sum('duration_seconds') / 60),
                'revenueThisMonthPkr' => (int) TopUp::where('status', 'approved')->whereNotNull('plan')->where('reviewed_at', '>=', $monthStart)->sum('amount_pkr'),
                // What Vapi charged for this month's calls (USD), to compare with plan revenue.
                'providerCostThisMonth' => round((float) Call::where('started_at', '>=', $monthStart)->sum('cost'), 2),
                'activePlans' => collect(array_keys(config('hamsukhan.plans')))->mapWithKeys(fn ($p) => [$p => User::where('plan', $p)->where('plan_expires_at', '>', now())->count()]),
                'pendingTopUps' => TopUp::awaitingReview()->count(),
            ],
            'recentActions' => AdminAction::with('admin:id,name', 'target:id,name')->latest('created_at')->limit(10)->get()->map(fn (AdminAction $a) => $this->actionRow($a)),
        ]);
    }

    public function index(Request $request): Response
    {
        $q = trim((string) $request->query('q', ''));
        $status = in_array($request->query('status'), ['active', 'suspended'], true) ? $request->query('status') : '';

        $page = User::where('is_admin', false)->whereNull('owner_id') // team members belong to their owner's account
            ->with('bot:id,user_id,business_name,is_active')->withCount('bots')
            ->withCount(['topUps as pending_topups' => fn ($t) => $t->awaitingReview()])
            ->when($q, fn ($w) => $w->where(fn ($x) => $x->where('name', 'like', "%{$q}%")->orWhere('email', 'like', "%{$q}%")
                ->orWhereHas('bot', fn ($b) => $b->where('business_name', 'like', "%{$q}%"))))
            ->when($status === 'suspended', fn ($w) => $w->whereNotNull('suspended_at'))
            ->when($status === 'active', fn ($w) => $w->whereNull('suspended_at'))
            ->latest()->paginate(20)->withQueryString();

        $since = now()->subDays(30);
        $calls = Call::join('bots', 'bots.id', '=', 'calls.bot_id')->whereIn('bots.user_id', collect($page->items())->pluck('id'))
            ->where('calls.started_at', '>=', $since)->selectRaw('bots.user_id as uid, count(*) n')->groupBy('bots.user_id')->pluck('n', 'uid');

        return Inertia::render('Admin/Accounts', [
            'accounts' => collect($page->items())->map(fn (User $u) => [
                'id' => $u->id,
                'name' => $u->name,
                'email' => $u->email,
                'business' => $u->bot?->business_name,
                'plan' => $u->activePlan() ? $u->planLimits()['name'] : null,
                'planExpiresAt' => $u->plan_expires_at?->toIso8601String(),
                'stores' => $u->bots_count,
                'calls30' => (int) ($calls[$u->id] ?? 0),
                'pendingTopUps' => $u->pending_topups,
                'suspended' => $u->isSuspended(),
                'createdAt' => $u->created_at->toIso8601String(),
            ]),
            'pagination' => ['page' => $page->currentPage(), 'lastPage' => $page->lastPage(), 'total' => $page->total()],
            'filters' => ['q' => $q, 'status' => $status],
        ]);
    }

    public function show(User $user): Response
    {
        abort_if($user->is_admin, 404);
        $bot = $user->bot()->with('voice')->withCount(['calls', 'bookings', 'integrations'])->first();

        return Inertia::render('Admin/AccountShow', [
            'account' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'timezone' => $user->timezone,
                'plan' => $user->activePlan(),
                'lastPlan' => $user->plan,
                'planExpiresAt' => $user->plan_expires_at?->toIso8601String(),
                'suspended' => $user->isSuspended(),
                'suspendedReason' => $user->suspended_reason,
                'suspendedAt' => $user->suspended_at?->toIso8601String(),
                'createdAt' => $user->created_at->toIso8601String(),
            ],
            'bot' => $bot ? [
                'business' => $bot->business_name,
                'website' => $bot->website,
                'language' => $bot->language,
                'voice' => $bot->voiceLabel(),
                'installed' => $bot->installed_at !== null,
                'calls' => $bot->calls_count,
                'bookings' => $bot->bookings_count,
                'integrations' => $bot->integrations_count,
                'vapiAssistantId' => $bot->vapi_assistant_id,
            ] : null,
            'stores' => $user->bots()->withCount('calls')->get()->map(fn (Bot $b) => ['id' => $b->id, 'name' => $b->business_name ?: 'Store #'.$b->id, 'website' => $b->website, 'calls' => $b->calls_count, 'active' => $b->is_active]),
            'usage' => app(SubscriptionService::class)->summary($user),
            'plans' => collect(config('hamsukhan.plans'))->map(fn ($p, $k) => ['id' => $k, 'name' => $p['name']])->values(),
            'payments' => $user->topUps()->latest()->limit(15)->get()->map(fn (TopUp $t) => [
                'id' => $t->id, 'date' => $t->created_at->toIso8601String(), 'plan' => $t->planName(), 'months' => $t->months, 'amountPkr' => (float) $t->amount_pkr, 'status' => $t->status,
            ]),
            'calls' => Call::whereIn('bot_id', $user->bots()->select('bots.id'))->latest('started_at')->limit(8)->get()->map->toRow(),
            'actions' => AdminAction::with('admin:id,name', 'target:id,name')->where('target_user_id', $user->id)->latest('created_at')->limit(20)->get()->map(fn (AdminAction $a) => $this->actionRow($a)),
        ]);
    }

    public function suspend(Request $request, User $user): RedirectResponse
    {
        abort_if($user->is_admin, 403);
        $reason = $request->validate(['reason' => ['required', 'string', 'max:200']])['reason'];
        $user->forceFill(['suspended_at' => now(), 'suspended_reason' => $reason])->save();
        Bot::where('user_id', $user->id)->update(['is_active' => false]); // every store's widget stops taking calls
        AdminAction::record($request->user(), $user, 'suspend', ['reason' => $reason]);

        return back()->with('success', "{$user->name} suspended, their bot is offline");
    }

    public function activate(Request $request, User $user): RedirectResponse
    {
        $user->forceFill(['suspended_at' => null, 'suspended_reason' => null])->save();
        Bot::where('user_id', $user->id)->update(['is_active' => true]);
        AdminAction::record($request->user(), $user, 'activate');

        return back()->with('success', "{$user->name} is active again");
    }

    /** Set a plan by hand (cash payment, a free month, a correction) or end it now. */
    public function setPlan(Request $request, User $user, SubscriptionService $subscriptions): RedirectResponse
    {
        abort_if($user->is_admin || $user->isMember(), 403);
        $data = $request->validate([
            'plan' => ['required', 'in:none,'.implode(',', array_keys(config('hamsukhan.plans')))],
            'months' => ['required_unless:plan,none', 'nullable', 'integer', 'between:1,12'],
            'reason' => ['required', 'string', 'max:150'],
        ]);

        if ($data['plan'] === 'none') {
            $user->forceFill(['plan_expires_at' => now()])->save();
        } else {
            $user = $subscriptions->activate($user, $data['plan'], (int) $data['months']);
        }
        AdminAction::record($request->user(), $user, 'set_plan', ['plan' => $data['plan'], 'months' => $data['months'] ?? null, 'reason' => $data['reason']]);

        return back()->with('success', $data['plan'] === 'none' ? 'Plan ended' : 'Plan active until '.$user->plan_expires_at->format('j M Y'));
    }

    public function impersonate(Request $request, User $user): RedirectResponse
    {
        abort_if($user->is_admin || $request->session()->has('impersonator_id'), 403);
        $admin = $request->user();
        AdminAction::record($admin, $user, 'impersonate');

        Auth::login($user);
        $request->session()->regenerate();
        $request->session()->put('impersonator_id', $admin->id);

        return redirect()->route('dashboard')->with('success', "You're now viewing {$user->name}'s account");
    }

    public function stopImpersonating(Request $request): RedirectResponse
    {
        $adminId = $request->session()->pull('impersonator_id');
        abort_unless($adminId && ($admin = User::where('is_admin', true)->find($adminId)), 403);
        $viewed = $request->user();

        Auth::login($admin);
        $request->session()->regenerate();

        return redirect()->route('admin.accounts.show', $viewed)->with('success', 'Back to your super admin account');
    }

    private function actionRow(AdminAction $a): array
    {
        return [
            'id' => $a->id,
            'action' => $a->action,
            'admin' => $a->admin?->name,
            'target' => $a->target?->name,
            'targetId' => $a->target_user_id,
            'meta' => $a->meta,
            'at' => $a->created_at->toIso8601String(),
        ];
    }
}
