<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/** The account owner adds staff to their account. A member gets an email link to set their own password. */
class TeamController extends Controller
{
    public const MAX_MEMBERS = 10;

    public function store(Request $request): RedirectResponse
    {
        $owner = $this->owner($request);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'role' => ['required', Rule::in(User::TEAM_ROLES)],
        ], ['email.unique' => 'That email already has a Hamsukhan login. Use another email for this team member.']);
        if ($owner->members()->count() >= self::MAX_MEMBERS) {
            return back()->withErrors(['email' => 'You can add up to '.self::MAX_MEMBERS.' team members.']);
        }

        $member = new User(['name' => $data['name'], 'email' => $data['email'], 'password' => Str::random(40), 'timezone' => $owner->timezone]);
        $member->forceFill(['owner_id' => $owner->id, 'team_role' => $data['role'], 'terms_accepted_at' => now()])->save();

        // The reset link doubles as the invite: the member chooses their password with it.
        $sent = rescue(fn () => Password::sendResetLink(['email' => $member->email]) === Password::RESET_LINK_SENT, false);

        return back()->with($sent ? 'success' : 'error', $sent
            ? "{$member->name} was added. We emailed them a link to set their password."
            : "{$member->name} was added, but the invite email could not be sent. They can use \"Forgot password\" on the login page with {$member->email}.");
    }

    public function update(Request $request, User $member): RedirectResponse
    {
        $this->authorizeMember($request, $member);
        $data = $request->validate(['role' => ['required', Rule::in(User::TEAM_ROLES)]]);
        $member->forceFill(['team_role' => $data['role']])->save();

        return back()->with('success', "{$member->name} is now {$data['role']}");
    }

    public function destroy(Request $request, User $member): RedirectResponse
    {
        $this->authorizeMember($request, $member);
        DB::table(config('session.table', 'sessions'))->where('user_id', $member->id)->delete();
        $member->delete();

        return back()->with('success', "{$member->name} was removed from your team");
    }

    private function owner(Request $request): User
    {
        $user = $request->user();
        abort_if($user->isMember() || $user->is_admin || ! $user->bot, 403, 'Only the account owner can manage the team.');

        return $user;
    }

    private function authorizeMember(Request $request, User $member): void
    {
        abort_unless($member->owner_id === $this->owner($request)->id, 404);
    }
}
