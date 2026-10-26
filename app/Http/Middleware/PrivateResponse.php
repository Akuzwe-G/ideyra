<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class PrivateResponse
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        $response->headers->set('Cache-Control', 'private, no-store, max-age=0');
        if ($request->is('intelligence', 'intelligence/*', 'reports', 'reports/*', 'ai', 'ai/*', 'career', 'career/*', 'v/*', 'dashboard', 'login', 'profile', 'profile/*', 'notifications', 'notifications/*', 'system', 'system/*', 'institutions/*', 'applications/*', 'activate/*', 'forgot-password', 'reset-password*', 'apply/*', 'preview/*')) {
            $response->headers->set('X-Robots-Tag', 'noindex, nofollow');
        }
        if ($request->is('network', 'network/*', 'showcase', 'showcase/*', 'challenges', 'challenges/*', 'events', 'events/*')) {
            $response->headers->set('X-Robots-Tag', 'noindex, nofollow');
        }
        $response->headers->set('Referrer-Policy', 'no-referrer');

        return $response;
    }
}
