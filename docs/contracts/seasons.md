# Seasons and phases (RUSH-015)

All routes are under `/api/v1/organizations/{organization}/seasons` and require
a same-origin Sanctum session and current Management membership. Responses are
private/no-store. Unsafe requests require CSRF; PUT is rate-limited to 120/minute.
Rangers cannot read administration configuration through these routes.

- `GET /`: paginated `data`, `links`, `meta`, 25 seasons/page, ordered by start/id.
- `GET /{uuid}`: one Season representation, with phases ordered by start/id.
- `PUT /{uuid}`: create with `expected_revision: null`, update with the last read
  integer revision. Returns 201 on create, 200 on update and the full representation.
  The UUID identifies the season and prevents duplicate creation effects.

```ts
interface Phase {
  id: string; // UUID
  name: string;
  starts_on: string; // YYYY-MM-DD, season-local date
  ends_on: string; // inclusive YYYY-MM-DD
}
interface Season {
  id: string;
  organization_id: string;
  name: string;
  starts_on: string;
  ends_on: string;
  timezone: string; // IANA identifier, e.g. America/Los_Angeles
  week_starts_on: number; // ISO 1..7
  revision: number;
  phases: Phase[];
}
type SaveSeason = Omit<Season, 'id' | 'organization_id' | 'revision' | 'phases'> & {
  expected_revision: number | null;
  reason: string;
  phases: (Omit<Phase, 'id'> & { id?: string | null })[];
};
```

PUT supplies the complete aggregate, including unchanged phases; an empty array
is valid for a new/empty season. Existing phase IDs cannot disappear. A new phase
may omit its ID for Server generation. Fields are allowlisted; roles, ownership
and revisions cannot be mass-assigned. Dates are inclusive, valid and ordered;
phases must fit within the season without overlap. Names max 120 characters,
reason max 500, max 100 phases. UUID collisions with another season's phase are
rejected. Configuration gaps remain explicitly unconfigured.

Errors use the standard API envelope: 401 session, 403 membership/role, 404 scoped
record missing, 409 `revision_conflict`, 422 field validation, 419 CSRF and 429
rate limit. Invalid/stale writes leave records and audit unchanged. Network
failure after acceptance requires a fresh GET and comparison; the caller must
not automatically replace expected_revision and retry. This is an online
administration API, not an additional sync transport.

SaveSeason also serves Orchid. SeasonCalendar provides Server-local calendar
resolution, week calculation and interval allocation; it is not a new public
payroll endpoint. [ADR 0015](../decisions/0015-season-calendar-policy.md) defines
DST and overnight semantics and the remaining task boundaries.
