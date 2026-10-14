<?php

namespace App\Http\Middleware;

use App\Models\Organization;
use App\Orchid\ManagementYear;
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
        $seasonRoute = $request->routeIs('platform.seasons', 'platform.phase-coverage', 'platform.phase-policies', 'platform.events') &&
            ($request->isMethod('GET') || ($request->isMethod('POST') && $request->route('method') === 'save'));
        $availabilityRoute = $request->routeIs('platform.unavailability') &&
            ($request->isMethod('GET') || ($request->isMethod('POST') && in_array($request->route('method'), ['save', 'acknowledge'], true)));
        $planningRoute = $request->routeIs('platform.planning-inputs') &&
            ($request->isMethod('GET') || ($request->isMethod('POST') && in_array($request->route('method'), ['capture', 'generate'], true)));
        $draftRoute = $request->routeIs('platform.drafts') &&
            ($request->isMethod('GET') || ($request->isMethod('POST') && in_array($request->route('method'), ['save', 'remove'], true)));
        abort_unless($seasonRoute || $availabilityRoute || $planningRoute || $draftRoute || ($request->isMethod('GET') && $request->routeIs('platform.index', 'platform.main')), 404);

        $year = app(ManagementYear::class)->context();
        abort_if(! $request->isMethodSafe() && $year['readOnly'], 403, 'Past years are read-only.');

        return $next($request);
    }
}
