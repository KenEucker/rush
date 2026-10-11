# Phase scheduling policy contract

Task: RUSH-017. Requirements: R-09, R-11, R-29.

`GET|PUT /api/v1/organizations/{organization}/phases/{phase}/policies`
requires a current Management membership and same-origin Sanctum session.
PUT requires CSRF and is rate limited. Responses are private/no-store.
Orchid `/admin/organizations/{organization}/phases/{phase}/policies` uses
the same authorization and save service.

## PUT: complete aggregate replacement

```json
{
  "expected_revision": null,
  "expected_season_revision": 1,
  "expected_coverage_revision": 1,
  "weekly_target_minutes": 1920,
  "planned_cap_minutes": 2400,
  "planned_warning_minutes": 2100,
  "actual_warning_minutes": 2400,
  "baselines": [{"shift_id": "<phase shift UUID>", "classification": "neutral"}],
  "weekday_overrides": [{"shift_id": "<phase shift UUID>", "weekday": 1, "classification": "undesirable"}],
  "date_overrides": [{"shift_id": "<phase shift UUID>", "date": "2026-01-05", "classification": "desirable"}],
  "reason": "Monday exception"
}
```

The sample values are illustrative, not defaults or approved employment rules.
Initial policy revision is null; updates supply the current integer. Coverage
must already exist. All four hour fields are required nonnegative integer minutes
up to 2,147,483,647; target ≤ cap. Warning thresholds are independent.
Baselines must classify every current phase shift exactly once. Values are
`desirable|neutral|undesirable`; no missing classification implies neutral.
Weekday is ISO 1–7. Dates must be real `YYYY-MM-DD` values inside phase bounds.
One override per shift/weekday or shift/date; foreign shifts and duplicates fail.
Override arrays must be present, including when empty. Empty removes overrides.
Reason is required, at most 500 characters. Storage limits: 100 baselines,
700 weekday overrides, 10,000 date overrides per phase.

## Read/save response

HTTP 200 returns `phase_id`, `revision` (null before first save), `starts_on`,
`ends_on`, current `season_revision`, current `coverage_revision`,
`requires_review`, four hour fields, and the three classification arrays.
Baselines additionally include current `shift_name` for display. Before the
first save, hour fields and baseline classifications are null. Existing policy
configuration is marked `requires_review` when calendar/coverage revisions change;
saving against the current revisions clears that state after full revalidation.

Unauthorized: 401; denied membership/role: 403; phase outside selected organization:
404; stale expected revision: 409; invalid fields: 422; throttled: 429.
No partial effects on validation, conflict or audit failure. Every accepted save
records actor, reason, UTC occurrence, revision and before/after snapshots.
No command queue or sync checkpoint is introduced; this is online administration.

## Classification consumption

`PhaseSchedulingPolicy::classification(snapshot, shiftId, localStartDate)` takes
an already authorized coherent snapshot, rejects policies needing review and
out-of-phase dates, and returns `{classification, source}` where source is
`date|weekday|baseline`. A dated override wins over weekday, then baseline.
The caller must obtain the local start date using the season time zone; an
overnight shift keeps its starting date's classification. This is independent
of elapsed DST duration and personal preferences. Future consumers must snapshot
all three revisions and the resolved result; this task does not calculate
fairness, generate schedules, evaluate mixed-phase hours or change payable time.
