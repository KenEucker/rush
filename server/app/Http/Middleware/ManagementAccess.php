<?php

namespace App\Http\Middleware;

use App\Models\Organization;
use Closure;
use Illuminate\Http\Request;

class ManagementAccess
{
    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();
        abort_unless($user && Organization::query()->whereHas('memberships', fn ($query) => $query->where('user_id', $user->id)->where('is_active', true))
            ->get()->contains(fn ($organization) => $user->can('manage', $organization)), 403);

        // Orchid's generic relation, attachment and account tools are not organization scoped.
        // Enable new routes only with their own domain policies in the owning task.
        abort_unless($request->isMethod('GET') && $request->routeIs('platform.index', 'platform.main'), 404);

        return $next($request);
    }
}
