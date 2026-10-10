# Phase coverage contract

Task: RUSH-016 · Requirements: R-02, R-03.

Same-origin Sanctum session, current organization Management membership, CSRF on
mutations, private/no-store responses. Administration requires the Server.

`GET /api/v1/organizations/{organization}/phases/{phase}/coverage`
returns the snapshot below. A new phase has null model/revision and empty arrays.
`PUT` at the same URL saves one complete aggregate, returning HTTP 200 with the
accepted snapshot. Unknown/foreign phases are 404 for authorized managers;
nonmembers/Rangers are 403, guests 401. Validation is 422; stale coverage or season
revisions are 409 `revision_conflict`. PUT is rate-limited to 120/minute.

```ts
type ShiftDefinition = {
  id?: string | null; // UUID; omit for a new row; always present in responses
  name: string;
  start_time: string; // HH:mm in season zone
  duration_minutes: number; // positive integer elapsed minutes
};
type Area = { id?: string | null; name: string };
type Group = Area & { area_names: string[] };
type Staffing = {
  shift_name: string;
  area_name?: string | null; // dedicated only
  group_name?: string | null; // shared only
  positions: number; // nonnegative integer
};
type CoverageWrite = {
  expected_revision: number | null; // null for initial configuration
  expected_season_revision: number;
  model: 'dedicated' | 'shared';
  shifts: ShiftDefinition[];
  areas: Area[];
  groups: Group[]; // empty in dedicated mode
  staffing: Staffing[];
  reason: string;
};
type CoverageSnapshot = Omit<CoverageWrite,
  'expected_revision' | 'expected_season_revision' | 'reason' | 'model'> & {
  phase_id: string;
  revision: number | null;
  season_revision: number;
  model: 'dedicated' | 'shared' | null;
};
```

Arrays are complete replacement collections. Preserve IDs returned by GET when
editing rows; omitted rows are removed from current configuration. Names are
unique ignoring case; references match exact names in the new payload. Shared
groups must name existing areas without duplicates. One staffing row per
shift/target is permitted. Dedicated rows must leave group_name blank/null;
shared rows must leave area_name blank/null. No mixed model is accepted.
Zero positions is explicit; absent shift/target pairs remain unconfigured.

Orchid uses `/admin/organizations/{organization}/phases/{phase}/coverage` with
only GET and POST `save` exposed. It invokes SavePhaseCoverage and adapts only
form field names, missing matrix arrays and comma-separated group members.

## Optional manual assignment source context

The existing Management-only
`PUT /api/v1/organizations/{organization}/official-assignments/{assignment}`
accepts an optional `shift` object alongside membership_id, expected_revision,
starts_at, ends_at and reason:

```ts
type AssignmentShiftInput = {
  phase_id: string;
  shift_id: string;
  target_id: string; // area UUID in dedicated mode, group UUID in shared mode
  date: string; // YYYY-MM-DD local shift start date within phase
  offset?: string | null; // ±HH:mm; required for repeated DST start times
  expected_coverage_revision: number;
  expected_season_revision: number;
};
type AssignmentShiftContext = {
  phase_id: string; season_id: string; season_revision: number;
  coverage_revision: number; model: 'dedicated' | 'shared'; timezone: string;
  shift_id: string; shift_name: string; target_id: string; target_name: string;
  area_names: string[];
  starts_at: string; ends_at: string; // whole shift UTC instants, NOT partial bounds
  is_partial: boolean;
};
```

The existing response gains `shift_context: AssignmentShiftContext | null`.
Top-level starts_at/ends_at remain the actual assignment bounds, in whole-second
UTC `YYYY-MM-DDTHH:mm:ssZ` form. They must be a positive subinterval of the whole
shift; matching both whole-shift bounds yields is_partial=false. The Server
derives the snapshot and partial flag. Supplying stale configuration yields 409;
invalid date/target/bounds or DST gap/fold yields 422. Omitting `shift` on an edit
preserves the prior context and revalidates the interval against it. Supplying
`shift: null` is invalid. Older intervals without source context remain supported.
