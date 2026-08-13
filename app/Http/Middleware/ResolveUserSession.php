<?php

namespace App\Http\Middleware;

use App\Actions\Auth\Logout;
use App\Models\UserSession;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cookie;
use Symfony\Component\HttpFoundation\Response;

class ResolveUserSession
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $session = $request->session();
        $token = null;

        if ($session->has('user_session')) {
            $token = UserSession::findValid($session->get('user_session'));
        }

        if (!$token && !$this->isAdminRequest($request)) {
            // The long-lived paymenter_remember cookie is a convenience for the
            // customer frontend only. Honouring it for the admin panel was a
            // full MFA bypass: a bare paymenter_remember cookie (no session, no
            // password, no 2FA) yielded HTTP 200 on the admin dashboard. Admin
            // access requires a real interactive session, so the remember-cookie
            // fallback is never consulted for /admin routes. (Lab 5 s3.8)
            $id = Cookie::get('paymenter_remember');

            if ($id) {
                $token = UserSession::findValid($id);
            }
        }

        if ($token) {
            $request->attributes->set('user_session', $token);
            $token->touchRequest($request);

            // Store in session for next request
            if (!$session->has('user_session') || $session->get('user_session') !== $token->ulid) {
                $session->put('user_session', $token->ulid);
            }

            // Login user
            if (!Auth::check() || Auth::id() !== $token->user_id) {
                Auth::login($token->user);
            }
        }

        // If no valid token, ensure user is logged out
        if (!$token && Auth::check()) {
            return $this->fail($request);
        }

        // Lottery-based garbage collection
        $this->garbageCollection();

        return $next($request);
    }

    private function isAdminRequest(Request $request): bool
    {
        // Matches the Filament admin panel path (AdminPanelProvider::panel()->path('admin')).
        return $request->is('admin', 'admin/*');
    }

    private function garbageCollection(): void
    {
        // Run lottery
        if (!$this->shouldRunGarbageCollection()) {
            return;
        }

        $now = now();

        // Delete expired remember sessions
        UserSession::whereNotNull('expires_at')
            ->where('expires_at', '<', $now)
            ->delete();

        // Delete inactive normal sessions
        UserSession::whereNull('expires_at')
            ->where('last_activity', '<', $now->copy()->subMinutes(config('session.lifetime')))
            ->delete();
    }

    private function shouldRunGarbageCollection(): bool
    {
        [$chances, $total] = config('session.lottery');

        return random_int(1, $total) <= $chances;
    }

    private function fail(Request $request): Response
    {
        app(Logout::class)->execute();

        return redirect('/');
    }
}
