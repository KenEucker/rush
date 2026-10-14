# Planning inputs — RUSH-022

All routes require the current same-organization Management session. Cookie/CSRF,
private responses and standardized API errors follow existing contracts. They
are online-only. Snapshot payloads contain private Ranger inputs.

- `POST /api/v1/organizations/{organization}/seasons/{season}/planning-inputs`
  captures immutable inputs (201). Body: `starts_on`, `ends_on` (inclusive local
  ISO dates), `reason` (required, at most 500 characters), optional
  `minimum_rest_minutes` (integer 0–10080, default 480), optional `offsets`
  (map of `shift-uuid:YYYY-MM-DD` to an explicit `±HH:MM` UTC offset).
- `GET /api/v1/organizations/{organization}/planning-inputs/{snapshot}` returns
  the original payload, actor/reason/created_at, fingerprint and `stale` boolean.
- `POST .../planning-inputs/{snapshot}/evaluate` accepts
  `{"candidates":[{"membership_id":"uuid","opportunity_id":"snapshot opportunity id","override_reason":"optional reason"}]}`.
  Returns `snapshot_id`, `stale`, `hypothetical: true`, precedence and candidate
  results. It does not persist assignments or override approvals. At most 1000
  candidates per evaluation. Invalid request shape returns 422; semantic errors
  remain inspectable candidate results.

Snapshot `inputs.schema_version` is 1. `parameters` includes effective defaults.
`season`, `roster`, `phases`, `weeks`, `preferences`, `events`, `commitments`,
`unavailability`, `opportunities` and `issues` retain deterministic source order.
Each phase includes policy/season/coverage revisions and complete staffing target
links; opportunities use `staffing-row-uuid:date` or event UUID. Effective preference
records retain nullable default and weekly `{id,revision}` tuples. Event revision
covers attendance; official assignments and unavailability retain individual
revisions. Membership state is captured explicitly because eligibility lacks its
own revision. The complete payload hash detects creations and removals too.

Weeks are keyed by the configured local week-start date with UTC half-open
boundaries and all seven `phase_days`. `policy_minute_sevenths` retains exact
proration numerators; display minutes = value / 7. Null means unknown/review
required. Actual elapsed seconds use interval union over paid commitments and
proposals. Non-overlapping boundaries do not add duplicate time.

Candidate results distinguish `hard` codes, `overridable` details with source IDs,
`warnings`, per-week positive `preferences`, `automatic_eligible`, and
`explicit_override_eligible`. Hard errors always win. Stale evaluation is explicitly
labeled hypothetical against historical inputs; future generation must capture
fresh inputs. No user-supplied interval, eligibility or source revision is trusted.

Capture is rate-limited to 30/minute and evaluation to 60/minute. Snapshot UUIDs
are Server-created. Retrying capture creates another audit snapshot, without any
schedule side effects. No offline mutation queue is involved.
