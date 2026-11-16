<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserIsActive
{
    /**
     * Kick out a user who was deactivated (or whose branch was frozen, task 228)
     * while their session was still open. Logging in is blocked in LoginRequest;
     * this closes the other half.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        $reason = $user?->accessDeniedReason();

        if ($reason === null) {
            return $next($request);
        }

        // An admin browsing as someone who just got deactivated keeps their own
        // session — drop them back into their real account instead of logging out.
        if ($request->session()->has('impersonator_id')) {
            $admin = User::find($request->session()->pull('impersonator_id'));

            if ($admin !== null && $admin->accessDeniedReason() === null) {
                Auth::login($admin);

                return redirect()->route('users.index')
                    ->with('error', 'تم تعطيل هذا الحساب أثناء التصفّح، وتم الرجوع إلى حسابك.');
            }
        }

        Auth::guard('web')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login')
            ->withErrors(['username' => $reason]);
    }
}
