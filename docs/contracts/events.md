# One-off event contract

RUSH-020 · R-06 · [Decision](../decisions/0020-one-off-events.md)

Same-origin Sanctum/CSRF, active Management only:

- `GET /api/v1/organizations/{organization}/seasons/{season}/events`: paginated
  `data`, ordered by start and UUID, 25 records per page.
- `GET .../events/{uuid}`: one configuration and current staffing evaluation.
- `PUT .../events/{uuid}`: create (201) or replace (200), including attendance.

Request fields:

```ts
type SaveEvent = {
  expected_revision: number | null; // null only for creation
  expected_season_revision: number;
  name: string; // 1–120 characters
  starts_local: string; // YYYY-MM-DD HH:mm:ss in current season zone
  ends_local: string;
  starts_offset?: string | null; // ±HH:mm; mandatory on DST folds
  ends_offset?: string | null;
  paid: boolean;
  counts_scheduled: boolean; // all areas when true
  target_type: 'none' | 'number' | 'percentage';
  target_value?: number | null; // null for none, 0–10000 number, 0–100 percentage
  attendance_membership_ids: string[]; // unique active organization Rangers
  reason: string; // 1–500 characters
};
```

Response includes UUID `id`, `organization_id`, `season_id`, `name`, UTC
`starts_at`/`ends_at` (`YYYY-MM-DDTHH:mm:ssZ`), snapshotted `timezone`,
`season_revision`, integer `revision`, `paid`, `counts_scheduled`,
`target_type`, nullable integer `target_value`, `requires_review`, and
`attendance: { id: UUID, organization_membership_id: UUID }[]` for assigned rows.

`evaluation` contains integer `active_rangers`, nullable integer `required`,
integer `minimum_counted`, nullable integer `maximum_shortfall`, and `segments`
with UTC bounds, deduplicated `counted_membership_ids`, `counted`, and nullable
`shortfall`. Null means no target, not zero. It also contains
`unavailable_membership_ids`, `inactive_membership_ids`, and `paid_attendance`:
each explicit attendee's `membership_id` and `allocations` from SeasonCalendar
(`starts_at`, `ends_at`, `week_starts_on`, nullable `phase_id`, `elapsed_seconds`).
These paid source intervals are not finalized or overlap-adjusted totals.

401 unauthenticated; 403 absent/revoked Management; 404 mismatched season/event
scope; 409 stale event or season revision; 422 invalid fields, inactive/foreign
attendees, DST gap/ambiguous offset, out-of-season bounds, or recurrence input.
No partial event, attendance, or audit write survives failure. A repeated PUT
with the old revision conflicts; it cannot duplicate attendance or audit effects.

Orchid at `/admin/organizations/{organization}/seasons/{season}/events/{uuid|new}`
uses the same services and retains old input on validation/conflict. It accepts
native datetime-local values with optional seconds and normalizes them for the
service. Event endpoints are online-only and are not registered in sync push/pull.
