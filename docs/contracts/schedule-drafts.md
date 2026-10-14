# Schedule drafts API

RUSH-023 · R-02, R-06, R-12, R-17. Same-origin Sanctum/CSRF, Management only.
All paths below are under `/api/v1/organizations/{organization}`. Generation and
editing require the Server and are never queued offline.

| Method and path | Input / result |
| --- | --- |
| `POST /planning-inputs/{snapshot}/drafts` | `{reason: string}`; 201 new draft |
| `GET /drafts/{draft}` | 200 saved draft, source freshness, assignments and demand |
| `PUT /drafts/{draft}` | `{expected_revision: integer, reason: string, candidates: Candidate[]}`; 200 updated draft |

```ts
interface Candidate {
  membership_id: string; // UUID, active Ranger in snapshot's organization
  opportunity_id: string; // exact snapshot opportunity identifier
  starts_at?: string; // UTC YYYY-MM-DDTHH:mm:ssZ, paired with ends_at
  ends_at?: string; // omitted pair means whole interval; partial shifts only
  override_reason?: string | null; // max 500, required for each conflicted candidate
}
```

PUT replaces the entire proposal set (at most 1,000); `[]` removes all proposals.
Each candidate must have a unique membership/opportunity pair. Clients must not
send response-only assignment IDs/context/evaluation fields as candidate input.
Unknown candidate fields are rejected. Expected revision and reason are mandatory.

The response includes `id`, `revision`, `state: "draft"`, `snapshot` (the planning
input read contract, including `stale`), `assignments` and `demand`. Each assignment
includes `id`, candidate fields with normalized UTC instants, immutable `context`
(source opportunity), and `evaluation` (hard/overridable/warning/preference factors
from the planning evaluator). Demand entries carry their `opportunity`,
`maximum_shortfall` and `segments: {starts_at, ends_at, counted, shortfall}[]`.
Demand describes captured inputs, not current official coverage when stale.

Generation creates independent proposals, never official shifts or attendance.
Automatic candidates cannot override availability, work overlap, rest or caps.
Editing may override those constraints only with per-candidate reasons. Missing
configuration, invalid intervals, duplicate candidates, inactive/foreign members
and already-committed event attendees remain hard failures.

Errors use the existing API error envelope: 401 unauthenticated, 403 unauthorized
or archived year, 404 unknown tenant-scoped record, 409 stale draft/source,
422 validation/constraint failure, 429 rate limit. Failure writes neither a new
revision nor partial assignments/audit. Rejected form input is retained by Orchid.
RUSH-027/028 will add release/publication transitions; no such endpoint exists here.
