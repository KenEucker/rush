<?php

use App\Models\Organization;
use App\Models\User;
use App\Services\Events\SaveEvent;
use App\Services\Seasons\SaveSeason;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Console\Kernel;

// Explicitly invoked only by the disposable browser-test fixture.
require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

abort_unless(app()->environment('local', 'testing') && config('database.connections.pgsql.database') === 'rush_ci', 403);
$organization = Organization::where('slug', 'rush-demo-rangers')->firstOrFail();
$actor = User::where('email', 'riley.management@example.com')->firstOrFail();
$year = CarbonImmutable::now($organization->timezone)->year - 1;
CarbonImmutable::withTestNow("$year-06-01T12:00:00Z", function () use ($organization, $actor, $year) {
    $season = app(SaveSeason::class)->handle($actor, $organization,
        'f8400000-0000-4000-8000-000000000001', ['name' => 'Archive browser proof',
            'starts_on' => "$year-01-01", 'ends_on' => ($year + 1).'-03-31', 'timezone' => $organization->timezone,
            'week_starts_on' => 1, 'expected_revision' => null,
            'phases' => [['name' => 'Archived phase', 'starts_on' => "$year-01-01", 'ends_on' => ($year + 1).'-03-31']]]);
    app(SaveEvent::class)->handle($actor, $organization, $season->id,
        'f8400000-0000-4000-8000-000000000002', ['name' => 'Archived event proof',
            'expected_revision' => null, 'expected_season_revision' => 1,
            'starts_local' => "$year-10-12 18:00:00", 'ends_local' => "$year-10-12 19:00:00",
            'paid' => true, 'target_type' => 'none', 'target_value' => null, 'counts_scheduled' => false,
            'attendance_membership_ids' => [], 'reason' => 'Browser archive fixture']);
});
