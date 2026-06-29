<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;

class ImpersonationController extends Controller
{
    /**
     * The session key holding the original admin's id while impersonating.
     */
    private const SESSION_KEY = 'impersonator_id';

    /**
     * Sign in as another user. The original admin's id is stashed in the
     * session so it can be restored later, and the swap is logged for audit.
     */
    public function start(Request $request, User $user): RedirectResponse
    {
        Gate::authorize('impersonate', $user);

        // Refuse to nest: an already-impersonated session must leave first.
        if ($request->session()->has(self::SESSION_KEY)) {
            return back()->with('error', 'أنت بالفعل تتصفّح كمستخدم آخر.');
        }

        $admin = $request->user();

        activity('security')
            ->causedBy($admin)
            ->performedOn($user)
            ->withProperties(['impersonator_id' => $admin->id, 'target_id' => $user->id])
            ->log('started impersonation');

        $request->session()->put(self::SESSION_KEY, $admin->id);

        Auth::login($user);

        return redirect()->route('dashboard');
    }

    /**
     * Stop impersonating and return to the original admin account.
     */
    public function leave(Request $request): RedirectResponse
    {
        $impersonatorId = $request->session()->pull(self::SESSION_KEY);

        if ($impersonatorId === null) {
            return redirect()->route('dashboard');
        }

        $admin = User::findOrFail($impersonatorId);
        $impersonated = $request->user();

        Auth::login($admin);

        activity('security')
            ->causedBy($admin)
            ->performedOn($impersonated)
            ->withProperties(['impersonator_id' => $admin->id, 'target_id' => $impersonated?->id])
            ->log('stopped impersonation');

        return redirect()->route('users.index')->with('success', 'تم الرجوع إلى حسابك.');
    }
}
