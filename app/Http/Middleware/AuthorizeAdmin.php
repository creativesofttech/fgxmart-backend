<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuthorizeAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        // Every authenticated admin can inspect and end their own session.
        if ($request->routeIs('admin.user', 'admin.logout')) {
            return $next($request);
        }
        // This endpoint filters each dataset against the admin's resource permissions.
        if ($request->routeIs('admin.dashboard')) return $next($request);

        $module = $request->segment(4);
        $modules = ['roles', 'permissions', 'categories', 'brands', 'attributes', 'countries', 'zipcodes', 'products', 'bank-accounts', 'company-settings', 'orders', 'faqs', 'pages', 'media'];
        abort_unless(in_array($module, $modules, true), 403, 'Unknown admin resource.');

        $action = match ($request->route()->getActionMethod()) {
            'index', 'show', 'adminIndex', 'adminShow' => 'index',
            'store', 'upload', 'generateSku' => 'create',
            'update', 'updateStatus' => 'edit',
            'destroy' => 'destroy',
            default => null,
        };

        abort_unless($action && $request->user()->hasPermission("{$module}.{$action}"), 403, 'You do not have permission for this action.');

        return $next($request);
    }
}
