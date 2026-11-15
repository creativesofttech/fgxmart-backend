<?php

namespace App\Http\Middleware;

use App\Models\Admin;
use Closure;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

class EnsureAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        $admin = $request->user();
        abort_unless($admin instanceof Admin, 403, 'Administrator access required.');
        $token = $admin->currentAccessToken();
        // Old wildcard/non-expiring tokens must sign in again after the migration.
        abort_unless($token instanceof PersonalAccessToken && in_array('admin', $token->abilities, true) && $token->expires_at !== null, 403, 'Please sign in again as an administrator.');

        return $next($request);
    }
}
