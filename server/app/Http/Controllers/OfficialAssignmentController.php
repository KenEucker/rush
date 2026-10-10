<?php

namespace App\Http\Controllers;

use App\Models\Organization;
use App\Services\Scheduling\SaveOfficialAssignment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OfficialAssignmentController extends Controller
{
    public function save(Request $request, Organization $organization, string $assignment, SaveOfficialAssignment $service): JsonResponse
    {
        $record = $service->handle($request->user(), $organization, $assignment, $request->all());

        return response()->json(['id' => $record->id, 'revision' => $record->revision,
            'shift_context' => $record->shift_context,
            'membership_id' => $record->organization_membership_id,
            'starts_at' => $record->starts_at->utc()->format('Y-m-d\TH:i:s\Z'),
            'ends_at' => $record->ends_at->utc()->format('Y-m-d\TH:i:s\Z')]);
    }
}
