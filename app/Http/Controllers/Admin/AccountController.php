<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AdminAction;
use App\Models\Call;
use App\Models\TopUp;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Services\WalletService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

/** Super admin: every customer account, with impersonation, suspension and balance adjustments. */
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
                'revenueThisMonth' => (float) TopUp::where('status', 'approved')->where('reviewed_at', '>=', $monthStart)->sum('amount_usd'),
                'usageThisMonth' => (float) -WalletTransaction::where('type', 'call_charge')->where('created_at', '>=', $monthStart)->sum('amount'),
                'walletTotal' => (float) User::sum('balance'),
                'pendingTopUps' => TopUp::where('status', 'pending')->count(),
            ],
            'recentActions' => AdminAction::with('admin:id,name', 'target:id,name')->latest('created_at')->limit(10)->get()->map(fn (AdminAction $a) => $this->actionRow($a)),
        ]);
    }

    public function index(Request $request): Response
    {
        $q = trim((string) $request->query('q', ''));
        $status = in_array($request->query('status'), ['active', 'suspended'], true) ? $request->query('status') : '';

        $page = User::where('is_admin', false)->whereNull('owner_id') // team members belong to their owner's account
            ->with('bot:id,user_id,business_name,is_active')
            ->withCount(['topUps as pending_topups' => fn ($t) => $t->where('status', 'pending')])
            ->when($q, fn ($w) => $w->where(fn ($x) => $x->where('name', 'like', "%{$q}%")->orWhere('email', 'like', "%{$q}%")
                ->orWhereHas('bot', fn ($b) => $b->where('business_name', 'like', "%{$q}%"))))
            ->when($status === 'suspended', fn ($w) => $w->whereNotNull('suspended_at'))
            ->when($status === 'active', fn ($w) => $w->whereNull('suspended_at'))
            ->latest()->paginate(20)->withQueryString();

        $since = now()->subDays(30);
        $calls = Call::whereIn('bot_id', collect($page->items())->pluck('bot.id')->filter())->where('started_at', '>=', $since)
            ->selectRaw('bot_id, count(*) n')->groupBy('bot_id')->pluck('n', 'bot_id');

        return Inertia::render('Admin/Accounts', [
            'accounts' => collect($page->items())->map(fn (User $u) => [
                'id' => $u->id,
                'name' => $u->name,
                'email' => $u->email,
                'business' => $u->bot?->business_name,
                'balance' => (float) $u->balance,
                'calls30' => (int) ($calls[$u->bot?->id] ?? 0),
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
                'balance' => (float) $user->balance,
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
            'transactions' => $user->transactions()->latest('created_at')->latest('id')->limit(15)->get()->map(fn (WalletTransaction $t) => [
                'id' => $t->id, 'date' => $t->created_at->toIso8601String(), 'text' => $t->description, 'type' => $t->type, 'amount' => (float) $t->amount, 'balanceAfter' => (float) $t->balance_after,
            ]),
            'calls' => $bot ? $bot->calls()->latest('started_at')->limit(8)->get()->map->toRow() : [],
            'actions' => AdminAction::with('admin:id,name', 'target:id,name')->where('target_user_id', $user->id)->latest('created_at')->limit(20)->get()->map(fn (AdminAction $a) => $this->actionRow($a)),
        ]);
    }

    public function suspend(Request $request, User $user): RedirectResponse
    {
        abort_if($user->is_admin, 403);
        $reason = $request->validate(['reason' => ['required', 'string', 'max:200']])['reason'];
        $user->forceFill(['suspended_at' => now(), 'suspended_reason' => $reason])->save();
        $user->bot?->update(['is_active' => false]); // widget stops taking calls
        AdminAction::record($request->user(), $user, 'suspend', ['reason' => $reason]);

        return back()->with('success', "{$user->name} suspended, their bot is offline");
    }

    public function activate(Request $request, User $user): RedirectResponse
    {
        $user->forceFill(['suspended_at' => null, 'suspended_reason' => null])->save();
        $user->bot?->update(['is_active' => true]);
        AdminAction::record($request->user(), $user, 'activate');

        return back()->with('success', "{$user->name} is active again");
    }

    public function adjustBalance(Request $request, User $user, WalletService $wallet): RedirectResponse
    {
        abort_if($user->is_admin, 403);
        $data = $request->validate([
            'amount' => ['required', 'numeric', 'not_in:0', 'between:-1000,1000'],
            'reason' => ['required', 'string', 'max:150'],
        ]);

        try {
            $wallet->adjust($user, (float) $data['amount'], 'Adjustment · '.$data['reason']);
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }
        AdminAction::record($request->user(), $user, 'adjust_balance', ['amount' => (float) $data['amount'], 'reason' => $data['reason']]);

        return back()->with('success', 'Balance adjusted');
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
